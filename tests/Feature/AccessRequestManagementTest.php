<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class AccessRequestManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_almacen_and_cliente_cannot_open_access_requests_index(): void
    {
        $this->seedBaseData();

        foreach ([Role::ALMACEN, Role::CLIENTE] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('access-requests.index'))
                ->assertForbidden();
        }
    }

    public function test_superadmin_can_view_pending_access_requests(): void
    {
        $this->seedBaseData();

        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $accessRequest = AccessRequest::factory()->create([
            'name' => 'Ana Responsable',
            'company' => 'Friesland',
        ]);

        $this->actingAs($superadmin)
            ->get(route('access-requests.index'))
            ->assertOk()
            ->assertSee('Solicitudes de acceso')
            ->assertSee($accessRequest->name);
    }

    public function test_administracion_can_view_pending_access_requests(): void
    {
        $this->seedBaseData();

        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);
        $accessRequest = AccessRequest::factory()->create([
            'email' => 'cliente@friesland.test',
        ]);

        $this->actingAs($admin)
            ->get(route('access-requests.index'))
            ->assertOk()
            ->assertSee($accessRequest->email);
    }

    public function test_approve_access_request_creates_cliente_user_with_client_assignment(): void
    {
        $this->seedBaseData();
        $this->configureBrevo();

        Http::fake([
            'https://api.brevo.com/*' => Http::response(['messageId' => 'approved-1'], 201),
        ]);

        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $clienteRole = Role::query()->where('slug', Role::CLIENTE)->firstOrFail();
        $accessRequest = AccessRequest::factory()->create([
            'name' => 'Cliente Nuevo',
            'email' => 'cliente.nuevo@friesland.test',
        ]);

        $this->actingAs($admin)
            ->patch(route('access-requests.approve', $accessRequest), [
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('access-requests.show', $accessRequest));

        $this->assertDatabaseHas('users', [
            'email' => 'cliente.nuevo@friesland.test',
            'role_id' => $clienteRole->id,
            'client_id' => $client->id,
            'active' => true,
        ]);

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => AccessRequest::STATUS_APPROVED,
            'client_id' => $client->id,
            'approved_by' => $admin->id,
        ]);

        Http::assertSent(fn ($request): bool => $request['subject'] === 'MAXIMO WMS - Tu acceso ha sido aprobado');
    }

    public function test_approve_access_request_updates_existing_user_client_and_active(): void
    {
        $this->seedBaseData();

        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $clienteRole = Role::query()->where('slug', Role::CLIENTE)->firstOrFail();
        $existingUser = User::factory()->create([
            'name' => 'Usuario Existente',
            'email' => 'existente@cliente.test',
            'role_id' => $clienteRole->id,
            'client_id' => null,
            'active' => false,
        ]);
        $accessRequest = AccessRequest::factory()->create([
            'name' => 'Usuario Existente',
            'email' => $existingUser->email,
        ]);

        $this->actingAs($superadmin)
            ->patch(route('access-requests.approve', $accessRequest), [
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('access-requests.show', $accessRequest));

        $this->assertDatabaseHas('users', [
            'id' => $existingUser->id,
            'email' => $existingUser->email,
            'role_id' => $clienteRole->id,
            'client_id' => $client->id,
            'active' => true,
        ]);
    }

    public function test_reject_access_request_marks_request_as_rejected(): void
    {
        $this->seedBaseData();
        $this->configureBrevo();

        Http::fake([
            'https://api.brevo.com/*' => Http::response(['messageId' => 'rejected-1'], 201),
        ]);

        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);
        $accessRequest = AccessRequest::factory()->create();

        $this->actingAs($admin)
            ->patch(route('access-requests.reject', $accessRequest), [
                'rejection_reason' => 'Falta validar la cuenta del cliente.',
            ])
            ->assertRedirect(route('access-requests.show', $accessRequest));

        $this->assertDatabaseHas('access_requests', [
            'id' => $accessRequest->id,
            'status' => AccessRequest::STATUS_REJECTED,
            'rejected_by' => $admin->id,
            'rejection_reason' => 'Falta validar la cuenta del cliente.',
        ]);
    }

    public function test_approved_cliente_user_remains_assigned_only_to_selected_client(): void
    {
        $this->seedBaseData();

        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $otherClient = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $accessRequest = AccessRequest::factory()->create([
            'email' => 'solo.cliente@friesland.test',
        ]);

        $this->actingAs($admin)
            ->patch(route('access-requests.approve', $accessRequest), [
                'client_id' => $client->id,
            ])
            ->assertRedirect(route('access-requests.show', $accessRequest));

        $approvedUser = User::query()->where('email', 'solo.cliente@friesland.test')->firstOrFail();

        $this->assertSame($client->id, $approvedUser->client_id);
        $this->assertNotSame($otherClient->id, $approvedUser->client_id);
    }

    public function test_users_index_shows_approved_user_with_cliente_role_and_client_assignment(): void
    {
        $this->seedBaseData();

        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $accessRequest = AccessRequest::factory()->create([
            'name' => 'Cliente Visible',
            'email' => 'visible@friesland.test',
        ]);

        $this->actingAs($superadmin)
            ->patch(route('access-requests.approve', $accessRequest), [
                'client_id' => $client->id,
            ]);

        $this->actingAs($superadmin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Cliente Visible')
            ->assertSee('Cliente')
            ->assertSee('FRIESLAND');
    }

    private function seedBaseData(): void
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
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
