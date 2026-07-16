<?php

namespace App\Services\Traceability;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsDispatchLineAllocation;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\StockPallet;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class TraceabilityBackfillService
{
    public function __construct(private readonly AuditLogService $audit) {}

    /** @return array<string, int> */
    public function run(?int $clientId = null, bool $apply = false): array
    {
        $receiptLines = GoodsReceiptLine::query()
            ->with(['goodsReceipt.client', 'goodsReceipt.supplier', 'item'])
            ->whereHas('goodsReceipt', fn (Builder $query) => $query
                ->where('status', GoodsReceipt::STATUS_CONFIRMED)
                ->when($clientId !== null, fn (Builder $clientQuery) => $clientQuery->where('client_id', $clientId)))
            ->get();
        $allocations = GoodsDispatchLineAllocation::query()
            ->with(['line.dispatch.client', 'line.item', 'stockPallet.location.warehouse'])
            ->whereHas('line.dispatch', fn (Builder $query) => $query
                ->whereIn('status', [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED])
                ->when($clientId !== null, fn (Builder $clientQuery) => $clientQuery->where('client_id', $clientId)))
            ->get();
        $unallocatedLines = GoodsDispatchLine::query()
            ->whereHas('dispatch', fn (Builder $query) => $query
                ->whereIn('status', [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED])
                ->when($clientId !== null, fn (Builder $clientQuery) => $clientQuery->where('client_id', $clientId)))
            ->whereDoesntHave('allocations')
            ->whereNull('stock_pallet_id')
            ->where(function (Builder $query): void {
                $query->where('loaded_pallets', '>', 0)->orWhere('loaded_partial_units', '>', 0);
            })
            ->count();
        $openingBalances = StockPallet::query()
            ->where('active', true)
            ->where('quantity_units', '>', 0)
            ->when($clientId !== null, fn (Builder $query) => $query->where('client_id', $clientId))
            ->whereDoesntHave('goodsReceipt')
            ->count();
        $summary = [
            'certain_receipts' => $receiptLines->whereNotNull('goods_receipt_id')->count(),
            'certain_dispatches' => $allocations->count(),
            'partial_receipts' => 0,
            'impossible_dispatches' => $unallocatedLines,
            'opening_balances' => $openingBalances,
            'created_movements' => 0,
            'existing_movements' => 0,
        ];

        if (! $apply) {
            return $summary;
        }

        return DB::transaction(function () use ($receiptLines, $allocations, $clientId, $summary): array {
            $result = $summary;

            foreach ($receiptLines as $line) {
                $key = "backfill:receipt-line:{$line->id}";

                if (InventoryMovement::query()->where('idempotency_key', $key)->exists()) {
                    $result['existing_movements']++;

                    continue;
                }

                $stock = StockPallet::query()
                    ->where('goods_receipt_id', $line->goods_receipt_id)
                    ->where('item_id', $line->item_id)
                    ->when($line->lot !== null, fn (Builder $query) => $query->where('lot', $line->lot))
                    ->first();
                $confidence = $stock instanceof StockPallet ? 'exact' : 'partial';

                if ($confidence === 'partial') {
                    $result['partial_receipts']++;
                }

                $this->createHistoricalMovement(
                    key: $key,
                    correlationId: $this->stableUuid($key),
                    clientId: (int) $line->goodsReceipt->client_id,
                    clientName: $line->goodsReceipt->client?->name,
                    itemId: $line->item_id,
                    sku: $line->sku ?? $line->item?->sku,
                    description: $line->description ?? $line->item?->description,
                    lot: $line->lot,
                    stockPalletId: $stock?->id,
                    movementType: InventoryMovement::RECEIPT,
                    unitsDelta: (int) $line->quantity_units,
                    fullPalletsDelta: (int) $line->pallet_count,
                    source: $line->goodsReceipt,
                    sourceLine: $line,
                    effectiveAt: $line->goodsReceipt->received_at ?? $line->goodsReceipt->confirmed_at,
                    metadata: ['label' => 'Entrada historica reconstruida', 'balances_reconstructed' => false],
                    confidence: $confidence,
                );
                $result['created_movements']++;
            }

            foreach ($allocations as $allocation) {
                $key = "backfill:dispatch-allocation:{$allocation->id}";

                if (InventoryMovement::query()->where('idempotency_key', $key)->exists()) {
                    $result['existing_movements']++;

                    continue;
                }

                $line = $allocation->line;
                $dispatch = $line->dispatch;
                $unitsPerPallet = (int) ($allocation->stockPallet?->units_per_pallet ?? $line->units_per_pallet ?? 0);
                $units = ((int) $allocation->loaded_pallets * $unitsPerPallet) + (int) $allocation->loaded_partial_units;

                $this->createHistoricalMovement(
                    key: $key,
                    correlationId: $this->stableUuid('backfill:dispatch:'.$dispatch->id),
                    clientId: (int) $dispatch->client_id,
                    clientName: $dispatch->client?->name,
                    itemId: $line->item_id,
                    sku: $line->sku ?? $line->item?->sku,
                    description: $line->description ?? $line->item?->description,
                    lot: $allocation->lot ?? $allocation->stockPallet?->lot ?? $line->lot,
                    stockPalletId: $allocation->stock_pallet_id,
                    movementType: InventoryMovement::DISPATCH,
                    unitsDelta: -$units,
                    fullPalletsDelta: -(int) $allocation->loaded_pallets,
                    source: $dispatch,
                    sourceLine: $line,
                    effectiveAt: $dispatch->sent_at ?? $dispatch->completed_at ?? $dispatch->created_at,
                    metadata: [
                        'label' => 'Salida historica reconstruida desde allocation',
                        'allocation_id' => $allocation->id,
                        'destination' => $line->destination_location ?? $dispatch->merchandiseRequest?->delivery_address,
                        'balances_reconstructed' => false,
                    ],
                    confidence: 'exact',
                );
                $result['created_movements']++;
            }

            $stocks = StockPallet::query()
                ->with(['client', 'item', 'location.warehouse'])
                ->where('active', true)
                ->where('quantity_units', '>', 0)
                ->when($clientId !== null, fn (Builder $query) => $query->where('client_id', $clientId))
                ->get();

            foreach ($stocks as $stock) {
                if (InventoryMovement::query()->where('stock_pallet_id', $stock->id)->exists()) {
                    continue;
                }

                $key = "backfill:opening-balance:{$stock->id}";
                $this->createHistoricalMovement(
                    key: $key,
                    correlationId: $this->stableUuid($key),
                    clientId: (int) $stock->client_id,
                    clientName: $stock->client?->name,
                    itemId: $stock->item_id,
                    sku: $stock->item?->sku,
                    description: $stock->item?->description,
                    lot: $stock->lot,
                    stockPalletId: $stock->id,
                    movementType: InventoryMovement::OPENING_BALANCE,
                    unitsDelta: (int) $stock->quantity_units,
                    fullPalletsDelta: (int) $stock->full_pallets,
                    source: $stock,
                    sourceLine: null,
                    effectiveAt: now(),
                    metadata: ['label' => 'Saldo inicial al activar trazabilidad', 'not_a_historical_receipt' => true],
                    confidence: 'opening_balance',
                );
                $result['created_movements']++;
            }

            $this->audit->record(
                event: 'traceability_backfill_applied',
                module: 'traceability',
                description: 'Backfill de trazabilidad aplicado sin modificar stock ni operaciones.',
                clientId: $clientId,
                newValues: $result,
                source: 'command',
                severity: 'important',
            );

            return $result;
        });
    }

    /** @param array<string, mixed> $metadata */
    private function createHistoricalMovement(
        string $key,
        string $correlationId,
        int $clientId,
        ?string $clientName,
        ?int $itemId,
        ?string $sku,
        ?string $description,
        ?string $lot,
        ?int $stockPalletId,
        string $movementType,
        int $unitsDelta,
        int $fullPalletsDelta,
        object $source,
        ?object $sourceLine,
        mixed $effectiveAt,
        array $metadata,
        string $confidence,
    ): void {
        InventoryMovement::query()->create([
            'uuid' => (string) Str::uuid(),
            'correlation_id' => $correlationId,
            'idempotency_key' => $key,
            'client_id' => $clientId,
            'client_name' => $clientName,
            'item_id' => $itemId,
            'sku' => $sku,
            'description' => $description,
            'lot' => $lot,
            'stock_pallet_id' => $stockPalletId,
            'movement_type' => $movementType,
            'source' => 'backfill',
            'source_type' => method_exists($source, 'getMorphClass') ? $source->getMorphClass() : $source::class,
            'source_id' => method_exists($source, 'getKey') ? $source->getKey() : null,
            'source_line_type' => $sourceLine !== null && method_exists($sourceLine, 'getMorphClass') ? $sourceLine->getMorphClass() : null,
            'source_line_id' => $sourceLine !== null && method_exists($sourceLine, 'getKey') ? $sourceLine->getKey() : null,
            'units_before' => null,
            'units_delta' => $unitsDelta,
            'units_after' => null,
            'full_pallets_before' => null,
            'full_pallets_delta' => $fullPalletsDelta,
            'full_pallets_after' => null,
            'warehouse_pallets_delta' => $fullPalletsDelta,
            'metadata' => $metadata,
            'reconstruction_confidence' => $confidence,
            'effective_at' => $effectiveAt ?? now(),
            'recorded_at' => now(),
            'created_at' => now(),
        ]);
    }

    private function stableUuid(string $value): string
    {
        $hex = md5($value);

        return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-4'.substr($hex, 13, 3).'-a'.substr($hex, 17, 3).'-'.substr($hex, 20, 12);
    }
}
