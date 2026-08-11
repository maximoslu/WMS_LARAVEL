<?php

namespace App\Services\Stock;

use App\Models\StockPallet;
use App\Support\Locations\LocationCode;
use App\Support\Stock\LotNormalizer;
use Illuminate\Support\Str;

class StockImportSnapshotGuard
{
    /**
     * @return array{
     *     hash:string,
     *     batches:int,
     *     references:int,
     *     units:int,
     *     warehouse_pallets:float,
     *     reference_totals:array<string, array{sku:string,units:int,warehouse_pallets:float}>,
     *     identity_keys:list<string>
     * }
     */
    public function capture(int $clientId): array
    {
        $stocks = StockPallet::query()
            ->with(['item:id,sku', 'location:id,code'])
            ->where('client_id', $clientId)
            ->where('active', true)
            ->orderBy('id')
            ->get();

        $referenceTotals = [];
        $canonicalRows = [];
        $identityKeys = [];

        foreach ($stocks as $stock) {
            $sku = trim((string) $stock->item?->sku);
            $skuKey = Str::upper($sku);
            $warehousePallets = (float) ($stock->warehouse_pallets ?? ((int) $stock->full_pallets + (int) $stock->peaks_count));

            if ($skuKey !== '') {
                $referenceTotals[$skuKey] ??= [
                    'sku' => $sku,
                    'units' => 0,
                    'warehouse_pallets' => 0.0,
                ];
                $referenceTotals[$skuKey]['units'] += (int) $stock->quantity_units;
                $referenceTotals[$skuKey]['warehouse_pallets'] += $warehousePallets;
            }

            $peaks = collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
                ->map(fn (int $number): int => (int) ($stock->{'peak_'.$number} ?? 0))
                ->all();
            $identityKey = $this->identityKey([
                'sku' => $sku,
                'lot' => $stock->lot,
                'location_code' => $stock->location?->code,
                'location_text' => $stock->location_text,
                'units_per_pallet' => $stock->units_per_pallet,
                'status' => $stock->status,
                'stock_category' => $stock->stock_category,
                'blocked_reason' => $stock->blocked_reason,
            ]);
            $identityKeys[] = $identityKey;
            $canonicalRows[] = [
                'id' => (int) $stock->id,
                'identity' => $identityKey,
                'quantity_units' => (int) $stock->quantity_units,
                'full_pallets' => (int) $stock->full_pallets,
                'peaks_count' => (int) $stock->peaks_count,
                'warehouse_pallets' => number_format($warehousePallets, 2, '.', ''),
                'peaks' => $peaks,
            ];
        }

        ksort($referenceTotals);
        sort($identityKeys);

        return [
            'hash' => hash('sha256', json_encode($canonicalRows, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)),
            'batches' => $stocks->count(),
            'references' => count($referenceTotals),
            'units' => (int) $stocks->sum('quantity_units'),
            'warehouse_pallets' => round($stocks->sum(
                fn (StockPallet $stock): float => (float) ($stock->warehouse_pallets ?? ((int) $stock->full_pallets + (int) $stock->peaks_count)),
            ), 2),
            'reference_totals' => $referenceTotals,
            'identity_keys' => array_values(array_unique($identityKeys)),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $importRows
     * @return array<string, mixed>
     */
    public function compare(array $current, array $importRows): array
    {
        $importReferences = [];
        $importIdentityKeys = [];
        $importUnits = 0;
        $importWarehousePallets = 0.0;

        foreach ($importRows as $row) {
            $sku = trim((string) ($row['sku'] ?? ''));
            $skuKey = Str::upper($sku);

            if ($skuKey !== '') {
                $importReferences[$skuKey] = $sku;
            }

            $importIdentityKeys[] = $this->identityKey($row);
            $importUnits += (int) ($row['quantity_units'] ?? 0);
            $importWarehousePallets += (float) ($row['warehouse_pallets'] ?? ((int) ($row['full_pallets'] ?? 0) + (int) ($row['peaks_count'] ?? 0)));
        }

        $currentReferenceKeys = array_keys($current['reference_totals']);
        $importReferenceKeys = array_keys($importReferences);
        $disappearingReferenceKeys = array_values(array_diff($currentReferenceKeys, $importReferenceKeys));
        $newReferenceKeys = array_values(array_diff($importReferenceKeys, $currentReferenceKeys));
        $currentIdentityKeys = $current['identity_keys'];
        $importIdentityKeys = array_values(array_unique($importIdentityKeys));
        $disappearingIdentityKeys = array_values(array_diff($currentIdentityKeys, $importIdentityKeys));
        $unitsDelta = $importUnits - (int) $current['units'];
        $palletsDelta = round($importWarehousePallets - (float) $current['warehouse_pallets'], 2);
        $unitsToZero = 0;
        $palletsToZero = 0.0;

        foreach ($disappearingReferenceKeys as $key) {
            $unitsToZero += (int) ($current['reference_totals'][$key]['units'] ?? 0);
            $palletsToZero += (float) ($current['reference_totals'][$key]['warehouse_pallets'] ?? 0);
        }

        return [
            'base_snapshot_hash' => $current['hash'],
            'current_references' => (int) $current['references'],
            'imported_references' => count($importReferences),
            'new_references' => count($newReferenceKeys),
            'disappearing_references' => count($disappearingReferenceKeys),
            'current_batches' => (int) $current['batches'],
            'imported_batches' => count($importIdentityKeys),
            'disappearing_batches' => count($disappearingIdentityKeys),
            'current_units' => (int) $current['units'],
            'imported_units' => $importUnits,
            'units_delta' => $unitsDelta,
            'units_delta_percent' => $this->percentChange((float) $current['units'], (float) $importUnits),
            'current_warehouse_pallets' => (float) $current['warehouse_pallets'],
            'imported_warehouse_pallets' => round($importWarehousePallets, 2),
            'warehouse_pallets_delta' => $palletsDelta,
            'warehouse_pallets_delta_percent' => $this->percentChange((float) $current['warehouse_pallets'], $importWarehousePallets),
            'units_to_zero' => $unitsToZero,
            'warehouse_pallets_to_zero' => round($palletsToZero, 2),
            'disappearing_skus_sample' => array_values(array_slice(array_map(
                fn (string $key): string => (string) ($current['reference_totals'][$key]['sku'] ?? $key),
                $disappearingReferenceKeys,
            ), 0, 10)),
            'new_skus_sample' => array_values(array_slice(array_map(
                fn (string $key): string => (string) ($importReferences[$key] ?? $key),
                $newReferenceKeys,
            ), 0, 10)),
            'requires_reduction_acknowledgement' => $disappearingReferenceKeys !== []
                || $disappearingIdentityKeys !== []
                || $unitsDelta < 0
                || $palletsDelta < 0,
        ];
    }

    /** @param array<string, mixed> $row */
    private function identityKey(array $row): string
    {
        $location = LocationCode::normalize($row['location_code'] ?? $row['location_text'] ?? '');

        return hash('sha256', json_encode([
            'sku' => Str::upper(trim((string) ($row['sku'] ?? ''))),
            'lot' => mb_strtoupper(LotNormalizer::normalize($row['lot'] ?? null)),
            'location' => Str::upper($location),
            'units_per_pallet' => (int) ($row['units_per_pallet'] ?? 0),
            'status' => $row['status'] ?? StockPallet::STATUS_AVAILABLE,
            'stock_category' => $row['stock_category'] ?? StockPallet::CATEGORY_IN_USE,
            'blocked_reason' => Str::upper(trim((string) ($row['blocked_reason'] ?? ''))),
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
    }

    private function percentChange(float $before, float $after): ?float
    {
        if (abs($before) < 0.00001) {
            return null;
        }

        return round((($after - $before) / $before) * 100, 2);
    }
}
