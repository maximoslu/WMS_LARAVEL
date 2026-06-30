<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class InternalBookingSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toArray(object $notifiable): array
    {
        $booking = $this->booking;

        return [
            'type' => 'nuevo_booking',
            'title' => 'Nuevo booking solicitado',
            'body' => sprintf(
                '%s ha solicitado %s para %s.',
                $booking->client?->name ?? 'Un cliente',
                $booking->referenceCode(),
                $booking->scheduledWindowLabel()
            ),
            'url' => route('bookings.show', $booking),
            'reference' => $booking->referenceCode(),
            'status' => $booking->status,
            'status_label' => $booking->statusLabel(),
            'booking_type' => $booking->type,
            'booking_type_label' => $booking->typeLabel(),
            'client_name' => $booking->client?->name,
            'carrier_name' => $booking->carrier_name,
            'scheduled_date' => $booking->scheduled_date?->toDateString(),
        ];
    }
}
