<?php

namespace App\Services\Bookings;

use App\Jobs\ProcessBookingStatusChangedJob;
use App\Jobs\ProcessBookingSubmittedNotificationsJob;
use App\Models\Booking;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerBookingStatusChangedNotification;
use App\Notifications\InternalBookingSubmittedNotification;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BookingNotificationService
{
    public function __construct(
        private readonly NotificationDeliveryService $deliveries,
    ) {}

    public function notifySubmitted(Booking $booking): void
    {
        ProcessBookingSubmittedNotificationsJob::dispatch($booking->id)->afterCommit();
    }

    public function deliverSubmittedNotifications(Booking $booking): void
    {
        $booking->loadMissing(['client', 'requestedBy']);

        $recipients = $this->internalRecipients();

        if ($recipients->isEmpty()) {
            Log::info('No hay usuarios internos activos para notificar un booking.', [
                'booking_id' => $booking->id,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            $this->deliveries->deliverToUser(
                'booking.submitted',
                'booking',
                $booking->id,
                'submitted',
                $recipient,
                ['database'],
                fn (array $channels) => new InternalBookingSubmittedNotification($booking, $channels),
            );
        }
    }

    public function notifyStatusChanged(Booking $booking, string $previousStatus): void
    {
        ProcessBookingStatusChangedJob::dispatch(
            $booking->id,
            $previousStatus,
            (string) $booking->status,
            $this->statusEventVersion($booking, $previousStatus),
        )->afterCommit();
    }

    public function deliverStatusChangedNotifications(
        Booking $booking,
        string $previousStatus,
        ?string $eventVersion = null,
    ): void {
        $booking->loadMissing(['client.users.role', 'requestedBy']);

        if ($booking->status === $previousStatus) {
            return;
        }

        $recipients = $this->clientRecipients($booking);

        if ($recipients->isEmpty()) {
            Log::info('No hay usuarios cliente para notificar cambio de estado de booking.', [
                'booking_id' => $booking->id,
                'status' => $booking->status,
            ]);

            return;
        }

        foreach ($recipients as $recipient) {
            $this->deliveries->deliverToUser(
                'booking.status_changed',
                'booking',
                $booking->id,
                $eventVersion ?? $this->statusEventVersion($booking, $previousStatus),
                $recipient,
                ['database'],
                fn (array $channels) => new CustomerBookingStatusChangedNotification($booking, $previousStatus, $channels),
            );
        }

    }

    private function statusEventVersion(Booking $booking, string $previousStatus): string
    {
        return $previousStatus.'>'.$booking->status.':'.$booking->updated_at?->format('Y-m-d H:i:s.u');
    }

    /**
     * @return Collection<int, User>
     */
    private function internalRecipients(): Collection
    {
        return User::query()
            ->with('role')
            ->where('active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('slug', [
                Role::ALMACEN,
                Role::ADMINISTRACION,
                Role::SUPERADMIN,
            ]))
            ->get()
            ->unique('id')
            ->values();
    }

    /**
     * @return Collection<int, User>
     */
    private function clientRecipients(Booking $booking): Collection
    {
        if ($booking->requestedBy !== null) {
            return collect([$booking->requestedBy]);
        }

        return $booking->client?->users
            ->filter(fn (User $user) => $user->active && $user->hasRole(Role::CLIENTE))
            ->unique('id')
            ->values() ?? collect();
    }
}
