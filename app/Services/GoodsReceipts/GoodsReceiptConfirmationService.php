<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\StockPallet;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsReceiptConfirmationService
{
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

            foreach ($receipt->lines as $line) {
                $this->confirmLine($receipt, $line);
            }

            $receipt->forceFill([
                'status' => GoodsReceipt::STATUS_CONFIRMED,
                'confirmed_by' => $user->id,
                'confirmed_at' => now(),
            ])->save();

            return $receipt->refresh();
        });
    }

    private function confirmLine(GoodsReceipt $receipt, GoodsReceiptLine $line): void
    {
        $item = $this->resolveItem($receipt, $line);
        $unitsPerPallet = (int) ($line->units_per_pallet ?? $item->units_per_pallet);
        $quantityUnits = (int) $line->quantity_units;

        if ($unitsPerPallet <= 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => "La linea {$line->id} necesita unidades por palet para generar stock.",
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
                'goods_receipt' => "La linea {$line->id} no genera palets validos.",
            ]);
        }

        for ($index = 1; $index <= $fullPallets; $index++) {
            $receipt->stockPallets()->create([
                'client_id' => $receipt->client_id,
                'item_id' => $item->id,
                'location_id' => $line->location_id,
                'location_text' => $line->location?->code,
                'pallet_code' => sprintf('GR-%d-L%d-P%d', $receipt->id, $line->id, $index),
                'lot' => $line->lot,
                'quantity_units' => $unitsPerPallet,
                'received_at' => $receipt->received_at,
                'status' => StockPallet::STATUS_AVAILABLE,
                'active' => true,
            ]);
        }

        if ($picoUnits > 0) {
            $receipt->stockPallets()->create([
                'client_id' => $receipt->client_id,
                'item_id' => $item->id,
                'location_id' => $line->location_id,
                'location_text' => $line->location?->code,
                'pallet_code' => sprintf('GR-%d-L%d-X1', $receipt->id, $line->id),
                'lot' => $line->lot,
                'quantity_units' => $picoUnits,
                'received_at' => $receipt->received_at,
                'status' => StockPallet::STATUS_AVAILABLE,
                'active' => true,
            ]);
        }

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

    private function resolveItem(GoodsReceipt $receipt, GoodsReceiptLine $line): Item
    {
        if ($line->item !== null) {
            if ((int) $line->item->client_id !== (int) $receipt->client_id) {
                throw ValidationException::withMessages([
                    'goods_receipt' => "El articulo de la linea {$line->id} no pertenece al cliente de la entrada.",
                ]);
            }

            return $line->item;
        }

        $sku = $line->sku;
        $description = $line->description;
        $unitsPerPallet = (int) ($line->units_per_pallet ?? 0);
        if ($sku === null || $description === null || $unitsPerPallet <= 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => "La linea {$line->id} necesita articulo o datos suficientes para crearlo (SKU, descripcion y unidades por palet).",
            ]);
        }

        return Item::query()->firstOrCreate(
            [
                'client_id' => $receipt->client_id,
                'sku' => $sku,
            ],
            [
                'description' => $description,
                'units_per_pallet' => $unitsPerPallet,
                'status' => Item::STATUS_ACTIVE,
                'active' => true,
            ]
        );
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
                    'goods_receipt' => "La linea {$line->id} no cuadra entre cantidad total, palets completos y pico.",
                ]);
            }

            return [$palletCount, $picoUnits];
        }

        return [
            intdiv($quantityUnits, $unitsPerPallet),
            $quantityUnits % $unitsPerPallet,
        ];
    }
}
