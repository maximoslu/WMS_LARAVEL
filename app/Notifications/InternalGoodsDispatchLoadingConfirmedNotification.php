<?php

namespace App\Notifications;

use App\Models\GoodsDispatch;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class InternalGoodsDispatchLoadingConfirmedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly GoodsDispatch $dispatch,
        private readonly User $confirmedBy,
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
        $dispatch = $this->dispatch;

        return (new MailMessage)
            ->subject('Carga real confirmada - '.$dispatch->dispatchNumber())
            ->greeting('Carga real confirmada')
            ->line('Se ha confirmado la carga real de una salida de mercancia.')
            ->line('Salida: '.$dispatch->dispatchNumber())
            ->line('Cliente: '.$dispatch->client?->name)
            ->line('Confirmado por: '.$this->confirmedBy->name)
            ->line('Total solicitado: '.number_format($dispatch->palletsCount(), 0, ',', '.'))
            ->line('Total cargado: '.number_format($dispatch->loadedPalletsCount(), 0, ',', '.'))
            ->line('Diferencias detectadas: '.($dispatch->hasLoadingDifferences() ? 'Si' : 'No'))
            ->action('Abrir salida', route('dispatches.show', $dispatch));
    }

    public function toArray(object $notifiable): array
    {
        $dispatch = $this->dispatch;

        return [
            'type' => 'confirmacion_carga_real',
            'title' => 'Carga real confirmada',
            'body' => sprintf(
                '%s confirmo %s para %s con %d pallets cargados.',
                $this->confirmedBy->name,
                $dispatch->dispatchNumber(),
                $dispatch->client?->name ?? 'sin cliente',
                $dispatch->loadedPalletsCount()
            ),
            'url' => route('dispatches.show', $dispatch),
            'dispatch_number' => $dispatch->dispatchNumber(),
            'client_name' => $dispatch->client?->name,
            'confirmed_by' => $this->confirmedBy->name,
            'requested_pallets' => $dispatch->palletsCount(),
            'loaded_pallets' => $dispatch->loadedPalletsCount(),
            'has_differences' => $dispatch->hasLoadingDifferences(),
            'confirmed_at' => $dispatch->latestLoadingConfirmationAt()?->toDateTimeString(),
        ];
    }
}
