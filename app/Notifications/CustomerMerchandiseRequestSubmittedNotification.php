<?php

namespace App\Notifications;

use App\Models\MerchandiseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class CustomerMerchandiseRequestSubmittedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly MerchandiseRequest $merchandiseRequest,
    ) {}

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->merchandiseRequest;
        $lines = $request->lines
            ->map(fn ($line) => sprintf(
                '%s | %d pallets',
                $line->item?->sku ?? 'Articulo eliminado',
                $line->requested_pallets
            ))
            ->all();

        return (new MailMessage)
            ->subject('Confirmacion de solicitud de mercancia '.$request->referenceCode())
            ->greeting('Solicitud registrada correctamente')
            ->line('Hemos recibido tu solicitud de mercancia y ya esta registrada en el SGA.')
            ->line('Referencia: '.$request->referenceCode())
            ->line('Fecha de solicitud: '.$request->submittedAt()?->format('d/m/Y H:i'))
            ->line('Estado inicial: '.$request->statusLabel())
            ->line('Total de pallets: '.number_format($request->requestedPalletsCount(), 0, ',', '.'))
            ->line('Lineas solicitadas:')
            ->line(implode(PHP_EOL, $lines))
            ->action('Ver solicitud', route('merchandise-requests.show', $request));
    }
}
