<?php

namespace App\Notifications;

use App\Models\GoodsDispatch;
use App\Models\MerchandiseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Date;

class CustomerDispatchDeliveryNoteNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly GoodsDispatch $dispatch,
        private readonly MerchandiseRequest $merchandiseRequest,
        private readonly string $pdfContent,
        private readonly string $currentStatus,
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
        $lines = $dispatch->lines
            ->filter(fn ($line) => $line->loadedPallets() > 0)
            ->map(fn ($line) => sprintf(
                '%s: %d pallets',
                $line->sku ?? $line->item?->sku ?? 'Articulo',
                $line->loadedPallets()
            ))
            ->implode('; ');

        $message = (new MailMessage)
            ->subject('Albaran de salida de tu pedido')
            ->greeting($this->currentStatus === MerchandiseRequest::STATUS_COMPLETED
                ? 'Tu pedido ha quedado completado'
                : 'Tu pedido ha sido enviado')
            ->line('Adjuntamos el albaran definitivo de salida.')
            ->line('Solicitud: '.$this->merchandiseRequest->referenceCode())
            ->line('Salida: '.$dispatch->dispatchNumber())
            ->line('Fecha de envio: '.Date::now()->format('d/m/Y H:i'))
            ->line('Cliente: '.$dispatch->client?->name)
            ->line('Total pallets entregados: '.number_format($dispatch->loadedPalletsCount(), 0, ',', '.'))
            ->line('Resumen de carga: '.$lines)
            ->action('Ver pedido', route('merchandise-requests.show', $this->merchandiseRequest));

        $message->attachData(
            $this->pdfContent,
            $dispatch->dispatchNumber().'.pdf',
            ['mime' => 'application/pdf']
        );

        return $message;
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'albaran_salida',
            'title' => 'Albaran de salida disponible',
            'body' => sprintf(
                'El albaran %s ya esta disponible para %s.',
                $this->dispatch->dispatchNumber(),
                $this->merchandiseRequest->referenceCode()
            ),
            'url' => route('merchandise-requests.show', $this->merchandiseRequest),
            'dispatch_number' => $this->dispatch->dispatchNumber(),
            'status' => $this->currentStatus,
            'status_label' => $this->merchandiseRequest->statusLabel(),
            'loaded_pallets' => $this->dispatch->loadedPalletsCount(),
            'delivery_note_sent_at' => Date::now()->toDateTimeString(),
        ];
    }
}
