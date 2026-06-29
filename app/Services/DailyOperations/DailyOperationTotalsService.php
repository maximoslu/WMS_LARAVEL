<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use Illuminate\Support\Carbon;

class DailyOperationTotalsService
{
    public function syncDay(DailyOperationDay $day, ?int $openingPallets = null, ?string $notes = null, ?int $updatedBy = null): DailyOperationDay
    {
        $day->loadMissing('lines');

        $opening = $openingPallets;

        if ($opening === null) {
            $opening = $day->opening_pallets;
        }

        if ($opening === null) {
            $opening = $this->previousExpectedPallets($day) ?? 0;
        }

        $opening = max(0, (int) $opening);
        $inbound = (int) $day->lines
            ->whereIn('section', DailyOperationLine::movementInboundSections())
            ->sum('pallets');
        $outbound = (int) $day->lines
            ->whereIn('section', DailyOperationLine::movementOutboundSections())
            ->sum('pallets');

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

    private function previousExpectedPallets(DailyOperationDay $day): ?int
    {
        if ($day->client_id === null || $day->operation_date === null) {
            return null;
        }

        return DailyOperationDay::query()
            ->where('client_id', $day->client_id)
            ->whereDate('operation_date', '<', Carbon::parse($day->operation_date)->toDateString())
            ->orderByDesc('operation_date')
            ->value('expected_pallets_tomorrow');
    }
}
