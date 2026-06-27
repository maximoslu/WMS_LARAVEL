<?php

namespace App\Support\Stock;

use App\Models\Item;
use App\Models\StockPallet;
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

        $stockRows = $this->stockQuery($normalizedFilters)
            ->get()
            ->map(fn (StockPallet $pallet): array => $this->buildStockRow($pallet));

        $withoutStockRows = $this->withoutStockQuery($normalizedFilters)
            ->get()
            ->map(fn (Item $item): array => $this->buildWithoutStockRow($item));

        $rows = match ($normalizedFilters['stock_state']) {
            'with_stock' => $stockRows,
            'without_stock' => $withoutStockRows,
            default => $stockRows->concat($withoutStockRows),
        };

        return [
            'filters' => $normalizedFilters,
            'rows' => $rows->values(),
            'summary' => [
                'references_with_stock' => $stockRows->pluck('item_id')->filter()->unique()->count(),
                'total_units' => (int) $stockRows->sum('quantity_units'),
                'total_pallets' => (int) $stockRows->sum('full_pallets'),
                'blocked_batches' => $stockRows->where('batch_status', StockPallet::STATUS_BLOCKED)->count(),
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function stockQuery(array $filters): Builder
    {
        return StockPallet::query()
            ->with([
                'client',
                'item.client',
                'item.defaultLocation.warehouse',
                'location.warehouse',
            ])
            ->where('active', true)
            ->whereHas('item')
            ->when($filters['client_id'] !== null, fn (Builder $query) => $query->where('client_id', $filters['client_id']))
            ->when($filters['item_id'] !== null, fn (Builder $query) => $query->where('item_id', $filters['item_id']))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $query->whereHas('item', function (Builder $query) use ($filters): void {
                    $query->where(function (Builder $query) use ($filters): void {
                        $query
                            ->where('sku', 'like', '%'.$filters['search'].'%')
                            ->orWhere('description', 'like', '%'.$filters['search'].'%');
                    });
                });
            })
            ->when($filters['lot'] !== '', fn (Builder $query) => $query->where('lot', 'like', '%'.$filters['lot'].'%'))
            ->when($filters['location_id'] !== null, fn (Builder $query) => $query->where('location_id', $filters['location_id']))
            ->when($filters['location'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $query) use ($filters): void {
                    $query
                        ->where('location_text', 'like', '%'.$filters['location'].'%')
                        ->orWhereHas('location', fn (Builder $query) => $query->where('code', 'like', '%'.$filters['location'].'%'));
                });
            })
            ->when($filters['batch_status'] !== 'all', fn (Builder $query) => $query->where('status', $filters['batch_status']))
            ->orderBy('received_at')
            ->orderBy('id');
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function withoutStockQuery(array $filters): Builder
    {
        return Item::query()
            ->with(['client', 'defaultLocation.warehouse'])
            ->when($filters['client_id'] !== null, fn (Builder $query) => $query->where('client_id', $filters['client_id']))
            ->when($filters['search'] !== '', function (Builder $query) use ($filters): void {
                $query->where(function (Builder $query) use ($filters): void {
                    $query
                        ->where('sku', 'like', '%'.$filters['search'].'%')
                        ->orWhere('description', 'like', '%'.$filters['search'].'%');
                });
            })
            ->whereDoesntHave('stockPallets', fn (Builder $query) => $query->where('active', true))
            ->orderBy('client_id')
            ->orderBy('sku');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(array $filters): array
    {
        $stockState = (string) ($filters['stock_state'] ?? 'with_stock');

        return [
            'client_id' => isset($filters['client_id']) && (int) $filters['client_id'] > 0
                ? (int) $filters['client_id']
                : null,
            'item_id' => isset($filters['item_id']) && (int) $filters['item_id'] > 0
                ? (int) $filters['item_id']
                : null,
            'search' => trim((string) ($filters['search'] ?? '')),
            'lot' => trim((string) ($filters['lot'] ?? '')),
            'location' => trim((string) ($filters['location'] ?? '')),
            'location_id' => isset($filters['location_id']) && (int) $filters['location_id'] > 0
                ? (int) $filters['location_id']
                : null,
            'batch_status' => in_array((string) ($filters['batch_status'] ?? 'all'), ['all', ...StockPallet::statuses()], true)
                ? (string) ($filters['batch_status'] ?? 'all')
                : 'all',
            'stock_state' => in_array($stockState, ['with_stock', 'without_stock', 'all'], true)
                ? $stockState
                : 'with_stock',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStockRow(StockPallet $pallet): array
    {
        $item = $pallet->item;
        $defaultLocation = $item?->defaultLocation;

        return [
            'row_type' => 'stock',
            'client_id' => $pallet->client_id,
            'client_name' => $pallet->client?->name ?? $item?->client?->name ?? 'Cliente',
            'client_code' => $pallet->client?->code ?? $item?->client?->code ?? '',
            'item_id' => $item?->id,
            'sku' => $item?->sku ?? 'Sin SKU',
            'description' => $item?->description ?? 'Sin descripcion',
            'lot' => $pallet->lot,
            'lot_label' => $pallet->lot ?: 'Sin lote',
            'received_at' => $pallet->received_at?->format('d/m/Y'),
            'received_at_raw' => $pallet->received_at?->format('Y-m-d'),
            'item_status' => $item?->status ?? Item::STATUS_ACTIVE,
            'item_status_label' => $item?->statusLabel() ?? Item::statusLabelFor(Item::STATUS_ACTIVE),
            'batch_status' => $pallet->status,
            'batch_status_label' => $pallet->statusLabel(),
            'blocked_reason' => $pallet->blocked_reason,
            'location_label' => $this->locationLabel($pallet) ?: 'Sin ubicacion',
            'default_location_label' => $this->defaultLocationLabel($defaultLocation),
            'quantity_units' => (int) $pallet->quantity_units,
            'units_per_pallet' => max(1, (int) ($pallet->units_per_pallet ?? $item?->units_per_pallet ?? 1)),
            'full_pallets' => (int) $pallet->full_pallets,
            'peaks_count' => (int) $pallet->peaks_count,
            'peak_1' => (int) $pallet->peak_1,
            'peak_2' => (int) $pallet->peak_2,
            'peak_3' => (int) $pallet->peak_3,
            'peak_4' => (int) $pallet->peak_4,
            'peak_5' => (int) $pallet->peak_5,
            'peak_6' => (int) $pallet->peak_6,
            'peak_7' => (int) $pallet->peak_7,
            'peak_8' => (int) $pallet->peak_8,
            'has_stock' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWithoutStockRow(Item $item): array
    {
        return [
            'row_type' => 'master_without_stock',
            'client_id' => $item->client_id,
            'client_name' => $item->client->name,
            'client_code' => $item->client->code,
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => null,
            'lot_label' => 'Sin stock',
            'received_at' => null,
            'received_at_raw' => null,
            'item_status' => $item->status,
            'item_status_label' => $item->statusLabel(),
            'batch_status' => null,
            'batch_status_label' => 'Sin stock',
            'blocked_reason' => null,
            'location_label' => 'Sin ubicacion',
            'default_location_label' => $this->defaultLocationLabel($item->defaultLocation),
            'quantity_units' => 0,
            'units_per_pallet' => max(1, (int) $item->units_per_pallet),
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
            'has_stock' => false,
        ];
    }

    private function locationLabel(StockPallet $pallet): string
    {
        $locationCode = $pallet->location?->code;

        if (filled($locationCode)) {
            return trim((string) $locationCode);
        }

        return trim((string) ($pallet->location_text ?? ''));
    }

    private function defaultLocationLabel(mixed $location): string
    {
        if ($location === null) {
            return 'Sin ubicacion por defecto';
        }

        $warehouseCode = $location->warehouse?->code;

        return trim($location->code.($warehouseCode ? ' / '.$warehouseCode : ''));
    }
}
