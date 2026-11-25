<?php

namespace App\Services\GoodsDispatches;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsDispatchLineAllocation;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\StockPallet;
use App\Models\User;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Stock\LotNormalizer;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockDispatchAllocationService
{
    public function __construct(private readonly InventoryMovementService $movements) {}

    /**
     * Deducts the real loaded quantities of a dispatch from client stock.
     * Must be called inside a DB transaction. Not idempotent by itself —
     * callers must guard on GoodsDispatch::hasStockApplied() before calling.
     */
    public function apply(GoodsDispatch $dispatch, ?User $user = null, ?string $correlationId = null): void
    {
        $correlationId ??= (string) Str::uuid();
        $lines = $dispatch->lines()->with('allocations')->get();

        foreach ($lines as $line) {
            if ($line->allocations->isNotEmpty()) {
                foreach ($line->allocations as $allocation) {
                    $this->applyAllocation($dispatch, $line, $allocation, $user, $correlationId);
                }

                continue;
            }

            $loadedPallets = $line->loadedPallets();
            $loadedPartialUnits = $line->loadedPartialUnits();

            if ($loadedPallets <= 0 && $loadedPartialUnits <= 0) {
                continue;
            }

            if ($loadedPallets > 0) {
                $this->applyPalletLine($dispatch, $line, $user, $correlationId);
            }

            if ($loadedPartialUnits > 0) {
                $this->applyPartialUnits($dispatch, $line, $loadedPartialUnits, $user, $correlationId);
            }
        }
    }

    private function applyAllocation(
        GoodsDispatch $dispatch,
        GoodsDispatchLine $line,
        GoodsDispatchLineAllocation $allocation,
        ?User $user,
        string $correlationId,
    ): void {
        $loadedPallets = $allocation->loadedPallets();
        $loadedPartialUnits = $allocation->loadedPartialUnits();

        if ($loadedPallets <= 0 && $loadedPartialUnits <= 0) {
            return;
        }

        $stockPallet = StockPallet::query()
            ->whereKey($allocation->stock_pallet_id)
            ->lockForUpdate()
            ->first();

        if (! $stockPallet instanceof StockPallet) {
            throw $this->unresolvedStockError($line);
        }

        $this->guardSameClient($dispatch, $stockPallet);
        $before = $this->movements->snapshot($stockPallet);

        $availablePallets = min($loadedPallets, max(0, (int) $stockPallet->full_pallets));
        $missingPallets = $loadedPallets - $availablePallets;
        $unitsPerPallet = (int) ($allocation->units_per_pallet ?: $stockPallet->units_per_pallet ?: $line->units_per_pallet);

        if ($loadedPallets > 0) {
            $this->guardUnitsPerPallet($line, $unitsPerPallet);
        }

        if ($availablePallets > 0) {
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - ($availablePallets * $unitsPerPallet));
            $this->decrementWarehousePallets($stockPallet, $availablePallets);
        }

        $missingPartialUnits = $loadedPartialUnits > 0
            ? $this->applyPartialUnitsToStockPallet($stockPallet, $line, $loadedPartialUnits, $allocation->selectedPeakUnitsByIndex(), $availablePallets)
            : 0;

        $stockPallet->save();
        $this->recordDispatchMovement(
            $dispatch,
            $line,
            $stockPallet,
            $before,
            $user,
            $correlationId,
            "allocation:{$allocation->id}",
            ['allocation_id' => $allocation->id],
        );

        $this->recordStockDeficit(
            $dispatch, $line, ($missingPallets * $unitsPerPallet) + $missingPartialUnits,
            $missingPallets, $stockPallet->lot, $user, $correlationId, "allocation:{$allocation->id}:deficit",
        );
    }

    private function applyPartialUnits(
        GoodsDispatch $dispatch,
        GoodsDispatchLine $line,
        int $units,
        ?User $user,
        string $correlationId,
    ): void {
        if ($line->stock_pallet_id === null) {
            $this->recordStockDeficit($dispatch, $line, $units, 0, $line->lot, $user, $correlationId, 'partial:deficit');

            return;
        }

        $stockPallet = StockPallet::query()
            ->whereKey($line->stock_pallet_id)
            ->lockForUpdate()
            ->first();

        if (! $stockPallet instanceof StockPallet) {
            throw $this->unresolvedStockError($line);
        }

        $this->guardSameClient($dispatch, $stockPallet);
        $before = $this->movements->snapshot($stockPallet);

        $missingUnits = $this->applyPartialUnitsToStockPallet($stockPallet, $line, $units, [], 0);

        $stockPallet->save();
        $this->recordDispatchMovement(
            $dispatch,
            $line,
            $stockPallet,
            $before,
            $user,
            $correlationId,
            'partial',
        );

        $this->recordStockDeficit($dispatch, $line, $missingUnits, 0, $stockPallet->lot, $user, $correlationId, 'partial:deficit');
    }

    /**
     * @param  array<int, int>  $selectedPeakUnitsByIndex
     */
    private function applyPartialUnitsToStockPallet(
        StockPallet $stockPallet,
        GoodsDispatchLine $line,
        int $units,
        array $selectedPeakUnitsByIndex = [],
        int $alreadyLoadedPallets = 0,
    ): int {
        $remaining = max(0, $units);
        $warehousePalletsRemoved = 0;

        foreach ($selectedPeakUnitsByIndex as $peakIndex => $selectedUnits) {
            if ($remaining <= 0) {
                break;
            }

            $peakField = 'peak_'.$peakIndex;
            $availableUnits = max(0, (int) $stockPallet->{$peakField});
            $selectedUnits = max(0, (int) $selectedUnits);

            if ($selectedUnits <= 0) {
                continue;
            }

            $take = min($remaining, $selectedUnits, $availableUnits);
            $stockPallet->{$peakField} = $availableUnits - $take;
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - $take);
            $remaining -= $take;

            if ($take > 0 && $take === $availableUnits) {
                $warehousePalletsRemoved++;
            }
        }

        $peakOrder = $this->peakConsumptionOrder($line, array_keys($selectedPeakUnitsByIndex));

        foreach ($peakOrder as $peakIndex) {
            if ($remaining <= 0) {
                break;
            }

            $peakField = 'peak_'.$peakIndex;
            $availableUnits = max(0, (int) $stockPallet->{$peakField});

            if ($availableUnits <= 0) {
                continue;
            }

            $take = min($remaining, $availableUnits);
            $stockPallet->{$peakField} = $availableUnits - $take;
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - $take);
            $remaining -= $take;

            if ($take === $availableUnits) {
                $warehousePalletsRemoved++;
            }
        }

        $breakableFullPallets = max(0, (int) $stockPallet->full_pallets - $alreadyLoadedPallets);

        while ($remaining > 0) {
            if ($breakableFullPallets <= 0) {
                break;
            }

            $unitsPerPallet = max(0, (int) ($stockPallet->units_per_pallet ?: $line->units_per_pallet));

            if ($unitsPerPallet <= 0) {
                break;
            }

            $take = min($remaining, $unitsPerPallet);
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - $take);
            $remaining -= $take;
            $breakableFullPallets--;

            if ($take < $unitsPerPallet) {
                $this->storeBrokenPalletRemainder($stockPallet, $line, $unitsPerPallet - $take);
            } else {
                $warehousePalletsRemoved++;
            }
        }

        $this->decrementWarehousePallets($stockPallet, $warehousePalletsRemoved);

        return $remaining;
    }

    private function applyPalletLine(
        GoodsDispatch $dispatch,
        GoodsDispatchLine $line,
        ?User $user,
        string $correlationId,
    ): void {
        $remaining = $line->loadedPallets();

        if ($line->stock_pallet_id !== null) {
            $stockPallet = StockPallet::query()
                ->whereKey($line->stock_pallet_id)
                ->lockForUpdate()
                ->first();

            if (! $stockPallet instanceof StockPallet) {
                throw $this->unresolvedStockError($line);
            }

            $this->guardSameClient($dispatch, $stockPallet);
            $before = $this->movements->snapshot($stockPallet);

            $availablePallets = min($remaining, max(0, (int) $stockPallet->full_pallets));
            $unitsPerPallet = (int) ($stockPallet->units_per_pallet ?: $line->units_per_pallet);
            $this->guardUnitsPerPallet($line, $unitsPerPallet);
            $stockPallet->quantity_units = max(0, (int) $stockPallet->quantity_units - ($availablePallets * $unitsPerPallet));
            $this->decrementWarehousePallets($stockPallet, $availablePallets);
            $stockPallet->save();
            $this->recordDispatchMovement(
                $dispatch,
                $line,
                $stockPallet,
                $before,
                $user,
                $correlationId,
                'pallet',
            );

            $this->recordStockDeficit(
                $dispatch, $line, ($remaining - $availablePallets) * $unitsPerPallet,
                $remaining - $availablePallets, $stockPallet->lot, $user, $correlationId, 'pallet:deficit',
            );

            return;
        }

        $batches = StockPallet::query()
            ->where('client_id', $dispatch->client_id)
            ->where('item_id', $line->item_id)
            ->where('active', true)
            ->where('status', StockPallet::STATUS_AVAILABLE)
            ->where('full_pallets', '>', 0)
            ->when(
                LotNormalizer::normalize($line->lot) !== LotNormalizer::NO_LOT,
                fn ($query) => $query->where('lot', LotNormalizer::normalize($line->lot)),
            )
            ->orderBy('received_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $take = min($remaining, (int) $batch->full_pallets);

            if ($take <= 0) {
                continue;
            }

            $unitsPerPallet = (int) ($batch->units_per_pallet ?: $line->units_per_pallet);
            $before = $this->movements->snapshot($batch);
            $batch->quantity_units = max(0, (int) $batch->quantity_units - ($take * $unitsPerPallet));
            $this->decrementWarehousePallets($batch, $take);
            $batch->save();
            $line->allocations()->create([
                'stock_pallet_id' => $batch->id,
                'lot' => $batch->lot,
                'location_text' => $batch->location_text,
                'units_per_pallet' => max(0, (int) $batch->units_per_pallet),
                'loaded_pallets' => $take,
                'loaded_partial_units' => 0,
                'selected_peaks' => [],
            ]);
            $this->recordDispatchMovement(
                $dispatch,
                $line,
                $batch,
                $before,
                $user,
                $correlationId,
                'pallet-batch-'.$batch->id,
            );

            $remaining -= $take;
        }

        if ($remaining > 0) {
            $unitsPerPallet = (int) $line->units_per_pallet;
            $this->guardUnitsPerPallet($line, $unitsPerPallet);
            $this->recordStockDeficit(
                $dispatch, $line, $remaining * $unitsPerPallet, $remaining,
                $line->lot, $user, $correlationId, 'pallet:deficit',
            );
        }
    }

    /**
     * @param  array<string, mixed>  $before
     * @param  array<string, mixed>  $metadata
     */
    private function recordDispatchMovement(
        GoodsDispatch $dispatch,
        GoodsDispatchLine $line,
        StockPallet $stockPallet,
        array $before,
        ?User $user,
        string $correlationId,
        string $segment,
        array $metadata = [],
    ): void {
        $after = $this->movements->snapshot($stockPallet->fresh(['client', 'item', 'location.warehouse']));

        $this->movements->record(
            before: $before,
            after: $after,
            movementType: InventoryMovement::DISPATCH,
            idempotencyKey: "dispatch:{$dispatch->id}:line:{$line->id}:{$segment}:{$correlationId}",
            correlationId: $correlationId,
            source: $dispatch,
            sourceLine: $line,
            user: $user,
            effectiveAt: $dispatch->sent_at ?? now(),
            metadata: [
                ...$metadata,
                'destination' => $line->destination_location ?? $dispatch->merchandiseRequest?->delivery_address,
                'delivery_reference' => $dispatch->merchandiseRequest?->delivery_reference,
            ],
        );
    }

    /**
     * Repairs the warehouse-pallet metric for legacy dispatches whose units were
     * already deducted. Partial-unit legacy repairs are rejected because their
     * original peak shape cannot be reconstructed safely after the fact.
     */
    public function applyWarehousePalletsOnly(GoodsDispatch $dispatch, ?User $user = null, ?string $correlationId = null): void
    {
        $correlationId ??= (string) Str::uuid();
        $lines = $dispatch->lines()->with('allocations')->get();

        foreach ($lines as $line) {
            if ($line->loadedPartialUnits() > 0) {
                throw ValidationException::withMessages([
                    'dispatch' => sprintf(
                        'La reparacion automatica de pallets almacen no es segura para %s porque contiene picos o unidades parciales.',
                        $this->lineLabel($line),
                    ),
                ]);
            }

            if ($line->allocations->isNotEmpty()) {
                foreach ($line->allocations as $allocation) {
                    $this->decrementLegacyWarehousePallets(
                        $dispatch,
                        $line,
                        (int) $allocation->stock_pallet_id,
                        $allocation->loadedPallets(),
                        $user,
                        $correlationId,
                    );
                }

                continue;
            }

            if ($line->loadedPallets() <= 0) {
                continue;
            }

            if ($line->stock_pallet_id === null) {
                throw ValidationException::withMessages([
                    'dispatch' => sprintf(
                        'No se puede reparar %s sin una partida de stock trazada.',
                        $this->lineLabel($line),
                    ),
                ]);
            }

            $this->decrementLegacyWarehousePallets(
                $dispatch,
                $line,
                (int) $line->stock_pallet_id,
                $line->loadedPallets(),
                $user,
                $correlationId,
            );
        }
    }

    private function decrementLegacyWarehousePallets(
        GoodsDispatch $dispatch,
        GoodsDispatchLine $line,
        int $stockPalletId,
        int $pallets,
        ?User $user,
        string $correlationId,
    ): void {
        if ($pallets <= 0) {
            return;
        }

        $stockPallet = StockPallet::query()
            ->whereKey($stockPalletId)
            ->lockForUpdate()
            ->first();

        if (! $stockPallet instanceof StockPallet) {
            throw $this->unresolvedStockError($line);
        }

        $this->guardSameClient($dispatch, $stockPallet);
        $before = $this->movements->snapshot($stockPallet);

        if ($this->warehousePallets($stockPallet) < $pallets) {
            throw ValidationException::withMessages([
                'dispatch' => sprintf(
                    'No se puede reparar %s: el contador de pallets almacen es menor que la carga trazada.',
                    $this->lineLabel($line),
                ),
            ]);
        }

        $this->decrementWarehousePallets($stockPallet, $pallets);
        $stockPallet->save();

        $after = $this->movements->snapshot($stockPallet->fresh(['client', 'item', 'location.warehouse']));
        $this->movements->record(
            before: $before,
            after: $after,
            movementType: InventoryMovement::CORRECTION,
            idempotencyKey: "dispatch-warehouse-repair:{$dispatch->id}:line:{$line->id}:stock:{$stockPallet->id}:{$correlationId}",
            correlationId: $correlationId,
            source: $dispatch,
            sourceLine: $line,
            user: $user,
            metadata: ['reason' => 'Reparacion del contador de pallets de almacen.'],
        );
    }

    private function recordStockDeficit(
        GoodsDispatch $dispatch,
        GoodsDispatchLine $line,
        int $missingUnits,
        int $missingPallets,
        ?string $lot,
        ?User $user,
        string $correlationId,
        string $segment,
    ): void {
        if ($missingUnits <= 0) {
            return;
        }

        $clientId = Item::query()->whereKey($line->item_id)->value('client_id');

        if ($clientId === null || (int) $clientId !== (int) $dispatch->client_id) {
            throw $this->unresolvedStockError($line);
        }

        // Keep the physical batch nonnegative and show the discrepancy in its own audited balance.
        $deficit = StockPallet::query()->create([
            'client_id' => $dispatch->client_id,
            'item_id' => $line->item_id,
            'lot' => $lot,
            'quantity_units' => 0,
            'units_per_pallet' => max(0, (int) $line->units_per_pallet),
            'warehouse_pallets' => 0,
            'notes' => 'Déficit de stock generado por la salida '.$dispatch->dispatchNumber().'; revisar y regularizar.',
            'active' => true,
            'status' => StockPallet::STATUS_AVAILABLE,
        ]);
        $before = $this->movements->snapshot($deficit);
        $deficit->quantity_units = -$missingUnits;
        $deficit->warehouse_pallets = -$missingPallets;
        $deficit->save();

        $this->recordDispatchMovement(
            $dispatch, $line, $deficit, $before, $user, $correlationId, $segment,
            ['stock_deficit' => true, 'missing_units' => $missingUnits, 'missing_pallets' => $missingPallets],
        );
    }

    private function guardUnitsPerPallet(GoodsDispatchLine $line, int $unitsPerPallet): void
    {
        if ($unitsPerPallet <= 0) {
            throw ValidationException::withMessages([
                'status' => sprintf('No se puede calcular el stock de %s sin unidades por pallet.', $this->lineLabel($line)),
            ]);
        }
    }

    private function decrementWarehousePallets(StockPallet $stockPallet, int $pallets): void
    {
        if ($pallets <= 0) {
            return;
        }

        $stockPallet->warehouse_pallets = max(0, $this->warehousePallets($stockPallet) - $pallets);
    }

    private function warehousePallets(StockPallet $stockPallet): float
    {
        return $stockPallet->warehouse_pallets !== null
            ? (float) $stockPallet->warehouse_pallets
            : (float) ((int) $stockPallet->full_pallets + (int) $stockPallet->peaks_count);
    }

    private function guardSameClient(GoodsDispatch $dispatch, StockPallet $stockPallet): void
    {
        if ((int) $stockPallet->client_id !== (int) $dispatch->client_id) {
            throw ValidationException::withMessages([
                'status' => 'No se puede enviar la salida: una linea hace referencia a stock de otro cliente.',
            ]);
        }
    }

    private function insufficientStockError(GoodsDispatchLine $line): ValidationException
    {
        return ValidationException::withMessages([
            'status' => sprintf(
                'No se puede enviar la salida porque no hay stock suficiente para %s.',
                $this->lineLabel($line)
            ),
        ]);
    }

    private function unresolvedStockError(GoodsDispatchLine $line): ValidationException
    {
        return ValidationException::withMessages([
            'status' => sprintf(
                'No se puede enviar la salida: no se pudo identificar la partida de stock real de %s.',
                $this->lineLabel($line)
            ),
        ]);
    }

    private function lineLabel(GoodsDispatchLine $line): string
    {
        $sku = $line->sku ?: $line->item?->sku;
        $description = $line->description ?: $line->item?->description;

        return trim(collect([$sku, $description])->filter()->implode(' - ')) ?: 'la referencia solicitada';
    }

    /**
     * @param  list<int>  $excludedPeakIndexes
     * @return list<int>
     */
    private function peakConsumptionOrder(GoodsDispatchLine $line, array $excludedPeakIndexes = []): array
    {
        $indexes = array_values(array_diff(range(1, StockPallet::MAX_PEAK_COLUMNS), $excludedPeakIndexes));

        if ($line->stock_peak_index === null) {
            return $indexes;
        }

        return array_values(array_unique([(int) $line->stock_peak_index, ...$indexes]));
    }

    private function storeBrokenPalletRemainder(StockPallet $stockPallet, GoodsDispatchLine $line, int $remainingUnits): void
    {
        if ($remainingUnits <= 0) {
            return;
        }

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakIndex) {
            $field = 'peak_'.$peakIndex;

            if ((int) ($stockPallet->{$field} ?? 0) > 0) {
                continue;
            }

            $stockPallet->{$field} = $remainingUnits;

            return;
        }

        throw $this->insufficientStockError($line);
    }
}
