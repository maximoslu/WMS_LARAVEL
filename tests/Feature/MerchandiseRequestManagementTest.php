<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MerchandiseRequestManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_can_create_request_by_pallets(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA000X',
            'description' => 'Caja para solicitud',
            'units_per_pallet' => 700,
        ]);

        $this->actingAs($clientUser)
            ->post(route('merchandise-requests.store'), [
                'delivery_reference' => 'REQ-FR-001',
                'delivery_address' => 'Muelle Friesland',
                'requested_date' => '2026-06-26',
                'notes' => 'Solicitar para expedicion',
                'lines' => [
                    [
                        'item_id' => $item->id,
                        'lot' => '',
                        'requested_pallets' => 20,
                        'units_per_pallet' => '',
                        'requested_units' => '',
                        'notes' => 'Urgente',
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('merchandise_requests', [
            'client_id' => $client->id,
            'requested_by' => $clientUser->id,
            'status' => MerchandiseRequest::STATUS_CREATED,
            'delivery_reference' => 'REQ-FR-001',
        ]);
    }

    public function test_requested_units_are_calculated_from_requested_pallets(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA000X',
            'description' => 'Caja paletizada',
            'units_per_pallet' => 700,
        ]);

        $this->actingAs($clientUser)
            ->post(route('merchandise-requests.store'), [
                'lines' => [
                    [
                        'item_id' => $item->id,
                        'requested_pallets' => 20,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('merchandise_request_lines', [
            'item_id' => $item->id,
            'requested_pallets' => 20,
            'units_per_pallet' => 700,
            'requested_units' => 14000,
        ]);
    }

    public function test_cliente_cannot_view_requests_from_another_client(): void
    {
        [$clientA, $clientB] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $clientA);
        $otherUser = $this->makeUserWithRole(Role::CLIENTE, $clientB);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $clientB->id,
            'requested_by' => $otherUser->id,
        ]);

        $this->actingAs($clientUser)
            ->get(route('merchandise-requests.show', $request))
            ->assertForbidden();
    }

    public function test_cliente_can_edit_request_before_prepared(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $request = $this->createRequestForClient($client, $clientUser);

        $this->actingAs($clientUser)
            ->put(route('merchandise-requests.update', $request), [
                'delivery_reference' => 'REQ-EDIT-001',
                'delivery_address' => 'Direccion actualizada',
                'requested_date' => '2026-06-26',
                'lines' => [
                    [
                        'item_id' => $request->lines()->firstOrFail()->item_id,
                        'lot' => '',
                        'requested_pallets' => 5,
                        'units_per_pallet' => '',
                        'requested_units' => '',
                    ],
                ],
            ])
            ->assertRedirect(route('merchandise-requests.show', $request));

        $this->assertDatabaseHas('merchandise_requests', [
            'id' => $request->id,
            'delivery_reference' => 'REQ-EDIT-001',
        ]);
    }

    public function test_cliente_cannot_edit_request_when_it_is_prepared(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $request = $this->createRequestForClient($client, $clientUser, MerchandiseRequest::STATUS_PREPARED);

        $this->actingAs($clientUser)
            ->get(route('merchandise-requests.edit', $request))
            ->assertForbidden();
    }

    public function test_creating_request_sends_notifications_to_almacen_administracion_and_superadmin(): void
    {
        $this->configureBrevo();
        Http::fake([
            'https://api.brevo.com/*' => Http::response(['messageId' => 'request-1'], 201),
        ]);

        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client, 'cliente@friesland.test');
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA000MAIL',
            'description' => 'Caja con correo',
            'units_per_pallet' => 700,
        ]);

        $this->makeUserWithRole(Role::ALMACEN, null, 'almacen@maximo.test');
        $this->makeUserWithRole(Role::ADMINISTRACION, null, 'administracion@maximo.test');
        $this->makeUserWithRole(Role::SUPERADMIN, null, 'superadmin@maximo.test');

        $this->actingAs($clientUser)
            ->post(route('merchandise-requests.store'), [
                'delivery_reference' => 'REQ-MAIL-001',
                'lines' => [
                    [
                        'item_id' => $item->id,
                        'requested_pallets' => 2,
                    ],
                ],
            ])
            ->assertRedirect();

        Http::assertSent(function ($request): bool {
            $recipients = collect($request['to'] ?? [])->pluck('email')->all();

            sort($recipients);

            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request['subject'] === 'Nueva solicitud de mercancia - FRIESLAND'
                && $recipients === [
                    'administracion@maximo.test',
                    'almacen@maximo.test',
                    'superadmin@maximo.test',
                ];
        });
    }

    public function test_preparing_request_discounts_stock(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $warehouseUser = $this->makeUserWithRole(Role::ALMACEN);
        $request = $this->createRequestForClient($client, $clientUser, MerchandiseRequest::STATUS_CREATED, 20, 700);

        foreach (range(1, 20) as $index) {
            StockPallet::query()->create([
                'client_id' => $client->id,
                'item_id' => $request->lines()->firstOrFail()->item_id,
                'location_text' => 'A1-'.$index,
                'pallet_code' => 'PAL-REQ-'.$index,
                'quantity_units' => 700,
                'active' => true,
            ]);
        }

        $this->actingAs($warehouseUser)
            ->patch(route('merchandise-requests.prepare', $request))
            ->assertRedirect(route('merchandise-requests.show', $request));

        $this->assertSame(MerchandiseRequest::STATUS_PREPARED, $request->fresh()->status);
        $this->assertSame(0, StockPallet::query()->where('item_id', $request->lines()->firstOrFail()->item_id)->where('active', true)->count());
        $this->assertDatabaseHas('merchandise_request_lines', [
            'id' => $request->lines()->firstOrFail()->id,
            'prepared_pallets' => 20,
            'prepared_units' => 14000,
        ]);
    }

    public function test_preparing_request_without_sufficient_stock_fails(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $warehouseUser = $this->makeUserWithRole(Role::ALMACEN);
        $request = $this->createRequestForClient($client, $clientUser, MerchandiseRequest::STATUS_CREATED, 2, 700);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $request->lines()->firstOrFail()->item_id,
            'location_text' => 'A1-01',
            'pallet_code' => 'PAL-REQ-01',
            'quantity_units' => 700,
            'active' => true,
        ]);

        $this->actingAs($warehouseUser)
            ->from(route('merchandise-requests.show', $request))
            ->patch(route('merchandise-requests.prepare', $request))
            ->assertRedirect(route('merchandise-requests.show', $request))
            ->assertSessionHasErrors('merchandise_request');

        $this->assertSame(MerchandiseRequest::STATUS_CREATED, $request->fresh()->status);
        $this->assertSame(1, StockPallet::query()->where('item_id', $request->lines()->firstOrFail()->item_id)->where('active', true)->count());
    }

    public function test_preparing_twice_does_not_duplicate_discount(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $warehouseUser = $this->makeUserWithRole(Role::ALMACEN);
        $request = $this->createRequestForClient($client, $clientUser, MerchandiseRequest::STATUS_CREATED, 1, 700);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $request->lines()->firstOrFail()->item_id,
            'location_text' => 'A1-01',
            'pallet_code' => 'PAL-REQ-01',
            'quantity_units' => 700,
            'active' => true,
        ]);

        $this->actingAs($warehouseUser)
            ->patch(route('merchandise-requests.prepare', $request))
            ->assertRedirect(route('merchandise-requests.show', $request));

        $this->actingAs($warehouseUser)
            ->from(route('merchandise-requests.show', $request))
            ->patch(route('merchandise-requests.prepare', $request))
            ->assertRedirect(route('merchandise-requests.show', $request))
            ->assertSessionHasErrors('merchandise_request');

        $this->assertSame(0, StockPallet::query()->where('item_id', $request->lines()->firstOrFail()->item_id)->where('active', true)->count());
        $this->assertDatabaseCount('merchandise_request_events', 2);
    }

    public function test_shipping_request_changes_status_to_shipped(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $warehouseUser = $this->makeUserWithRole(Role::ALMACEN);
        $request = $this->createPreparedRequestWithStock($client, $clientUser, $warehouseUser);

        $this->actingAs($warehouseUser)
            ->patch(route('merchandise-requests.ship', $request))
            ->assertRedirect(route('merchandise-requests.show', $request));

        $this->assertSame(MerchandiseRequest::STATUS_SHIPPED, $request->fresh()->status);
        $this->assertNotNull($request->fresh()->shipped_at);
    }

    public function test_timeline_registers_created_prepared_and_shipped_events(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $warehouseUser = $this->makeUserWithRole(Role::ALMACEN);
        $request = $this->createPreparedRequestWithStock($client, $clientUser, $warehouseUser);

        $this->actingAs($warehouseUser)
            ->patch(route('merchandise-requests.ship', $request))
            ->assertRedirect(route('merchandise-requests.show', $request));

        $eventTypes = $request->fresh()->events()->pluck('event_type')->all();

        $this->assertSame(['created', 'prepared', 'shipped'], $eventTypes);
    }

    public function test_unauthorized_roles_receive_403(): void
    {
        [$client] = $this->seedBaseData();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacenUser = $this->makeUserWithRole(Role::ALMACEN);
        $request = $this->createRequestForClient($client, $clientUser);

        $this->actingAs($clientUser)
            ->patch(route('merchandise-requests.prepare', $request))
            ->assertForbidden();

        $this->actingAs($almacenUser)
            ->get(route('merchandise-requests.create'))
            ->assertForbidden();
    }

    /**
     * @return array{0: Client, 1: Client}
     */
    private function seedBaseData(): array
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

    private function makeUserWithRole(string $roleSlug, ?Client $client = null, ?string $email = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        $attributes = [
            'role_id' => $role->id,
            'client_id' => $client?->id,
        ];

        if ($email !== null) {
            $attributes['email'] = $email;
        }

        return User::factory()->create($attributes);
    }

    private function createRequestForClient(
        Client $client,
        User $clientUser,
        string $status = MerchandiseRequest::STATUS_CREATED,
        int $requestedPallets = 2,
        int $unitsPerPallet = 700,
    ): MerchandiseRequest {
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'REQ-ITEM-'.$requestedPallets.'-'.$unitsPerPallet,
            'description' => 'Articulo de solicitud',
            'units_per_pallet' => $unitsPerPallet,
        ]);

        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $clientUser->id,
            'status' => $status,
            'requested_date' => '2026-06-26',
        ]);

        $request->lines()->create([
            'item_id' => $item->id,
            'lot' => $item->lot,
            'requested_pallets' => $requestedPallets,
            'units_per_pallet' => $unitsPerPallet,
            'requested_units' => $requestedPallets * $unitsPerPallet,
            'notes' => 'Linea demo',
        ]);

        $request->events()->create([
            'user_id' => $clientUser->id,
            'event_type' => 'created',
            'title' => 'Pedido creado',
            'description' => 'Solicitud creada desde test.',
        ]);

        return $request->fresh();
    }

    private function createPreparedRequestWithStock(Client $client, User $clientUser, User $warehouseUser): MerchandiseRequest
    {
        $request = $this->createRequestForClient($client, $clientUser, MerchandiseRequest::STATUS_CREATED, 2, 700);

        foreach (range(1, 2) as $index) {
            StockPallet::query()->create([
                'client_id' => $client->id,
                'item_id' => $request->lines()->firstOrFail()->item_id,
                'location_text' => 'B1-0'.$index,
                'pallet_code' => 'PAL-PREP-0'.$index,
                'quantity_units' => 700,
                'active' => true,
            ]);
        }

        $this->actingAs($warehouseUser)
            ->patch(route('merchandise-requests.prepare', $request))
            ->assertRedirect(route('merchandise-requests.show', $request));

        return $request->fresh();
    }

    private function configureBrevo(): void
    {
        config([
            'services.brevo.key' => 'test-brevo-key',
            'services.brevo.base_url' => 'https://api.brevo.com/v3',
            'mail.from.address' => 'sistema@maximosl.com',
            'mail.from.name' => 'MAXIMO WMS',
        ]);
    }
}
