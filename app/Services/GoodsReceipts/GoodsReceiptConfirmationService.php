<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptConfirmationService
{
    public function __construct(
        private readonly GoodsReceiptStockApplicationService $stockApplicationService,
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
                $this->stockApplicationService->apply($receipt);
            }

            $receipt->forceFill([
                'status' => GoodsReceipt::STATUS_CONFIRMED,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
                'stock_applied_at' => $receipt->stock_applied_at ?? now(),
                'stock_applied_by' => $receipt->stock_applied_by ?? $user->id,
            ])->save();

            return $receipt->refresh();
        });
    }
}
