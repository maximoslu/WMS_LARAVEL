<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesNotificationDelivery;
use App\Services\BrevoMailService;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class SendPasswordResetEmailJob implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, RetriesNotificationDelivery, SerializesModels;

    public function __construct(
        public readonly int $userId,
        public readonly string $recipientEmail,
        public readonly string $resetUrl,
        public readonly string $eventVersion,
    ) {}

    public function handle(BrevoMailService $mail, NotificationDeliveryService $deliveries): void
    {
        try {
            $deliveries->deliver(
                'auth.password_reset_requested',
                'user',
                $this->userId,
                $this->eventVersion,
                'brevo_api',
                $this->recipientEmail,
                fn ($delivery): ?string => $mail->sendPasswordReset(
                    $this->recipientEmail,
                    $this->resetUrl,
                    $delivery->provider_idempotency_key,
                ),
            );
        } catch (Throwable $exception) {
            $this->handleDeliveryException($exception);
        }
    }
}
