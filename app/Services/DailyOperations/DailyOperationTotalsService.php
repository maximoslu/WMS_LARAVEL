<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\Item;
use App\Models\StockPallet;

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

        $opening = $day->client_id !== null
            ? $this->stockBaseForClient((int) $day->client_id)
            : max(0, (int) $openingPallets);

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
            ->where('active', true)
            ->where('status', '!=', StockPallet::STATUS_OBSOLETE)
            ->whereHas('item', fn ($query) => $query->where('status', '!=', Item::STATUS_OBSOLETE))
            ->where(function ($query): void {
                $query
                    ->where('quantity_units', '>', 0)
                    ->orWhere('full_pallets', '>', 0)
                    ->orWhere('peaks_count', '>', 0);
            })
            ->sum('full_pallets');
    }
}
