<?php

namespace Tests\Feature;

use App\Jobs\ProcessGoodsDispatchLoadingConfirmedNotificationsJob;
use App\Jobs\ProcessGoodsDispatchStatusChangedJob;
use App\Models\Client;
use App\Models\ClientDispatchEmailRecipient;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Notifications\CustomerDispatchDeliveryNoteNotification;
use App\Notifications\CustomerMerchandiseRequestStatusChangedNotification;
use App\Notifications\InternalGoodsDispatchLoadingConfirmedNotification;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use App\Services\Stock\StockExportService;
use App\Support\Stock\StockOverviewBuilder;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class GoodsDispatchManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_cannot_access_goods_dispatch_module(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->get(route('dispatches.index'))
            ->assertForbidden();
    }

    public function test_internal_roles_can_access_goods_dispatch_module(): void
    {
        $this->seedBaseData();

        foreach ([Role::ALMACEN, Role::ADMINISTRACION, Role::SUPERADMIN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('dispatches.index'))
                ->assertOk()
                ->assertSee('Salida de mercancía');
        }
    }

    public function test_dispatch_index_uses_refined_cards_and_compact_header_actions(): void
    {
        $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->get(route('dispatches.index'))
            ->assertOk()
            ->assertSee('dispatch-entry-grid--refined', false)
            ->assertSee('dispatch-section-card', false)
            ->assertSee('dispatch-section-action', false)
            ->assertSee('dispatch-table-wrap', false);
    }

    public function test_dispatch_index_shows_ver_todos_as_compact_button_not_full_width_bar(): void
    {
        $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->get(route('dispatches.index'))
            ->assertOk()
            ->assertSee('dispatch-section-action', false)
            ->assertSee('Ver todos');
    }

    public function test_internal_view_shows_statuses_in_spanish(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);

        $this->actingAs($almacen)
            ->get(route('dispatches.show', $dispatch))
            ->assertOk()
            ->assertSee('En preparación')
            ->assertDontSee('Preparing');
    }

    public function test_manual_dispatch_requires_client_and_lines(): void
    {
        $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->from(route('dispatches.create'))
            ->post(route('dispatches.store'), [
                'client_id' => null,
                'quantities' => [],
            ])
            ->assertRedirect(route('dispatches.create'))
            ->assertSessionHasErrors(['client_id', 'quantities']);
    }

    public function test_valid_manual_dispatch_is_saved_with_lines(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA000X',
            'units_per_pallet' => 700,
        ]);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->post(route('dispatches.store'), [
                'client_id' => $client->id,
                'notes' => 'Salida urgente',
                'quantities' => [
                    $item->id => 4,
                ],
            ])
            ->assertRedirect();

        $dispatch = GoodsDispatch::query()->firstOrFail();

        $this->assertSame(GoodsDispatch::STATUS_DRAFT, $dispatch->status);
        $this->assertSame(GoodsDispatch::TYPE_MANUAL, $dispatch->type);
        $this->assertTrue($dispatch->camion_propio);
        $this->assertDatabaseHas('goods_dispatch_lines', [
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'sku' => 'CAJA000X',
            'pallets' => 4,
            'requested_units' => 2800,
        ]);
    }

    public function test_internal_user_can_change_transport_to_external_and_back_to_own_truck(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'camion_propio' => false,
            'created_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->put(route('dispatches.own-truck.update', $dispatch), [
                'camion_propio' => '1',
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertTrue($dispatch->fresh()->camion_propio);

        $this->actingAs($almacen)
            ->put(route('dispatches.own-truck.update', $dispatch), [
                'camion_propio' => '0',
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertFalse($dispatch->fresh()->camion_propio);

        $this->actingAs($almacen)
            ->put(route('dispatches.own-truck.update', $dispatch), [
                'camion_propio' => '1',
            ]);

        $this->actingAs($almacen)
            ->get(route('dispatches.show', $dispatch))
            ->assertOk()
            ->assertSee('Camión propio MAXIMO')
            ->assertSee('Camión externo')
            ->assertSee('Actualizar transporte')
            ->assertDontSee('Guardar camión');
    }

    public function test_dispatch_from_request_copies_lines_and_prevents_duplicates(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 500,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
            'camion_propio' => false,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'lot' => $item->lot,
            'units_per_pallet' => 500,
            'requested_pallets' => 3,
            'requested_units' => 1500,
        ]);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest))
            ->assertRedirect();

        $dispatch = GoodsDispatch::query()->firstOrFail();

        $this->assertSame($merchandiseRequest->id, $dispatch->merchandise_request_id);
        $this->assertTrue($dispatch->camion_propio);
        $this->assertDatabaseHas('goods_dispatch_lines', [
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'pallets' => 3,
        ]);
        $this->assertDatabaseHas('merchandise_requests', [
            'id' => $merchandiseRequest->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest))
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame(1, GoodsDispatch::query()->count());
    }

    public function test_internal_request_page_prioritizes_actions_and_lines_over_reference_and_tracking(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'LINEA-PRIORITARIA',
            'description' => 'Mercancía para preparar',
            'units_per_pallet' => 100,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 100,
            'requested_pallets' => 3,
            'requested_peaks' => 0,
            'requested_units' => 300,
        ]);

        $response = $this->actingAs($almacen)
            ->get(route('dispatches.requests.show', $merchandiseRequest))
            ->assertOk()
            ->assertSee($client->name)
            ->assertSee('Pendiente')
            ->assertSee('Pallets')
            ->assertSee('Picos')
            ->assertSee('Empezar carga')
            ->assertDontSee('GENERAR SALIDA')
            ->assertSee('Imprimir preparación')
            ->assertSee('LÍNEAS DEL PEDIDO Y CARGA REAL')
            ->assertSee('LINEA-PRIORITARIA')
            ->assertDontSee('Cambiar estado')
            ->assertDontSee('Nuevo estado')
            ->assertDontSee('El almacén ve enseguida')
            ->assertDontSee('Zona de decisión rápida')
            ->assertDontSee('Cada línea refleja claramente');

        $html = $response->getContent();

        $this->assertStringNotContainsString('<h2 class="ops-page-title page-title-compact">'.$merchandiseRequest->referenceCode(), $html);
        $this->assertLessThan(
            strpos($html, 'Seguimiento del pedido'),
            strpos($html, 'data-request-lines-section'),
            'Las líneas deben aparecer antes que el seguimiento.'
        );
    }

    public function test_internal_request_page_shows_view_dispatch_and_compact_loading_editor_when_dispatch_exists(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'sku' => 'CARGA-001']);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $requestLine = $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 100,
            'requested_pallets' => 2,
            'requested_units' => 200,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $dispatchLine = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'source_request_line_id' => $requestLine->id,
            'sku' => $item->sku,
            'requested_pallets' => 2,
            'loaded_pallets' => 2,
        ]);

        $this->actingAs($almacen)
            ->get(route('dispatches.requests.show', $merchandiseRequest))
            ->assertOk()
            ->assertDontSee('Ver salida t')
            ->assertDontSee('GENERAR SALIDA')
            ->assertSee('Partida / lote / ubicaci')
            ->assertSee('data-add-assignment', false)
            ->assertSee('Cerrar pedido')
            ->assertSee('Camión externo')
            ->assertSee('Camión propio MAXIMO')
            ->assertSee('Guardar preparación')
            ->assertSee('Confirmar envío')
            ->assertDontSee('Confirmar envío y abrir albarán')
            ->assertSee('name="lines[line_'.$dispatchLine->id.'][loaded_quantity]"', false)
            ->assertSee('name="lines[line_'.$dispatchLine->id.'][loaded_pallets]"', false)
            ->assertSee('name="lines[line_'.$dispatchLine->id.'][loaded_partial_units]"', false)
            ->assertSee('name="lines[line_'.$dispatchLine->id.'][loading_notes]"', false)
            ->assertDontSee('@hidden', false)
            ->assertDontSee('id)&gt;', false);
    }

    public function test_loading_can_be_saved_from_internal_request_page_without_dispatching_stock(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'requested_pallets' => 3,
            'loaded_pallets' => null,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'return_to_request' => '1',
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'loaded_quantity' => 2,
                        'loading_notes' => 'Falta un pallet.',
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.requests.show', $merchandiseRequest));

        $line->refresh();
        $dispatch->refresh();

        $this->assertSame(2, $line->loadedPallets());
        $this->assertSame('Falta un pallet.', $line->loading_notes);
        $this->assertSame(GoodsDispatch::STATUS_PREPARING, $dispatch->status);
        $this->assertNull($dispatch->stock_applied_at);
    }

    public function test_internal_request_page_confirms_dispatch_and_keeps_preparation_open(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 100,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 100,
            'quantity_units' => 500,
            'full_pallets' => 5,
            'warehouse_pallets' => 5,
            'peak_1' => 0,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $requestLine = $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 100,
            'requested_pallets' => 2,
            'requested_units' => 200,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
            'camion_propio' => true,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'source_request_line_id' => $requestLine->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 100,
            'requested_pallets' => 2,
            'requested_units' => 200,
            'loaded_pallets' => null,
            'loaded_partial_units' => null,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'return_to_request' => '1',
                'finalize_dispatch' => '1',
                'camion_propio' => '0',
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'stock_pallet_id' => $stock->id,
                        'loaded_pallets' => 2,
                        'loaded_partial_units' => 0,
                        'allocations' => [
                            [
                                'stock_pallet_id' => $stock->id,
                                'loaded_pallets' => 2,
                                'loaded_partial_units' => 0,
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.requests.show', $merchandiseRequest));

        $dispatch->refresh();
        $merchandiseRequest->refresh();
        $stock->refresh();

        $this->assertSame(GoodsDispatch::STATUS_SENT, $dispatch->status);
        $this->assertSame(MerchandiseRequest::STATUS_SENT, $merchandiseRequest->status);
        $this->assertFalse($dispatch->camion_propio);
        $this->assertNotNull($dispatch->stock_applied_at);
        $this->assertSame(300, (int) $stock->quantity_units);
        $this->assertSame(3.0, (float) $stock->warehouse_pallets);
    }

    public function test_internal_request_page_saves_full_pallets_and_partial_peak_units_for_selected_batch(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'MIXTO-LOAD',
            'units_per_pallet' => 1000,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-MIXTO',
            'location_text' => 'A-01',
            'units_per_pallet' => 1000,
            'quantity_units' => 5000,
            'peak_1' => 0,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $requestLine = $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 1000,
            'requested_pallets' => 2,
            'requested_peaks' => 0,
            'requested_units' => 2000,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'source_request_line_id' => $requestLine->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 1000,
            'requested_pallets' => 2,
            'requested_units' => 2000,
            'loaded_pallets' => null,
            'loaded_partial_units' => null,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'return_to_request' => '1',
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'stock_pallet_id' => $stock->id,
                        'loaded_pallets' => 1,
                        'loaded_partial_units' => 300,
                        'loading_notes' => 'Un pallet completo y pico real.',
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.requests.show', $merchandiseRequest));

        $line->refresh();

        $this->assertSame($stock->id, $line->stock_pallet_id);
        $this->assertSame(1, $line->loadedPallets());
        $this->assertSame(300, $line->loadedPartialUnits());
        $this->assertSame(1300, $line->loadedUnitsTotal());
        $this->assertSame('Un pallet completo y pico real.', $line->loading_notes);
        $this->assertSame(GoodsDispatch::STATUS_PREPARING, $dispatch->fresh()->status);
        $this->assertNull($dispatch->fresh()->stock_applied_at);
    }

    public function test_internal_request_page_saves_multiple_real_allocations_for_same_line(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0031',
            'units_per_pallet' => 700,
        ]);
        $stockA = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-A',
            'location_text' => 'A-01',
            'units_per_pallet' => 700,
            'quantity_units' => 7500,
            'peak_1' => 500,
        ]);
        $stockB = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-B',
            'location_text' => 'B-01',
            'units_per_pallet' => 700,
            'quantity_units' => 7390,
            'peak_1' => 390,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $requestLine = $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 700,
            'requested_pallets' => 3,
            'requested_peaks' => 0,
            'requested_units' => 2100,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'source_request_line_id' => $requestLine->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 700,
            'requested_pallets' => 3,
            'requested_units' => 2100,
            'loaded_pallets' => null,
            'loaded_partial_units' => null,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'return_to_request' => '1',
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'allocations' => [
                            [
                                'stock_pallet_id' => $stockA->id,
                                'loaded_pallets' => 1,
                                'selected_peak_indices' => [1],
                            ],
                            [
                                'stock_pallet_id' => $stockB->id,
                                'loaded_pallets' => 0,
                                'selected_peak_indices' => [1],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.requests.show', $merchandiseRequest));

        $line->refresh()->load('allocations');

        $this->assertSame($stockA->id, $line->stock_pallet_id);
        $this->assertSame(1, $line->loadedPallets());
        $this->assertSame(890, $line->loadedPartialUnits());
        $this->assertSame(1590, $line->loadedUnitsTotal());
        $this->assertTrue($line->hasLoadingDifference());
        $this->assertCount(2, $line->allocations);
        $this->assertSame([['index' => 1, 'units' => 500]], $line->allocations[0]->selected_peaks);
        $this->assertSame([['index' => 1, 'units' => 390]], $line->allocations[1]->selected_peaks);
    }

    public function test_caja0031_three_pallets_can_be_prepared_as_one_pallet_plus_two_existing_peaks(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0031',
            'units_per_pallet' => 700,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'CAJA0031-LOTE',
            'location_text' => 'C-01',
            'units_per_pallet' => 700,
            'quantity_units' => 308890,
            'peak_1' => 500,
            'peak_2' => 390,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 700,
            'requested_pallets' => 3,
            'requested_units' => 2100,
            'loaded_pallets' => null,
            'loaded_partial_units' => null,
        ]);

        $this->assertSame(440, $stock->full_pallets);

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'allocations' => [
                            [
                                'stock_pallet_id' => $stock->id,
                                'loaded_pallets' => 1,
                                'selected_peak_indices' => [1, 2],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $line->refresh()->load('allocations');

        $this->assertSame(1, $line->loadedPallets());
        $this->assertSame(890, $line->loadedPartialUnits());
        $this->assertSame(1590, $line->loadedUnitsTotal());
        $this->assertTrue($line->hasLoadingDifference());
        $this->assertSame([['index' => 1, 'units' => 500], ['index' => 2, 'units' => 390]], $line->allocations->first()->selected_peaks);
    }

    public function test_internal_loading_rejects_reusing_same_peak_twice(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 700]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 700,
            'quantity_units' => 1200,
            'peak_1' => 500,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 700,
            'requested_pallets' => 2,
            'requested_units' => 1400,
            'loaded_pallets' => null,
        ]);

        $this->actingAs($almacen)
            ->from(route('dispatches.show', $dispatch))
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'allocations' => [
                            [
                                'stock_pallet_id' => $stock->id,
                                'selected_peak_indices' => [1],
                            ],
                            [
                                'stock_pallet_id' => $stock->id,
                                'selected_peak_indices' => [1],
                            ],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionHasErrors('lines.line_'.$line->id.'.allocations.1.selected_peak_indices');

        $this->assertNull($line->fresh()->confirmed_at);
    }

    public function test_internal_loading_allows_real_quantity_above_requested_when_stock_is_sufficient(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 1000]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 1000,
            'quantity_units' => 5000,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 1000,
            'requested_pallets' => 1,
            'requested_units' => 1000,
            'loaded_pallets' => null,
        ]);

        $this->actingAs($almacen)
            ->from(route('dispatches.show', $dispatch))
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'stock_pallet_id' => $stock->id,
                        'loaded_pallets' => 1,
                        'loaded_partial_units' => 1,
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionDoesntHaveErrors();

        $line->refresh();

        $this->assertSame(1001, $line->loadedUnitsTotal());
        $this->assertSame('superior', $line->loadingStatus());
        $this->assertSame('Carga superior a lo solicitado', $line->loadingStatusLabel());
        $this->assertNotNull($line->confirmed_at);
        $this->assertSame(5000, $stock->fresh()->quantity_units, 'Saving preparation must not apply stock yet.');
    }

    public function test_loading_status_distinguishes_unprepared_partial_complete_and_superior(): void
    {
        $line = new GoodsDispatchLine([
            'line_type' => 'pallet',
            'units_per_pallet' => 100,
            'requested_pallets' => 3,
            'requested_units' => 300,
            'loaded_partial_units' => 0,
        ]);

        $line->loaded_pallets = 0;
        $this->assertSame('pending', $line->loadingStatus());

        $line->loaded_pallets = 1;
        $this->assertSame('partial', $line->loadingStatus());

        $line->loaded_pallets = 3;
        $this->assertSame('complete', $line->loadingStatus());

        $line->loaded_pallets = 4;
        $this->assertSame('superior', $line->loadingStatus());
        $this->assertSame('Carga superior a lo solicitado', $line->loadingStatusLabel());
    }

    public function test_internal_loading_rejects_negative_real_quantities(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 100,
            'quantity_units' => 500,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 100,
            'requested_pallets' => 1,
            'requested_units' => 100,
            'loaded_pallets' => null,
        ]);

        $this->actingAs($almacen)
            ->from(route('dispatches.show', $dispatch))
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'allocations' => [[
                            'stock_pallet_id' => $stock->id,
                            'loaded_pallets' => -1,
                            'loaded_partial_units' => -10,
                        ]],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionHasErrors([
                'lines.line_'.$line->id.'.allocations.0.loaded_pallets',
                'lines.line_'.$line->id.'.allocations.0.loaded_partial_units',
            ])
            ->assertSessionDoesntHaveErrors('lines');

        $this->assertNull($line->fresh()->confirmed_at);
        $this->assertSame(500, $stock->fresh()->quantity_units);
    }

    public function test_internal_loading_rejects_partial_units_above_selected_batch_stock(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 1000]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 1000,
            'quantity_units' => 100,
            'peak_1' => 100,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 1000,
            'requested_pallets' => 1,
            'requested_units' => 1000,
            'loaded_pallets' => null,
        ]);

        $this->actingAs($almacen)
            ->from(route('dispatches.show', $dispatch))
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'stock_pallet_id' => $stock->id,
                        'loaded_pallets' => 0,
                        'loaded_partial_units' => 101,
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionHasErrors('lines.line_'.$line->id.'.allocations.0.stock_pallet_id')
            ->assertSessionDoesntHaveErrors('lines');

        $this->assertSame(100, $stock->fresh()->quantity_units);
    }

    public function test_dispatch_from_request_preserves_peak_lines(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'PICO-DISPATCH',
            'units_per_pallet' => 600,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-DISPATCH',
            'units_per_pallet' => 600,
            'full_pallets' => 1,
            'peaks_count' => 1,
            'peak_1' => 95,
            'peak_2' => 0,
            'peak_3' => 0,
            'peak_4' => 0,
            'peak_5' => 0,
            'peak_6' => 0,
            'peak_7' => 0,
            'peak_8' => 0,
            'peak_9' => 0,
            'peak_10' => 0,
            'quantity_units' => 695,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'line_type' => 'peak',
            'stock_peak_index' => 1,
            'lot' => 'LOT-DISPATCH',
            'units_per_pallet' => 600,
            'units_per_peak' => 95,
            'requested_pallets' => 0,
            'requested_peaks' => 1,
            'requested_units' => 95,
        ]);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest))
            ->assertRedirect();

        $dispatch = GoodsDispatch::query()->firstOrFail();

        $this->assertDatabaseHas('goods_dispatch_lines', [
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'line_type' => 'peak',
            'stock_peak_index' => 1,
            'requested_pallets' => 0,
            'requested_peaks' => 1,
            'requested_units' => 95,
        ]);
    }

    public function test_request_and_dispatch_pages_do_not_render_mojibake(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 40,
            'requested_pallets' => 2,
            'requested_peaks' => 0,
            'requested_units' => 80,
        ]);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest))
            ->assertRedirect();

        $dispatch = GoodsDispatch::query()->firstOrFail();

        foreach ([
            route('dispatches.requests.show', $merchandiseRequest),
            route('dispatches.show', $dispatch),
            route('dispatches.index'),
        ] as $url) {
            $html = $this->actingAs($almacen)->get($url)->assertOk()->getContent();

            $this->assertStringNotContainsString('Ã', $html, "Mojibake found at {$url}");
            $this->assertStringNotContainsString('â€', $html, "Mojibake found at {$url}");
        }
    }

    public function test_changing_dispatch_status_to_sent_sets_sent_at(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
            'sent_at' => null,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 40,
            'requested_pallets' => 2,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_SENT,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertNotNull($dispatch->fresh()->sent_at);
    }

    public function test_delivery_note_pdf_responds_correctly_when_dispatch_is_sent(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $client->update([
            'delivery_address' => 'Calle Mayor 1',
            'delivery_postal_code' => '28001',
            'delivery_city' => 'Madrid',
            'delivery_province' => 'Madrid',
            'delivery_country' => 'Espana',
        ]);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 3,
            'loaded_pallets' => 4,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->get(route('dispatches.delivery-note', $dispatch))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_dispatch_pdf_links_open_in_new_tab(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_SENT,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 2,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->get(route('dispatches.show', $dispatch))
            ->assertOk()
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false);

        $this->actingAs($almacen)
            ->get(route('dispatches.requests.show', $merchandiseRequest))
            ->assertOk()
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_preparation_pdf_responds_correctly(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
        ]);

        $this->actingAs($almacen)
            ->get(route('merchandise-requests.preparation-pdf', $merchandiseRequest))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_cannot_complete_dispatch_without_confirming_loaded_lines(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 2,
            'loaded_pallets' => null,
            'confirmed_at' => null,
            'confirmed_by' => null,
        ]);

        $this->actingAs($almacen)
            ->from(route('dispatches.show', $dispatch))
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_COMPLETED,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionHasErrors('status');

        $this->assertNull($dispatch->fresh()->completed_at);
    }

    public function test_loaded_pallets_can_be_confirmed_line_by_line_and_store_confirmation_data(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $this->makeUserWithRole(Role::SUPERADMIN);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 3,
            'loaded_pallets' => null,
            'confirmed_at' => null,
            'confirmed_by' => null,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    $line->id => [
                        'loaded_pallets' => 3,
                        'loading_notes' => 'Se confirma la carga completa.',
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $line->refresh();

        $this->assertSame(3, $line->loadedPallets());
        $this->assertSame($almacen->id, $line->confirmed_by);
        $this->assertNotNull($line->confirmed_at);
        $this->assertSame('Se confirma la carga completa.', $line->loading_notes);
        Bus::assertDispatchedAfterResponse(
            ProcessGoodsDispatchLoadingConfirmedNotificationsJob::class,
            fn (ProcessGoodsDispatchLoadingConfirmedNotificationsJob $job): bool => $job->goodsDispatchId === $dispatch->id
                && $job->confirmedByUserId === $almacen->id
        );
    }

    public function test_confirmar_carga_real_no_envia_email_y_no_notifica_al_actor(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'cliente@friesland.test']);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 1,
            'loaded_pallets' => 1,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        (new ProcessGoodsDispatchLoadingConfirmedNotificationsJob($dispatch->id, $almacen->id))
            ->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertNotSentTo($almacen, InternalGoodsDispatchLoadingConfirmedNotification::class);
        Notification::assertSentTo($administracion, InternalGoodsDispatchLoadingConfirmedNotification::class, fn ($notification, array $channels): bool => $channels === ['database']);
        Notification::assertNotSentTo($administracion, InternalGoodsDispatchLoadingConfirmedNotification::class, fn ($notification, array $channels): bool => $channels === ['mail']);
        Notification::assertNotSentTo($cliente, InternalGoodsDispatchLoadingConfirmedNotification::class);
        Notification::assertNotSentTo($cliente, CustomerDispatchDeliveryNoteNotification::class);
        Notification::assertNotSentTo($cliente, CustomerMerchandiseRequestStatusChangedNotification::class);
    }

    public function test_confirming_loading_can_add_extra_reference_lines(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $requestedItem = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'BASE0001',
        ]);
        $extraItem = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'EXTRA0001',
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $requestedItem->id,
            'sku' => $requestedItem->sku,
            'description' => $requestedItem->description,
            'lot' => $requestedItem->lot,
            'requested_pallets' => 3,
            'loaded_pallets' => null,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'item_id' => $requestedItem->id,
                        'loaded_pallets' => 0,
                        'loading_notes' => 'La referencia original no sale.',
                    ],
                    'new_extra' => [
                        'item_id' => $extraItem->id,
                        'loaded_pallets' => 2,
                        'loading_notes' => 'Sustitucion operativa.',
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertDatabaseHas('goods_dispatch_lines', [
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $extraItem->id,
            'is_extra_line' => true,
            'requested_pallets' => 0,
            'loaded_pallets' => 2,
        ]);
    }

    public function test_completing_dispatch_sends_customer_email_with_delivery_note_attachment(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'cliente@friesland.test']);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_SENT,
            'shipped_at' => now(),
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 3,
            'loaded_pallets' => 4,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $line->item_id,
            'lot' => $line->lot,
            'units_per_pallet' => $line->units_per_pallet ?? 1,
            'requested_pallets' => 3,
            'requested_units' => ($line->units_per_pallet ?? 1) * 3,
        ]);

        (new ProcessGoodsDispatchStatusChangedJob(
            $dispatch->id,
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_SENT,
            GoodsDispatch::STATUS_COMPLETED
        ))->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentTo(
            $cliente,
            CustomerDispatchDeliveryNoteNotification::class,
            function ($notification, array $channels) use ($cliente): bool {
                $mail = $notification->toMail($cliente);

                return in_array('mail', $channels, true)
                    && $mail instanceof MailMessage
                    && count($mail->rawAttachments) > 0;
            }
        );
    }

    public function test_delivery_note_is_sent_to_additional_dispatch_email_recipients(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        ClientDispatchEmailRecipient::factory()->create([
            'client_id' => $client->id,
            'email' => 'carretillero@edelvives.test',
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'usuario@edelvives.test']);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_SENT,
            'shipped_at' => now(),
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 1,
            'loaded_pallets' => 1,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        (new ProcessGoodsDispatchStatusChangedJob(
            $dispatch->id,
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_PREPARING,
            GoodsDispatch::STATUS_SENT
        ))->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentTo($cliente, CustomerDispatchDeliveryNoteNotification::class);
        Notification::assertSentOnDemand(
            CustomerDispatchDeliveryNoteNotification::class,
            function ($notification, array $channels, object $notifiable): bool {
                return in_array('mail', $channels, true)
                    && $notifiable->routeNotificationFor('mail') === 'carretillero@edelvives.test';
            }
        );
    }

    public function test_sent_dispatch_sends_delivery_note_once_and_stores_delivery_note_sent_at(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'cliente@friesland.test']);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 40,
            'requested_pallets' => 2,
            'loaded_pallets' => 3,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_SENT,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        Bus::assertDispatchedAfterResponse(
            ProcessGoodsDispatchStatusChangedJob::class,
            fn (ProcessGoodsDispatchStatusChangedJob $job): bool => $job->goodsDispatchId === $dispatch->id
                && $job->currentStatus === GoodsDispatch::STATUS_SENT
        );
    }

    public function test_completed_status_does_not_duplicate_delivery_note_when_it_was_already_sent(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'cliente@friesland.test']);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_SENT,
            'shipped_at' => now(),
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
            'delivery_note_sent_at' => now(),
        ]);
        $merchandiseRequest->update([
            'status' => MerchandiseRequest::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 2,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        (new ProcessGoodsDispatchStatusChangedJob(
            $dispatch->id,
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_SENT,
            GoodsDispatch::STATUS_COMPLETED
        ))->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentTo($cliente, CustomerMerchandiseRequestStatusChangedNotification::class);
        Notification::assertNotSentTo($cliente, CustomerDispatchDeliveryNoteNotification::class);
    }

    public function test_albaran_view_shows_loaded_quantities_and_delivery_address(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $client->update([
            'delivery_address' => 'Calle Mayor 1',
            'delivery_postal_code' => '28001',
            'delivery_city' => 'Madrid',
            'delivery_province' => 'Madrid',
            'delivery_country' => 'Espana',
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 2,
            'loaded_pallets' => 5,
            'destination_location' => 'Muelle cliente 2',
            'loading_notes' => 'Se cargan pallets extra.',
            'confirmed_at' => now(),
        ]);

        $html = view('dispatches.delivery-note-pdf', [
            'dispatch' => $dispatch->load('client', 'lines'),
        ])->render();

        $this->assertStringContainsString('Pallets entregados', $html);
        $this->assertStringContainsString('Ubicación destino', $html);
        $this->assertStringContainsString('Muelle cliente 2', $html);
        $this->assertStringContainsString('Calle Mayor 1', $html);
        $this->assertStringContainsString('5', $html);
    }

    public function test_dispatch_from_request_copies_destination_location_to_delivery_note(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'UBIC-DEST-001',
            'units_per_pallet' => 50,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 50,
            'requested_pallets' => 1,
            'requested_units' => 50,
            'destination_location' => 'Zona devoluciones EDE',
        ]);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest), [
                'return_to_request' => '1',
            ])
            ->assertRedirect(route('dispatches.requests.show', $merchandiseRequest));

        $dispatch = GoodsDispatch::query()->where('merchandise_request_id', $merchandiseRequest->id)->firstOrFail();
        $line = $dispatch->lines()->firstOrFail();

        $this->assertSame('Zona devoluciones EDE', $line->destination_location);

        $line->update([
            'loaded_pallets' => 1,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);
        $dispatch->update([
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
        ]);

        $html = view('dispatches.delivery-note-pdf', [
            'dispatch' => $dispatch->load('client', 'lines'),
        ])->render();

        $this->assertStringContainsString('Zona devoluciones EDE', $html);
    }

    public function test_dispatch_uses_superior_real_loading_and_deducts_it_once(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SUPERIOR-LOAD',
            'units_per_pallet' => 100,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 100,
            'quantity_units' => 500,
            'peak_1' => 0,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $requestLine = $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 100,
            'requested_pallets' => 3,
            'requested_peaks' => 0,
            'requested_units' => 300,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'source_request_line_id' => $requestLine->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 100,
            'requested_pallets' => 3,
            'requested_units' => 300,
            'loaded_pallets' => null,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'return_to_request' => '1',
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'allocations' => [[
                            'stock_pallet_id' => $stock->id,
                            'loaded_pallets' => 4,
                        ]],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.requests.show', $merchandiseRequest))
            ->assertSessionDoesntHaveErrors();

        $this->actingAs($almacen)
            ->get(route('dispatches.requests.show', $merchandiseRequest))
            ->assertOk()
            ->assertSee('Carga superior a lo solicitado')
            ->assertSee('Exceso operativo');

        $this->assertSame(500, $stock->fresh()->quantity_units, 'Preparation must not deduct stock.');

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame(100, $stock->fresh()->quantity_units, 'Dispatch must deduct the four actually loaded pallets, not the three requested.');
        $this->assertSame(1, $stock->fresh()->full_pallets);
        $this->assertSame(1.0, (float) $stock->fresh()->warehouse_pallets);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch->fresh()), ['status' => GoodsDispatch::STATUS_COMPLETED])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame(100, $stock->fresh()->quantity_units, 'Completing the dispatch must not deduct the superior load twice.');
    }

    public function test_enviar_salida_descuenta_pallets_completos(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);
        $this->assertSame(10, $stock->fresh()->full_pallets);

        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 40,
            'requested_pallets' => 3,
            'loaded_pallets' => 3,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_SENT,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionHas('status', 'Salida enviada y stock actualizado correctamente.');

        $freshStock = $stock->fresh();
        $this->assertSame(7, $freshStock->full_pallets);
        $this->assertSame(280, $freshStock->quantity_units);
        $this->assertSame(7.0, (float) $freshStock->warehouse_pallets);
        $this->assertNotNull($dispatch->fresh()->stock_applied_at);
        $this->assertNotNull($dispatch->fresh()->warehouse_stock_applied_at);
    }

    public function test_enviar_salida_descuenta_picos(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 600]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 600,
            'quantity_units' => 695,
            'peak_1' => 95,
        ]);
        $this->assertSame(1, $stock->fresh()->full_pallets);
        $this->assertSame(95, $stock->fresh()->peak_1);

        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'line_type' => 'peak',
            'stock_peak_index' => 1,
            'units_per_pallet' => 600,
            'units_per_peak' => 95,
            'requested_pallets' => 0,
            'requested_peaks' => 1,
            'loaded_pallets' => 0,
            'loaded_peaks' => 1,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_SENT,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $freshStock = $stock->fresh();
        $this->assertSame(0, $freshStock->peak_1);
        $this->assertSame(600, $freshStock->quantity_units);
        $this->assertSame(1, $freshStock->full_pallets, 'Full pallets must stay untouched when only a peak is shipped.');
        $this->assertSame(1.0, (float) $freshStock->warehouse_pallets);
    }

    public function test_enviar_parte_de_un_pico_conserva_la_unidad_logistica_hasta_vaciarla(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 600]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 600,
            'quantity_units' => 650,
            'warehouse_pallets' => 2,
            'peak_1' => 50,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'line_type' => 'peak',
            'stock_peak_index' => 1,
            'units_per_pallet' => 600,
            'units_per_peak' => 50,
            'requested_peaks' => 1,
            'loaded_pallets' => 0,
            'loaded_peaks' => 0,
            'loaded_partial_units' => 20,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $freshStock = $stock->fresh();
        $this->assertSame(630, $freshStock->quantity_units);
        $this->assertSame(30, $freshStock->peak_1);
        $this->assertSame(1, $freshStock->full_pallets);
        $this->assertSame(2.0, (float) $freshStock->warehouse_pallets);
    }

    public function test_enviar_salida_descuenta_pallets_y_pico_parcial_de_la_partida_elegida(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 1000]);
        $selectedStock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-ELEGIDO',
            'units_per_pallet' => 1000,
            'quantity_units' => 5000,
            'peak_1' => 0,
        ]);
        $untouchedStock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-NO-ELEGIDO',
            'units_per_pallet' => 1000,
            'quantity_units' => 5000,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $selectedStock->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 1000,
            'requested_pallets' => 2,
            'requested_units' => 2000,
            'loaded_pallets' => 1,
            'loaded_partial_units' => 300,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_SENT,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $freshSelectedStock = $selectedStock->fresh();
        $this->assertSame(3700, $freshSelectedStock->quantity_units);
        $this->assertSame(3, $freshSelectedStock->full_pallets);
        $this->assertSame(700, $freshSelectedStock->peak_1);
        $this->assertSame(4.0, (float) $freshSelectedStock->warehouse_pallets);
        $this->assertSame(5000, $untouchedStock->fresh()->quantity_units);
    }

    public function test_enviar_salida_descuenta_asignaciones_reales_multiples(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0031',
            'units_per_pallet' => 700,
        ]);
        $stockA = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-A',
            'units_per_pallet' => 700,
            'quantity_units' => 4200,
            'peak_1' => 0,
        ]);
        $stockB = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-B',
            'units_per_pallet' => 700,
            'quantity_units' => 890,
            'peak_1' => 500,
            'peak_2' => 390,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stockA->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 700,
            'requested_pallets' => 3,
            'requested_units' => 2100,
            'loaded_pallets' => 1,
            'loaded_partial_units' => 890,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);
        $line->allocations()->create([
            'stock_pallet_id' => $stockA->id,
            'lot' => $stockA->lot,
            'location_text' => $stockA->location_text,
            'loaded_pallets' => 1,
            'loaded_partial_units' => 0,
            'selected_peaks' => [],
        ]);
        $line->allocations()->create([
            'stock_pallet_id' => $stockB->id,
            'lot' => $stockB->lot,
            'location_text' => $stockB->location_text,
            'loaded_pallets' => 0,
            'loaded_partial_units' => 890,
            'selected_peaks' => [['index' => 1, 'units' => 500], ['index' => 2, 'units' => 390]],
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_SENT,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame(3500, $stockA->fresh()->quantity_units);
        $this->assertSame(5, $stockA->fresh()->full_pallets);
        $freshStockB = $stockB->fresh();
        $this->assertSame(0, $freshStockB->quantity_units);
        $this->assertSame(0, $freshStockB->peak_1);
        $this->assertSame(0, $freshStockB->peak_2);
        $this->assertSame(0.0, (float) $freshStockB->warehouse_pallets);
    }

    public function test_salida_enviada_no_descuenta_dos_veces(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 40,
            'requested_pallets' => 2,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame(320, $stock->fresh()->quantity_units);
        $this->assertSame(8.0, (float) $stock->fresh()->warehouse_pallets);

        // Simulates a duplicate form submit for the same target status.
        $this->actingAs($almacen)
            ->from(route('dispatches.show', $dispatch))
            ->patch(route('dispatches.update-status', $dispatch->fresh()), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionHas('status', 'La salida ya estaba en ese estado.');

        $this->assertSame(320, $stock->fresh()->quantity_units);
        $this->assertSame(8.0, (float) $stock->fresh()->warehouse_pallets);
    }

    public function test_completar_salida_no_descuenta_dos_veces(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 40,
            'requested_pallets' => 2,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $afterSent = $stock->fresh()->quantity_units;
        $this->assertSame(320, $afterSent);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch->fresh()), ['status' => GoodsDispatch::STATUS_COMPLETED])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame($afterSent, $stock->fresh()->quantity_units);
        $this->assertNotNull($dispatch->fresh()->completed_at);
    }

    public function test_roles_internos_ven_y_pueden_usar_la_accion_directa_de_completar_una_salida_enviada(): void
    {
        Bus::fake();
        $this->seedBaseData();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);
            $dispatch = GoodsDispatch::factory()->create([
                'client_id' => $client->id,
                'status' => GoodsDispatch::STATUS_SENT,
                'sent_at' => now(),
                'stock_applied_at' => now(),
            ]);
            GoodsDispatchLine::factory()->create([
                'goods_dispatch_id' => $dispatch->id,
                'item_id' => $item->id,
                'units_per_pallet' => 40,
                'requested_pallets' => 1,
                'loaded_pallets' => 1,
                'confirmed_at' => now(),
                'confirmed_by' => $user->id,
            ]);

            $this->actingAs($user)
                ->get(route('dispatches.show', $dispatch))
                ->assertOk()
                ->assertSee('Marcar como completado')
                ->assertSee('name="status" value="'.GoodsDispatch::STATUS_COMPLETED.'"', false);

            $this->actingAs($user)
                ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_COMPLETED])
                ->assertRedirect(route('dispatches.show', $dispatch));

            $this->assertSame(GoodsDispatch::STATUS_COMPLETED, $dispatch->fresh()->status);
            $this->assertNotNull($dispatch->fresh()->completed_at);
        }
    }

    public function test_linea_cargada_a_cero_no_descuenta(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $itemA = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $stockA = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $itemA->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);
        $itemB = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 20]);
        $stockB = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $itemB->id,
            'units_per_pallet' => 20,
            'quantity_units' => 200,
            'peak_1' => 0,
        ]);

        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $itemA->id,
            'stock_pallet_id' => $stockA->id,
            'units_per_pallet' => 40,
            'requested_pallets' => 2,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $itemB->id,
            'stock_pallet_id' => $stockB->id,
            'units_per_pallet' => 20,
            'requested_pallets' => 3,
            'loaded_pallets' => 0,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame(320, $stockA->fresh()->quantity_units, 'Line with loaded quantity must still deduct.');
        $this->assertSame(200, $stockB->fresh()->quantity_units, 'Line loaded to zero must not deduct.');
        $this->assertSame(10, $stockB->fresh()->full_pallets);
    }

    public function test_linea_extra_descuenta_stock(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $itemA = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $stockA = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $itemA->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);
        $itemExtra = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 25]);
        $stockExtra = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $itemExtra->id,
            'units_per_pallet' => 25,
            'quantity_units' => 250,
            'peak_1' => 0,
        ]);

        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $itemA->id,
            'stock_pallet_id' => $stockA->id,
            'units_per_pallet' => 40,
            'requested_pallets' => 1,
            'loaded_pallets' => 1,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $itemExtra->id,
            'stock_pallet_id' => $stockExtra->id,
            'units_per_pallet' => 25,
            'requested_pallets' => 0,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
            'is_extra_line' => true,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame(200, $stockExtra->fresh()->quantity_units, 'Extra line must also deduct stock.');
        $this->assertSame(8, $stockExtra->fresh()->full_pallets);
    }

    public function test_no_permite_enviar_si_no_hay_stock_suficiente(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'sku' => 'SKU-CORTO', 'units_per_pallet' => 40]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 40,
            'quantity_units' => 40,
            'peak_1' => 0,
        ]);
        $this->assertSame(1, $stock->fresh()->full_pallets);

        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'sku' => 'SKU-CORTO',
            'units_per_pallet' => 40,
            'requested_pallets' => 5,
            'loaded_pallets' => 5,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->from(route('dispatches.show', $dispatch))
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionHasErrors('status');

        $freshDispatch = $dispatch->fresh();
        $this->assertSame(GoodsDispatch::STATUS_PREPARING, $freshDispatch->status);
        $this->assertNull($freshDispatch->sent_at);
        $this->assertNull($freshDispatch->stock_applied_at);
        $this->assertSame(40, $stock->fresh()->quantity_units, 'Stock must not be partially deducted.');
    }

    public function test_no_descuenta_stock_de_otro_cliente(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::factory()->create();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $otherItem = Item::factory()->create(['client_id' => $otherClient->id, 'units_per_pallet' => 40]);
        $otherStock = StockPallet::factory()->create([
            'client_id' => $otherClient->id,
            'item_id' => $otherItem->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);

        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        // Forces a cross-client reference that normal request/dispatch flows never
        // produce, to prove the allocation service refuses to touch it.
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $otherItem->id,
            'stock_pallet_id' => $otherStock->id,
            'units_per_pallet' => 40,
            'requested_pallets' => 2,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->from(route('dispatches.show', $dispatch))
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertSessionHasErrors('status');

        $this->assertSame(400, $otherStock->fresh()->quantity_units, 'Stock of another client must never be touched.');
        $this->assertSame(GoodsDispatch::STATUS_PREPARING, $dispatch->fresh()->status);
    }

    public function test_stock_overview_refleja_descuento_despues_de_enviar(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 40,
            'requested_pallets' => 4,
            'loaded_pallets' => 4,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $builder = app(StockOverviewBuilder::class);
        $before = $builder->build($almacen, ['search' => $item->sku]);
        $rowBefore = collect($before['rows'])->firstWhere('id', $stock->id);
        $this->assertNotNull($rowBefore);
        $this->assertSame(10, $rowBefore['full_pallets']);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $after = $builder->build($almacen, ['search' => $item->sku]);
        $rowAfter = collect($after['rows'])->firstWhere('id', $stock->id);
        $this->assertNotNull($rowAfter);
        $this->assertSame(6, $rowAfter['full_pallets']);
        $this->assertSame(6.0, $rowAfter['warehouse_pallets']);
        $this->assertSame(6.0, $after['summary']['total_warehouse_pallets']);
    }

    public function test_edelvives_1026_menos_10_deja_1016_en_db_overview_pantalla_y_export(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'EDEL-REGRESION-1026',
            'description' => 'Caso regresion stock pallets almacen',
            'units_per_pallet' => 100,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-1026',
            'units_per_pallet' => 100,
            'quantity_units' => 102600,
            'full_pallets' => 1026,
            'warehouse_pallets' => 1026,
            'peak_1' => 0,
        ]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $request->id,
            'type' => GoodsDispatch::TYPE_REQUEST,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 100,
            'requested_pallets' => 8,
            'requested_units' => 800,
            'loaded_pallets' => 10,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $freshStock = $stock->fresh();
        $freshDispatch = $dispatch->fresh();
        $this->assertSame(101600, $freshStock->quantity_units);
        $this->assertSame(1016, $freshStock->full_pallets);
        $this->assertSame(1016.0, (float) $freshStock->warehouse_pallets);
        $this->assertNotNull($freshDispatch->stock_applied_at);
        $this->assertNotNull($freshDispatch->warehouse_stock_applied_at);

        $overview = app(StockOverviewBuilder::class)->build($almacen, [
            'client_id' => $client->id,
            'search' => $item->sku,
        ]);
        $row = collect($overview['rows'])->firstWhere('id', $stock->id);
        $this->assertSame(1016.0, $overview['summary']['total_warehouse_pallets']);
        $this->assertSame(1016.0, $row['warehouse_pallets']);

        $this->actingAs($almacen)
            ->get(route('stock.index', ['client_id' => $client->id, 'search' => $item->sku]))
            ->assertOk()
            ->assertSeeText('1.016,00')
            ->assertDontSeeText('1.026,00');

        $exportRow = app(StockExportService::class)->rows($client->id)->firstWhere('sku', $item->sku);
        $this->assertSame(101600, $exportRow['quantity']);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $freshDispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect();

        $this->assertSame(101600, $stock->fresh()->quantity_units);
        $this->assertSame(1016.0, (float) $stock->fresh()->warehouse_pallets);
    }

    public function test_flujo_pedidos_pallet_y_pico_sigue_funcionando(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $palletItem = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 40]);
        $palletStock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $palletItem->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'peak_1' => 0,
        ]);
        $peakItem = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 600]);
        $peakStock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $peakItem->id,
            'units_per_pallet' => 600,
            'quantity_units' => 695,
            'peak_1' => 95,
        ]);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'lines' => [
                    'pallet_line' => [
                        'item_id' => $palletItem->id,
                        'line_type' => 'pallet',
                        'stock_pallet_id' => $palletStock->id,
                        'quantity' => 2,
                    ],
                    'peak_line' => [
                        'item_id' => $peakItem->id,
                        'line_type' => 'peak',
                        'stock_pallet_id' => $peakStock->id,
                        'stock_peak_index' => 1,
                        'quantity' => 1,
                    ],
                ],
            ])
            ->assertRedirect();

        $merchandiseRequest = MerchandiseRequest::query()->firstOrFail();

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest))
            ->assertRedirect();

        $dispatch = GoodsDispatch::query()->firstOrFail();
        $lines = $dispatch->lines()->get()->keyBy('line_type');

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    $lines['pallet']->id => ['line_id' => $lines['pallet']->id, 'loaded_quantity' => 2],
                    $lines['peak']->id => ['line_id' => $lines['peak']->id, 'loaded_quantity' => 1],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch->fresh()), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertSame(320, $palletStock->fresh()->quantity_units);
        $this->assertSame(8, $palletStock->fresh()->full_pallets);
        $this->assertSame(8.0, (float) $palletStock->fresh()->warehouse_pallets);
        $this->assertSame(0, $peakStock->fresh()->peak_1);
        $this->assertSame(600, $peakStock->fresh()->quantity_units);
        $this->assertSame(1.0, (float) $peakStock->fresh()->warehouse_pallets);
        $this->assertSame(GoodsDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertSame(MerchandiseRequest::STATUS_SENT, $merchandiseRequest->fresh()->status);
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
