<?php

namespace Tests\Feature;

use App\Jobs\ProcessMerchandiseRequestSubmittedNotificationsJob;
use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestLine;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
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
            ->assertSee('NUEVO PEDIDO')
            ->assertSee('Referencia / SKU')
            ->assertSee('Pallets')
            ->assertSee('Picos')
            ->assertSee('Añadir línea')
            ->assertSee('ENVIAR PEDIDO')
            ->assertSee('Sin líneas.')
            ->assertDontSee('Camión propio')
            ->assertDontSee('Selecciona una referencia para ver el detalle')
            ->assertDontSee('Solicitar mercancía con selección clara')
            ->assertDontSee('Pensado para usuarios')
            ->assertDontSee('CAJA000X');
    }

    public function test_cliente_sees_only_own_open_requests_below_new_order(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $otherUser = $this->makeUserWithRole(Role::CLIENTE, $otherClient);
        $item = Item::factory()->create(['client_id' => $client->id]);

        $pending = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        $preparing = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $sent = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_SENT,
        ]);
        $completed = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_COMPLETED,
        ]);
        $cancelled = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_CANCELLED,
        ]);
        $foreign = MerchandiseRequest::factory()->create([
            'client_id' => $otherClient->id,
            'requested_by' => $otherUser->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);

        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $pending->id,
            'item_id' => $item->id,
            'requested_pallets' => 2,
        ]);

        $response = $this->actingAs($cliente)
            ->get(route('merchandise-requests.create'))
            ->assertOk()
            ->assertSeeInOrder(['NUEVO PEDIDO', 'ENVIAR PEDIDO', 'PEDIDOS PENDIENTES'])
            ->assertSee($pending->referenceCode())
            ->assertSee($preparing->referenceCode())
            ->assertDontSee($sent->referenceCode())
            ->assertDontSee($completed->referenceCode())
            ->assertDontSee($cancelled->referenceCode())
            ->assertDontSee($foreign->referenceCode())
            ->assertSee('1 línea /')
            ->assertSee('2 pallets');

        $response->assertSee(route('merchandise-requests.show', $pending), false);
    }

    public function test_cliente_sees_empty_pending_orders_state(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        Item::factory()->create(['client_id' => $client->id]);

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.create'))
            ->assertOk()
            ->assertSee('PEDIDOS PENDIENTES')
            ->assertSee('Sin pedidos pendientes.');
    }

    public function test_cliente_cannot_open_another_clients_request_from_pending_orders(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $otherUser = $this->makeUserWithRole(Role::CLIENTE, $otherClient);
        $foreign = MerchandiseRequest::factory()->create([
            'client_id' => $otherClient->id,
            'requested_by' => $otherUser->id,
        ]);

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.show', $foreign))
            ->assertForbidden();
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

    public function test_internal_ajax_search_uses_selected_client(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SEARCH-FRIES-001',
            'active' => true,
        ]);
        Item::factory()->create([
            'client_id' => $otherClient->id,
            'sku' => 'SEARCH-EDEL-001',
            'active' => true,
        ]);

        $response = $this->actingAs($almacen)
            ->getJson(route('merchandise-requests.items.search', [
                'client_id' => $otherClient->id,
                'search' => 'SEARCH',
            ]))
            ->assertOk();

        $skus = collect($response->json('data'))->pluck('sku')->all();

        $this->assertContains('SEARCH-EDEL-001', $skus);
        $this->assertNotContains('SEARCH-FRIES-001', $skus);
    }

    public function test_cliente_ajax_search_ignores_foreign_client_id(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SEARCH-FRIES-002',
            'active' => true,
        ]);
        Item::factory()->create([
            'client_id' => $otherClient->id,
            'sku' => 'SEARCH-EDEL-002',
            'active' => true,
        ]);

        $response = $this->actingAs($cliente)
            ->getJson(route('merchandise-requests.items.search', [
                'client_id' => $otherClient->id,
                'search' => 'SEARCH',
            ]))
            ->assertOk();

        $skus = collect($response->json('data'))->pluck('sku')->all();

        $this->assertContains('SEARCH-FRIES-002', $skus);
        $this->assertNotContains('SEARCH-EDEL-002', $skus);
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
            'lot' => null,
            'requested_pallets' => 4,
            'requested_units' => 2800,
        ]);
        Bus::assertDispatchedAfterResponse(
            ProcessMerchandiseRequestSubmittedNotificationsJob::class,
            fn (ProcessMerchandiseRequestSubmittedNotificationsJob $job): bool => $job->merchandiseRequestId === $request->id
        );
    }

    public function test_cliente_crea_pedido_solo_para_si_mismo_aunque_envie_client_id(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'OWN-ORDER-001',
            'units_per_pallet' => 100,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'client_id' => $otherClient->id,
                'lines' => [
                    'line_1' => [
                        'item_id' => $item->id,
                        'line_type' => 'pallet',
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertRedirect();

        $request = MerchandiseRequest::query()->firstOrFail();

        $this->assertSame($client->id, $request->client_id);
        $this->assertSame($cliente->id, $request->requested_by);
        $this->assertDatabaseHas('merchandise_request_lines', [
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'requested_pallets' => 2,
        ]);
    }

    public function test_cliente_no_puede_crear_pedido_para_otro_cliente_manipulando_referencia(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $otherItem = Item::factory()->create([
            'client_id' => $otherClient->id,
            'sku' => 'FOREIGN-ORDER-001',
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->from(route('merchandise-requests.create'))
            ->post(route('merchandise-requests.store'), [
                'client_id' => $otherClient->id,
                'lines' => [
                    'line_1' => [
                        'item_id' => $otherItem->id,
                        'line_type' => 'pallet',
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertRedirect(route('merchandise-requests.create'))
            ->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('merchandise_requests', 0);
    }

    public function test_almacen_puede_crear_pedido_para_cualquier_cliente(): void
    {
        $this->assertInternalRoleCanCreateRequestForClient(Role::ALMACEN);
    }

    public function test_administracion_puede_crear_pedido_para_cualquier_cliente(): void
    {
        $this->assertInternalRoleCanCreateRequestForClient(Role::ADMINISTRACION);
    }

    public function test_superadmin_puede_crear_pedido_para_cualquier_cliente(): void
    {
        $this->assertInternalRoleCanCreateRequestForClient(Role::SUPERADMIN);
    }

    public function test_usuario_no_autorizado_no_puede_crear_pedido_en_nombre_de_cliente(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create(['client_id' => $client->id]);
        $clienteSinClienteAsignado = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($clienteSinClienteAsignado)
            ->get(route('merchandise-requests.create', ['client_id' => $client->id]))
            ->assertForbidden();

        $this->actingAs($clienteSinClienteAsignado)
            ->post(route('merchandise-requests.store'), [
                'client_id' => $client->id,
                'lines' => [
                    'line_1' => [
                        'item_id' => $item->id,
                        'line_type' => 'pallet',
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertForbidden();

        $this->assertDatabaseCount('merchandise_requests', 0);
    }

    public function test_pedido_cliente_dentro_de_horario_no_muestra_aviso_fuera_ventana(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 700,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->travelTo(Carbon::parse('2026-01-05 08:00:00', config('app.timezone')));

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 2,
                ],
            ])
            ->assertRedirect()
            ->assertSessionMissing('warning');

        $this->travelBack();
    }

    public function test_pedido_cliente_antes_de_las_7_muestra_aviso_fuera_ventana(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 700,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->travelTo(Carbon::parse('2026-01-05 05:59:59', config('app.timezone')));

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 2,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->travelBack();
    }

    public function test_pedido_cliente_despues_de_las_15_muestra_aviso_fuera_ventana(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 700,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->travelTo(Carbon::parse('2026-01-05 14:01:00', config('app.timezone')));

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 2,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->travelBack();
    }

    public function test_pedido_cliente_fin_de_semana_muestra_aviso_fuera_ventana(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 700,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->travelTo(Carbon::parse('2026-01-03 10:00:00', config('app.timezone')));

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.create'))
            ->assertOk()
            ->assertSee('Estas realizando el pedido fuera de la ventana operativa contractual de planificacion.');

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 2,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->travelBack();
    }

    public function test_show_muestra_aviso_estructurado_fuera_de_horario_y_lineas_en_tabla(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0030',
            'units_per_pallet' => 700,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->travelTo(Carbon::parse('2026-01-03 10:00:00', config('app.timezone')));

        $this->actingAs($cliente)
            ->followingRedirects()
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 2,
                ],
            ])
            ->assertOk()
            ->assertSee('Solicitud registrada y notificada correctamente.')
            ->assertSee('Pedido fuera de horario operativo')
            ->assertSee('order-table', false)
            ->assertSee('CAJA0030');

        $this->travelBack();
    }

    public function test_pedido_fuera_de_horario_se_registra_igualmente(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 700,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->travelTo(Carbon::parse('2026-01-03 10:00:00', config('app.timezone')));

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 3,
                ],
            ])
            ->assertRedirect()
            ->assertSessionHas('warning');

        $request = MerchandiseRequest::query()->firstOrFail();

        $this->assertSame(MerchandiseRequest::STATUS_PENDING, $request->status);
        $this->assertSame(3, $request->requestedPalletsCount());

        $this->travelBack();
    }

    public function test_cliente_can_create_request_with_peak_line_from_stock_variant(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'PICO0001',
            'description' => 'Referencia con pico',
            'units_per_pallet' => 700,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-PICO',
            'units_per_pallet' => 700,
            'full_pallets' => 2,
            'peaks_count' => 1,
            'peak_1' => 120,
            'quantity_units' => 1520,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'lines' => [
                    'peak_variant' => [
                        'item_id' => $item->id,
                        'line_type' => 'peak',
                        'stock_pallet_id' => $stock->id,
                        'stock_peak_index' => 1,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertRedirect();

        $request = MerchandiseRequest::query()->firstOrFail();

        $this->assertDatabaseHas('merchandise_request_lines', [
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'line_type' => 'peak',
            'stock_peak_index' => 1,
            'lot' => 'LOT-PICO',
            'requested_pallets' => 0,
            'requested_peaks' => 1,
            'requested_units' => 120,
        ]);
    }

    public function test_cliente_can_create_request_with_multiple_line_payload(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $firstItem = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'LINEA001',
            'units_per_pallet' => 100,
        ]);
        $secondItem = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'LINEA002',
            'units_per_pallet' => 80,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'lines' => [
                    'line_1' => [
                        'item_id' => $firstItem->id,
                        'line_type' => 'pallet',
                        'quantity' => 2,
                    ],
                    'line_2' => [
                        'item_id' => $secondItem->id,
                        'line_type' => 'pallet',
                        'quantity' => 3,
                    ],
                ],
            ])
            ->assertRedirect();

        $request = MerchandiseRequest::query()->firstOrFail();

        $this->assertSame(2, $request->lines()->count());
        $this->assertSame(5, $request->requestedPalletsCount());
        $this->assertDatabaseHas('merchandise_request_lines', [
            'merchandise_request_id' => $request->id,
            'item_id' => $firstItem->id,
            'requested_pallets' => 2,
            'requested_units' => 200,
        ]);
        $this->assertDatabaseHas('merchandise_request_lines', [
            'merchandise_request_id' => $request->id,
            'item_id' => $secondItem->id,
            'requested_pallets' => 3,
            'requested_units' => 240,
        ]);
    }

    public function test_cliente_request_does_not_decrement_stock_until_dispatch(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'STOCK001',
            'units_per_pallet' => 50,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 50,
            'full_pallets' => 6,
            'peaks_count' => 1,
            'peak_1' => 25,
            'quantity_units' => 325,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'lines' => [
                    'pallet_variant' => [
                        'item_id' => $item->id,
                        'line_type' => 'pallet',
                        'stock_pallet_id' => $stock->id,
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertRedirect();

        $stock->refresh();

        $this->assertSame(6, $stock->full_pallets);
        $this->assertSame(1, $stock->peaks_count);
        $this->assertSame(25, $stock->peak_1);
        $this->assertSame(325, $stock->quantity_units);
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
            ->assertSee('Referencia / SKU')
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

    public function test_cliente_cannot_create_empty_request_with_line_payload(): void
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
                'lines' => [
                    'line_1' => [
                        'item_id' => $item->id,
                        'line_type' => 'pallet',
                        'quantity' => 0,
                    ],
                ],
            ])
            ->assertRedirect(route('merchandise-requests.create'))
            ->assertSessionHasErrors('lines');
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
            ->assertSee('En preparación')
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
            'lot' => null,
            'units_per_pallet' => $matchingItem->units_per_pallet,
            'requested_pallets' => 2,
            'requested_units' => $matchingItem->units_per_pallet * 2,
        ]);
        $otherRequest->lines()->create([
            'item_id' => $otherItem->id,
            'lot' => null,
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

    public function test_ajax_search_excludes_blocked_and_obsolete_items(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'ACTIVO001',
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'BLOQ001',
            'status' => Item::STATUS_BLOCKED,
            'active' => false,
        ]);
        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'OBSO001',
            'status' => Item::STATUS_OBSOLETE,
            'active' => false,
        ]);

        $response = $this->actingAs($cliente)
            ->getJson(route('merchandise-requests.items.search', [
                'search' => '001',
            ]))
            ->assertOk();

        $skus = collect($response->json('data'))->pluck('sku')->all();

        $this->assertContains('ACTIVO001', $skus);
        $this->assertNotContains('BLOQ001', $skus);
        $this->assertNotContains('OBSO001', $skus);
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

    public function test_internal_listing_shows_peaks_units_and_associated_dispatch(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $requester->id,
        ]);
        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'requested_pallets' => 3,
            'requested_peaks' => 0,
            'requested_units' => 120,
            'units_per_pallet' => 40,
        ]);
        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'line_type' => 'peak',
            'requested_pallets' => 0,
            'requested_peaks' => 1,
            'requested_units' => 15,
            'units_per_pallet' => 40,
            'units_per_peak' => 15,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $request->id,
            'type' => GoodsDispatch::TYPE_REQUEST,
        ]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->get(route('merchandise-requests.index'))
            ->assertOk()
            ->assertSee($request->referenceCode())
            ->assertSee('135')
            ->assertSee($dispatch->dispatchNumber());
    }

    public function test_internal_request_detail_prioritizes_loading_workflow(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'OPERATIVO-001',
            'description' => 'Linea visible para preparar',
            'units_per_pallet' => 40,
        ]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $requester->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'requested_pallets' => 2,
            'requested_units' => 80,
            'units_per_pallet' => 40,
        ]);

        $response = $this->actingAs($almacen)
            ->get(route('merchandise-requests.show', $request))
            ->assertOk()
            ->assertSee('Preparación del pedido')
            ->assertSee('Empezar carga')
            ->assertDontSee('Generar salida')
            ->assertSee('OPERATIVO-001')
            ->assertSee('Linea visible para preparar')
            ->assertSee('Pendiente de cargar')
            ->assertSee('Más acciones')
            ->assertSee('Imprimir preparación')
            ->assertSee('Cambiar estado');

        $html = $response->getContent();

        $this->assertLessThan(
            strpos($html, 'Más acciones'),
            strpos($html, 'data-order-preparation-section'),
            'La preparación y las líneas deben aparecer antes que las acciones secundarias.'
        );
    }

    public function test_start_loading_from_request_detail_creates_dispatch_and_opens_loading_screen(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'START-LOAD-001',
            'units_per_pallet' => 50,
        ]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $requester->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'requested_pallets' => 3,
            'requested_units' => 150,
            'units_per_pallet' => 50,
        ]);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $request), [
                'return_to_request' => '1',
            ])
            ->assertRedirect(route('dispatches.requests.show', $request));

        $dispatch = GoodsDispatch::query()->where('merchandise_request_id', $request->id)->firstOrFail();

        $this->assertSame(GoodsDispatch::STATUS_PREPARING, $dispatch->status);
        $this->assertDatabaseHas('goods_dispatch_lines', [
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'requested_pallets' => 3,
        ]);

        $this->actingAs($almacen)
            ->get(route('dispatches.requests.show', $request))
            ->assertOk()
            ->assertSee('START-LOAD-001')
            ->assertSee('LÍNEAS DEL PEDIDO Y CARGA REAL')
            ->assertSee('Partida / lote / ubicaci')
            ->assertSee('GUARDAR PREPARACIÓN');
    }

    public function test_existing_dispatch_shows_continue_loading_on_request_detail(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'sku' => 'CONTINUE-001']);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $requester->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $line = MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'requested_pallets' => 1,
            'requested_units' => $item->units_per_pallet,
            'units_per_pallet' => $item->units_per_pallet,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $request->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $dispatch->lines()->create([
            'item_id' => $item->id,
            'source_request_line_id' => $line->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'line_type' => 'pallet',
            'units_per_pallet' => $item->units_per_pallet,
            'pallets' => 1,
            'requested_pallets' => 1,
            'requested_units' => $item->units_per_pallet,
        ]);

        $this->actingAs($almacen)
            ->get(route('merchandise-requests.show', $request))
            ->assertOk()
            ->assertSee('Continuar carga')
            ->assertDontSee('Empezar carga')
            ->assertDontSee('Generar salida')
            ->assertSee(route('dispatches.requests.show', $request), false);
    }

    public function test_cliente_does_not_see_internal_loading_actions_on_request_detail(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create(['client_id' => $client->id, 'sku' => 'CLIENT-VIEW-001']);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
        ]);

        $this->actingAs($cliente)
            ->get(route('merchandise-requests.show', $request))
            ->assertOk()
            ->assertSee('CLIENT-VIEW-001')
            ->assertDontSee('Empezar carga')
            ->assertDontSee('Continuar carga')
            ->assertDontSee('Más acciones')
            ->assertDontSee('Cambiar estado');
    }

    public function test_internal_roles_can_start_loading_from_request_detail(): void
    {
        $this->seedBaseData();

        foreach ([Role::ALMACEN, Role::ADMINISTRACION, Role::SUPERADMIN] as $roleSlug) {
            $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
            $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
            $internal = $this->makeUserWithRole($roleSlug);
            $item = Item::factory()->create([
                'client_id' => $client->id,
                'sku' => strtoupper($roleSlug).'-LOAD-001',
                'units_per_pallet' => 20,
            ]);
            $request = MerchandiseRequest::factory()->create([
                'client_id' => $client->id,
                'requested_by' => $requester->id,
                'status' => MerchandiseRequest::STATUS_PENDING,
            ]);
            MerchandiseRequestLine::factory()->create([
                'merchandise_request_id' => $request->id,
                'item_id' => $item->id,
                'line_type' => 'pallet',
                'requested_pallets' => 1,
                'requested_units' => 20,
                'units_per_pallet' => 20,
            ]);

            $this->actingAs($internal)
                ->get(route('merchandise-requests.show', $request))
                ->assertOk()
                ->assertSee('Empezar carga');

            $this->actingAs($internal)
                ->post(route('dispatches.requests.generate', $request), [
                    'return_to_request' => '1',
                ])
                ->assertRedirect(route('dispatches.requests.show', $request));

            $this->assertDatabaseHas('goods_dispatches', [
                'merchandise_request_id' => $request->id,
                'created_by' => $internal->id,
                'status' => GoodsDispatch::STATUS_PREPARING,
            ]);
        }
    }

    public function test_request_detail_shows_pallet_and_peak_type_badges(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $requester = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $requester->id,
        ]);
        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'requested_pallets' => 2,
            'requested_peaks' => 0,
            'requested_units' => 80,
            'units_per_pallet' => 40,
        ]);
        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'line_type' => 'peak',
            'requested_pallets' => 0,
            'requested_peaks' => 1,
            'requested_units' => 15,
            'units_per_pallet' => 40,
            'units_per_peak' => 15,
        ]);

        $this->actingAs($requester)
            ->get(route('merchandise-requests.show', $request))
            ->assertOk()
            ->assertSee('Pallet completo')
            ->assertSee('Pico');
    }

    public function test_create_and_show_pages_do_not_render_mojibake(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
        ]);
        MerchandiseRequestLine::factory()->create([
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
        ]);

        foreach ([
            route('merchandise-requests.create'),
            route('merchandise-requests.show', $request),
            route('merchandise-requests.index'),
        ] as $url) {
            $html = $this->actingAs($cliente)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('Ã', $html, "Mojibake found at {$url}");
            $this->assertStringNotContainsString('â€', $html, "Mojibake found at {$url}");
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

    private function assertInternalRoleCanCreateRequestForClient(string $roleSlug): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => strtoupper($roleSlug).'-ORDER-001',
            'units_per_pallet' => 125,
        ]);
        $internalUser = $this->makeUserWithRole($roleSlug);

        $this->actingAs($internalUser)
            ->get(route('merchandise-requests.create', ['client_id' => $client->id]))
            ->assertOk()
            ->assertSee('Cliente del pedido')
            ->assertSee($client->name);

        $this->actingAs($internalUser)
            ->post(route('merchandise-requests.store'), [
                'client_id' => $client->id,
                'lines' => [
                    'line_1' => [
                        'item_id' => $item->id,
                        'line_type' => 'pallet',
                        'quantity' => 3,
                    ],
                ],
            ])
            ->assertRedirect();

        $request = MerchandiseRequest::query()->firstOrFail();

        $this->assertSame($client->id, $request->client_id);
        $this->assertSame($internalUser->id, $request->requested_by);
        $this->assertSame(3, $request->requestedPalletsCount());
        $this->assertDatabaseHas('merchandise_request_lines', [
            'merchandise_request_id' => $request->id,
            'item_id' => $item->id,
            'requested_pallets' => 3,
            'requested_units' => 375,
        ]);
    }
}
