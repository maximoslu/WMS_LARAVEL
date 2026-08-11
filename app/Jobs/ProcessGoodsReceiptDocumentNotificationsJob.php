<?php

namespace App\Jobs;

use App\Jobs\Concerns\RetriesNotificationDelivery;
use App\Models\GoodsReceipt;
use App\Services\GoodsReceipts\GoodsReceiptDocumentNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Throwable;

class ProcessGoodsReceiptDocumentNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use RetriesNotificationDelivery;
    use SerializesModels;

    public function __construct(
        public readonly int $goodsReceiptId,
    ) {}

    public function handle(GoodsReceiptDocumentNotificationService $notificationService): void
    {
        $receipt = GoodsReceipt::query()
            ->with(['client', 'supplier'])
            ->find($this->goodsReceiptId);

        if ($receipt === null) {
            return;
        }

        try {
            $notificationService->deliverDocumentAvailableNotifications($receipt);
        } catch (Throwable $exception) {
            $this->handleDeliveryException($exception);
        }
    }
}
