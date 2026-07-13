<?php

namespace App\Jobs;

use App\Models\GoodsDispatch;
use App\Models\MerchandiseRequest;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessGoodsDispatchStatusChangedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(
        public readonly int $goodsDispatchId,
        public readonly ?int $merchandiseRequestId,
        public readonly string $previousRequestStatus,
        public readonly string $currentStatus,
    ) {}

    public function handle(MerchandiseRequestNotificationService $notificationService): void
    {
        $dispatch = GoodsDispatch::query()
            ->with([
                'client',
                'lines.item',
                'merchandiseRequest.client',
                'merchandiseRequest.requestedBy',
                'merchandiseRequest.lines.item',
                'merchandiseRequest.client.users.role',
                'merchandiseRequest.client.dispatchEmailRecipients',
            ])
            ->find($this->goodsDispatchId);

        if ($dispatch === null || $dispatch->merchandiseRequest === null) {
            return;
        }

        try {
            if (
                in_array($this->currentStatus, [MerchandiseRequest::STATUS_SENT, MerchandiseRequest::STATUS_COMPLETED], true)
                && $dispatch->delivery_note_sent_at === null
            ) {
                $notificationService->sendDeliveryNoteToClient($dispatch, $this->currentStatus);

                if ($this->currentStatus === MerchandiseRequest::STATUS_COMPLETED) {
                    return;
                }
            }

            if ($this->currentStatus !== MerchandiseRequest::STATUS_SENT) {
                $notificationService->deliverStatusChangedNotification(
                    $dispatch->merchandiseRequest,
                    $this->previousRequestStatus
                );
            }
        } catch (Throwable $exception) {
            Log::warning('Fallo al procesar cambio de estado de salida.', [
                'dispatch_id' => $this->goodsDispatchId,
                'merchandise_request_id' => $this->merchandiseRequestId,
                'previous_request_status' => $this->previousRequestStatus,
                'current_status' => $this->currentStatus,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
