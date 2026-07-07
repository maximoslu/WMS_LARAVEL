<?php

namespace App\Services\GoodsReceipts;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use Illuminate\Validation\ValidationException;

class GoodsReceiptItemResolver
{
    /**
     * @param  array<string, mixed>  $line
     */
    public function resolveForPayload(int $clientId, array $line): ?Item
    {
        $itemId = isset($line['item_id']) ? (int) $line['item_id'] : 0;

        if ($itemId > 0) {
            $item = Item::query()->find($itemId);

            if (! $item instanceof Item || (int) $item->client_id !== $clientId) {
                throw ValidationException::withMessages([
                    'goods_receipt' => 'Una de las lineas hace referencia a un articulo que no pertenece al cliente seleccionado.',
                ]);
            }

            return $item;
        }

        $sku = $this->normalizeNullableUpper($line['sku'] ?? null);

        if ($sku === null) {
            return null;
        }

        $description = $this->normalizeNullableText($line['description'] ?? null);
        $unitsPerPallet = isset($line['units_per_pallet']) ? (int) $line['units_per_pallet'] : 0;

        return $this->findOrCreate($clientId, $sku, $description, $unitsPerPallet);
    }

    public function resolveForLine(GoodsReceipt $receipt, GoodsReceiptLine $line): Item
    {
        if ($line->item instanceof Item) {
            if ((int) $line->item->client_id !== (int) $receipt->client_id) {
                throw ValidationException::withMessages([
                    'goods_receipt' => "El articulo de la linea {$line->id} no pertenece al cliente de la entrada.",
                ]);
            }

            return $line->item;
        }

        $sku = $this->normalizeNullableUpper($line->sku);
        $description = $this->normalizeNullableText($line->description);
        $unitsPerPallet = (int) ($line->units_per_pallet ?? 0);

        if ($sku === null || $description === null || $unitsPerPallet <= 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => "La linea {$line->id} necesita articulo o datos suficientes para crearlo (SKU, descripcion y unidades por pallet).",
            ]);
        }

        return $this->findOrCreate((int) $receipt->client_id, $sku, $description, $unitsPerPallet);
    }

    /**
     * @return array{item: Item, created: bool}
     */
    public function createOrReuseForQuickAdd(int $clientId, string $sku, string $description, int $unitsPerPallet): array
    {
        $normalizedSku = $this->normalizeNullableUpper($sku);

        if ($normalizedSku === null) {
            throw ValidationException::withMessages([
                'sku' => 'El SKU es obligatorio para crear el articulo.',
            ]);
        }

        $existingItem = Item::query()
            ->where('client_id', $clientId)
            ->where('sku', $normalizedSku)
            ->first();

        if ($existingItem instanceof Item) {
            return ['item' => $existingItem, 'created' => false];
        }

        $normalizedDescription = $this->normalizeNullableText($description);

        if ($normalizedDescription === null || $unitsPerPallet <= 0) {
            throw ValidationException::withMessages([
                'description' => 'Descripcion y uds/pallet son obligatorias para crear un articulo nuevo.',
            ]);
        }

        $item = Item::query()->create([
            'client_id' => $clientId,
            'sku' => $normalizedSku,
            'description' => $normalizedDescription,
            'units_per_pallet' => $unitsPerPallet,
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
            'lot' => null,
            'lot_key' => '',
            'default_location_id' => null,
        ]);

        return ['item' => $item, 'created' => true];
    }

    private function findOrCreate(int $clientId, string $sku, ?string $description, int $unitsPerPallet): Item
    {
        $existingItem = Item::query()
            ->where('client_id', $clientId)
            ->where('sku', $sku)
            ->first();

        if ($existingItem instanceof Item) {
            return $existingItem;
        }

        if ($description === null || $unitsPerPallet <= 0) {
            throw ValidationException::withMessages([
                'goods_receipt' => 'Faltan datos para crear automaticamente un articulo nuevo desde la entrada.',
            ]);
        }

        return Item::query()->create([
            'client_id' => $clientId,
            'sku' => $sku,
            'description' => $description,
            'units_per_pallet' => $unitsPerPallet,
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
            'lot' => null,
            'lot_key' => '',
            'default_location_id' => null,
        ]);
    }

    private function normalizeNullableUpper(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : mb_strtoupper($normalized);
    }

    private function normalizeNullableText(mixed $value): ?string
    {
        $normalized = trim((string) $value);

        return $normalized === '' ? null : $normalized;
    }
}
