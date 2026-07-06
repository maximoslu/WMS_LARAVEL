<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\StockPallet;
use App\Support\Stock\StockBatchCalculator;
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

        [$fullPallets, $picoUnits] = $this->splitLine($line, $unitsPerPallet, $quantityUnits);

        if ($fullPallets === 0 && $picoUnits === 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => "La linea {$line->id} no genera una partida valida.",
            ]);
        }

        $stockPallet = $this->resolveTargetBatch($receipt, $item, $line, $unitsPerPallet);
        $nextQuantityUnits = (int) ($stockPallet->quantity_units ?? 0) + $quantityUnits;

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
        $stockPallet->save();

        $line->forceFill([
            'item_id' => $item->id,
            'sku' => $line->sku ?? $item->sku,
            'description' => $line->description ?? $item->description,
            'lot' => $line->lot,
            'units_per_pallet' => $unitsPerPallet,
            'pallet_count' => $fullPallets,
            'pico_units' => $picoUnits > 0 ? $picoUnits : null,
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
     * @return array{0: int, 1: int}
     */
    private function splitLine(GoodsReceiptLine $line, int $unitsPerPallet, int $quantityUnits): array
    {
        $palletCount = (int) $line->pallet_count;
        $picoUnits = (int) ($line->pico_units ?? 0);

        if ($palletCount > 0 || $line->pico_units !== null) {
            $computedTotal = ($palletCount * $unitsPerPallet) + $picoUnits;

            if ($computedTotal !== $quantityUnits) {
                throw ValidationException::withMessages([
                    'goods_receipt' => "La linea {$line->id} no cuadra entre cantidad total, pallets completos y pico.",
                ]);
            }

            return [$palletCount, $picoUnits];
        }

        return [
            StockBatchCalculator::calculateFullPallets($quantityUnits, $unitsPerPallet),
            StockBatchCalculator::calculateRemainderPeak($quantityUnits, $unitsPerPallet),
        ];
    }
}
