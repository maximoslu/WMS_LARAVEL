<?php

namespace App\Jobs;

use App\Models\MerchandiseRequest;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessMerchandiseRequestSubmittedNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $merchandiseRequestId,
    ) {}

    public function handle(MerchandiseRequestNotificationService $notificationService): void
    {
        $merchandiseRequest = MerchandiseRequest::query()
            ->with(['client', 'requestedBy', 'lines.item'])
            ->find($this->merchandiseRequestId);

        if ($merchandiseRequest === null) {
            return;
        }

        try {
            $notificationService->deliverSubmittedNotifications($merchandiseRequest);
        } catch (Throwable $exception) {
            Log::warning('Fallo al procesar notificaciones de nueva solicitud de mercancia.', [
                'merchandise_request_id' => $this->merchandiseRequestId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
