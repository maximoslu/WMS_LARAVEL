<?php

namespace App\Services\DailyOperations;

use App\Enums\MerchandiseRequestServiceLevel;
use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\StockPallet;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DailyOperationTotalsService
{
    /**
     * @return array<string, int>
     */
    public function sectionBreakdown(DailyOperationDay $day): array
    {
        $day->loadMissing('lines');

        return collect(DailyOperationLine::sections())
            ->mapWithKeys(fn (string $section): array => [
                $section => (int) $day->lines->where('section', $section)->sum('pallets'),
            ])
            ->all();
    }

    /**
     * @return array{
     *     normal:array{moved_pallets:int,truck_management:int,truck_trips:int},
     *     ff:array{moved_pallets:int,truck_management:int,truck_trips:int},
     *     ff_dispatch_ids:array<int, int>
     * }
     */
    public function serviceLevelBreakdown(DailyOperationDay $day): array
    {
        $day->loadMissing('lines');
        $dispatchIds = $day->lines
            ->filter(fn (DailyOperationLine $line): bool => $line->source_type === DailyOperationLine::SOURCE_GOODS_DISPATCH
                && $line->source_id !== null)
            ->pluck('source_id')
            ->map(fn ($sourceId): int => (int) $sourceId)
            ->filter(fn (int $sourceId): bool => $sourceId > 0)
            ->unique()
            ->values();

        $ffDispatchIds = $dispatchIds->isEmpty() || $day->client_id === null
            ? []
            : GoodsDispatch::query()
                ->where('client_id', $day->client_id)
                ->whereIn('id', $dispatchIds->all())
                ->whereHas('merchandiseRequest', fn ($query) => $query->where(
                    'service_level',
                    MerchandiseRequestServiceLevel::SAME_DAY->value,
                ))
                ->pluck('id')
                ->map(fn ($dispatchId): int => (int) $dispatchId)
                ->all();

        $ffLines = $day->lines->filter(fn (DailyOperationLine $line): bool => $line->source_type === DailyOperationLine::SOURCE_GOODS_DISPATCH
            && in_array((int) $line->source_id, $ffDispatchIds, true));

        return [
            'normal' => $this->operationalServiceTotals($day->lines->diff($ffLines)),
            'ff' => $this->operationalServiceTotals($ffLines),
            'ff_dispatch_ids' => $ffDispatchIds,
        ];
    }

    public function syncDay(DailyOperationDay $day, ?int $openingPallets = null, ?string $notes = null, ?int $updatedBy = null): DailyOperationDay
    {
        $day->loadMissing('lines');

        $opening = $openingPallets !== null
            ? max(0, (int) $openingPallets)
            : $this->openingForSync($day);

        $breakdown = $this->sectionBreakdown($day);
        $inbound = (int) collect(DailyOperationLine::movementInboundSections())
            ->sum(fn (string $section): int => (int) ($breakdown[$section] ?? 0));
        $outbound = (int) collect(DailyOperationLine::movementOutboundSections())
            ->sum(fn (string $section): int => (int) ($breakdown[$section] ?? 0));

        $day->fill([
            'opening_pallets' => $opening,
            'stored_pallets_today' => $opening + $inbound,
            'moved_pallets_today' => $inbound + $outbound,
            'expected_pallets_tomorrow' => max(0, $opening + $inbound - $outbound),
            'notes' => $notes ?? $day->notes,
            'updated_by' => $updatedBy ?? $day->updated_by,
        ])->save();

        return $day->fresh(['client', 'creator', 'updater', 'lines.creator']);
    }

    public function stockBaseForClient(int $clientId): int
    {
        return (int) StockPallet::query()
            ->where('client_id', $clientId)
            ->whereHas('item')
            ->withPhysicalStock()
            ->sum(DB::raw('COALESCE(warehouse_pallets, full_pallets + peaks_count)'));
    }

    /**
     * @return array{inbound:int, outbound:int}
     */
    public function externalMovementTotalsForDate(string $operationDate, int $clientId): array
    {
        $date = Carbon::parse($operationDate)->toDateString();
        $inbound = 0;
        $outbound = 0;

        GoodsReceipt::query()
            ->with('lines')
            ->where('client_id', $clientId)
            ->where('status', GoodsReceipt::STATUS_CONFIRMED)
            ->whereDate('received_at', $date)
            ->get()
            ->each(fn (GoodsReceipt $receipt) => $inbound += $this->receiptLogisticUnits($receipt));

        GoodsDispatch::query()
            ->with('lines.allocations')
            ->where('client_id', $clientId)
            ->whereIn('status', [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED])
            ->where(function ($query) use ($date): void {
                $query
                    ->where(function ($query) use ($date): void {
                        $query->where('status', GoodsDispatch::STATUS_COMPLETED)
                            ->whereDate('completed_at', $date);
                    })
                    ->orWhere(function ($query) use ($date): void {
                        $query->where('status', GoodsDispatch::STATUS_SENT)
                            ->whereDate('sent_at', $date);
                    });
            })
            ->get()
            ->each(fn (GoodsDispatch $dispatch) => $outbound += $this->dispatchLogisticUnits($dispatch));

        return ['inbound' => $inbound, 'outbound' => $outbound];
    }

    public function openingPalletsForDate(
        string $operationDate,
        int $clientId,
        int $inboundPallets,
        int $outboundPallets,
        ?int $storedOpeningPallets = null,
    ): int
    {
        $date = Carbon::parse($operationDate)->toDateString();
        $openingFromCurrentStock = $this->openingPalletsFromCurrentStockForDate($clientId, $inboundPallets, $outboundPallets);

        if ($this->usesLiveStockBaseForDate($date)) {
            return $openingFromCurrentStock;
        }

        if ($storedOpeningPallets !== null) {
            return max(0, $storedOpeningPallets);
        }

        $previousDate = Carbon::parse($date)->subDay()->toDateString();
        $previousDay = DailyOperationDay::query()
            ->whereDate('operation_date', $previousDate)
            ->where('client_id', $clientId)
            ->first();

        if ($previousDay instanceof DailyOperationDay) {
            return max(0, (int) $previousDay->expected_pallets_tomorrow);
        }

        return $openingFromCurrentStock;
    }

    public function openingPalletsFromCurrentStockForDate(int $clientId, int $inboundPallets, int $outboundPallets): int
    {
        return max(0, $this->stockBaseForClient($clientId) + $outboundPallets - $inboundPallets);
    }

    public function usesLiveStockBaseForDate(string $operationDate): bool
    {
        return $operationDate === Carbon::now(config('app.timezone', 'UTC'))->toDateString();
    }

    private function openingForSync(DailyOperationDay $day): int
    {
        if ($day->client_id === null) {
            return 0;
        }

        $operationDate = $day->operation_date?->toDateString();

        if ($operationDate !== null
            && ! $this->usesLiveStockBaseForDate($operationDate)
            && $day->exists
            && $day->opening_pallets !== null) {
            return max(0, (int) $day->opening_pallets);
        }

        return $this->stockBaseForClient((int) $day->client_id);
    }

    /**
     * @param  Collection<int, DailyOperationLine>  $lines
     * @return array{moved_pallets:int,truck_management:int,truck_trips:int}
     */
    private function operationalServiceTotals(Collection $lines): array
    {
        $movementSections = [
            ...DailyOperationLine::movementInboundSections(),
            ...DailyOperationLine::movementOutboundSections(),
        ];

        return [
            'moved_pallets' => (int) $lines
                ->whereIn('section', $movementSections)
                ->sum('pallets'),
            'truck_management' => (int) $lines
                ->where('section', DailyOperationLine::SECTION_GESTION_CAMION)
                ->sum('pallets'),
            'truck_trips' => (int) $lines
                ->where('section', DailyOperationLine::SECTION_VIAJE_CAMION)
                ->sum('pallets'),
        ];
    }

    public function receiptLogisticUnits(GoodsReceipt $receipt): int
    {
        $receipt->loadMissing('lines');

        $lineUnits = (int) $receipt->lines->sum(function (GoodsReceiptLine $line) use ($receipt): int {
            $movementUnits = $this->receiptMovementWarehousePalletsForCurrentLine($receipt, $line);

            return max($movementUnits, $this->receiptLineLogisticUnits($line));
        });

        return max(0, $lineUnits);
    }

    public function dispatchLogisticUnits(GoodsDispatch $dispatch): int
    {
        $dispatch->loadMissing('lines.allocations');
        $documentMovementPallets = $this->movementWarehousePallets($dispatch, InventoryMovement::DISPATCH);
        $dispatchPallets = max(0, (int) $dispatch->lines->sum(function (GoodsDispatchLine $line) use ($dispatch): int {
            $movementUnits = $this->movementWarehousePalletsForLine($dispatch, $line, InventoryMovement::DISPATCH);

            return $movementUnits > 0 ? $movementUnits : $this->dispatchLineLogisticUnits($line);
        }));

        if ($dispatchPallets === 0) {
            $dispatchPallets = $documentMovementPallets;
        } else {
            $dispatchPallets = max(
                $dispatchPallets,
                $documentMovementPallets,
            );
        }

        if ($dispatchPallets === 0) {
            $dispatchPallets = max(0, $dispatch->palletsCount() + $dispatch->peaksCount());
        }

        return $dispatchPallets;
    }

    private function receiptLineLogisticUnits(GoodsReceiptLine $line): int
    {
        return (int) $line->pallet_count + ($line->peakUnits() !== [] ? count($line->peakUnits()) : 0);
    }

    private function receiptMovementWarehousePalletsForCurrentLine(GoodsReceipt $receipt, GoodsReceiptLine $line): int
    {
        $delta = (float) InventoryMovement::query()
            ->where('source_type', $receipt->getMorphClass())
            ->where('source_id', $receipt->getKey())
            ->where('source_line_type', $line->getMorphClass())
            ->where('source_line_id', $line->getKey())
            ->where('movement_type', InventoryMovement::RECEIPT)
            ->latest('recorded_at')
            ->latest('id')
            ->value('warehouse_pallets_delta');

        return (int) round(abs($delta));
    }

    private function dispatchLineLogisticUnits(GoodsDispatchLine $line): int
    {
        $pallets = $line->loadedPallets();

        if ($line->isPeakLine()) {
            return $pallets + $line->loadedPeaks();
        }

        return $pallets + ($line->loadedPartialUnits() > 0 ? 1 : 0);
    }

    private function movementWarehousePallets(GoodsReceipt|GoodsDispatch $source, string $movementType): int
    {
        $delta = (float) InventoryMovement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('movement_type', $movementType)
            ->sum('warehouse_pallets_delta');

        return (int) round(abs($delta));
    }

    private function movementWarehousePalletsForLine(
        GoodsReceipt|GoodsDispatch $source,
        GoodsReceiptLine|GoodsDispatchLine $line,
        string $movementType,
    ): int {
        $delta = (float) InventoryMovement::query()
            ->where('source_type', $source->getMorphClass())
            ->where('source_id', $source->getKey())
            ->where('source_line_type', $line->getMorphClass())
            ->where('source_line_id', $line->getKey())
            ->where('movement_type', $movementType)
            ->sum('warehouse_pallets_delta');

        return (int) round(abs($delta));
    }
}
