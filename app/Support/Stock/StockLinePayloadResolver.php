<?php

namespace App\Support\Stock;

use App\Models\Item;
use App\Models\StockPallet;
use App\Support\WmsLineType;
use Illuminate\Support\Collection;

class StockLinePayloadResolver
{
    /**
     * @param  iterable<mixed, mixed>  $submittedLines
     * @return array{
     *     lines: array<int, array{
     *         item_id:int,
     *         sku:string,
     *         description:string,
     *         stock_pallet_id:int|null,
     *         line_type:string,
     *         stock_peak_index:int|null,
     *         lot:string|null,
     *         location_text:string|null,
     *         units_per_pallet:int,
     *         units_per_peak:int|null,
     *         requested_pallets:int,
     *         requested_peaks:int,
     *         requested_units:int
     *     }>,
     *     errors: array<string, string>
     * }
     */
    public function resolve(int $clientId, iterable $submittedLines, bool $activeOnly = true): array
    {
        $rows = collect($submittedLines)
            ->map(fn ($payload, $key) => [
                'key' => (string) $key,
                'payload' => is_array($payload) ? $payload : [],
            ])
            ->values();

        $positiveRows = $rows->filter(function (array $row): bool {
            return $this->normalizeQuantity($row['payload']['quantity'] ?? null) > 0;
        })->values();

        if ($positiveRows->isEmpty()) {
            return [
                'lines' => [],
                'errors' => [],
            ];
        }

        $itemIds = $positiveRows
            ->pluck('payload.item_id')
            ->filter(fn ($itemId) => is_numeric((string) $itemId) && (int) $itemId > 0)
            ->map(fn ($itemId) => (int) $itemId)
            ->unique()
            ->values()
            ->all();

        $stockPalletIds = $positiveRows
            ->pluck('payload.stock_pallet_id')
            ->filter(fn ($stockPalletId) => is_numeric((string) $stockPalletId) && (int) $stockPalletId > 0)
            ->map(fn ($stockPalletId) => (int) $stockPalletId)
            ->unique()
            ->values()
            ->all();

        $items = Item::query()
            ->where('client_id', $clientId)
            ->when($activeOnly, fn ($query) => $query->where('active', true))
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        $stockPallets = StockPallet::query()
            ->where('client_id', $clientId)
            ->whereIn('id', $stockPalletIds)
            ->get()
            ->keyBy('id');

        $resolvedLines = [];
        $errors = [];

        foreach ($positiveRows as $row) {
            $payload = $row['payload'];
            $rowKey = $row['key'];
            $quantity = $this->normalizeQuantity($payload['quantity'] ?? null);
            $itemId = is_numeric((string) ($payload['item_id'] ?? null))
                ? (int) $payload['item_id']
                : 0;
            $lineType = in_array($payload['line_type'] ?? null, WmsLineType::values(), true)
                ? (string) $payload['line_type']
                : WmsLineType::PALLET;
            $stockPalletId = is_numeric((string) ($payload['stock_pallet_id'] ?? null))
                ? (int) $payload['stock_pallet_id']
                : null;
            $stockPeakIndex = is_numeric((string) ($payload['stock_peak_index'] ?? null))
                ? (int) $payload['stock_peak_index']
                : null;

            $item = $items->get($itemId);

            if (! $item instanceof Item) {
                $errors["lines.$rowKey.item_id"] = 'Selecciona una referencia válida para este cliente.';
                continue;
            }

            $stockPallet = $stockPalletId !== null ? $stockPallets->get($stockPalletId) : null;

            if ($stockPalletId !== null && (! $stockPallet instanceof StockPallet || (int) $stockPallet->item_id !== $item->id)) {
                $errors["lines.$rowKey.stock_pallet_id"] = 'La partida seleccionada no coincide con la referencia elegida.';
                continue;
            }

            if ($lineType === WmsLineType::PEAK) {
                if (! $stockPallet instanceof StockPallet) {
                    $errors["lines.$rowKey.stock_pallet_id"] = 'Selecciona un pico existente para esta referencia.';
                    continue;
                }

                if ($stockPeakIndex === null || $stockPeakIndex < 1 || $stockPeakIndex > StockPallet::MAX_PEAK_COLUMNS) {
                    $errors["lines.$rowKey.stock_peak_index"] = 'El pico seleccionado no es válido.';
                    continue;
                }

                $unitsPerPeak = max(0, (int) ($stockPallet->{'peak_'.$stockPeakIndex} ?? 0));

                if ($unitsPerPeak <= 0) {
                    $errors["lines.$rowKey.stock_peak_index"] = 'El pico seleccionado ya no está disponible.';
                    continue;
                }

                if ($quantity !== 1) {
                    $errors["lines.$rowKey.quantity"] = 'Cada línea de pico representa un pico concreto. Añade otro pico en otra línea si hace falta.';
                    continue;
                }

                $resolvedLines[] = [
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'stock_pallet_id' => $stockPallet->id,
                    'line_type' => WmsLineType::PEAK,
                    'stock_peak_index' => $stockPeakIndex,
                    'lot' => filled($stockPallet->lot) ? trim((string) $stockPallet->lot) : null,
                    'location_text' => filled($stockPallet->location_text) ? trim((string) $stockPallet->location_text) : null,
                    'units_per_pallet' => (int) $item->units_per_pallet,
                    'units_per_peak' => $unitsPerPeak,
                    'requested_pallets' => 0,
                    'requested_peaks' => 1,
                    'requested_units' => $unitsPerPeak,
                ];

                continue;
            }

            $resolvedLines[] = [
                'item_id' => $item->id,
                'sku' => $item->sku,
                'description' => $item->description,
                'stock_pallet_id' => $stockPallet?->id,
                'line_type' => WmsLineType::PALLET,
                'stock_peak_index' => null,
                'lot' => filled($stockPallet?->lot) ? trim((string) $stockPallet->lot) : null,
                'location_text' => filled($stockPallet?->location_text) ? trim((string) $stockPallet->location_text) : null,
                'units_per_pallet' => (int) $item->units_per_pallet,
                'units_per_peak' => null,
                'requested_pallets' => $quantity,
                'requested_peaks' => 0,
                'requested_units' => $quantity * (int) $item->units_per_pallet,
            ];
        }

        return [
            'lines' => $resolvedLines,
            'errors' => $errors,
        ];
    }

    private function normalizeQuantity(mixed $value): int
    {
        if ($value === '' || $value === null) {
            return 0;
        }

        $normalized = preg_replace('/[^\d-]/', '', (string) $value) ?? '';

        if ($normalized === '' || $normalized === '-') {
            return 0;
        }

        return (int) $normalized;
    }
}
