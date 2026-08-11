<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesNotificationDelivery;
use App\Models\MerchandiseRequest;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessMerchandiseRequestSubmittedNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RetriesNotificationDelivery;
    use SerializesModels;

    public function __construct(
        public readonly int $merchandiseRequestId,
    ) {}

    public function handle(MerchandiseRequestNotificationService $notificationService): void
    {
        $merchandiseRequest = MerchandiseRequest::query()
            ->with(['client', 'requestedBy.role', 'lines.item'])
            ->find($this->merchandiseRequestId);

        if ($merchandiseRequest === null || $merchandiseRequest->isDraft()) {
            return;
        }

        try {
            $notificationService->deliverSubmittedNotifications($merchandiseRequest);
        } catch (Throwable $exception) {
            $this->handleDeliveryException($exception);
        }
    }
}
