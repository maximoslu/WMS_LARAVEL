<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\StockPallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptDeletionService
{
    public function delete(GoodsReceipt $receipt, User $user): void
    {
        DB::transaction(function () use ($receipt, $user): void {
            $receipt->loadMissing([
                'client',
                'supplier',
                'lines.item',
                'lines.location',
            ]);

            if ($receipt->hasStockApplied()) {
                $this->revertAppliedStock($receipt);
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

    private function revertAppliedStock(GoodsReceipt $receipt): void
    {
        if ($receipt->status !== GoodsReceipt::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'goods_receipt' => 'La entrada tiene trazas de stock aplicado, pero no esta confirmada. Revisa el estado antes de borrarla.',
            ]);
        }

        foreach ($receipt->lines as $line) {
            $this->revertLine($receipt, $line);
        }
    }

    private function revertLine(GoodsReceipt $receipt, GoodsReceiptLine $line): void
    {
        $item = $line->item;

        if (! $item instanceof Item) {
            $item = Item::query()
                ->where('client_id', $receipt->client_id)
                ->where('sku', $line->sku)
                ->first();
        }

        if (! $item instanceof Item) {
            throw ValidationException::withMessages([
                'goods_receipt' => "No se puede revertir la linea {$line->id} porque su articulo ya no existe.",
            ]);
        }

        $unitsPerPallet = (int) ($line->units_per_pallet ?? $item->units_per_pallet ?? 0);
        $quantityUnits = (int) $line->quantity_units;

        if ($unitsPerPallet <= 0 || $quantityUnits <= 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => "La linea {$line->id} no tiene datos suficientes para revertir el stock de forma segura.",
            ]);
        }

        $batches = StockPallet::query()
            ->where('client_id', $receipt->client_id)
            ->where('item_id', $item->id)
            ->where('location_id', $line->location_id)
            ->where('lot', $line->lot)
            ->where('units_per_pallet', $unitsPerPallet)
            ->where('active', true)
            ->lockForUpdate()
            ->orderByDesc('id')
            ->get();

        $availableUnits = (int) $batches->sum('quantity_units');

        if ($availableUnits < $quantityUnits) {
            throw ValidationException::withMessages([
                'goods_receipt' => "No se puede borrar la entrada porque la reversión de la linea {$line->id} dejaria el stock incoherente.",
            ]);
        }

        $pendingUnits = $quantityUnits;

        foreach ($batches as $batch) {
            if ($pendingUnits <= 0) {
                break;
            }

            $currentUnits = (int) $batch->quantity_units;

            if ($currentUnits <= 0) {
                continue;
            }

            $deductedUnits = min($currentUnits, $pendingUnits);
            $remainingUnits = $currentUnits - $deductedUnits;

            if ($remainingUnits <= 0) {
                $batch->delete();
            } else {
                $batch->fill([
                    'quantity_units' => $remainingUnits,
                ])->save();
            }

            $pendingUnits -= $deductedUnits;
        }
    }
}
