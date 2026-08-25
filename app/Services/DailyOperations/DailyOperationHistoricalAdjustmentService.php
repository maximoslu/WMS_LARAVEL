<?php

namespace App\Services\DailyOperations;

use App\Models\DailyOperationDay;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class DailyOperationHistoricalAdjustmentService
{
    public function __construct(
        private readonly DailyOperationTotalsService $totalsService,
        private readonly AuditLogService $audit,
    ) {}

    public function adjust(DailyOperationDay $day, int $openingPallets, string $reason, User $user): DailyOperationDay
    {
        $today = Carbon::now(config('app.timezone', 'UTC'))->startOfDay();
        $operationDate = $day->operation_date?->copy()->startOfDay();

        if ($operationDate === null || ! $operationDate->lt($today)) {
            throw ValidationException::withMessages(['opening_pallets' => 'Solo se puede ajustar la base de un día histórico ya transcurrido.']);
        }

        $reason = trim($reason);

        if ($reason === '') {
            throw ValidationException::withMessages(['reason' => 'El motivo del ajuste histórico es obligatorio.']);
        }

        return DB::transaction(function () use ($day, $openingPallets, $reason, $user): DailyOperationDay {
            $lockedDay = DailyOperationDay::query()->lockForUpdate()->findOrFail($day->id);
            $oldValues = [
                'opening_pallets' => $lockedDay->opening_pallets,
                'stored_pallets_today' => $lockedDay->stored_pallets_today,
                'moved_pallets_today' => $lockedDay->moved_pallets_today,
                'expected_pallets_tomorrow' => $lockedDay->expected_pallets_tomorrow,
            ];

            if ((int) $lockedDay->opening_pallets === $openingPallets) {
                if (! $lockedDay->is_historical_anchor) {
                    $lockedDay->is_historical_anchor = true;
                    $lockedDay->save();
                }

                return $lockedDay->fresh(['lines']);
            }

            $lockedDay->is_historical_anchor = true;
            $lockedDay->save();

            $adjustedDay = $this->totalsService->syncDay(
                $lockedDay->fresh(['lines']),
                $openingPallets,
                $lockedDay->notes,
                (int) $user->id,
            );

            $this->audit->record(
                event: 'daily_operation_historical_base_adjusted',
                module: 'daily_operations',
                description: 'Base de apertura histórica ajustada manualmente.',
                auditable: $adjustedDay,
                user: $user,
                clientId: $adjustedDay->client_id,
                oldValues: $oldValues,
                newValues: [
                    'opening_pallets' => $adjustedDay->opening_pallets,
                    'stored_pallets_today' => $adjustedDay->stored_pallets_today,
                    'moved_pallets_today' => $adjustedDay->moved_pallets_today,
                    'expected_pallets_tomorrow' => $adjustedDay->expected_pallets_tomorrow,
                ],
                metadata: ['reason' => $reason],
            );

            return $adjustedDay;
        });
    }
}
