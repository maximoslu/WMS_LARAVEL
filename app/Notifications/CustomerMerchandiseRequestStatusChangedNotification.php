<?php

namespace App\Notifications;

use App\Models\MerchandiseRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Date;

class CustomerMerchandiseRequestStatusChangedNotification extends Notification
{
    use Queueable;

    public function __construct(
        private readonly MerchandiseRequest $merchandiseRequest,
        private readonly string $previousStatus,
        private readonly ?array $deliveryNoteAttachment = null,
    ) {}

    public function via(object $notifiable): array
    {
        return ['database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->merchandiseRequest;
        $summary = ($request->dispatch?->lines ?? $request->lines)
            ->map(fn ($line) => sprintf(
                '%s: %d pallets',
                $line->item?->sku ?? $line->sku ?? 'Articulo eliminado',
                method_exists($line, 'loadedPallets')
                    ? $line->loadedPallets()
                    : $line->requested_pallets
            ))
            ->implode('; ');
        $dispatchNumber = $request->dispatch?->dispatchNumber();

        $message = (new MailMessage)
            ->subject('Actualizacion de solicitud '.$request->referenceCode())
            ->greeting('Tu solicitud ha cambiado de estado')
            ->line('Solicitud: '.$request->referenceCode())
            ->line('Estado anterior: '.$this->labelFor($this->previousStatus))
            ->line('Estado actual: '.$request->statusLabel())
            ->line('Fecha del cambio: '.Date::now()->format('d/m/Y H:i'))
            ->line('Total de pallets: '.number_format($request->requestedPalletsCount(), 0, ',', '.'))
            ->line('Resumen: '.$summary)
            ->action('Ver solicitud', route('merchandise-requests.show', $request));

        if ($this->deliveryNoteAttachment !== null) {
            if ($dispatchNumber !== null) {
                $message->line('Albaran asociado: '.$dispatchNumber);
            }

            $message->line('Adjuntamos el albaran definitivo de salida en PDF.');
            $message->attachData(
                $this->deliveryNoteAttachment['content'],
                $this->deliveryNoteAttachment['name'],
                ['mime' => 'application/pdf']
            );
        }

        return $message;
    }

    private function labelFor(string $status): string
    {
        return MerchandiseRequest::statusOptions()[$status] ?? ucfirst($status);
    }

    public function toArray(object $notifiable): array
    {
        $request = $this->merchandiseRequest;
        $dispatchNumber = $request->dispatch?->dispatchNumber();

        return [
            'type' => 'estado_solicitud_mercancia',
            'title' => 'Tu solicitud ha cambiado de estado',
            'body' => sprintf(
                '%s ahora esta en estado %s.',
                $request->referenceCode(),
                $request->statusLabel()
            ),
            'url' => route('merchandise-requests.show', $request),
            'reference' => $request->referenceCode(),
            'status' => $request->status,
            'status_label' => $request->statusLabel(),
            'dispatch_number' => $dispatchNumber,
            'completed_with_delivery_note' => $this->deliveryNoteAttachment !== null,
        ];
    }
}
