<?php

namespace App\Http\Controllers\Traceability;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traceability\Concerns\AuthorizesTraceability;
use App\Models\Client;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockAlertEvent;
use App\Models\StockAlertRule;
use App\Services\Audit\AuditLogService;
use App\Services\Traceability\StockForecastService;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class StockAlertController extends Controller
{
    use AuthorizesTraceability;

    public function index(Request $request, StockForecastService $forecast): View
    {
        $this->authorizeTraceabilityRead($request);
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'status' => ['nullable', Rule::in(['active', 'resolved', 'all'])],
        ]);
        $clientId = isset($filters['client_id']) ? (int) $filters['client_id'] : null;
        $status = $filters['status'] ?? 'active';
        $events = StockAlertEvent::query()
            ->with(['client', 'item', 'rule'])
            ->when($clientId !== null, fn (Builder $query) => $query->where('client_id', $clientId))
            ->when($status === 'active', fn (Builder $query) => $query->whereNull('resolved_at'))
            ->when($status === 'resolved', fn (Builder $query) => $query->whereNotNull('resolved_at'))
            ->latest('triggered_at')
            ->paginate(30, ['*'], 'events_page')
            ->withQueryString();
        $rules = $clientId !== null
            ? StockAlertRule::query()->with(['client', 'item'])->where('client_id', $clientId)->orderBy('item_id')->paginate(30, ['*'], 'rules_page')->withQueryString()
            : StockAlertRule::query()->whereRaw('1 = 0')->paginate(30, ['*'], 'rules_page')->withQueryString();
        $forecasts = $rules->getCollection()->mapWithKeys(fn (StockAlertRule $rule): array => [
            $rule->id => $forecast->forecast(
                $rule->item,
                $rule->include_blocked_stock,
                $rule->include_obsolete_stock,
                (int) $rule->lead_time_days,
                (int) $rule->safety_stock_units,
            ),
        ]);

        return view('traceability.alerts.index', [
            'events' => $events,
            'rules' => $rules,
            'forecasts' => $forecasts,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'items' => $clientId !== null ? Item::query()->where('client_id', $clientId)->where('active', true)->orderBy('sku')->get() : collect(),
            'filters' => ['client_id' => $clientId, 'status' => $status],
            'canManage' => $request->user()->canAccessRole(Role::ADMINISTRACION),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function store(Request $request, AuditLogService $audit): RedirectResponse
    {
        $this->authorizeTraceabilityAdmin($request);
        $data = $this->validatedRule($request);
        $this->assertItemBelongsToClient((int) $data['client_id'], (int) $data['item_id']);
        $data['created_by'] = $request->user()->id;
        $data['updated_by'] = $request->user()->id;
        $rule = DB::transaction(function () use ($data, $request, $audit): StockAlertRule {
            $rule = StockAlertRule::query()->create($data);
            $audit->record(
                event: 'stock_alert_rule_created',
                module: 'stock_alerts',
                description: 'Regla de alerta de stock creada.',
                auditable: $rule,
                subject: $rule->item,
                user: $request->user(),
                clientId: $rule->client_id,
                newValues: $rule->toArray(),
            );

            return $rule;
        });

        return to_route('traceability.alerts.index', ['client_id' => $rule->client_id])->with('status', 'Regla de alerta creada.');
    }

    public function update(Request $request, StockAlertRule $stockAlertRule, AuditLogService $audit): RedirectResponse
    {
        $this->authorizeTraceabilityAdmin($request);
        $data = $this->validatedRule($request, $stockAlertRule);
        $this->assertItemBelongsToClient((int) $data['client_id'], (int) $data['item_id']);
        $old = $stockAlertRule->toArray();
        $data['updated_by'] = $request->user()->id;
        DB::transaction(function () use ($stockAlertRule, $data, $old, $request, $audit): void {
            $identityChanged = (int) $old['client_id'] !== (int) $data['client_id']
                || (int) $old['item_id'] !== (int) $data['item_id'];
            $stockAlertRule->update($data);
            if (! $stockAlertRule->active || $identityChanged) {
                StockAlertEvent::query()
                    ->where('stock_alert_rule_id', $stockAlertRule->id)
                    ->whereNull('resolved_at')
                    ->update([
                        'status' => StockAlertEvent::STATUS_RESOLVED,
                        'resolved_by' => $request->user()->id,
                        'resolved_at' => now(),
                        'updated_at' => now(),
                    ]);
            }
            $audit->record(
                event: $old['active'] && ! $stockAlertRule->active ? 'stock_alert_rule_deactivated' : 'stock_alert_rule_updated',
                module: 'stock_alerts',
                description: 'Regla de alerta de stock actualizada.',
                auditable: $stockAlertRule,
                subject: $stockAlertRule->item,
                user: $request->user(),
                clientId: $stockAlertRule->client_id,
                oldValues: $old,
                newValues: $stockAlertRule->fresh()->toArray(),
            );
        });

        return to_route('traceability.alerts.index', ['client_id' => $stockAlertRule->client_id])->with('status', 'Regla de alerta actualizada.');
    }

    public function acknowledge(Request $request, StockAlertEvent $stockAlertEvent, AuditLogService $audit): RedirectResponse
    {
        return $this->transitionEvent($request, $stockAlertEvent, $audit, StockAlertEvent::STATUS_ACKNOWLEDGED);
    }

    public function silence(Request $request, StockAlertEvent $stockAlertEvent, AuditLogService $audit): RedirectResponse
    {
        $request->validate(['hours' => ['required', 'integer', 'min:1', 'max:720']]);

        return $this->transitionEvent($request, $stockAlertEvent, $audit, StockAlertEvent::STATUS_SILENCED, now()->addHours((int) $request->integer('hours')));
    }

    public function resolve(Request $request, StockAlertEvent $stockAlertEvent, AuditLogService $audit): RedirectResponse
    {
        return $this->transitionEvent($request, $stockAlertEvent, $audit, StockAlertEvent::STATUS_RESOLVED);
    }

    private function transitionEvent(Request $request, StockAlertEvent $event, AuditLogService $audit, string $status, $silencedUntil = null): RedirectResponse
    {
        $this->authorizeTraceabilityAdmin($request);
        abort_if($event->resolved_at !== null, 409, 'La alerta ya esta resuelta.');
        DB::transaction(function () use ($event, $request, $audit, $status, $silencedUntil): void {
            $locked = StockAlertEvent::query()->whereKey($event->id)->lockForUpdate()->firstOrFail();
            abort_if($locked->resolved_at !== null, 409, 'La alerta ya esta resuelta.');
            $values = ['status' => $status];
            if ($status === StockAlertEvent::STATUS_ACKNOWLEDGED) {
                $values += ['acknowledged_by' => $request->user()->id, 'acknowledged_at' => now()];
            } elseif ($status === StockAlertEvent::STATUS_SILENCED) {
                $values += ['silenced_until' => $silencedUntil];
            } elseif ($status === StockAlertEvent::STATUS_RESOLVED) {
                $values += ['resolved_by' => $request->user()->id, 'resolved_at' => now()];
            }
            $locked->update($values);
            $audit->record(
                event: 'stock_alert_'.$status,
                module: 'stock_alerts',
                description: 'Estado de alerta de stock actualizado a '.$status.'.',
                auditable: $locked,
                user: $request->user(),
                clientId: $locked->client_id,
                newValues: $values,
            );
        });

        return back()->with('status', 'Estado de la alerta actualizado.');
    }

    /** @return array<string, mixed> */
    private function validatedRule(Request $request, ?StockAlertRule $rule = null): array
    {
        $data = $request->validate([
            'client_id' => ['required', 'integer', 'exists:clients,id'],
            'item_id' => [
                'required',
                'integer',
                'exists:items,id',
                Rule::unique('stock_alert_rules', 'item_id')->where('client_id', $request->integer('client_id'))->ignore($rule?->id),
            ],
            'active' => ['nullable', 'boolean'],
            'minimum_units' => ['nullable', 'integer', 'min:0'],
            'minimum_pallets' => ['nullable', 'integer', 'min:0'],
            'minimum_coverage_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'exhaustion_horizon_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'safety_stock_units' => ['nullable', 'integer', 'min:0'],
            'lead_time_days' => ['nullable', 'integer', 'min:0', 'max:3650'],
            'include_blocked_stock' => ['nullable', 'boolean'],
            'include_obsolete_stock' => ['nullable', 'boolean'],
            'severity' => ['required', Rule::in(['warning', 'critical'])],
            'cooldown_minutes' => ['required', 'integer', 'min:1', 'max:525600'],
        ]);
        abort_if(collect(['minimum_units', 'minimum_pallets', 'minimum_coverage_days', 'exhaustion_horizon_days'])
            ->every(fn (string $key): bool => ($data[$key] ?? null) === null), 422, 'Configura al menos un umbral.');

        return [
            ...$data,
            'active' => $request->boolean('active'),
            'include_blocked_stock' => $request->boolean('include_blocked_stock'),
            'include_obsolete_stock' => $request->boolean('include_obsolete_stock'),
            'safety_stock_units' => $data['safety_stock_units'] ?? 0,
            'lead_time_days' => $data['lead_time_days'] ?? 0,
        ];
    }

    private function assertItemBelongsToClient(int $clientId, int $itemId): void
    {
        abort_unless(Item::query()->whereKey($itemId)->where('client_id', $clientId)->exists(), 422, 'El articulo no pertenece al cliente.');
    }
}
