<?php

namespace Tests\Feature;

use App\Support\WmsNavigation;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoleAccessTest extends TestCase
{
    use RefreshDatabase;

    public function test_authenticated_user_can_access_dashboard(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Rol')
            ->assertSee('Cliente');
    }

    public function test_superadmin_sees_full_administration_sections(): void
    {
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Usuarios y roles')
            ->assertSee('Backups')
            ->assertSee('Auditoria y trazabilidad')
            ->assertSee('Operaciones diarias')
            ->assertSee('Stock actual')
            ->assertSee('module-link-icon', false);
    }

    public function test_cliente_does_not_see_administrative_sections(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('STOCK')
            ->assertSee('BOOKING')
            ->assertSee('PEDIDOS')
            ->assertDontSee('Mi inventario')
            ->assertDontSee('Solicitudes')
            ->assertDontSee('Solicitar mercancia')
            ->assertDontSee('Usuarios y roles')
            ->assertDontSee('Backups')
            ->assertDontSee('Auditoria y trazabilidad');
    }

    public function test_cliente_dashboard_links_stock_booking_and_pedidos_to_expected_routes(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee(route('stock.index'), false)
            ->assertSee(route('bookings.index'), false)
            ->assertSee(route('merchandise-requests.create'), false);
    }

    public function test_almacen_sees_pedidos_link_pointing_to_internal_listing(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Pedidos')
            ->assertSee(route('merchandise-requests.index'), false);
    }

    public function test_dashboard_stock_no_muestra_palets(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Stock actual')
            ->assertSee('Articulos')
            ->assertSee('Ubicaciones')
            ->assertDontSee('Palets');
    }

    public function test_contador_stock_refleja_tres_opciones_visibles(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $stockSection = collect($response->viewData('navigationSections'))
            ->firstWhere('key', 'stock');

        $this->assertNotNull($stockSection);
        $this->assertCount(3, $stockSection['children']);
        $this->assertSame(
            3,
            count(collect(WmsNavigation::sectionsForUser($user))->firstWhere('key', 'stock')['children'])
        );
    }

    public function test_higher_role_can_access_lower_role_route(): void
    {
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('goods-receipts.index'))
            ->assertOk()
            ->assertSee('Entradas');
    }

    public function test_lower_role_is_blocked_from_higher_role_route(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('modules.backups'))
            ->assertForbidden();
    }

    public function test_role_seeder_creates_expected_roles(): void
    {
        $this->seed(RoleSeeder::class);

        foreach (Role::defaults() as $role) {
            $this->assertDatabaseHas('roles', [
                'slug' => $role['slug'],
                'level' => $role['level'],
            ]);
        }
    }

    public function test_superadmin_seeder_assigns_maximo_account(): void
    {
        $this->seed([
            RoleSeeder::class,
            SuperAdminSeeder::class,
        ]);

        $this->assertDatabaseHas('users', [
            'email' => 'administracion@maximosl.com',
        ]);

        $user = User::query()->where('email', 'administracion@maximosl.com')->firstOrFail();

        $this->assertSame(Role::SUPERADMIN, $user->role?->slug);
        $this->assertNotNull($user->email_verified_at);
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $this->seed(RoleSeeder::class);

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $clientId = null;

        if ($roleSlug === Role::CLIENTE) {
            $clientId = \App\Models\Client::factory()->create()->id;
        }

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $clientId,
        ]);
    }
}
