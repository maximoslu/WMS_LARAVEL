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
use Tests\TestCase;

class LocationDeduplicationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_audit_is_read_only_and_apply_consolidates_real_variants_without_losing_stock(): void
    {
        [$client, $warehouse, $canonical, $spacedId, $zeroPaddedId] = $this->makeDuplicatedNave38();
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

        $this->assertDatabaseMissing('locations', ['id' => $spacedId]);
        $this->assertDatabaseMissing('locations', ['id' => $zeroPaddedId]);
        $this->assertSame(3, StockPallet::query()->where('location_id', $canonical->id)->count());
        $this->assertSame($canonical->id, $item->fresh()->default_location_id);
        $this->assertSame($canonical->id, $receipt->lines()->firstOrFail()->location_id);
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

    /** @return array{Client, Warehouse, Location, int, int} */
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
        Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'FONDO',
            'active' => true,
        ]);

        return [$client, $warehouse, $canonical, $spacedId, $zeroPaddedId];
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
