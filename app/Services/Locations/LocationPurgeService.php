<?php

namespace App\Services\Locations;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class LocationPurgeService
{
    /** @return array{warehouse: Warehouse|null, location_ids: list<int>, locations: int, stock: int, stock_text: int, items: int, receipt_lines: int, movements: int, warehouses: Collection<int, Warehouse>} */
    public function plan(?Warehouse $warehouse = null): array
    {
        $locations = Location::query()
            ->with('warehouse')
            ->when($warehouse instanceof Warehouse, fn (Builder $query) => $query->where('warehouse_id', $warehouse->id))
            ->get();
        $locationIds = $locations->pluck('id')->map(fn (mixed $id): int => (int) $id)->values()->all();
        $stockQuery = DB::table('stock_pallets')->whereIn('location_id', $locationIds);
        $movementCount = $locationIds === [] || ! Schema::hasTable('inventory_movements')
            ? 0
            : DB::table('inventory_movements')
                ->where(function ($query) use ($locationIds): void {
                    $query
                        ->whereIn('location_id', $locationIds)
                        ->orWhereIn('from_location_id', $locationIds)
                        ->orWhereIn('to_location_id', $locationIds);
                })
                ->count();

        return [
            'warehouse' => $warehouse,
            'location_ids' => $locationIds,
            'locations' => count($locationIds),
            'stock' => (clone $stockQuery)->count(),
            'stock_text' => $warehouse instanceof Warehouse
                ? (clone $stockQuery)->whereNotNull('location_text')->where('location_text', '<>', '')->count()
                : DB::table('stock_pallets')->whereNotNull('location_text')->where('location_text', '<>', '')->count(),
            'items' => DB::table('items')->whereIn('default_location_id', $locationIds)->count(),
            'receipt_lines' => DB::table('goods_receipt_lines')->whereIn('location_id', $locationIds)->count(),
            'movements' => $movementCount,
            'warehouses' => $locations->pluck('warehouse')->filter()->unique('id')->values(),
        ];
    }

    /** @return array{warehouse: Warehouse|null, location_ids: list<int>, locations: int, stock: int, stock_text: int, items: int, receipt_lines: int, movements: int, warehouses: Collection<int, Warehouse>, deleted: int} */
    public function apply(?Warehouse $warehouse = null): array
    {
        return DB::transaction(function () use ($warehouse): array {
            $plan = $this->plan($warehouse);
            $locationIds = $plan['location_ids'];

            if ($locationIds === [] && $warehouse instanceof Warehouse) {
                return [...$plan, 'deleted' => 0];
            }

            if ($locationIds !== []) {
                DB::table('stock_pallets')
                    ->whereIn('location_id', $locationIds)
                    ->update([
                        'location_id' => null,
                        'location_text' => null,
                        'updated_at' => now(),
                    ]);
            }

            if (! $warehouse instanceof Warehouse) {
                DB::table('stock_pallets')
                    ->whereNotNull('location_text')
                    ->where('location_text', '<>', '')
                    ->update([
                        'location_text' => null,
                        'updated_at' => now(),
                    ]);
            }

            if ($locationIds !== []) {
                DB::table('items')
                    ->whereIn('default_location_id', $locationIds)
                    ->update([
                        'default_location_id' => null,
                        'updated_at' => now(),
                    ]);

                DB::table('goods_receipt_lines')
                    ->whereIn('location_id', $locationIds)
                    ->update([
                        'location_id' => null,
                        'updated_at' => now(),
                    ]);
            }

            $deleted = $locationIds === [] ? 0 : Location::query()->whereIn('id', $locationIds)->delete();

            return [...$plan, 'deleted' => $deleted];
        });
    }

    public function resolveWarehouse(?string $filter): ?Warehouse
    {
        $filter = trim((string) $filter);

        if ($filter === '') {
            return null;
        }

        return Warehouse::query()
            ->where(function (Builder $query) use ($filter): void {
                $query->where('code', $filter)->orWhere('name', $filter);

                if (ctype_digit($filter)) {
                    $query->orWhere('id', (int) $filter);
                }
            })
            ->first();
    }
}
