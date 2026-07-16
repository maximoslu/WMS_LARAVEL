<?php

namespace App\Services\Locations;

use App\Models\Client;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\Locations\LocationCode;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LocationIntegrityService
{
    /** @var array<string, string> */
    public const REFERENCES = [
        'stock_pallets' => 'location_id',
        'goods_receipt_lines' => 'location_id',
        'items' => 'default_location_id',
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
                        $match->orWhereKey((int) $warehouseFilter);
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
        $normalized = LocationCode::normalize($group->first()->code);

        return $group
            ->sortBy(fn (Location $location): array => [
                $location->active ? 0 : 1,
                $location->code === $normalized ? 0 : 1,
                $location->id,
            ])
            ->first();
    }

    /** @return Collection<int, Location> */
    public function canonicalActiveLocations(Collection $locations): Collection
    {
        return $locations
            ->where('active', true)
            ->groupBy(fn (Location $location): string => $location->warehouse_id.'|'.LocationCode::normalize($location->code))
            ->map(fn (Collection $group): Location => $this->canonicalLocation($group))
            ->sortBy(fn (Location $location): array => [
                mb_strtoupper($location->warehouse?->name ?: $location->warehouse?->code ?: ''),
                ...LocationCode::naturalSortKey($location->code),
            ])
            ->values();
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

    /** @return array{stock: int, receipts: int, items: int} */
    public function referenceCounts(int $locationId): array
    {
        return [
            'stock' => DB::table('stock_pallets')->where('location_id', $locationId)->count(),
            'receipts' => DB::table('goods_receipt_lines')->where('location_id', $locationId)->count(),
            'items' => DB::table('items')->where('default_location_id', $locationId)->count(),
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
}
