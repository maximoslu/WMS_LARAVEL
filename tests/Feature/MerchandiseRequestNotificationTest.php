<?php

namespace Tests\Feature;

use App\Jobs\ProcessGoodsDispatchStatusChangedJob;
use App\Jobs\ProcessMerchandiseRequestStatusChangedJob;
use App\Jobs\ProcessMerchandiseRequestSubmittedNotificationsJob;
use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerDispatchDeliveryNoteNotification;
use App\Notifications\CustomerMerchandiseRequestStatusChangedNotification;
use App\Notifications\CustomerMerchandiseRequestSubmittedNotification;
use App\Notifications\InternalGoodsDispatchCompletedNotification;
use App\Notifications\InternalMerchandiseRequestSubmittedNotification;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MerchandiseRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_request_dispatches_only_the_creation_notifications_job_after_response(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 700,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 3,
                ],
            ])
            ->assertRedirect();

        $request = MerchandiseRequest::query()->firstOrFail();

        Bus::assertDispatchedAfterResponse(
            ProcessMerchandiseRequestSubmittedNotificationsJob::class,
            fn (ProcessMerchandiseRequestSubmittedNotificationsJob $job): bool => $job->merchandiseRequestId === $request->id
        );
        Bus::assertNotDispatched(ProcessMerchandiseRequestStatusChangedJob::class);
        Bus::assertNotDispatched(ProcessGoodsDispatchStatusChangedJob::class);
    }

    public function test_creation_hito_generates_one_customer_and_one_internal_notification_with_mail_channels(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $merchandiseRequest = $this->createMerchandiseRequestWithLine($client, $cliente);

        (new ProcessMerchandiseRequestSubmittedNotificationsJob($merchandiseRequest->id))
            ->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentToTimes($cliente, CustomerMerchandiseRequestSubmittedNotification::class, 1);
        Notification::assertSentTo(
            $cliente,
            CustomerMerchandiseRequestSubmittedNotification::class,
            fn ($notification, array $channels): bool => $channels === ['database', 'mail']
        );

        foreach ([$almacen, $administracion, $superadmin] as $internalUser) {
            Notification::assertSentToTimes($internalUser, InternalMerchandiseRequestSubmittedNotification::class, 1);
            Notification::assertSentTo(
                $internalUser,
                InternalMerchandiseRequestSubmittedNotification::class,
                fn ($notification, array $channels): bool => $channels === ['database', 'mail']
            );
        }
    }

    public function test_internal_user_creating_order_for_client_uses_the_same_customer_recipients(): void
    {
        Bus::fake();
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $internalCreator = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 500,
        ]);

        $this->actingAs($internalCreator)
            ->post(route('merchandise-requests.store'), [
                'client_id' => $client->id,
                'quantities' => [
                    $item->id => 2,
                ],
            ])
            ->assertRedirect();

        $request = MerchandiseRequest::query()->firstOrFail();

        (new ProcessMerchandiseRequestSubmittedNotificationsJob($request->id))
            ->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentToTimes($cliente, CustomerMerchandiseRequestSubmittedNotification::class, 1);
        Notification::assertNotSentTo($internalCreator, CustomerMerchandiseRequestSubmittedNotification::class);
    }

    public function test_preparation_status_change_does_not_queue_or_send_communications(): void
    {
        Bus::fake();
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = $this->createMerchandiseRequestWithLine($client, $cliente);

        $this->actingAs($almacen)
            ->patch(route('merchandise-requests.update-status', $merchandiseRequest), [
                'status' => MerchandiseRequest::STATUS_PREPARING,
            ])
            ->assertRedirect(route('merchandise-requests.show', $merchandiseRequest));

        Bus::assertNotDispatched(ProcessMerchandiseRequestStatusChangedJob::class);
        Notification::assertNothingSent();

        (new ProcessMerchandiseRequestStatusChangedJob(
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_PENDING
        ))->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertNotSentTo($cliente, CustomerMerchandiseRequestStatusChangedNotification::class);
    }

    public function test_generating_dispatch_and_confirming_lines_do_not_send_intermediate_communications(): void
    {
        Bus::fake();
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = $this->createMerchandiseRequestWithLine($client, $cliente);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest))
            ->assertRedirect();

        Bus::assertNotDispatched(ProcessMerchandiseRequestStatusChangedJob::class);

        $dispatch = GoodsDispatch::query()->firstOrFail();
        $line = $dispatch->lines()->firstOrFail();

        $this->actingAs($almacen)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'loaded_pallets' => 1,
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        Notification::assertNothingSent();
    }

    public function test_sent_status_does_not_send_delivery_note_or_status_change_communication(): void
    {
        Bus::fake();
        Notification::fake();
        $this->seedBaseData();

        [$merchandiseRequest, $dispatch, $stock] = $this->createConfirmedDispatch();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_SENT,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        Bus::assertNotDispatched(ProcessGoodsDispatchStatusChangedJob::class);
        Notification::assertNothingSent();
        $this->assertNull($dispatch->fresh()->delivery_note_sent_at);
        $this->assertSame(MerchandiseRequest::STATUS_SENT, $merchandiseRequest->fresh()->status);
        $this->assertSame(360, $stock->fresh()->quantity_units);
    }

    public function test_completion_hito_notifies_internal_and_customer_once_with_delivery_note_attachment(): void
    {
        Notification::fake();
        $this->seedBaseData();

        [$merchandiseRequest, $dispatch] = $this->createConfirmedDispatch(GoodsDispatch::STATUS_SENT);
        $cliente = $merchandiseRequest->requestedBy;
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        (new ProcessGoodsDispatchStatusChangedJob(
            $dispatch->id,
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_SENT,
            GoodsDispatch::STATUS_COMPLETED
        ))->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentToTimes($administracion, InternalGoodsDispatchCompletedNotification::class, 1);
        Notification::assertSentTo(
            $administracion,
            InternalGoodsDispatchCompletedNotification::class,
            fn ($notification, array $channels): bool => $channels === ['database', 'mail']
        );
        Notification::assertSentToTimes($cliente, CustomerDispatchDeliveryNoteNotification::class, 1);
        Notification::assertSentTo(
            $cliente,
            CustomerDispatchDeliveryNoteNotification::class,
            function ($notification, array $channels) use ($cliente): bool {
                $mail = $notification->toMail($cliente);

                return $channels === ['database', 'mail']
                    && $mail instanceof MailMessage
                    && count($mail->rawAttachments) === 1;
            }
        );
        Notification::assertNotSentTo($almacen, CustomerMerchandiseRequestStatusChangedNotification::class);
        $this->assertNotNull($dispatch->fresh()->delivery_note_sent_at);
    }

    public function test_delivery_note_generation_and_retried_completion_job_do_not_duplicate_final_communication(): void
    {
        Notification::fake();
        $this->seedBaseData();

        [$merchandiseRequest, $dispatch] = $this->createConfirmedDispatch(GoodsDispatch::STATUS_SENT);

        (new ProcessGoodsDispatchStatusChangedJob(
            $dispatch->id,
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_SENT,
            GoodsDispatch::STATUS_COMPLETED
        ))->handle(app(MerchandiseRequestNotificationService::class));

        (new ProcessGoodsDispatchStatusChangedJob(
            $dispatch->id,
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_SENT,
            GoodsDispatch::STATUS_COMPLETED
        ))->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentToTimes($merchandiseRequest->requestedBy, CustomerDispatchDeliveryNoteNotification::class, 1);
    }

    public function test_existing_notification_history_is_not_modified_by_intermediate_changes(): void
    {
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = $this->createMerchandiseRequestWithLine($client, $cliente);

        $cliente->notifications()->create([
            'id' => (string) \Illuminate\Support\Str::uuid(),
            'type' => CustomerMerchandiseRequestSubmittedNotification::class,
            'data' => ['type' => 'historico', 'reference' => $merchandiseRequest->referenceCode()],
        ]);
        $notificationId = $cliente->notifications()->firstOrFail()->id;

        $this->actingAs($almacen)
            ->patch(route('merchandise-requests.update-status', $merchandiseRequest), [
                'status' => MerchandiseRequest::STATUS_PREPARING,
            ])
            ->assertRedirect(route('merchandise-requests.show', $merchandiseRequest));

        $this->assertDatabaseHas('notifications', [
            'id' => $notificationId,
            'notifiable_id' => $cliente->id,
        ]);
    }

    private function seedBaseData(): void
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);
    }

    private function createMerchandiseRequestWithLine(Client $client, User $cliente): MerchandiseRequest
    {
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 40,
        ]);

        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);

        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'lot' => $item->lot,
            'units_per_pallet' => 40,
            'requested_pallets' => 1,
            'requested_units' => 40,
        ]);

        return $merchandiseRequest->fresh(['client', 'requestedBy', 'lines.item']);
    }

    /**
     * @return array{0: MerchandiseRequest, 1: GoodsDispatch, 2: \App\Models\StockPallet}
     */
    private function createConfirmedDispatch(string $status = GoodsDispatch::STATUS_PREPARING): array
    {
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 40,
        ]);
        $stock = \App\Models\StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 40,
            'quantity_units' => 400,
            'full_pallets' => 10,
            'warehouse_pallets' => 10,
            'peak_1' => 0,
        ]);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => $status,
            'shipped_at' => in_array($status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true) ? now() : null,
            'completed_at' => $status === GoodsDispatch::STATUS_COMPLETED ? now() : null,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'type' => GoodsDispatch::TYPE_REQUEST,
            'status' => $status,
            'sent_at' => in_array($status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true) ? now() : null,
            'completed_at' => $status === GoodsDispatch::STATUS_COMPLETED ? now() : null,
            'stock_applied_at' => in_array($status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true) ? now() : null,
            'warehouse_stock_applied_at' => in_array($status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true) ? now() : null,
        ]);
        $requestLine = $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'lot' => $item->lot,
            'units_per_pallet' => 40,
            'requested_pallets' => 1,
            'requested_units' => 40,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'source_request_line_id' => $requestLine->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => $item->lot,
            'units_per_pallet' => 40,
            'requested_pallets' => 1,
            'requested_units' => 40,
            'loaded_pallets' => 1,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        return [
            $merchandiseRequest->fresh(['client', 'requestedBy', 'lines.item']),
            $dispatch->fresh(['client', 'merchandiseRequest', 'lines.item']),
            $stock,
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
