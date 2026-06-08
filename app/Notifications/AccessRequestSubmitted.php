<?php

namespace App\Notifications;

use App\Models\AccessRequest;
use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class AccessRequestSubmitted extends Notification
{
    use Queueable;

    public function __construct(
        public readonly AccessRequest $accessRequest,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('MAXIMO WMS - Nueva solicitud de acceso')
            ->greeting('Solicitud registrada')
            ->line('Se ha recibido una nueva solicitud de acceso en MAXIMO WMS.')
            ->line('Nombre: '.$this->accessRequest->name)
            ->line('Email: '.$this->accessRequest->email)
            ->line('Empresa: '.($this->accessRequest->company ?: 'No indicada'))
            ->line('Mensaje: '.($this->accessRequest->notes ?: 'Sin observaciones'))
            ->line('Fecha: '.$this->accessRequest->created_at?->format('Y-m-d H:i:s'))
            ->salutation('MAXIMO WMS');
    }
}
