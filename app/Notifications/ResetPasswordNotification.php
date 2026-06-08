<?php

namespace App\Notifications;

use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

class ResetPasswordNotification extends Notification
{
    public function __construct(
        private readonly string $token,
    ) {
    }

    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject('MAXIMO WMS - Recuperacion de contrasena')
            ->greeting('Acceso operativo')
            ->line('Hemos recibido una solicitud para restablecer la contrasena de tu cuenta.')
            ->action('Restablecer contrasena', $this->resetUrl($notifiable))
            ->line('Si no has solicitado este cambio, puedes ignorar este correo.')
            ->salutation('MAXIMO WMS');
    }

    private function resetUrl(object $notifiable): string
    {
        return route('password.reset', [
            'token' => $this->token,
            'email' => $notifiable->getEmailForPasswordReset(),
        ]);
    }
}
