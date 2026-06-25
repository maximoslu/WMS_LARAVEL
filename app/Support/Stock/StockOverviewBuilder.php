<?php

namespace App\Support\Stock;

use App\Models\Item;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StockOverviewBuilder
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{filters: array<string, mixed>, rows: Collection<int, array<string, mixed>>, summary: array<string, int>}
     */
    public function build(array $filters = []): array
    {
        $normalizedFilters = $this->normalizeFilters($filters);

        $rows = $this->baseQuery($normalizedFilters)
            ->get()
            ->map(fn (Item $item): array => $this->buildRow($item))
            ->filter(fn (array $row): bool => $this->passesDerivedFilters($row, $normalizedFilters))
            ->values();

        return [
            'filters' => $normalizedFilters,
            'rows' => $rows,
            'summary' => [
                'references_with_stock' => $rows->where('has_stock', true)->count(),
                'total_units' => (int) $rows->sum('total_units'),
                'total_pallets' => (int) $rows->sum('total_pallets'),
                'total_peaks' => (int) $rows->sum('pico_count'),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function baseQuery(array $filters): Builder
    {
        return Item::query()
            ->with([
                'client',
                'stockPallets' => fn ($query) => $query
                    ->where('active', true)
                    ->orderBy('received_at')
                    ->orderBy('id'),
            ])
            ->when($filters['client_id'] !== null, fn (Builder $query) => $query->where('client_id', $filters['client_id']))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $query) use ($filters): void {
                    $query
                        ->where('sku', 'like', '%'.$filters['search'].'%')
                        ->orWhere('description', 'like', '%'.$filters['search'].'%');
                });
            })
            ->when($filters['lot'] !== '', fn (Builder $query) => $query->where('lot', 'like', '%'.$filters['lot'].'%'))
            ->when($filters['location'] !== '', function (Builder $query) use ($filters): void {
                $query->whereHas('stockPallets', function (Builder $query) use ($filters): void {
                    $query
                        ->where('active', true)
                        ->where('location_text', 'like', '%'.$filters['location'].'%');
                });
            })
            ->orderBy('client_id')
            ->orderBy('sku')
            ->orderBy('lot_key');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $stockState = (string) ($filters['stock_state'] ?? 'with_stock');
        $peakState = (string) ($filters['peak_state'] ?? 'all');

        return [
            'client_id' => isset($filters['client_id']) && (int) $filters['client_id'] > 0
                ? (int) $filters['client_id']
                : null,
            'search' => trim((string) ($filters['search'] ?? '')),
            'lot' => trim((string) ($filters['lot'] ?? '')),
            'location' => trim((string) ($filters['location'] ?? '')),
            'stock_state' => in_array($stockState, ['with_stock', 'without_stock', 'all'], true)
                ? $stockState
                : 'with_stock',
            'peak_state' => in_array($peakState, ['all', 'with_peaks'], true)
                ? $peakState
                : 'all',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildRow(Item $item): array
    {
        $pallets = $item->stockPallets
            ->where('active', true)
            ->values();

        $standardUnits = max(1, (int) $item->units_per_pallet);
        $picoQuantities = $pallets
            ->filter(fn ($pallet): bool => (int) $pallet->quantity_units !== $standardUnits)
            ->pluck('quantity_units')
            ->map(fn ($quantity): int => (int) $quantity)
            ->values();

        $locationList = $pallets
            ->pluck('location_text')
            ->filter(fn (?string $location): bool => filled($location))
            ->map(fn (?string $location): string => trim((string) $location))
            ->unique()
            ->values();

        return [
            'client_id' => $item->client_id,
            'client_name' => $item->client->name,
            'client_code' => $item->client->code,
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => $item->lot,
            'lot_label' => $item->lot ?: 'Sin lote',
            'units_per_pallet' => $standardUnits,
            'item_active' => (bool) $item->active,
            'total_units' => (int) $pallets->sum('quantity_units'),
            'full_pallets' => $pallets->filter(
                fn ($pallet): bool => (int) $pallet->quantity_units === $standardUnits
            )->count(),
            'pico_count' => $picoQuantities->count(),
            'total_pallets' => $pallets->count(),
            'pico_quantities' => $picoQuantities->all(),
            'pico_columns' => collect(range(0, 9))
                ->map(fn (int $index): ?int => $picoQuantities->get($index))
                ->all(),
            'has_stock' => $pallets->isNotEmpty(),
            'has_peaks' => $picoQuantities->isNotEmpty(),
            'locations' => $locationList->all(),
            'location_summary' => $locationList->take(3)->implode(' · '),
        ];
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, mixed>  $filters
     */
    private function passesDerivedFilters(array $row, array $filters): bool
    {
        if ($filters['stock_state'] === 'with_stock' && ! $row['has_stock']) {
            return false;
        }

        if ($filters['stock_state'] === 'without_stock' && $row['has_stock']) {
            return false;
        }

        if ($filters['peak_state'] === 'with_peaks' && ! $row['has_peaks']) {
            return false;
        }

        return true;
    }
}
