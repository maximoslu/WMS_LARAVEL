<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsReceipt;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockAdjustmentTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmin_can_view_and_execute_stock_adjustments(): void
    {
        [$client, $item, $stockPallet] = $this->stockFixture();

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->get(route('stock.adjustments.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
            ]))
            ->assertOk()
            ->assertSee('Regularizar stock')
            ->assertSee('Aplicar regularizacion');

        foreach ([Role::ADMINISTRACION, Role::ALMACEN, Role::CLIENTE] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug, $roleSlug === Role::CLIENTE ? $client : null);

            $this->actingAs($user)
                ->get(route('stock.adjustments.create'))
                ->assertForbidden();

            $this->actingAs($user)
                ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, $stockPallet))
                ->assertForbidden();
        }

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, $stockPallet, [
                'full_pallets' => 1,
                'peak_units' => 0,
            ]))
            ->assertRedirect(route('stock.adjustments.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
            ]));
    }

    public function test_stock_index_shows_regularize_button_only_for_superadmin(): void
    {
        [$client] = $this->stockFixture();

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->get(route('stock.index', ['client_id' => $client->id]))
            ->assertOk()
            ->assertSee('Regularizar')
            ->assertSee(route('stock.adjustments.create', ['client_id' => $client->id]), false);

        foreach ([Role::ADMINISTRACION, Role::ALMACEN, Role::CLIENTE] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug, $roleSlug === Role::CLIENTE ? $client : null);

            $this->actingAs($user)
                ->get(route('stock.index', $roleSlug === Role::CLIENTE ? [] : ['client_id' => $client->id]))
                ->assertOk()
                ->assertDontSee('Regularizar');
        }
    }

    public function test_superadmin_adds_stock_to_existing_batch_and_records_traceability(): void
    {
        [$client, $item, $stockPallet, $location] = $this->stockFixture([
            'lot' => 'LOT-AJUSTE',
            'quantity_units' => 1000,
            'units_per_pallet' => 100,
            'stock_category' => StockPallet::CATEGORY_BLOCKED,
            'status' => StockPallet::STATUS_BLOCKED,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, $stockPallet, [
                'full_pallets' => 2,
                'peak_units' => 30,
                'note' => null,
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $stockPallet->fresh();

        $this->assertSame(1230, $fresh->quantity_units);
        $this->assertSame(12, $fresh->full_pallets);
        $this->assertSame(1, $fresh->peaks_count);
        $this->assertSame(30, $fresh->peak_1);
        $this->assertSame($client->id, $fresh->client_id);
        $this->assertSame($item->id, $fresh->item_id);
        $this->assertSame('LOT-AJUSTE', $fresh->lot);
        $this->assertSame($location->id, $fresh->location_id);
        $this->assertSame(StockPallet::CATEGORY_BLOCKED, $fresh->stock_category);
        $this->assertSame(StockPallet::STATUS_BLOCKED, $fresh->status);

        $movement = InventoryMovement::query()->latest('id')->firstOrFail();
        $this->assertSame(InventoryMovement::MANUAL_ADJUSTMENT, $movement->movement_type);
        $this->assertSame(230, $movement->units_delta);
        $this->assertSame('manual_superadmin_adjustment', $movement->source);
        $this->assertSame('add', $movement->metadata['action']);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'stock_manual_adjustment_added',
            'auditable_id' => $stockPallet->id,
        ]);
    }

    public function test_superadmin_creates_new_batch_without_goods_receipt_or_dispatch(): void
    {
        [$client, $item, , $location] = $this->stockFixture();
        $receiptCount = GoodsReceipt::query()->count();
        $dispatchCount = GoodsDispatch::query()->count();

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, null, [
                'mode' => 'new',
                'stock_pallet_id' => null,
                'lot' => 'LOT-NUEVO',
                'location_id' => $location->id,
                'status' => StockPallet::STATUS_OBSOLETE,
                'stock_category' => StockPallet::CATEGORY_OBSOLETE,
                'full_pallets' => 3,
                'peak_units' => 25,
            ]))
            ->assertSessionHasNoErrors();

        $fresh = StockPallet::query()->where('lot', 'LOT-NUEVO')->firstOrFail();

        $this->assertSame(325, $fresh->quantity_units);
        $this->assertSame(3, $fresh->full_pallets);
        $this->assertSame(1, $fresh->peaks_count);
        $this->assertSame(25, $fresh->peak_1);
        $this->assertSame($location->id, $fresh->location_id);
        $this->assertSame(StockPallet::STATUS_OBSOLETE, $fresh->status);
        $this->assertSame(StockPallet::CATEGORY_OBSOLETE, $fresh->stock_category);
        $this->assertSame($receiptCount, GoodsReceipt::query()->count());
        $this->assertSame($dispatchCount, GoodsDispatch::query()->count());
        $this->assertDatabaseHas('inventory_movements', [
            'stock_pallet_id' => $fresh->id,
            'movement_type' => InventoryMovement::MANUAL_ADJUSTMENT,
            'units_delta' => 325,
        ]);
    }

    public function test_superadmin_removes_stock_without_negative_and_keeps_zero_batch(): void
    {
        [$client, $item, $stockPallet] = $this->stockFixture([
            'quantity_units' => 250,
            'units_per_pallet' => 100,
        ]);
        $dispatchCount = GoodsDispatch::query()->count();

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, $stockPallet, [
                'action' => 'remove',
                'full_pallets' => 2,
                'peak_units' => 50,
            ]))
            ->assertSessionHasNoErrors();

        $fresh = $stockPallet->fresh();

        $this->assertTrue($fresh->active);
        $this->assertSame(0, $fresh->quantity_units);
        $this->assertSame(0, $fresh->full_pallets);
        $this->assertSame(0, $fresh->peaks_count);
        $this->assertSame(0, $fresh->peak_1);
        $this->assertSame($dispatchCount, GoodsDispatch::query()->count());
        $this->assertDatabaseHas('inventory_movements', [
            'stock_pallet_id' => $stockPallet->id,
            'movement_type' => InventoryMovement::MANUAL_ADJUSTMENT,
            'units_delta' => -250,
        ]);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'stock_manual_adjustment_removed',
            'auditable_id' => $stockPallet->id,
        ]);
    }

    public function test_adjustment_validations_reject_unsafe_inputs(): void
    {
        [$client, $item, $stockPallet] = $this->stockFixture([
            'quantity_units' => 100,
            'units_per_pallet' => 100,
        ]);
        $otherClient = Client::factory()->create(['active' => true]);
        $otherItem = Item::factory()->create(['client_id' => $otherClient->id]);
        $inactiveLocation = $this->locationForClient($client, 'OFF-01', false);

        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, $stockPallet, [
                'action' => 'remove',
                'full_pallets' => 2,
                'peak_units' => 0,
            ]))
            ->assertSessionHasErrors('full_pallets');

        $this->actingAs($user)
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, $stockPallet, [
                'full_pallets' => 0,
                'peak_units' => 0,
            ]))
            ->assertSessionHasErrors('full_pallets');

        $this->actingAs($user)
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, $stockPallet, [
                'full_pallets' => -1,
            ]))
            ->assertSessionHasErrors('full_pallets');

        $this->actingAs($user)
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, null, [
                'mode' => 'new',
                'stock_pallet_id' => null,
                'location_id' => $inactiveLocation->id,
            ]))
            ->assertSessionHasErrors('location_id');

        $this->actingAs($user)
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $otherItem, null, [
                'mode' => 'new',
                'stock_pallet_id' => null,
            ]))
            ->assertSessionHasErrors('item_id');

        $this->assertSame(100, $stockPallet->fresh()->quantity_units);
    }

    public function test_history_shows_last_adjustments_with_user_reference_action_and_delta(): void
    {
        [$client, $item, $stockPallet] = $this->stockFixture([
            'lot' => 'LOT-HISTORIAL',
            'quantity_units' => 100,
            'units_per_pallet' => 100,
        ]);
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->post(route('stock.adjustments.store'), $this->validPayload($client, $item, $stockPallet, [
                'full_pallets' => 1,
                'peak_units' => 0,
                'note' => null,
            ]))
            ->assertSessionHasNoErrors();

        $this->actingAs($user)
            ->get(route('stock.adjustments.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
            ]))
            ->assertOk()
            ->assertSee('Ultimas regularizaciones')
            ->assertSee($user->name)
            ->assertSee($item->sku)
            ->assertSee('Anadir')
            ->assertSee('100 uds')
            ->assertSee('LOT-HISTORIAL');
    }

    public function test_stock_adjustments_do_not_break_stock_relocation_import_receipts_or_dispatches(): void
    {
        [$client, $item, $stockPallet] = $this->stockFixture();
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('stock.index', ['client_id' => $client->id]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('stock.relocations.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
            ]))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('stock.import'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('goods-receipts.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('dispatches.index'))
            ->assertOk();

        $this->assertSame(0, AuditLog::query()->where('module', 'stock')->count());
    }

    /** @param array<string, mixed> $stockOverrides */
    private function stockFixture(array $stockOverrides = []): array
    {
        $this->seed(RoleSeeder::class);

        $client = Client::factory()->create(['active' => true]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-AJUSTE-'.fake()->unique()->numerify('###'),
            'description' => 'Articulo para ajuste manual',
            'units_per_pallet' => 100,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
        ]);
        $location = $this->locationForClient($client, 'AJ-'.fake()->unique()->numerify('##'));
        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'lot' => 'LOT-AJUSTE',
            'quantity_units' => 1000,
            'units_per_pallet' => 100,
            'full_pallets' => 10,
            'peaks_count' => 0,
            'warehouse_pallets' => 10,
            'peak_1' => 0,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
            ...$stockOverrides,
        ]);

        return [$client, $item, $stockPallet, $location];
    }

    private function locationForClient(Client $client, string $code, bool $active = true): Location
    {
        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
            'code' => 'WH-'.$code,
            'active' => true,
        ]);

        return Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => $code,
            'active' => $active,
        ]);
    }

    private function makeUserWithRole(string $roleSlug, ?Client $client = null): User
    {
        $this->seed(RoleSeeder::class);

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $roleSlug === Role::CLIENTE ? $client?->id : null,
        ]);
    }

    /** @param array<string, mixed> $overrides */
    private function validPayload(Client $client, Item $item, ?StockPallet $stockPallet, array $overrides = []): array
    {
        return [
            'client_id' => $client->id,
            'item_id' => $item->id,
            'action' => 'add',
            'mode' => $stockPallet instanceof StockPallet ? 'existing' : 'new',
            'stock_pallet_id' => $stockPallet?->id,
            'lot' => $stockPallet?->lot ?? 'SIN LOTE',
            'location_id' => $stockPallet?->location_id,
            'status' => $stockPallet?->status ?? StockPallet::STATUS_AVAILABLE,
            'stock_category' => $stockPallet?->stock_category ?? StockPallet::CATEGORY_IN_USE,
            'full_pallets' => 1,
            'units_per_pallet' => $stockPallet?->units_per_pallet ?: $item->units_per_pallet ?: 100,
            'peak_units' => 0,
            'note' => 'Nota interna de prueba',
            'confirmed' => '1',
            ...$overrides,
        ];
    }
}
