<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockPallet;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LocationDeduplicationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_does_not_modify_and_apply_reassigns_every_location_reference_without_losing_stock(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES', 'name' => 'Edelvives']);
        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
            'code' => '38',
            'name' => 'NAVE 38',
        ]);
        $canonical = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => '6',
            'active' => true,
        ]);
        $duplicateId = DB::table('locations')->insertGetId([
            'warehouse_id' => $warehouse->id,
            'code' => '06',
            'name' => 'Duplicada historica',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'default_location_id' => $duplicateId,
        ]);
        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $canonical->id,
            'quantity_units' => 8000,
            'units_per_pallet' => 8000,
        ]);
        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $duplicateId,
            'quantity_units' => 2000,
            'units_per_pallet' => 8000,
        ]);
        $receipt = GoodsReceipt::factory()->create(['client_id' => $client->id]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'location_id' => $duplicateId,
        ]);
        $stockBefore = StockPallet::query()->sum('quantity_units');

        $this->artisan('wms:locations:deduplicate', [
            '--client' => 'EDELVIVES',
            '--warehouse' => 'NAVE 38',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('codigo normalizado 6')
            ->expectsOutputToContain('Dry-run: 1 grupo(s) duplicado(s)')
            ->assertSuccessful();

        $this->assertDatabaseHas('locations', ['id' => $duplicateId, 'code' => '06']);
        $this->assertDatabaseHas('stock_pallets', ['location_id' => $duplicateId]);

        $this->artisan('wms:locations:deduplicate', [
            '--client' => 'EDELVIVES',
            '--warehouse' => 'NAVE 38',
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertDatabaseMissing('locations', ['id' => $duplicateId]);
        $this->assertSame(2, StockPallet::query()->where('location_id', $canonical->id)->count());
        $this->assertSame($stockBefore, StockPallet::query()->sum('quantity_units'));
        $this->assertSame($canonical->id, $item->fresh()->default_location_id);
        $this->assertSame($canonical->id, $receipt->lines()->firstOrFail()->location_id);
        $this->assertSame(1, Location::query()->where('warehouse_id', $warehouse->id)->where('code', '6')->count());
    }

    public function test_command_defaults_to_dry_run_when_apply_is_not_explicit(): void
    {
        $warehouse = Warehouse::factory()->create();
        Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '7']);
        DB::table('locations')->insert([
            'warehouse_id' => $warehouse->id,
            'code' => '07',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->artisan('wms:locations:deduplicate')
            ->expectsOutputToContain('Dry-run')
            ->assertSuccessful();

        $this->assertSame(2, Location::query()->where('warehouse_id', $warehouse->id)->count());
    }
}
