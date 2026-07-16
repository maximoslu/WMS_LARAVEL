<?php

namespace App\Services\GoodsDispatches;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\MerchandiseRequest;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GoodsDispatchWorkflowService
{
    public function __construct(
        private readonly MerchandiseRequestNotificationService $notificationService,
        private readonly StockDispatchAllocationService $stockAllocationService,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @param  array<string, array{
     *     line_id?:int|string|null,
     *     item_id?:int|string|null,
     *     stock_pallet_id?:int|string|null,
     *     line_type:string,
     *     stock_peak_index?:int|string|null,
     *     sku?:string,
     *     description?:string,
     *     lot?:string|null,
     *     units_per_pallet?:int|string|null,
     *     units_per_peak?:int|string|null,
     *     loaded_pallets:int|string,
     *     loaded_peaks?:int|string|null,
     *     loaded_partial_units?:int|string|null,
     *     allocations?:array<int, array{
     *         stock_pallet_id?:int|null,
     *         loaded_pallets:int,
     *         loaded_partial_units:int,
     *         selected_peaks?:array<int, array{index:int, units:int}>,
     *         lot?:string|null,
     *         location_text?:string|null
     *     }>,
     *     loading_notes?:?string,
     *     remove?:mixed
     * }>  $linePayload
     */
    public function confirmLoading(GoodsDispatch $dispatch, array $linePayload, User $user): void
    {
        $dispatch->loadMissing(['lines.allocations', 'merchandiseRequest']);

        DB::transaction(function () use ($dispatch, $linePayload, $user): void {
            $correlationId = $this->audit->correlationId();
            $dispatch = GoodsDispatch::query()
                ->whereKey($dispatch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $dispatch->load(['lines.allocations', 'merchandiseRequest']);

            if ($dispatch->hasStockApplied() || in_array($dispatch->status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true)) {
                throw ValidationException::withMessages([
                    'dispatch' => 'La carga de una salida enviada o completada no se puede modificar.',
                ]);
            }

            $confirmedAt = now();
            $existingLines = $dispatch->lines->keyBy('id');

            foreach ($linePayload as $rowKey => $payload) {
                $lineId = isset($payload['line_id']) && $payload['line_id'] !== null
                    ? (int) $payload['line_id']
                    : null;
                $loadedPallets = (int) $payload['loaded_pallets'];
                $loadedPeaks = (int) ($payload['loaded_peaks'] ?? 0);
                $loadedPartialUnits = (int) ($payload['loaded_partial_units'] ?? 0);
                $loadingNotes = filled($payload['loading_notes'] ?? null)
                    ? trim((string) $payload['loading_notes'])
                    : null;
                $removeLine = filter_var($payload['remove'] ?? false, FILTER_VALIDATE_BOOL);

                if ($lineId !== null && $existingLines->has($lineId)) {
                    $line = $existingLines->get($lineId);

                    if ($line->is_extra_line && $removeLine) {
                        $line->delete();

                        continue;
                    }

                    $line->update([
                        'stock_pallet_id' => $payload['stock_pallet_id'] ?? $line->stock_pallet_id,
                        'stock_peak_index' => $payload['stock_peak_index'] ?? $line->stock_peak_index,
                        'lot' => $payload['lot'] ?? $line->lot,
                        'loaded_pallets' => $loadedPallets,
                        'loaded_peaks' => $loadedPeaks,
                        'loaded_partial_units' => $loadedPartialUnits,
                        'loading_notes' => $loadingNotes,
                        'confirmed_by' => $user->id,
                        'confirmed_at' => $confirmedAt,
                    ]);

                    $this->syncAllocations($line, $payload['allocations'] ?? []);

                    continue;
                }

                if ($removeLine) {
                    continue;
                }

                $createdLine = $dispatch->lines()->create([
                    'item_id' => $payload['item_id'],
                    'stock_pallet_id' => $payload['stock_pallet_id'] ?? null,
                    'line_type' => $payload['line_type'],
                    'stock_peak_index' => $payload['stock_peak_index'] ?? null,
                    'sku' => $payload['sku'] ?? 'Articulo',
                    'description' => $payload['description'] ?? 'Sin descripcion',
                    'lot' => $payload['lot'] ?? null,
                    'units_per_pallet' => $payload['units_per_pallet'] ?? null,
                    'units_per_peak' => $payload['units_per_peak'] ?? null,
                    'pallets' => 0,
                    'requested_units' => 0,
                    'requested_pallets' => 0,
                    'requested_peaks' => 0,
                    'loaded_pallets' => $loadedPallets,
                    'loaded_peaks' => $loadedPeaks,
                    'loaded_partial_units' => $loadedPartialUnits,
                    'loading_notes' => $loadingNotes,
                    'confirmed_by' => $user->id,
                    'confirmed_at' => $confirmedAt,
                    'is_extra_line' => true,
                ]);

                $this->syncAllocations($createdLine, $payload['allocations'] ?? []);
            }

            if ($dispatch->status === GoodsDispatch::STATUS_DRAFT) {
                $dispatch->update([
                    'status' => GoodsDispatch::STATUS_PREPARING,
                ]);
            }

            if ($dispatch->merchandiseRequest !== null && $dispatch->merchandiseRequest->status === MerchandiseRequest::STATUS_PENDING) {
                $dispatch->merchandiseRequest->update([
                    'status' => MerchandiseRequest::STATUS_PREPARING,
                    'prepared_by' => $user->id,
                    'prepared_at' => $dispatch->merchandiseRequest->prepared_at ?? now(),
                ]);
            }

            $this->audit->record(
                event: 'goods_dispatch_loading_confirmed',
                module: 'goods_dispatches',
                description: 'Carga real de la salida confirmada.',
                auditable: $dispatch,
                subject: $dispatch->merchandiseRequest,
                user: $user,
                clientId: $dispatch->client_id,
                newValues: ['line_count' => count($linePayload), 'status' => $dispatch->status],
                correlationId: $correlationId,
            );
        });

        $freshDispatch = $dispatch->fresh([
            'client',
            'lines.item',
            'merchandiseRequest',
        ]);

        $this->notificationService->notifyLoadingConfirmed($freshDispatch, $user);
    }

    /**
     * @param  array<int, array{
     *     stock_pallet_id?:int|null,
     *     loaded_pallets:int,
     *     loaded_partial_units:int,
     *     selected_peaks?:array<int, array{index:int, units:int}>,
     *     lot?:string|null,
     *     location_text?:string|null
     * }>  $allocations
     */
    private function syncAllocations(GoodsDispatchLine $line, array $allocations): void
    {
        $line->allocations()->delete();

        foreach ($allocations as $allocation) {
            $stockPalletId = $allocation['stock_pallet_id'] ?? null;
            $loadedPallets = (int) ($allocation['loaded_pallets'] ?? 0);
            $loadedPartialUnits = (int) ($allocation['loaded_partial_units'] ?? 0);

            if ($stockPalletId === null || ($loadedPallets <= 0 && $loadedPartialUnits <= 0)) {
                continue;
            }

            $line->allocations()->create([
                'stock_pallet_id' => $stockPalletId,
                'lot' => $allocation['lot'] ?? null,
                'location_text' => $allocation['location_text'] ?? null,
                'loaded_pallets' => $loadedPallets,
                'loaded_partial_units' => $loadedPartialUnits,
                'selected_peaks' => $allocation['selected_peaks'] ?? [],
            ]);
        }

        $line->unsetRelation('allocations');
    }

    public function changeStatus(GoodsDispatch $dispatch, string $newStatus, User $user): ?string
    {
        $correlationId = $this->audit->correlationId();
        $result = DB::transaction(function () use ($dispatch, $newStatus, $user, $correlationId): array {
            $lockedDispatch = GoodsDispatch::query()
                ->whereKey($dispatch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedDispatch->load(['client', 'merchandiseRequest', 'lines.item']);

            if ($lockedDispatch->status === $newStatus) {
                return ['changed' => false, 'previous_request_status' => null];
            }

            $previousDispatchStatus = $lockedDispatch->status;
            $this->guardStatusTransition($lockedDispatch, $newStatus);
            $previousRequestStatus = $lockedDispatch->merchandiseRequest?->status;
            $dispatchPayload = [
                'status' => $newStatus,
            ];

            if (in_array($newStatus, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true) && $lockedDispatch->sent_at === null) {
                $dispatchPayload['sent_at'] = now();
            }

            if ($newStatus === GoodsDispatch::STATUS_COMPLETED && $lockedDispatch->completed_at === null) {
                $dispatchPayload['completed_at'] = now();
            }

            if (
                in_array($newStatus, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true)
                && ! $lockedDispatch->hasStockApplied()
            ) {
                $this->stockAllocationService->apply($lockedDispatch, $user, $correlationId);

                $dispatchPayload['stock_applied_at'] = now();
                $dispatchPayload['stock_applied_by'] = $user->id;
                $dispatchPayload['warehouse_stock_applied_at'] = now();
                $dispatchPayload['warehouse_stock_applied_by'] = $user->id;
            }

            $lockedDispatch->update($dispatchPayload);

            $this->audit->record(
                event: 'goods_dispatch_status_changed',
                module: 'goods_dispatches',
                description: "Estado de salida cambiado de {$previousDispatchStatus} a {$newStatus}.",
                auditable: $lockedDispatch,
                subject: $lockedDispatch->merchandiseRequest,
                user: $user,
                clientId: $lockedDispatch->client_id,
                oldValues: ['status' => $previousDispatchStatus],
                newValues: ['status' => $newStatus, 'stock_applied_at' => $dispatchPayload['stock_applied_at'] ?? null],
                correlationId: $correlationId,
                severity: in_array($newStatus, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true) ? 'important' : 'info',
            );

            $merchandiseRequest = $lockedDispatch->merchandiseRequest;

            if ($merchandiseRequest === null) {
                return ['changed' => true, 'previous_request_status' => null];
            }

            $requestPayload = [
                'status' => $newStatus,
            ];

            if ($newStatus === MerchandiseRequest::STATUS_PREPARING) {
                $requestPayload['prepared_by'] = $user->id;
                $requestPayload['prepared_at'] = $merchandiseRequest->prepared_at ?? now();
            }

            if (in_array($newStatus, [MerchandiseRequest::STATUS_SENT, MerchandiseRequest::STATUS_COMPLETED], true)
                && $merchandiseRequest->shipped_at === null) {
                $requestPayload['shipped_by'] = $user->id;
                $requestPayload['shipped_at'] = now();
            }

            if ($newStatus === MerchandiseRequest::STATUS_COMPLETED && $merchandiseRequest->completed_at === null) {
                $requestPayload['completed_at'] = now();
            }

            if ($newStatus === MerchandiseRequest::STATUS_CANCELLED) {
                $requestPayload['cancelled_at'] = $merchandiseRequest->cancelled_at ?? now();
            }

            $merchandiseRequest->update($requestPayload);

            $this->audit->record(
                event: 'merchandise_request_status_changed',
                module: 'merchandise_requests',
                description: "Estado de pedido cambiado de {$previousRequestStatus} a {$newStatus} desde su salida.",
                auditable: $merchandiseRequest,
                subject: $lockedDispatch,
                user: $user,
                clientId: $merchandiseRequest->client_id,
                oldValues: ['status' => $previousRequestStatus],
                newValues: ['status' => $newStatus],
                correlationId: $correlationId,
                severity: in_array($newStatus, [MerchandiseRequest::STATUS_SENT, MerchandiseRequest::STATUS_COMPLETED], true) ? 'important' : 'info',
            );

            return ['changed' => true, 'previous_request_status' => $previousRequestStatus];
        });

        if ($result['changed'] && $result['previous_request_status'] !== null) {
            $this->notificationService->notifyDispatchStatusChanged(
                $dispatch->fresh(['merchandiseRequest']),
                $result['previous_request_status'],
                $newStatus,
            );
        }

        return null;
    }

    public function applyMissingStock(GoodsDispatch $dispatch): bool
    {
        return DB::transaction(function () use ($dispatch): bool {
            $correlationId = $this->audit->correlationId();
            $lockedDispatch = GoodsDispatch::query()
                ->whereKey($dispatch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedDispatch->load(['client', 'merchandiseRequest', 'lines.item']);

            if (! in_array($lockedDispatch->status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true)) {
                throw ValidationException::withMessages([
                    'dispatch' => 'Solo se puede reparar una salida enviada o completada.',
                ]);
            }

            if ($lockedDispatch->hasStockApplied()) {
                return false;
            }

            $this->ensureDeliveryNoteCanBeGenerated($lockedDispatch);
            $this->stockAllocationService->apply($lockedDispatch, null, $correlationId);
            $lockedDispatch->update([
                'stock_applied_at' => now(),
                'stock_applied_by' => null,
                'warehouse_stock_applied_at' => now(),
                'warehouse_stock_applied_by' => null,
            ]);

            $this->audit->record(
                event: 'goods_dispatch_stock_repaired',
                module: 'goods_dispatches',
                description: 'Descuento de stock faltante aplicado mediante reparacion.',
                auditable: $lockedDispatch,
                clientId: $lockedDispatch->client_id,
                correlationId: $correlationId,
                source: 'command',
                severity: 'warning',
            );

            return true;
        });
    }

    public function repairWarehouseStock(GoodsDispatch $dispatch): bool
    {
        return DB::transaction(function () use ($dispatch): bool {
            $correlationId = $this->audit->correlationId();
            $lockedDispatch = GoodsDispatch::query()
                ->whereKey($dispatch->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedDispatch->load(['client', 'lines.item']);

            if (! $lockedDispatch->hasStockApplied()) {
                throw ValidationException::withMessages([
                    'dispatch' => 'La salida no tiene aplicado el descuento de unidades; usa primero la reparacion normal.',
                ]);
            }

            if ($lockedDispatch->hasWarehouseStockApplied()) {
                return false;
            }

            $this->stockAllocationService->applyWarehousePalletsOnly($lockedDispatch, null, $correlationId);
            $lockedDispatch->update([
                'warehouse_stock_applied_at' => now(),
                'warehouse_stock_applied_by' => null,
            ]);

            $this->audit->record(
                event: 'goods_dispatch_warehouse_stock_repaired',
                module: 'goods_dispatches',
                description: 'Contador de pallets de almacen reparado para una salida historica.',
                auditable: $lockedDispatch,
                clientId: $lockedDispatch->client_id,
                correlationId: $correlationId,
                source: 'command',
                severity: 'warning',
            );

            return true;
        });
    }

    public function ensureDeliveryNoteCanBeGenerated(GoodsDispatch $dispatch): void
    {
        $dispatch->loadMissing(['client', 'lines', 'merchandiseRequest']);

        if (! $dispatch->hasConfirmedLoading()) {
            throw ValidationException::withMessages([
                'dispatch' => 'Debes confirmar primero las cantidades realmente cargadas antes de generar el albaran.',
            ]);
        }

        if ($dispatch->lines->isEmpty()) {
            throw ValidationException::withMessages([
                'dispatch' => 'La salida no tiene lineas y no puede generar un albaran.',
            ]);
        }

        if (! $dispatch->hasDeliveredLine()) {
            throw ValidationException::withMessages([
                'dispatch' => 'La salida no tiene ninguna línea cargada con pallets o picos superiores a cero.',
            ]);
        }

    }

    private function guardStatusTransition(GoodsDispatch $dispatch, string $newStatus): void
    {
        $allowedTransitions = [
            GoodsDispatch::STATUS_DRAFT => [GoodsDispatch::STATUS_PREPARING, GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED, GoodsDispatch::STATUS_CANCELLED],
            GoodsDispatch::STATUS_PREPARING => [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED, GoodsDispatch::STATUS_CANCELLED],
            GoodsDispatch::STATUS_SENT => [GoodsDispatch::STATUS_COMPLETED],
            GoodsDispatch::STATUS_COMPLETED => [],
            GoodsDispatch::STATUS_CANCELLED => [],
        ];

        if (! in_array($newStatus, $allowedTransitions[$dispatch->status] ?? [], true)) {
            throw ValidationException::withMessages([
                'status' => 'La transicion solicitada no es valida y podria desalinear la salida del stock aplicado.',
            ]);
        }

        if (in_array($newStatus, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true)) {
            if (! $dispatch->hasConfirmedLoading()) {
                throw ValidationException::withMessages([
                    'status' => 'Debes confirmar las lineas cargadas antes de marcar la salida como enviada o completada.',
                ]);
            }

            if (! $dispatch->hasDeliveredLine()) {
                throw ValidationException::withMessages([
                    'status' => 'Debes tener al menos una línea cargada con pallets o picos mayores que cero antes de enviar o completar la salida.',
                ]);
            }

            $this->ensureDeliveryNoteCanBeGenerated($dispatch);
        }
    }
}
