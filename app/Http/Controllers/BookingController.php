<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreBookingRequest;
use App\Http\Requests\UpdateBookingRequest;
use App\Http\Requests\UpdateBookingStatusRequest;
use App\Models\Booking;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Bookings\BookingNotificationService;
use App\Services\GoogleCalendarService;
use App\Services\Warehouses\WarehouseIntegrityService;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;
use Illuminate\View\View;

class BookingController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->canAccessBookings($user), 403);

        $isClient = $user->hasRole(Role::CLIENTE);
        $filters = $this->filtersFromRequest($request, $isClient);

        $bookings = $this->filteredQuery($user, $filters)
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time_from')
            ->orderByDesc('id')
            ->paginate(12)
            ->withQueryString();

        return view('bookings.index', [
            'bookings' => $bookings,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'filters' => $filters,
            'isClient' => $isClient,
            'canCreate' => $this->canCreateBooking($user),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
            'statusOptions' => Booking::statusOptions(),
            'typeOptions' => Booking::typeOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->canCreateBooking($user), 403);

        return view('bookings.create', $this->formViewData($user, null));
    }

    public function store(
        StoreBookingRequest $request,
        BookingNotificationService $notificationService,
        GoogleCalendarService $googleCalendarService,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($this->canCreateBooking($user), 403);

        $validated = $request->validated();
        $clientId = $user->hasRole(Role::CLIENTE)
            ? (int) $user->client_id
            : (int) ($validated['client_id'] ?? 0);

        abort_if($clientId <= 0, 422, 'Debes seleccionar un cliente valido para el booking.');

        $payload = [
            'client_id' => $clientId,
            'requested_by' => $user->id,
            'status' => Booking::STATUS_REQUESTED,
            'type' => $validated['type'],
            'scheduled_date' => $validated['scheduled_date'],
            'scheduled_time_from' => $validated['scheduled_time_from'] ?? null,
            'scheduled_time_to' => $validated['scheduled_time_to'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'carrier_name' => $validated['carrier_name'] ?? null,
            'vehicle_plate' => $validated['vehicle_plate'] ?? null,
            'driver_name' => $validated['driver_name'] ?? null,
            'pallets_expected' => $validated['pallets_expected'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'origin_destination' => $validated['origin_destination'] ?? null,
            'document_reference' => $validated['document_reference'] ?? null,
            'loading_dock' => $validated['loading_dock'] ?? null,
        ];

        if ($this->isInternalUser($user)) {
            $payload['internal_notes'] = $validated['internal_notes'] ?? null;
            $payload['assigned_to'] = $validated['assigned_to'] ?? null;
            $payload['warehouse_id'] = $validated['warehouse_id'] ?? null;
        }

        $booking = Booking::query()->create($payload);

        $notificationService->notifySubmitted($booking);
        $syncResult = $googleCalendarService->syncBookingEvent($booking->fresh());

        return $this->redirectWithSyncFeedback(
            redirect()->route('bookings.show', $booking),
            'Solicitud registrada correctamente. Queda pendiente de validacion operativa.',
            $syncResult
        );
    }

    public function show(Request $request, Booking $booking): View
    {
        $user = $request->user();
        abort_unless($this->canViewBooking($user, $booking), 403);

        $booking->load(['client', 'requestedBy', 'assignedTo', 'approvedBy', 'cancelledBy', 'warehouse']);

        return view('bookings.show', [
            'booking' => $booking,
            'isClient' => $user->hasRole(Role::CLIENTE),
            'canEdit' => $this->canEditBooking($user, $booking),
            'availableStatuses' => $this->allowedStatusesFor($user, $booking),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function edit(Request $request, Booking $booking): View
    {
        $user = $request->user();
        abort_unless($this->canEditBooking($user, $booking), 403);

        return view('bookings.edit', $this->formViewData($user, $booking));
    }

    public function update(
        UpdateBookingRequest $request,
        Booking $booking,
        GoogleCalendarService $googleCalendarService,
    ): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->canEditBooking($user, $booking), 403);

        $validated = $request->validated();
        $payload = [
            'type' => $validated['type'],
            'scheduled_date' => $validated['scheduled_date'],
            'scheduled_time_from' => $validated['scheduled_time_from'] ?? null,
            'scheduled_time_to' => $validated['scheduled_time_to'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'contact_phone' => $validated['contact_phone'] ?? null,
            'carrier_name' => $validated['carrier_name'] ?? null,
            'vehicle_plate' => $validated['vehicle_plate'] ?? null,
            'driver_name' => $validated['driver_name'] ?? null,
            'pallets_expected' => $validated['pallets_expected'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'origin_destination' => $validated['origin_destination'] ?? null,
            'document_reference' => $validated['document_reference'] ?? null,
            'loading_dock' => $validated['loading_dock'] ?? null,
        ];

        if ($this->isInternalUser($user)) {
            $payload['client_id'] = (int) ($validated['client_id'] ?? $booking->client_id);
            $payload['internal_notes'] = $validated['internal_notes'] ?? null;
            $payload['assigned_to'] = $validated['assigned_to'] ?? null;
            $payload['warehouse_id'] = $validated['warehouse_id'] ?? null;
        }

        $booking->update($payload);
        $syncResult = $googleCalendarService->syncBookingEvent($booking->fresh());

        return $this->redirectWithSyncFeedback(
            redirect()->route('bookings.show', $booking),
            'Booking actualizado correctamente.',
            $syncResult
        );
    }

    public function updateStatus(
        UpdateBookingStatusRequest $request,
        Booking $booking,
        BookingNotificationService $notificationService,
        GoogleCalendarService $googleCalendarService,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($this->canViewBooking($user, $booking), 403);

        $validated = $request->validated();
        $newStatus = $validated['status'];
        $previousStatus = $booking->status;

        abort_if(! in_array($newStatus, $this->allowedStatusesFor($user, $booking), true), 403);

        if ($previousStatus === $newStatus) {
            if ($this->canWriteInternalNotes($user) && array_key_exists('internal_notes', $validated)) {
                $booking->update(['internal_notes' => $validated['internal_notes']]);
            }

            return back()->with('status', 'El booking ya estaba en ese estado.');
        }

        $payload = [
            'status' => $newStatus,
        ];

        if ($this->canWriteInternalNotes($user) && array_key_exists('internal_notes', $validated)) {
            $payload['internal_notes'] = $validated['internal_notes'];
        }

        if ($newStatus === Booking::STATUS_APPROVED) {
            $payload['approved_by'] = $user->id;
            $payload['approved_at'] = $booking->approved_at ?? now();
        }

        if ($newStatus === Booking::STATUS_CANCELLED) {
            $payload['cancelled_by'] = $user->id;
            $payload['cancelled_at'] = $booking->cancelled_at ?? now();
        }

        if ($newStatus !== Booking::STATUS_CANCELLED) {
            $payload['cancelled_by'] = null;
            $payload['cancelled_at'] = null;
        }

        $booking->update($payload);
        $notificationService->notifyStatusChanged($booking->fresh(), $previousStatus);
        $syncResult = $googleCalendarService->syncBookingEvent($booking->fresh());

        return $this->redirectWithSyncFeedback(
            redirect()->route('bookings.show', $booking),
            'Estado del booking actualizado correctamente.',
            $syncResult
        );
    }

    public function calendar(Request $request, GoogleCalendarService $googleCalendarService): View
    {
        $user = $request->user();
        abort_unless($this->canAccessBookings($user), 403);

        $isClient = $user->hasRole(Role::CLIENTE);
        $filters = $this->filtersFromRequest($request, $isClient);
        $filters['date_from'] ??= now()->toDateString();
        $filters['date_to'] ??= now()->addDays(30)->toDateString();

        $bookings = $this->filteredQuery($user, $filters)
            ->whereBetween('scheduled_date', [$filters['date_from'], $filters['date_to']])
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time_from')
            ->get();
        $showGoogleCalendarLayer = $this->isInternalUser($user);
        $showGoogleCalendarControls = $user->canAccessRole(Role::ADMINISTRACION);
        $googleCalendarStatus = $showGoogleCalendarControls
            ? $googleCalendarService->getConnectionStatus()
            : null;
        $googleCalendarEvents = collect();

        if ($showGoogleCalendarLayer) {
            try {
                $googleCalendarEvents = $googleCalendarService->getEventsBetween(
                    Carbon::parse($filters['date_from']),
                    Carbon::parse($filters['date_to'])
                );
            } catch (Throwable $exception) {
                Log::warning('La agenda de bookings ignora un fallo controlado de Google Calendar.', [
                    'channel' => 'google_calendar',
                    'exception' => $exception::class,
                ]);
            }
        }

        $calendarDays = collect();
        $startDate = Carbon::parse($filters['date_from'])->startOfDay();
        $endDate = Carbon::parse($filters['date_to'])->startOfDay();

        for ($date = $startDate->copy(); $date->lte($endDate); $date->addDay()) {
            $calendarDays->push([
                'date' => $date->copy(),
                'bookings' => $bookings
                    ->filter(fn (Booking $booking) => $booking->scheduled_date?->isSameDay($date))
                    ->values(),
                'google_events' => $googleCalendarEvents
                    ->filter(fn (array $event) => $event['starts_at']->isSameDay($date))
                    ->values(),
            ]);
        }

        return view('bookings.calendar', [
            'calendarDays' => $calendarDays,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'filters' => $filters,
            'isClient' => $isClient,
            'navigationSections' => WmsNavigation::sectionsForUser($user),
            'statusOptions' => Booking::statusOptions(),
            'typeOptions' => Booking::typeOptions(),
            'showGoogleCalendarControls' => $showGoogleCalendarControls,
            'showGoogleCalendarLayer' => $showGoogleCalendarLayer,
            'googleCalendarStatus' => $googleCalendarStatus,
        ]);
    }

    public function destroy(
        Request $request,
        Booking $booking,
        BookingNotificationService $notificationService,
        GoogleCalendarService $googleCalendarService,
    ): RedirectResponse
    {
        $user = $request->user();
        abort_unless($this->canViewBooking($user, $booking), 403);

        abort_if(! in_array(Booking::STATUS_CANCELLED, $this->allowedStatusesFor($user, $booking), true), 403);

        $previousStatus = $booking->status;

        $booking->update([
            'status' => Booking::STATUS_CANCELLED,
            'cancelled_by' => $user->id,
            'cancelled_at' => $booking->cancelled_at ?? now(),
        ]);

        if ($previousStatus !== Booking::STATUS_CANCELLED) {
            $notificationService->notifyStatusChanged($booking->fresh(), $previousStatus);
        }

        $syncResult = $googleCalendarService->syncBookingEvent($booking->fresh());

        return $this->redirectWithSyncFeedback(
            redirect()->route('bookings.index'),
            'Booking cancelado correctamente.',
            $syncResult
        );
    }

    public function retryGoogleCalendarSync(
        Request $request,
        Booking $booking,
        GoogleCalendarService $googleCalendarService,
    ): RedirectResponse {
        $user = $request->user();
        abort_unless($this->isInternalUser($user) && $this->canViewBooking($user, $booking), 403);

        $syncResult = $googleCalendarService->syncBookingEvent($booking->fresh());

        return $this->redirectWithSyncFeedback(
            redirect()->route('bookings.show', $booking),
            'Sincronizacion con Google Calendar reintentada correctamente.',
            $syncResult
        );
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function filteredQuery(User $user, array $filters): Builder
    {
        $isClient = $user->hasRole(Role::CLIENTE);

        return Booking::query()
            ->with(['client', 'requestedBy', 'assignedTo', 'approvedBy', 'cancelledBy'])
            ->when($isClient, fn (Builder $query) => $query->where('client_id', $user->client_id))
            ->when(! $isClient && ! empty($filters['client_id']), fn (Builder $query) => $query->where('client_id', $filters['client_id']))
            ->when(! empty($filters['status']) && $filters['status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['status']))
            ->when(! empty($filters['type']) && $filters['type'] !== 'all', fn (Builder $query) => $query->where('type', $filters['type']))
            ->when(! empty($filters['date_from']), fn (Builder $query) => $query->whereDate('scheduled_date', '>=', $filters['date_from']))
            ->when(! empty($filters['date_to']), fn (Builder $query) => $query->whereDate('scheduled_date', '<=', $filters['date_to']))
            ->when(! empty($filters['search']), function (Builder $query) use ($filters): void {
                $search = $filters['search'];

                $query->where(function (Builder $query) use ($search): void {
                    $query
                        ->where('booking_code', 'like', '%'.$search.'%')
                        ->orWhere('carrier_name', 'like', '%'.$search.'%')
                        ->orWhere('vehicle_plate', 'like', '%'.$search.'%')
                        ->orWhere('driver_name', 'like', '%'.$search.'%');
                });
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function filtersFromRequest(Request $request, bool $isClient): array
    {
        return [
            'client_id' => ! $isClient && $request->integer('client_id') > 0 ? $request->integer('client_id') : null,
            'date_from' => filled($request->string('date_from')) ? (string) $request->string('date_from') : null,
            'date_to' => filled($request->string('date_to')) ? (string) $request->string('date_to') : null,
            'status' => (string) $request->string('status', 'all'),
            'type' => (string) $request->string('type', 'all'),
            'search' => trim((string) $request->string('search')),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formViewData(User $user, ?Booking $booking): array
    {
        $booking?->loadMissing(['client', 'requestedBy', 'assignedTo', 'warehouse']);

        return [
            'booking' => $booking,
            'isClient' => $user->hasRole(Role::CLIENTE),
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'warehouses' => $this->warehouseOptions(),
            'internalUsers' => User::query()
                ->with('role')
                ->where('active', true)
                ->whereHas('role', fn ($query) => $query->whereIn('slug', [
                    Role::ALMACEN,
                    Role::ADMINISTRACION,
                    Role::SUPERADMIN,
                ]))
                ->orderBy('name')
                ->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
            'typeOptions' => Booking::typeOptions(),
            'statusOptions' => Booking::statusOptions(),
        ];
    }

    /** @return Collection<int, Warehouse> */
    private function warehouseOptions(): Collection
    {
        return app(WarehouseIntegrityService::class)->canonicalActiveWarehouses(
            Warehouse::query()->with('client')->where('active', true)->get(),
        );
    }

    private function canAccessBookings(?User $user): bool
    {
        return $user !== null && ($user->hasRole(Role::CLIENTE) || $user->canAccessRole(Role::ALMACEN));
    }

    private function canCreateBooking(User $user): bool
    {
        return ($user->hasRole(Role::CLIENTE) && $user->client_id !== null)
            || $this->isInternalUser($user);
    }

    private function canViewBooking(User $user, Booking $booking): bool
    {
        if ($this->isInternalUser($user)) {
            return true;
        }

        return $user->hasRole(Role::CLIENTE) && (int) $user->client_id === (int) $booking->client_id;
    }

    private function canEditBooking(User $user, Booking $booking): bool
    {
        if ($this->isInternalUser($user)) {
            return true;
        }

        return $this->canViewBooking($user, $booking)
            && $booking->canClientCancel()
            && ! in_array($booking->status, [Booking::STATUS_IN_PROGRESS, Booking::STATUS_COMPLETED], true);
    }

    /**
     * @return list<string>
     */
    private function allowedStatusesFor(User $user, Booking $booking): array
    {
        if ($user->hasRole(Role::CLIENTE) && $this->canViewBooking($user, $booking) && $booking->canClientCancel()) {
            return [Booking::STATUS_CANCELLED];
        }

        if ($user->hasRole(Role::ALMACEN)) {
            return [
                Booking::STATUS_PLANNED,
                Booking::STATUS_IN_PROGRESS,
                Booking::STATUS_COMPLETED,
            ];
        }

        if ($user->canAccessRole(Role::ADMINISTRACION)) {
            return Booking::statuses();
        }

        return [];
    }

    private function isInternalUser(User $user): bool
    {
        return $user->canAccessRole(Role::ALMACEN);
    }

    private function canWriteInternalNotes(User $user): bool
    {
        return $this->isInternalUser($user);
    }

    private function redirectWithSyncFeedback(
        RedirectResponse $response,
        string $statusMessage,
        array $syncResult,
    ): RedirectResponse {
        $response = $response->with('status', $statusMessage);

        if (($syncResult['success'] ?? false) === false) {
            $response = $response->with(
                'warning',
                (string) ($syncResult['warning'] ?? 'No se pudo sincronizar el booking con Google Calendar.')
            );
        }

        return $response;
    }
}
