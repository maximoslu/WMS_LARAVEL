<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
