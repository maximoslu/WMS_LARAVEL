<?php

namespace App\Notifications;

use App\Models\GoodsDispatch;
use App\Models\MerchandiseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InternalGoodsDispatchCompletedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly GoodsDispatch $dispatch,
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
        $clientLabel = $this->merchandiseRequest->client?->name ?? $this->dispatch->client?->name ?? 'cliente';

        return (new MailMessage)
            ->subject('Pedido '.$this->merchandiseRequest->referenceCode().' de '.$clientLabel.' completado')
            ->greeting('Pedido completado')
            ->line('Pedido '.$this->merchandiseRequest->referenceCode().' de '.$clientLabel.' completado.')
            ->line('El albaran de salida ha sido generado o enviado.')
            ->line('Salida: '.$this->dispatch->dispatchNumber())
            ->line('Cliente: '.$clientLabel)
            ->line('Total pallets entregados: '.number_format($this->dispatch->loadedPalletsCount(), 0, ',', '.'))
            ->action('Abrir salida', route('dispatches.show', $this->dispatch));
    }

    public function toArray(object $notifiable): array
    {
        $clientLabel = $this->merchandiseRequest->client?->name ?? $this->dispatch->client?->name ?? 'cliente';

        return [
            'type' => 'pedido_completado_empresa',
            'title' => 'Pedido '.$this->merchandiseRequest->referenceCode().' de '.$clientLabel.' completado',
            'body' => 'Pedido '.$this->merchandiseRequest->referenceCode().' de '.$clientLabel.' completado.',
            'url' => route('dispatches.show', $this->dispatch),
            'reference' => $this->merchandiseRequest->referenceCode(),
            'dispatch_number' => $this->dispatch->dispatchNumber(),
            'status' => $this->merchandiseRequest->status,
            'status_label' => $this->merchandiseRequest->statusLabel(),
            'loaded_pallets' => $this->dispatch->loadedPalletsCount(),
            'delivery_note_sent_at' => $this->dispatch->delivery_note_sent_at?->toDateTimeString(),
        ];
    }
}
