<?php

namespace App\Support\Stock;

use App\Models\Item;
use App\Models\User;
use App\Models\StockPallet;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class StockOverviewBuilder
{
    /**
     * @param  array<string, mixed>  $filters
     * @return array{filters: array<string, mixed>, rows: Collection<int, array<string, mixed>>, paginator: LengthAwarePaginator, summary: array<string, int>}
     */
    public function build(User $user, array $filters = []): array
    {
        $normalizedFilters = $this->normalizeFilters($user, $filters);
        $paginator = match ($normalizedFilters['stock_state']) {
            'with_stock' => $this->paginateStockRows($normalizedFilters),
            'without_stock' => $this->paginateWithoutStockRows($normalizedFilters),
            default => $this->paginateAllRows($normalizedFilters),
        };
        $rows = collect($paginator->items());
        $summaryQuery = $this->stockQuery($normalizedFilters);

        return [
            'filters' => $normalizedFilters,
            'rows' => $rows->values(),
            'paginator' => $paginator,
            'summary' => [
                'references_with_stock' => (clone $summaryQuery)->distinct('item_id')->count('item_id'),
                'total_units' => (int) (clone $summaryQuery)->sum('quantity_units'),
                'total_pallets' => (int) (clone $summaryQuery)->sum('full_pallets'),
                'total_full_pallets' => (int) (clone $summaryQuery)->sum('full_pallets'),
                'total_peaks' => (int) (clone $summaryQuery)->sum('peaks_count'),
                'total_logistic_units' => (int) ((clone $summaryQuery)->sum(DB::raw('full_pallets + peaks_count'))),
                'batches_with_peaks' => (clone $summaryQuery)->where('peaks_count', '>', 0)->count(),
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
            ->where(function (Builder $query): void {
                $query
                    ->where('quantity_units', '>', 0)
                    ->orWhere('full_pallets', '>', 0)
                    ->orWhere('peaks_count', '>', 0);
            })
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
            ->when($filters['only_peaks'], fn (Builder $query) => $query->where('peaks_count', '>', 0))
            ->orderBy('received_at')
            ->orderBy('lot')
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
            ->whereDoesntHave('stockPallets', function (Builder $query): void {
                $query
                    ->where('active', true)
                    ->where(function (Builder $query): void {
                        $query
                            ->where('quantity_units', '>', 0)
                            ->orWhere('full_pallets', '>', 0)
                            ->orWhere('peaks_count', '>', 0);
                    });
            })
            ->orderBy('client_id')
            ->orderBy('sku');
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function normalizeFilters(User $user, array $filters): array
    {
        $stockState = (string) ($filters['stock_state'] ?? 'with_stock');
        $isClient = $user->hasRole('cliente');

        return [
            'client_id' => $this->resolveClientId($user, $filters['client_id'] ?? null),
            'item_id' => $isClient ? null : (isset($filters['item_id']) && (int) $filters['item_id'] > 0
                ? (int) $filters['item_id']
                : null),
            'search' => trim((string) ($filters['search'] ?? '')),
            'lot' => trim((string) ($filters['lot'] ?? '')),
            'location' => $isClient ? '' : trim((string) ($filters['location'] ?? '')),
            'location_id' => $isClient
                ? null
                : (isset($filters['location_id']) && (int) $filters['location_id'] > 0
                    ? (int) $filters['location_id']
                    : null),
            'per_page' => in_array((int) ($filters['per_page'] ?? 25), [25, 50, 100], true)
                ? (int) ($filters['per_page'] ?? 25)
                : 25,
            'only_peaks' => filter_var($filters['only_peaks'] ?? false, FILTER_VALIDATE_BOOL),
            'batch_status' => in_array((string) ($filters['batch_status'] ?? 'all'), ['all', ...StockPallet::statuses()], true)
                ? (string) ($filters['batch_status'] ?? 'all')
                : 'all',
            'stock_state' => in_array($stockState, ['with_stock', 'without_stock', 'all'], true)
                ? $stockState
                : 'with_stock',
            'is_client' => $isClient,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginationQuery(array $filters): array
    {
        $query = [
            'search' => $filters['search'],
            'lot' => $filters['lot'],
            'only_peaks' => $filters['only_peaks'] ? 1 : 0,
            'per_page' => $filters['per_page'],
            'stock_state' => $filters['stock_state'],
            'batch_status' => $filters['batch_status'],
        ];

        if (! $filters['is_client']) {
            $query['client_id'] = $filters['client_id'];
            $query['item_id'] = $filters['item_id'];
            $query['location'] = $filters['location'];
            $query['location_id'] = $filters['location_id'];
        }

        return array_filter(
            $query,
            fn (mixed $value): bool => ! in_array($value, [null, ''], true)
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function buildStockRow(StockPallet $pallet): array
    {
        $item = $pallet->item;
        $defaultLocation = $item?->defaultLocation;

        return [
            'id' => $pallet->id,
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
            'units_per_pallet' => $pallet->units_per_pallet !== null
                ? (int) $pallet->units_per_pallet
                : (int) ($item?->units_per_pallet ?? 0),
            'units_per_pallet_label' => $this->unitsPerPalletLabel(
                $pallet->units_per_pallet !== null
                    ? (int) $pallet->units_per_pallet
                    : (int) ($item?->units_per_pallet ?? 0),
            ),
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
            'peak_9' => (int) $pallet->peak_9,
            'peak_10' => (int) $pallet->peak_10,
            'row_visual_state' => $this->rowVisualState($item?->status, $pallet->status),
            'has_stock' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function buildWithoutStockRow(Item $item): array
    {
        return [
            'id' => null,
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
            'units_per_pallet' => (int) $item->units_per_pallet,
            'units_per_pallet_label' => $this->unitsPerPalletLabel((int) $item->units_per_pallet),
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
            'row_visual_state' => $this->rowVisualState($item->status, null),
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

    private function unitsPerPalletLabel(int $unitsPerPallet): string
    {
        return $unitsPerPallet > 0
            ? number_format($unitsPerPallet, 0, ',', '.')
            : 'Sin dato';
    }

    private function rowVisualState(?string $itemStatus, ?string $batchStatus): string
    {
        if ($batchStatus === StockPallet::STATUS_BLOCKED) {
            return 'blocked';
        }

        if ($batchStatus === StockPallet::STATUS_OBSOLETE || $itemStatus === Item::STATUS_OBSOLETE) {
            return 'obsolete';
        }

        return 'normal';
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginateStockRows(array $filters): LengthAwarePaginator
    {
        $paginator = $this->stockQuery($filters)
            ->paginate(
                perPage: $filters['per_page'],
                columns: ['*'],
                pageName: 'page',
            );

        return $paginator
            ->appends($this->paginationQuery($filters))
            ->through(fn (StockPallet $pallet): array => $this->buildStockRow($pallet));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginateWithoutStockRows(array $filters): LengthAwarePaginator
    {
        $paginator = $this->withoutStockQuery($filters)
            ->paginate(
                perPage: $filters['per_page'],
                columns: ['*'],
                pageName: 'page',
            );

        return $paginator
            ->appends($this->paginationQuery($filters))
            ->through(fn (Item $item): array => $this->buildWithoutStockRow($item));
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function paginateAllRows(array $filters): LengthAwarePaginator
    {
        $stockIndexQuery = $this->stockIndexQuery($filters);
        $withoutStockIndexQuery = $this->withoutStockIndexQuery($filters);
        $page = LengthAwarePaginator::resolveCurrentPage('page');
        $perPage = $filters['per_page'];
        $unionQuery = $stockIndexQuery->unionAll($withoutStockIndexQuery);
        $rowsPage = DB::query()
            ->fromSub($unionQuery, 'stock_index')
            ->orderBy('sort_group')
            ->orderBy('received_at_sort')
            ->orderBy('sku_sort')
            ->orderBy('lot_sort')
            ->orderBy('row_id')
            ->forPage($page, $perPage)
            ->get();
        $total = DB::query()
            ->fromSub($unionQuery, 'stock_index')
            ->count();
        $stockIds = $rowsPage
            ->where('row_type', 'stock')
            ->pluck('row_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $withoutStockIds = $rowsPage
            ->where('row_type', 'master_without_stock')
            ->pluck('row_id')
            ->map(fn ($id) => (int) $id)
            ->all();
        $stockRows = StockPallet::query()
            ->with(['client', 'item.client', 'item.defaultLocation.warehouse', 'location.warehouse'])
            ->whereIn('id', $stockIds)
            ->get()
            ->keyBy('id');
        $withoutStockRows = Item::query()
            ->with(['client', 'defaultLocation.warehouse'])
            ->whereIn('id', $withoutStockIds)
            ->get()
            ->keyBy('id');
        $items = $rowsPage->map(function (object $row) use ($stockRows, $withoutStockRows): ?array {
            if ($row->row_type === 'stock') {
                $pallet = $stockRows->get((int) $row->row_id);

                return $pallet ? $this->buildStockRow($pallet) : null;
            }

            $item = $withoutStockRows->get((int) $row->row_id);

            return $item ? $this->buildWithoutStockRow($item) : null;
        })->filter()->values();

        return new LengthAwarePaginator(
            items: $items,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => LengthAwarePaginator::resolveCurrentPath(),
                'pageName' => 'page',
                'query' => $this->paginationQuery($filters),
            ],
        );
    }

    private function resolveClientId(User $user, mixed $requestedClientId): ?int
    {
        if ($user->hasRole('cliente')) {
            return $user->client_id !== null ? (int) $user->client_id : null;
        }

        return isset($requestedClientId) && (int) $requestedClientId > 0
            ? (int) $requestedClientId
            : null;
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function stockIndexQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        return $this->stockQuery($filters)
            ->getQuery()
            ->join('items', 'stock_pallets.item_id', '=', 'items.id')
            ->selectRaw("'stock' as row_type")
            ->selectRaw('stock_pallets.id as row_id')
            ->selectRaw('0 as sort_group')
            ->selectRaw("COALESCE(DATE_FORMAT(stock_pallets.received_at, '%Y-%m-%d'), '9999-12-31') as received_at_sort")
            ->selectRaw('items.sku as sku_sort')
            ->selectRaw("COALESCE(stock_pallets.lot, '') as lot_sort");
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function withoutStockIndexQuery(array $filters): \Illuminate\Database\Query\Builder
    {
        return $this->withoutStockQuery($filters)
            ->getQuery()
            ->selectRaw("'master_without_stock' as row_type")
            ->selectRaw('items.id as row_id')
            ->selectRaw('1 as sort_group')
            ->selectRaw("'9999-12-31' as received_at_sort")
            ->selectRaw('items.sku as sku_sort')
            ->selectRaw("'' as lot_sort");
    }
}
