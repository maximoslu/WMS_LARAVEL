<?php

namespace App\Services\Stock;

use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockPallet;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\InventoryMovementService;
use App\Support\Stock\StockBatchIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockAdjustmentService
{
    public function __construct(
        private readonly InventoryMovementService $movements,
        private readonly AuditLogService $audit,
        private readonly StockBatchIdentityService $batchIdentities,
    ) {}

    public function apply(StoreStockAdjustmentRequest $request): StockPallet
    {
        return DB::transaction(function () use ($request): StockPallet {
            $correlationId = $this->audit->correlationId();
            $stockPallet = $request->mode() === StoreStockAdjustmentRequest::MODE_EXISTING
                || $request->action() === StoreStockAdjustmentRequest::ACTION_REMOVE
                    ? $this->adjustExistingStock($request)
                    : $this->createNewStock($request);

            $stockPallet->loadMissing(['client', 'item', 'location.warehouse']);
            $after = $this->movements->snapshot($stockPallet);
            $before = $request->attributes->get('stock_adjustment_before_snapshot');
            $metadata = $this->metadata($request);

            $this->movements->record(
                before: is_array($before) ? $before : $this->movements->snapshot(null),
                after: $after,
                movementType: InventoryMovement::MANUAL_ADJUSTMENT,
                idempotencyKey: "stock-adjustment:{$stockPallet->id}:{$correlationId}",
                correlationId: $correlationId,
                source: $stockPallet,
                user: $request->user(),
                metadata: $metadata,
                sourceLabel: 'manual_superadmin_adjustment',
            );

            $this->audit->record(
                event: $request->action() === StoreStockAdjustmentRequest::ACTION_REMOVE
                    ? 'stock_manual_adjustment_removed'
                    : 'stock_manual_adjustment_added',
                module: 'stock',
                description: $request->action() === StoreStockAdjustmentRequest::ACTION_REMOVE
                    ? 'Regularizacion manual de stock por superadmin: baja de unidades.'
                    : 'Regularizacion manual de stock por superadmin: alta de unidades.',
                auditable: $stockPallet,
                user: $request->user(),
                clientId: $stockPallet->client_id,
                oldValues: is_array($before) ? $before : [],
                newValues: $after,
                metadata: $metadata,
                correlationId: $correlationId,
                severity: 'important',
                request: $request,
            );

            return $stockPallet;
        }, 3);
    }

    private function adjustExistingStock(StoreStockAdjustmentRequest $request): StockPallet
    {
        $candidate = StockPallet::query()->findOrFail($request->stockPalletId());
        $sourceIdentity = StockBatchIdentity::fromStockPallet($candidate);
        $futureUnitsPerPallet = (int) $candidate->units_per_pallet > 0
            ? (int) $candidate->units_per_pallet
            : $request->unitsPerPallet();
        $destinationIdentity = $this->identityWithUnitsPerPallet($candidate, $futureUnitsPerPallet);
        $this->batchIdentities->lockIdentities([$sourceIdentity, $destinationIdentity]);

        $stockPallet = StockPallet::query()
            ->with(['client', 'item', 'location.warehouse'])
            ->lockForUpdate()
            ->findOrFail($request->stockPalletId());

        if (StockBatchIdentity::fromStockPallet($stockPallet)->hash() !== $sourceIdentity->hash()) {
            throw ValidationException::withMessages([
                'stock_pallet_id' => 'La identidad de la partida ha cambiado durante la regularizacion. Vuelve a intentarlo.',
            ]);
        }

        if ($destinationIdentity->hash() !== $sourceIdentity->hash()) {
            $collision = $this->batchIdentities->getAfterLock($destinationIdentity)
                ->first(fn (StockPallet $candidate): bool => (int) $candidate->id !== (int) $stockPallet->id);

            if ($collision instanceof StockPallet) {
                throw ValidationException::withMessages([
                    'units_per_pallet' => 'El cambio de unidades por pallet colisionaria con otra partida activa.',
                ]);
            }
        }

        $before = $this->movements->snapshot($stockPallet);
        $quantityDelta = $request->quantityDelta();
        $afterQuantity = $request->action() === StoreStockAdjustmentRequest::ACTION_REMOVE
            ? (int) $stockPallet->quantity_units - $quantityDelta
            : (int) $stockPallet->quantity_units + $quantityDelta;

        if ($afterQuantity < 0) {
            throw ValidationException::withMessages([
                'full_pallets' => 'No puedes quitar mas stock del disponible en la partida seleccionada.',
            ]);
        }

        $stockPallet->quantity_units = $afterQuantity;
        $stockPallet->units_per_pallet = (int) $stockPallet->units_per_pallet > 0
            ? (int) $stockPallet->units_per_pallet
            : $request->unitsPerPallet();
        $stockPallet->warehouse_pallets = null;

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $stockPallet->{'peak_'.$peakNumber} = 0;
        }

        $stockPallet->save();
        $request->attributes->set('stock_adjustment_before_snapshot', $before);

        return $stockPallet->fresh(['client', 'item', 'location.warehouse']);
    }

    private function identityWithUnitsPerPallet(StockPallet $stockPallet, int $unitsPerPallet): StockBatchIdentity
    {
        return new StockBatchIdentity(
            clientId: (int) $stockPallet->client_id,
            itemId: (int) $stockPallet->item_id,
            lot: $stockPallet->lot,
            locationId: $stockPallet->location_id !== null ? (int) $stockPallet->location_id : null,
            locationText: $stockPallet->location_text,
            unitsPerPallet: $unitsPerPallet,
            status: $stockPallet->status,
            stockCategory: $stockPallet->stock_category,
            blockedReason: $stockPallet->blocked_reason,
        );
    }

    private function createNewStock(StoreStockAdjustmentRequest $request): StockPallet
    {
        $item = Item::query()->findOrFail($request->itemId());
        $location = $request->locationId() !== null
            ? Location::query()->findOrFail($request->locationId())
            : null;
        $attributes = [
            'client_id' => $request->clientId(),
            'item_id' => $item->id,
            'location_id' => $location?->id,
            'location_text' => $location?->code,
            'lot' => $request->lot(),
            'quantity_units' => $request->quantityDelta(),
            'units_per_pallet' => $request->unitsPerPallet(),
            'warehouse_pallets' => null,
            'status' => $request->stockStatus(),
            'stock_category' => $request->stockCategory(),
            'source_sheet' => 'REGULARIZACION',
            'notes' => $request->note() ?: 'Regularizacion manual superadmin.',
            'active' => true,
        ];
        $identity = new StockBatchIdentity(
            clientId: $request->clientId(),
            itemId: (int) $item->id,
            lot: $request->lot(),
            locationId: $location?->id,
            locationText: null,
            unitsPerPallet: $request->unitsPerPallet(),
            status: $request->stockStatus(),
            stockCategory: $request->stockCategory(),
            blockedReason: null,
        );
        $existing = $this->batchIdentities->lockAndGet($identity);

        if ($existing->count() > 1) {
            throw ValidationException::withMessages([
                'stock_pallet_id' => 'Existen varias partidas historicas con esta identidad; deben sanearse antes de regularizarla.',
            ]);
        }

        $stockPallet = $existing->first();

        if ($stockPallet instanceof StockPallet) {
            $request->attributes->set('stock_adjustment_before_snapshot', $this->movements->snapshot($stockPallet));
            $stockPallet->forceFill([
                'quantity_units' => (int) $stockPallet->quantity_units + $request->quantityDelta(),
                'warehouse_pallets' => null,
            ]);

            foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
                $stockPallet->{'peak_'.$peakNumber} = 0;
            }

            $stockPallet->save();

            return $stockPallet->fresh(['client', 'item', 'location.warehouse']);
        }

        $request->attributes->set('stock_adjustment_before_snapshot', $this->movements->snapshot(null));

        return StockPallet::query()->create($attributes)->fresh(['client', 'item', 'location.warehouse']);
    }

    /** @return array<string, mixed> */
    private function metadata(StoreStockAdjustmentRequest $request): array
    {
        return [
            'origin' => 'regularizacion manual superadmin',
            'action' => $request->action(),
            'mode' => $request->mode(),
            'quantity_delta_requested' => $request->signedQuantityDelta(),
            'full_pallets_requested' => $request->action() === StoreStockAdjustmentRequest::ACTION_REMOVE
                ? -1 * $request->fullPallets()
                : $request->fullPallets(),
            'peak_units_requested' => $request->action() === StoreStockAdjustmentRequest::ACTION_REMOVE
                ? -1 * $request->peakUnits()
                : $request->peakUnits(),
            'units_per_pallet_requested' => $request->unitsPerPallet(),
            'note' => $request->note(),
            'ip_address' => $request->ip(),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
            'does_not_create_goods_receipt' => true,
            'does_not_create_goods_dispatch' => true,
        ];
    }
}
