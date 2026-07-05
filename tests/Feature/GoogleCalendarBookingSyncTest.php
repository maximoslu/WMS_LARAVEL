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
use Illuminate\Support\Collection;
use Tests\TestCase;

class GoogleCalendarBookingSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_crear_booking_crea_evento_google_calendar(): void
    {
        $service = new FakeGoogleCalendarService();
        $this->app->instance(GoogleCalendarService::class, $service);
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('bookings.store'), [
                'type' => Booking::TYPE_ENTRY,
                'scheduled_date' => '2026-07-05',
                'carrier_name' => 'Proveedor Norte',
            ])
            ->assertRedirect();

        $booking = Booking::query()->firstOrFail();

        $this->assertCount(1, $service->syncCalls);
        $this->assertSame($booking->id, $service->syncCalls[0]['booking_id']);
        $this->assertSame('evt-'.$booking->id, $booking->fresh()->google_calendar_event_id);
    }

    public function test_crear_booking_guarda_google_event_id(): void
    {
        $service = new FakeGoogleCalendarService();
        $this->app->instance(GoogleCalendarService::class, $service);
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('bookings.store'), [
                'type' => Booking::TYPE_EXIT,
                'scheduled_date' => '2026-07-06',
                'carrier_name' => 'Carrier X',
            ])
            ->assertRedirect();

        $booking = Booking::query()->firstOrFail()->fresh();

        $this->assertNotNull($booking->google_calendar_synced_at);
        $this->assertSame('evt-'.$booking->id, $booking->google_calendar_event_id);
        $this->assertNull($booking->google_calendar_sync_error);
    }

    public function test_crear_booking_no_falla_si_google_calendar_falla(): void
    {
        $service = new FakeGoogleCalendarService();
        $service->shouldFail = true;
        $this->app->instance(GoogleCalendarService::class, $service);
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('bookings.store'), [
                'type' => Booking::TYPE_ENTRY,
                'scheduled_date' => '2026-07-05',
                'carrier_name' => 'Proveedor Norte',
            ])
            ->assertRedirect()
            ->assertSessionHas('warning', 'Booking registrado en WMS, pero no se pudo sincronizar con Google Calendar. Revisalo desde administracion.');

        $booking = Booking::query()->firstOrFail();

        $this->assertSame(Booking::STATUS_REQUESTED, $booking->status);
        $this->assertNotNull($booking->google_calendar_sync_error);
    }

    public function test_editar_booking_actualiza_evento_google_calendar(): void
    {
        $service = new FakeGoogleCalendarService();
        $this->app->instance(GoogleCalendarService::class, $service);
        [$client] = $this->seedBaseData();
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'google_calendar_event_id' => 'evt-existing',
            'google_calendar_synced_at' => now()->subHour(),
        ]);

        $this->actingAs($superadmin)
            ->put(route('bookings.update', $booking), [
                'client_id' => $client->id,
                'type' => Booking::TYPE_MIXED,
                'scheduled_date' => '2026-07-07',
                'scheduled_time_from' => '11:00',
                'scheduled_time_to' => '12:00',
                'carrier_name' => 'Carrier actualizado',
            ])
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame('evt-existing', $booking->google_calendar_event_id);
        $this->assertNotNull($booking->google_calendar_synced_at);
        $this->assertSame('evt-existing', $service->syncCalls[0]['event_id']);
    }

    public function test_cancelar_booking_cancela_o_elimina_evento_google_calendar(): void
    {
        $service = new FakeGoogleCalendarService();
        $this->app->instance(GoogleCalendarService::class, $service);
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => Booking::STATUS_REQUESTED,
            'google_calendar_event_id' => 'evt-cancel',
            'google_calendar_synced_at' => now()->subHour(),
        ]);

        $this->actingAs($cliente)
            ->delete(route('bookings.destroy', $booking))
            ->assertRedirect()
            ->assertSessionHasNoErrors();

        $booking->refresh();

        $this->assertSame(Booking::STATUS_CANCELLED, $booking->status);
        $this->assertNull($booking->google_calendar_event_id);
        $this->assertNull($booking->google_calendar_sync_error);
    }

    public function test_reintentar_sincronizacion_crea_evento_si_no_existe(): void
    {
        $service = new FakeGoogleCalendarService();
        $this->app->instance(GoogleCalendarService::class, $service);
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'google_calendar_event_id' => null,
            'google_calendar_synced_at' => null,
            'google_calendar_sync_error' => 'Token expirado',
        ]);

        $this->actingAs($almacen)
            ->patch(route('bookings.google-calendar.retry', $booking))
            ->assertRedirect()
            ->assertSessionHas('status', 'Sincronizacion con Google Calendar reintentada correctamente.');

        $booking->refresh();

        $this->assertSame('evt-'.$booking->id, $booking->google_calendar_event_id);
        $this->assertNull($booking->google_calendar_sync_error);
    }

    public function test_no_duplica_evento_si_booking_ya_tiene_google_calendar_event_id(): void
    {
        $service = new FakeGoogleCalendarService();
        $this->app->instance(GoogleCalendarService::class, $service);
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'google_calendar_event_id' => 'evt-stable',
            'google_calendar_synced_at' => now()->subHour(),
            'google_calendar_sync_error' => 'Error previo',
        ]);

        $this->actingAs($almacen)
            ->patch(route('bookings.google-calendar.retry', $booking))
            ->assertRedirect();

        $booking->refresh();

        $this->assertSame('evt-stable', $booking->google_calendar_event_id);
        $this->assertNull($booking->google_calendar_sync_error);
        $this->assertSame('evt-stable', $service->syncCalls[0]['event_id']);
    }

    public function test_cliente_puede_crear_booking_y_queda_pendiente_si_google_falla(): void
    {
        $service = new FakeGoogleCalendarService();
        $service->shouldFail = true;
        $this->app->instance(GoogleCalendarService::class, $service);
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('bookings.store'), [
                'type' => Booking::TYPE_OTHER,
                'scheduled_date' => '2026-07-08',
                'carrier_name' => 'Carrier pendiente',
            ])
            ->assertRedirect();

        $booking = Booking::query()->firstOrFail()->fresh();

        $this->assertSame(Booking::STATUS_REQUESTED, $booking->status);
        $this->assertNull($booking->google_calendar_event_id);
        $this->assertNotNull($booking->google_calendar_sync_error);
    }

    public function test_listado_muestra_estado_sincronizacion_google_para_internos(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        Booking::factory()->create([
            'client_id' => $client->id,
            'google_calendar_event_id' => 'evt-ok',
            'google_calendar_synced_at' => now(),
            'google_calendar_sync_error' => null,
        ]);
        Booking::factory()->create([
            'client_id' => $client->id,
            'google_calendar_event_id' => null,
            'google_calendar_synced_at' => null,
            'google_calendar_sync_error' => 'Permiso insuficiente',
        ]);

        $this->actingAs($almacen)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('Google Calendar')
            ->assertSee('Sincronizado con Google Calendar')
            ->assertSee('Error de sincronizacion con Google Calendar');
    }

    public function test_dashboard_sigue_mostrando_eventos_google_y_bookings_wms(): void
    {
        $service = new FakeGoogleCalendarService();
        $service->events = collect([
            [
                'source' => 'google',
                'id' => 'evt-externo',
                'title' => 'Reserva externa de muelle',
                'starts_at' => now()->startOfWeek(Carbon::MONDAY)->addDay()->setTime(9, 0),
                'ends_at' => now()->startOfWeek(Carbon::MONDAY)->addDay()->setTime(10, 0),
                'all_day' => false,
                'location' => 'Dock 3',
                'description' => 'Prueba',
            ],
        ]);
        $this->app->instance(GoogleCalendarService::class, $service);
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'booking_code' => 'BK-900001',
            'scheduled_date' => now()->startOfWeek(Carbon::MONDAY)->addDay()->toDateString(),
        ]);

        $this->actingAs($almacen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($booking->referenceCode())
            ->assertSee('Reserva externa de muelle')
            ->assertSee('Dock 3')
            ->assertSee('Google');
    }

    /**
     * @return array{0: Client, 1: Client}
     */
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

class FakeGoogleCalendarService extends GoogleCalendarService
{
    public bool $shouldFail = false;

    /** @var list<array{booking_id:int,status:string,event_id:?string}> */
    public array $syncCalls = [];

    public Collection $events;

    public function __construct()
    {
        $this->events = collect();
    }

    public function syncBookingEvent(Booking $booking): array
    {
        $this->syncCalls[] = [
            'booking_id' => $booking->id,
            'status' => $booking->status,
            'event_id' => $booking->google_calendar_event_id,
        ];

        if ($booking->status === Booking::STATUS_CANCELLED) {
            $booking->forceFill([
                'google_calendar_event_id' => null,
                'google_calendar_synced_at' => now(),
                'google_calendar_sync_error' => null,
            ])->saveQuietly();

            return [
                'success' => true,
                'action' => 'deleted',
                'event_id' => null,
                'warning' => null,
            ];
        }

        if ($this->shouldFail) {
            $booking->forceFill([
                'google_calendar_sync_error' => 'Fake sync failure',
            ])->saveQuietly();

            return [
                'success' => false,
                'action' => 'failed',
                'event_id' => $booking->google_calendar_event_id,
                'warning' => 'Booking registrado en WMS, pero no se pudo sincronizar con Google Calendar. Revisalo desde administracion.',
            ];
        }

        $eventId = $booking->google_calendar_event_id ?: 'evt-'.$booking->id;
        $action = $booking->google_calendar_event_id ? 'updated' : 'created';

        $booking->forceFill([
            'google_calendar_event_id' => $eventId,
            'google_calendar_synced_at' => now(),
            'google_calendar_sync_error' => null,
        ])->saveQuietly();

        return [
            'success' => true,
            'action' => $action,
            'event_id' => $eventId,
            'warning' => null,
        ];
    }

    public function getConnectionStatus(): array
    {
        return [
            'state' => 'connected',
            'label' => 'Conectado',
            'message' => 'Google Calendar listo para sincronizar bookings.',
        ];
    }

    public function getEventsBetween(Carbon $startsAt, Carbon $endsAt): Collection
    {
        return $this->events;
    }
}
