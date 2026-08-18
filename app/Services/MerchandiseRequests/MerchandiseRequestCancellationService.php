<?php

namespace App\Services\MerchandiseRequests;

use App\Models\GoodsDispatch;
use App\Models\InventoryMovement;
use App\Models\MerchandiseRequest;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class MerchandiseRequestCancellationService
{
    public function __construct(private readonly AuditLogService $audit) {}

    public function canCancel(MerchandiseRequest $request): bool
    {
        return $this->blockingReason($request) === null;
    }

    public function cancel(MerchandiseRequest $request, \App\Models\User $user): void
    {
        DB::transaction(function () use ($request, $user): void {
            $lockedRequest = MerchandiseRequest::query()
                ->whereKey($request->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRequest->load(['goodsDispatches.lines.allocations']);

            $blockingReason = $this->blockingReason($lockedRequest);

            if ($blockingReason !== null) {
                throw ValidationException::withMessages(['request' => $blockingReason]);
            }

            $previousStatus = $lockedRequest->status;
            $cancelledDispatches = [];

            foreach ($lockedRequest->goodsDispatches as $dispatch) {
                if (in_array($dispatch->status, [GoodsDispatch::STATUS_DRAFT, GoodsDispatch::STATUS_PREPARING], true)) {
                    $dispatch->update(['status' => GoodsDispatch::STATUS_CANCELLED]);
                    $cancelledDispatches[] = $dispatch->id;

                    $this->audit->record(
                        event: 'goods_dispatch_cancelled',
                        module: 'goods_dispatches',
                        description: 'Salida de pedido anulada antes de registrar carga o stock.',
                        auditable: $dispatch,
                        subject: $lockedRequest,
                        user: $user,
                        clientId: $dispatch->client_id,
                        oldValues: ['status' => $dispatch->getOriginal('status')],
                        newValues: ['status' => GoodsDispatch::STATUS_CANCELLED],
                        severity: 'warning',
                    );
                }
            }

            $lockedRequest->update([
                'status' => MerchandiseRequest::STATUS_CANCELLED,
                'cancelled_at' => $lockedRequest->cancelled_at ?? now(),
            ]);

            $this->audit->record(
                event: 'merchandise_request_cancelled',
                module: 'merchandise_requests',
                description: 'Pedido anulado por usuario interno antes de afectar stock.',
                auditable: $lockedRequest,
                user: $user,
                clientId: $lockedRequest->client_id,
                oldValues: ['status' => $previousStatus],
                newValues: [
                    'status' => MerchandiseRequest::STATUS_CANCELLED,
                    'cancelled_at' => $lockedRequest->cancelled_at,
                    'cancelled_dispatch_ids' => $cancelledDispatches,
                ],
                severity: 'warning',
            );
        });
    }

    public function blockingReason(MerchandiseRequest $request): ?string
    {
        if (! in_array($request->status, [
            MerchandiseRequest::STATUS_PENDING,
            MerchandiseRequest::STATUS_PREPARING,
            MerchandiseRequest::STATUS_PARTIALLY_FULFILLED,
        ], true)) {
            return in_array($request->status, [MerchandiseRequest::STATUS_SENT, MerchandiseRequest::STATUS_COMPLETED], true)
                ? 'No se puede eliminar este pedido porque ya está enviado o cerrado.'
                : 'Este pedido ya no se puede eliminar en su estado actual.';
        }

        $request->loadMissing(['goodsDispatches.lines.allocations']);

        foreach ($request->goodsDispatches as $dispatch) {
            if (in_array($dispatch->status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true)) {
                return 'No se puede eliminar este pedido porque ya está enviado o cerrado.';
            }

            if ($dispatch->hasStockApplied() || $dispatch->hasWarehouseStockApplied()) {
                return 'No se puede eliminar este pedido porque ya tiene movimientos de stock.';
            }

            if ($dispatch->lines->contains(fn ($line): bool => $line->hasActualLoadedQuantity())) {
                return 'No se puede eliminar este pedido porque ya tiene carga registrada.';
            }

            if (InventoryMovement::query()
                ->where('source_type', $dispatch->getMorphClass())
                ->where('source_id', $dispatch->id)
                ->where('movement_type', InventoryMovement::DISPATCH)
                ->exists()) {
                return 'No se puede eliminar este pedido porque ya tiene movimientos de stock.';
            }
        }

        return null;
    }
}
