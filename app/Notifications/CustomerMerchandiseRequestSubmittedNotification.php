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
        $request = $this->merchandiseRequest;
        $lines = $request->lines
            ->map(fn ($line) => sprintf(
                '%s | %d pallets',
                $line->item?->sku ?? 'Articulo eliminado',
                $line->requested_pallets
            ))
            ->all();

        return (new MailMessage)
            ->subject('Tu pedido '.$request->referenceCode().' se ha registrado correctamente')
            ->greeting('Pedido registrado correctamente')
            ->line('Tu pedido '.$request->referenceCode().' se ha registrado correctamente.')
            ->line('Referencia: '.$request->referenceCode())
            ->line('Fecha de solicitud: '.$request->submittedAt()?->format('d/m/Y H:i'))
            ->line('Estado inicial: '.$request->statusLabel())
            ->line('Total de pallets: '.number_format($request->requestedPalletsCount(), 0, ',', '.'))
            ->line('Lineas solicitadas:')
            ->line(implode(PHP_EOL, $lines))
            ->action('Ver solicitud', route('merchandise-requests.show', $request));
    }

    public function toArray(object $notifiable): array
    {
        $request = $this->merchandiseRequest;

        return [
            'type' => 'pedido_creado_cliente',
            'title' => 'Pedido registrado correctamente',
            'body' => 'Tu pedido '.$request->referenceCode().' se ha registrado correctamente.',
            'url' => route('merchandise-requests.show', $request),
            'reference' => $request->referenceCode(),
            'status' => $request->status,
            'status_label' => $request->statusLabel(),
            'submitted_at' => $request->submittedAt()?->toDateTimeString(),
        ];
    }
}
