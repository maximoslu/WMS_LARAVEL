<?php

namespace App\Services;

use App\Exceptions\BrevoMailConfigurationException;
use App\Models\AccessRequest;
use App\Models\MerchandiseRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use RuntimeException;

class BrevoMailService
{
    public function isConfigured(): bool
    {
        return filled((string) config('services.brevo.key'))
            && filled((string) config('mail.from.address'))
            && filled((string) config('mail.from.name'));
    }

    /**
     * @throws BrevoMailConfigurationException
     */
    public function assertConfigured(): void
    {
        if (! filled((string) config('services.brevo.key'))) {
            throw new BrevoMailConfigurationException('Falta configurar BREVO_API_KEY para el envio de correo.');
        }

        if (! filled((string) config('mail.from.address'))) {
            throw new BrevoMailConfigurationException('Falta configurar MAIL_FROM_ADDRESS para el envio de correo.');
        }

        if (! filled((string) config('mail.from.name'))) {
            throw new BrevoMailConfigurationException('Falta configurar MAIL_FROM_NAME para el envio de correo.');
        }
    }

    /**
     * @throws BrevoMailConfigurationException
     */
    public function sendPasswordReset(string $recipientEmail, string $resetUrl): void
    {
        $this->send(
            toEmails: $recipientEmail,
            subject: 'MAXIMO WMS - Recuperacion de contrasena',
            htmlView: 'emails.brevo.password-reset',
            data: [
                'resetUrl' => $resetUrl,
            ],
        );
    }

    /**
     * @throws BrevoMailConfigurationException
     */
    public function sendAccessRequestNotification(AccessRequest $accessRequest): void
    {
        $this->send(
            toEmails: (string) config('wms.access_request_notification_email'),
            subject: 'MAXIMO WMS - Nueva solicitud de acceso',
            htmlView: 'emails.brevo.access-request-submitted',
            data: [
                'accessRequest' => $accessRequest,
            ],
        );
    }

    /**
     * @throws BrevoMailConfigurationException
     */
    public function sendTestMail(string $recipientEmail): void
    {
        $this->send(
            toEmails: $recipientEmail,
            subject: 'MAXIMO WMS - Prueba de correo',
            htmlView: 'emails.brevo.test-mail',
            data: [
                'sentAt' => now()->format('Y-m-d H:i:s'),
                'mailer' => 'brevo-api',
            ],
        );
    }

    /**
     * @param  array<int, string>  $recipientEmails
     *
     * @throws BrevoMailConfigurationException
     */
    public function sendMerchandiseRequestCreated(array $recipientEmails, MerchandiseRequest $merchandiseRequest): void
    {
        $this->send(
            toEmails: $recipientEmails,
            subject: 'Nueva solicitud de mercancia - '.$merchandiseRequest->client->name,
            htmlView: 'emails.brevo.merchandise-request-created',
            data: [
                'merchandiseRequest' => $merchandiseRequest,
                'requestUrl' => route('merchandise-requests.show', $merchandiseRequest),
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $data
     *
     * @throws BrevoMailConfigurationException
     */
    private function send(string|array $toEmails, string $subject, string $htmlView, array $data): void
    {
        $this->assertConfigured();

        $htmlContent = View::make($htmlView, $data)->render();
        $textContent = trim(preg_replace('/\s+/', ' ', strip_tags($htmlContent)) ?? '');
        $recipients = collect((array) $toEmails)
            ->map(fn (string $email): string => trim($email))
            ->filter(fn (string $email): bool => $email !== '')
            ->unique()
            ->map(fn (string $email): array => ['email' => $email])
            ->values()
            ->all();

        if ($recipients === []) {
            return;
        }

        $response = Http::baseUrl((string) config('services.brevo.base_url'))
            ->acceptJson()
            ->withHeaders([
                'api-key' => (string) config('services.brevo.key'),
            ])
            ->post('/smtp/email', [
                'sender' => [
                    'email' => (string) config('mail.from.address'),
                    'name' => (string) config('mail.from.name'),
                ],
                'to' => $recipients,
                'subject' => $subject,
                'htmlContent' => $htmlContent,
                'textContent' => $textContent,
            ]);

        if ($response->failed()) {
            throw new RuntimeException(sprintf(
                'Brevo API devolvio error al enviar el correo. HTTP %s.',
                $response->status()
            ));
        }
    }
}
