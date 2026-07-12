<?php

namespace Tests\Feature;

use App\Jobs\ProcessGoodsDispatchLoadingConfirmedNotificationsJob;
use App\Jobs\ProcessGoodsDispatchStatusChangedJob;
use App\Models\Client;
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
                'camion_propio' => '1',
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

    public function test_internal_user_can_update_own_truck_flag_on_existing_dispatch(): void
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
            ->get(route('dispatches.show', $dispatch))
            ->assertOk()
            ->assertSee('Cami&oacute;n propio', false);
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
            'camion_propio' => true,
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
            ->assertSee('GENERAR SALIDA')
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
            ->assertSee('Ver salida')
            ->assertDontSee('GENERAR SALIDA')
            ->assertSee('Cargar desde')
            ->assertSee('GUARDAR PREPARACIÓN')
            ->assertSee('name="lines[line_'.$dispatchLine->id.'][loaded_quantity]"', false)
            ->assertSee('name="lines[line_'.$dispatchLine->id.'][loaded_pallets]"', false)
            ->assertSee('name="lines[line_'.$dispatchLine->id.'][loaded_partial_units]"', false)
            ->assertSee('name="lines[line_'.$dispatchLine->id.'][loading_notes]"', false);
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

    public function test_internal_loading_rejects_partial_units_above_requested_quantity(): void
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
            ->assertSessionHasErrors('lines.line_'.$line->id.'.loaded_partial_units');

        $this->assertNull($line->fresh()->confirmed_at);
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
            ->assertSessionHasErrors('lines.line_'.$line->id.'.stock_pallet_id');

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

    public function test_cliente_no_recibe_email_al_confirmar_carga_real(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'cliente@friesland.test']);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
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

        Notification::assertSentTo($almacen, InternalGoodsDispatchLoadingConfirmedNotification::class);
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
            'loading_notes' => 'Se cargan pallets extra.',
            'confirmed_at' => now(),
        ]);

        $html = view('dispatches.delivery-note-pdf', [
            'dispatch' => $dispatch->load('client', 'lines'),
        ])->render();

        $this->assertStringContainsString('Pallets entregados', $html);
        $this->assertStringContainsString('Calle Mayor 1', $html);
        $this->assertStringContainsString('5', $html);
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
        $this->assertNotNull($dispatch->fresh()->stock_applied_at);
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
        $this->assertSame(5000, $untouchedStock->fresh()->quantity_units);
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

        // Simulates a duplicate form submit for the same target status.
        $this->actingAs($almacen)
            ->from(route('dispatches.show', $dispatch))
            ->patch(route('dispatches.update-status', $dispatch->fresh()), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionHas('status', 'La salida ya estaba en ese estado.');

        $this->assertSame(320, $stock->fresh()->quantity_units);
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

        $builder = app(\App\Support\Stock\StockOverviewBuilder::class);
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
        $this->assertSame(0, $peakStock->fresh()->peak_1);
        $this->assertSame(600, $peakStock->fresh()->quantity_units);
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
