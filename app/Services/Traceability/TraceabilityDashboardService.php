<?php

namespace App\Services\Traceability;

use App\Models\AuditLog;
use App\Models\InventoryMovement;
use App\Models\StockAlertEvent;
use App\Models\StockPallet;
use App\Models\UserActivitySession;
use Illuminate\Support\Facades\DB;

class TraceabilityDashboardService
{
    /** @return array<string, mixed> */
    public function summary(int $periodDays = 30): array
    {
        $from = now()->subDays(max(1, min($periodDays, 90)))->startOfDay();
        $periodMovements = InventoryMovement::query()->where('effective_at', '>=', $from);
        $incompleteLots = StockPallet::query()
            ->where('active', true)
            ->whereNotNull('lot')
            ->where('lot', '!=', '')
            ->whereDoesntHave('goodsReceipt')
            ->select(['client_id', 'item_id', 'lot'])
            ->groupBy('client_id', 'item_id', 'lot');

        return [
            'period_days' => $periodDays,
            'movements_today' => InventoryMovement::query()->whereDate('effective_at', today())->count(),
            'entries_period' => (clone $periodMovements)->whereIn('movement_type', [
                InventoryMovement::RECEIPT,
                InventoryMovement::IMPORT,
                InventoryMovement::OPENING_BALANCE,
            ])->where('units_delta', '>', 0)->sum('units_delta'),
            'dispatches_period' => abs((int) (clone $periodMovements)
                ->where('movement_type', InventoryMovement::DISPATCH)
                ->where('units_delta', '<', 0)
                ->sum('units_delta')),
            'active_alerts' => StockAlertEvent::query()->whereNull('resolved_at')->count(),
            'incomplete_lots' => DB::query()->fromSub($incompleteLots, 'incomplete_lots')->count(),
            'recent_users' => UserActivitySession::query()
                ->whereNull('ended_at')
                ->where('last_seen_at', '>=', now()->subMinutes(15))
                ->distinct('user_id')
                ->count('user_id'),
            'latest_actions' => AuditLog::query()
                ->latest('occurred_at')
                ->limit(8)
                ->get(),
            'top_items' => InventoryMovement::query()
                ->where('effective_at', '>=', $from)
                ->where('units_delta', '!=', 0)
                ->select(['client_id', 'client_name', 'item_id', 'sku', 'description'])
                ->selectRaw('SUM(ABS(units_delta)) as moved_units')
                ->groupBy('client_id', 'client_name', 'item_id', 'sku', 'description')
                ->orderByDesc(DB::raw('SUM(ABS(units_delta))'))
                ->limit(8)
                ->get(),
        ];
    }
}
