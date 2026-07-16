<?php

namespace App\Services\Traceability;

use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestLine;
use App\Models\StockPallet;
use Illuminate\Support\Carbon;

class StockForecastService
{
    /**
     * @return array<string, mixed>
     */
    public function forecast(
        Item $item,
        bool $includeBlocked = false,
        bool $includeObsolete = false,
        int $leadTimeDays = 0,
        int $safetyStockUnits = 0,
    ): array {
        $availableQuery = StockPallet::query()
            ->where('client_id', $item->client_id)
            ->where('item_id', $item->id)
            ->where('active', true);

        if (! $includeBlocked) {
            $availableQuery->where('status', '!=', StockPallet::STATUS_BLOCKED)
                ->where('stock_category', '!=', StockPallet::CATEGORY_BLOCKED);
        }

        if (! $includeObsolete) {
            $availableQuery->where('status', '!=', StockPallet::STATUS_OBSOLETE)
                ->where('stock_category', '!=', StockPallet::CATEGORY_OBSOLETE);
        }

        $availableUnits = (int) (clone $availableQuery)->sum('quantity_units');
        $availablePallets = (float) (clone $availableQuery)->sum('warehouse_pallets');
        $pendingDemand = $this->pendingDemandUnits($item);
        $netAvailable = max(0, $availableUnits - $pendingDemand - $safetyStockUnits);
        $since = now()->subDays(90)->startOfDay();
        $daily = InventoryMovement::query()
            ->where('client_id', $item->client_id)
            ->where('item_id', $item->id)
            ->where('movement_type', InventoryMovement::DISPATCH)
            ->where('units_delta', '<', 0)
            ->where('effective_at', '>=', $since)
            ->selectRaw('DATE(effective_at) as movement_date, SUM(ABS(units_delta)) as units')
            ->groupByRaw('DATE(effective_at)')
            ->orderBy('movement_date')
            ->pluck('units', 'movement_date')
            ->map(fn (mixed $value): float => (float) $value);

        $firstMovementAt = InventoryMovement::query()
            ->where('client_id', $item->client_id)
            ->where('item_id', $item->id)
            ->where('movement_type', InventoryMovement::DISPATCH)
            ->where('units_delta', '<', 0)
            ->min('effective_at');
        $historyDays = $firstMovementAt !== null
            ? max(1, Carbon::parse($firstMovementAt)->startOfDay()->diffInDays(now()->startOfDay()) + 1)
            : 0;

        $average7 = $this->calendarAverage($daily->all(), 7);
        $average30 = $this->calendarAverage($daily->all(), 30);
        $average90 = $this->calendarAverage($daily->all(), 90);
        $weightedAverage = ($average7 * 0.5) + ($average30 * 0.3) + ($average90 * 0.2);
        $standardDeviation = $this->standardDeviation($daily->values()->all());

        if ($historyDays === 0 || $daily->isEmpty()) {
            return $this->result(
                $availableUnits,
                $availablePallets,
                $pendingDemand,
                $netAvailable,
                $leadTimeDays,
                $safetyStockUnits,
                $historyDays,
                $average7,
                $average30,
                $average90,
                0,
                $standardDeviation,
                'insufficient',
                'Sin salidas registradas.',
            );
        }

        if ($historyDays < 14) {
            return $this->result(
                $availableUnits,
                $availablePallets,
                $pendingDemand,
                $netAvailable,
                $leadTimeDays,
                $safetyStockUnits,
                $historyDays,
                $average7,
                $average30,
                $average90,
                $weightedAverage,
                $standardDeviation,
                'insufficient',
                "Solo existen {$historyDays} dias de historico.",
            );
        }

        $coefficientOfVariation = $weightedAverage > 0 ? $standardDeviation / $weightedAverage : 0;
        $confidence = $historyDays >= 60 && $coefficientOfVariation <= 1 ? 'high' : ($coefficientOfVariation <= 2 ? 'medium' : 'low');
        $reason = $confidence === 'low'
            ? 'Consumo irregular; confianza baja.'
            : 'Prevision basada en medias moviles ponderadas de 7, 30 y 90 dias.';

        return $this->result(
            $availableUnits,
            $availablePallets,
            $pendingDemand,
            $netAvailable,
            $leadTimeDays,
            $safetyStockUnits,
            $historyDays,
            $average7,
            $average30,
            $average90,
            $weightedAverage,
            $standardDeviation,
            $confidence,
            $reason,
        );
    }

    private function pendingDemandUnits(Item $item): int
    {
        return (int) MerchandiseRequestLine::query()
            ->where('item_id', $item->id)
            ->whereHas('merchandiseRequest', fn ($query) => $query
                ->where('client_id', $item->client_id)
                ->whereIn('status', [MerchandiseRequest::STATUS_PENDING, MerchandiseRequest::STATUS_PREPARING]))
            ->selectRaw('COALESCE(SUM(COALESCE(requested_units, (COALESCE(requested_pallets, 0) * COALESCE(units_per_pallet, 0)) + (COALESCE(requested_peaks, 0) * COALESCE(units_per_peak, 0)))), 0) as total')
            ->value('total');
    }

    /** @param array<string, float> $daily */
    private function calendarAverage(array $daily, int $days): float
    {
        $cutoff = now()->subDays($days - 1)->toDateString();
        $total = collect($daily)
            ->filter(fn (float $units, string $date): bool => $date >= $cutoff)
            ->sum();

        return round($total / $days, 4);
    }

    /** @param list<float> $values */
    private function standardDeviation(array $values): float
    {
        if (count($values) < 2) {
            return 0;
        }

        $average = array_sum($values) / count($values);
        $variance = array_sum(array_map(fn (float $value): float => ($value - $average) ** 2, $values)) / count($values);

        return round(sqrt($variance), 4);
    }

    /** @return array<string, mixed> */
    private function result(
        int $availableUnits,
        float $availablePallets,
        int $pendingDemand,
        int $netAvailable,
        int $leadTimeDays,
        int $safetyStockUnits,
        int $historyDays,
        float $average7,
        float $average30,
        float $average90,
        float $weightedAverage,
        float $standardDeviation,
        string $confidence,
        string $reason,
    ): array {
        $coverageDays = $weightedAverage > 0 ? round($netAvailable / $weightedAverage, 2) : null;
        $exhaustionDate = $coverageDays !== null ? now()->addDays((int) floor($coverageDays))->toDateString() : null;

        return [
            'method' => 'Media movil ponderada 7/30/90 dias',
            'period_days' => min(90, $historyDays),
            'history_days' => $historyDays,
            'available_units' => $availableUnits,
            'available_pallets' => $availablePallets,
            'pending_demand_units' => $pendingDemand,
            'safety_stock_units' => $safetyStockUnits,
            'net_available_units' => $netAvailable,
            'lead_time_days' => $leadTimeDays,
            'average_daily_7' => $average7,
            'average_daily_30' => $average30,
            'average_daily_90' => $average90,
            'weighted_daily_average' => round($weightedAverage, 4),
            'standard_deviation' => $standardDeviation,
            'coverage_days' => $coverageDays,
            'estimated_exhaustion_date' => $exhaustionDate,
            'confidence' => $confidence,
            'reason' => $reason,
        ];
    }
}
