<?php

namespace App\Services\Stock;

use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockPallet;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\InventoryMovementService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockAdjustmentService
{
    public function __construct(
        private readonly InventoryMovementService $movements,
        private readonly AuditLogService $audit,
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
        });
    }

    private function adjustExistingStock(StoreStockAdjustmentRequest $request): StockPallet
    {
        $stockPallet = StockPallet::query()
            ->with(['client', 'item', 'location.warehouse'])
            ->lockForUpdate()
            ->findOrFail($request->stockPalletId());

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

    private function createNewStock(StoreStockAdjustmentRequest $request): StockPallet
    {
        $item = Item::query()->findOrFail($request->itemId());
        $location = $request->locationId() !== null
            ? Location::query()->findOrFail($request->locationId())
            : null;
        $request->attributes->set('stock_adjustment_before_snapshot', $this->movements->snapshot(null));

        return StockPallet::query()->create([
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
        ])->fresh(['client', 'item', 'location.warehouse']);
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
