<?php

namespace App\Jobs\Concerns;

use App\Services\Notifications\NotificationFailureClassifier;
use Illuminate\Support\Facades\Log;
use Throwable;

trait RetriesNotificationDelivery
{
    public int $tries = 4;

    public int $timeout = 60;

    /** @return list<int> */
    public function backoff(): array
    {
        return [30, 120, 300];
    }

    protected function handleDeliveryException(Throwable $exception): void
    {
        if (app(NotificationFailureClassifier::class)->isPermanent($exception) && $this->job !== null) {
            $this->fail($exception);

            return;
        }

        throw $exception;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('Job de notificacion agotado o fallido permanentemente.', [
            'job' => static::class,
            'exception' => $exception === null ? null : $exception::class,
        ]);
    }
}
