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
                'total_pallets' => $stockRows->count(),
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
            'search' => trim((string) ($filters['search'] ?? '')),
            'lot' => trim((string) ($filters['lot'] ?? '')),
            'location' => trim((string) ($filters['location'] ?? '')),
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
            'description' => $item?->description ?? 'Sin descripción',
            'lot' => $pallet->lot,
            'lot_label' => $pallet->lot ?: 'Sin lote',
            'received_at' => $pallet->received_at?->format('d/m/Y'),
            'received_at_raw' => $pallet->received_at?->format('Y-m-d'),
            'item_status' => $item?->status ?? Item::STATUS_ACTIVE,
            'item_status_label' => $item?->statusLabel() ?? Item::statusLabelFor(Item::STATUS_ACTIVE),
            'batch_status' => $pallet->status,
            'batch_status_label' => $pallet->statusLabel(),
            'blocked_reason' => $pallet->blocked_reason,
            'location_label' => $this->locationLabel($pallet) ?: 'Sin ubicación',
            'default_location_label' => $this->defaultLocationLabel($defaultLocation),
            'quantity_units' => (int) $pallet->quantity_units,
            'units_per_pallet' => max(1, (int) ($item?->units_per_pallet ?? 1)),
            'pallet_code' => $pallet->pallet_code ?: 'Sin código',
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
            'location_label' => 'Sin ubicación',
            'default_location_label' => $this->defaultLocationLabel($item->defaultLocation),
            'quantity_units' => 0,
            'units_per_pallet' => max(1, (int) $item->units_per_pallet),
            'pallet_code' => '-',
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
            return 'Sin ubicación por defecto';
        }

        $warehouseCode = $location->warehouse?->code;

        return trim($location->code.($warehouseCode ? ' / '.$warehouseCode : ''));
    }
}
