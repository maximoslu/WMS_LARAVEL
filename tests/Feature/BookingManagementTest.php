<?php

namespace Tests\Feature;

use App\Jobs\ProcessBookingStatusChangedJob;
use App\Jobs\ProcessBookingSubmittedNotificationsJob;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerBookingStatusChangedNotification;
use App\Notifications\InternalBookingSubmittedNotification;
use App\Services\Bookings\BookingNotificationService;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class BookingManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_can_create_own_booking(): void
    {
        Bus::fake();
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->post(route('bookings.store'), [
                'type' => Booking::TYPE_ENTRY,
                'scheduled_date' => '2026-07-02',
                'carrier_name' => 'Proveedor Norte',
                'notes' => 'Pendiente de validacion.',
            ])
            ->assertRedirect();

        $booking = Booking::query()->firstOrFail();

        $this->assertSame($client->id, $booking->client_id);
        $this->assertSame($cliente->id, $booking->requested_by);
        $this->assertSame(Booking::STATUS_REQUESTED, $booking->status);
        $this->assertSame('Proveedor Norte', $booking->carrier_name);
        $this->assertSame('BK-000001', $booking->referenceCode());
        Bus::assertDispatchedAfterResponse(
            ProcessBookingSubmittedNotificationsJob::class,
            fn (ProcessBookingSubmittedNotificationsJob $job): bool => $job->bookingId === $booking->id
        );
    }

    public function test_cliente_sees_simplified_booking_form(): void
    {
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->get(route('bookings.create'))
            ->assertOk()
            ->assertSee('Tipo')
            ->assertSee('Fecha solicitada')
            ->assertSee('Transportista o referencia de llegada')
            ->assertSee('Observaciones');
    }

    public function test_cliente_does_not_see_internal_booking_fields_in_form(): void
    {
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->get(route('bookings.create'))
            ->assertOk()
            ->assertDontSee('Hora desde')
            ->assertDontSee('Hora hasta')
            ->assertDontSee('Muelle')
            ->assertDontSee('Referencia documental')
            ->assertDontSee('Notas internas');
    }

    public function test_internal_user_can_edit_operational_booking_fields(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
        ]);

        $this->actingAs($almacen)
            ->get(route('bookings.edit', $booking))
            ->assertOk()
            ->assertSee('Hora desde')
            ->assertSee('Hora hasta')
            ->assertSee('Muelle')
            ->assertSee('Referencia documental')
            ->assertSee('Notas internas');
    }

    public function test_cliente_sees_only_own_bookings(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $otherCliente = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $ownBooking = Booking::factory()->create([
            'client_id' => $friesland->id,
            'requested_by' => $cliente->id,
            'booking_code' => 'BK-100001',
        ]);
        $foreignBooking = Booking::factory()->create([
            'client_id' => $edelvives->id,
            'requested_by' => $otherCliente->id,
            'booking_code' => 'BK-100002',
        ]);

        $this->actingAs($cliente)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee($ownBooking->referenceCode())
            ->assertDontSee($foreignBooking->referenceCode());
    }

    public function test_cliente_cannot_view_booking_from_another_client(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $otherCliente = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $foreignBooking = Booking::factory()->create([
            'client_id' => $edelvives->id,
            'requested_by' => $otherCliente->id,
        ]);

        $this->actingAs($cliente)
            ->get(route('bookings.show', $foreignBooking))
            ->assertForbidden();
    }

    public function test_almacen_can_view_client_bookings(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'carrier_name' => 'Transmax',
        ]);

        $this->actingAs($almacen)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee($booking->referenceCode())
            ->assertSee('Transmax');
    }

    public function test_administracion_can_approve_and_reject_bookings(): void
    {
        Bus::fake();
        [$client] = $this->seedBaseData();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'status' => Booking::STATUS_REQUESTED,
        ]);

        $this->actingAs($administracion)
            ->patch(route('bookings.update-status', $booking), [
                'status' => Booking::STATUS_APPROVED,
            ])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_APPROVED, $booking->status);
        $this->assertSame($administracion->id, $booking->approved_by);

        $this->actingAs($administracion)
            ->patch(route('bookings.update-status', $booking), [
                'status' => Booking::STATUS_REJECTED,
            ])
            ->assertRedirect();

        $this->assertSame(Booking::STATUS_REJECTED, $booking->fresh()->status);
        Bus::assertDispatchedAfterResponse(ProcessBookingStatusChangedJob::class);
    }

    public function test_superadmin_can_manage_everything(): void
    {
        [$client] = $this->seedBaseData();
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'status' => Booking::STATUS_APPROVED,
        ]);

        $this->actingAs($superadmin)
            ->put(route('bookings.update', $booking), [
                'client_id' => $client->id,
                'type' => Booking::TYPE_EXIT,
                'scheduled_date' => '2026-07-03',
                'scheduled_time_from' => '11:00',
                'scheduled_time_to' => '12:00',
                'pallets_expected' => 20,
                'carrier_name' => 'Carrier X',
                'vehicle_plate' => '9999ZZZ',
                'driver_name' => 'Pablo',
                'contact_name' => 'Ines',
                'contact_phone' => '600000002',
                'notes' => 'Actualizado',
                'internal_notes' => 'Plan interno',
            ])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(Booking::TYPE_EXIT, $booking->type);
        $this->assertSame(20, $booking->pallets_expected);
        $this->assertSame('Plan interno', $booking->internal_notes);
    }

    public function test_booking_created_stays_in_requested_status(): void
    {
        [$client] = $this->seedBaseData();
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'status' => Booking::STATUS_REQUESTED,
        ]);

        $this->assertSame(Booking::STATUS_REQUESTED, $booking->status);
        $this->assertSame('Solicitado', $booking->statusLabel());
    }

    public function test_status_change_updates_booking_correctly(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'status' => Booking::STATUS_APPROVED,
        ]);

        $this->actingAs($almacen)
            ->patch(route('bookings.update-status', $booking), [
                'status' => Booking::STATUS_PLANNED,
                'internal_notes' => 'Muelle reservado',
            ])
            ->assertRedirect();

        $booking->refresh();
        $this->assertSame(Booking::STATUS_PLANNED, $booking->status);
        $this->assertSame('Muelle reservado', $booking->internal_notes);
    }

    public function test_dashboard_shows_booking_calendar_block(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        Booking::factory()->create([
            'client_id' => $client->id,
            'scheduled_date' => now()->addDay()->toDateString(),
        ]);

        $this->actingAs($almacen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Agenda operativa WMS')
            ->assertSee('dashboard-booking-calendar-grid', false);
    }

    public function test_dashboard_renders_booking_day_names_in_spanish(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        Booking::factory()->create([
            'client_id' => $client->id,
            'scheduled_date' => now()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->toDateString(),
        ]);

        $this->actingAs($almacen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Lunes');
    }

    public function test_dashboard_shows_bookings_inside_operational_agenda(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $calendarDate = now()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->addDay()->toDateString();
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'booking_code' => 'BK-200001',
            'scheduled_date' => $calendarDate,
        ]);

        $this->actingAs($almacen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Agenda operativa WMS')
            ->assertSee($booking->referenceCode());
    }

    public function test_cliente_only_sees_own_bookings_on_dashboard(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $calendarDate = now()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->addDay()->toDateString();

        $ownBooking = Booking::factory()->create([
            'client_id' => $friesland->id,
            'booking_code' => 'BK-210001',
            'scheduled_date' => $calendarDate,
        ]);
        $foreignBooking = Booking::factory()->create([
            'client_id' => $edelvives->id,
            'booking_code' => 'BK-210002',
            'scheduled_date' => $calendarDate,
        ]);

        $this->actingAs($cliente)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee($ownBooking->referenceCode())
            ->assertDontSee($foreignBooking->referenceCode());
    }

    public function test_almacen_sees_bookings_from_multiple_clients_on_dashboard(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $calendarDate = now()->startOfWeek(\Illuminate\Support\Carbon::MONDAY)->addDay()->toDateString();

        Booking::factory()->create([
            'client_id' => $friesland->id,
            'booking_code' => 'BK-220001',
            'scheduled_date' => $calendarDate,
        ]);
        Booking::factory()->create([
            'client_id' => $edelvives->id,
            'booking_code' => 'BK-220002',
            'scheduled_date' => $calendarDate,
        ]);

        $this->actingAs($almacen)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('BK-220001')
            ->assertSee('BK-220002');
    }

    public function test_dashboard_shows_empty_state_when_there_are_no_bookings(): void
    {
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Sin actividad');
    }

    public function test_calendar_shows_bookings_grouped_by_date(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        Booking::factory()->create([
            'client_id' => $client->id,
            'booking_code' => 'BK-300001',
            'scheduled_date' => '2026-07-05',
        ]);
        Booking::factory()->create([
            'client_id' => $client->id,
            'booking_code' => 'BK-300002',
            'scheduled_date' => '2026-07-05',
        ]);

        $this->actingAs($almacen)
            ->get(route('bookings.calendar', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-10',
            ]))
            ->assertOk()
            ->assertSee('05/07/2026')
            ->assertSee('BK-300001')
            ->assertSee('BK-300002');
    }

    public function test_calendar_uses_visual_status_classes(): void
    {
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        Booking::factory()->create([
            'client_id' => $client->id,
            'status' => Booking::STATUS_REQUESTED,
            'scheduled_date' => '2026-07-05',
        ]);

        $this->actingAs($almacen)
            ->get(route('bookings.calendar', [
                'date_from' => '2026-07-01',
                'date_to' => '2026-07-10',
            ]))
            ->assertOk()
            ->assertSee('dashboard-booking-chip--solicitado', false);
    }

    public function test_google_calendar_status_is_rendered_for_administracion_in_calendar(): void
    {
        [$client] = $this->seedBaseData();
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);
        Booking::factory()->create([
            'client_id' => $client->id,
            'scheduled_date' => now()->addDay()->toDateString(),
        ]);

        $this->actingAs($administracion)
            ->get(route('bookings.calendar'))
            ->assertOk()
            ->assertSee('Agenda Google')
            ->assertSee('Desactivada');
    }

    public function test_google_calendar_status_is_hidden_for_cliente(): void
    {
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        Booking::factory()->create([
            'client_id' => $client->id,
            'scheduled_date' => now()->addDay()->toDateString(),
        ]);

        $this->actingAs($cliente)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Conectar Google Calendar')
            ->assertDontSee('Agenda Google');
    }

    public function test_cliente_sees_nueva_solicitud_label_instead_of_solicitar_booking(): void
    {
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->get(route('bookings.index'))
            ->assertOk()
            ->assertSee('Nueva solicitud')
            ->assertDontSee('Solicitar booking');
    }

    public function test_dashboard_and_booking_detail_do_not_show_visible_todos(): void
    {
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
        ]);

        $this->actingAs($cliente)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('TODO');

        $this->actingAs($cliente)
            ->get(route('bookings.show', $booking))
            ->assertOk()
            ->assertDontSee('TODO');
    }

    public function test_internal_database_notification_is_created_for_superadmin_administracion_and_almacen(): void
    {
        Notification::fake();
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
        ]);

        app(BookingNotificationService::class)->deliverSubmittedNotifications($booking);

        Notification::assertSentTo($almacen, InternalBookingSubmittedNotification::class, fn ($notification, array $channels) => $channels === ['database']);
        Notification::assertSentTo($administracion, InternalBookingSubmittedNotification::class, fn ($notification, array $channels) => $channels === ['database']);
        Notification::assertSentTo($superadmin, InternalBookingSubmittedNotification::class, fn ($notification, array $channels) => $channels === ['database']);
    }

    public function test_booking_creation_does_not_email_internal_users(): void
    {
        Notification::fake();
        [$client] = $this->seedBaseData();
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
        ]);

        app(BookingNotificationService::class)->deliverSubmittedNotifications($booking);

        Notification::assertNotSentTo($almacen, InternalBookingSubmittedNotification::class, fn ($notification, array $channels) => $channels === ['mail']);
        Notification::assertSentTo($almacen, InternalBookingSubmittedNotification::class, fn ($notification, array $channels) => $channels === ['database']);
    }

    public function test_internal_booking_notification_stays_database_only_even_with_invalid_email(): void
    {
        Notification::fake();
        [$client] = $this->seedBaseData();
        $first = $this->makeUserWithRole(Role::ALMACEN);
        $second = $this->makeUserWithRole(Role::ADMINISTRACION);
        $second->update(['email' => 'email-invalido']);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
        ]);

        app(BookingNotificationService::class)->deliverSubmittedNotifications($booking);

        Notification::assertNotSentTo($first, InternalBookingSubmittedNotification::class, fn ($notification, array $channels) => $channels === ['mail']);
        Notification::assertNotSentTo($second, InternalBookingSubmittedNotification::class, fn ($notification, array $channels) => $channels === ['mail']);
        Notification::assertSentTo($first, InternalBookingSubmittedNotification::class, fn ($notification, array $channels) => $channels === ['database']);
        Notification::assertSentTo($second, InternalBookingSubmittedNotification::class, fn ($notification, array $channels) => $channels === ['database']);
    }

    public function test_cliente_receives_database_notification_only_when_booking_status_changes(): void
    {
        Notification::fake();
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => Booking::STATUS_APPROVED,
        ]);

        app(BookingNotificationService::class)->deliverStatusChangedNotifications($booking, Booking::STATUS_REQUESTED);

        Notification::assertSentTo($cliente, CustomerBookingStatusChangedNotification::class, fn ($notification, array $channels) => $channels === ['database']);
        Notification::assertNotSentTo($cliente, CustomerBookingStatusChangedNotification::class, fn ($notification, array $channels) => $channels === ['mail']);
    }

    public function test_booking_routes_have_expected_permissions(): void
    {
        [$client] = $this->seedBaseData();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $booking = Booking::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
        ]);

        $this->actingAs($cliente)
            ->patch(route('bookings.update-status', $booking), [
                'status' => Booking::STATUS_APPROVED,
            ])
            ->assertForbidden();

        $this->actingAs($almacen)
            ->get(route('bookings.calendar'))
            ->assertOk();
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
