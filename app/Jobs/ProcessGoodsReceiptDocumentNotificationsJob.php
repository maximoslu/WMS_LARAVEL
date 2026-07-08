<?php

namespace App\Jobs;

use App\Models\GoodsReceipt;
use App\Services\GoodsReceipts\GoodsReceiptDocumentNotificationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

class ProcessGoodsReceiptDocumentNotificationsJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
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
            Log::warning('Fallo al procesar notificaciones de albaran de entrada.', [
                'goods_receipt_id' => $this->goodsReceiptId,
                'message' => $exception->getMessage(),
            ]);
        }
    }
}
