<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\StockPallet;
use App\Support\Stock\StockBatchCalculator;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class GoodsReceiptStockApplicationService
{
    public function __construct(
        private readonly GoodsReceiptItemResolver $itemResolver,
    ) {}

    /**
     * Applies the stock impact of a confirmed receipt.
     * Must be called inside a DB transaction.
     */
    public function apply(GoodsReceipt $receipt): void
    {
        $receipt->loadMissing([
            'lines.item',
            'lines.location',
        ]);

        foreach ($receipt->lines as $line) {
            $this->applyLine($receipt, $line);
        }
    }

    /**
     * Reverts the stock impact of a previously confirmed receipt (its current
     * lines, before any further changes). Must be called inside a DB
     * transaction. Callers are responsible for checking hasStockApplied().
     */
    public function revert(GoodsReceipt $receipt): void
    {
        if ($receipt->status !== GoodsReceipt::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'goods_receipt' => 'La entrada tiene trazas de stock aplicado, pero no esta confirmada. Revisa el estado antes de continuar.',
            ]);
        }

        $receipt->loadMissing(['lines.item']);

        // The direct FK is the reliable link between this receipt and the stock
        // batches it generated (see resolveTargetBatch() below). Matching by
        // client/item/location/lot heuristics instead is fragile: a null
        // location_id, a manually edited batch, or a later consolidation
        // breaks the match and blocks reversion even when it is perfectly safe.
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
        // manual edit of the batch (location, lot...) does not falsely block reversion.
        $target = $candidateBatches->first(function (StockPallet $batch) use ($line): bool {
            return (int) $batch->location_id === (int) $line->location_id
                && (string) $batch->lot === (string) $line->lot
                && (int) $batch->units_per_pallet === (int) $line->units_per_pallet;
        }) ?? $candidateBatches->sortByDesc('quantity_units')->first();

        $availableUnits = (int) $target->quantity_units;

        if ($availableUnits < $quantityUnits) {
            throw ValidationException::withMessages([
                'goods_receipt' => "No se puede continuar: revertir la linea de {$itemLabel} dejaria stock negativo (disponible {$availableUnits}, se necesitan {$quantityUnits}).",
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

    private function applyLine(GoodsReceipt $receipt, GoodsReceiptLine $line): void
    {
        $item = $this->resolveItem($receipt, $line);
        $unitsPerPallet = (int) ($line->units_per_pallet ?? $item->units_per_pallet);
        $quantityUnits = (int) $line->quantity_units;

        if ($unitsPerPallet <= 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => "La linea {$line->id} necesita unidades por pallet para generar stock.",
            ]);
        }

        if ($quantityUnits <= 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => "La linea {$line->id} necesita una cantidad mayor que cero para confirmarse.",
            ]);
        }

        [$fullPallets, $peakUnits] = $this->splitLine($line, $unitsPerPallet, $quantityUnits);
        $picoUnits = array_sum($peakUnits);

        if ($fullPallets === 0 && $picoUnits === 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => "La linea {$line->id} no genera una partida valida.",
            ]);
        }

        $stockPallet = $this->resolveTargetBatch($receipt, $item, $line, $unitsPerPallet);
        $nextQuantityUnits = (int) ($stockPallet->quantity_units ?? 0) + $quantityUnits;

        $stockPeaks = $this->mergedStockPeaks($stockPallet, $peakUnits, $line);

        $stockPallet->fill([
            'client_id' => $receipt->client_id,
            'item_id' => $item->id,
            'goods_receipt_id' => $receipt->id,
            'location_id' => $line->location_id,
            'location_text' => $line->location?->code,
            'lot' => $line->lot,
            'units_per_pallet' => $unitsPerPallet,
            'received_at' => $receipt->received_at,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
            'notes' => $line->notes,
            'quantity_units' => $nextQuantityUnits,
            ...$this->peakAttributes($stockPeaks),
        ]);
        $stockPallet->save();

        $line->forceFill([
            'item_id' => $item->id,
            'sku' => $line->sku ?? $item->sku,
            'description' => $line->description ?? $item->description,
            'lot' => $line->lot,
            'units_per_pallet' => $unitsPerPallet,
            'pallet_count' => $fullPallets,
            'pico_units' => $picoUnits > 0 ? $picoUnits : null,
            ...$this->peakAttributes($peakUnits, null),
        ])->save();
    }

    private function resolveTargetBatch(GoodsReceipt $receipt, Item $item, GoodsReceiptLine $line, int $unitsPerPallet): StockPallet
    {
        $query = StockPallet::query()
            ->where('client_id', $receipt->client_id)
            ->where('item_id', $item->id)
            ->where('location_id', $line->location_id)
            ->where('lot', $line->lot)
            ->where('units_per_pallet', $unitsPerPallet)
            ->where('active', true)
            ->where('status', StockPallet::STATUS_AVAILABLE)
            ->where(function ($query) use ($receipt): void {
                $query
                    ->whereNull('goods_receipt_id')
                    ->orWhere('goods_receipt_id', $receipt->id);
            })
            ->lockForUpdate()
            ->orderByDesc('id');

        $existing = $query->first();

        if ($existing instanceof StockPallet) {
            return $existing;
        }

        return new StockPallet([
            'pallet_code' => null,
            'quantity_units' => 0,
            'full_pallets' => 0,
            'peaks_count' => 0,
            'peak_1' => 0,
            'peak_2' => 0,
            'peak_3' => 0,
            'peak_4' => 0,
            'peak_5' => 0,
            'peak_6' => 0,
            'peak_7' => 0,
            'peak_8' => 0,
            'peak_9' => 0,
            'peak_10' => 0,
        ]);
    }

    private function resolveItem(GoodsReceipt $receipt, GoodsReceiptLine $line): Item
    {
        return $this->itemResolver->resolveForLine($receipt, $line);
    }

    /**
     * @return array{0: int, 1: list<int>}
     */
    private function splitLine(GoodsReceiptLine $line, int $unitsPerPallet, int $quantityUnits): array
    {
        $palletCount = (int) $line->pallet_count;
        $peakUnits = $line->peakUnits();
        $picoUnits = array_sum($peakUnits);

        if ($palletCount > 0 || $peakUnits !== [] || $line->pico_units !== null) {
            $computedTotal = ($palletCount * $unitsPerPallet) + $picoUnits;

            if ($computedTotal !== $quantityUnits) {
                throw ValidationException::withMessages([
                    'goods_receipt' => "La linea {$line->id} no cuadra entre cantidad total, pallets completos y pico.",
                ]);
            }

            return [$palletCount, $peakUnits];
        }

        $remainder = StockBatchCalculator::calculateRemainderPeak($quantityUnits, $unitsPerPallet);

        return [
            StockBatchCalculator::calculateFullPallets($quantityUnits, $unitsPerPallet),
            $remainder > 0 ? [$remainder] : [],
        ];
    }

    /** @param list<int> $incomingPeaks
     * @return list<int>
     */
    private function mergedStockPeaks(StockPallet $stockPallet, array $incomingPeaks, GoodsReceiptLine $line): array
    {
        $existingPeaks = collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
            ->map(fn (int $number): int => (int) ($stockPallet->{'peak_'.$number} ?? 0))
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();
        $peaks = array_values(array_merge($existingPeaks, $incomingPeaks));

        if (count($peaks) > StockPallet::MAX_PEAK_COLUMNS) {
            throw ValidationException::withMessages([
                'goods_receipt' => "La linea {$line->id} supera el maximo de 10 picos para una misma partida de stock.",
            ]);
        }

        return $peaks;
    }

    /** @param list<int> $peaks
     * @return array<string, int|null>
     */
    private function peakAttributes(array $peaks, ?int $emptyValue = 0): array
    {
        return collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
            ->mapWithKeys(fn (int $number): array => [
                'peak_'.$number => $peaks[$number - 1] ?? $emptyValue,
            ])
            ->all();
    }
}
