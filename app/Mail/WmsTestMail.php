<?php

namespace App\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WmsTestMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: 'MAXIMO WMS - Prueba de correo',
        );
    }

    public function content(): Content
    {
        return new Content(
            markdown: 'emails.test-mail',
            with: [
                'sentAt' => now()->format('Y-m-d H:i:s'),
            ],
        );
    }
}
