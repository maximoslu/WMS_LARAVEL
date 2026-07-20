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

    public function test_cliente_dashboard_no_muestra_panel_proximo_booking(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Proximos BOOKING')
            ->assertDontSee('Ver agenda');
    }

    public function test_cliente_dashboard_no_muestra_panel_notificaciones(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Notificaciones recientes')
            ->assertDontSee('Ver todas');
    }

    public function test_superadmin_dashboard_no_muestra_panel_proximo_booking(): void
    {
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Proximos bookings')
            ->assertDontSee('Ver agenda');
    }

    public function test_superadmin_dashboard_no_muestra_panel_notificaciones(): void
    {
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Notificaciones recientes')
            ->assertDontSee('Ver todas');
    }

    public function test_almacen_dashboard_no_muestra_panel_proximo_booking(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Proximos bookings')
            ->assertDontSee('Ver agenda');
    }

    public function test_almacen_dashboard_no_muestra_panel_notificaciones(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Notificaciones recientes')
            ->assertDontSee('Ver todas');
    }

    public function test_administracion_dashboard_no_muestra_panel_proximo_booking(): void
    {
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Proximos bookings')
            ->assertDontSee('Ver agenda');
    }

    public function test_administracion_dashboard_no_muestra_panel_notificaciones(): void
    {
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Notificaciones recientes')
            ->assertDontSee('Ver todas');
    }

    public function test_cliente_dashboard_mantiene_agenda_operativa(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Agenda de BOOKING')
            ->assertSee('Abrir agenda');
    }

    public function test_footer_global_se_renderiza_en_dashboard(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('app-footer', false);
    }

    public function test_footer_global_muestra_jorge_monge(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Jorge Monge')
            ->assertSee('WMS creado y desarrollado por Jorge Monge.');
    }

    public function test_footer_global_muestra_2026(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('© 2026')
            ->assertSee('www.jorgemonge.es');
    }

    public function test_footer_global_enlaza_a_jorgemonge(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('https://www.jorgemonge.es', false)
            ->assertSee('target="_blank"', false)
            ->assertSee('rel="noopener noreferrer"', false);
    }

    public function test_footer_global_aparece_para_superadmin(): void
    {
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Jorge Monge')
            ->assertSee('www.jorgemonge.es');
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

    public function test_dashboard_mantiene_accesos_principales_por_rol(): void
    {
        $cliente = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($cliente)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('STOCK')
            ->assertSee('BOOKING')
            ->assertSee('PEDIDOS');

        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Stock actual')
            ->assertSee('Pedidos')
            ->assertSee('Salidas');
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

    public function test_contador_stock_refleja_cuatro_opciones_visibles(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $response = $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk();

        $stockSection = collect($response->viewData('navigationSections'))
            ->firstWhere('key', 'stock');

        $this->assertNotNull($stockSection);
        $this->assertCount(4, $stockSection['children']);
        $this->assertSame('stock-relocations', $stockSection['children'][1]['key']);
        $this->assertSame(
            4,
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

    public function test_almacen_no_puede_importar_stock_masivo(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.import'))
            ->assertForbidden();
    }

    public function test_almacen_no_puede_acceder_a_usuarios_roles_auditoria_y_backups(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('users.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('audit.index'))
            ->assertForbidden();

        $this->actingAs($user)
            ->get(route('modules.backups'))
            ->assertForbidden();
    }

    public function test_superadmin_sigue_pudiendo_todo_en_maestros_y_sistema(): void
    {
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('items.create'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('locations.create'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('suppliers.create'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('users.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('audit.index'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('modules.backups'))
            ->assertOk();
    }

    public function test_administracion_sigue_pudiendo_gestionar_datos_maestros(): void
    {
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($user)
            ->get(route('items.create'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('locations.create'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('suppliers.create'))
            ->assertOk();
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
