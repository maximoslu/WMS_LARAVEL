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
    ) {}

    public function via(object $notifiable): array
    {
        $channels = ['database'];

        if (filled($notifiable->email ?? null)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    public function toMail(object $notifiable): MailMessage
    {
        $request = $this->merchandiseRequest;

        return (new MailMessage)
            ->subject('Nueva solicitud de mercancia '.$request->referenceCode())
            ->greeting('Nueva solicitud pendiente')
            ->line('Se ha registrado una nueva solicitud de mercancia en el SGA.')
            ->line('Cliente: '.$request->client?->name)
            ->line('Referencia: '.$request->referenceCode())
            ->line('Estado: '.$request->statusLabel())
            ->line('Total de pallets: '.number_format($request->requestedPalletsCount(), 0, ',', '.'))
            ->action('Abrir solicitud', route('dispatches.requests.show', $request));
    }

    public function toArray(object $notifiable): array
    {
        $request = $this->merchandiseRequest;

        return [
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
        ];
    }
}
