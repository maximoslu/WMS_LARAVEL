<?php

namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class GoogleCalendarDashboardTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_loads_though_google_calendar_is_disabled(): void
    {
        config()->set('google-calendar.enabled', false);
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        Booking::factory()->create([
            'client_id' => $client->id,
            'scheduled_date' => now()->addDay()->toDateString(),
        ]);

        $this->actingAs($almacen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Calendario de bookings');
    }

    public function test_dashboard_loads_though_google_service_fails_in_a_controlled_way(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        Booking::factory()->create([
            'client_id' => $client->id,
            'scheduled_date' => now()->addDay()->toDateString(),
        ]);

        $service = Mockery::mock(GoogleCalendarService::class);
        $service->shouldReceive('getEventsBetween')
            ->once()
            ->andThrow(new RuntimeException('google failure'));
        $this->app->instance(GoogleCalendarService::class, $service);

        $this->actingAs($almacen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Calendario de bookings');
    }

    public function test_dashboard_can_render_mocked_google_events(): void
    {
        [$client] = $this->seedBaseData();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $service = Mockery::mock(GoogleCalendarService::class);
        $service->shouldReceive('getConnectionStatus')
            ->once()
            ->andReturn([
                'state' => 'connected',
                'label' => 'Conectado',
                'message' => 'Google Calendar esta listo para mostrar eventos en modo solo lectura.',
            ]);
        $service->shouldReceive('getEventsBetween')
            ->once()
            ->andReturn(collect([
                [
                    'source' => 'google',
                    'id' => 'evt-1',
                    'title' => 'Muelle reservado externo',
                    'starts_at' => now()->startOfWeek(Carbon::MONDAY)->addDay()->setTime(9, 0),
                    'ends_at' => now()->startOfWeek(Carbon::MONDAY)->addDay()->setTime(10, 0),
                    'all_day' => false,
                    'location' => 'Dock 3',
                    'description' => 'Prueba',
                ],
            ]));
        $this->app->instance(GoogleCalendarService::class, $service);

        $this->actingAs($administracion)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Conectado')
            ->assertSee('Google')
            ->assertSee('Muelle reservado externo')
            ->assertSee('Dock 3');
    }

    private function seedBaseData(): array
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);

        return [
            Client::query()->where('code', 'FRIESLAND')->firstOrFail(),
            Client::query()->where('code', 'EDELVIVES')->firstOrFail(),
        ];
    }

    private function makeUserWithRole(string $roleSlug, ?Client $client = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $roleSlug === Role::CLIENTE ? $client?->id : null,
        ]);
    }
}
