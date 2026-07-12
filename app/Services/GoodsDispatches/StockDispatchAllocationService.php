<?php

namespace App\Services\GoodsDispatches;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsDispatchLineAllocation;
use App\Models\StockPallet;
use Illuminate\Validation\ValidationException;

class StockDispatchAllocationService
{
    /**
     * Deducts the real loaded quantities of a dispatch from client stock.
     * Must be called inside a DB transaction. Not idempotent by itself —
     * callers must guard on GoodsDispatch::hasStockApplied() before calling.
     */
    public function apply(GoodsDispatch $dispatch): void
    {
        $lines = $dispatch->lines()->with('allocations')->get();

        foreach ($lines as $line) {
            if ($line->allocations->isNotEmpty()) {
                foreach ($line->allocations as $allocation) {
                    $this->applyAllocation($dispatch, $line, $allocation);
                }

                continue;
            }

            $loadedPallets = $line->loadedPallets();
            $loadedPartialUnits = $line->loadedPartialUnits();

            if ($loadedPallets <= 0 && $loadedPartialUnits <= 0) {
                continue;
            }

            if ($loadedPallets > 0) {
                $this->applyPalletLine($dispatch, $line);
            }

            if ($loadedPartialUnits > 0) {
                $this->applyPartialUnits($dispatch, $line, $loadedPartialUnits);
            }
        }
    }

    private function applyAllocation(GoodsDispatch $dispatch, GoodsDispatchLine $line, GoodsDispatchLineAllocation $allocation): void
    {
        $loadedPallets = $allocation->loadedPallets();
        $loadedPartialUnits = $allocation->loadedPartialUnits();

        if ($loadedPallets <= 0 && $loadedPartialUnits <= 0) {
            return;
        }

        $stockPallet = StockPallet::query()
            ->whereKey($allocation->stock_pallet_id)
            ->lockForUpdate()
            ->first();

        if (! $stockPallet instanceof StockPallet) {
            throw $this->unresolvedStockError($line);
        }

        $this->guardSameClient($dispatch, $stockPallet);

        if ($loadedPallets > 0) {
            if ((int) $stockPallet->full_pallets < $loadedPallets) {
                throw $this->insufficientStockError($line);
            }

            $unitsPerPallet = (int) ($stockPallet->units_per_pallet ?: $line->units_per_pallet);
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - ($loadedPallets * $unitsPerPallet));
        }

        if ($loadedPartialUnits > 0) {
            $this->applyPartialUnitsToStockPallet($stockPallet, $line, $loadedPartialUnits, $allocation->selectedPeakUnitsByIndex(), $loadedPallets);
        }

        $stockPallet->save();
    }

    private function applyPartialUnits(GoodsDispatch $dispatch, GoodsDispatchLine $line, int $units): void
    {
        if ($line->stock_pallet_id === null) {
            throw $this->unresolvedStockError($line);
        }

        $stockPallet = StockPallet::query()
            ->whereKey($line->stock_pallet_id)
            ->lockForUpdate()
            ->first();

        if (! $stockPallet instanceof StockPallet) {
            throw $this->unresolvedStockError($line);
        }

        $this->guardSameClient($dispatch, $stockPallet);

        $this->applyPartialUnitsToStockPallet($stockPallet, $line, $units, [], 0);

        $stockPallet->save();
    }

    /**
     * @param  array<int, int>  $selectedPeakUnitsByIndex
     */
    private function applyPartialUnitsToStockPallet(
        StockPallet $stockPallet,
        GoodsDispatchLine $line,
        int $units,
        array $selectedPeakUnitsByIndex = [],
        int $alreadyLoadedPallets = 0,
    ): void {
        $remaining = max(0, $units);

        foreach ($selectedPeakUnitsByIndex as $peakIndex => $selectedUnits) {
            if ($remaining <= 0) {
                break;
            }

            $peakField = 'peak_'.$peakIndex;
            $availableUnits = max(0, (int) $stockPallet->{$peakField});
            $selectedUnits = max(0, (int) $selectedUnits);

            if ($selectedUnits <= 0 || $availableUnits < $selectedUnits) {
                throw $this->insufficientStockError($line);
            }

            $take = min($remaining, $selectedUnits);
            $stockPallet->{$peakField} = $availableUnits - $take;
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - $take);
            $remaining -= $take;
        }

        $peakOrder = $this->peakConsumptionOrder($line, array_keys($selectedPeakUnitsByIndex));

        foreach ($peakOrder as $peakIndex) {
            if ($remaining <= 0) {
                break;
            }

            $peakField = 'peak_'.$peakIndex;
            $availableUnits = max(0, (int) $stockPallet->{$peakField});

            if ($availableUnits <= 0) {
                continue;
            }

            $take = min($remaining, $availableUnits);
            $stockPallet->{$peakField} = $availableUnits - $take;
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - $take);
            $remaining -= $take;
        }

        $breakableFullPallets = max(0, (int) $stockPallet->full_pallets - $alreadyLoadedPallets);

        while ($remaining > 0) {
            if ($breakableFullPallets <= 0) {
                throw $this->insufficientStockError($line);
            }

            $unitsPerPallet = max(0, (int) ($stockPallet->units_per_pallet ?: $line->units_per_pallet));

            if ($unitsPerPallet <= 0) {
                throw $this->insufficientStockError($line);
            }

            $take = min($remaining, $unitsPerPallet);
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - $take);
            $remaining -= $take;
            $breakableFullPallets--;

            if ($take < $unitsPerPallet) {
                $this->storeBrokenPalletRemainder($stockPallet, $line, $unitsPerPallet - $take);
            }
        }
    }

    private function applyPalletLine(GoodsDispatch $dispatch, GoodsDispatchLine $line): void
    {
        $remaining = $line->loadedPallets();

        if ($line->stock_pallet_id !== null) {
            $stockPallet = StockPallet::query()
                ->whereKey($line->stock_pallet_id)
                ->lockForUpdate()
                ->first();

            if (! $stockPallet instanceof StockPallet) {
                throw $this->unresolvedStockError($line);
            }

            $this->guardSameClient($dispatch, $stockPallet);

            if ((int) $stockPallet->full_pallets < $remaining) {
                throw $this->insufficientStockError($line);
            }

            $unitsPerPallet = (int) ($stockPallet->units_per_pallet ?: $line->units_per_pallet);
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - ($remaining * $unitsPerPallet));
            $stockPallet->save();

            return;
        }

        $batches = StockPallet::query()
            ->where('client_id', $dispatch->client_id)
            ->where('item_id', $line->item_id)
            ->where('active', true)
            ->where('status', StockPallet::STATUS_AVAILABLE)
            ->where('full_pallets', '>', 0)
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        if ((int) $batches->sum('full_pallets') < $remaining) {
            throw $this->insufficientStockError($line);
        }

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $batch->full_pallets);

            if ($take <= 0) {
                continue;
            }

            $unitsPerPallet = (int) ($batch->units_per_pallet ?: $line->units_per_pallet);
            $batch->quantity_units = max(0, (int) $batch->quantity_units - ($take * $unitsPerPallet));
            $batch->save();

            $remaining -= $take;
        }
    }

    private function guardSameClient(GoodsDispatch $dispatch, StockPallet $stockPallet): void
    {
        if ((int) $stockPallet->client_id !== (int) $dispatch->client_id) {
            throw ValidationException::withMessages([
                'status' => 'No se puede enviar la salida: una linea hace referencia a stock de otro cliente.',
            ]);
        }
    }

    private function insufficientStockError(GoodsDispatchLine $line): ValidationException
    {
        return ValidationException::withMessages([
            'status' => sprintf(
                'No se puede enviar la salida porque no hay stock suficiente para %s.',
                $this->lineLabel($line)
            ),
        ]);
    }

    private function unresolvedStockError(GoodsDispatchLine $line): ValidationException
    {
        return ValidationException::withMessages([
            'status' => sprintf(
                'No se puede enviar la salida: no se pudo identificar la partida de stock real de %s.',
                $this->lineLabel($line)
            ),
        ]);
    }

    private function lineLabel(GoodsDispatchLine $line): string
    {
        $sku = $line->sku ?: $line->item?->sku;
        $description = $line->description ?: $line->item?->description;

        return trim(collect([$sku, $description])->filter()->implode(' - ')) ?: 'la referencia solicitada';
    }

    /**
     * @param  list<int>  $excludedPeakIndexes
     * @return list<int>
     */
    private function peakConsumptionOrder(GoodsDispatchLine $line, array $excludedPeakIndexes = []): array
    {
        $indexes = array_values(array_diff(range(1, StockPallet::MAX_PEAK_COLUMNS), $excludedPeakIndexes));

        if ($line->stock_peak_index === null) {
            return $indexes;
        }

        return array_values(array_unique([(int) $line->stock_peak_index, ...$indexes]));
    }

    private function storeBrokenPalletRemainder(StockPallet $stockPallet, GoodsDispatchLine $line, int $remainingUnits): void
    {
        if ($remainingUnits <= 0) {
            return;
        }

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakIndex) {
            $field = 'peak_'.$peakIndex;

            if ((int) ($stockPallet->{$field} ?? 0) > 0) {
                continue;
            }

            $stockPallet->{$field} = $remainingUnits;

            return;
        }

        throw $this->insufficientStockError($line);
    }
}
