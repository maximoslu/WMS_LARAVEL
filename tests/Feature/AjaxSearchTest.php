<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AjaxSearchTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_endpoint_requires_auth(): void
    {
        $this->getJson(route('ajax.items', ['q' => 'PR']))
            ->assertUnauthorized();
    }

    public function test_cliente_only_sees_own_active_items_in_ajax_search(): void
    {
        [$client, $otherClient] = $this->seedClients();

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'PRUEBA-OK',
            'description' => 'Visible',
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);

        Item::factory()->create([
            'client_id' => $otherClient->id,
            'sku' => 'PRUEBA-OTRO',
            'description' => 'Oculto',
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'PRUEBA-BLOQ',
            'description' => 'Bloqueado',
            'status' => Item::STATUS_BLOCKED,
            'active' => false,
        ]);

        $user = $this->makeUserWithRole(Role::CLIENTE, $client);

        $response = $this->actingAs($user)
            ->getJson(route('ajax.items', ['q' => 'PRUEBA']));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('PRUEBA-OK', $data[0]['sku']);
    }

    public function test_stock_variant_search_preserves_utf8_in_json(): void
    {
        [$client] = $this->seedClients();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'GEN-UTF8',
            'description' => 'Artículo genérico',
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);
        $user = $this->makeUserWithRole(Role::CLIENTE, $client);

        $response = $this->actingAs($user)
            ->getJson(route('ajax.stock-variants', ['q' => 'GEN-UTF8']));

        $response
            ->assertOk()
            ->assertJsonPath('data.0.item_id', $item->id)
            ->assertJsonPath('data.0.description', 'Artículo genérico')
            ->assertJsonPath('data.0.summary', 'Pallet genérico');

        $this->assertStringContainsString('Pallet genérico', $response->getContent());
        $this->assertStringNotContainsString('genÃ©rico', $response->getContent());
    }

    public function test_items_endpoint_finds_matches_by_description_only(): void
    {
        [$client] = $this->seedClients();

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'REF-0001',
            'description' => 'Bobina papel couche brillo',
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'REF-0002',
            'description' => 'Etiqueta adhesiva blanca',
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $response = $this->actingAs($user)
            ->getJson(route('ajax.items', ['q' => 'couche', 'client_id' => $client->id]));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('REF-0001', $data[0]['sku']);
    }

    public function test_items_endpoint_scopes_internal_role_search_to_requested_client(): void
    {
        [$client, $otherClient] = $this->seedClients();

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SCOPE-A',
            'description' => 'Articulo del cliente A',
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);

        Item::factory()->create([
            'client_id' => $otherClient->id,
            'sku' => 'SCOPE-B',
            'description' => 'Articulo del cliente B',
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $response = $this->actingAs($user)
            ->getJson(route('ajax.items', ['q' => 'SCOPE', 'client_id' => $client->id]));

        $response->assertOk();
        $data = $response->json('data');

        $this->assertCount(1, $data);
        $this->assertSame('SCOPE-A', $data[0]['sku']);
    }

    public function test_items_endpoint_limits_results(): void
    {
        [$client] = $this->seedClients();

        collect(range(1, 15))->each(function (int $index) use ($client): void {
            Item::factory()->create([
                'client_id' => $client->id,
                'sku' => 'BULK-'.$index,
                'description' => 'Producto bulk '.$index,
                'status' => Item::STATUS_ACTIVE,
                'active' => true,
            ]);
        });

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $response = $this->actingAs($user)
            ->getJson(route('ajax.items', ['q' => 'BU', 'client_id' => $client->id, 'limit' => 10]));

        $response->assertOk();
        $this->assertCount(10, $response->json('data'));
    }

    public function test_locations_endpoint_can_search_by_code_for_internal_roles(): void
    {
        $this->seed(RoleSeeder::class);

        $warehouse = Warehouse::factory()->create(['code' => 'WH-AJAX']);
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A1-TEST',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->getJson(route('ajax.locations', ['q' => 'A1']))
            ->assertOk()
            ->assertJsonFragment([
                'id' => $location->id,
                'label' => 'A1-TEST',
            ]);
    }

    public function test_suppliers_endpoint_finds_matches_scoped_to_client_and_global(): void
    {
        [$client, $otherClient] = $this->seedClients();

        Supplier::factory()->create([
            'client_id' => $client->id,
            'name' => 'PROVEEDOR PROPIO',
            'active' => true,
        ]);

        Supplier::factory()->create([
            'client_id' => null,
            'name' => 'PROVEEDOR GLOBAL',
            'active' => true,
        ]);

        Supplier::factory()->create([
            'client_id' => $otherClient->id,
            'name' => 'PROVEEDOR AJENO',
            'active' => true,
        ]);

        Supplier::factory()->create([
            'client_id' => $client->id,
            'name' => 'PROVEEDOR INACTIVO',
            'active' => false,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $response = $this->actingAs($user)
            ->getJson(route('ajax.suppliers', ['q' => 'PROVEEDOR', 'client_id' => $client->id]));

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->sort()->values()->all();

        $this->assertSame(['PROVEEDOR GLOBAL', 'PROVEEDOR PROPIO'], $names);
    }

    public function test_suppliers_endpoint_requires_authorized_role(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->getJson(route('ajax.suppliers', ['q' => 'PROVEEDOR']))
            ->assertForbidden();
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
            'client_id' => $client?->id,
        ]);
    }
}
