<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_database_seeder_creates_expected_clients(): void
    {
        $this->seed(ClientSeeder::class);

        $this->assertDatabaseHas('clients', [
            'code' => 'FRIESLAND',
            'name' => 'FRIESLAND',
        ]);

        $this->assertDatabaseHas('clients', [
            'code' => 'EDELVIVES',
            'name' => 'EDELVIVES',
        ]);
    }

    public function test_administracion_and_superadmin_can_view_item_listing(): void
    {
        $this->seedBaseData();

        $client = Client::query()->firstOrFail();
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-STOCK-01',
            'description' => 'Articulo operativo',
        ]);

        foreach ([Role::ADMINISTRACION, Role::SUPERADMIN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('items.index'))
                ->assertOk()
                ->assertSee('Articulos')
                ->assertSee('SKU-STOCK-01');
        }
    }

    public function test_administracion_can_create_item(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();

        $this->actingAs($user)
            ->post(route('items.store'), [
                'client_id' => $client->id,
                'sku' => ' fr-100 ',
                'description' => ' Palet leche entera ',
                'units_per_pallet' => 700,
                'status' => Item::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'client_id' => $client->id,
            'sku' => 'FR-100',
            'description' => 'Palet leche entera',
            'units_per_pallet' => 700,
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);
    }

    public function test_almacen_can_create_item(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();

        $this->actingAs($user)
            ->post(route('items.store'), [
                'client_id' => $client->id,
                'sku' => ' alm-100 ',
                'description' => ' Alta rapida desde almacen ',
                'units_per_pallet' => 320,
                'status' => Item::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'client_id' => $client->id,
            'sku' => 'ALM-100',
            'description' => 'Alta rapida desde almacen',
            'units_per_pallet' => 320,
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);
    }

    public function test_almacen_sees_new_item_button_and_edit_action(): void
    {
        $this->seedBaseData();

        $client = Client::query()->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-ALM-01',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('items.index'))
            ->assertOk()
            ->assertSee('Nuevo articulo')
            ->assertSee(route('items.create'), false)
            ->assertSee(route('items.edit', $item), false)
            ->assertDontSee(route('items.toggle-active', $item), false);

        $this->actingAs($user)
            ->get(route('items.create'))
            ->assertOk()
            ->assertSee('Nuevo articulo');
    }

    public function test_items_index_defaults_to_list_view(): void
    {
        $this->seedBaseData();

        $client = Client::query()->firstOrFail();
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-LIST-01',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('items.index'))
            ->assertOk()
            ->assertSee('Vista lista de articulos')
            ->assertSee('SKU-LIST-01');
    }

    public function test_items_index_supports_cards_view(): void
    {
        $this->seedBaseData();

        $client = Client::query()->firstOrFail();
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-CARD-01',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('items.index', ['view' => 'cards']))
            ->assertOk()
            ->assertSee('Vista tarjetas de articulos')
            ->assertSee('SKU-CARD-01');
    }

    public function test_item_filters_keep_working_in_list_view(): void
    {
        $this->seedBaseData();

        $client = Client::query()->firstOrFail();
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-FILTER-01',
            'description' => 'Encontrable',
        ]);

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-FILTER-02',
            'description' => 'No mostrar',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('items.index', ['search' => 'Encontrable']))
            ->assertOk()
            ->assertSee('SKU-FILTER-01')
            ->assertDontSee('SKU-FILTER-02');
    }

    public function test_cliente_cannot_create_items(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('items.create'))
            ->assertForbidden();
    }

    public function test_validation_fails_when_units_per_pallet_is_less_than_one(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::query()->firstOrFail();

        $this->actingAs($user)
            ->from(route('items.create'))
            ->post(route('items.store'), [
                'client_id' => $client->id,
                'sku' => 'SKU-0001',
                'description' => 'Articulo invalido',
                'units_per_pallet' => 0,
                'status' => Item::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('items.create'))
            ->assertSessionHasErrors('units_per_pallet');
    }

    public function test_duplicate_sku_is_not_allowed_within_same_client(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-DUP',
        ]);

        $this->actingAs($user)
            ->from(route('items.create'))
            ->post(route('items.store'), [
                'client_id' => $client->id,
                'sku' => 'sku-dup',
                'description' => 'Duplicado',
                'units_per_pallet' => 100,
                'status' => Item::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('items.create'))
            ->assertSessionHasErrors('sku');
    }

    public function test_same_sku_is_allowed_for_different_clients(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $firstClient = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $secondClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();

        Item::factory()->create([
            'client_id' => $firstClient->id,
            'sku' => 'SKU-COMUN',
        ]);

        $this->actingAs($user)
            ->post(route('items.store'), [
                'client_id' => $secondClient->id,
                'sku' => 'sku-comun',
                'description' => 'SKU compartido entre clientes',
                'units_per_pallet' => 480,
                'status' => Item::STATUS_ACTIVE,
            ])
            ->assertRedirect(route('items.index'));

        $this->assertDatabaseCount('items', 2);
    }

    public function test_item_belongs_to_a_client(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
        ]);

        $this->assertTrue($item->client->is($client));
    }

    public function test_item_form_does_not_show_lot_field(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $item = Item::factory()->create();

        $this->actingAs($user)
            ->get(route('items.create'))
            ->assertOk()
            ->assertDontSee('name="lot"', false);

        $this->actingAs($user)
            ->get(route('items.edit', $item))
            ->assertOk()
            ->assertDontSee('name="lot"', false);
    }

    public function test_item_can_be_updated_to_blocked_and_obsolete(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $item = Item::factory()->create([
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);

        $this->actingAs($user)
            ->put(route('items.update', $item), [
                'client_id' => $item->client_id,
                'sku' => $item->sku,
                'description' => $item->description,
                'units_per_pallet' => $item->units_per_pallet,
                'status' => Item::STATUS_BLOCKED,
                'default_location_id' => '',
            ])
            ->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'status' => Item::STATUS_BLOCKED,
            'active' => false,
        ]);

        $this->actingAs($user)
            ->put(route('items.update', $item), [
                'client_id' => $item->client_id,
                'sku' => $item->sku,
                'description' => $item->description,
                'units_per_pallet' => $item->units_per_pallet,
                'status' => Item::STATUS_OBSOLETE,
                'default_location_id' => '',
            ])
            ->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'id' => $item->id,
            'status' => Item::STATUS_OBSOLETE,
            'active' => false,
        ]);
    }

    public function test_item_can_store_optional_default_location(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $warehouse = Warehouse::factory()->create();
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A1-01',
        ]);

        $this->actingAs($user)
            ->post(route('items.store'), [
                'client_id' => $client->id,
                'sku' => 'SKU-LOC-ITEM',
                'description' => 'Con ubicación por defecto',
                'units_per_pallet' => 500,
                'status' => Item::STATUS_ACTIVE,
                'default_location_id' => $location->id,
            ])
            ->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'client_id' => $client->id,
            'sku' => 'SKU-LOC-ITEM',
            'default_location_id' => $location->id,
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
