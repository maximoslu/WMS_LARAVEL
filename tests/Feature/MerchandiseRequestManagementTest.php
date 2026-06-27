<?php

namespace Tests\Feature;

use App\Jobs\ProcessMerchandiseRequestSubmittedNotificationsJob;
use App\Models\Client;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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
            ->assertSee('Solicitar mercancía')
            ->assertSee('Buscar por SKU, referencia, lote o descripción...')
            ->assertDontSee('CAJA000X');
    }

    public function test_ajax_search_limits_results_and_hides_other_clients_items(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        foreach (range(1, 18) as $index) {
            Item::factory()->create([
                'client_id' => $client->id,
                'sku' => sprintf('CAJA%04d', $index),
                'description' => 'Catalogo cliente '.$index,
                'lot_key' => sprintf('L%04d', $index),
                'active' => true,
            ]);
        }
        Item::factory()->create([
            'client_id' => $otherClient->id,
            'sku' => 'CAJA9999',
            'description' => 'Otro cliente',
            'active' => true,
        ]);

        $response = $this->actingAs($cliente)
            ->getJson(route('merchandise-requests.items.search', [
                'search' => 'CAJA',
            ]))
            ->assertOk();

        $data = $response->json('data');

        $this->assertCount(15, $data);
        $this->assertTrue(collect($data)->every(fn (array $item): bool => str_starts_with($item['sku'], 'CAJA')));
        $this->assertFalse(collect($data)->contains(fn (array $item): bool => $item['sku'] === 'CAJA9999'));
    }

    public function test_cliente_can_create_request_with_lines(): void
    {
        Bus::fake();
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
        Bus::assertDispatchedAfterResponse(
            ProcessMerchandiseRequestSubmittedNotificationsJob::class,
            fn (ProcessMerchandiseRequestSubmittedNotificationsJob $job): bool => $job->merchandiseRequestId === $request->id
        );
    }

    public function test_cliente_can_create_a_second_request_after_submitting_the_first_one(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $firstItem = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0001',
            'description' => 'Primera referencia',
            'units_per_pallet' => 700,
        ]);
        $secondItem = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0002',
            'description' => 'Segunda referencia',
            'units_per_pallet' => 560,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $firstItem->id => 2,
                ],
            ])
            ->assertRedirect();

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.create'))
            ->assertOk()
            ->assertSee('Buscar mercancía')
            ->assertDontSee('data-request-hidden-quantity', false);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $secondItem->id => 3,
                ],
            ])
            ->assertRedirect();

        $this->assertSame(2, MerchandiseRequest::query()->count());
        $this->assertDatabaseHas('merchandise_request_lines', [
            'item_id' => $firstItem->id,
            'requested_pallets' => 2,
        ]);
        $this->assertDatabaseHas('merchandise_request_lines', [
            'item_id' => $secondItem->id,
            'requested_pallets' => 3,
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

    public function test_cliente_cannot_create_request_with_negative_or_decimal_pallets(): void
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
                    $item->id => -1,
                ],
            ])
            ->assertRedirect(route('merchandise-requests.create'))
            ->assertSessionHasErrors('quantities');

        $this->actingAs($cliente)
            ->from(route('merchandise-requests.create'))
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => '1.5',
                ],
            ])
            ->assertRedirect(route('merchandise-requests.create'))
            ->assertSessionHasErrors('quantities.'.$item->id);
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

    public function test_cliente_sees_request_status_in_spanish_and_cannot_change_it(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.show', $merchandiseRequest))
            ->assertOk()
            ->assertSee('En preparacion')
            ->assertDontSee('Preparing');

        $this->actingAs($cliente)
            ->patch(route('merchandise-requests.update-status', $merchandiseRequest), [
                'status' => MerchandiseRequest::STATUS_COMPLETED,
            ])
            ->assertForbidden();
    }

    public function test_cliente_can_filter_requests_by_item_sku_without_optional_columns(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $otherRequester = $this->makeUserWithRole(Role::CLIENTE, $otherClient);

        $matchingItem = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0001',
            'description' => 'Mercancia cliente',
        ]);
        $otherItem = Item::factory()->create([
            'client_id' => $otherClient->id,
            'sku' => 'OTRA0001',
        ]);

        $matchingRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        $otherRequest = MerchandiseRequest::factory()->create([
            'client_id' => $otherClient->id,
            'requested_by' => $otherRequester->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);

        $matchingRequest->lines()->create([
            'item_id' => $matchingItem->id,
            'lot' => $matchingItem->lot,
            'units_per_pallet' => $matchingItem->units_per_pallet,
            'requested_pallets' => 2,
            'requested_units' => $matchingItem->units_per_pallet * 2,
        ]);
        $otherRequest->lines()->create([
            'item_id' => $otherItem->id,
            'lot' => $otherItem->lot,
            'units_per_pallet' => $otherItem->units_per_pallet,
            'requested_pallets' => 1,
            'requested_units' => $otherItem->units_per_pallet,
        ]);

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.index', [
                'status' => MerchandiseRequest::STATUS_PENDING,
                'search' => 'CAJA0001',
            ]))
            ->assertOk()
            ->assertSee($matchingRequest->referenceCode())
            ->assertDontSee($otherRequest->referenceCode());
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
