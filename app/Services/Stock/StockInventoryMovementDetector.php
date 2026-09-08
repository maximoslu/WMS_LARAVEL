<?php

namespace App\Services\Stock;

use App\Models\InventoryMovement;
use App\Models\StockInventoryLocation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StockInventoryMovementDetector
{
    /**
     * @param  Collection<int, StockInventoryLocation>  $locations
     * @return array<string, int>
     */
    public function latestRelevantMovementIds(int $clientId, Collection $locations): array
    {
        $checked = $locations->filter(fn (StockInventoryLocation $location): bool => $location->checked_at !== null);

        if ($checked->isEmpty()) {
            return [];
        }

        $minimumWatermark = (int) $checked->min('checked_movement_id');
        $trackedLocationIds = $checked->pluck('location_id')->filter()->map(fn (mixed $id): int => (int) $id)->unique();
        $tracksUnlocated = $checked->contains(fn (StockInventoryLocation $location): bool => $location->location_id === null);

        $movements = InventoryMovement::query()
            ->where('client_id', $clientId)
            ->where('id', '>', $minimumWatermark)
            ->whereIn('movement_type', $this->physicalMovementTypes())
            ->where(fn (Builder $query) => $query->whereNull('source')->orWhere('source', '!=', 'backfill'))
            ->where(function (Builder $query) use ($trackedLocationIds, $tracksUnlocated): void {
                if ($trackedLocationIds->isNotEmpty()) {
                    $query
                        ->whereIn('location_id', $trackedLocationIds)
                        ->orWhereIn('from_location_id', $trackedLocationIds)
                        ->orWhereIn('to_location_id', $trackedLocationIds);
                }

                if ($tracksUnlocated) {
                    $method = $trackedLocationIds->isNotEmpty() ? 'orWhere' : 'where';
                    $query->{$method}(function (Builder $unlocated): void {
                        $unlocated
                            ->where(function (Builder $physical): void {
                                $physical->whereNull('location_id')->whereNull('to_location_id');
                            })
                            ->orWhere(function (Builder $transfer): void {
                                $transfer
                                    ->where('movement_type', InventoryMovement::TRANSFER)
                                    ->where(function (Builder $side): void {
                                        $side->whereNull('from_location_id')->orWhereNull('to_location_id');
                                    });
                            });
                    });
                }
            })
            ->orderBy('id')
            ->get();

        $latest = [];

        foreach ($movements as $movement) {
            if (! $this->changesPhysicalStock($movement)) {
                continue;
            }

            foreach ($this->affectedScopeKeys($movement) as $scopeKey) {
                $latest[$scopeKey] = max($latest[$scopeKey] ?? 0, (int) $movement->id);
            }
        }

        return $latest;
    }

    public function currentWatermark(int $clientId): int
    {
        return (int) (InventoryMovement::query()->where('client_id', $clientId)->max('id') ?? 0);
    }

    /** @return list<string> */
    private function physicalMovementTypes(): array
    {
        return [
            InventoryMovement::RECEIPT,
            InventoryMovement::DISPATCH,
            InventoryMovement::MANUAL_ADJUSTMENT,
            InventoryMovement::IMPORT,
            InventoryMovement::IMPORT_RETIREMENT,
            InventoryMovement::TRANSFER,
            InventoryMovement::REVERSAL,
            InventoryMovement::CORRECTION,
        ];
    }

    private function changesPhysicalStock(InventoryMovement $movement): bool
    {
        return (int) $movement->units_delta !== 0
            || (int) $movement->full_pallets_delta !== 0
            || abs((float) $movement->warehouse_pallets_delta) > 0.0001
            || ($movement->movement_type === InventoryMovement::TRANSFER
                && $movement->from_location_id !== $movement->to_location_id);
    }

    /** @return list<string> */
    private function affectedScopeKeys(InventoryMovement $movement): array
    {
        if ($movement->movement_type === InventoryMovement::TRANSFER) {
            return collect([$movement->from_location_id, $movement->to_location_id])
                ->map(fn (mixed $id): string => $id === null ? 'unlocated' : 'location:'.(int) $id)
                ->unique()
                ->values()
                ->all();
        }

        $locationId = $movement->location_id ?? $movement->to_location_id ?? $movement->from_location_id;

        return [$locationId === null ? 'unlocated' : 'location:'.(int) $locationId];
    }
}
