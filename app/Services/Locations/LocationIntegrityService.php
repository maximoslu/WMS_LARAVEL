<?php

namespace App\Services\Locations;

use App\Models\Client;
use App\Models\Location;
use App\Models\StockPallet;
use App\Models\Warehouse;
use App\Support\Locations\LocationCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LocationIntegrityService
{
    /** @var array<string, list<string>> */
    public const REFERENCES = [
        'stock_pallets' => ['location_id'],
        'goods_receipt_lines' => ['location_id'],
        'items' => ['default_location_id'],
        'inventory_movements' => ['location_id', 'from_location_id', 'to_location_id'],
    ];

    public function resolveClient(?string $filter): ?Client
    {
        $filter = trim((string) $filter);

        if ($filter === '') {
            return null;
        }

        return Client::query()
            ->where(function (Builder $query) use ($filter): void {
                $query->where('code', $filter)->orWhere('name', $filter);

                if (ctype_digit($filter)) {
                    $query->orWhereKey((int) $filter);
                }
            })
            ->first();
    }

    /** @return Collection<int, Location> */
    public function locations(?Client $client, ?string $warehouseFilter): Collection
    {
        $warehouseFilter = trim((string) $warehouseFilter);

        $warehouseIds = Warehouse::query()
            ->when($warehouseFilter !== '', function (Builder $query) use ($warehouseFilter): void {
                $query->where(function (Builder $match) use ($warehouseFilter): void {
                    $match->where('code', $warehouseFilter)->orWhere('name', $warehouseFilter);

                    if (ctype_digit($warehouseFilter)) {
                        $match->orWhere('id', (int) $warehouseFilter);
                    }
                });
            })
            ->when($client instanceof Client, function (Builder $query) use ($client): void {
                $query->where(function (Builder $clientQuery) use ($client): void {
                    $clientQuery
                        ->where('client_id', $client->id)
                        ->orWhereHas('locations.stockPallets', fn (Builder $stockQuery) => $stockQuery->where('client_id', $client->id))
                        ->orWhereHas('locations.defaultItems', fn (Builder $itemQuery) => $itemQuery->where('client_id', $client->id))
                        ->orWhereHas('locations.goodsReceiptLines.goodsReceipt', fn (Builder $receiptQuery) => $receiptQuery->where('client_id', $client->id));
                });
            })
            ->pluck('id');

        $query = Location::query()
            ->with('warehouse.client')
            ->whereIn('warehouse_id', $warehouseIds)
            ->orderBy('warehouse_id');

        return LocationCode::applyNaturalOrder($query)
            ->orderBy('id')
            ->get();
    }

    /** @return Collection<int, Collection<int, Location>> */
    public function duplicateGroups(Collection $locations): Collection
    {
        return $locations
            ->groupBy(fn (Location $location): string => $location->warehouse_id.'|'.LocationCode::normalize($location->code))
            ->filter(fn (Collection $group): bool => $group->count() > 1)
            ->values();
    }

    /** @param Collection<int, Location> $group */
    public function canonicalLocation(Collection $group): Location
    {
        if ($group->count() === 1) {
            return $group->first();
        }

        $normalized = LocationCode::normalize($group->first()->code);

        return $group
            ->sortBy(function (Location $location) use ($normalized): array {
                $references = $this->referenceCounts($location->id);
                $referenceTotal = array_sum($references);

                return [
                    $location->active ? 0 : 1,
                    -$referenceTotal,
                    $location->code === $normalized ? 0 : 1,
                    $location->id,
                ];
            })
            ->first();
    }

    /** @return Collection<int, Location> */
    public function canonicalActiveLocations(Collection $locations): Collection
    {
        return $locations
            ->where('active', true)
            ->groupBy(fn (Location $location): string => $location->warehouse_id.'|'.LocationCode::normalize($location->code))
            ->map(fn (Collection $group): Location => $this->canonicalLocation($group))
            ->sortBy(fn (Location $location): array => $this->locationSortKey($location))
            ->values();
    }

    /** @return Collection<int, Location> */
    public function activeCanonicalLocationOptions(): Collection
    {
        return $this->canonicalActiveLocations(
            Location::query()
                ->with('warehouse.client')
                ->where('active', true)
                ->whereHas('warehouse', fn (Builder $query) => $query->where('active', true))
                ->get()
        );
    }

    /** @return Collection<int, Location> */
    public function compatibleLocationOptionsForClient(Client|int $client): Collection
    {
        $clientId = $client instanceof Client ? (int) $client->id : (int) $client;

        if ($clientId <= 0) {
            return collect();
        }

        $locations = $this->activeCanonicalLocationOptions();
        $warehouseClientIds = $this->clientIdsUsingWarehouses($locations->pluck('warehouse_id'));

        return $this->deduplicateLocationOptionsForClient(
            $locations
                ->filter(fn (Location $location): bool => $this->locationIsCompatibleForClient($location, $clientId, $warehouseClientIds))
                ->values(),
        );
    }

    /** @return Collection<int, Location> */
    public function canonicalActiveLocationsForStock(StockPallet $stockPallet): Collection
    {
        return $this->compatibleLocationOptionsForClient((int) $stockPallet->client_id);
    }

    public function isLocationCompatibleWithClient(int|Location $location, int $clientId): bool
    {
        $location = $location instanceof Location
            ? $location->loadMissing('warehouse')
            : Location::query()->with('warehouse')->find($location);

        if (! $location instanceof Location || ! $this->isBaseLocationCompatibleWithClient($location, $clientId)) {
            return false;
        }

        return $this->compatibleLocationOptionsForClient($clientId)
            ->contains(fn (Location $candidate): bool => (int) $candidate->id === (int) $location->id);
    }

    private function isBaseLocationCompatibleWithClient(Location $location, int $clientId): bool
    {
        $location->loadMissing('warehouse');

        if (! $location->active || ! $location->warehouse?->active) {
            return false;
        }

        if (! $this->isCanonicalActiveLocation($location)) {
            return false;
        }

        return $this->locationIsCompatibleForClient(
            $location,
            $clientId,
            $this->clientIdsUsingWarehouses(collect([(int) $location->warehouse_id])),
        );
    }

    /**
     * @param  Collection<int, Location>  $locations
     * @param  Collection<int, Client>  $clients
     * @return array<int, list<int>>
     */
    public function clientIdsForLocationOptions(Collection $locations, Collection $clients): array
    {
        $locations->each(fn (Location $location): Location => $location->loadMissing('warehouse'));
        $warehouseClientIds = $this->clientIdsUsingWarehouses($locations->pluck('warehouse_id'));
        $options = $locations
            ->mapWithKeys(fn (Location $location): array => [(int) $location->id => []])
            ->all();

        foreach ($clients as $client) {
            $clientId = (int) $client->id;

            if ($clientId <= 0) {
                continue;
            }

            $this->deduplicateLocationOptionsForClient(
                $locations
                    ->filter(fn (Location $location): bool => $this->locationIsCompatibleForClient($location, $clientId, $warehouseClientIds))
                    ->values()
            )->each(function (Location $location) use (&$options, $clientId): void {
                $options[(int) $location->id][] = $clientId;
            });
        }

        return collect($options)
            ->map(fn (array $clientIds): array => collect($clientIds)->unique()->values()->all())
            ->all();
    }

    public function isCanonicalActiveLocation(Location $location): bool
    {
        if (! $location->active) {
            return false;
        }

        return $this->canonicalActiveLocations(
            Location::query()
                ->with('warehouse')
                ->where('warehouse_id', $location->warehouse_id)
                ->where('active', true)
                ->get()
        )->contains(fn (Location $candidate): bool => (int) $candidate->id === (int) $location->id);
    }

    /** @return list<string> */
    public function expectedCodes(Warehouse $warehouse, ?Client $client): array
    {
        $warehouseIdentity = LocationCode::normalize(($warehouse->code ?? '').' '.($warehouse->name ?? ''));
        $isNave38 = preg_match('/(^|\s)(NAVE\s*)?38($|\s)/u', $warehouseIdentity) === 1;
        $clientCode = LocationCode::normalize($client?->code ?? $warehouse->client?->code ?? '');

        return $isNave38 && $clientCode === 'EDELVIVES'
            ? LocationCode::expectedEdelvivesCodes()
            : [];
    }

    /** @param Collection<int, Location> $locations
     * @return array{missing: list<string>, extras: list<string>}
     */
    public function seriesStatus(Collection $locations, ?Client $client): array
    {
        $warehouse = $locations->first()?->warehouse;

        if (! $warehouse instanceof Warehouse) {
            return ['missing' => [], 'extras' => []];
        }

        $expected = $this->expectedCodes($warehouse, $client);

        if ($expected === []) {
            return ['missing' => [], 'extras' => []];
        }

        $present = $locations
            ->map(fn (Location $location): string => LocationCode::normalize($location->code))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values()
            ->all();

        return [
            'missing' => array_values(array_diff($expected, $present)),
            'extras' => collect(array_diff($present, $expected))
                ->sortBy(fn (string $code): array => LocationCode::naturalSortKey($code))
                ->values()
                ->all(),
        ];
    }

    /** @return array{stock: int, receipts: int, items: int, movements: int} */
    public function referenceCounts(int $locationId): array
    {
        return [
            'stock' => DB::table('stock_pallets')->where('location_id', $locationId)->count(),
            'receipts' => DB::table('goods_receipt_lines')->where('location_id', $locationId)->count(),
            'items' => DB::table('items')->where('default_location_id', $locationId)->count(),
            'movements' => DB::table('inventory_movements')
                ->where('location_id', $locationId)
                ->orWhere('from_location_id', $locationId)
                ->orWhere('to_location_id', $locationId)
                ->count(),
        ];
    }

    /** @param Collection<int, Location> $locations
     * @return Collection<int, object>
     */
    public function stockMap(Collection $locations, ?Client $client): Collection
    {
        return DB::table('stock_pallets')
            ->join('items', 'items.id', '=', 'stock_pallets.item_id')
            ->join('locations', 'locations.id', '=', 'stock_pallets.location_id')
            ->whereIn('stock_pallets.location_id', $locations->pluck('id'))
            ->when($client instanceof Client, fn ($query) => $query->where('stock_pallets.client_id', $client->id))
            ->orderBy('stock_pallets.id')
            ->get([
                'stock_pallets.id',
                'stock_pallets.item_id',
                'items.sku',
                'stock_pallets.lot',
                'stock_pallets.location_id',
                'locations.code as location_code',
                'stock_pallets.quantity_units',
                'stock_pallets.full_pallets',
                'stock_pallets.peaks_count',
                'stock_pallets.warehouse_pallets',
                'stock_pallets.active',
            ]);
    }

    /** @param Collection<int, Location> $locations
     * @return array<int, array<string, mixed>>
     */
    public function stockSnapshot(Collection $locations, ?Client $client): array
    {
        $columns = [
            'stock_pallets.id', 'stock_pallets.client_id', 'stock_pallets.item_id',
            'stock_pallets.goods_receipt_id', 'stock_pallets.stock_import_id',
            'stock_pallets.location_text', 'stock_pallets.pallet_code', 'stock_pallets.lot',
            'stock_pallets.quantity_units', 'stock_pallets.units_per_pallet',
            'stock_pallets.full_pallets', 'stock_pallets.peaks_count', 'stock_pallets.warehouse_pallets',
            'stock_pallets.peak_1', 'stock_pallets.peak_2', 'stock_pallets.peak_3', 'stock_pallets.peak_4',
            'stock_pallets.peak_5', 'stock_pallets.peak_6', 'stock_pallets.peak_7', 'stock_pallets.peak_8',
            'stock_pallets.peak_9', 'stock_pallets.peak_10', 'stock_pallets.received_at',
            'stock_pallets.imported_at', 'stock_pallets.status', 'stock_pallets.stock_category',
            'stock_pallets.blocked_reason', 'stock_pallets.source_sheet', 'stock_pallets.notes',
            'stock_pallets.active', 'locations.code as resolved_location_code',
        ];

        return DB::table('stock_pallets')
            ->join('locations', 'locations.id', '=', 'stock_pallets.location_id')
            ->whereIn('stock_pallets.location_id', $locations->pluck('id'))
            ->when($client instanceof Client, fn ($query) => $query->where('stock_pallets.client_id', $client->id))
            ->orderBy('stock_pallets.id')
            ->get($columns)
            ->map(function (object $row): array {
                $values = (array) $row;
                $values['resolved_location_code'] = LocationCode::normalize($values['resolved_location_code']);

                return $values;
            })
            ->keyBy('id')
            ->all();
    }

    /** @return list<int> */
    private function clientIdsUsingWarehouse(int $warehouseId): array
    {
        return $this->clientIdsUsingWarehouses(collect([$warehouseId]))[$warehouseId] ?? [];
    }

    /**
     * @param  Collection<int, int>  $warehouseIds
     * @return array<int, list<int>>
     */
    private function clientIdsUsingWarehouses(Collection $warehouseIds): array
    {
        $warehouseIds = $warehouseIds
            ->map(fn (mixed $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values();

        if ($warehouseIds->isEmpty()) {
            return [];
        }

        $usage = array_fill_keys($warehouseIds->all(), []);
        $append = function (object $row) use (&$usage): void {
            $warehouseId = (int) $row->warehouse_id;
            $clientId = (int) $row->client_id;

            if ($warehouseId > 0 && $clientId > 0) {
                $usage[$warehouseId][] = $clientId;
            }
        };

        DB::table('stock_pallets')
            ->join('locations', 'locations.id', '=', 'stock_pallets.location_id')
            ->whereIn('locations.warehouse_id', $warehouseIds)
            ->get(['locations.warehouse_id as warehouse_id', 'stock_pallets.client_id as client_id'])
            ->each($append);

        DB::table('items')
            ->join('locations', 'locations.id', '=', 'items.default_location_id')
            ->whereIn('locations.warehouse_id', $warehouseIds)
            ->get(['locations.warehouse_id as warehouse_id', 'items.client_id as client_id'])
            ->each($append);

        DB::table('goods_receipt_lines')
            ->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id')
            ->join('locations', 'locations.id', '=', 'goods_receipt_lines.location_id')
            ->whereIn('locations.warehouse_id', $warehouseIds)
            ->get(['locations.warehouse_id as warehouse_id', 'goods_receipts.client_id as client_id'])
            ->each($append);

        return collect($usage)
            ->map(fn (array $clientIds): array => collect($clientIds)
                ->unique()
                ->sort()
                ->values()
                ->all())
            ->all();
    }

    /**
     * @param  array<int, list<int>>  $warehouseClientIds
     */
    private function locationIsCompatibleForClient(Location $location, int $clientId, array $warehouseClientIds): bool
    {
        $location->loadMissing('warehouse');

        if ($clientId <= 0 || ! $location->active || ! $location->warehouse?->active) {
            return false;
        }

        $warehouseClientId = $location->warehouse?->client_id;

        if ($warehouseClientId !== null) {
            return (int) $warehouseClientId === $clientId;
        }

        $clientIds = $warehouseClientIds[(int) $location->warehouse_id]
            ?? $this->clientIdsUsingWarehouse((int) $location->warehouse_id);

        return $clientIds === [] || in_array($clientId, $clientIds, true);
    }

    /** @return Collection<int, Location> */
    private function deduplicateLocationOptionsForClient(Collection $locations): Collection
    {
        return $locations
            ->groupBy(fn (Location $location): string => $this->locationOptionGroupKey($location))
            ->map(fn (Collection $group): Location => $this->canonicalLocation($group))
            ->sortBy(fn (Location $location): array => $this->locationSortKey($location))
            ->values();
    }

    private function locationOptionGroupKey(Location $location): string
    {
        $location->loadMissing('warehouse');

        return $this->warehouseOptionIdentity($location->warehouse).'|'.LocationCode::normalize($location->code);
    }

    private function warehouseOptionIdentity(?Warehouse $warehouse): string
    {
        $warehouseName = trim((string) ($warehouse?->name ?: $warehouse?->code));
        $warehouseIdentity = LocationCode::normalize(($warehouse?->code ?? '').' '.$warehouseName);

        if (preg_match('/(^|\s)(NAVE\s*)?38($|\s)/u', $warehouseIdentity) === 1) {
            return 'NAVE 38';
        }

        return LocationCode::normalize($warehouseName);
    }

    /** @return array<int|string, mixed> */
    private function locationSortKey(Location $location): array
    {
        return [
            mb_strtoupper($this->warehouseOptionIdentity($location->warehouse)),
            ...LocationCode::naturalSortKey($location->code),
            $location->id,
        ];
    }
}
