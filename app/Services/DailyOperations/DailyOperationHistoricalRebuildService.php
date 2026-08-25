<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use Illuminate\Support\Carbon;
use Illuminate\Validation\ValidationException;

class DailyOperationHistoricalRebuildService
{
    public function __construct(
        private readonly DailyOperationTotalsService $totalsService,
    ) {}

    public function openingForDate(string $operationDate, int $clientId, ?DailyOperationDay $targetDay = null): int
    {
        $date = Carbon::parse($operationDate)->startOfDay();

        if ($targetDay?->is_historical_anchor && $targetDay->opening_pallets !== null) {
            return max(0, (int) $targetDay->opening_pallets);
        }

        $anchor = DailyOperationDay::query()
            ->where('client_id', $clientId)
            ->where('is_historical_anchor', true)
            ->whereNotNull('opening_pallets')
            ->whereDate('operation_date', '<', $date->toDateString())
            ->orderByDesc('operation_date')
            ->first();

        if ($anchor === null) {
            throw ValidationException::withMessages([
                'operation_date' => 'No hay base histórica fiable para reconstruir este día. Ajusta una base anterior para crear un ancla.',
            ]);
        }

        $opening = max(0, (int) $anchor->expected_pallets_tomorrow);
        $cursor = $anchor->operation_date?->copy()->addDay()->startOfDay();

        while ($cursor !== null && $cursor->lt($date)) {
            $movement = $this->totalsService->externalMovementTotalsForDate($cursor->toDateString(), $clientId);
            $opening = max(0, $opening + $movement['inbound'] - $movement['outbound']);
            $cursor = $cursor->addDay();
        }

        return $opening;
    }
}
