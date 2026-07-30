<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\StockPallet;
use Illuminate\Support\Carbon;
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

    public function syncDay(DailyOperationDay $day, ?int $openingPallets = null, ?string $notes = null, ?int $updatedBy = null): DailyOperationDay
    {
        $day->loadMissing('lines');

        $opening = $openingPallets !== null
            ? max(0, (int) $openingPallets)
            : ($day->client_id !== null
                ? $this->stockBaseForClient((int) $day->client_id)
                : 0);

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

    public function openingPalletsForDate(string $operationDate, int $clientId, int $inboundPallets, int $outboundPallets): int
    {
        $date = Carbon::parse($operationDate)->toDateString();
        $openingFromCurrentStock = $this->openingPalletsFromCurrentStock($clientId, $inboundPallets, $outboundPallets);

        if ($this->usesLiveStockBaseForDate($date)) {
            return $openingFromCurrentStock;
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

    private function openingPalletsFromCurrentStock(int $clientId, int $inboundPallets, int $outboundPallets): int
    {
        return max(0, $this->stockBaseForClient($clientId) + $outboundPallets - $inboundPallets);
    }

    private function usesLiveStockBaseForDate(string $operationDate): bool
    {
        return $operationDate === Carbon::now(config('app.timezone', 'UTC'))->toDateString();
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
