<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockPallet;
use App\Models\Warehouse;
use App\Support\Locations\LocationCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class WarehouseDeduplicationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_is_read_only_and_apply_merges_nave_38_without_losing_references(): void
    {
        [$client, $canonicalWarehouse, $duplicateWarehouse, $canonicalLocation, $duplicateLocation] = $this->makeDuplicatedNave38();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'default_location_id' => $duplicateLocation->id,
            'sku' => 'ED-WH-TEST',
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $duplicateLocation->id,
            'quantity_units' => 2400,
            'units_per_pallet' => 1200,
        ]);
        $receipt = GoodsReceipt::factory()->create(['client_id' => $client->id]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'location_id' => $duplicateLocation->id,
        ]);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'warehouse_id' => $duplicateWarehouse->id,
        ]);
        $snapshot = $this->databaseSnapshot();

        $this->artisan('wms:warehouses:deduplicate', [
            '--client' => 'EDELVIVES',
            '--warehouse-code' => '38',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('Almacenes detectados: 2')
            ->expectsOutputToContain((string) $canonicalWarehouse->id)
            ->expectsOutputToContain((string) $duplicateWarehouse->id)
            ->expectsOutputToContain('Ubicaciones por almacen 38')
            ->assertSuccessful();

        $this->assertSame($snapshot, $this->databaseSnapshot());

        $this->artisan('wms:warehouses:deduplicate', [
            '--client' => 'EDELVIVES',
            '--warehouse-code' => '38',
            '--apply' => true,
        ])
            ->expectsOutputToContain('Aplicacion completada')
            ->assertSuccessful();

        $remainingWarehouse = Warehouse::query()
            ->whereIn('id', [$canonicalWarehouse->id, $duplicateWarehouse->id])
            ->firstOrFail();
        $remainingLocation = Location::query()
            ->where('warehouse_id', $remainingWarehouse->id)
            ->where('code', '7')
            ->firstOrFail();

        $this->assertSame(1, Warehouse::query()->whereIn('id', [$canonicalWarehouse->id, $duplicateWarehouse->id])->count());
        $this->assertSame(1, Location::query()->whereIn('id', [$canonicalLocation->id, $duplicateLocation->id])->count());
        $this->assertSame($remainingLocation->id, $stock->fresh()->location_id);
        $this->assertSame($remainingLocation->id, $item->fresh()->default_location_id);
        $this->assertSame($remainingLocation->id, $receipt->lines()->firstOrFail()->location_id);
        $this->assertSame($remainingWarehouse->id, $booking->fresh()->warehouse_id);
        $this->assertSame(
            LocationCode::expectedEdelvivesCodes(),
            Location::query()
                ->where('warehouse_id', $remainingWarehouse->id)
                ->get()
                ->sortBy(fn (Location $location): array => LocationCode::naturalSortKey($location->code))
                ->pluck('code')
                ->values()
                ->all(),
        );
    }

    public function test_apply_is_idempotent_after_warehouses_are_merged(): void
    {
        $this->makeDuplicatedNave38();
        $arguments = [
            '--client' => 'EDELVIVES',
            '--warehouse-code' => '38',
            '--apply' => true,
        ];

        $this->artisan('wms:warehouses:deduplicate', $arguments)->assertSuccessful();
        $snapshot = $this->databaseSnapshot();

        $this->artisan('wms:warehouses:deduplicate', $arguments)
            ->expectsOutputToContain('Aplicacion completada')
            ->assertSuccessful();

        $this->assertSame($snapshot, $this->databaseSnapshot());
        $remainingWarehouse = Warehouse::query()->where('code', '38')->firstOrFail();
        $this->assertSame(52, Location::query()->where('warehouse_id', $remainingWarehouse->id)->count());
    }

    public function test_warehouse_codes_are_normalized_before_saving(): void
    {
        $warehouse = Warehouse::factory()->create([
            'client_id' => null,
            'code' => ' 038 ',
            'name' => 'NAVE 38',
        ]);

        $this->assertSame('38', $warehouse->fresh()->code);
    }

    /** @return array{Client, Warehouse, Warehouse, Location, Location} */
    private function makeDuplicatedNave38(): array
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES', 'name' => 'Edelvives']);
        $canonicalWarehouse = Warehouse::factory()->create([
            'client_id' => null,
            'code' => '38',
            'name' => 'NAVE 38',
        ]);
        $duplicateWarehouse = Warehouse::factory()->create([
            'client_id' => null,
            'code' => '038',
            'name' => 'NAVE 38',
        ]);
        $canonicalLocation = Location::factory()->create([
            'warehouse_id' => $canonicalWarehouse->id,
            'code' => '7',
            'active' => true,
        ]);
        $duplicateLocation = Location::factory()->create([
            'warehouse_id' => $duplicateWarehouse->id,
            'code' => '07',
            'active' => true,
        ]);
        Location::factory()->create([
            'warehouse_id' => $duplicateWarehouse->id,
            'code' => '8',
            'active' => true,
        ]);

        return [$client, $canonicalWarehouse, $duplicateWarehouse, $canonicalLocation, $duplicateLocation];
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function databaseSnapshot(): array
    {
        return [
            'warehouses' => DB::table('warehouses')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'locations' => DB::table('locations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'stock' => DB::table('stock_pallets')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'items' => DB::table('items')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'receipt_lines' => DB::table('goods_receipt_lines')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'bookings' => DB::table('bookings')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }
}
