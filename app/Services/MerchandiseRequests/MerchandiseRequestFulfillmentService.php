<?php

namespace App\Services\MerchandiseRequests;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestLine;
use Illuminate\Support\Collection;

class MerchandiseRequestFulfillmentService
{
    /**
     * @return array{
     *     lines:Collection<int, array<string, mixed>>,
     *     target_units:int,
     *     served_units:int,
     *     current_units:int,
     *     pending_units:int,
     *     pending_units_after_current:int,
     *     is_fully_served:bool,
     *     has_pending_before_current:bool,
     *     has_pending_after_current:bool
     * }
     */
    public function summary(MerchandiseRequest $request, ?GoodsDispatch $currentDispatch = null): array
    {
        $request->loadMissing([
            'lines.item',
            'goodsDispatches.lines.allocations.stockPallet.location.warehouse',
            'goodsDispatches.lines.stockPallet.location.warehouse',
            'goodsDispatches.lines.sourceRequestLine',
        ]);

        $finalizedStatuses = [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED];
        $finalizedDispatches = $request->goodsDispatches
            ->filter(fn (GoodsDispatch $dispatch): bool => in_array($dispatch->status, $finalizedStatuses, true))
            ->reject(fn (GoodsDispatch $dispatch): bool => $currentDispatch !== null && (int) $dispatch->id === (int) $currentDispatch->id);

        $currentLinesByRequestLine = $currentDispatch
            ? $currentDispatch->loadMissing(['lines.allocations.stockPallet.location.warehouse', 'lines.stockPallet.location.warehouse'])->lines->groupBy('source_request_line_id')
            : collect();

        $servedLinesByRequestLine = $finalizedDispatches
            ->flatMap(fn (GoodsDispatch $dispatch): Collection => $dispatch->lines)
            ->filter(fn (GoodsDispatchLine $line): bool => $line->source_request_line_id !== null)
            ->groupBy('source_request_line_id');

        $lineSummaries = $request->lines->map(function (MerchandiseRequestLine $line) use ($servedLinesByRequestLine, $currentLinesByRequestLine): array {
            $targetUnits = $line->requiredUnits() ?? $line->requestedUnitsTotal();
            $servedLines = $servedLinesByRequestLine->get($line->id, collect());
            $servedUnits = (int) $servedLines
                ->sum(fn (GoodsDispatchLine $dispatchLine): int => $dispatchLine->loadedUnitsTotal());
            $currentUnits = (int) ($currentLinesByRequestLine->get($line->id, collect()))
                ->sum(fn (GoodsDispatchLine $dispatchLine): int => $dispatchLine->loadedUnitsTotal());
            $servedPallets = (int) $servedLines
                ->sum(fn (GoodsDispatchLine $dispatchLine): int => $dispatchLine->loadedPallets());
            $currentPallets = (int) ($currentLinesByRequestLine->get($line->id, collect()))
                ->sum(fn (GoodsDispatchLine $dispatchLine): int => $dispatchLine->loadedPallets());
            $servedPeaks = (int) $servedLines
                ->sum(fn (GoodsDispatchLine $dispatchLine): int => $this->loadedPeakCount($dispatchLine));
            $currentPeaks = (int) ($currentLinesByRequestLine->get($line->id, collect()))
                ->sum(fn (GoodsDispatchLine $dispatchLine): int => $this->loadedPeakCount($dispatchLine));
            $targetPallets = $line->requestedPalletsCount();
            $targetPeaks = $line->requestedPeaksCount();
            $pendingBeforeCurrent = max(0, $targetUnits - $servedUnits);
            $pendingAfterCurrent = max(0, $targetUnits - $servedUnits - $currentUnits);

            return [
                'request_line' => $line,
                'target_units' => $targetUnits,
                'served_units' => $servedUnits,
                'current_units' => $currentUnits,
                'target_pallets' => $targetPallets,
                'served_pallets' => $servedPallets,
                'current_pallets' => $currentPallets,
                'pending_pallets_after_current' => max(0, $targetPallets - $servedPallets - $currentPallets),
                'target_peaks' => $targetPeaks,
                'served_peaks' => $servedPeaks,
                'current_peaks' => $currentPeaks,
                'pending_peaks_after_current' => max(0, $targetPeaks - $servedPeaks - $currentPeaks),
                'pending_units' => $pendingBeforeCurrent,
                'pending_units_after_current' => $pendingAfterCurrent,
                'is_fully_served' => $pendingAfterCurrent === 0,
                'served_picking_locations' => $servedLines
                    ->flatMap(fn (GoodsDispatchLine $dispatchLine): Collection => $dispatchLine->pickingLocationSummaries())
                    ->values(),
            ];
        });

        $targetUnits = (int) $lineSummaries->sum('target_units');
        $servedUnits = (int) $lineSummaries->sum('served_units');
        $currentUnits = (int) $lineSummaries->sum('current_units');
        $pendingUnits = (int) $lineSummaries->sum('pending_units');
        $pendingAfterCurrent = (int) $lineSummaries->sum('pending_units_after_current');

        return [
            'lines' => $lineSummaries,
            'target_units' => $targetUnits,
            'served_units' => $servedUnits,
            'current_units' => $currentUnits,
            'pending_units' => $pendingUnits,
            'pending_units_after_current' => $pendingAfterCurrent,
            'is_fully_served' => $pendingAfterCurrent === 0,
            'has_pending_before_current' => $pendingUnits > 0,
            'has_pending_after_current' => $pendingAfterCurrent > 0,
        ];
    }

    private function loadedPeakCount(GoodsDispatchLine $line): int
    {
        if ($line->hasLoadingAllocations()) {
            return (int) $line->allocations
                ->sum(fn ($allocation): int => count($allocation->selected_peaks ?? []));
        }

        return $line->loadedPeaks();
    }

    /**
     * @return Collection<int, array<string, mixed>>
     */
    public function pendingLinesForNextDispatch(MerchandiseRequest $request): Collection
    {
        return $this->summary($request)['lines']
            ->filter(fn (array $line): bool => (int) $line['pending_units'] > 0)
            ->values();
    }

    /**
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public function closureSnapshot(array $summary): array
    {
        return [
            'target_units' => $summary['target_units'],
            'served_units' => $summary['served_units'] + $summary['current_units'],
            'unserved_units' => $summary['pending_units_after_current'],
            'lines' => $summary['lines']->map(fn (array $line): array => [
                'request_line_id' => $line['request_line']->id,
                'sku' => $line['request_line']->item?->sku,
                'target_units' => $line['target_units'],
                'served_units' => $line['served_units'] + $line['current_units'],
                'unserved_units' => $line['pending_units_after_current'],
            ])->values()->all(),
        ];
    }
}
