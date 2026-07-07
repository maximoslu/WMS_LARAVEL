<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\StockPallet;
use App\Models\User;
use Illuminate\Support\Collection;
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

        // The direct FK is the reliable link between this receipt and the stock
        // batches it generated (see GoodsReceiptStockApplicationService::resolveTargetBatch).
        // Matching by client/item/location/lot heuristics instead is fragile: a
        // null location_id, a manually edited batch, or a later consolidation
        // breaks the match and blocks deletion even when reverting is perfectly safe.
        $batchesByItem = StockPallet::query()
            ->where('goods_receipt_id', $receipt->id)
            ->lockForUpdate()
            ->get()
            ->groupBy('item_id');

        foreach ($receipt->lines as $line) {
            $this->revertLine($line, $batchesByItem->get($line->item_id, collect()));
        }
    }

    private function revertLine(GoodsReceiptLine $line, Collection $candidateBatches): void
    {
        $item = $line->item;

        if (! $item instanceof Item) {
            $item = Item::query()->find($line->item_id);
        }

        $itemLabel = $item?->sku ?? $line->sku ?? "linea {$line->id}";

        $quantityUnits = (int) $line->quantity_units;

        if ($quantityUnits <= 0) {
            // Nothing was ever added to stock for this line; there is nothing to revert.
            return;
        }

        if ($candidateBatches->isEmpty()) {
            throw ValidationException::withMessages([
                'goods_receipt' => "No se puede revertir el stock de {$itemLabel}: no queda ninguna partida generada por esta entrada (es probable que ya se haya movido o enviado).",
            ]);
        }

        // Prefer the batch that matches the line's own location/lot/units_per_pallet
        // exactly. Fall back to the batch with the most remaining quantity so a
        // manual edit of the batch (location, lot...) does not falsely block deletion.
        $target = $candidateBatches->first(function (StockPallet $batch) use ($line): bool {
            return (int) $batch->location_id === (int) $line->location_id
                && (string) $batch->lot === (string) $line->lot
                && (int) $batch->units_per_pallet === (int) $line->units_per_pallet;
        }) ?? $candidateBatches->sortByDesc('quantity_units')->first();

        $availableUnits = (int) $target->quantity_units;

        if ($availableUnits < $quantityUnits) {
            throw ValidationException::withMessages([
                'goods_receipt' => "No se puede borrar la entrada: revertir la linea de {$itemLabel} dejaria stock negativo (disponible {$availableUnits}, se necesitan {$quantityUnits}).",
            ]);
        }

        $remainingUnits = $availableUnits - $quantityUnits;

        if ($remainingUnits <= 0) {
            $target->delete();
            // Keep the in-memory attribute in sync so a later line for the same
            // item never picks this (now deleted) batch again via the fallback.
            $target->quantity_units = 0;

            return;
        }

        $target->fill(['quantity_units' => $remainingUnits])->save();
    }
}
