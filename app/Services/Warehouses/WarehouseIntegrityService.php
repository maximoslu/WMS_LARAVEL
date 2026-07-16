<?php

namespace App\Services\Warehouses;

use App\Models\Client;
use App\Models\Location;
use App\Models\Warehouse;
use App\Services\Locations\LocationIntegrityService;
use App\Support\Locations\LocationCode;
use App\Support\Warehouses\WarehouseCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class WarehouseIntegrityService
{
    /** @var array<string, string> */
    public const WAREHOUSE_REFERENCES = [
        'bookings' => 'warehouse_id',
    ];

    public function __construct(private readonly LocationIntegrityService $locations)
    {
    }

    public function resolveClient(?string $filter): ?Client
    {
        return $this->locations->resolveClient($filter);
    }

    /** @return Collection<int, Warehouse> */
    public function candidateWarehouses(?Client $client, ?string $warehouseCode): Collection
    {
        $code = WarehouseCode::normalize($warehouseCode);

        return Warehouse::query()
            ->with(['client', 'locations' => fn ($query) => $query->orderBy('id')])
            ->when($client instanceof Client, function (Builder $query) use ($client): void {
                $query->where(function (Builder $scope) use ($client): void {
                    $scope->where('client_id', $client->id)->orWhereNull('client_id');
                });
            })
            ->orderBy('id')
            ->get()
            ->filter(fn (Warehouse $warehouse): bool => $this->matchesWarehouse($warehouse, $code))
            ->values();
    }

    public function canonicalWarehouse(Collection $warehouses): ?Warehouse
    {
        return $warehouses
            ->sortBy(fn (Warehouse $warehouse): array => [
                $warehouse->active ? 0 : 1,
                -$this->totalReferenceCount($warehouse),
                WarehouseCode::normalize($warehouse->code) === '38' ? 0 : 1,
                $warehouse->id,
            ])
            ->first();
    }

    /** @param Collection<int, Warehouse> $warehouses
     * @return Collection<int, Location>
     */
    public function locationsForWarehouses(Collection $warehouses): Collection
    {
        $warehouseIds = $warehouses->pluck('id');

        if ($warehouseIds->isEmpty()) {
            return collect();
        }

        $query = Location::query()
            ->with('warehouse.client')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderBy('warehouse_id');

        return LocationCode::applyNaturalOrder($query)
            ->orderBy('id')
            ->get();
    }

    /** @param Collection<int, Location> $locations
     * @return Collection<int, Collection<int, Location>>
     */
    public function duplicateLocationGroupsAcrossWarehouses(Collection $locations): Collection
    {
        return $locations
            ->groupBy(fn (Location $location): string => LocationCode::normalize($location->code))
            ->filter(fn (Collection $group, string $code): bool => $code !== '' && $group->count() > 1)
            ->values();
    }

    /** @param Collection<int, Location> $group */
    public function canonicalLocation(Collection $group, Warehouse $canonicalWarehouse): Location
    {
        $normalized = LocationCode::normalize($group->first()->code);

        return $group
            ->sortBy(fn (Location $location): array => [
                $location->warehouse_id === $canonicalWarehouse->id ? 0 : 1,
                $location->active ? 0 : 1,
                $location->code === $normalized ? 0 : 1,
                -array_sum($this->locations->referenceCounts($location->id)),
                $location->id,
            ])
            ->first();
    }

    /** @return array{locations: int, stock: int, receipts: int, dispatches: int, items: int, bookings: int, total: int} */
    public function warehouseReferenceCounts(Warehouse $warehouse): array
    {
        $locationIds = Location::query()->where('warehouse_id', $warehouse->id)->pluck('id');

        $counts = [
            'locations' => $locationIds->count(),
            'stock' => $locationIds->isEmpty()
                ? 0
                : DB::table('stock_pallets')->whereIn('location_id', $locationIds)->count(),
            'receipts' => $locationIds->isEmpty()
                ? 0
                : DB::table('goods_receipt_lines')->whereIn('location_id', $locationIds)->count(),
            'dispatches' => $this->dispatchReferenceCount($locationIds),
            'items' => $locationIds->isEmpty()
                ? 0
                : DB::table('items')->whereIn('default_location_id', $locationIds)->count(),
            'bookings' => DB::table('bookings')->where('warehouse_id', $warehouse->id)->count(),
        ];
        $counts['total'] = array_sum($counts);

        return $counts;
    }

    /** @return array{stock: int, receipts: int, dispatches: int, items: int} */
    public function locationReferenceCounts(Location $location): array
    {
        return [
            ...$this->locations->referenceCounts($location->id),
            'dispatches' => $this->dispatchReferenceCount(collect([$location->id])),
        ];
    }

    /** @return array{missing: list<string>, extras: list<string>} */
    public function seriesStatus(Collection $locations, ?Client $client): array
    {
        return $this->locations->seriesStatus($locations, $client);
    }

    /** @return array<int, array<string, mixed>> */
    public function stockSnapshot(Collection $locations, ?Client $client): array
    {
        return $this->locations->stockSnapshot($locations, $client);
    }

    /** @param Collection<int, Warehouse> $warehouses
     * @return Collection<int, Warehouse>
     */
    public function canonicalActiveWarehouses(Collection $warehouses): Collection
    {
        return $warehouses
            ->where('active', true)
            ->groupBy(fn (Warehouse $warehouse): string => ($warehouse->client_id ?? 'global').'|'.WarehouseCode::normalize($warehouse->code))
            ->map(fn (Collection $group): Warehouse => $this->canonicalWarehouse($group))
            ->filter()
            ->sortBy(fn (Warehouse $warehouse): array => [
                $warehouse->client_id === null ? 0 : 1,
                ...WarehouseCode::naturalSortKey($warehouse->code),
                mb_strtoupper($warehouse->name),
                $warehouse->id,
            ])
            ->values();
    }

    private function matchesWarehouse(Warehouse $warehouse, string $code): bool
    {
        if ($code === '') {
            return true;
        }

        $normalizedCode = WarehouseCode::normalize($warehouse->code);
        $normalizedName = WarehouseCode::normalize($warehouse->name);

        return $normalizedCode === $code
            || $normalizedName === $code
            || $normalizedName === 'NAVE '.$code
            || $normalizedName === 'NAVE'.$code;
    }

    private function totalReferenceCount(Warehouse $warehouse): int
    {
        return $this->warehouseReferenceCounts($warehouse)['total'];
    }

    /** @param Collection<int, int> $locationIds */
    private function dispatchReferenceCount(Collection $locationIds): int
    {
        if ($locationIds->isEmpty()) {
            return 0;
        }

        $stockIds = DB::table('stock_pallets')->whereIn('location_id', $locationIds)->pluck('id');

        if ($stockIds->isEmpty()) {
            return 0;
        }

        return DB::table('goods_dispatch_lines')->whereIn('stock_pallet_id', $stockIds)->count()
            + DB::table('goods_dispatch_line_allocations')->whereIn('stock_pallet_id', $stockIds)->count();
    }
}
