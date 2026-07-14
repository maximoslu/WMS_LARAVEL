<?php

namespace App\Notifications;

use App\Models\MerchandiseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InternalMerchandiseRequestSubmittedNotification extends Notification
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
        $clientLabel = $request->client?->code ?: $request->client?->name ?: 'CLIENTE';
        $lines = $request->lines
            ->map(fn ($line) => sprintf(
                '%s | %d pallets | lote %s',
                $line->item?->sku ?? 'Articulo eliminado',
                $line->requested_pallets,
                $line->lot ?: 'sin lote'
            ))
            ->implode('; ');

        return (new MailMessage)
            ->subject('Pedido de '.$clientLabel.' - '.$request->referenceCode())
            ->greeting('Nueva solicitud pendiente')
            ->line('Se ha registrado una nueva solicitud de mercancia en el SGA.')
            ->line('Solicitud: '.$request->referenceCode())
            ->line('Cliente: '.$request->client?->name)
            ->line('Solicitante: '.$request->requestedBy?->name ?? 'Sin usuario')
            ->line('Fecha: '.$request->submittedAt()?->format('d/m/Y H:i'))
            ->line('Referencia: '.$request->referenceCode())
            ->line('Estado: '.$request->statusLabel())
            ->line('Lineas: '.$lines)
            ->line('Total de pallets: '.number_format($request->requestedPalletsCount(), 0, ',', '.'))
            ->action('Abrir solicitud', route('dispatches.requests.show', $request));
    }

    public function toArray(object $notifiable): array
    {
        $request = $this->merchandiseRequest;

        return [
            'type' => 'nueva_solicitud_mercancia',
            'title' => 'Nueva solicitud de mercancia',
            'body' => sprintf(
                '%s ha registrado %s con %d pallets.',
                $request->client?->name ?? 'Un cliente',
                $request->referenceCode(),
                $request->requestedPalletsCount()
            ),
            'url' => route('dispatches.requests.show', $request),
            'reference' => $request->referenceCode(),
            'status' => $request->status,
            'status_label' => $request->statusLabel(),
            'requested_by' => $request->requestedBy?->name,
            'submitted_at' => $request->submittedAt()?->toDateTimeString(),
        ];
    }
}
