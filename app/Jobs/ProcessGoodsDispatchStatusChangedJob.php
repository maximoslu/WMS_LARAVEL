<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesNotificationDelivery;
use App\Models\GoodsDispatch;
use App\Models\MerchandiseRequest;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessGoodsDispatchStatusChangedJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RetriesNotificationDelivery;
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
                $this->currentStatus === MerchandiseRequest::STATUS_COMPLETED
                && $dispatch->delivery_note_sent_at === null
            ) {
                $notificationService->sendDeliveryNoteToClient($dispatch, $this->currentStatus);
            }
        } catch (Throwable $exception) {
            $this->handleDeliveryException($exception);
        }
    }
}
