<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;

class GoodsReceiptDeletionService
{
    public function __construct(
        private readonly GoodsReceiptStockApplicationService $stockApplicationService,
        private readonly AuditLogService $audit,
    ) {}

    public function delete(GoodsReceipt $receipt, User $user): void
    {
        DB::transaction(function () use ($receipt, $user): void {
            $correlationId = $this->audit->correlationId();
            $receipt->loadMissing([
                'client',
                'supplier',
                'lines.item',
            ]);

            if ($receipt->hasStockApplied()) {
                $this->stockApplicationService->revert($receipt, $user, $correlationId);
            }

            $this->audit->record(
                event: 'goods_receipt_deleted',
                module: 'goods_receipts',
                description: 'Entrada eliminada de forma autorizada.',
                auditable: $receipt,
                user: $user,
                clientId: $receipt->client_id,
                oldValues: $receipt->only(['receipt_number', 'status', 'received_at', 'supplier_id']),
                correlationId: $correlationId,
                severity: 'warning',
            );

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
