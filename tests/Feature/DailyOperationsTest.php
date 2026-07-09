<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\Supplier;
use App\Models\User;
use App\Support\WmsLineType;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('STOCK BASE CLIENTE')
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

        $this->assertSame(2033, $day->opening_pallets);
        $this->assertSame(2044, $day->stored_pallets_today);
        $this->assertSame(33, $day->moved_pallets_today);
        $this->assertSame(2022, $day->expected_pallets_tomorrow);
        $this->assertSame(500, $otherDay->opening_pallets);

        $this->assertSame(11, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_DESCARGA)->sum('pallets'));
        $this->assertSame(12, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_CARGA)->sum('pallets'));
        $this->assertSame(10, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_ENVIO)->sum('pallets'));
        $this->assertSame(3, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->sum('pallets'));
        $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)->sum('pallets'));
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
            'counterparty_name' => 'Stock base del cliente',
            'pallets' => 1044,
            'is_auto_generated' => true,
        ]);
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

        $this->createStockPallet($client, 990, StockPallet::STATUS_AVAILABLE, $item);

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
        $this->assertSame(1, DailyOperationLine::query()->where('day_id', $day->id)->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)->sum('pallets'));
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

        $this->createStockPallet($client, 1, StockPallet::STATUS_AVAILABLE, $item);
        $this->createStockPallet($client, 1, StockPallet::STATUS_BLOCKED, $item);

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
            'counterparty_name' => 'Stock base del cliente',
            'pallets' => 2,
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

    public function test_view_shows_labels_with_accents_and_refined_layout_classes(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->get(route('daily-operations.index', ['date' => '2026-07-05', 'client_id' => $client->id]))
            ->assertOk()
            ->assertSee('Gestión de camión')
            ->assertSee('Viaje de camión')
            ->assertSee('Envío')
            ->assertSee('Horas operario')
            ->assertSee('STOCK BASE CLIENTE')
            ->assertSee('Pallets completos + picos actualmente almacenados.')
            ->assertSee('Stock base logístico + descargas del día.')
            ->assertSee('Descargas + salidas/envíos del día, incluyendo picos.')
            ->assertSee('Stock base + descargas - salidas/envíos.')
            ->assertSee('daily-ops-toolbar', false)
            ->assertSee('daily-ops-summary-form', false);
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

    private function createStockBase(Client $client, int $pallets): void
    {
        if ($pallets > 0) {
            $this->createStockPallet($client, $pallets, StockPallet::STATUS_AVAILABLE);
        }
    }

    private function createStockPallet(Client $client, int $fullPallets, string $status, ?Item $item = null): void
    {
        $item ??= Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 1,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'status' => $status,
            'quantity_units' => max(0, $fullPallets),
            'units_per_pallet' => 1,
            'full_pallets' => max(0, $fullPallets),
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
