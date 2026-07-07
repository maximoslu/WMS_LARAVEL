<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;

class GoodsReceiptDeletionService
{
    public function __construct(
        private readonly GoodsReceiptStockApplicationService $stockApplicationService,
    ) {}

    public function delete(GoodsReceipt $receipt, User $user): void
    {
        DB::transaction(function () use ($receipt, $user): void {
            $receipt->loadMissing([
                'client',
                'supplier',
                'lines.item',
            ]);

            if ($receipt->hasStockApplied()) {
                $this->stockApplicationService->revert($receipt);
            }

            logger()->warning('goods_receipt_deleted', [
                'goods_receipt_id' => $receipt->id,
                'receipt_number' => $receipt->receipt_number,
                'client_id' => $receipt->client_id,
                'deleted_by' => $user->id,
                'had_stock_applied' => $receipt->hasStockApplied(),
            ]);

            $receipt->lines()->delete();
            $receipt->delete();
        });
    }
}
