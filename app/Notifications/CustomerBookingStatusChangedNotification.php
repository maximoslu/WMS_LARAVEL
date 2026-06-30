<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerBookingStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly string $previousStatus,
        private readonly array $channels = ['database', 'mail'],
    ) {}

    public function via(object $notifiable): array
    {
        return array_values(array_filter($this->channels, function (string $channel) use ($notifiable): bool {
            if ($channel === 'mail') {
                return filter_var($notifiable->email ?? null, FILTER_VALIDATE_EMAIL) !== false;
            }

            return $channel === 'database';
        }));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $booking = $this->booking;

        return (new MailMessage)
            ->subject('MAXIMO WMS - Booking actualizado')
            ->greeting('Tu booking ha cambiado de estado')
            ->line('Código: '.$booking->referenceCode())
            ->line('Estado anterior: '.(Booking::statusOptions()[$this->previousStatus] ?? ucfirst($this->previousStatus)))
            ->line('Estado actual: '.$booking->statusLabel())
            ->line('Fecha: '.$booking->scheduledWindowLabel())
            ->line('Observaciones: '.($booking->notes ?: 'Sin observaciones'))
            ->line('Notas internas: '.($booking->internal_notes ?: 'Sin notas adicionales'))
            ->action('Ver booking', route('bookings.show', $booking));
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
