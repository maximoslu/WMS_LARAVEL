<?php

namespace App\Services\GoodsDispatches;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
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
        $lines = $dispatch->lines()->get();

        foreach ($lines as $line) {
            $quantity = $line->loadedQuantity();

            if ($quantity <= 0) {
                continue;
            }

            if ($line->isPeakLine()) {
                $this->applyPeakLine($dispatch, $line);

                continue;
            }

            $this->applyPalletLine($dispatch, $line);
        }
    }

    private function applyPeakLine(GoodsDispatch $dispatch, GoodsDispatchLine $line): void
    {
        if ($line->stock_pallet_id === null || $line->stock_peak_index === null) {
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

        $peakField = 'peak_'.$line->stock_peak_index;
        $availableUnits = max(0, (int) $stockPallet->{$peakField});

        if ($availableUnits <= 0) {
            throw $this->insufficientStockError($line);
        }

        $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - $availableUnits);
        $stockPallet->{$peakField} = 0;
        $stockPallet->save();
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
}
