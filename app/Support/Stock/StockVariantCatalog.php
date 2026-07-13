<?php

namespace App\Support\Stock;

use App\Models\Item;
use App\Models\StockPallet;
use App\Support\WmsLineType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StockVariantCatalog
{
    /**
     * @return array<int, array<string, mixed>>
     */
    public function search(string $query, ?int $clientId = null, int $limit = 10, bool $activeOnly = true): array
    {
        $normalizedQuery = trim($query);

        if (mb_strlen($normalizedQuery) < 2) {
            return [];
        }

        $items = Item::query()
            ->with([
                'client',
                'stockPallets' => fn ($query) => $query
                    ->where('active', true)
                    ->where('status', StockPallet::STATUS_AVAILABLE)
                    ->orderByDesc('received_at')
                    ->orderBy('lot'),
            ])
            ->when($clientId !== null, fn (Builder $builder) => $builder->where('client_id', $clientId))
            ->when($activeOnly, fn (Builder $builder) => $builder->where('active', true))
            ->where(function (Builder $builder) use ($normalizedQuery): void {
                $builder
                    ->where('sku', 'like', '%'.$normalizedQuery.'%')
                    ->orWhere('description', 'like', '%'.$normalizedQuery.'%')
                    ->orWhereHas('stockPallets', function (Builder $builder) use ($normalizedQuery): void {
                        $builder
                            ->where('active', true)
                            ->where('status', StockPallet::STATUS_AVAILABLE)
                            ->where(function (Builder $builder) use ($normalizedQuery): void {
                                $builder
                                    ->where('lot', 'like', '%'.$normalizedQuery.'%')
                                    ->orWhere('location_text', 'like', '%'.$normalizedQuery.'%')
                                    ->orWhere('pallet_code', 'like', '%'.$normalizedQuery.'%');
                            });
                    });
            })
            ->orderBy('sku')
            ->limit(max($limit, 15))
            ->get();

        $variants = $items
            ->flatMap(fn (Item $item) => $this->variantsForItem($item))
            ->values()
            ->all();

        return array_slice($variants, 0, max($limit, 15));
    }

    /**
     * @param  iterable<mixed, mixed>  $submittedLines
     * @return array<int, array<string, mixed>>
     */
    public function hydrateSelections(iterable $submittedLines, ?int $clientId = null): array
    {
        $rows = collect($submittedLines)
            ->map(fn ($payload, $key) => is_array($payload)
                ? $payload
                : [
                    'item_id' => $key,
                    'line_type' => WmsLineType::PALLET,
                    'quantity' => $payload,
                ])
            ->filter(function (array $payload): bool {
                $quantity = is_numeric((string) ($payload['quantity'] ?? null))
                    ? (int) $payload['quantity']
                    : 0;

                return $quantity > 0;
            })
            ->values();

        if ($rows->isEmpty()) {
            return [];
        }

        $itemIds = $rows
            ->pluck('item_id')
            ->filter(fn ($itemId) => is_numeric((string) $itemId) && (int) $itemId > 0)
            ->map(fn ($itemId) => (int) $itemId)
            ->unique()
            ->values()
            ->all();

        $stockPalletIds = $rows
            ->pluck('stock_pallet_id')
            ->filter(fn ($stockPalletId) => is_numeric((string) $stockPalletId) && (int) $stockPalletId > 0)
            ->map(fn ($stockPalletId) => (int) $stockPalletId)
            ->unique()
            ->values()
            ->all();

        $items = Item::query()
            ->with('client')
            ->when($clientId !== null, fn (Builder $builder) => $builder->where('client_id', $clientId))
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        $stockPallets = StockPallet::query()
            ->whereIn('id', $stockPalletIds)
            ->get()
            ->keyBy('id');

        return $rows
            ->map(function (array $payload) use ($items, $stockPallets): ?array {
                $itemId = (int) ($payload['item_id'] ?? 0);
                $item = $items->get($itemId);

                if (! $item instanceof Item) {
                    return null;
                }

                $lineType = in_array($payload['line_type'] ?? null, WmsLineType::values(), true)
                    ? (string) $payload['line_type']
                    : WmsLineType::PALLET;
                $stockPalletId = is_numeric((string) ($payload['stock_pallet_id'] ?? null))
                    ? (int) $payload['stock_pallet_id']
                    : null;
                $stockPeakIndex = is_numeric((string) ($payload['stock_peak_index'] ?? null))
                    ? (int) $payload['stock_peak_index']
                    : null;
                $quantity = (int) ($payload['quantity'] ?? 0);
                $stockPallet = $stockPalletId !== null ? $stockPallets->get($stockPalletId) : null;

                $variant = match (true) {
                    $lineType === WmsLineType::PEAK && $stockPallet instanceof StockPallet && $stockPeakIndex !== null
                        => $this->buildPeakVariant($item, $stockPallet, $stockPeakIndex, max(0, (int) ($stockPallet->{'peak_'.$stockPeakIndex} ?? 0))),
                    $lineType === WmsLineType::PALLET && $stockPallet instanceof StockPallet
                        => $this->buildPalletVariant($item, $stockPallet),
                    default => $this->buildFallbackVariant($item),
                };

                if ($variant === null) {
                    return null;
                }

                $variant['selected_quantity'] = $quantity;
                $variant['destination_location'] = trim((string) ($payload['destination_location'] ?? '')) !== ''
                    ? trim((string) $payload['destination_location'])
                    : null;

                return $variant;
            })
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function variantsForItem(Item $item): array
    {
        $variants = collect();
        $availableBatches = $item->stockPallets->filter(fn (StockPallet $stockPallet) => $this->batchHasAvailableVariants($stockPallet));

        if ($availableBatches->isEmpty()) {
            $variants->push($this->buildFallbackVariant($item));

            return $variants->filter()->values()->all();
        }

        foreach ($availableBatches as $stockPallet) {
            if ((int) $stockPallet->full_pallets > 0) {
                $variants->push($this->buildPalletVariant($item, $stockPallet));
            }

            foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakIndex) {
                $peakUnits = max(0, (int) ($stockPallet->{'peak_'.$peakIndex} ?? 0));

                if ($peakUnits <= 0) {
                    continue;
                }

                $variants->push($this->buildPeakVariant($item, $stockPallet, $peakIndex, $peakUnits));
            }
        }

        return $variants->filter()->values()->all();
    }

    private function batchHasAvailableVariants(StockPallet $stockPallet): bool
    {
        if ((int) $stockPallet->full_pallets > 0) {
            return true;
        }

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakIndex) {
            if ((int) ($stockPallet->{'peak_'.$peakIndex} ?? 0) > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, mixed>
     */
    private function buildFallbackVariant(Item $item): array
    {
        return [
            'variant_key' => $this->variantKey(WmsLineType::PALLET, $item->id),
            'id' => $this->variantKey(WmsLineType::PALLET, $item->id),
            'item_id' => $item->id,
            'client_id' => $item->client_id,
            'client_name' => $item->client?->name,
            'line_type' => WmsLineType::PALLET,
            'line_type_label' => WmsLineType::label(WmsLineType::PALLET),
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => null,
            'location_text' => null,
            'stock_pallet_id' => null,
            'stock_peak_index' => null,
            'units_per_pallet' => (int) $item->units_per_pallet,
            'units_per_peak' => null,
            'available_pallets' => null,
            'available_peaks' => 0,
            'quantity_min' => 1,
            'quantity_max' => null,
            'meta' => trim(number_format((int) $item->units_per_pallet, 0, ',', '.').' uds/pallet · Sin desglose de stock'),
            'label' => $item->sku.' · '.$item->description,
            'search_value' => $item->sku,
            'summary' => 'Pallet genérico',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildPalletVariant(Item $item, StockPallet $stockPallet): array
    {
        $availablePallets = max(0, (int) $stockPallet->full_pallets);
        $availablePeaks = $this->countAvailablePeaks($stockPallet);
        $location = filled($stockPallet->location_text) ? trim((string) $stockPallet->location_text) : null;
        $lot = filled($stockPallet->lot) ? trim((string) $stockPallet->lot) : null;

        return [
            'variant_key' => $this->variantKey(WmsLineType::PALLET, $item->id, $stockPallet->id),
            'id' => $this->variantKey(WmsLineType::PALLET, $item->id, $stockPallet->id),
            'item_id' => $item->id,
            'client_id' => $item->client_id,
            'client_name' => $item->client?->name,
            'line_type' => WmsLineType::PALLET,
            'line_type_label' => WmsLineType::label(WmsLineType::PALLET),
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => $lot,
            'location_text' => $location,
            'stock_pallet_id' => $stockPallet->id,
            'stock_peak_index' => null,
            'units_per_pallet' => (int) $item->units_per_pallet,
            'units_per_peak' => null,
            'available_pallets' => $availablePallets,
            'available_peaks' => $availablePeaks,
            'quantity_min' => 1,
            'quantity_max' => $availablePallets > 0 ? $availablePallets : null,
            'meta' => $this->metaString([
                $lot ? 'Lote '.$lot : null,
                number_format((int) $item->units_per_pallet, 0, ',', '.').' uds/pallet',
                $availablePallets > 0 ? number_format($availablePallets, 0, ',', '.').' pallets disponibles' : null,
                $availablePeaks > 0 ? number_format($availablePeaks, 0, ',', '.').' picos' : null,
                $location ? 'Ubicación '.$location : null,
            ]),
            'label' => $item->sku.' · '.$item->description,
            'search_value' => $item->sku,
            'summary' => 'Pallet completo'.($lot ? ' · lote '.$lot : ''),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildPeakVariant(Item $item, StockPallet $stockPallet, int $peakIndex, int $peakUnits): ?array
    {
        if ($peakUnits <= 0) {
            return null;
        }

        $availablePallets = max(0, (int) $stockPallet->full_pallets);
        $availablePeaks = $this->countAvailablePeaks($stockPallet);
        $location = filled($stockPallet->location_text) ? trim((string) $stockPallet->location_text) : null;
        $lot = filled($stockPallet->lot) ? trim((string) $stockPallet->lot) : null;

        return [
            'variant_key' => $this->variantKey(WmsLineType::PEAK, $item->id, $stockPallet->id, $peakIndex),
            'id' => $this->variantKey(WmsLineType::PEAK, $item->id, $stockPallet->id, $peakIndex),
            'item_id' => $item->id,
            'client_id' => $item->client_id,
            'client_name' => $item->client?->name,
            'line_type' => WmsLineType::PEAK,
            'line_type_label' => WmsLineType::label(WmsLineType::PEAK),
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => $lot,
            'location_text' => $location,
            'stock_pallet_id' => $stockPallet->id,
            'stock_peak_index' => $peakIndex,
            'units_per_pallet' => (int) $item->units_per_pallet,
            'units_per_peak' => $peakUnits,
            'available_pallets' => $availablePallets,
            'available_peaks' => $availablePeaks,
            'quantity_min' => 1,
            'quantity_max' => 1,
            'meta' => $this->metaString([
                $lot ? 'Lote '.$lot : null,
                'Pico '.$peakIndex.' · '.number_format($peakUnits, 0, ',', '.').' uds',
                $availablePallets > 0 ? number_format($availablePallets, 0, ',', '.').' pallets disponibles' : null,
                $availablePeaks > 0 ? number_format($availablePeaks, 0, ',', '.').' picos' : null,
                $location ? 'Ubicación '.$location : null,
            ]),
            'label' => $item->sku.' · '.$item->description,
            'search_value' => $item->sku,
            'summary' => 'Pico '.$peakIndex.' · '.number_format($peakUnits, 0, ',', '.').' uds',
        ];
    }

    private function countAvailablePeaks(StockPallet $stockPallet): int
    {
        $count = 0;

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakIndex) {
            if ((int) ($stockPallet->{'peak_'.$peakIndex} ?? 0) > 0) {
                $count++;
            }
        }

        return $count;
    }

    private function variantKey(string $lineType, int $itemId, ?int $stockPalletId = null, ?int $stockPeakIndex = null): string
    {
        return match ($lineType) {
            WmsLineType::PEAK => implode(':', [WmsLineType::PEAK, $itemId, $stockPalletId, $stockPeakIndex]),
            default => $stockPalletId !== null
                ? implode(':', [WmsLineType::PALLET, $itemId, $stockPalletId])
                : implode(':', ['catalog', $itemId]),
        };
    }

    /**
     * @param  array<int, string|null>  $parts
     */
    private function metaString(array $parts): string
    {
        return collect($parts)
            ->filter()
            ->implode(' · ');
    }
}
