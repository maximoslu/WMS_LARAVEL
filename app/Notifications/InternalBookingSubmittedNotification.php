<?php

namespace App\Notifications;

use App\Models\Booking;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InternalBookingSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly Booking $booking,
        private readonly array $channels = ['database'],
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
            ->subject('MAXIMO WMS - Nueva solicitud de booking')
            ->greeting('Nueva solicitud de booking')
            ->line('Se ha registrado una nueva solicitud de booking en MAXIMO WMS.')
            ->line('Código: '.$booking->referenceCode())
            ->line('Cliente: '.($booking->client?->name ?? 'Sin cliente'))
            ->line('Tipo: '.$booking->typeLabel())
            ->line('Fecha: '.$booking->scheduledWindowLabel())
            ->line('Proveedor / transportista / origen: '.($booking->carrier_name ?: 'Sin dato'))
            ->line('Observaciones: '.($booking->notes ?: 'Sin observaciones'))
            ->action('Abrir booking', route('bookings.show', $booking));
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
