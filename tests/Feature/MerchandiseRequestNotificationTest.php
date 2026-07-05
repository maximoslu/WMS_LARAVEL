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
use App\Notifications\InternalMerchandiseRequestSubmittedNotification;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MerchandiseRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_request_dispatches_submitted_notifications_job_after_response(): void
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
    }

    public function test_submitted_job_delivers_customer_and_internal_notifications(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 700,
        ]);

        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'lot' => $item->lot,
            'units_per_pallet' => $item->units_per_pallet,
            'requested_pallets' => 3,
            'requested_units' => 2100,
        ]);

        (new ProcessMerchandiseRequestSubmittedNotificationsJob($merchandiseRequest->id))
            ->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentTo(
            $cliente,
            CustomerMerchandiseRequestSubmittedNotification::class,
            fn ($notification, array $channels): bool => in_array('mail', $channels, true)
        );

        foreach ([$almacen, $administracion, $superadmin] as $internalUser) {
            Notification::assertSentTo($internalUser, InternalMerchandiseRequestSubmittedNotification::class);
        }
    }

    public function test_status_change_dispatches_job_after_response(): void
    {
        Bus::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);

        $this->actingAs($almacen)
            ->patch(route('merchandise-requests.update-status', $merchandiseRequest), [
                'status' => MerchandiseRequest::STATUS_PREPARING,
            ])
            ->assertRedirect(route('merchandise-requests.show', $merchandiseRequest));

        Bus::assertDispatchedAfterResponse(
            ProcessMerchandiseRequestStatusChangedJob::class,
            fn (ProcessMerchandiseRequestStatusChangedJob $job): bool => $job->merchandiseRequestId === $merchandiseRequest->id
                && $job->previousStatus === MerchandiseRequest::STATUS_PENDING
        );
    }

    public function test_status_change_job_keeps_database_notification_when_email_is_invalid(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'correo-invalido']);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);

        (new ProcessMerchandiseRequestStatusChangedJob(
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_PENDING
        ))->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentTo(
            $cliente,
            CustomerMerchandiseRequestStatusChangedNotification::class,
            fn ($notification, array $channels): bool => in_array('database', $channels, true)
                && ! in_array('mail', $channels, true)
        );
    }

    public function test_cliente_no_recibe_email_en_cambios_intermedios_de_pedido(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'cliente@friesland.test']);
        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);

        (new ProcessMerchandiseRequestStatusChangedJob(
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_PENDING
        ))->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentTo(
            $cliente,
            CustomerMerchandiseRequestStatusChangedNotification::class,
            fn ($notification, array $channels): bool => $channels === ['database']
        );
    }

    public function test_sent_dispatch_job_delivers_delivery_note_notification(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'cliente@friesland.test']);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 700,
        ]);
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
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => $item->lot,
            'units_per_pallet' => $item->units_per_pallet,
            'requested_pallets' => 2,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'lot' => $item->lot,
            'units_per_pallet' => $item->units_per_pallet,
            'requested_pallets' => 2,
            'requested_units' => $item->units_per_pallet * 2,
        ]);

        (new ProcessGoodsDispatchStatusChangedJob(
            $dispatch->id,
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_PREPARING,
            GoodsDispatch::STATUS_SENT,
        ))->handle(app(MerchandiseRequestNotificationService::class));

        $this->assertNotNull($dispatch->fresh()->delivery_note_sent_at);
        Notification::assertSentTo($cliente, CustomerDispatchDeliveryNoteNotification::class);
    }

    public function test_cliente_no_recibe_email_al_generar_salida(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'cliente@friesland.test']);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 700,
        ]);

        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $merchandiseRequest->lines()->create([
            'item_id' => $item->id,
            'lot' => $item->lot,
            'units_per_pallet' => $item->units_per_pallet,
            'requested_pallets' => 3,
            'requested_units' => 2100,
        ]);
        $merchandiseRequest->update(['status' => MerchandiseRequest::STATUS_PENDING]);

        $this->actingAs($almacen)
            ->post(route('dispatches.requests.generate', $merchandiseRequest))
            ->assertRedirect();

        (new ProcessMerchandiseRequestStatusChangedJob(
            $merchandiseRequest->id,
            MerchandiseRequest::STATUS_PENDING
        ))->handle(app(MerchandiseRequestNotificationService::class));

        Notification::assertSentTo(
            $cliente,
            CustomerMerchandiseRequestStatusChangedNotification::class,
            fn ($notification, array $channels): bool => $channels === ['database']
        );
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
