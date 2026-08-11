<?php

namespace App\Services\Notifications;

use App\Exceptions\NotificationDeliveryInProgressException;
use App\Models\NotificationDelivery;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class NotificationDeliveryService
{
    private const PROCESSING_LEASE_SECONDS = 90;

    /**
     * @param  list<string>  $channels
     * @param  Closure(list<string>): object  $notification
     */
    public function deliverToUser(
        string $type,
        string $sourceType,
        int|string $sourceId,
        ?string $eventVersion,
        User $recipient,
        array $channels,
        Closure $notification,
    ): void {
        foreach ($channels as $channel) {
            if ($channel === 'mail' && filter_var($recipient->email, FILTER_VALIDATE_EMAIL) === false) {
                continue;
            }

            $recipientIdentity = $channel === 'mail'
                ? (string) $recipient->email
                : $this->userRecipient($recipient->id);

            $this->deliver(
                $type,
                $sourceType,
                $sourceId,
                $eventVersion,
                $channel,
                $recipientIdentity,
                function () use ($recipient, $notification, $channel): ?string {
                    $recipient->notify($notification([$channel]));

                    return null;
                },
            );
        }
    }

    /** @param Closure(list<string>): object $notification */
    public function deliverToEmail(
        string $type,
        string $sourceType,
        int|string $sourceId,
        ?string $eventVersion,
        string $email,
        Closure $notification,
    ): void {
        $email = Str::lower(trim($email));

        if (filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return;
        }

        $this->deliver(
            $type,
            $sourceType,
            $sourceId,
            $eventVersion,
            'mail',
            $email,
            function () use ($email, $notification): ?string {
                Notification::route('mail', $email)->notify($notification(['mail']));

                return null;
            },
        );
    }

    /**
     * @param  Closure(NotificationDelivery): (string|null)  $send
     */
    public function deliver(
        string $type,
        string $sourceType,
        int|string $sourceId,
        ?string $eventVersion,
        string $channel,
        string $recipient,
        Closure $send,
    ): bool {
        $identity = $this->identity($type, $sourceType, $sourceId, $eventVersion, $channel, $recipient);
        $token = (string) Str::uuid();

        NotificationDelivery::query()->insertOrIgnore([
            'idempotency_key' => $identity['idempotency_key'],
            'provider_idempotency_key' => (string) Str::uuid(),
            'type' => $type,
            'source_type' => $sourceType,
            'source_id' => (string) $sourceId,
            'event_version' => $eventVersion,
            'channel' => $channel,
            'recipient_hash' => $identity['recipient_hash'],
            'status' => NotificationDelivery::STATUS_PENDING,
            'attempts' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $delivery = DB::transaction(function () use ($identity, $token): ?NotificationDelivery {
            $delivery = NotificationDelivery::query()
                ->where('idempotency_key', $identity['idempotency_key'])
                ->lockForUpdate()
                ->firstOrFail();

            if ($delivery->status === NotificationDelivery::STATUS_SENT) {
                return null;
            }

            if (
                $delivery->status === NotificationDelivery::STATUS_PROCESSING
                && $delivery->processing_started_at?->isAfter(now()->subSeconds(self::PROCESSING_LEASE_SECONDS))
            ) {
                throw new NotificationDeliveryInProgressException('Otra ejecucion esta procesando esta entrega.');
            }

            $delivery->forceFill([
                'status' => NotificationDelivery::STATUS_PROCESSING,
                'attempts' => $delivery->attempts + 1,
                'processing_token' => $token,
                'processing_started_at' => now(),
                'failed_at' => null,
                'last_error' => null,
            ])->save();

            return $delivery->fresh();
        }, 3);

        if ($delivery === null) {
            return false;
        }

        try {
            $providerMessageId = $send($delivery);
        } catch (Throwable $exception) {
            NotificationDelivery::query()
                ->whereKey($delivery->id)
                ->where('processing_token', $token)
                ->update([
                    'status' => NotificationDelivery::STATUS_FAILED,
                    'processing_token' => null,
                    'processing_started_at' => null,
                    'failed_at' => now(),
                    'last_error' => $this->summarizeError($exception),
                    'updated_at' => now(),
                ]);

            throw $exception;
        }

        $updated = NotificationDelivery::query()
            ->whereKey($delivery->id)
            ->where('processing_token', $token)
            ->update([
                'status' => NotificationDelivery::STATUS_SENT,
                'processing_token' => null,
                'processing_started_at' => null,
                'sent_at' => now(),
                'failed_at' => null,
                'provider_message_id' => filled($providerMessageId) ? Str::limit($providerMessageId, 255, '') : null,
                'last_error' => null,
                'updated_at' => now(),
            ]);

        if ($updated !== 1) {
            throw new RuntimeException('No se pudo confirmar de forma segura la entrega de la notificacion.');
        }

        return true;
    }

    /** @return array{idempotency_key: string, recipient_hash: string} */
    public function identity(
        string $type,
        string $sourceType,
        int|string $sourceId,
        ?string $eventVersion,
        string $channel,
        string $recipient,
    ): array {
        $normalizedRecipient = $this->normalizeRecipient($channel, $recipient);
        $payload = json_encode([
            'type' => Str::lower(trim($type)),
            'source_type' => Str::lower(trim($sourceType)),
            'source_id' => (string) $sourceId,
            'event_version' => $eventVersion === null ? null : trim($eventVersion),
            'channel' => Str::lower(trim($channel)),
            'recipient' => $normalizedRecipient,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        return [
            'idempotency_key' => hash('sha256', $payload),
            'recipient_hash' => hash('sha256', $normalizedRecipient),
        ];
    }

    public function userRecipient(int|string $userId): string
    {
        return 'user:'.$userId;
    }

    private function normalizeRecipient(string $channel, string $recipient): string
    {
        $recipient = trim($recipient);

        if (Str::lower($channel) === 'mail') {
            $recipient = Str::lower($recipient);
        }

        return $recipient;
    }

    private function summarizeError(Throwable $exception): string
    {
        $summary = $exception::class.': '.$exception->getMessage();
        $secrets = [
            config('services.brevo.key'),
            config('mail.mailers.smtp.password'),
            config('mail.mailers.smtp.username'),
        ];

        foreach ($secrets as $secret) {
            if (is_string($secret) && strlen($secret) >= 4) {
                $summary = str_replace($secret, '[redacted]', $summary);
            }
        }

        return Str::limit($summary, 1000, '...');
    }
}
