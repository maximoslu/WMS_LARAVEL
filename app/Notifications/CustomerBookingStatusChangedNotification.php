<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class CustomerBookingStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly string $previousStatus,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $booking = $this->booking;

        return [
            'type' => 'booking_estado_actualizado',
            'title' => 'Tu booking ha cambiado de estado',
            'body' => sprintf(
                '%s ha pasado de %s a %s.',
                $booking->referenceCode(),
                Booking::statusOptions()[$this->previousStatus] ?? ucfirst($this->previousStatus),
                $booking->statusLabel()
            ),
            'url' => route('bookings.show', $booking),
            'reference' => $booking->referenceCode(),
            'status' => $booking->status,
            'status_label' => $booking->statusLabel(),
            'previous_status' => $this->previousStatus,
            'scheduled_date' => $booking->scheduled_date?->toDateString(),
        ];
    }
}
