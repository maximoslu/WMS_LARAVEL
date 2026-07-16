<?php

namespace App\Services\Traceability;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\StockPallet;
use Illuminate\Database\Eloquent\Builder;

class LotTraceabilityService
{
    /** @return array<string, mixed> */
    public function trace(int $clientId, string $lot, ?int $itemId = null, array $filters = []): array
    {
        $lot = trim($lot);
        $from = $filters['date_from'] ?? null;
        $to = $filters['date_to'] ?? null;
        $locationId = isset($filters['location_id']) ? (int) $filters['location_id'] : null;
        $supplierId = isset($filters['supplier_id']) ? (int) $filters['supplier_id'] : null;
        $status = $filters['status'] ?? 'all';
        $stock = StockPallet::query()
            ->with(['client', 'item', 'goodsReceipt.supplier', 'location.warehouse'])
            ->where('client_id', $clientId)
            ->where('lot', $lot)
            ->when($itemId !== null, fn (Builder $query) => $query->where('item_id', $itemId))
            ->when($locationId !== null, fn (Builder $query) => $query->where('location_id', $locationId))
            ->when($status === 'active', fn (Builder $query) => $query->where('active', true))
            ->when($status === 'historical', fn (Builder $query) => $query->where('active', false))
            ->when($status === 'available', fn (Builder $query) => $query->where('status', StockPallet::STATUS_AVAILABLE))
            ->when($status === 'blocked', fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where('status', StockPallet::STATUS_BLOCKED)
                ->orWhere('stock_category', StockPallet::CATEGORY_BLOCKED)))
            ->when($status === 'obsolete', fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where('status', StockPallet::STATUS_OBSOLETE)
                ->orWhere('stock_category', StockPallet::CATEGORY_OBSOLETE)))
            ->orderByDesc('active')
            ->orderBy('id')
            ->get();
        $movements = InventoryMovement::query()
            ->where('client_id', $clientId)
            ->where('lot', $lot)
            ->when($itemId !== null, fn (Builder $query) => $query->where('item_id', $itemId))
            ->when($locationId !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope
                ->where('location_id', $locationId)
                ->orWhere('from_location_id', $locationId)
                ->orWhere('to_location_id', $locationId)))
            ->when($from !== null, fn (Builder $query) => $query->whereDate('effective_at', '>=', $from))
            ->when($to !== null, fn (Builder $query) => $query->whereDate('effective_at', '<=', $to))
            ->orderBy('effective_at')
            ->orderBy('id')
            ->get();
        $receiptLines = GoodsReceiptLine::query()
            ->with(['goodsReceipt.client', 'goodsReceipt.supplier', 'item', 'location.warehouse'])
            ->where('lot', $lot)
            ->whereHas('goodsReceipt', fn (Builder $query) => $query->where('client_id', $clientId))
            ->when($itemId !== null, fn (Builder $query) => $query->where('item_id', $itemId))
            ->when($supplierId !== null, fn (Builder $query) => $query->whereHas('goodsReceipt', fn (Builder $receiptQuery) => $receiptQuery->where('supplier_id', $supplierId)))
            ->when($locationId !== null, fn (Builder $query) => $query->where('location_id', $locationId))
            ->when($from !== null, fn (Builder $query) => $query->whereHas('goodsReceipt', fn (Builder $receiptQuery) => $receiptQuery->whereDate('received_at', '>=', $from)))
            ->when($to !== null, fn (Builder $query) => $query->whereHas('goodsReceipt', fn (Builder $receiptQuery) => $receiptQuery->whereDate('received_at', '<=', $to)))
            ->orderBy('id')
            ->get();
        $dispatchLines = GoodsDispatchLine::query()
            ->with(['dispatch.client', 'dispatch.merchandiseRequest', 'item', 'allocations.stockPallet.location.warehouse'])
            ->whereHas('dispatch', fn (Builder $query) => $query
                ->where('client_id', $clientId)
                ->whereIn('status', [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED]))
            ->where(function (Builder $query) use ($lot): void {
                $query->where('lot', $lot)
                    ->orWhereHas('allocations', fn (Builder $allocationQuery) => $allocationQuery->where('lot', $lot))
                    ->orWhereHas('allocations.stockPallet', fn (Builder $stockQuery) => $stockQuery->where('lot', $lot));
            })
            ->when($itemId !== null, fn (Builder $query) => $query->where('item_id', $itemId))
            ->when($locationId !== null, fn (Builder $query) => $query->whereHas('allocations.stockPallet', fn (Builder $stockQuery) => $stockQuery->where('location_id', $locationId)))
            ->when($from !== null, fn (Builder $query) => $query->whereHas('dispatch', fn (Builder $dispatchQuery) => $dispatchQuery->whereDate('sent_at', '>=', $from)))
            ->when($to !== null, fn (Builder $query) => $query->whereHas('dispatch', fn (Builder $dispatchQuery) => $dispatchQuery->whereDate('sent_at', '<=', $to)))
            ->orderBy('id')
            ->get();

        $issues = $this->integrityIssues($stock, $movements, $receiptLines, $dispatchLines);
        $integrity = collect($issues)->contains(fn (array $issue): bool => $issue['level'] === 'inconsistent')
            ? 'inconsistent'
            : ($issues === [] ? 'complete' : 'partial');

        $timeline = collect()
            ->concat($receiptLines->map(fn (GoodsReceiptLine $line): array => [
                'at' => $line->goodsReceipt?->confirmed_at ?? $line->goodsReceipt?->received_at ?? $line->created_at,
                'type' => 'receipt',
                'label' => 'Entrada '.$line->goodsReceipt?->receipt_number,
                'units' => (int) $line->quantity_units,
                'record' => $line,
            ]))
            ->concat($movements->map(fn (InventoryMovement $movement): array => [
                'at' => $movement->effective_at,
                'type' => $movement->movement_type,
                'label' => ucfirst(str_replace('_', ' ', $movement->movement_type)),
                'units' => (int) $movement->units_delta,
                'record' => $movement,
            ]))
            ->concat($dispatchLines->map(fn (GoodsDispatchLine $line): array => [
                'at' => $line->dispatch?->sent_at ?? $line->dispatch?->completed_at ?? $line->created_at,
                'type' => 'dispatch_document',
                'label' => 'Salida '.$line->dispatch?->dispatch_number,
                'units' => -$line->loadedUnits(),
                'record' => $line,
            ]))
            ->sortBy(fn (array $entry) => $entry['at']?->timestamp ?? 0)
            ->values();

        return [
            'lot' => $lot,
            'stock' => $stock,
            'movements' => $movements,
            'receipt_lines' => $receiptLines,
            'dispatch_lines' => $dispatchLines,
            'timeline' => $timeline,
            'issues' => $issues,
            'integrity' => $integrity,
            'current_units' => (int) $stock->where('active', true)->sum('quantity_units'),
            'current_pallets' => (float) $stock->where('active', true)->sum('warehouse_pallets'),
        ];
    }

    /** @return list<array{level:string,message:string}> */
    private function integrityIssues($stock, $movements, $receiptLines, $dispatchLines): array
    {
        $issues = [];

        if ($receiptLines->isEmpty()) {
            $issues[] = ['level' => 'partial', 'message' => 'Lote sin entrada identificable. Puede proceder de una importacion o de historico anterior al ledger.'];
        }

        if ($receiptLines->contains(fn (GoodsReceiptLine $line): bool => blank($line->goodsReceipt?->document_path))) {
            $issues[] = ['level' => 'partial', 'message' => 'Existe una entrada sin documento de proveedor asociado.'];
        }

        if ($dispatchLines->contains(fn (GoodsDispatchLine $line): bool => $line->allocations->isEmpty())) {
            $issues[] = ['level' => 'partial', 'message' => 'Existe una salida historica sin allocation que identifique la partida consumida.'];
        }

        if ($movements->contains(fn (InventoryMovement $movement): bool => $movement->user_id === null && $movement->source !== 'backfill')) {
            $issues[] = ['level' => 'partial', 'message' => 'Existe un movimiento operativo sin usuario identificable.'];
        }

        if ($stock->contains(fn (StockPallet $batch): bool => ! $batch->active && (int) $batch->quantity_units > 0)) {
            $issues[] = ['level' => 'inconsistent', 'message' => 'Existe una partida inactiva con saldo positivo.'];
        }

        if ($movements->isNotEmpty()) {
            $ledgerBalance = (int) $movements->sum('units_delta');
            $currentBalance = (int) $stock->where('active', true)->sum('quantity_units');

            if ($ledgerBalance !== $currentBalance) {
                $issues[] = [
                    'level' => 'partial',
                    'message' => "El saldo del ledger ({$ledgerBalance}) no coincide aun con el stock actual ({$currentBalance}); falta historico o saldo inicial.",
                ];
            }
        }

        return $issues;
    }
}
