<?php

namespace App\Notifications;

use App\Models\GoodsReceipt;
use App\Support\GoodsReceipts\DocumentDisplayNamer;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ClientGoodsReceiptDocumentAvailableNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly GoodsReceipt $receipt,
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
        $receipt = $this->receipt;
        $documentName = DocumentDisplayNamer::baseName($receipt);

        return (new MailMessage)
            ->subject('Nuevo albarán disponible - Entrada #'.$receipt->id)
            ->greeting('Hola,')
            ->line('Hay un nuevo albarán disponible en WMS para tu mercancía.')
            ->line('Cliente: '.($receipt->client?->name ?? ''))
            ->line('Proveedor: '.($receipt->supplier?->name ?: 'Sin proveedor'))
            ->line('Fecha de entrada: '.(optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente'))
            ->line('Entrada: '.$documentName)
            ->action('Ver Mis albaranes', route('client-goods-receipts.index'))
            ->line('Puedes consultarlo y descargarlo desde tu panel de cliente en "Mis albaranes".');
    }

    public function toArray(object $notifiable): array
    {
        $receipt = $this->receipt;

        return [
            'type' => 'goods_receipt_document_available',
            'title' => 'Nuevo albarán disponible',
            'body' => sprintf(
                'Entrada #%d de %s ya tiene albarán disponible en Mis albaranes.',
                $receipt->id,
                $receipt->supplier?->name ?: 'proveedor sin especificar'
            ),
            'url' => route('client-goods-receipts.index'),
            'goods_receipt_id' => $receipt->id,
            'received_at' => $receipt->received_at?->toDateString(),
        ];
    }
}
