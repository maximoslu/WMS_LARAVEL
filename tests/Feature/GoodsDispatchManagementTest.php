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
                'quantities' => [
                    $item->id => 4,
                ],
            ])
            ->assertRedirect();

        $dispatch = GoodsDispatch::query()->firstOrFail();

        $this->assertSame(GoodsDispatch::STATUS_DRAFT, $dispatch->status);
        $this->assertSame(GoodsDispatch::TYPE_MANUAL, $dispatch->type);
        $this->assertDatabaseHas('goods_dispatch_lines', [
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'sku' => 'CAJA000X',
            'pallets' => 4,
            'requested_units' => 2800,
        ]);
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

    public function test_changing_dispatch_status_to_sent_sets_sent_at(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
            'sent_at' => null,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
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
                        'loaded_pallets' => 5,
                        'loading_notes' => 'Se carga un pallet extra por operativa.',
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $line->refresh();

        $this->assertSame(5, $line->loadedPallets());
        $this->assertSame($almacen->id, $line->confirmed_by);
        $this->assertNotNull($line->confirmed_at);
        $this->assertSame('Se carga un pallet extra por operativa.', $line->loading_notes);
        Bus::assertDispatchedAfterResponse(
            ProcessGoodsDispatchLoadingConfirmedNotificationsJob::class,
            fn (ProcessGoodsDispatchLoadingConfirmedNotificationsJob $job): bool => $job->goodsDispatchId === $dispatch->id
                && $job->confirmedByUserId === $almacen->id
        );
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
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
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
