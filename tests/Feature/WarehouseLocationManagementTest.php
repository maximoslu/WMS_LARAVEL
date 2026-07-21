<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class WarehouseLocationManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_and_administracion_can_list_warehouses(): void
    {
        $this->seedBaseData();
        Warehouse::factory()->create([
            'code' => 'MAX-01',
            'name' => 'MAXIMO PRINCIPAL',
        ]);

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('warehouses.index'))
                ->assertOk()
                ->assertSee('Almacenes')
                ->assertSee('MAX-01');
        }
    }

    public function test_administracion_can_create_warehouse(): void
    {
        $this->seedBaseData();
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();

        $this->actingAs($user)
            ->post(route('warehouses.store'), [
                'client_id' => $client->id,
                'code' => ' fr-wh-01 ',
                'name' => ' Friesland principal ',
                'active' => '1',
            ])
            ->assertRedirect(route('warehouses.index'));

        $this->assertDatabaseHas('warehouses', [
            'client_id' => $client->id,
            'code' => 'FR-WH-01',
            'name' => 'Friesland principal',
            'active' => true,
        ]);
    }

    public function test_almacen_can_view_but_cannot_create_warehouses(): void
    {
        $this->seedBaseData();
        Warehouse::factory()->create([
            'code' => 'MAX-01',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('warehouses.index'))
            ->assertOk()
            ->assertSee('MAX-01');

        $this->actingAs($user)
            ->get(route('warehouses.create'))
            ->assertForbidden();
    }

    public function test_locations_index_loads_for_almacen(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create([
            'code' => 'MAX-01',
        ]);
        Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A1-01',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('locations.index'))
            ->assertOk()
            ->assertSee('Ubicaciones')
            ->assertSee('A1-01');
    }

    public function test_almacen_can_create_location_inside_warehouse(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create([
            'code' => 'MAX-01',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->post(route('locations.store'), [
                'warehouse_id' => $warehouse->id,
                'code' => ' a1-01 ',
                'name' => ' Hueco frontal ',
                'zone' => ' BULK ',
                'aisle' => ' A1 ',
                'rack' => ' 01 ',
                'level' => ' 01 ',
                'position' => ' 01 ',
                'active' => '1',
            ])
            ->assertRedirect(route('locations.index'));

        $this->assertDatabaseHas('locations', [
            'warehouse_id' => $warehouse->id,
            'code' => 'A1-01',
            'name' => 'Hueco frontal',
            'zone' => 'BULK',
            'aisle' => 'A1',
        ]);
    }

    public function test_almacen_sees_new_location_button_and_edit_action(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create([
            'code' => 'MAX-01',
        ]);
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A1-01',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('locations.index'))
            ->assertOk()
            ->assertSee('Nueva ubicacion')
            ->assertSee(route('locations.create'), false)
            ->assertSee(route('locations.edit', $location), false)
            ->assertDontSee(route('locations.toggle-active', $location), false);
    }

    public function test_duplicate_location_code_is_not_allowed_within_same_warehouse(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create();
        Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A1-01',
        ]);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($user)
            ->from(route('locations.create'))
            ->post(route('locations.store'), [
                'warehouse_id' => $warehouse->id,
                'code' => 'a1-01',
                'name' => 'Duplicada',
                'active' => '1',
            ])
            ->assertRedirect(route('locations.create'))
            ->assertSessionHasErrors('code');
    }

    public function test_same_location_code_is_allowed_in_different_warehouses(): void
    {
        $this->seedBaseData();
        $firstWarehouse = Warehouse::factory()->create(['code' => 'MAX-01']);
        $secondWarehouse = Warehouse::factory()->create(['code' => 'MAX-02']);

        Location::factory()->create([
            'warehouse_id' => $firstWarehouse->id,
            'code' => 'A1-01',
        ]);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($user)
            ->post(route('locations.store'), [
                'warehouse_id' => $secondWarehouse->id,
                'code' => 'A1-01',
                'name' => 'Repetida en otro almacen',
                'active' => '1',
            ])
            ->assertRedirect(route('locations.index'));

        $this->assertDatabaseCount('locations', 2);
    }

    public function test_numeric_location_codes_are_normalized_before_duplicate_validation(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create(['code' => 'MAX-38']);
        Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '6']);
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->from(route('locations.create'))
            ->post(route('locations.store'), [
                'warehouse_id' => $warehouse->id,
                'code' => ' 06 ',
                'active' => '1',
            ])
            ->assertRedirect(route('locations.create'))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Location::query()->where('warehouse_id', $warehouse->id)->count());
    }

    public function test_prefixed_calle_location_code_is_normalized_before_duplicate_validation(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create(['code' => '38', 'name' => 'NAVE 38']);
        Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '11']);
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->from(route('locations.create'))
            ->post(route('locations.store'), [
                'warehouse_id' => $warehouse->id,
                'code' => ' Calle 11 ',
                'active' => '1',
            ])
            ->assertRedirect(route('locations.create'))
            ->assertSessionHasErrors('code');

        $this->assertSame(1, Location::query()->where('warehouse_id', $warehouse->id)->count());
    }

    public function test_editing_location_cannot_duplicate_physical_location_with_prefixed_code(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create(['code' => '38', 'name' => 'NAVE 38']);
        Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '11']);
        $editable = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '12']);
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->from(route('locations.edit', $editable))
            ->put(route('locations.update', $editable), [
                'warehouse_id' => $warehouse->id,
                'code' => 'NAVE 38 - Calle 11',
                'active' => '1',
            ])
            ->assertRedirect(route('locations.edit', $editable))
            ->assertSessionHasErrors('code');

        $this->assertSame('12', $editable->fresh()->code);
    }

    public function test_nave_38_locations_index_uses_natural_order_and_canonical_warehouse_filter(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create([
            'client_id' => null,
            'code' => '38',
            'name' => 'NAVE 38',
        ]);

        foreach (['10', '0', 'F', '2', 'A', '11', '1'] as $code) {
            Location::factory()->create([
                'warehouse_id' => $warehouse->id,
                'code' => $code,
                'active' => true,
            ]);
        }

        $duplicateWarehouse = Warehouse::factory()->create([
            'client_id' => null,
            'code' => '038',
            'name' => 'NAVE 38',
            'active' => true,
        ]);
        DB::table('locations')->insert([
            'warehouse_id' => $duplicateWarehouse->id,
            'code' => '02',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $content = $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('locations.index', ['warehouse_id' => $warehouse->id]))
            ->assertOk()
            ->assertSee('NAVE 38')
            ->assertSee('Mostrando ubicaciones de NAVE 38')
            ->assertDontSee('value="'.$duplicateWarehouse->id.'"', false)
            ->getContent();

        $positions = collect(['0', '1', '2', '10', '11', 'A', 'F'])
            ->mapWithKeys(fn (string $code): array => [$code => strpos($content, '<td><strong>'.$code.'</strong></td>')]);

        $positions->each(fn ($position) => $this->assertNotFalse($position));

        $this->assertTrue(
            $positions['0'] < $positions['1']
            && $positions['1'] < $positions['2']
            && $positions['2'] < $positions['10']
            && $positions['10'] < $positions['11']
            && $positions['11'] < $positions['A']
            && $positions['A'] < $positions['F']
        );
    }

    public function test_locations_index_explains_when_all_warehouses_are_visible(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create();
        Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A1',
        ]);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('locations.index'))
            ->assertOk()
            ->assertSee('Mostrando ubicaciones de todos los almacenes');
    }

    public function test_locations_index_hides_locations_from_inactive_warehouses(): void
    {
        $this->seedBaseData();
        $activeWarehouse = Warehouse::factory()->create(['code' => 'ACTIVA', 'active' => true]);
        $inactiveWarehouse = Warehouse::factory()->inactive()->create(['code' => 'INACTIVA']);
        Location::factory()->create(['warehouse_id' => $activeWarehouse->id, 'code' => 'VISIBLE-01']);
        Location::factory()->create(['warehouse_id' => $inactiveWarehouse->id, 'code' => 'OCULTA-01']);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('locations.index', ['status' => 'all']))
            ->assertOk()
            ->assertSee('VISIBLE-01')
            ->assertDontSee('OCULTA-01')
            ->assertSee('Mostrando ubicaciones de todos los almacenes activos');
    }

    public function test_superadmin_sees_controlled_purge_zone_but_other_internal_roles_do_not(): void
    {
        $this->seedBaseData();

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->get(route('locations.index'))
            ->assertOk()
            ->assertSee('Zona peligrosa')
            ->assertSee('Eliminar todas las ubicaciones');

        foreach ([Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $this->actingAs($this->makeUserWithRole($roleSlug))
                ->get(route('locations.index'))
                ->assertOk()
                ->assertDontSee('Zona peligrosa')
                ->assertDontSee('Eliminar todas las ubicaciones');
        }
    }

    public function test_location_range_creation_creates_missing_numeric_locations_without_duplicates(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create(['code' => '38', 'name' => 'NAVE 38']);
        Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '5']);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->post(route('locations.range.store'), [
                'warehouse_id' => $warehouse->id,
                'type' => 'calle',
                'from' => 0,
                'to' => 10,
            ])
            ->assertRedirect(route('locations.index', ['warehouse_id' => $warehouse->id]));

        $this->assertSame(11, Location::query()->where('warehouse_id', $warehouse->id)->count());
        $this->assertSame(1, Location::query()->where('warehouse_id', $warehouse->id)->where('code', '5')->count());
        $this->assertDatabaseHas('locations', [
            'warehouse_id' => $warehouse->id,
            'code' => '0',
            'name' => 'Calle 0',
        ]);
        $this->assertDatabaseHas('locations', [
            'warehouse_id' => $warehouse->id,
            'code' => '10',
            'name' => 'Calle 10',
        ]);
    }

    public function test_location_range_creation_validates_order_and_large_confirmation(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create();
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->from(route('locations.index'))
            ->post(route('locations.range.store'), [
                'warehouse_id' => $warehouse->id,
                'type' => 'calle',
                'from' => 20,
                'to' => 10,
            ])
            ->assertRedirect(route('locations.index'))
            ->assertSessionHasErrors('to');

        $this->actingAs($user)
            ->from(route('locations.index'))
            ->post(route('locations.range.store'), [
                'warehouse_id' => $warehouse->id,
                'type' => 'calle',
                'from' => 0,
                'to' => 1001,
            ])
            ->assertRedirect(route('locations.index'))
            ->assertSessionHasErrors('range_confirmation');
    }

    public function test_locations_accept_free_and_prefixed_operational_codes(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create();
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->post(route('locations.store'), [
                'warehouse_id' => $warehouse->id,
                'type' => 'libre',
                'code' => ' fondo ',
                'active' => '1',
            ])
            ->assertRedirect(route('locations.index'));

        $this->actingAs($user)
            ->post(route('locations.store'), [
                'warehouse_id' => $warehouse->id,
                'type' => 'pasillo',
                'code' => ' A ',
                'active' => '1',
            ])
            ->assertRedirect(route('locations.index'));

        $this->assertDatabaseHas('locations', [
            'warehouse_id' => $warehouse->id,
            'code' => 'FONDO',
            'name' => 'FONDO',
        ]);
        $this->assertDatabaseHas('locations', [
            'warehouse_id' => $warehouse->id,
            'code' => 'PASILLO A',
            'name' => 'Pasillo A',
        ]);
    }

    public function test_only_superadmin_can_purge_locations(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create(['code' => '38']);

        foreach ([Role::ADMINISTRACION, Role::ALMACEN, Role::CLIENTE] as $roleSlug) {
            $this->actingAs($this->makeUserWithRole($roleSlug))
                ->post(route('locations.purge'), [
                    'scope' => 'warehouse',
                    'warehouse_id' => $warehouse->id,
                    'confirmation' => 'ELIMINAR UBICACIONES 38',
                ])
                ->assertForbidden();
        }
    }

    public function test_purge_requires_exact_confirmation(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create(['code' => '38']);

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->from(route('locations.index'))
            ->post(route('locations.purge'), [
                'scope' => 'warehouse',
                'warehouse_id' => $warehouse->id,
                'confirmation' => 'ELIMINAR',
            ])
            ->assertRedirect(route('locations.index'))
            ->assertSessionHasErrors('confirmation');
    }

    public function test_superadmin_purge_removes_location_catalog_without_changing_stock_quantities_or_history(): void
    {
        $this->seedBaseData();
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
            'lot' => 'LOT-KEEP',
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
            'idempotency_key' => 'test-location-purge-'.$location->id,
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

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->post(route('locations.purge'), [
                'scope' => 'warehouse',
                'warehouse_id' => $warehouse->id,
                'confirmation' => 'ELIMINAR UBICACIONES 38',
            ])
            ->assertRedirect(route('locations.index', ['warehouse_id' => $warehouse->id, 'status' => 'all']));

        $this->assertDatabaseMissing('locations', ['id' => $location->id]);
        $this->assertDatabaseHas('stock_pallets', [
            'id' => $stock->id,
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => null,
            'location_text' => null,
            'lot' => 'LOT-KEEP',
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

    public function test_superadmin_can_delete_location_without_references(): void
    {
        $this->seedBaseData();
        $warehouse = Warehouse::factory()->create();
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'DEL-01',
        ]);

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->delete(route('locations.destroy', $location))
            ->assertRedirect(route('locations.index'));

        $this->assertDatabaseMissing('locations', [
            'id' => $location->id,
        ]);
    }

    public function test_location_with_stock_references_cannot_be_deleted(): void
    {
        $this->seedBaseData();
        $client = Client::factory()->create();
        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
        ]);
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'KEEP-01',
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
        ]);
        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->delete(route('locations.destroy', $location))
            ->assertRedirect(route('locations.index'));

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
        ]);
    }

    public function test_non_superadmin_cannot_delete_locations(): void
    {
        $this->seedBaseData();
        $location = Location::factory()->create();

        $this->actingAs($this->makeUserWithRole(Role::ADMINISTRACION))
            ->delete(route('locations.destroy', $location))
            ->assertForbidden();

        $this->assertDatabaseHas('locations', [
            'id' => $location->id,
        ]);
    }

    private function seedBaseData(): void
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }
}
