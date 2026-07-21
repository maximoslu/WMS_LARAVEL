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
use Illuminate\Support\Str;
use Tests\TestCase;

class PurgeLocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_purge_locations_dry_run_does_not_modify_data(): void
    {
        [$warehouse, $location, $stock, $item, $receiptLine] = $this->stockWithLocation();

        $this->artisan('wms:locations:purge', [
            '--warehouse' => $warehouse->code,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN wms:locations:purge')
            ->expectsOutputToContain('Ubicaciones a eliminar: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('locations', ['id' => $location->id]);
        $this->assertSame($location->id, $stock->fresh()->location_id);
        $this->assertSame($location->id, $item->fresh()->default_location_id);
        $this->assertSame($location->id, $receiptLine->fresh()->location_id);
    }

    public function test_purge_locations_apply_clears_references_without_changing_stock_quantities_or_history(): void
    {
        [$warehouse, $location, $stock, $item, $receiptLine] = $this->stockWithLocation();

        $this->artisan('wms:locations:purge', [
            '--warehouse' => $warehouse->code,
            '--apply' => true,
        ])
            ->expectsOutputToContain('APPLY wms:locations:purge')
            ->expectsOutputToContain('Purga completada. Ubicaciones eliminadas: 1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
        $this->assertDatabaseHas('stock_pallets', [
            'id' => $stock->id,
            'location_id' => null,
            'location_text' => null,
            'quantity_units' => 22500,
            'units_per_pallet' => 7500,
            'full_pallets' => 3,
            'peaks_count' => 0,
        ]);
        $this->assertNull($item->fresh()->default_location_id);
        $this->assertNull($receiptLine->fresh()->location_id);
        $this->assertDatabaseHas('inventory_movements', [
            'stock_pallet_id' => $stock->id,
            'location_id' => $location->id,
            'from_location_id' => $location->id,
            'to_location_id' => $location->id,
        ]);
    }

    public function test_purge_locations_rejects_apply_and_dry_run_together(): void
    {
        $this->artisan('wms:locations:purge', [
            '--dry-run' => true,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Usa --dry-run o --apply, no ambos.')
            ->assertFailed();
    }

    /** @return array{Warehouse, Location, StockPallet, Item, GoodsReceiptLine} */
    private function stockWithLocation(): array
    {
        $client = Client::factory()->create();
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id, 'code' => '38']);
        $location = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '11']);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'default_location_id' => $location->id,
            'units_per_pallet' => 7500,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'quantity_units' => 22500,
            'units_per_pallet' => 7500,
            'full_pallets' => 3,
            'peaks_count' => 0,
            'peak_1' => 0,
        ]);
        $receipt = GoodsReceipt::factory()->create(['client_id' => $client->id]);
        $receiptLine = GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
        ]);

        DB::table('inventory_movements')->insert([
            'uuid' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => 'test-command-location-purge-'.$location->id,
            'client_id' => $client->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'movement_type' => 'test',
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
            'from_location_id' => $location->id,
            'to_location_id' => $location->id,
            'units_delta' => 0,
            'effective_at' => now(),
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        return [$warehouse, $location, $stock, $item, $receiptLine];
    }
}
