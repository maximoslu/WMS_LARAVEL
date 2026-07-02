<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockImport;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Stock\StockOverviewBuilder;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockOverviewBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_calculates_operational_stock_totals_from_inventory_rows(): void
    {
        [$client] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'FR-1080',
            'description' => 'Pallet estandar',
            'units_per_pallet' => 1080,
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-A',
            'location_text' => 'A1-01',
            'quantity_units' => 70000,
            'units_per_pallet' => 1080,
            'full_pallets' => 64,
            'peaks_count' => 1,
            'peak_1' => 880,
            'received_at' => '2026-06-26',
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        $result = app(StockOverviewBuilder::class)->build($user, [
            'stock_state' => 'with_stock',
        ]);

        $this->assertSame(70000, $result['summary']['total_units']);
        $this->assertSame(64, $result['summary']['total_pallets']);
        $this->assertSame(64, $result['summary']['total_full_pallets']);
        $this->assertSame(1, $result['summary']['total_peaks']);
        $this->assertSame(65, $result['summary']['total_logistic_units']);
        $this->assertSame(1, $result['summary']['references_with_stock']);
    }

    public function test_builder_counts_logistic_only_rows_with_zero_quantity_as_stock(): void
    {
        [$client] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CONTRACOLADOS',
            'description' => 'Stock logistico',
            'units_per_pallet' => 1,
        ]);
        $stockImport = StockImport::query()->create([
            'client_id' => $client->id,
            'uploaded_by' => $user->id,
            'original_filename' => 'edelvives.xlsx',
            'stored_path' => 'stock-imports/test.xlsx',
            'status' => StockImport::STATUS_IMPORTED,
            'total_rows' => 1,
            'imported_rows' => 1,
            'skipped_rows' => 0,
            'available_rows' => 1,
            'blocked_rows' => 0,
            'detected_sheets_json' => ['processed' => ['STOCK']],
            'summary_json' => [],
            'warnings_json' => [],
            'errors_json' => [],
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'stock_import_id' => $stockImport->id,
            'lot' => 'SIN LOTE',
            'location_text' => '0',
            'quantity_units' => 0,
            'units_per_pallet' => 0,
            'full_pallets' => 24,
            'peaks_count' => 0,
            'received_at' => '2026-07-02',
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        $result = app(StockOverviewBuilder::class)->build($user, [
            'stock_state' => 'with_stock',
        ]);

        $this->assertSame(1, $result['summary']['references_with_stock']);
        $this->assertSame(0, $result['summary']['total_units']);
        $this->assertSame(24, $result['summary']['total_full_pallets']);
        $this->assertSame(0, $result['summary']['total_peaks']);
        $this->assertSame(24, $result['summary']['total_logistic_units']);
        $this->assertNotNull($result['rows']->firstWhere('sku', 'CONTRACOLADOS'));
    }

    public function test_builder_separates_same_sku_across_clients(): void
    {
        [$friesland, $edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $frItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'SKU-COMUN',
            'description' => 'Producto FR',
        ]);

        $edItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-COMUN',
            'description' => 'Producto ED',
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frItem->id,
            'lot' => 'FR-LOT',
            'quantity_units' => 700,
        ]);

        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'lot' => 'ED-LOT',
            'quantity_units' => 180,
        ]);

        $result = app(StockOverviewBuilder::class)->build($user, [
            'stock_state' => 'with_stock',
        ]);

        $this->assertCount(2, $result['rows']);
        $this->assertEqualsCanonicalizing(['FRIESLAND', 'EDELVIVES'], $result['rows']->pluck('client_code')->all());
    }

    public function test_builder_keeps_same_item_split_by_inventory_lot(): void
    {
        [$client] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-LOTE',
            'description' => 'Producto con lotes',
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-A',
            'quantity_units' => 500,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-B',
            'quantity_units' => 300,
        ]);

        $rows = app(StockOverviewBuilder::class)->build($user, [
            'stock_state' => 'with_stock',
        ])['rows']->where('sku', 'SKU-LOTE')->values();

        $this->assertCount(2, $rows);
        $this->assertEqualsCanonicalizing(['LOT-A', 'LOT-B'], $rows->pluck('lot')->all());
    }

    public function test_builder_reports_without_stock_items_when_requested(): void
    {
        [$client] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-SIN-STOCK',
        ]);

        $rows = app(StockOverviewBuilder::class)->build($user, [
            'stock_state' => 'without_stock',
        ])['rows'];

        $row = $rows->firstWhere('sku', $item->sku);

        $this->assertNotNull($row);
        $this->assertFalse($row['has_stock']);
        $this->assertSame('Sin stock', $row['batch_status_label']);
    }

    public function test_builder_hides_legacy_zero_quantity_batches_and_keeps_item_in_without_stock_view(): void
    {
        [$client] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-ZERO-LEGACY',
            'description' => 'Legacy a cero',
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => 0,
            'units_per_pallet' => 700,
            'full_pallets' => 0,
            'peaks_count' => 0,
            'peak_1' => 0,
        ]);

        $withStockRows = app(StockOverviewBuilder::class)->build($user, [
            'stock_state' => 'with_stock',
        ])['rows'];

        $withoutStockRows = app(StockOverviewBuilder::class)->build($user, [
            'stock_state' => 'without_stock',
        ])['rows'];

        $this->assertNull($withStockRows->firstWhere('sku', 'SKU-ZERO-LEGACY'));
        $this->assertNotNull($withoutStockRows->firstWhere('sku', 'SKU-ZERO-LEGACY'));
    }

    public function test_builder_prefers_location_code_over_legacy_text(): void
    {
        [$client] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $warehouse = Warehouse::factory()->create();
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A1-REAL',
        ]);

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-LOC',
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'location_text' => 'LEGACY-TEXT',
            'quantity_units' => 500,
        ]);

        $row = app(StockOverviewBuilder::class)->build($user, [
            'stock_state' => 'with_stock',
        ])['rows']->firstWhere('sku', 'SKU-LOC');

        $this->assertNotNull($row);
        $this->assertSame('A1-REAL', $row['location_label']);
    }

    public function test_builder_forces_client_scope_for_cliente_user(): void
    {
        [$friesland, $edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $friesland);

        $frItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'SKU-FR-CLIENT',
        ]);

        $edItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-ED-CLIENT',
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frItem->id,
            'quantity_units' => 100,
        ]);

        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'quantity_units' => 100,
        ]);

        $result = app(StockOverviewBuilder::class)->build($user, [
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'location' => 'AJENA',
            'location_id' => 999,
        ]);

        $this->assertSame($friesland->id, $result['filters']['client_id']);
        $this->assertNull($result['filters']['item_id']);
        $this->assertSame('', $result['filters']['location']);
        $this->assertNull($result['filters']['location_id']);
        $this->assertCount(1, $result['rows']);
        $this->assertSame('SKU-FR-CLIENT', $result['rows']->first()['sku']);
    }

    /**
     * @return array{0: Client, 1: Client}
     */
    private function seedClients(): array
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);

        return [
            Client::query()->where('code', 'FRIESLAND')->firstOrFail(),
            Client::query()->where('code', 'EDELVIVES')->firstOrFail(),
        ];
    }

    private function makeUserWithRole(string $roleSlug, ?Client $client = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $roleSlug === Role::CLIENTE ? $client?->id : null,
        ]);
    }
}
