<?php

namespace App\Services\Traceability;

use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\StockPallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ClientInventoryAnalyticsService
{
    public function __construct(private readonly StockForecastService $forecast) {}

    /** @return array<string, mixed> */
    public function analyze(
        int $clientId,
        Carbon $from,
        Carbon $to,
        ?int $itemId = null,
        ?string $category = null,
        ?string $lot = null,
        ?int $warehouseId = null,
    ): array {
        $base = InventoryMovement::query()
            ->where('client_id', $clientId)
            ->whereBetween('effective_at', [$from->startOfDay(), $to->endOfDay()])
            ->when($itemId !== null, fn ($query) => $query->where('item_id', $itemId))
            ->when(filled($lot), fn ($query) => $query->where('lot', $lot))
            ->when($category !== null, fn ($query) => $query->where(fn ($scope) => $scope
                ->where('metadata->stock_category_after', $category)
                ->orWhere('metadata->stock_category_before', $category)))
            ->when($warehouseId !== null, fn ($query) => $query->where(fn ($scope) => $scope
                ->where('warehouse_id', $warehouseId)
                ->orWhere('from_warehouse_id', $warehouseId)
                ->orWhere('to_warehouse_id', $warehouseId)));
        $rankings = (clone $base)
            ->select(['item_id', 'sku', 'description'])
            ->selectRaw('SUM(CASE WHEN units_delta > 0 THEN units_delta ELSE 0 END) as inbound_units')
            ->selectRaw('SUM(CASE WHEN units_delta < 0 THEN ABS(units_delta) ELSE 0 END) as outbound_units')
            ->selectRaw('SUM(ABS(units_delta)) as rotation_units')
            ->groupBy('item_id', 'sku', 'description')
            ->orderByDesc(DB::raw('SUM(ABS(units_delta))'))
            ->limit(50)
            ->get();
        $movedItemIds = (clone $base)->whereNotNull('item_id')->distinct()->pluck('item_id');
        $withoutMovement = Item::query()
            ->where('client_id', $clientId)
            ->where('active', true)
            ->when($itemId !== null, fn ($query) => $query->whereKey($itemId))
            ->when($category !== null, fn ($query) => $query->where('stock_category', $category))
            ->when(filled($lot) || $warehouseId !== null, fn ($query) => $query->whereHas('stockPallets', fn ($stockQuery) => $stockQuery
                ->where('active', true)
                ->when(filled($lot), fn ($lotQuery) => $lotQuery->where('lot', $lot))
                ->when($warehouseId !== null, fn ($warehouseQuery) => $warehouseQuery->whereHas('location', fn ($locationQuery) => $locationQuery->where('warehouse_id', $warehouseId)))))
            ->whereNotIn('id', $movedItemIds)
            ->withSum(['stockPallets as current_units' => fn ($query) => $query->where('active', true)], 'quantity_units')
            ->orderByDesc('current_units')
            ->limit(25)
            ->get();
        $stock = StockPallet::query()
            ->where('client_id', $clientId)
            ->where('active', true)
            ->when($itemId !== null, fn ($query) => $query->where('item_id', $itemId))
            ->when($category !== null, fn ($query) => $query->where('stock_category', $category))
            ->when(filled($lot), fn ($query) => $query->where('lot', $lot))
            ->when($warehouseId !== null, fn ($query) => $query->whereHas('location', fn ($locationQuery) => $locationQuery->where('warehouse_id', $warehouseId)));
        $stockSummary = [
            'available_units' => (int) (clone $stock)
                ->where('status', StockPallet::STATUS_AVAILABLE)
                ->where('stock_category', StockPallet::CATEGORY_IN_USE)
                ->sum('quantity_units'),
            'blocked_units' => (int) (clone $stock)
                ->where(fn ($query) => $query->where('status', StockPallet::STATUS_BLOCKED)->orWhere('stock_category', StockPallet::CATEGORY_BLOCKED))
                ->sum('quantity_units'),
            'obsolete_units' => (int) (clone $stock)
                ->where(fn ($query) => $query->where('status', StockPallet::STATUS_OBSOLETE)->orWhere('stock_category', StockPallet::CATEGORY_OBSOLETE))
                ->sum('quantity_units'),
            'active_lots' => (clone $stock)->whereNotNull('lot')->distinct()->count('lot'),
        ];
        $selectedItems = Item::query()
            ->where('client_id', $clientId)
            ->when($itemId !== null, fn ($query) => $query->whereKey($itemId))
            ->when($category !== null, fn ($query) => $query->where('stock_category', $category))
            ->when($itemId === null, fn ($query) => $query->whereIn('id', $rankings->pluck('item_id')->filter()->take(10)))
            ->get();
        $forecasts = $selectedItems->mapWithKeys(fn (Item $item): array => [
            $item->id => ['item' => $item, 'forecast' => $this->forecast->forecast($item)],
        ]);

        return [
            'rankings' => $rankings,
            'without_movement' => $withoutMovement,
            'stock_summary' => $stockSummary,
            'forecasts' => $forecasts,
            'total_inbound' => (int) $rankings->sum('inbound_units'),
            'total_outbound' => (int) $rankings->sum('outbound_units'),
            'manual_adjustments' => (clone $base)->where('movement_type', InventoryMovement::MANUAL_ADJUSTMENT)->count(),
            'incomplete_traceability' => (clone $base)->where('reconstruction_confidence', '!=', 'exact')->count(),
            'abc' => $this->abc($rankings),
        ];
    }

    /** @return Collection<int, array<string, mixed>> */
    private function abc(Collection $rankings): Collection
    {
        $total = max(1, (int) $rankings->sum('outbound_units'));
        $running = 0;

        return $rankings->sortByDesc('outbound_units')->values()->map(function ($row) use (&$running, $total): array {
            $running += (int) $row->outbound_units;
            $percentage = ($running / $total) * 100;

            return [
                'item_id' => $row->item_id,
                'sku' => $row->sku,
                'outbound_units' => (int) $row->outbound_units,
                'class' => $percentage <= 80 ? 'A' : ($percentage <= 95 ? 'B' : 'C'),
            ];
        });
    }
}
