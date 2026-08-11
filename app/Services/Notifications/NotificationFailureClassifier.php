<?php

namespace App\Services\Notifications;

use App\Exceptions\BrevoMailConfigurationException;
use App\Exceptions\PermanentNotificationDeliveryException;
use App\Exceptions\TransientNotificationDeliveryException;
use Illuminate\Validation\ValidationException;
use Illuminate\View\ViewException;
use InvalidArgumentException;
use Throwable;

class NotificationFailureClassifier
{
    public function isPermanent(Throwable $exception): bool
    {
        if ($exception instanceof TransientNotificationDeliveryException) {
            return false;
        }

        return $exception instanceof PermanentNotificationDeliveryException
            || $exception instanceof BrevoMailConfigurationException
            || $exception instanceof InvalidArgumentException
            || $exception instanceof ValidationException
            || $exception instanceof ViewException
            || preg_match('/\b5\d{2}\b/', $exception->getMessage()) === 1;
    }
}
