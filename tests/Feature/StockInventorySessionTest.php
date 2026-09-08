<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockInventoryLocation;
use App\Models\StockInventorySession;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\StockInventorySessionService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class StockInventorySessionTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_open_inventory_per_client_and_independent_clients_are_supported(): void
    {
        [$first, , $firstLocation] = $this->clientWithStock('CLIENTE-UNO', '1', 'SKU-UNO');
        [$second, , $secondLocation] = $this->clientWithStock('CLIENTE-DOS', '1', 'SKU-DOS');
        $user = $this->user(Role::ALMACEN);

        $this->actingAs($user)->post(route('stock.inventory.start'), [
            'client_id' => $first->id,
            'scope_keys' => ['location:'.$firstLocation->id],
        ])->assertRedirect();

        $this->actingAs($user)->from(route('stock.inventory.index', ['client_id' => $first->id]))
            ->post(route('stock.inventory.start'), [
                'client_id' => $first->id,
                'scope_keys' => ['location:'.$firstLocation->id],
            ])
            ->assertSessionHasErrors('client_id');

        $this->actingAs($user)->post(route('stock.inventory.start'), [
            'client_id' => $second->id,
            'scope_keys' => ['location:'.$secondLocation->id],
        ])->assertRedirect();

        $this->assertSame(2, StockInventorySession::query()->count());
        $this->assertSame(1, StockInventorySession::query()->where('client_id', $first->id)->count());
        $this->assertSame(1, StockInventorySession::query()->where('client_id', $second->id)->count());
        $this->assertSame(2, AuditLog::query()->where('event', 'stock_inventory_started')->count());
    }

    public function test_check_persists_user_time_snapshot_and_progress_without_changing_stock_or_movements(): void
    {
        [$client, $stock, $location] = $this->clientWithStock('PERSISTE', '7', 'SKU-PERSISTE');
        $firstUser = $this->user(Role::ALMACEN);
        $session = $this->start($firstUser, $client);
        $inventoryLocation = $session->locations()->firstOrFail();
        $stockBefore = $stock->fresh()->getAttributes();
        $movementCount = InventoryMovement::query()->count();

        $this->actingAs($firstUser)
            ->patch(route('stock.inventory.locations.check', [$session, $inventoryLocation]), [
                'notes' => 'Conteo físico correcto.',
            ])
            ->assertRedirect()
            ->assertSessionHas('status');

        $checked = $inventoryLocation->fresh('checker');
        $this->assertSame(StockInventoryLocation::STATUS_CHECKED, $checked->check_state);
        $this->assertSame($firstUser->id, $checked->checked_by);
        $this->assertNotNull($checked->checked_at);
        $this->assertSame('SKU-PERSISTE', $checked->checked_snapshot[0]['sku']);
        $this->assertSame(2, $checked->checked_snapshot[0]['full_pallets']);
        $this->assertSame(1, $checked->checked_snapshot[0]['peaks']);
        $this->assertSame(50, $checked->checked_snapshot[0]['peak_units']);
        $this->assertSame('Conteo físico correcto.', $checked->notes);
        $this->assertSame($stockBefore, $stock->fresh()->getAttributes());
        $this->assertSame($movementCount, InventoryMovement::query()->count());
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'stock_inventory_location_checked',
            'client_id' => $client->id,
            'user_id' => $firstUser->id,
            'subject_id' => $checked->id,
        ]);

        $secondUser = $this->user(Role::ALMACEN);
        $this->actingAs($secondUser)
            ->get(route('stock.inventory.show', $session))
            ->assertOk()
            ->assertSee('100,0%')
            ->assertSee('is-checked', false)
            ->assertSee($location->code)
            ->assertSee('2 pallets')
            ->assertSee('1 picos / 50 uds');
    }

    public function test_location_and_reference_orders_are_natural_and_never_change_progress(): void
    {
        $client = Client::factory()->create(['name' => 'ORDEN']);
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id, 'code' => '38', 'name' => 'NAVE 38']);

        foreach ([['10', 'SKU-D'], ['2', 'SKU-C'], ['11', 'SKU-A'], ['1', 'SKU-B']] as [$code, $sku]) {
            $location = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => $code]);
            $this->stock($client, $location, $sku);
        }

        $user = $this->user(Role::ALMACEN);
        $session = $this->start($user, $client);
        $checked = $session->locations()->where('location_code', '1')->firstOrFail();
        app(StockInventorySessionService::class)->check($user, $session, $checked);
        $service = app(StockInventorySessionService::class);
        $byLocation = $service->viewData($user, $session, ['order' => 'location']);
        $byReference = $service->viewData($user, $session, ['order' => 'reference']);

        $this->assertSame(['1', '2', '10', '11'], $byLocation['locations']->pluck('model.location_code')->all());
        $this->assertSame(['SKU-A', 'SKU-B', 'SKU-C', 'SKU-D'], $byReference['locations']->pluck('first_sku')->all());
        $this->assertEqualsCanonicalizing(
            $byLocation['locations']->pluck('model.id')->all(),
            $byReference['locations']->pluck('model.id')->all(),
        );
        $this->assertSame(1, $service->summary($session)['checked']);
        $this->assertSame($user->id, $checked->fresh()->checked_by);
    }

    public function test_pending_checked_and_needs_review_filters_use_effective_state(): void
    {
        $client = Client::factory()->create();
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id]);
        $first = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '1']);
        $second = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '2']);
        $this->stock($client, $first, 'SKU-1');
        $this->stock($client, $second, 'SKU-2');
        $user = $this->user(Role::ALMACEN);
        $session = $this->start($user, $client);
        $checked = $session->locations()->where('location_id', $first->id)->firstOrFail();
        app(StockInventorySessionService::class)->check($user, $session, $checked);

        $service = app(StockInventorySessionService::class);
        $this->assertSame([$second->id], $service->viewData($user, $session, ['status' => 'pending'])['locations']->pluck('model.location_id')->all());
        $this->assertSame([$first->id], $service->viewData($user, $session, ['status' => 'checked'])['locations']->pluck('model.location_id')->all());

        $this->movement($client, InventoryMovement::RECEIPT, $first, unitsDelta: 10);
        $this->assertSame([$first->id], $service->viewData($user, $session, ['status' => 'needs_review'])['locations']->pluck('model.location_id')->all());
    }

    public function test_client_isolation_and_manual_session_or_line_id_tampering_are_denied(): void
    {
        [$own] = $this->clientWithStock('PROPIO', '1', 'SKU-PROPIO');
        [$other] = $this->clientWithStock('AJENO', '2', 'SKU-AJENO');
        $internal = $this->user(Role::ALMACEN);
        $ownSession = $this->start($internal, $own);
        $otherSession = $this->start($internal, $other);
        $clientUser = $this->user(Role::CLIENTE, $own);

        $this->actingAs($clientUser)->get(route('stock.inventory.show', $ownSession))->assertOk();
        $this->actingAs($clientUser)->get(route('stock.inventory.show', $otherSession))->assertForbidden();
        $this->actingAs($clientUser)
            ->patch(route('stock.inventory.locations.check', [$ownSession, $otherSession->locations()->firstOrFail()]))
            ->assertForbidden();
        $this->actingAs($clientUser)
            ->post(route('stock.inventory.complete', $otherSession), ['confirmed' => '1'])
            ->assertForbidden();
    }

    public function test_entry_dispatch_and_manual_adjustment_after_check_require_review_but_backfill_does_not(): void
    {
        [$client, , $location] = $this->clientWithStock('MOVIMIENTOS', '15', 'SKU-MOV');
        $user = $this->user(Role::ALMACEN);
        $session = $this->start($user, $client);
        $inventoryLocation = $session->locations()->firstOrFail();
        $service = app(StockInventorySessionService::class);

        $service->check($user, $session, $inventoryLocation);
        $this->movement($client, InventoryMovement::RECEIPT, $location, unitsDelta: 100);
        $this->assertSame(StockInventoryLocation::STATUS_NEEDS_REVIEW, $service->viewData($user, $session)['locations']->first()['status']);

        $service->check($user, $session, $inventoryLocation);
        $this->movement($client, InventoryMovement::DISPATCH, $location, unitsDelta: -50);
        $this->assertSame(StockInventoryLocation::STATUS_NEEDS_REVIEW, $service->viewData($user, $session)['locations']->first()['status']);

        $service->check($user, $session, $inventoryLocation);
        $this->movement($client, InventoryMovement::MANUAL_ADJUSTMENT, $location, unitsDelta: 25);
        $this->assertSame(StockInventoryLocation::STATUS_NEEDS_REVIEW, $service->viewData($user, $session)['locations']->first()['status']);

        $service->check($user, $session, $inventoryLocation);
        $this->movement($client, InventoryMovement::RECEIPT, $location, unitsDelta: 500, source: 'backfill');
        AuditLog::query()->create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $client->id,
            'event' => 'administrative_note',
            'module' => 'stock',
            'description' => 'Acción administrativa sin cambio físico.',
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
        $this->assertSame(StockInventoryLocation::STATUS_CHECKED, $service->viewData($user, $session)['locations']->first()['status']);
        $this->assertSame(3, AuditLog::query()->where('event', 'stock_inventory_location_rechecked')->count());
    }

    public function test_relocation_requires_review_for_source_and_destination(): void
    {
        $client = Client::factory()->create();
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id]);
        $source = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '1']);
        $destination = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '2']);
        $movingStock = $this->stock($client, $source, 'SKU-MOVER');
        $this->stock($client, $destination, 'SKU-DESTINO');
        $user = $this->user(Role::ALMACEN);
        $session = $this->start($user, $client);
        $service = app(StockInventorySessionService::class);

        foreach ($session->locations as $inventoryLocation) {
            $service->check($user, $session, $inventoryLocation);
        }

        $this->actingAs($user)->post(route('stock.relocations.store'), [
            'client_id' => $client->id,
            'item_id' => $movingStock->item_id,
            'stock_pallet_id' => $movingStock->id,
            'destination_location_id' => $destination->id,
        ])->assertRedirect();

        $this->assertSame(
            [StockInventoryLocation::STATUS_NEEDS_REVIEW, StockInventoryLocation::STATUS_NEEDS_REVIEW],
            $service->viewData($user, $session)['locations']->pluck('status')->sort()->values()->all(),
        );
    }

    public function test_inventory_can_only_finish_when_current_and_then_remains_in_history(): void
    {
        [$client, , $location] = $this->clientWithStock('CIERRE', '1', 'SKU-CIERRE');
        $user = $this->user(Role::ALMACEN);
        $session = $this->start($user, $client);
        $inventoryLocation = $session->locations()->firstOrFail();

        $this->actingAs($user)->post(route('stock.inventory.complete', $session), ['confirmed' => '1'])
            ->assertSessionHasErrors('inventory');

        app(StockInventorySessionService::class)->check($user, $session, $inventoryLocation);
        $this->movement($client, InventoryMovement::DISPATCH, $location, unitsDelta: -1);
        $this->actingAs($user)->post(route('stock.inventory.complete', $session), ['confirmed' => '1'])
            ->assertSessionHasErrors('inventory');

        app(StockInventorySessionService::class)->check($user, $session, $inventoryLocation);
        $this->actingAs($user)->post(route('stock.inventory.complete', $session), ['confirmed' => '1'])
            ->assertRedirect(route('stock.inventory.index', ['client_id' => $client->id]));

        $completed = $session->fresh();
        $this->assertSame(StockInventorySession::STATUS_COMPLETED, $completed->status);
        $this->assertNull($completed->open_slot);
        $this->assertSame(100, $completed->final_summary['progress']);
        $this->assertNotNull($completed->completed_at);
        $this->assertSame(StockInventoryLocation::STATUS_CHECKED, $inventoryLocation->fresh()->finalized_status);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'stock_inventory_completed',
            'client_id' => $client->id,
            'user_id' => $user->id,
        ]);

        $replacement = $this->start($user, $client);
        $this->assertNotSame($completed->id, $replacement->id);
        $this->assertTrue(app(StockInventorySessionService::class)->historyForClient($client->id)->contains('id', $completed->id));
    }

    public function test_two_users_can_check_different_locations_without_overwriting_each_other(): void
    {
        $client = Client::factory()->create();
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id]);
        $firstLocation = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '1']);
        $secondLocation = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '2']);
        $this->stock($client, $firstLocation, 'SKU-1');
        $this->stock($client, $secondLocation, 'SKU-2');
        $firstUser = $this->user(Role::ALMACEN);
        $secondUser = $this->user(Role::ALMACEN);
        $session = $this->start($firstUser, $client);

        $this->actingAs($firstUser)->patch(route('stock.inventory.locations.check', [
            $session, $session->locations()->where('location_id', $firstLocation->id)->firstOrFail(),
        ]))->assertRedirect();
        $this->actingAs($secondUser)->patch(route('stock.inventory.locations.check', [
            $session, $session->locations()->where('location_id', $secondLocation->id)->firstOrFail(),
        ]))->assertRedirect();

        $this->assertSame($firstUser->id, $session->locations()->where('location_id', $firstLocation->id)->value('checked_by'));
        $this->assertSame($secondUser->id, $session->locations()->where('location_id', $secondLocation->id)->value('checked_by'));
        $this->assertSame(2, app(StockInventorySessionService::class)->summary($session)['checked']);
    }

    public function test_natural_ranges_and_explicit_partial_location_selection_define_scope(): void
    {
        $client = Client::factory()->create();
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id, 'code' => '1']);
        $locations = collect();

        foreach (['1', '2', '3', '9', '10', '11'] as $code) {
            $location = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => $code]);
            $this->stock($client, $location, 'SKU-'.$code);
            $locations->put($code, $location);
        }

        $user = $this->user(Role::ALMACEN);
        $response = $this->actingAs($user)->get(route('stock.inventory.index', [
            'client_id' => $client->id,
            'warehouse_id' => $warehouse->id,
            'location_from' => '2',
            'location_to' => '10',
        ]));
        $response->assertOk();
        $this->assertSame(
            ['SKU-2', 'SKU-3', 'SKU-9', 'SKU-10'],
            $response->viewData('rows')->pluck('sku')->all(),
        );

        $session = app(StockInventorySessionService::class)->start($user, [
            'client_id' => $client->id,
            'warehouse_id' => $warehouse->id,
            'location_from' => '2',
            'location_to' => '10',
        ], ['location:'.$locations['2']->id, 'location:'.$locations['9']->id]);

        $this->assertEqualsCanonicalizing(
            [$locations['2']->id, $locations['9']->id],
            $session->locations()->pluck('location_id')->all(),
        );
    }

    public function test_client_without_location_visibility_cannot_operate_sessions_and_stock_details_keep_pallets_and_peaks(): void
    {
        [$hiddenClient] = $this->clientWithStock('OCULTO', '1', 'SKU-OCULTO');
        $hiddenClient->update(['show_storage_occupancy_to_client' => false]);
        $hiddenUser = $this->user(Role::CLIENTE, $hiddenClient);

        $this->actingAs($hiddenUser)->post(route('stock.inventory.start'), [
            'scope_keys' => ['unlocated'],
        ])->assertForbidden();

        [$visibleClient] = $this->clientWithStock('VISIBLE', '4', 'SKU-VISIBLE');
        $visibleUser = $this->user(Role::CLIENTE, $visibleClient);
        $session = $this->start($visibleUser, $visibleClient);

        $this->actingAs($visibleUser)->get(route('stock.inventory.show', $session))
            ->assertOk()
            ->assertSee('SKU-VISIBLE')
            ->assertSee('2 pallets')
            ->assertSee('1 picos / 50 uds')
            ->assertSee('250 uds');
    }

    /** @return array{Client, StockPallet, Location} */
    private function clientWithStock(string $name, string $locationCode, string $sku): array
    {
        $client = Client::factory()->create(['name' => $name]);
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id, 'code' => 'WH-'.$name]);
        $location = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => $locationCode]);

        return [$client, $this->stock($client, $location, $sku), $location];
    }

    private function stock(Client $client, Location $location, string $sku): StockPallet
    {
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => $sku,
            'description' => 'Descripción '.$sku,
            'units_per_pallet' => 100,
        ]);

        return StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'lot' => 'LOTE-'.$sku,
            'quantity_units' => 250,
            'units_per_pallet' => 100,
            'warehouse_pallets' => 3,
            'peak_1' => 50,
            'active' => true,
        ]);
    }

    private function user(string $roleSlug, ?Client $client = null): User
    {
        $this->seed(RoleSeeder::class);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $roleSlug === Role::CLIENTE ? $client?->id : null,
        ]);
    }

    private function start(User $user, Client $client): StockInventorySession
    {
        return app(StockInventorySessionService::class)->start($user, ['client_id' => $client->id]);
    }

    private function movement(
        Client $client,
        string $type,
        ?Location $location,
        int $unitsDelta = 0,
        ?Location $from = null,
        ?Location $to = null,
        string $source = 'live',
    ): InventoryMovement {
        return InventoryMovement::query()->create([
            'uuid' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'client_id' => $client->id,
            'movement_type' => $type,
            'source' => $source,
            'warehouse_id' => $location?->warehouse_id,
            'location_id' => $location?->id,
            'from_warehouse_id' => $from?->warehouse_id ?? $location?->warehouse_id,
            'from_location_id' => $from?->id ?? $location?->id,
            'to_warehouse_id' => $to?->warehouse_id ?? $location?->warehouse_id,
            'to_location_id' => $to?->id ?? $location?->id,
            'units_delta' => $unitsDelta,
            'full_pallets_delta' => 0,
            'warehouse_pallets_delta' => 0,
            'effective_at' => now(),
            'recorded_at' => now(),
            'created_at' => now(),
        ]);
    }
}
