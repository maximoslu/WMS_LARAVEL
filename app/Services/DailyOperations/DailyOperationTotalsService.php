<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use Illuminate\Support\Carbon;

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

        $opening = $openingPallets;

        if ($opening === null) {
            $opening = $day->opening_pallets;
        }

        if ($opening === null) {
            $opening = $this->previousExpectedPallets($day) ?? 0;
        }

        $opening = max(0, (int) $opening);
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
