<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\StockPallet;
use App\Models\User;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Stock\LotNormalizer;
use App\Support\Stock\StockBatchCalculator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptStockApplicationService
{
    public function __construct(
        private readonly GoodsReceiptItemResolver $itemResolver,
        private readonly InventoryMovementService $movements,
    ) {}

    /**
     * Applies the stock impact of a confirmed receipt.
     * Must be called inside a DB transaction.
     */
    public function apply(GoodsReceipt $receipt, User $user, string $correlationId): void
    {
        $receipt->loadMissing([
            'lines.item',
            'lines.location',
        ]);

        foreach ($receipt->lines as $line) {
            $this->applyLine($receipt, $line, $user, $correlationId);
        }
    }

    /**
     * Reverts the stock impact of a previously confirmed receipt (its current
     * lines, before any further changes). Must be called inside a DB
     * transaction. Callers are responsible for checking hasStockApplied().
     */
    public function revert(GoodsReceipt $receipt, User $user, string $correlationId): void
    {
        if ($receipt->status !== GoodsReceipt::STATUS_CONFIRMED) {
            throw ValidationException::withMessages([
                'goods_receipt' => 'La entrada tiene trazas de stock aplicado, pero no esta confirmada. Revisa el estado antes de continuar.',
            ]);
        }

        $receipt->loadMissing(['lines.item']);

        $receiptMovements = InventoryMovement::query()
            ->where('source_type', $receipt->getMorphClass())
            ->where('source_id', $receipt->id)
            ->where('movement_type', InventoryMovement::RECEIPT)
            ->whereIn('source_line_id', $receipt->lines->pluck('id'))
            ->latest('id')
            ->lockForUpdate()
            ->get()
            ->groupBy('source_line_id');

        // The movement ledger is authoritative once a receipt has been applied:
        // a consolidated batch can contain several receipts, so its direct FK
        // cannot identify the contribution that must be reverted. The FK query
        // below remains a compatibility fallback for legacy rows without a
        // receipt movement.
        $batchesByItem = StockPallet::query()
            ->where('goods_receipt_id', $receipt->id)
            ->lockForUpdate()
            ->get()
            ->groupBy('item_id');

        foreach ($receipt->lines as $line) {
            $movement = $receiptMovements->get($line->id)?->first();

            if ($movement instanceof InventoryMovement) {
                $this->revertMovement($receipt, $line, $movement, $user, $correlationId);

                continue;
            }

            $this->revertLine($receipt, $line, $batchesByItem->get($line->item_id, collect()), $user, $correlationId);
        }
    }

    private function revertMovement(
        GoodsReceipt $receipt,
        GoodsReceiptLine $line,
        InventoryMovement $movement,
        User $user,
        string $correlationId,
    ): void {
        $stockPallet = StockPallet::query()
            ->whereKey($movement->stock_pallet_id)
            ->lockForUpdate()
            ->first();

        if (! $stockPallet instanceof StockPallet) {
            throw ValidationException::withMessages([
                'goods_receipt' => "No se puede revertir la linea {$line->id}: la partida consolidada ya no existe.",
            ]);
        }

        $quantityUnits = (int) $movement->units_delta;
        $warehousePallets = (float) $movement->warehouse_pallets_delta;

        if ($quantityUnits <= 0 || $warehousePallets < 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => "No se puede revertir la linea {$line->id}: el movimiento de entrada no tiene deltas validos.",
            ]);
        }

        $availableUnits = (int) $stockPallet->quantity_units;
        $availableWarehousePallets = (float) ($stockPallet->warehouse_pallets ?? 0);

        if ($availableUnits < $quantityUnits || $availableWarehousePallets + 0.0001 < $warehousePallets) {
            throw ValidationException::withMessages([
                'goods_receipt' => "No se puede continuar: revertir la linea {$line->id} dejaria stock negativo.",
            ]);
        }

        $before = $this->movements->snapshot($stockPallet);
        $remainingUnits = $availableUnits - $quantityUnits;
        $remainingWarehousePallets = max(0, $availableWarehousePallets - $warehousePallets);
        $remainingPeaks = $this->subtractPeakDelta($stockPallet, $movement);

        if ($remainingUnits <= 0 && $remainingWarehousePallets <= 0) {
            $stockPallet->delete();
            $stockPallet->quantity_units = 0;

            $this->recordReversal($receipt, $line, $stockPallet, $before, $user, $correlationId, $movement->id);

            return;
        }

        $stockPallet->forceFill([
            'quantity_units' => $remainingUnits,
            'warehouse_pallets' => $remainingWarehousePallets,
            ...$this->peakAttributes($remainingPeaks),
        ])->save();

        $this->recordReversal($receipt, $line, $stockPallet, $before, $user, $correlationId, $movement->id);
    }

    /** @return list<int> */
    private function subtractPeakDelta(StockPallet $stockPallet, InventoryMovement $movement): array
    {
        $deltas = is_array($movement->peaks_delta) ? array_values($movement->peaks_delta) : [];

        return collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
            ->map(function (int $number) use ($stockPallet, $deltas): int {
                $current = (int) ($stockPallet->{'peak_'.$number} ?? 0);
                $delta = max(0, (int) ($deltas[$number - 1] ?? 0));

                if ($current < $delta) {
                    throw ValidationException::withMessages([
                        'goods_receipt' => 'No se puede revertir la entrada porque uno de sus picos ya no esta disponible.',
                    ]);
                }

                return $current - $delta;
            })
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();
    }

    private function revertLine(
        GoodsReceipt $receipt,
        GoodsReceiptLine $line,
        Collection $candidateBatches,
        User $user,
        string $correlationId,
    ): void {
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
                && LotNormalizer::normalize($batch->lot) === LotNormalizer::normalize($line->lot)
                && (int) $batch->units_per_pallet === (int) $line->units_per_pallet;
        }) ?? $candidateBatches->sortByDesc('quantity_units')->first();

        $availableUnits = (int) $target->quantity_units;

        if ($availableUnits < $quantityUnits) {
            throw ValidationException::withMessages([
                'goods_receipt' => "No se puede continuar: revertir la linea de {$itemLabel} dejaria stock negativo (disponible {$availableUnits}, se necesitan {$quantityUnits}).",
            ]);
        }

        $remainingUnits = $availableUnits - $quantityUnits;
        $before = $this->movements->snapshot($target);

        if ($remainingUnits <= 0) {
            $target->delete();
            // Keep the in-memory attribute in sync so a later line for the same
            // item never picks this (now deleted) batch again via the fallback.
            $target->quantity_units = 0;

            $this->recordReversal($receipt, $line, $target, $before, $user, $correlationId);

            return;
        }

        $target->fill(['quantity_units' => $remainingUnits])->save();
        $this->recordReversal($receipt, $line, $target, $before, $user, $correlationId);
    }

    private function applyLine(GoodsReceipt $receipt, GoodsReceiptLine $line, User $user, string $correlationId): void
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
        $before = $this->movements->snapshot($stockPallet);
        $nextQuantityUnits = (int) ($stockPallet->quantity_units ?? 0) + $quantityUnits;
        $existingWarehousePallets = $stockPallet->exists
            ? (float) ($stockPallet->warehouse_pallets ?? ((int) $stockPallet->full_pallets + (int) $stockPallet->peaks_count))
            : 0.0;
        $nextWarehousePallets = $existingWarehousePallets + $fullPallets + count($peakUnits);

        $stockPeaks = $this->mergedStockPeaks($stockPallet, $peakUnits, $line);

        $stockPallet->fill([
            'client_id' => $receipt->client_id,
            'item_id' => $item->id,
            'goods_receipt_id' => $stockPallet->goods_receipt_id
                ?? ($stockPallet->stock_import_id === null ? $receipt->id : null),
            'location_id' => $line->location_id,
            'location_text' => $line->location?->code,
            'lot' => LotNormalizer::normalize($line->lot),
            'units_per_pallet' => $unitsPerPallet,
            'received_at' => $receipt->received_at,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
            'warehouse_pallets' => $nextWarehousePallets,
            'notes' => $line->notes,
            'quantity_units' => $nextQuantityUnits,
            ...$this->peakAttributes($stockPeaks),
        ]);
        $stockPallet->save();
        $after = $this->movements->snapshot($stockPallet->fresh(['client', 'item', 'location.warehouse']));

        $this->movements->record(
            before: $before,
            after: $after,
            movementType: InventoryMovement::RECEIPT,
            idempotencyKey: "receipt:{$receipt->id}:line:{$line->id}:stock:{$stockPallet->id}:{$correlationId}",
            correlationId: $correlationId,
            source: $receipt,
            sourceLine: $line,
            user: $user,
            effectiveAt: $receipt->received_at?->copy()->startOfDay(),
            metadata: ['receipt_number' => $receipt->receipt_number],
        );

        $line->forceFill([
            'item_id' => $item->id,
            'sku' => $line->sku ?? $item->sku,
            'description' => $line->description ?? $item->description,
            'lot' => LotNormalizer::normalize($line->lot),
            'units_per_pallet' => $unitsPerPallet,
            'pallet_count' => $fullPallets,
            'pico_units' => $picoUnits > 0 ? $picoUnits : null,
            ...$this->peakAttributes($peakUnits, null),
        ])->save();
    }

    /** @param array<string, mixed> $before */
    private function recordReversal(
        GoodsReceipt $receipt,
        GoodsReceiptLine $line,
        StockPallet $stockPallet,
        array $before,
        User $user,
        string $correlationId,
        ?int $reversalOfId = null,
    ): void {
        $after = $this->movements->snapshot($stockPallet->exists
            ? $stockPallet->fresh(['client', 'item', 'location.warehouse'])
            : null);
        $after = [
            ...$before,
            ...$after,
            'client_id' => $before['client_id'],
            'item_id' => $before['item_id'],
            'stock_pallet_id' => $before['stock_pallet_id'],
        ];

        if (! $stockPallet->exists) {
            $after['units'] = 0;
            $after['full_pallets'] = 0;
            $after['warehouse_pallets'] = 0;
            $after['peaks'] = array_fill(0, StockPallet::MAX_PEAK_COLUMNS, 0);
            $after['active'] = false;
        }

        $this->movements->record(
            before: $before,
            after: $after,
            movementType: InventoryMovement::REVERSAL,
            idempotencyKey: "receipt-reversal:{$receipt->id}:line:{$line->id}:stock:{$before['stock_pallet_id']}:{$correlationId}",
            correlationId: $correlationId,
            source: $receipt,
            sourceLine: $line,
            user: $user,
            metadata: ['reason' => 'Reversion de entrada confirmada antes de editar o eliminar.'],
            reversalOfId: $reversalOfId,
        );
    }

    private function resolveTargetBatch(GoodsReceipt $receipt, Item $item, GoodsReceiptLine $line, int $unitsPerPallet): StockPallet
    {
        $query = StockPallet::query()
            ->where('client_id', $receipt->client_id)
            ->where('item_id', $item->id)
            ->where('location_id', $line->location_id)
            ->where('lot', LotNormalizer::normalize($line->lot))
            ->where('units_per_pallet', $unitsPerPallet)
            ->where(function ($query) use ($item): void {
                $query
                    ->where('stock_category', $item->stock_category ?? StockPallet::CATEGORY_IN_USE)
                    ->orWhereNull('stock_category');
            })
            ->where('active', true)
            ->where('status', StockPallet::STATUS_AVAILABLE)
            ->lockForUpdate()
            ->orderByDesc('id');

        $existing = $query->get();

        if ($existing->count() > 1) {
            return $this->consolidateTargetBatches($existing);
        }

        if ($existing->isNotEmpty()) {
            return $existing->first();
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

    /** @param Collection<int, StockPallet> $batches */
    private function consolidateTargetBatches(Collection $batches): StockPallet
    {
        $batches = $batches->sortBy('id')->values();

        /** @var StockPallet $keeper */
        $keeper = $batches->first();
        $duplicateIds = $batches->slice(1)->pluck('id')->all();
        $peaks = $batches
            ->flatMap(fn (StockPallet $batch): Collection => collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
                ->map(fn (int $number): int => (int) ($batch->{'peak_'.$number} ?? 0))
                ->filter(fn (int $value): bool => $value > 0))
            ->values()
            ->all();

        if (count($peaks) > StockPallet::MAX_PEAK_COLUMNS) {
            throw ValidationException::withMessages([
                'goods_receipt' => 'No se pueden consolidar las partidas compatibles porque superan el maximo de 10 picos.',
            ]);
        }

        $keeper->forceFill([
            'pallet_code' => null,
            'quantity_units' => (int) $batches->sum(fn (StockPallet $batch): int => (int) $batch->quantity_units),
            'warehouse_pallets' => (float) $batches->sum(fn (StockPallet $batch): float => (float) ($batch->warehouse_pallets ?? 0)),
            ...$this->peakAttributes($peaks),
        ])->save();

        if ($duplicateIds !== []) {
            DB::table('inventory_movements')
                ->whereIn('stock_pallet_id', $duplicateIds)
                ->update(['stock_pallet_id' => $keeper->id]);

            StockPallet::query()
                ->whereIn('id', $duplicateIds)
                ->update([
                    'active' => false,
                    'status' => StockPallet::STATUS_OBSOLETE,
                    'blocked_reason' => 'Partida consolidada en la partida #'.$keeper->id,
                    'notes' => 'Historica: consolidada en la partida #'.$keeper->id,
                    'updated_at' => now(),
                ]);
        }

        return $keeper->fresh(['client', 'item', 'location.warehouse']);
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
