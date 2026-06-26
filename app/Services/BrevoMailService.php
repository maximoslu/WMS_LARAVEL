<?php

namespace App\Services;

use App\Exceptions\BrevoMailConfigurationException;
use App\Models\AccessRequest;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
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
            subject: 'Restablecer contrasena - MAXIMO WMS',
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
        $recipientEmails = User::query()
            ->where('active', true)
            ->whereHas('role', fn ($query) => $query->whereIn('slug', [
                Role::SUPERADMIN,
                Role::ADMINISTRACION,
            ]))
            ->pluck('email')
            ->filter(fn (?string $email) => filled($email))
            ->map(fn (string $email) => Str::lower(trim($email)))
            ->unique()
            ->values()
            ->all();

        if ($recipientEmails === []) {
            $fallbackEmail = Str::lower(trim((string) config('wms.access_request_notification_email')));

            if (filled($fallbackEmail)) {
                $recipientEmails = [$fallbackEmail];
            }
        }

        $this->send(
            toEmails: $recipientEmails,
            subject: 'MAXIMO WMS - Nueva solicitud de acceso',
            htmlView: 'emails.brevo.access-request-submitted',
            data: [
                'accessRequest' => $accessRequest,
                'reviewUrl' => route('access-requests.index'),
            ],
        );
    }

    /**
     * @throws BrevoMailConfigurationException
     */
    public function sendAccessRequestApproved(AccessRequest $accessRequest): void
    {
        $this->send(
            toEmails: $accessRequest->email,
            subject: 'MAXIMO WMS - Tu acceso ha sido aprobado',
            htmlView: 'emails.brevo.access-request-approved',
            data: [
                'accessRequest' => $accessRequest,
                'loginUrl' => route('login'),
                'resetUrl' => route('password.request'),
            ],
        );
    }

    /**
     * @throws BrevoMailConfigurationException
     */
    public function sendAccessRequestRejected(AccessRequest $accessRequest): void
    {
        $this->send(
            toEmails: $accessRequest->email,
            subject: 'MAXIMO WMS - Solicitud de acceso revisada',
            htmlView: 'emails.brevo.access-request-rejected',
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
     * @param  array<string, mixed>  $data
     *
     * @throws BrevoMailConfigurationException
     */
    private function send(string|array $toEmails, string $subject, string $htmlView, array $data): void
    {
        $this->assertConfigured();

        $recipients = collect(is_array($toEmails) ? $toEmails : [$toEmails])
            ->filter(fn (?string $email) => filled($email))
            ->map(fn (string $email): array => ['email' => trim($email)])
            ->unique('email')
            ->values()
            ->all();

        if ($recipients === []) {
            throw new RuntimeException('No hay destinatarios validos para el envio de correo.');
        }

        $htmlContent = View::make($htmlView, $data)->render();
        $textContent = trim(preg_replace('/\s+/', ' ', strip_tags($htmlContent)) ?? '');

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
