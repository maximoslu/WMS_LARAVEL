<?php

namespace App\Services\Traceability;

use App\Jobs\SendStockAlertEmailJob;
use App\Models\ClientStockAlertEmailRecipient;
use App\Models\StockAlertEvent;
use App\Models\StockAlertRule;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class StockAlertEvaluationService
{
    public function __construct(
        private readonly StockForecastService $forecast,
        private readonly AuditLogService $audit,
    ) {}

    /**
     * @return array{evaluated: int, triggered: int, resolved: int, unchanged: int, rows: list<array<string, mixed>>}
     */
    public function evaluate(?int $clientId = null, ?int $itemId = null, bool $apply = false): array
    {
        $rules = StockAlertRule::query()
            ->with(['client', 'item'])
            ->where('active', true)
            ->when($clientId !== null, fn (Builder $query) => $query->where('client_id', $clientId))
            ->when($itemId !== null, fn (Builder $query) => $query->where('item_id', $itemId))
            ->orderBy('client_id')
            ->orderBy('item_id')
            ->get();
        $summary = ['evaluated' => 0, 'triggered' => 0, 'resolved' => 0, 'unchanged' => 0, 'rows' => []];

        foreach ($rules as $rule) {
            $result = $this->evaluateRule($rule, $apply);
            $summary['evaluated']++;
            $summary[$result['action']]++;
            $summary['rows'][] = $result;
        }

        return $summary;
    }

    /** @return array<string, mixed> */
    private function evaluateRule(StockAlertRule $rule, bool $apply): array
    {
        $forecast = $this->forecast->forecast(
            $rule->item,
            $rule->include_blocked_stock,
            $rule->include_obsolete_stock,
            (int) $rule->lead_time_days,
            (int) $rule->safety_stock_units,
        );
        $reasons = $this->failedCriteria($rule, $forecast);
        $severity = $this->severity($rule, $forecast, $reasons);
        $action = 'unchanged';

        if ($apply) {
            $action = DB::transaction(function () use ($rule, $forecast, $reasons, $severity): string {
                $lockedRule = StockAlertRule::query()->whereKey($rule->id)->lockForUpdate()->firstOrFail();
                $activeEvent = StockAlertEvent::query()
                    ->where('stock_alert_rule_id', $lockedRule->id)
                    ->whereNull('resolved_at')
                    ->latest('triggered_at')
                    ->lockForUpdate()
                    ->first();

                $lockedRule->forceFill(['last_evaluated_at' => now()])->save();

                if ($reasons === []) {
                    if ($activeEvent instanceof StockAlertEvent) {
                        $activeEvent->forceFill([
                            'status' => StockAlertEvent::STATUS_RESOLVED,
                            'resolved_at' => now(),
                            'notification_status' => $activeEvent->notification_status,
                        ])->save();
                        $this->audit->record(
                            event: 'stock_alert_resolved',
                            module: 'stock_alerts',
                            description: 'Alerta de stock resuelta automaticamente al recuperar los umbrales.',
                            auditable: $activeEvent,
                            clientId: $lockedRule->client_id,
                            source: 'scheduler',
                        );

                        return 'resolved';
                    }

                    return 'unchanged';
                }

                if (! $this->shouldCreateEvent($lockedRule, $activeEvent, $severity, $forecast)) {
                    return 'unchanged';
                }

                if ($activeEvent instanceof StockAlertEvent) {
                    $activeEvent->forceFill([
                        'status' => StockAlertEvent::STATUS_RESOLVED,
                        'resolved_at' => now(),
                    ])->save();
                }

                $recipients = ClientStockAlertEmailRecipient::query()
                    ->where('client_id', $lockedRule->client_id)
                    ->where('active', true)
                    ->pluck('email')
                    ->map(fn (string $email): string => Str::lower(trim($email)))
                    ->unique()
                    ->values()
                    ->all();
                $event = StockAlertEvent::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'stock_alert_rule_id' => $lockedRule->id,
                    'client_id' => $lockedRule->client_id,
                    'item_id' => $lockedRule->item_id,
                    'severity' => $severity,
                    'status' => $severity,
                    'reason' => implode(' ', $reasons),
                    'observed_units' => $forecast['available_units'],
                    'threshold_units' => $lockedRule->minimum_units,
                    'observed_pallets' => $forecast['available_pallets'],
                    'threshold_pallets' => $lockedRule->minimum_pallets,
                    'coverage_days' => $forecast['coverage_days'],
                    'estimated_exhaustion_date' => $forecast['estimated_exhaustion_date'],
                    'criteria' => $forecast,
                    'recipients' => $recipients,
                    'notification_status' => $recipients === [] ? 'skipped' : 'queued',
                    'triggered_at' => now(),
                ]);
                $lockedRule->forceFill(['last_alerted_at' => now()])->save();
                $this->audit->record(
                    event: 'stock_alert_triggered',
                    module: 'stock_alerts',
                    description: $event->reason,
                    auditable: $event,
                    subject: $lockedRule->item,
                    clientId: $lockedRule->client_id,
                    newValues: ['severity' => $severity, 'forecast' => $forecast],
                    source: 'scheduler',
                    severity: $severity,
                );

                if ($recipients !== []) {
                    DB::afterCommit(fn () => SendStockAlertEmailJob::dispatch($event->id));
                }

                return 'triggered';
            });
        } elseif ($reasons !== []) {
            $action = 'triggered';
        }

        return [
            'rule_id' => $rule->id,
            'client_id' => $rule->client_id,
            'client' => $rule->client?->name,
            'item_id' => $rule->item_id,
            'sku' => $rule->item?->sku,
            'severity' => $reasons === [] ? 'normal' : $severity,
            'reason' => $reasons === [] ? 'Dentro de umbrales.' : implode(' ', $reasons),
            'forecast' => $forecast,
            'action' => $action,
        ];
    }

    /** @return list<string> */
    private function failedCriteria(StockAlertRule $rule, array $forecast): array
    {
        $reasons = [];

        if ($rule->minimum_units !== null && $forecast['available_units'] < (int) $rule->minimum_units) {
            $reasons[] = "Stock {$forecast['available_units']} uds por debajo de {$rule->minimum_units} uds.";
        }

        if ($rule->minimum_pallets !== null && $forecast['available_pallets'] < (int) $rule->minimum_pallets) {
            $reasons[] = "Stock {$forecast['available_pallets']} pallets por debajo de {$rule->minimum_pallets}.";
        }

        if ($rule->minimum_coverage_days !== null
            && $forecast['coverage_days'] !== null
            && $forecast['coverage_days'] < (int) $rule->minimum_coverage_days) {
            $reasons[] = "Cobertura {$forecast['coverage_days']} dias por debajo de {$rule->minimum_coverage_days}.";
        }

        if ($rule->exhaustion_horizon_days !== null
            && $forecast['coverage_days'] !== null
            && $forecast['coverage_days'] <= (int) $rule->exhaustion_horizon_days) {
            $reasons[] = "Agotamiento estimado dentro de {$rule->exhaustion_horizon_days} dias.";
        }

        return $reasons;
    }

    private function severity(StockAlertRule $rule, array $forecast, array $reasons): string
    {
        if ($reasons === []) {
            return 'normal';
        }

        if ($rule->severity === 'critical' || $forecast['available_units'] <= 0) {
            return StockAlertEvent::STATUS_CRITICAL;
        }

        if ($rule->minimum_units !== null && $forecast['available_units'] <= ((int) $rule->minimum_units * 0.5)) {
            return StockAlertEvent::STATUS_CRITICAL;
        }

        return StockAlertEvent::STATUS_WARNING;
    }

    private function shouldCreateEvent(
        StockAlertRule $rule,
        ?StockAlertEvent $activeEvent,
        string $severity,
        array $forecast,
    ): bool {
        if (! $activeEvent instanceof StockAlertEvent) {
            return true;
        }

        if ($activeEvent->silenced_until?->isFuture()) {
            return false;
        }

        if ($activeEvent->status === StockAlertEvent::STATUS_SILENCED
            && $activeEvent->silenced_until?->isPast()
            && $severity === StockAlertEvent::STATUS_CRITICAL) {
            return true;
        }

        if ($activeEvent->severity !== $severity) {
            return true;
        }

        $cooldownElapsed = $rule->last_alerted_at === null
            || $rule->last_alerted_at->lte(now()->subMinutes((int) $rule->cooldown_minutes));
        $significantlyWorse = $activeEvent->observed_units !== null
            && $forecast['available_units'] <= ((int) $activeEvent->observed_units * 0.8);

        return $cooldownElapsed && $significantlyWorse;
    }
}
