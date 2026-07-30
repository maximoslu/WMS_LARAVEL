<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\WmsLineType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class DailyOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_administracion_and_almacen_can_access_daily_operations(): void
    {
        $this->seed(RoleSeeder::class);
        $client = Client::factory()->create();

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('daily-operations.index', ['client_id' => $client->id]))
                ->assertOk()
                ->assertSee('Operaciones diarias');
        }
    }

    public function test_cliente_cannot_access_daily_operations(): void
    {
        $this->seed(RoleSeeder::class);
        $client = Client::factory()->create();
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('daily-operations.index', ['client_id' => $client->id]))
            ->assertForbidden();
    }

    public function test_can_create_day_summary_and_operation_line(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->post(route('daily-operations.day.upsert'), [
                'client_id' => $client->id,
                'operation_date' => '2026-06-29',
                'opening_pallets' => 100,
                'notes' => 'Cierre operativo del día.',
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-06-29', 'client_id' => $client->id]));

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-06-29')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->actingAs($user)
            ->post(route('daily-operations.lines.store'), [
                'client_id' => $client->id,
                'operation_date' => '2026-06-29',
                'section' => DailyOperationLine::SECTION_DESCARGA,
                'counterparty_name' => 'Transporte Norte',
                'pallets' => 12,
                'observations' => 'Recepción de proveedor.',
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-06-29', 'client_id' => $client->id]));

        $day->refresh();

        $this->assertSame(0, $day->opening_pallets);
        $this->assertSame(12, $day->stored_pallets_today);
        $this->assertSame(12, $day->moved_pallets_today);
        $this->assertSame(12, $day->expected_pallets_tomorrow);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_DESCARGA,
            'counterparty_name' => 'Transporte Norte',
            'pallets' => 12,
            'is_auto_generated' => false,
        ]);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_GESTION_CAMION,
            'counterparty_name' => 'Transporte Norte',
            'pallets' => 1,
            'source_type' => DailyOperationLine::SOURCE_MANUAL_LINE,
            'is_auto_generated' => true,
        ]);
    }

    public function test_daily_operations_can_filter_by_selected_date_and_client_and_show_totals(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::factory()->create(['name' => 'Cliente Sur']);

        $day = DailyOperationDay::query()->create([
            'operation_date' => '2026-06-30',
            'client_id' => $client->id,
            'opening_pallets' => 50,
            'stored_pallets_today' => 57,
            'moved_pallets_today' => 7,
            'expected_pallets_tomorrow' => 43,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $day->lines()->create([
            'section' => DailyOperationLine::SECTION_CARGA,
            'counterparty_name' => 'Cliente Sur',
            'pallets' => 7,
            'observations' => 'Carga de expedición.',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('daily-operations.index', ['date' => '2026-06-30', 'client_id' => $client->id]))
            ->assertOk()
            ->assertSee('Cliente Sur')
            ->assertSee('57')
            ->assertSee('PALLETS FACTURABLES DEL DIA')
            ->assertSee('50');
    }

    public function test_same_date_can_have_independent_daily_operation_days_per_client(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $clientA = Client::factory()->create(['name' => 'Friesland']);
        $clientB = Client::factory()->create(['name' => 'Edelvives']);

        $dayA = DailyOperationDay::query()->create([
            'operation_date' => '2026-07-01',
            'client_id' => $clientA->id,
            'opening_pallets' => 10,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $dayB = DailyOperationDay::query()->create([
            'operation_date' => '2026-07-01',
            'client_id' => $clientB->id,
            'opening_pallets' => 22,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $dayA->lines()->create([
            'section' => DailyOperationLine::SECTION_DESCARGA,
            'counterparty_name' => 'Proveedor A',
            'pallets' => 3,
            'created_by' => $user->id,
        ]);

        $dayB->lines()->create([
            'section' => DailyOperationLine::SECTION_ENVIO,
            'counterparty_name' => 'Destino B',
            'pallets' => 8,
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('daily-operations.index', ['date' => '2026-07-01', 'client_id' => $clientA->id]))
            ->assertOk()
            ->assertSee('Proveedor A')
            ->assertDontSee('Destino B');

        $this->actingAs($user)
            ->get(route('daily-operations.index', ['date' => '2026-07-01', 'client_id' => $clientB->id]))
            ->assertOk()
            ->assertSee('Destino B')
            ->assertDontSee('Proveedor A');
    }

    public function test_manual_descarga_generates_truck_management_and_counts_as_movement(): void
    {
        $this->assertManualOperationAssociations(DailyOperationLine::SECTION_DESCARGA, 35, true, false);
    }

    public function test_manual_carga_generates_truck_management_and_counts_as_movement(): void
    {
        $this->assertManualOperationAssociations(DailyOperationLine::SECTION_CARGA, 20, true, false);
    }

    public function test_manual_envio_generates_truck_management_and_trip_and_counts_as_movement(): void
    {
        $this->assertManualOperationAssociations(DailyOperationLine::SECTION_ENVIO, 20, true, true);
    }

    public function test_manual_viaje_de_camion_generates_truck_management_but_does_not_count_as_movement(): void
    {
        $this->assertManualOperationAssociations(DailyOperationLine::SECTION_VIAJE_CAMION, 2, true, false);
    }

    public function test_manual_gestion_camion_does_not_generate_another_truck_management(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->post(route('daily-operations.lines.store'), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-03',
                'section' => DailyOperationLine::SECTION_GESTION_CAMION,
                'counterparty_name' => 'Pedido X',
                'pallets' => 1,
                'observations' => 'Manual',
            ])
            ->assertRedirect();

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-03')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->count());
        $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->count());
    }

    public function test_almacenaje_and_truck_management_do_not_count_as_pallet_movement(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->post(route('daily-operations.day.upsert'), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-07',
            ])
            ->assertRedirect();

        $this->createStockBase($client, 50);

        $this->storeLine($user, $client, '2026-07-07', DailyOperationLine::SECTION_ALMACENAJE, 'Base', 50);
        $this->storeLine($user, $client, '2026-07-07', DailyOperationLine::SECTION_GESTION_CAMION, 'Manual', 2);
        $this->storeLine($user, $client, '2026-07-07', DailyOperationLine::SECTION_HORAS_OPERARIO, 'Refuerzo', 4);

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-07')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(50, $day->opening_pallets);
        $this->assertSame(0, $day->moved_pallets_today);
        $this->assertSame(50, $day->stored_pallets_today);
        $this->assertSame(50, $day->expected_pallets_tomorrow);
    }

    public function test_updating_manual_line_does_not_duplicate_associated_lines(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->post(route('daily-operations.lines.store'), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-03',
                'section' => DailyOperationLine::SECTION_ENVIO,
                'counterparty_name' => 'Pedido X',
                'pallets' => 20,
                'observations' => 'Inicial',
            ])
            ->assertRedirect();

        $line = DailyOperationLine::query()
            ->where('section', DailyOperationLine::SECTION_ENVIO)
            ->firstOrFail();

        $this->actingAs($user)
            ->put(route('daily-operations.lines.update', $line), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-03',
                'section' => DailyOperationLine::SECTION_ENVIO,
                'counterparty_name' => 'Pedido X',
                'pallets' => 21,
                'observations' => 'Ajustada',
            ])
            ->assertRedirect();

        $this->assertSame(
            1,
            DailyOperationLine::query()
                ->where('day_id', $line->day_id)
                ->where('section', DailyOperationLine::SECTION_GESTION_CAMION)
                ->where('source_type', DailyOperationLine::SOURCE_MANUAL_LINE)
                ->where('parent_line_id', $line->id)
                ->count()
        );

        $this->assertSame(
            1,
            DailyOperationLine::query()
                ->where('day_id', $line->day_id)
                ->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)
                ->where('source_type', DailyOperationLine::SOURCE_MANUAL_LINE)
                ->where('parent_line_id', $line->id)
                ->count()
        );
    }

    public function test_deleting_parent_line_removes_associated_automatic_lines(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->post(route('daily-operations.lines.store'), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-08',
                'section' => DailyOperationLine::SECTION_ENVIO,
                'counterparty_name' => 'Pedido X',
                'pallets' => 10,
                'observations' => 'Manual',
            ])
            ->assertRedirect();

        $parentLine = DailyOperationLine::query()
            ->where('section', DailyOperationLine::SECTION_ENVIO)
            ->firstOrFail();

        $this->assertSame(3, DailyOperationLine::query()->where('day_id', $parentLine->day_id)->count());

        $this->actingAs($user)
            ->delete(route('daily-operations.lines.destroy', $parentLine))
            ->assertRedirect();

        $this->assertSame(0, DailyOperationLine::query()->where('day_id', $parentLine->day_id)->count());
    }

    public function test_daily_totals_follow_operational_billing_rules(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create(['name' => 'Friesland']);
        $otherClient = Client::factory()->create(['name' => 'Edelvives']);

        $this->createStockBase($client, 2000);
        $this->createStockPallet($client, 30, StockPallet::STATUS_BLOCKED);
        $this->createStockPallet($client, 3, StockPallet::STATUS_AVAILABLE);
        $this->createStockPallet($client, 99, StockPallet::STATUS_OBSOLETE);
        $this->createStockPallet($client, 0, StockPallet::STATUS_AVAILABLE);
        $this->createStockBase($otherClient, 500);

        $obsoleteItem = Item::factory()->create([
            'client_id' => $client->id,
            'status' => Item::STATUS_OBSOLETE,
            'active' => false,
            'units_per_pallet' => 1,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $obsoleteItem->id,
            'status' => StockPallet::STATUS_AVAILABLE,
            'quantity_units' => 40,
            'units_per_pallet' => 1,
            'full_pallets' => 40,
            'peaks_count' => 0,
            'peak_1' => 0,
        ]);

        $this->actingAs($user)
            ->post(route('daily-operations.day.upsert'), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-04',
                'notes' => 'Base inicial',
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->post(route('daily-operations.day.upsert'), [
                'client_id' => $otherClient->id,
                'operation_date' => '2026-07-04',
                'notes' => 'Base aislada',
            ])
            ->assertRedirect();

        $this->storeLine($user, $client, '2026-07-04', DailyOperationLine::SECTION_DESCARGA, 'Entrada A', 11);
        $this->storeLine($user, $client, '2026-07-04', DailyOperationLine::SECTION_CARGA, 'Carga B', 12);
        $this->storeLine($user, $client, '2026-07-04', DailyOperationLine::SECTION_ENVIO, 'Pedido X', 10);

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-04')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $otherDay = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-04')
            ->where('client_id', $otherClient->id)
            ->firstOrFail();

        $otherDay->refresh();

        $this->assertSame(2172, $day->opening_pallets);
        $this->assertSame(2183, $day->stored_pallets_today);
        $this->assertSame(33, $day->moved_pallets_today);
        $this->assertSame(2161, $day->expected_pallets_tomorrow);
        $this->assertSame(500, $otherDay->opening_pallets);

        $this->assertSame(11, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_DESCARGA)->sum('pallets'));
        $this->assertSame(12, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_CARGA)->sum('pallets'));
        $this->assertSame(10, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_ENVIO)->sum('pallets'));
        $this->assertSame(3, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->sum('pallets'));
        $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)->sum('pallets'));
    }

    public function test_stock_fisico_para_ocupacion_y_facturacion_incluye_las_cuatro_categorias_y_export_oficial_solo_dos(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create(['name' => 'Friesland']);

        $this->createStockPallet($client, 10, StockPallet::STATUS_AVAILABLE, null, StockPallet::CATEGORY_IN_USE);
        $this->createStockPallet($client, 5, StockPallet::STATUS_BLOCKED, null, StockPallet::CATEGORY_BLOCKED);
        $this->createStockPallet($client, 3, StockPallet::STATUS_OBSOLETE, null, StockPallet::CATEGORY_OBSOLETE);
        $this->createStockPallet($client, 2, StockPallet::STATUS_AVAILABLE, null, StockPallet::CATEGORY_MISC);

        $this->actingAs($user)
            ->post(route('daily-operations.day.upsert'), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-24',
                'notes' => 'Base fisica',
            ])
            ->assertRedirect();

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-24')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $officialRows = app(\App\Services\Stock\StockExportService::class)->rows($client->id);

        $this->assertSame(20, $day->opening_pallets);
        $this->assertSame(20, $day->stored_pallets_today);
        $this->assertSame(15.0, (float) $officialRows->sum('total_pallets'));
        $this->assertSame(2, $officialRows->count());
    }

    public function test_stock_base_counts_full_pallets_and_picos_for_edelvives_billing(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create(['name' => 'EDELVIVES']);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'EDELVIVES-LOGISTIC-UNITS',
            'units_per_pallet' => 100,
        ]);

        $this->createStockPallet($client, 948, StockPallet::STATUS_AVAILABLE);

        foreach (range(1, 96) as $_) {
            $this->createStockPalletWithPeaks($client, 0, 1, StockPallet::STATUS_AVAILABLE, $item);
        }

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-09',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-09', 'client_id' => $client->id]));

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-09')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(1044, $day->opening_pallets);
        $this->assertSame(1044, $day->stored_pallets_today);
        $this->assertSame(0, $day->moved_pallets_today);
        $this->assertSame(1044, $day->expected_pallets_tomorrow);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_ALMACENAJE,
            'counterparty_name' => 'Pallets facturables del dia',
            'pallets' => 1044,
            'is_auto_generated' => true,
        ]);
    }

    public function test_recalculate_reconstructs_opening_stock_when_same_day_dispatch_already_reduced_current_stock(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create(['name' => 'EDELVIVES']);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 100,
        ]);

        $this->createStockPallet($client, 1035, StockPallet::STATUS_AVAILABLE, $item);

        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => '2026-07-09 09:00:00',
            'created_by' => $user->id,
            'camion_propio' => false,
        ]);

        GoodsDispatchLine::query()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'sku' => 'EDELVIVES-OUT',
            'description' => 'Salida EDELVIVES',
            'units_per_pallet' => 100,
            'pallets' => 9,
            'requested_units' => 900,
            'requested_pallets' => 9,
            'loaded_pallets' => 9,
            'is_extra_line' => false,
        ]);

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-09',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-09', 'client_id' => $client->id]));

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-09')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(1044, $day->opening_pallets);
        $this->assertSame(1044, $day->stored_pallets_today);
        $this->assertSame(9, $day->moved_pallets_today);
        $this->assertSame(1035, $day->expected_pallets_tomorrow);
        $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->sum('pallets'));
        $this->assertSame(0, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)->sum('pallets'));
    }

    public function test_current_day_recalculate_excludes_internal_relocations_from_edelvives_billing_base(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-30 12:00:00', 'Europe/Madrid'));

        try {
            $this->seed(RoleSeeder::class);
            $user = $this->makeUserWithRole(Role::ALMACEN);
            $client = Client::factory()->create([
                'name' => 'EDELVIVES',
                'code' => 'EDELVIVES',
            ]);
            $warehouse = Warehouse::factory()->create([
                'client_id' => $client->id,
                'code' => '38',
                'name' => 'NAVE 38',
                'active' => true,
            ]);
            $source = Location::factory()->create([
                'warehouse_id' => $warehouse->id,
                'code' => '10',
                'active' => true,
            ]);
            $destination = Location::factory()->create([
                'warehouse_id' => $warehouse->id,
                'code' => '12',
                'active' => true,
            ]);
            $dispatchLocation = Location::factory()->create([
                'warehouse_id' => $warehouse->id,
                'code' => '14',
                'active' => true,
            ]);
            $item = Item::factory()->create([
                'client_id' => $client->id,
                'sku' => 'EDELVIVES-30-07',
                'units_per_pallet' => 1,
            ]);

            DailyOperationDay::query()->create([
                'operation_date' => '2026-07-29',
                'client_id' => $client->id,
                'opening_pallets' => 1131,
                'stored_pallets_today' => 1131,
                'moved_pallets_today' => 0,
                'expected_pallets_tomorrow' => 1131,
                'created_by' => $user->id,
                'updated_by' => $user->id,
            ]);

            $relocatedStock = StockPallet::factory()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'location_id' => $source->id,
                'quantity_units' => 52,
                'units_per_pallet' => 1,
                'full_pallets' => 52,
                'warehouse_pallets' => 52,
                'active' => true,
                'status' => StockPallet::STATUS_AVAILABLE,
            ]);
            $dispatchStock = StockPallet::factory()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'location_id' => $dispatchLocation->id,
                'quantity_units' => 1027,
                'units_per_pallet' => 1,
                'full_pallets' => 1027,
                'warehouse_pallets' => 1027,
                'active' => true,
                'status' => StockPallet::STATUS_AVAILABLE,
            ]);
            $totals = app(\App\Services\DailyOperations\DailyOperationTotalsService::class);

            $this->assertSame(1079, $totals->stockBaseForClient($client->id));

            $this->actingAs($user)
                ->post(route('stock.relocations.store'), [
                    'client_id' => $client->id,
                    'item_id' => $item->id,
                    'stock_pallet_id' => $relocatedStock->id,
                    'destination_location_id' => $destination->id,
                ])
                ->assertRedirect();

            $transfer = InventoryMovement::query()
                ->where('movement_type', InventoryMovement::TRANSFER)
                ->firstOrFail();

            $this->assertSame(52.0, (float) $transfer->warehouse_pallets_before);
            $this->assertSame(52.0, (float) $transfer->warehouse_pallets_after);
            $this->assertSame('0.00', (string) $transfer->warehouse_pallets_delta);
            $this->assertSame(1079, $totals->stockBaseForClient($client->id));

            $dispatch = GoodsDispatch::factory()->create([
                'id' => 41,
                'client_id' => $client->id,
                'status' => GoodsDispatch::STATUS_SENT,
                'sent_at' => '2026-07-30 09:00:00',
                'created_by' => $user->id,
                'camion_propio' => true,
                'stock_applied_at' => '2026-07-30 09:00:00',
                'stock_applied_by' => $user->id,
                'warehouse_stock_applied_at' => '2026-07-30 09:00:00',
                'warehouse_stock_applied_by' => $user->id,
            ]);

            GoodsDispatchLine::query()->create([
                'goods_dispatch_id' => $dispatch->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $dispatchStock->id,
                'line_type' => WmsLineType::PALLET,
                'sku' => 'EDELVIVES-30-07',
                'description' => 'Salida EDELVIVES 30/07',
                'units_per_pallet' => 1,
                'pallets' => 10,
                'requested_units' => 10,
                'requested_pallets' => 10,
                'requested_peaks' => 0,
                'loaded_pallets' => 10,
                'loaded_peaks' => 0,
                'is_extra_line' => false,
            ]);

            $dispatchStock->forceFill([
                'quantity_units' => 1017,
                'full_pallets' => 1017,
                'warehouse_pallets' => 1017,
            ])->save();

            $this->assertSame(1069, $totals->stockBaseForClient($client->id));

            foreach (range(1, 2) as $_) {
                $this->actingAs($user)
                    ->post(route('daily-operations.recalculate'), [
                        'operation_date' => '2026-07-30',
                        'client_id' => $client->id,
                    ])
                    ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-30', 'client_id' => $client->id]));
            }

            $day = DailyOperationDay::query()
                ->whereDate('operation_date', '2026-07-30')
                ->where('client_id', $client->id)
                ->firstOrFail();

            $this->assertSame(1079, $day->opening_pallets);
            $this->assertSame(1079, $day->stored_pallets_today);
            $this->assertSame(10, $day->moved_pallets_today);
            $this->assertSame(1069, $day->expected_pallets_tomorrow);
            $this->assertSame(0, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_DESCARGA)->sum('pallets'));
            $this->assertSame(10, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_ENVIO)->sum('pallets'));
            $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->sum('pallets'));
            $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)->sum('pallets'));
            $this->assertSame(1079, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_ALMACENAJE)->sum('pallets'));
            $this->assertSame(4, DailyOperationLine::query()->where('day_id', $day->id)->where('is_auto_generated', true)->count());
        } finally {
            Carbon::setTestNow();
        }
    }

    public function test_recalculate_uses_logistic_units_for_storage_movements_and_tomorrow_base(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create(['name' => 'EDELVIVES']);
        $supplier = Supplier::factory()->create([
            'client_id' => $client->id,
            'name' => 'MONDI',
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 100,
        ]);

        $this->createStockPallet($client, 1000, StockPallet::STATUS_AVAILABLE, $item);

        foreach (range(1, 10) as $_) {
            $this->createStockPalletWithPeaks($client, 0, 1, StockPallet::STATUS_AVAILABLE, $item);
        }

        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'receipt_number' => 'RCPT-LOG-001',
            'status' => GoodsReceipt::STATUS_CONFIRMED,
            'received_at' => '2026-07-09',
            'created_by' => $user->id,
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => 'SKU-IN',
            'description' => 'Entrada con pico',
            'lot' => 'LOT-IN',
            'quantity_units' => 2950,
            'units_per_pallet' => 100,
            'pallet_count' => 29,
            'pico_units' => 50,
        ]);

        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => '2026-07-09 10:00:00',
            'created_by' => $user->id,
            'camion_propio' => false,
        ]);

        GoodsDispatchLine::query()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'line_type' => WmsLineType::PALLET,
            'sku' => 'SKU-OUT-PALLET',
            'description' => 'Salida pallets',
            'units_per_pallet' => 100,
            'pallets' => 19,
            'requested_units' => 1900,
            'requested_pallets' => 19,
            'requested_peaks' => 0,
            'loaded_pallets' => 19,
            'loaded_peaks' => 0,
            'is_extra_line' => false,
        ]);

        GoodsDispatchLine::query()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'line_type' => WmsLineType::PEAK,
            'sku' => 'SKU-OUT-PEAK',
            'description' => 'Salida pico',
            'units_per_pallet' => 100,
            'units_per_peak' => 30,
            'pallets' => 0,
            'requested_units' => 30,
            'requested_pallets' => 0,
            'requested_peaks' => 1,
            'loaded_pallets' => 0,
            'loaded_peaks' => 1,
            'is_extra_line' => false,
        ]);

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-09',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-09', 'client_id' => $client->id]));

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-09')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(1000, $day->opening_pallets);
        $this->assertSame(1030, $day->stored_pallets_today);
        $this->assertSame(50, $day->moved_pallets_today);
        $this->assertSame(1010, $day->expected_pallets_tomorrow);

        $this->assertSame(30, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_DESCARGA)->sum('pallets'));
        $this->assertSame(20, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_ENVIO)->sum('pallets'));
        $this->assertSame(2, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->sum('pallets'));
        $this->assertSame(0, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)->sum('pallets'));
    }

    public function test_edelvives_daily_operations_reconcile_closed_july_29_case(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create([
            'name' => 'EDELVIVES',
            'code' => 'EDELVIVES',
        ]);
        $supplier = Supplier::factory()->create([
            'client_id' => $client->id,
            'name' => 'EDELVIVES proveedor',
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 1,
        ]);

        DailyOperationDay::query()->create([
            'operation_date' => '2026-07-28',
            'client_id' => $client->id,
            'opening_pallets' => 1026,
            'stored_pallets_today' => 1026,
            'moved_pallets_today' => 0,
            'expected_pallets_tomorrow' => 1026,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);
        $this->createStockPallet($client, 1079, StockPallet::STATUS_AVAILABLE, $item);

        $this->createConfirmedReceipt($client, $supplier, $item, $user, 'EDE-029-1', '2026-07-29', [9]);
        $this->createConfirmedReceipt($client, $supplier, $item, $user, 'EDE-029-2', '2026-07-29', [9]);
        $this->createConfirmedReceipt($client, $supplier, $item, $user, 'EDE-029-3', '2026-07-29', [7, 11]);
        [$receipt40, $receipt40Line] = $this->createConfirmedReceipt($client, $supplier, $item, $user, 'EDE-040', '2026-07-29', [29]);
        $this->recordReceiptWarehouseMovement($receipt40, $receipt40Line, 31, $user);

        foreach ([10, 4] as $index => $pallets) {
            $dispatch = GoodsDispatch::factory()->create([
                'client_id' => $client->id,
                'status' => GoodsDispatch::STATUS_SENT,
                'sent_at' => '2026-07-29 1'.$index.':00:00',
                'created_by' => $user->id,
                'camion_propio' => false,
            ]);

            GoodsDispatchLine::query()->create([
                'goods_dispatch_id' => $dispatch->id,
                'item_id' => $item->id,
                'line_type' => WmsLineType::PALLET,
                'sku' => 'EDE-OUT-'.$pallets,
                'description' => 'Salida EDELVIVES '.$pallets,
                'units_per_pallet' => 1,
                'pallets' => $pallets,
                'requested_units' => $pallets,
                'requested_pallets' => $pallets,
                'requested_peaks' => 0,
                'loaded_pallets' => $pallets,
                'loaded_peaks' => 0,
                'is_extra_line' => false,
            ]);
        }

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-29',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-29', 'client_id' => $client->id]));

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-29')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(1026, $day->opening_pallets);
        $this->assertSame(1093, $day->stored_pallets_today);
        $this->assertSame(81, $day->moved_pallets_today);
        $this->assertSame(1079, $day->expected_pallets_tomorrow);
        $this->assertSame(1079, app(\App\Services\DailyOperations\DailyOperationTotalsService::class)->stockBaseForClient($client->id));
        $this->assertSame(67, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_DESCARGA)->sum('pallets'));
        $this->assertSame(14, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_ENVIO)->sum('pallets'));

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_DESCARGA,
            'source_type' => DailyOperationLine::SOURCE_GOODS_RECEIPT,
            'source_id' => $receipt40->id,
            'pallets' => 31,
        ]);
        $this->assertSame(29, $receipt40Line->fresh()->pallet_count);
    }

    public function test_recalculate_does_not_drop_receipt_lines_without_individual_stock_movement(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create([
            'name' => 'EDELVIVES',
            'code' => 'EDELVIVES',
        ]);
        $supplier = Supplier::factory()->create([
            'client_id' => $client->id,
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'BOBINAS-100',
            'description' => 'Bobinas 100',
            'units_per_pallet' => 1,
        ]);
        $this->createStockPallet($client, 21, StockPallet::STATUS_AVAILABLE, $item);

        [$receipt, $firstLine] = $this->createConfirmedReceipt(
            $client,
            $supplier,
            $item,
            $user,
            'ALB-BOBINAS-100',
            '2026-07-29',
            [11, 10],
        );
        $secondLine = $receipt->lines()->whereKeyNot($firstLine->id)->firstOrFail();
        $this->recordReceiptWarehouseMovement($receipt, $firstLine, 11, $user);

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-29',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-29', 'client_id' => $client->id]));

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-29')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(0, $day->opening_pallets);
        $this->assertSame(21, $day->stored_pallets_today);
        $this->assertSame(21, $day->moved_pallets_today);
        $this->assertSame(21, $day->expected_pallets_tomorrow);
        $this->assertSame(21, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_DESCARGA)->sum('pallets'));
        $this->assertSame(10, $secondLine->fresh()->pallet_count);
    }

    public function test_confirmed_receipt_edits_rebuild_daily_operations_from_current_lines_without_duplicating_movements(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $client = Client::factory()->create([
            'name' => 'EDELVIVES',
            'code' => 'EDELVIVES',
        ]);
        $supplier = Supplier::factory()->create([
            'client_id' => $client->id,
            'name' => 'EDELVIVES proveedor',
        ]);
        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
            'code' => '38',
            'name' => 'NAVE 38',
        ]);
        $location11 = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => '11',
        ]);
        $location10 = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => '10',
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'BOBINAS-100',
            'description' => 'Bobinas 100',
            'units_per_pallet' => 100,
        ]);

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-BOBINAS-IDEMP',
                'received_at' => '2026-07-29',
                'camion_propio' => false,
                'lines' => [
                    $this->receiptLinePayload($item, $location11, 11),
                    $this->receiptLinePayload($item, $location10, 10),
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()
            ->where('receipt_number', 'ALB-BOBINAS-IDEMP')
            ->firstOrFail();

        $this->assertSame(2, $receipt->lines()->count());

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $this->assertReceiptDailyState($user, $client, $receipt->fresh(), 21);
        $this->assertReceiptStock($receipt, 21, 2100, 2);

        $this->updateConfirmedReceiptToPallets($user, $receipt, $item, $location11, 21);
        $this->assertReceiptDailyState($user, $client, $receipt->fresh(), 21);
        $this->assertReceiptStock($receipt, 21, 2100, 1);

        foreach (range(1, 3) as $_) {
            $this->updateConfirmedReceiptToPallets($user, $receipt, $item, $location11, 21);
            $this->assertReceiptDailyState($user, $client, $receipt->fresh(), 21);
            $this->assertReceiptStock($receipt, 21, 2100, 1);
        }

        $this->updateConfirmedReceiptToPallets($user, $receipt, $item, $location11, 23);
        $this->assertReceiptDailyState($user, $client, $receipt->fresh(), 23);
        $this->assertReceiptStock($receipt, 23, 2300, 1);

        $this->updateConfirmedReceiptToPallets($user, $receipt, $item, $location11, 18);
        $day = $this->assertReceiptDailyState($user, $client, $receipt->fresh(), 18);
        $this->assertReceiptStock($receipt, 18, 1800, 1);

        $this->assertGreaterThan(
            2,
            InventoryMovement::query()
                ->where('source_type', $receipt->getMorphClass())
                ->where('source_id', $receipt->id)
                ->where('movement_type', InventoryMovement::RECEIPT)
                ->count()
        );

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-29',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-29', 'client_id' => $client->id]));

        $day->refresh();

        $this->assertSame(18, $day->stored_pallets_today);
        $this->assertSame(18, $day->moved_pallets_today);
        $this->assertSame(18, $day->expected_pallets_tomorrow);
        $this->assertSame(3, DailyOperationLine::query()->where('day_id', $day->id)->count());
        $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_DESCARGA)->count());
    }

    public function test_recalculate_counts_trips_only_for_receipts_and_dispatches_marked_as_own_truck(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create();
        $supplier = Supplier::factory()->create(['client_id' => $client->id]);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);

        $this->createStockPallet($client, 10, StockPallet::STATUS_AVAILABLE, $item);

        foreach ([true, false] as $ownTruck) {
            $receipt = GoodsReceipt::factory()->create([
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'status' => GoodsReceipt::STATUS_CONFIRMED,
                'received_at' => '2026-07-10',
                'camion_propio' => $ownTruck,
                'created_by' => $user->id,
            ]);

            GoodsReceiptLine::query()->create([
                'goods_receipt_id' => $receipt->id,
                'item_id' => $item->id,
                'sku' => $ownTruck ? 'IN-OWN' : 'IN-EXT',
                'description' => 'Entrada test',
                'quantity_units' => 100,
                'units_per_pallet' => 100,
                'pallet_count' => 1,
            ]);

            $dispatch = GoodsDispatch::factory()->create([
                'client_id' => $client->id,
                'status' => GoodsDispatch::STATUS_SENT,
                'sent_at' => '2026-07-10 10:00:00',
                'camion_propio' => $ownTruck,
                'created_by' => $user->id,
            ]);

            GoodsDispatchLine::query()->create([
                'goods_dispatch_id' => $dispatch->id,
                'item_id' => $item->id,
                'sku' => $ownTruck ? 'OUT-OWN' : 'OUT-EXT',
                'description' => 'Salida test',
                'units_per_pallet' => 100,
                'pallets' => 1,
                'requested_units' => 100,
                'requested_pallets' => 1,
                'loaded_pallets' => 1,
                'is_extra_line' => false,
            ]);
        }

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-10',
                'client_id' => $client->id,
            ])
            ->assertRedirect();

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-10')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(4, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->sum('pallets'));
        $this->assertSame(2, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)->sum('pallets'));
    }

    public function test_recalculate_builds_automatic_lines_and_preserves_manual_lines_without_duplicates(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create(['name' => 'Friesland']);
        $supplier = Supplier::factory()->create([
            'client_id' => $client->id,
            'name' => 'Transportes Norte',
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
        ]);

        $day = DailyOperationDay::query()->create([
            'operation_date' => '2026-07-02',
            'client_id' => $client->id,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $day->lines()->create([
            'section' => DailyOperationLine::SECTION_GESTION,
            'counterparty_name' => 'Ajuste manual',
            'pallets' => 2,
            'observations' => 'Línea manual',
            'sort_order' => 1,
            'created_by' => $user->id,
        ]);

        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'receipt_number' => 'RCPT-001',
            'status' => GoodsReceipt::STATUS_CONFIRMED,
            'received_at' => '2026-07-02',
            'created_by' => $user->id,
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
        ]);

        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => 'SKU-R1',
            'description' => 'Producto recepcionado',
            'lot' => 'LOT-001',
            'quantity_units' => 500,
            'units_per_pallet' => 100,
            'pallet_count' => 5,
            'pico_units' => 0,
        ]);

        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => '2026-07-02 09:30:00',
            'created_by' => $user->id,
            'camion_propio' => true,
        ]);

        GoodsDispatchLine::query()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'sku' => 'SKU-D1',
            'description' => 'Producto expedido',
            'units_per_pallet' => 100,
            'pallets' => 3,
            'requested_units' => 300,
            'requested_pallets' => 3,
            'loaded_pallets' => 3,
            'is_extra_line' => false,
        ]);

        $this->createStockPallet($client, 2, StockPallet::STATUS_AVAILABLE, $item);
        $this->createStockPallet($client, 2, StockPallet::STATUS_BLOCKED, $item);

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-02',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-02', 'client_id' => $client->id]));

        $day->refresh();

        $this->assertSame(2, $day->opening_pallets);
        $this->assertSame(7, $day->stored_pallets_today);
        $this->assertSame(8, $day->moved_pallets_today);
        $this->assertSame(4, $day->expected_pallets_tomorrow);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_DESCARGA,
            'counterparty_name' => 'Transportes Norte',
            'pallets' => 5,
            'is_auto_generated' => true,
        ]);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_ENVIO,
            'counterparty_name' => 'Friesland',
            'pallets' => 3,
            'is_auto_generated' => true,
        ]);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_GESTION_CAMION,
            'counterparty_name' => 'Friesland',
            'pallets' => 1,
            'source_type' => DailyOperationLine::SOURCE_GOODS_DISPATCH,
            'is_auto_generated' => true,
        ]);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_VIAJE_CAMION,
            'counterparty_name' => 'Friesland',
            'pallets' => 1,
            'is_auto_generated' => true,
        ]);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_ALMACENAJE,
            'counterparty_name' => 'Pallets facturables del dia',
            'pallets' => 7,
            'is_auto_generated' => true,
        ]);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_GESTION,
            'counterparty_name' => 'Ajuste manual',
            'pallets' => 2,
            'is_auto_generated' => false,
        ]);

        $this->assertSame(6, DailyOperationLine::query()->where('day_id', $day->id)->where('is_auto_generated', true)->count());

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-02',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-02', 'client_id' => $client->id]));

        $this->assertSame(7, DailyOperationLine::query()->where('day_id', $day->id)->count());
        $this->assertSame(6, DailyOperationLine::query()->where('day_id', $day->id)->where('is_auto_generated', true)->count());
    }

    public function test_view_shows_simplified_billing_layout_without_noisy_sections(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->get(route('daily-operations.index', ['date' => '2026-07-05', 'client_id' => $client->id]))
            ->assertOk()
            ->assertSee('PALLETS FACTURABLES DEL DIA')
            ->assertSee('PALLETS MOVIDOS DEL DIA')
            ->assertSee('GESTIONES DE CAMION')
            ->assertSee('VIAJES')
            ->assertDontSee('Reglas actuales')
            ->assertDontSee('Nueva línea operativa')
            ->assertDontSee('Movimiento diario')
            ->assertSee('daily-ops-toolbar', false)
            ->assertDontSee('daily-ops-total-chip', false);
    }

    private function assertManualOperationAssociations(string $section, int $pallets, bool $expectsManagement, bool $expectsTrip): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->post(route('daily-operations.lines.store'), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-03',
                'section' => $section,
                'counterparty_name' => 'Pedido X',
                'pallets' => $pallets,
                'observations' => 'Manual',
            ])
            ->assertRedirect();

        $parentLine = DailyOperationLine::query()
            ->where('section', $section)
            ->where('counterparty_name', 'Pedido X')
            ->firstOrFail();

        $managementCount = DailyOperationLine::query()
            ->where('day_id', $parentLine->day_id)
            ->where('section', DailyOperationLine::SECTION_GESTION_CAMION)
            ->where('parent_line_id', $parentLine->id)
            ->count();

        $tripCount = DailyOperationLine::query()
            ->where('day_id', $parentLine->day_id)
            ->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)
            ->where('parent_line_id', $parentLine->id)
            ->count();

        $this->assertSame($expectsManagement ? 1 : 0, $managementCount);
        $this->assertSame($expectsTrip ? 1 : 0, $tripCount);
    }

    private function storeLine(User $user, Client $client, string $date, string $section, string $counterpartyName, int $pallets): void
    {
        $this->actingAs($user)
            ->post(route('daily-operations.lines.store'), [
                'client_id' => $client->id,
                'operation_date' => $date,
                'section' => $section,
                'counterparty_name' => $counterpartyName,
                'pallets' => $pallets,
                'observations' => 'Automática de test',
            ])
            ->assertRedirect();
    }

    private function receiptLinePayload(Item $item, Location $location, int $pallets): array
    {
        return [
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => 'BOB-100',
            'quantity_units' => $pallets * 100,
            'units_per_pallet' => 100,
            'pallet_count' => $pallets,
            'pico_units' => '',
            'location_id' => $location->id,
        ];
    }

    private function updateConfirmedReceiptToPallets(
        User $user,
        GoodsReceipt $receipt,
        Item $item,
        Location $location,
        int $pallets,
    ): void {
        $receipt->refresh();

        $this->actingAs($user)
            ->put(route('goods-receipts.update', $receipt), [
                'client_id' => $receipt->client_id,
                'supplier_id' => $receipt->supplier_id,
                'receipt_number' => $receipt->receipt_number,
                'received_at' => $receipt->received_at?->format('Y-m-d'),
                'camion_propio' => false,
                'lines' => [
                    $this->receiptLinePayload($item, $location, $pallets),
                ],
            ])
            ->assertRedirect(route('goods-receipts.show', $receipt));
    }

    private function assertReceiptDailyState(User $user, Client $client, GoodsReceipt $receipt, int $pallets): DailyOperationDay
    {
        $date = $receipt->received_at?->format('Y-m-d') ?? '2026-07-29';

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => $date,
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => $date, 'client_id' => $client->id]));

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', $date)
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(0, $day->opening_pallets);
        $this->assertSame($pallets, $day->stored_pallets_today);
        $this->assertSame($pallets, $day->moved_pallets_today);
        $this->assertSame($pallets, $day->expected_pallets_tomorrow);
        $this->assertSame($pallets, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_DESCARGA)->sum('pallets'));
        $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_DESCARGA)->count());
        $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->sum('pallets'));
        $this->assertSame(3, DailyOperationLine::query()->where('day_id', $day->id)->count());

        return $day;
    }

    private function assertReceiptStock(GoodsReceipt $receipt, int $warehousePallets, int $quantityUnits, int $batchCount): void
    {
        $query = StockPallet::query()->where('goods_receipt_id', $receipt->id);

        $this->assertSame($batchCount, (int) $query->count());
        $this->assertSame($quantityUnits, (int) $query->sum('quantity_units'));
        $this->assertSame((float) $warehousePallets, (float) $query->sum('warehouse_pallets'));
    }

    public function test_recalculate_counts_multiple_dispatches_for_same_request_as_independent_trucks(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create(['name' => 'Friesland']);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 100,
        ]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'status' => MerchandiseRequest::STATUS_PARTIALLY_FULFILLED,
        ]);

        $this->createStockPallet($client, 7, StockPallet::STATUS_AVAILABLE, $item);

        foreach ([1 => 2, 2 => 3] as $sequence => $pallets) {
            $dispatch = GoodsDispatch::factory()->create([
                'client_id' => $client->id,
                'merchandise_request_id' => $request->id,
                'shipment_sequence' => $sequence,
                'type' => GoodsDispatch::TYPE_REQUEST,
                'status' => GoodsDispatch::STATUS_SENT,
                'sent_at' => '2026-07-10 10:0'.$sequence.':00',
                'created_by' => $user->id,
                'camion_propio' => true,
            ]);

            GoodsDispatchLine::query()->create([
                'goods_dispatch_id' => $dispatch->id,
                'item_id' => $item->id,
                'line_type' => WmsLineType::PALLET,
                'sku' => 'MULTI-DAILY-'.$sequence,
                'description' => 'Salida parcial '.$sequence,
                'units_per_pallet' => 100,
                'pallets' => $pallets,
                'requested_units' => $pallets * 100,
                'requested_pallets' => $pallets,
                'loaded_pallets' => $pallets,
                'is_extra_line' => false,
            ]);
        }

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-10',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-10', 'client_id' => $client->id]));

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-10')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(12, $day->opening_pallets);
        $this->assertSame(12, $day->stored_pallets_today);
        $this->assertSame(5, $day->moved_pallets_today);
        $this->assertSame(7, $day->expected_pallets_tomorrow);
        $this->assertSame(2, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_ENVIO)->count());
        $this->assertSame(2, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->sum('pallets'));
        $this->assertSame(2, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)->sum('pallets'));
    }

    private function createStockBase(Client $client, int $pallets): void
    {
        if ($pallets > 0) {
            $this->createStockPallet($client, $pallets, StockPallet::STATUS_AVAILABLE);
        }
    }

    private function createConfirmedReceipt(
        Client $client,
        Supplier $supplier,
        Item $item,
        User $user,
        string $receiptNumber,
        string $date,
        array $linePallets,
    ): array {
        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'receipt_number' => $receiptNumber,
            'status' => GoodsReceipt::STATUS_CONFIRMED,
            'received_at' => $date,
            'created_by' => $user->id,
            'confirmed_by' => $user->id,
            'confirmed_at' => now(),
        ]);
        $firstLine = null;

        foreach ($linePallets as $index => $pallets) {
            $line = GoodsReceiptLine::query()->create([
                'goods_receipt_id' => $receipt->id,
                'item_id' => $item->id,
                'sku' => $receiptNumber.'-'.$index,
                'description' => 'Entrada EDELVIVES '.$pallets,
                'lot' => 'LOT-'.$receiptNumber,
                'quantity_units' => $pallets,
                'units_per_pallet' => 1,
                'pallet_count' => $pallets,
                'pico_units' => null,
            ]);

            $firstLine ??= $line;
        }

        return [$receipt, $firstLine];
    }

    private function recordReceiptWarehouseMovement(GoodsReceipt $receipt, GoodsReceiptLine $line, int $warehousePallets, User $user): void
    {
        InventoryMovement::query()->create([
            'uuid' => (string) \Illuminate\Support\Str::uuid(),
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'idempotency_key' => 'daily-ops-test-receipt-'.$receipt->id.'-'.$line->id,
            'client_id' => $receipt->client_id,
            'client_name' => $receipt->client?->name,
            'item_id' => $line->item_id,
            'sku' => $line->sku,
            'description' => $line->description,
            'lot' => $line->lot,
            'movement_type' => InventoryMovement::RECEIPT,
            'source_type' => $receipt->getMorphClass(),
            'source_id' => $receipt->id,
            'source_line_type' => $line->getMorphClass(),
            'source_line_id' => $line->id,
            'user_id' => $user->id,
            'user_name' => $user->name,
            'units_before' => 0,
            'units_delta' => $warehousePallets,
            'units_after' => $warehousePallets,
            'full_pallets_before' => 0,
            'full_pallets_delta' => $warehousePallets,
            'full_pallets_after' => $warehousePallets,
            'warehouse_pallets_before' => 0,
            'warehouse_pallets_delta' => $warehousePallets,
            'warehouse_pallets_after' => $warehousePallets,
            'peaks_before' => [],
            'peaks_delta' => [],
            'peaks_after' => [],
            'metadata' => ['receipt_number' => $receipt->receipt_number],
            'effective_at' => $receipt->received_at?->copy()->startOfDay() ?? now(),
            'recorded_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function createStockPallet(
        Client $client,
        int $fullPallets,
        string $status,
        ?Item $item = null,
        string $stockCategory = StockPallet::CATEGORY_IN_USE,
    ): void
    {
        $item ??= Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 1,
            'stock_category' => $stockCategory,
            'status' => match ($stockCategory) {
                StockPallet::CATEGORY_BLOCKED => Item::STATUS_BLOCKED,
                StockPallet::CATEGORY_OBSOLETE => Item::STATUS_OBSOLETE,
                default => Item::STATUS_ACTIVE,
            },
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'status' => $status,
            'stock_category' => $stockCategory,
            'quantity_units' => max(0, $fullPallets),
            'units_per_pallet' => 1,
            'full_pallets' => max(0, $fullPallets),
            'warehouse_pallets' => max(0, $fullPallets),
            'peaks_count' => 0,
            'peak_1' => 0,
            'active' => true,
        ]);
    }

    private function createStockPalletWithPeaks(Client $client, int $fullPallets, int $peaksCount, string $status, ?Item $item = null): void
    {
        $item ??= Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 100,
        ]);

        $peakColumns = [];

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $peakColumns['peak_'.$peakNumber] = $peakNumber <= $peaksCount ? 1 : 0;
        }

        StockPallet::factory()->create(array_merge([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'status' => $status,
            'quantity_units' => ($fullPallets * 100) + $peaksCount,
            'units_per_pallet' => 100,
            'full_pallets' => max(0, $fullPallets),
            'peaks_count' => max(0, $peaksCount),
            'active' => true,
        ], $peakColumns));
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }
}
