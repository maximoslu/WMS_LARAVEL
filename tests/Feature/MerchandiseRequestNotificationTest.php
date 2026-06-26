<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerMerchandiseRequestStatusChangedNotification;
use App\Notifications\CustomerMerchandiseRequestSubmittedNotification;
use App\Notifications\InternalMerchandiseRequestSubmittedNotification;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class MerchandiseRequestNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_receives_email_notification_when_request_is_created(): void
    {
        Notification::fake();
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

        Notification::assertSentTo(
            $cliente,
            CustomerMerchandiseRequestSubmittedNotification::class,
            fn ($notification, array $channels): bool => in_array('mail', $channels, true)
        );
    }

    public function test_internal_users_receive_notification_when_request_is_created(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
        ]);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($cliente)
            ->post(route('merchandise-requests.store'), [
                'quantities' => [
                    $item->id => 2,
                ],
            ])
            ->assertRedirect();

        foreach ([$almacen, $administracion, $superadmin] as $internalUser) {
            Notification::assertSentTo(
                $internalUser,
                InternalMerchandiseRequestSubmittedNotification::class
            );
        }
    }

    public function test_status_change_to_sent_notifies_cliente_through_dispatch_workflow(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
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
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
            'confirmed_by' => $almacen->id,
        ]);

        $this->actingAs($almacen)
            ->patch(route('dispatches.update-status', $dispatch), [
                'status' => GoodsDispatch::STATUS_SENT,
            ])
            ->assertRedirect(route('dispatches.show', $dispatch));

        $this->assertDatabaseHas('merchandise_requests', [
            'id' => $merchandiseRequest->id,
            'status' => MerchandiseRequest::STATUS_SENT,
        ]);

        Notification::assertSentTo(
            $cliente,
            CustomerMerchandiseRequestStatusChangedNotification::class,
            fn ($notification, array $channels): bool => in_array('mail', $channels, true)
        );
    }

    public function test_status_change_with_invalid_email_keeps_database_notification_without_breaking_flow(): void
    {
        Notification::fake();
        $this->seedBaseData();

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $cliente->update(['email' => 'correo-invalido']);
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

        Notification::assertSentTo(
            $cliente,
            CustomerMerchandiseRequestStatusChangedNotification::class,
            fn ($notification, array $channels): bool => in_array('database', $channels, true)
                && ! in_array('mail', $channels, true)
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
