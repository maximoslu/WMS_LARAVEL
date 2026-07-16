<?php

namespace App\Services\Bookings;

use App\Jobs\ProcessBookingStatusChangedJob;
use App\Jobs\ProcessBookingSubmittedNotificationsJob;
use App\Models\Booking;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CustomerBookingStatusChangedNotification;
use App\Notifications\InternalBookingSubmittedNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

class BookingNotificationService
{
    public function notifySubmitted(Booking $booking): void
    {
        ProcessBookingSubmittedNotificationsJob::dispatch($booking->id)->afterResponse();
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
            $recipient->notify(new InternalBookingSubmittedNotification($booking, ['database']));
        }
    }

    public function notifyStatusChanged(Booking $booking, string $previousStatus): void
    {
        ProcessBookingStatusChangedJob::dispatch($booking->id, $previousStatus)->afterResponse();
    }

    public function deliverStatusChangedNotifications(Booking $booking, string $previousStatus): void
    {
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
            $recipient->notify(new CustomerBookingStatusChangedNotification($booking, $previousStatus, ['database']));
        }

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
