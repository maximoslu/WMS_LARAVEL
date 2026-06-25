<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Role;
use App\Models\User;
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
                'lot' => ' lote-a1 ',
                'units_per_pallet' => 700,
                'active' => '1',
            ])
            ->assertRedirect(route('items.index'));

        $this->assertDatabaseHas('items', [
            'client_id' => $client->id,
            'sku' => 'FR-100',
            'description' => 'Palet leche entera',
            'lot' => 'LOTE-A1',
            'lot_key' => 'LOTE-A1',
            'units_per_pallet' => 700,
            'active' => true,
        ]);
    }

    public function test_almacen_can_view_items_but_cannot_create(): void
    {
        $this->seedBaseData();

        $client = Client::query()->firstOrFail();
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-ALM-01',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('items.index'))
            ->assertOk()
            ->assertSee('SKU-ALM-01');

        $this->actingAs($user)
            ->get(route('items.create'))
            ->assertForbidden();
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
                'lot' => '',
                'units_per_pallet' => 0,
                'active' => '1',
            ])
            ->assertRedirect(route('items.create'))
            ->assertSessionHasErrors('units_per_pallet');
    }

    public function test_duplicate_sku_and_lot_is_not_allowed_within_same_client(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-DUP',
            'lot' => null,
            'lot_key' => '',
        ]);

        $this->actingAs($user)
            ->from(route('items.create'))
            ->post(route('items.store'), [
                'client_id' => $client->id,
                'sku' => 'sku-dup',
                'description' => 'Duplicado',
                'lot' => '',
                'units_per_pallet' => 100,
                'active' => '1',
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
            'lot' => null,
            'lot_key' => '',
        ]);

        $this->actingAs($user)
            ->post(route('items.store'), [
                'client_id' => $secondClient->id,
                'sku' => 'sku-comun',
                'description' => 'SKU compartido entre clientes',
                'lot' => '',
                'units_per_pallet' => 480,
                'active' => '1',
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
