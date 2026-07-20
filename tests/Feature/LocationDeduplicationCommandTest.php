<?php

namespace Tests\Feature;

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
use Illuminate\Support\Str;
use Tests\TestCase;

class LocationDeduplicationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_is_read_only_and_apply_consolidates_real_variants_without_losing_stock(): void
    {
        [$client, $warehouse, $canonical, $spacedId, $zeroPaddedId, $prefixedId] = $this->makeDuplicatedNave38();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'default_location_id' => $spacedId,
            'sku' => 'ED-LOCATION-TEST',
        ]);

        foreach ([
            [$canonical->id, 'LOT-CANONICAL', 8000],
            [$spacedId, 'LOT-SPACED', 2500],
            [$zeroPaddedId, 'LOT-ZERO', 1250],
        ] as [$locationId, $lot, $units]) {
            StockPallet::factory()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'location_id' => $locationId,
                'lot' => $lot,
                'quantity_units' => $units,
                'units_per_pallet' => 1000,
            ]);
        }

        $receipt = GoodsReceipt::factory()->create(['client_id' => $client->id]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'location_id' => $zeroPaddedId,
        ]);
        $movementId = DB::table('inventory_movements')->insertGetId([
            'uuid' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => 'location-dedup-test',
            'client_id' => $client->id,
            'item_id' => $item->id,
            'stock_pallet_id' => null,
            'movement_type' => 'transfer',
            'source' => 'test',
            'warehouse_id' => $warehouse->id,
            'location_id' => $spacedId,
            'from_location_id' => $zeroPaddedId,
            'to_location_id' => $prefixedId,
            'units_delta' => 0,
            'full_pallets_delta' => 0,
            'warehouse_pallets_delta' => 0,
            'effective_at' => now(),
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $databaseBeforeAudit = $this->databaseSnapshot();
        $stockBefore = $this->stockBusinessSnapshot();

        $this->artisan('wms:locations:audit', [
            '--client' => 'EDELVIVES',
            '--warehouse' => 'NAVE 38',
        ])
            ->expectsOutputToContain('AUDITORIA DE SOLO LECTURA')
            ->expectsOutputToContain('Duplicado 5')
            ->expectsOutputToContain('extras (se conservan): FONDO')
            ->expectsOutputToContain('Sin cambios')
            ->assertSuccessful();

        $this->assertSame($databaseBeforeAudit, $this->databaseSnapshot());

        $this->artisan('wms:locations:deduplicate', [
            '--client' => 'EDELVIVES',
            '--warehouse' => 'NAVE 38',
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('codigo normalizado 5')
            ->expectsOutputToContain('extras a conservar: FONDO')
            ->expectsOutputToContain('Dry-run: 1 grupo(s) duplicado(s), 51 ubicacion(es) por crear')
            ->assertSuccessful();

        $this->assertSame($databaseBeforeAudit, $this->databaseSnapshot());

        $this->artisan('wms:locations:deduplicate', [
            '--client' => 'EDELVIVES',
            '--warehouse' => 'NAVE 38',
            '--apply' => true,
        ])
            ->expectsOutputToContain('1 grupo(s) consolidados y 51 ubicacion(es) creadas')
            ->assertSuccessful();

        $remainingLocation = Location::query()
            ->where('warehouse_id', $warehouse->id)
            ->where('code', '5')
            ->firstOrFail();

        $this->assertSame(1, Location::query()->where('warehouse_id', $warehouse->id)->where('code', '5')->count());
        $this->assertNotContains($prefixedId, [$remainingLocation->id]);
        $this->assertSame(3, StockPallet::query()->where('location_id', $remainingLocation->id)->count());
        $this->assertSame($remainingLocation->id, $item->fresh()->default_location_id);
        $this->assertSame($remainingLocation->id, $receipt->lines()->firstOrFail()->location_id);
        $movement = DB::table('inventory_movements')->where('id', $movementId)->first();
        $this->assertSame($remainingLocation->id, (int) $movement->location_id);
        $this->assertSame($remainingLocation->id, (int) $movement->from_location_id);
        $this->assertSame($remainingLocation->id, (int) $movement->to_location_id);
        $this->assertSame($stockBefore, $this->stockBusinessSnapshot());
        $this->assertSame(
            LocationCode::expectedEdelvivesCodes(),
            Location::query()
                ->where('warehouse_id', $warehouse->id)
                ->where('code', '!=', 'FONDO')
                ->get()
                ->sortBy(fn (Location $location): array => LocationCode::naturalSortKey($location->code))
                ->pluck('code')
                ->values()
                ->all(),
        );
        $this->assertDatabaseHas('locations', [
            'warehouse_id' => $warehouse->id,
            'code' => 'FONDO',
            'active' => true,
        ]);
    }

    public function test_apply_is_idempotent_after_series_and_duplicates_are_repaired(): void
    {
        [, $warehouse] = $this->makeDuplicatedNave38();

        $arguments = [
            '--client' => 'EDELVIVES',
            '--warehouse' => 'NAVE 38',
            '--apply' => true,
        ];

        $this->artisan('wms:locations:deduplicate', $arguments)->assertSuccessful();
        $snapshot = $this->databaseSnapshot();

        $this->artisan('wms:locations:deduplicate', $arguments)
            ->expectsOutputToContain('0 grupo(s) consolidados y 0 ubicacion(es) creadas')
            ->assertSuccessful();

        $this->assertSame($snapshot, $this->databaseSnapshot());
        $this->assertSame(53, Location::query()->where('warehouse_id', $warehouse->id)->count());
    }

    public function test_command_defaults_to_dry_run_when_apply_is_not_explicit(): void
    {
        $warehouse = Warehouse::factory()->create();
        Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '7']);
        $this->insertRawLocation($warehouse->id, '07');

        $this->artisan('wms:locations:deduplicate')
            ->expectsOutputToContain('Dry-run')
            ->assertSuccessful();

        $this->assertSame(2, Location::query()->where('warehouse_id', $warehouse->id)->count());
    }

    /** @return array{Client, Warehouse, Location, int, int, int} */
    private function makeDuplicatedNave38(): array
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES', 'name' => 'Edelvives']);
        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
            'code' => '38',
            'name' => 'NAVE 38',
        ]);
        $canonical = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => '5',
            'name' => 'Calle 5',
            'active' => true,
        ]);
        $spacedId = $this->insertRawLocation($warehouse->id, ' 5 ');
        $zeroPaddedId = $this->insertRawLocation($warehouse->id, '05');
        $prefixedId = $this->insertRawLocation($warehouse->id, 'Calle 5');
        Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'FONDO',
            'active' => true,
        ]);

        return [$client, $warehouse, $canonical, $spacedId, $zeroPaddedId, $prefixedId];
    }

    private function insertRawLocation(int $warehouseId, string $code): int
    {
        return DB::table('locations')->insertGetId([
            'warehouse_id' => $warehouseId,
            'code' => $code,
            'name' => 'Duplicada historica',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /** @return array<string, array<int, array<string, mixed>>> */
    private function databaseSnapshot(): array
    {
        return [
            'locations' => DB::table('locations')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'stock' => DB::table('stock_pallets')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'items' => DB::table('items')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
            'receipt_lines' => DB::table('goods_receipt_lines')->orderBy('id')->get()->map(fn (object $row): array => (array) $row)->all(),
        ];
    }

    /** @return array<int, array<string, mixed>> */
    private function stockBusinessSnapshot(): array
    {
        return DB::table('stock_pallets')
            ->orderBy('id')
            ->get()
            ->map(function (object $row): array {
                $values = (array) $row;
                unset($values['location_id'], $values['created_at'], $values['updated_at']);

                return $values;
            })
            ->all();
    }
}
