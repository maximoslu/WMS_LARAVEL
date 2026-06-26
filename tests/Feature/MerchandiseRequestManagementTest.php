<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MerchandiseRequestManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_can_view_request_merchandise_form(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA000X',
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.create'))
            ->assertOk()
            ->assertSee('Solicitar mercancia')
            ->assertSee('CAJA000X');
    }

    public function test_cliente_can_create_request_with_lines(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA000X',
            'description' => 'Caja de prueba',
            'units_per_pallet' => 700,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 4,
                ],
            ])
            ->assertRedirect();

        $request = MerchandiseRequest::query()->firstOrFail();

        $this->assertSame(MerchandiseRequest::STATUS_PENDING, $request->status);
        $this->assertSame(4, $request->requestedPalletsCount());
        $this->assertDatabaseHas('merchandise_request_lines', [
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'lot' => $item->lot,
            'requested_pallets' => 4,
            'requested_units' => 2800,
        ]);
    }

    public function test_cliente_cannot_create_empty_request(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->from(route('merchandise-requests.create'))
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 0,
                ],
            ])
            ->assertRedirect(route('merchandise-requests.create'))
            ->assertSessionHasErrors('quantities');
    }

    public function test_cliente_only_sees_own_requests(): void
    {
        $this->seedBaseData();

        $firstClient = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $secondClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $firstClient);
        $otherUser = $this->makeUserWithRole(Role::CLIENTE, $secondClient);

        $ownRequest = MerchandiseRequest::factory()->create([
            'client_id' => $firstClient->id,
            'requested_by' => $cliente->id,
        ]);
        $foreignRequest = MerchandiseRequest::factory()->create([
            'client_id' => $secondClient->id,
            'requested_by' => $otherUser->id,
        ]);

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.index'))
            ->assertOk()
            ->assertSee($ownRequest->referenceCode())
            ->assertDontSee($foreignRequest->referenceCode());
    }

    public function test_internal_roles_can_view_received_requests(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $requester->id,
        ]);

        foreach ([Role::ALMACEN, Role::ADMINISTRACION, Role::SUPERADMIN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('merchandise-requests.index'))
                ->assertOk()
                ->assertSee($request->referenceCode());
        }
    }

    private function seedBaseData(): void
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);
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
