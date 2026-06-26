<?php

namespace App\Notifications;

use App\Models\MerchandiseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerMerchandiseRequestStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly MerchandiseRequest $merchandiseRequest,
        private readonly string $previousStatus,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->merchandiseRequest;

        return (new MailMessage)
            ->subject('Actualizacion de solicitud '.$request->referenceCode())
            ->greeting('Tu solicitud ha cambiado de estado')
            ->line('Solicitud: '.$request->referenceCode())
            ->line('Estado anterior: '.$this->labelFor($this->previousStatus))
            ->line('Estado actual: '.$request->statusLabel())
            ->line('Total de pallets: '.number_format($request->requestedPalletsCount(), 0, ',', '.'))
            ->action('Ver solicitud', route('merchandise-requests.show', $request));
    }

    private function labelFor(string $status): string
    {
        return match ($status) {
            MerchandiseRequest::STATUS_PENDING => 'Pendiente',
            MerchandiseRequest::STATUS_PREPARING => 'Preparando',
            MerchandiseRequest::STATUS_SENT => 'Enviado',
            MerchandiseRequest::STATUS_COMPLETED => 'Completado',
            MerchandiseRequest::STATUS_CANCELLED => 'Cancelado',
            default => ucfirst($status),
        };
    }
}
