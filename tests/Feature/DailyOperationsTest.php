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

        $this->assertSame(112, $day->stored_pallets_today);
        $this->assertSame(12, $day->moved_pallets_today);
        $this->assertSame(112, $day->expected_pallets_tomorrow);

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
            ->assertSee('Pallets iniciales')
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

    public function test_manual_descarga_generates_truck_management(): void
    {
        $this->assertManualOperationGeneratesTruckManagement(DailyOperationLine::SECTION_DESCARGA, 35, 'Generada por Descarga.');
    }

    public function test_manual_carga_generates_truck_management(): void
    {
        $this->assertManualOperationGeneratesTruckManagement(DailyOperationLine::SECTION_CARGA, 20, 'Generada por Carga.');
    }

    public function test_manual_envio_generates_truck_management(): void
    {
        $this->assertManualOperationGeneratesTruckManagement(DailyOperationLine::SECTION_ENVIO, 20, 'Generada por Envío.');
    }

    public function test_manual_viaje_de_camion_generates_truck_management(): void
    {
        $this->assertManualOperationGeneratesTruckManagement(DailyOperationLine::SECTION_VIAJE_CAMION, 2, 'Generada por Viaje de camión.');
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

    public function test_updating_manual_line_does_not_duplicate_associated_truck_management(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->post(route('daily-operations.lines.store'), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-03',
                'section' => DailyOperationLine::SECTION_CARGA,
                'counterparty_name' => 'Pedido X',
                'pallets' => 20,
                'observations' => 'Inicial',
            ])
            ->assertRedirect();

        $line = DailyOperationLine::query()
            ->where('section', DailyOperationLine::SECTION_CARGA)
            ->firstOrFail();

        $this->actingAs($user)
            ->put(route('daily-operations.lines.update', $line), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-03',
                'section' => DailyOperationLine::SECTION_CARGA,
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
    }

    public function test_daily_totals_follow_operational_billing_rules(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::factory()->create();

        $this->actingAs($user)
            ->post(route('daily-operations.day.upsert'), [
                'client_id' => $client->id,
                'operation_date' => '2026-07-04',
                'opening_pallets' => 1000,
                'notes' => 'Base inicial',
            ])
            ->assertRedirect();

        $this->storeLine($user, $client, '2026-07-04', DailyOperationLine::SECTION_DESCARGA, 'SAICA', 100);
        $this->storeLine($user, $client, '2026-07-04', DailyOperationLine::SECTION_ENVIO, 'Pedido X', 50);
        $this->storeLine($user, $client, '2026-07-04', DailyOperationLine::SECTION_GESTION_CAMION, 'Manual extra', 1);
        $this->storeLine($user, $client, '2026-07-04', DailyOperationLine::SECTION_VIAJE_CAMION, 'Pedido X', 2);

        $day = DailyOperationDay::query()
            ->whereDate('operation_date', '2026-07-04')
            ->where('client_id', $client->id)
            ->firstOrFail();

        $this->assertSame(1000, $day->opening_pallets);
        $this->assertSame(1100, $day->stored_pallets_today);
        $this->assertSame(150, $day->moved_pallets_today);
        $this->assertSame(1050, $day->expected_pallets_tomorrow);
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

        StockPallet::factory()->count(2)->create([
            'item_id' => $item->id,
            'client_id' => $client->id,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->post(route('daily-operations.recalculate'), [
                'operation_date' => '2026-07-02',
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-07-02', 'client_id' => $client->id]));

        $day->refresh();

        $this->assertSame(0, $day->opening_pallets);
        $this->assertSame(5, $day->stored_pallets_today);
        $this->assertSame(8, $day->moved_pallets_today);
        $this->assertSame(2, $day->expected_pallets_tomorrow);

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
            ->assertSee('daily-ops-toolbar', false)
            ->assertSee('daily-ops-summary-form', false);
    }

    private function assertManualOperationGeneratesTruckManagement(string $section, int $pallets, string $observation): void
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

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $parentLine->day_id,
            'section' => DailyOperationLine::SECTION_GESTION_CAMION,
            'counterparty_name' => 'Pedido X',
            'pallets' => 1,
            'parent_line_id' => $parentLine->id,
            'source_type' => DailyOperationLine::SOURCE_MANUAL_LINE,
            'observations' => $observation,
        ]);
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

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }
}
