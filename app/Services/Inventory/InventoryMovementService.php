<?php

namespace App\Services\Inventory;

use App\Jobs\EvaluateStockAlertsJob;
use App\Models\InventoryMovement;
use App\Models\StockPallet;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InventoryMovementService
{
    /** @return array<string, mixed> */
    public function snapshot(?StockPallet $stockPallet): array
    {
        if (! $stockPallet instanceof StockPallet) {
            return [
                'client_id' => null,
                'client_name' => null,
                'item_id' => null,
                'sku' => null,
                'description' => null,
                'lot' => null,
                'stock_pallet_id' => null,
                'warehouse_id' => null,
                'location_id' => null,
                'units' => 0,
                'full_pallets' => 0,
                'warehouse_pallets' => 0,
                'peaks' => array_fill(0, StockPallet::MAX_PEAK_COLUMNS, 0),
                'active' => false,
                'status' => null,
                'stock_category' => null,
            ];
        }

        $stockPallet->loadMissing(['client', 'item', 'location.warehouse']);

        return [
            'client_id' => (int) $stockPallet->client_id,
            'client_name' => $stockPallet->client?->name,
            'item_id' => $stockPallet->item_id !== null ? (int) $stockPallet->item_id : null,
            'sku' => $stockPallet->item?->sku,
            'description' => $stockPallet->item?->description,
            'lot' => $stockPallet->lot,
            'stock_pallet_id' => $stockPallet->exists ? (int) $stockPallet->id : null,
            'warehouse_id' => $stockPallet->location?->warehouse_id,
            'location_id' => $stockPallet->location_id,
            'units' => (int) $stockPallet->quantity_units,
            'full_pallets' => (int) $stockPallet->full_pallets,
            'warehouse_pallets' => (float) ($stockPallet->warehouse_pallets ?? 0),
            'peaks' => collect(range(1, StockPallet::MAX_PEAK_COLUMNS))
                ->map(fn (int $index): int => (int) ($stockPallet->{'peak_'.$index} ?? 0))
                ->all(),
            'active' => (bool) $stockPallet->active,
            'status' => $stockPallet->status,
            'stock_category' => $stockPallet->stock_category,
        ];
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $after
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        array $before,
        array $after,
        string $movementType,
        string $idempotencyKey,
        string $correlationId,
        ?Model $source = null,
        ?Model $sourceLine = null,
        ?User $user = null,
        ?Carbon $effectiveAt = null,
        array $metadata = [],
        string $sourceLabel = 'live',
        string $confidence = 'exact',
        ?int $reversalOfId = null,
    ): InventoryMovement {
        $existing = InventoryMovement::query()->where('idempotency_key', $idempotencyKey)->first();

        if ($existing instanceof InventoryMovement) {
            return $existing;
        }

        $beforePeaks = array_values($before['peaks'] ?? []);
        $afterPeaks = array_values($after['peaks'] ?? []);
        $peakCount = max(count($beforePeaks), count($afterPeaks), StockPallet::MAX_PEAK_COLUMNS);
        $peakDelta = [];

        for ($index = 0; $index < $peakCount; $index++) {
            $peakDelta[] = (int) ($afterPeaks[$index] ?? 0) - (int) ($beforePeaks[$index] ?? 0);
        }

        $clientId = $after['client_id'] ?? $before['client_id'] ?? null;

        if ($clientId === null) {
            throw new \InvalidArgumentException('Un movimiento de inventario necesita client_id.');
        }

        $movement = InventoryMovement::query()->create([
            'uuid' => (string) Str::uuid(),
            'correlation_id' => $correlationId,
            'idempotency_key' => $idempotencyKey,
            'client_id' => (int) $clientId,
            'client_name' => $after['client_name'] ?? $before['client_name'] ?? null,
            'item_id' => $after['item_id'] ?? $before['item_id'] ?? null,
            'sku' => $after['sku'] ?? $before['sku'] ?? null,
            'description' => $after['description'] ?? $before['description'] ?? null,
            'lot' => $after['lot'] ?? $before['lot'] ?? null,
            'stock_pallet_id' => $after['stock_pallet_id'] ?? $before['stock_pallet_id'] ?? null,
            'movement_type' => $movementType,
            'source' => $sourceLabel,
            'source_type' => $source?->getMorphClass(),
            'source_id' => $source?->getKey(),
            'source_line_type' => $sourceLine?->getMorphClass(),
            'source_line_id' => $sourceLine?->getKey(),
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_role' => $user?->role?->slug,
            'warehouse_id' => $after['warehouse_id'] ?? $before['warehouse_id'] ?? null,
            'location_id' => $after['location_id'] ?? $before['location_id'] ?? null,
            'from_warehouse_id' => $before['warehouse_id'] ?? null,
            'from_location_id' => $before['location_id'] ?? null,
            'to_warehouse_id' => $after['warehouse_id'] ?? null,
            'to_location_id' => $after['location_id'] ?? null,
            'units_before' => (int) ($before['units'] ?? 0),
            'units_delta' => (int) ($after['units'] ?? 0) - (int) ($before['units'] ?? 0),
            'units_after' => (int) ($after['units'] ?? 0),
            'full_pallets_before' => (int) ($before['full_pallets'] ?? 0),
            'full_pallets_delta' => (int) ($after['full_pallets'] ?? 0) - (int) ($before['full_pallets'] ?? 0),
            'full_pallets_after' => (int) ($after['full_pallets'] ?? 0),
            'warehouse_pallets_before' => (float) ($before['warehouse_pallets'] ?? 0),
            'warehouse_pallets_delta' => (float) ($after['warehouse_pallets'] ?? 0) - (float) ($before['warehouse_pallets'] ?? 0),
            'warehouse_pallets_after' => (float) ($after['warehouse_pallets'] ?? 0),
            'peaks_before' => $beforePeaks,
            'peaks_delta' => $peakDelta,
            'peaks_after' => $afterPeaks,
            'metadata' => [
                ...$metadata,
                'active_before' => $before['active'] ?? null,
                'active_after' => $after['active'] ?? null,
                'status_before' => $before['status'] ?? null,
                'status_after' => $after['status'] ?? null,
                'stock_category_before' => $before['stock_category'] ?? null,
                'stock_category_after' => $after['stock_category'] ?? null,
            ],
            'reconstruction_confidence' => $confidence,
            'reversal_of_id' => $reversalOfId,
            'effective_at' => $effectiveAt ?? now(),
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        if ($movement->item_id !== null) {
            DB::afterCommit(fn () => EvaluateStockAlertsJob::dispatch(
                (int) $movement->client_id,
                (int) $movement->item_id,
            ));
        }

        return $movement;
    }
}
