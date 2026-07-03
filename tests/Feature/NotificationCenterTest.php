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

    public function test_dashboard_limits_recent_notifications_and_keeps_unread_counter(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->createNotifications($user, 7);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Aviso 007')
            ->assertSee('Aviso 006')
            ->assertSee('Aviso 005')
            ->assertSee('Aviso 004')
            ->assertSee('Aviso 003')
            ->assertDontSee('Aviso 002')
            ->assertDontSee('Aviso 001')
            ->assertSee('Ver todas')
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

    public function test_notifications_empty_state_is_clear_on_dashboard_and_index(): void
    {
        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('dashboard'))
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
