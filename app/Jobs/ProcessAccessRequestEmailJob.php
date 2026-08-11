<?php

namespace App\Jobs;

use App\Exceptions\PermanentNotificationDeliveryException;
use App\Jobs\Concerns\RetriesNotificationDelivery;
use App\Models\AccessRequest;
use App\Services\BrevoMailService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use InvalidArgumentException;
use Throwable;

class ProcessAccessRequestEmailJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesNotificationDelivery, SerializesModels;

    public const SUBMITTED = 'submitted';

    public const APPROVED = 'approved';

    public const REJECTED = 'rejected';

    public function __construct(
        public readonly int $accessRequestId,
        public readonly string $event,
    ) {}

    public function handle(BrevoMailService $mail, NotificationDeliveryService $deliveries): void
    {
        $accessRequest = AccessRequest::query()->find($this->accessRequestId);

        if ($accessRequest === null) {
            return;
        }

        try {
            $recipients = $this->recipients($accessRequest, $mail);

            if ($recipients === []) {
                throw new PermanentNotificationDeliveryException('No hay destinatarios validos para este aviso de solicitud de acceso.');
            }

            foreach ($recipients as $recipient) {
                $deliveries->deliver(
                    'access_request.'.$this->event,
                    'access_request',
                    $accessRequest->id,
                    $this->eventVersion($accessRequest),
                    'brevo_api',
                    $recipient,
                    fn ($delivery): ?string => $this->send($mail, $accessRequest, $recipient, $delivery->provider_idempotency_key),
                );
            }
        } catch (Throwable $exception) {
            $this->handleDeliveryException($exception);
        }
    }

    /** @return list<string> */
    private function recipients(AccessRequest $accessRequest, BrevoMailService $mail): array
    {
        $recipients = $this->event === self::SUBMITTED
            ? $mail->accessRequestNotificationRecipients()
            : [$accessRequest->email];

        return collect($recipients)
            ->filter(fn (mixed $email): bool => is_string($email) && filter_var(trim($email), FILTER_VALIDATE_EMAIL) !== false)
            ->map(fn (string $email): string => mb_strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();
    }

    private function eventVersion(AccessRequest $accessRequest): string
    {
        return match ($this->event) {
            self::SUBMITTED => 'submitted:'.($accessRequest->created_at?->format('Y-m-d H:i:s.u') ?? $accessRequest->id),
            self::APPROVED => 'approved:'.($accessRequest->approved_at?->format('Y-m-d H:i:s.u') ?? $accessRequest->updated_at?->format('Y-m-d H:i:s.u')),
            self::REJECTED => 'rejected:'.($accessRequest->rejected_at?->format('Y-m-d H:i:s.u') ?? $accessRequest->updated_at?->format('Y-m-d H:i:s.u')),
            default => throw new InvalidArgumentException('Evento de solicitud de acceso no soportado.'),
        };
    }

    private function send(
        BrevoMailService $mail,
        AccessRequest $accessRequest,
        string $recipient,
        string $providerIdempotencyKey,
    ): ?string {
        return match ($this->event) {
            self::SUBMITTED => $mail->sendAccessRequestNotification($accessRequest, [$recipient], $providerIdempotencyKey),
            self::APPROVED => $mail->sendAccessRequestApproved($accessRequest, $recipient, $providerIdempotencyKey),
            self::REJECTED => $mail->sendAccessRequestRejected($accessRequest, $recipient, $providerIdempotencyKey),
            default => throw new InvalidArgumentException('Evento de solicitud de acceso no soportado.'),
        };
    }
}
