<?php

namespace App\Http\Controllers\Traceability;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traceability\Concerns\AuthorizesTraceability;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\User;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class BusinessAuditLogController extends Controller
{
    use AuthorizesTraceability;

    public function index(Request $request): View
    {
        $this->authorizeTraceabilityAdmin($request);
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'role' => ['nullable', 'string', 'max:50'],
            'module' => ['nullable', 'string', 'max:80'],
            'event' => ['nullable', 'string', 'max:100'],
            'entity_type' => ['nullable', 'string', 'max:255'],
            'entity_id' => ['nullable', 'integer'],
            'correlation_id' => ['nullable', 'uuid'],
            'severity' => ['nullable', 'string', 'max:20'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $from = $filters['date_from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['date_to'] ?? now()->toDateString();
        $logs = AuditLog::query()
            ->when(isset($filters['client_id']), fn (Builder $query) => $query->where('client_id', $filters['client_id']))
            ->when(isset($filters['user_id']), fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when(filled($filters['role'] ?? null), fn (Builder $query) => $query->where('user_role', $filters['role']))
            ->when(filled($filters['module'] ?? null), fn (Builder $query) => $query->where('module', $filters['module']))
            ->when(filled($filters['event'] ?? null), fn (Builder $query) => $query->where('event', $filters['event']))
            ->when(filled($filters['entity_type'] ?? null), fn (Builder $query) => $query->where('auditable_type', $filters['entity_type']))
            ->when(isset($filters['entity_id']), fn (Builder $query) => $query->where('auditable_id', $filters['entity_id']))
            ->when(filled($filters['correlation_id'] ?? null), fn (Builder $query) => $query->where('correlation_id', $filters['correlation_id']))
            ->when(filled($filters['severity'] ?? null), fn (Builder $query) => $query->where('severity', $filters['severity']))
            ->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->latest('occurred_at')
            ->paginate(50)
            ->withQueryString();

        return view('traceability.audit.index', [
            'logs' => $logs,
            'clients' => Client::query()->orderBy('name')->get(),
            'users' => User::query()->orderBy('name')->get(),
            'modules' => AuditLog::query()->distinct()->orderBy('module')->pluck('module'),
            'events' => AuditLog::query()->distinct()->orderBy('event')->pluck('event'),
            'filters' => [...$filters, 'date_from' => $from, 'date_to' => $to],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }
}
