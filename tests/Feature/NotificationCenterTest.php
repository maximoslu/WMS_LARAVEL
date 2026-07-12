<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_keeps_unread_counter_in_topbar_without_notification_panel(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->createNotifications($user, 7);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Notificaciones recientes')
            ->assertDontSee('Aviso 007')
            ->assertSee(route('notifications.index'), false)
            ->assertSee('aria-label="Notificaciones, 7 sin leer"', false)
            ->assertSee('users-pending-count', false)
            ->assertSee('>7<', false);
    }

    public function test_notifications_index_is_paginated_with_summary(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->createNotifications($user, 12);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Mostrando 1-10 de 12 notificaciones')
            ->assertSee('Siguiente')
            ->assertDontSee('Aviso 002')
            ->assertDontSee('Aviso 001');

        $this->actingAs($user)
            ->get(route('notifications.index', ['page' => 2]))
            ->assertOk()
            ->assertSee('Mostrando 11-12 de 12 notificaciones')
            ->assertSee('Anterior')
            ->assertSee('Aviso 002')
            ->assertSee('Aviso 001');
    }

    public function test_notifications_empty_state_is_clear_on_index_and_dashboard_has_no_panel(): void
    {
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Notificaciones recientes')
            ->assertSee('Notificaciones');

        $client = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($client)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('No hay notificaciones recientes.');

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('No hay notificaciones recientes.');
    }

    public function test_notifications_breadcrumb_keeps_dashboard_clickable_and_current_page_not_linked(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee(route('dashboard'), false)
            ->assertSee('aria-current="page"', false)
            ->assertSee('>Notificaciones<', false)
            ->assertDontSee('href="'.route('notifications.index').'">Notificaciones', false);
    }

    public function test_notification_can_be_marked_as_read_from_the_list(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);
        $notificationId = (string) Str::uuid();

        DB::table('notifications')->insert([
            'id' => $notificationId,
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode([
                'title' => 'Aviso puntual',
                'body' => 'Pendiente de lectura.',
            ], JSON_THROW_ON_ERROR),
            'created_at' => '2026-07-03 09:00:00',
            'updated_at' => '2026-07-03 09:00:00',
        ]);

        $this->actingAs($user)
            ->patch(route('notifications.read', $notificationId))
            ->assertRedirect();

        $this->assertNotNull(DB::table('notifications')->where('id', $notificationId)->value('read_at'));
    }

    public function test_panel_notificaciones_renderiza_filas_compactas(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->createTypedNotification($user, [
            'type' => 'nueva_solicitud_mercancia',
            'title' => 'Nueva solicitud de mercancia',
            'body' => 'Pedido registrado',
        ]);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('notification-card', false)
            ->assertSee('notification-card-badges', false)
            ->assertSee('notification-card-actions', false);
    }

    public function test_notificacion_booking_muestra_badge_booking(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->createTypedNotification($user, [
            'type' => 'booking_estado_actualizado',
            'title' => 'Tu booking ha cambiado de estado',
            'body' => 'BK-000001 ha pasado a confirmado.',
        ]);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('notification-card--booking', false)
            ->assertSee('notification-kind-badge--booking', false)
            ->assertSee('BOOKING');
    }

    public function test_notificacion_pedido_muestra_badge_pedido(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->createTypedNotification($user, [
            'type' => 'estado_solicitud_mercancia',
            'title' => 'Tu solicitud ha cambiado de estado',
            'body' => 'Pedido en preparacion.',
            'url' => '/solicitudes-mercancia/1',
        ]);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('notification-card--pedido', false)
            ->assertSee('notification-kind-badge--pedido', false)
            ->assertSee('PEDIDO');
    }

    public function test_notificacion_salida_muestra_badge_salida(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->createTypedNotification($user, [
            'type' => 'albaran_salida',
            'title' => 'Albaran de salida disponible',
            'body' => 'Salida expedida.',
            'url' => '/salidas/1',
        ]);

        $this->actingAs($user)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('notification-card--salida', false)
            ->assertSee('notification-kind-badge--salida', false)
            ->assertSee('SALIDA');
    }

    public function test_superadmin_marca_todas_las_notificaciones_de_todos_los_usuarios_como_leidas(): void
    {
        $cliente = $this->makeUserWithRole(Role::CLIENTE);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->createNotifications($cliente, 3);
        $this->createNotifications($almacen, 2);
        $this->createNotifications($superadmin, 1);

        $this->assertSame(6, DB::table('notifications')->whereNull('read_at')->count());

        $this->actingAs($superadmin)
            ->post(route('notifications.read-all'))
            ->assertRedirect()
            ->assertSessionHas('status', 'Se han marcado 6 notificaciones como leidas.');

        // Todas quedan marcadas como leidas (de todos los usuarios).
        $this->assertSame(0, DB::table('notifications')->whereNull('read_at')->count());
        // No se borra ningun registro: solo se marca read_at.
        $this->assertSame(6, DB::table('notifications')->count());
        // El contador de no leidas baja a 0 para los usuarios afectados.
        $this->assertSame(0, $cliente->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $almacen->fresh()->unreadNotifications()->count());
        $this->assertSame(0, $superadmin->fresh()->unreadNotifications()->count());
    }

    public function test_marcar_todas_sin_pendientes_informa_que_no_habia(): void
    {
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($superadmin)
            ->post(route('notifications.read-all'))
            ->assertRedirect()
            ->assertSessionHas('status', 'No habia notificaciones pendientes.');
    }

    public function test_cliente_no_puede_marcar_todas_las_notificaciones_como_leidas(): void
    {
        $cliente = $this->makeUserWithRole(Role::CLIENTE);
        $this->createNotifications($cliente, 2);

        $this->actingAs($cliente)
            ->post(route('notifications.read-all'))
            ->assertForbidden();

        $this->assertSame(2, DB::table('notifications')->whereNull('read_at')->count());
    }

    public function test_almacen_no_puede_marcar_todas_las_notificaciones_como_leidas(): void
    {
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $otro = $this->makeUserWithRole(Role::CLIENTE);
        $this->createNotifications($otro, 2);

        $this->actingAs($almacen)
            ->post(route('notifications.read-all'))
            ->assertForbidden();

        $this->assertSame(2, DB::table('notifications')->whereNull('read_at')->count());
    }

    public function test_administracion_no_puede_marcar_todas_las_notificaciones_como_leidas(): void
    {
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);
        $otro = $this->makeUserWithRole(Role::CLIENTE);
        $this->createNotifications($otro, 2);

        $this->actingAs($administracion)
            ->post(route('notifications.read-all'))
            ->assertForbidden();

        $this->assertSame(2, DB::table('notifications')->whereNull('read_at')->count());
    }

    public function test_boton_marcar_todas_solo_visible_para_superadmin(): void
    {
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $cliente = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($superadmin)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertSee('Marcar todas como leidas')
            ->assertSee(route('notifications.read-all'), false);

        $this->actingAs($cliente)
            ->get(route('notifications.index'))
            ->assertOk()
            ->assertDontSee('Marcar todas como leidas')
            ->assertDontSee(route('notifications.read-all'), false);
    }

    private function createNotifications(User $user, int $count): void
    {
        $rows = [];

        foreach (range(1, $count) as $index) {
            $rows[] = [
                'id' => (string) Str::uuid(),
                'type' => 'test',
                'notifiable_type' => User::class,
                'notifiable_id' => $user->id,
                'data' => json_encode([
                    'title' => sprintf('Aviso %03d', $index),
                    'body' => 'Detalle '.$index,
                ], JSON_THROW_ON_ERROR),
                'created_at' => sprintf('2026-07-03 10:%02d:00', $index),
                'updated_at' => sprintf('2026-07-03 10:%02d:00', $index),
            ];
        }

        DB::table('notifications')->insert($rows);
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function createTypedNotification(User $user, array $data): void
    {
        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $user->id,
            'data' => json_encode($data, JSON_THROW_ON_ERROR),
            'created_at' => '2026-07-03 09:00:00',
            'updated_at' => '2026-07-03 09:00:00',
        ]);
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $this->seed(RoleSeeder::class);

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();
        $clientId = null;

        if ($roleSlug === Role::CLIENTE) {
            $clientId = Client::factory()->create()->id;
        }

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $clientId,
        ]);
    }
}
