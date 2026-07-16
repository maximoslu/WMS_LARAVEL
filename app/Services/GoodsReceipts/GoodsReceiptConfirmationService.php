<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptConfirmationService
{
    public function __construct(
        private readonly GoodsReceiptStockApplicationService $stockApplicationService,
        private readonly AuditLogService $audit,
    ) {}

    public function confirm(GoodsReceipt $receipt, User $user): GoodsReceipt
    {
        if ($receipt->status === GoodsReceipt::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'goods_receipt' => 'La entrada ya esta confirmada y no puede generar stock dos veces.',
            ]);
        }

        if (! in_array($receipt->status, [GoodsReceipt::STATUS_DRAFT, GoodsReceipt::STATUS_PENDING_REVIEW], true)) {
            throw ValidationException::withMessages([
                'goods_receipt' => 'Solo se pueden confirmar entradas en borrador o pendientes de revision.',
            ]);
        }

        return DB::transaction(function () use ($receipt, $user): GoodsReceipt {
            $receipt = GoodsReceipt::query()
                ->whereKey($receipt->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($receipt->status === GoodsReceipt::STATUS_CONFIRMED || $receipt->hasStockApplied()) {
                throw ValidationException::withMessages([
                    'goods_receipt' => 'La entrada ya esta confirmada y no puede generar stock dos veces.',
                ]);
            }

            if (! in_array($receipt->status, [GoodsReceipt::STATUS_DRAFT, GoodsReceipt::STATUS_PENDING_REVIEW], true)) {
                throw ValidationException::withMessages([
                    'goods_receipt' => 'Solo se pueden confirmar entradas en borrador o pendientes de revision.',
                ]);
            }

            $correlationId = $this->audit->correlationId();
            $receipt->loadMissing([
                'client',
                'lines.item',
                'lines.location',
            ]);

            if ($receipt->lines->isEmpty()) {
                throw ValidationException::withMessages([
                    'goods_receipt' => 'La entrada debe tener al menos una linea antes de confirmarse.',
                ]);
            }

            if (! $receipt->hasStockApplied()) {
                $this->stockApplicationService->apply($receipt, $user, $correlationId);
            }

            $receipt->forceFill([
                'status' => GoodsReceipt::STATUS_CONFIRMED,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
                'stock_applied_at' => $receipt->stock_applied_at ?? now(),
                'stock_applied_by' => $receipt->stock_applied_by ?? $user->id,
            ])->save();

            $this->audit->record(
                event: 'goods_receipt_confirmed',
                module: 'goods_receipts',
                description: 'Entrada confirmada y stock aplicado.',
                auditable: $receipt,
                user: $user,
                clientId: $receipt->client_id,
                newValues: ['status' => GoodsReceipt::STATUS_CONFIRMED, 'stock_applied_at' => $receipt->stock_applied_at],
                correlationId: $correlationId,
            );

            return $receipt->refresh();
        });
    }
}
