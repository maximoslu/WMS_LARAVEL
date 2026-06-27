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

class ProcessMerchandiseRequestStatusChangedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $merchandiseRequestId,
        public readonly string $previousStatus,
    ) {}

    public function handle(MerchandiseRequestNotificationService $notificationService): void
    {
        $merchandiseRequest = MerchandiseRequest::query()
            ->with(['client', 'requestedBy', 'lines.item', 'dispatch.lines.item', 'client.users.role'])
            ->find($this->merchandiseRequestId);

        if ($merchandiseRequest === null) {
            return;
        }

        try {
            $notificationService->deliverStatusChangedNotification($merchandiseRequest, $this->previousStatus);
        } catch (Throwable $exception) {
            Log::warning('Fallo al procesar notificacion de cambio de estado de solicitud.', [
                'merchandise_request_id' => $this->merchandiseRequestId,
                'previous_status' => $this->previousStatus,
                'current_status' => $merchandiseRequest->status,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
