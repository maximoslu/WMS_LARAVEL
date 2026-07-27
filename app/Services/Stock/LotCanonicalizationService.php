<?php

namespace App\Services\Stock;

use App\Models\Client;
use App\Models\StockPallet;
use App\Support\Stock\LotNormalizer;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Schema;

class LotCanonicalizationService
{
    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function run(?string $clientFilter = null, ?string $tableFilter = null, bool $apply = false): array
    {
        $client = $this->resolveClient($clientFilter);
        $tables = $this->tableDefinitions();

        if ($tableFilter !== null && $tableFilter !== '') {
            $tables = array_filter($tables, fn (array $definition): bool => $definition['table'] === $tableFilter);
        }

        $result = [
            'mode' => $apply ? 'apply' : 'dry-run',
            'client' => $client?->only(['id', 'code', 'name']),
            'canonical_lot' => LotNormalizer::NO_LOT,
            'tables' => [],
            'stock_collisions' => [],
            'stock_conflicts' => [],
            'stock_consolidated_groups' => 0,
            'records_changed' => 0,
            'records_deleted' => 0,
            'units_before' => 0,
            'units_after' => 0,
            'full_pallets_before' => 0,
            'full_pallets_after' => 0,
            'peaks_before' => 0,
            'peaks_after' => 0,
            'warehouse_pallets_before' => 0.0,
            'warehouse_pallets_after' => 0.0,
        ];

        $work = function () use ($tables, $client, $apply, &$result): void {
            foreach ($tables as $definition) {
                if (! Schema::hasTable($definition['table']) || ! Schema::hasColumn($definition['table'], 'lot')) {
                    continue;
                }

                $rows = $this->rowsFor($definition, $client);
                $aliases = $rows->filter(fn (object $row): bool => $this->needsCanonicalLot($row->lot));

                $tableSummary = [
                    'table' => $definition['table'],
                    'records_scanned' => $rows->count(),
                    'records_to_normalize' => $aliases->count(),
                    'variants' => $aliases
                        ->groupBy(fn (object $row): string => $this->variantLabel($row->lot))
                        ->map(fn (Collection $group): int => $group->count())
                        ->sortKeys()
                        ->all(),
                ];

                if ($definition['table'] === 'stock_pallets') {
                    $this->handleStockPallets($aliases, $apply, $result, $tableSummary);
                } elseif ($apply && $aliases->isNotEmpty()) {
                    $ids = $aliases->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
                    $payload = ['lot' => LotNormalizer::NO_LOT];

                    if (Schema::hasColumn($definition['table'], 'updated_at')) {
                        $payload['updated_at'] = now();
                    }

                    $changed = $this->db->table($definition['table'])
                        ->whereIn('id', $ids)
                        ->update($payload);

                    $result['records_changed'] += $changed;
                }

                $result['tables'][] = $tableSummary;
            }
        };

        if ($apply) {
            $this->db->transaction($work);
        } else {
            $work();
        }

        return $result;
    }

    public function resolveClient(?string $value): ?Client
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        return Client::query()
            ->where('id', is_numeric($value) ? (int) $value : 0)
            ->orWhere('code', $value)
            ->orWhere('name', $value)
            ->first();
    }

    /**
     * @return list<array{table:string, client:string}>
     */
    private function tableDefinitions(): array
    {
        return [
            ['table' => 'stock_pallets', 'client' => 'client_id'],
            ['table' => 'goods_receipt_lines', 'client' => 'goods_receipts.client_id'],
            ['table' => 'merchandise_request_lines', 'client' => 'merchandise_requests.client_id'],
            ['table' => 'goods_dispatch_lines', 'client' => 'goods_dispatches.client_id'],
            ['table' => 'goods_dispatch_line_allocations', 'client' => 'goods_dispatches.client_id'],
            ['table' => 'inventory_movements', 'client' => 'client_id'],
        ];
    }

    private function rowsFor(array $definition, ?Client $client): Collection
    {
        $table = $definition['table'];
        $query = $this->db->table($table)->select($table.'.id', $table.'.lot');

        match ($table) {
            'goods_receipt_lines' => $query->join('goods_receipts', 'goods_receipts.id', '=', 'goods_receipt_lines.goods_receipt_id'),
            'merchandise_request_lines' => $query->join('merchandise_requests', 'merchandise_requests.id', '=', 'merchandise_request_lines.merchandise_request_id'),
            'goods_dispatch_lines' => $query->join('goods_dispatches', 'goods_dispatches.id', '=', 'goods_dispatch_lines.goods_dispatch_id'),
            'goods_dispatch_line_allocations' => $query
                ->join('goods_dispatch_lines', 'goods_dispatch_lines.id', '=', 'goods_dispatch_line_allocations.goods_dispatch_line_id')
                ->join('goods_dispatches', 'goods_dispatches.id', '=', 'goods_dispatch_lines.goods_dispatch_id'),
            default => null,
        };

        if ($client instanceof Client) {
            $query->where($definition['client'], $client->id);
        }

        return $query->orderBy($table.'.id')->get();
    }

    private function handleStockPallets(Collection $aliases, bool $apply, array &$result, array &$tableSummary): void
    {
        if ($aliases->isEmpty()) {
            $tableSummary['simple_text_updates'] = 0;
            $tableSummary['collision_groups'] = 0;

            return;
        }

        $aliasIds = $aliases->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $aliasIdentityKeys = StockPallet::query()
            ->whereIn('id', $aliasIds)
            ->get()
            ->map(fn (StockPallet $stock): string => $this->stockIdentity($stock))
            ->unique()
            ->values();

        $stocks = StockPallet::query()
            ->orderBy('id')
            ->get()
            ->filter(fn (StockPallet $stock): bool => LotNormalizer::isNoLotAlias($stock->getRawOriginal('lot'))
                && $aliasIdentityKeys->contains($this->stockIdentity($stock)))
            ->values();

        $groups = $stocks->groupBy(fn (StockPallet $stock): string => $this->stockIdentity($stock));
        $simple = $groups->filter(fn (Collection $group): bool => $group->count() === 1);
        $collisions = $groups->filter(fn (Collection $group): bool => $group->count() > 1);

        $tableSummary['simple_text_updates'] = $simple->count();
        $tableSummary['collision_groups'] = $collisions->count();

        foreach ($simple as $group) {
            $stock = $group->first();

            if (! $stock instanceof StockPallet) {
                continue;
            }

            $this->addStockTotals($result, $stock, 'before');

            if ($apply) {
                $stock->forceFill(['lot' => LotNormalizer::NO_LOT])->save();
                $result['records_changed']++;
            }

            $this->addStockTotals($result, $stock->fresh() ?? $stock, 'after');
        }

        foreach ($collisions as $identity => $group) {
            $summary = $this->stockGroupSummary($identity, $group);
            $result['stock_collisions'][] = $summary;

            foreach ($group as $stock) {
                $this->addStockTotals($result, $stock, 'before');
            }

            if (! $this->canConsolidateStockGroup($group)) {
                if ($apply) {
                    $result['records_changed'] += $this->normalizeStockGroupLots($group);
                }

                $result['stock_conflicts'][] = [
                    ...$summary,
                    'reason' => $this->stockGroupConflictReason($group),
                ];

                foreach ($group as $stock) {
                    $this->addStockTotals($result, $stock->fresh() ?? $stock, 'after');
                }

                continue;
            }

            if ($apply) {
                $consolidated = $this->consolidateStockGroup($group);

                $result['records_deleted'] += $consolidated['deleted'];
                $result['records_changed']++;
                $result['stock_consolidated_groups']++;

                $target = StockPallet::query()->find($consolidated['target_id']);
                if ($target instanceof StockPallet) {
                    $this->addStockTotals($result, $target, 'after');
                }
            } else {
                $this->addStockGroupTotals($result, $group, 'after');
            }
        }
    }

    private function needsCanonicalLot(mixed $lot): bool
    {
        return LotNormalizer::isNoLotAlias($lot)
            && LotNormalizer::normalize($lot) !== $lot;
    }

    private function variantLabel(mixed $lot): string
    {
        if ($lot === null) {
            return '[NULL]';
        }

        $value = (string) $lot;

        if ($value === '') {
            return '[VACIO]';
        }

        if (trim($value) === '') {
            return '[ESPACIOS]';
        }

        return $value;
    }

    private function stockIdentity(StockPallet $stock): string
    {
        return implode('|', [
            (int) $stock->client_id,
            (int) $stock->item_id,
            (int) ($stock->goods_receipt_id ?? 0),
            (int) ($stock->stock_import_id ?? 0),
            (int) ($stock->location_id ?? 0),
            trim((string) ($stock->location_text ?? '')),
            (int) ($stock->units_per_pallet ?? 0),
            (string) $stock->status,
            (string) $stock->stock_category,
            (int) (bool) $stock->active,
        ]);
    }

    private function canConsolidateStockGroup(Collection $group): bool
    {
        if ($group->contains(fn (StockPallet $stock): bool => $stock->location_id === null)) {
            return false;
        }

        return $group
            ->flatMap(fn (StockPallet $stock): array => $this->positivePeaks($stock))
            ->count() <= StockPallet::MAX_PEAK_COLUMNS;
    }

    private function stockGroupConflictReason(Collection $group): string
    {
        if ($group->contains(fn (StockPallet $stock): bool => $stock->location_id === null)) {
            return 'No se consolida automaticamente: ubicacion/almacen ambiguo sin location_id.';
        }

        return 'No se consolida automaticamente: supera 10 picos o no mantiene identidad fisica compatible.';
    }

    private function normalizeStockGroupLots(Collection $group): int
    {
        $changed = 0;

        foreach ($group as $stock) {
            if (! $stock instanceof StockPallet || ! $this->needsCanonicalLot($stock->getRawOriginal('lot'))) {
                continue;
            }

            $stock->forceFill(['lot' => LotNormalizer::NO_LOT])->save();
            $changed++;
        }

        return $changed;
    }

    /**
     * @return array{deleted:int, target_id:int|null}
     */
    private function consolidateStockGroup(Collection $group): array
    {
        $target = $group->sortBy(function (StockPallet $stock): array {
            return [$stock->getRawOriginal('lot') === LotNormalizer::NO_LOT ? 0 : 1, $stock->id];
        })->first();

        if (! $target instanceof StockPallet) {
            return ['deleted' => 0, 'target_id' => null];
        }

        $duplicates = $group->reject(fn (StockPallet $stock): bool => (int) $stock->id === (int) $target->id);
        $peaks = $group->flatMap(fn (StockPallet $stock): array => $this->positivePeaks($stock))->values()->all();
        $target->forceFill([
            'lot' => LotNormalizer::NO_LOT,
            'quantity_units' => (int) $group->sum('quantity_units'),
            'full_pallets' => (int) $group->sum('full_pallets'),
            'peaks_count' => count($peaks),
            'warehouse_pallets' => (float) $group->sum(fn (StockPallet $stock): float => (float) ($stock->warehouse_pallets ?? 0)),
            ...collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
                ->mapWithKeys(fn (int $number): array => ['peak_'.$number => $peaks[$number - 1] ?? 0])
                ->all(),
        ])->save();

        $duplicateIds = $duplicates->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();

        if ($duplicateIds === []) {
            return ['deleted' => 0, 'target_id' => (int) $target->id];
        }

        foreach (['merchandise_request_lines', 'goods_dispatch_lines', 'goods_dispatch_line_allocations', 'inventory_movements'] as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'stock_pallet_id')) {
                $this->db->table($table)
                    ->whereIn('stock_pallet_id', $duplicateIds)
                    ->update(['stock_pallet_id' => $target->id]);
            }
        }

        return [
            'deleted' => StockPallet::query()->whereIn('id', $duplicateIds)->delete(),
            'target_id' => (int) $target->id,
        ];
    }

    /**
     * @return list<int>
     */
    private function positivePeaks(StockPallet $stock): array
    {
        return collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
            ->map(fn (int $number): int => (int) ($stock->{'peak_'.$number} ?? 0))
            ->filter(fn (int $value): bool => $value > 0)
            ->values()
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function stockGroupSummary(string $identity, Collection $group): array
    {
        return [
            'identity' => $identity,
            'ids' => $group->pluck('id')->values()->all(),
            'lots' => $group->map(fn (StockPallet $stock): mixed => $stock->getRawOriginal('lot'))->values()->all(),
            'units_before' => (int) $group->sum('quantity_units'),
            'full_pallets_before' => (int) $group->sum('full_pallets'),
            'peaks_before' => (int) $group->sum('peaks_count'),
            'warehouse_pallets_before' => (float) $group->sum(fn (StockPallet $stock): float => (float) ($stock->warehouse_pallets ?? 0)),
        ];
    }

    private function addStockTotals(array &$result, StockPallet $stock, string $suffix): void
    {
        $result['units_'.$suffix] += (int) $stock->quantity_units;
        $result['full_pallets_'.$suffix] += (int) $stock->full_pallets;
        $result['peaks_'.$suffix] += (int) $stock->peaks_count;
        $result['warehouse_pallets_'.$suffix] += (float) ($stock->warehouse_pallets ?? 0);
    }

    private function addStockGroupTotals(array &$result, Collection $group, string $suffix): void
    {
        $result['units_'.$suffix] += (int) $group->sum('quantity_units');
        $result['full_pallets_'.$suffix] += (int) $group->sum('full_pallets');
        $result['peaks_'.$suffix] += (int) $group->sum('peaks_count');
        $result['warehouse_pallets_'.$suffix] += (float) $group->sum(fn (StockPallet $stock): float => (float) ($stock->warehouse_pallets ?? 0));
    }
}
