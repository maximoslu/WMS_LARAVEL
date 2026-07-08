<?php

namespace App\Notifications;

use App\Models\GoodsReceipt;
use App\Services\GoodsReceipts\GoodsReceiptDocumentStorage;
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
        // Anonymous (on-demand) notifiables are raw email addresses, not
        // platform users: they have no database notification inbox to write to.
        $isAnonymous = $notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable;

        return array_values(array_filter($this->channels, function (string $channel) use ($notifiable, $isAnonymous): bool {
            if ($channel === 'mail') {
                $address = $notifiable->routeNotificationFor('mail', $this);

                return filter_var(is_array($address) ? ($address[0] ?? null) : $address, FILTER_VALIDATE_EMAIL) !== false;
            }

            return $channel === 'database' && ! $isAnonymous;
        }));
    }

    public function toMail(object $notifiable): MailMessage
    {
        $receipt = $this->receipt;
        $documentName = DocumentDisplayNamer::baseName($receipt);
        $isAnonymous = $notifiable instanceof \Illuminate\Notifications\AnonymousNotifiable;

        $message = (new MailMessage)
            ->subject('Nuevo albarán disponible - Entrada #'.$receipt->id)
            ->greeting('Hola,')
            ->line('Hay un nuevo albarán disponible en WMS para tu mercancía.')
            ->line('Cliente: '.($receipt->client?->name ?? ''))
            ->line('Proveedor: '.($receipt->supplier?->name ?: 'Sin proveedor'))
            ->line('Fecha de entrada: '.(optional($receipt->received_at)->format('d/m/Y') ?: 'Pendiente'))
            ->line('Entrada: '.$documentName);

        if (! $isAnonymous) {
            return $message
                ->action('Ver Mis albaranes', route('client-goods-receipts.index'))
                ->line('Puedes consultarlo y descargarlo desde tu panel de cliente en "Mis albaranes".');
        }

        // External recipients are not WMS users, so a login-gated portal link
        // would be a dead end for them: attach the document directly instead.
        $document = app(GoodsReceiptDocumentStorage::class)->read($receipt);

        if ($document === null) {
            return $message->line('El documento no esta disponible para adjuntar en este momento.');
        }

        $extension = pathinfo((string) $document['original_name'], PATHINFO_EXTENSION);
        $attachmentName = $documentName.($extension !== '' ? '.'.$extension : '');

        return $message
            ->line('Adjuntamos el documento en este correo.')
            ->attachData($document['contents'], $attachmentName, [
                'mime' => $document['mime'] ?: 'application/octet-stream',
            ]);
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
