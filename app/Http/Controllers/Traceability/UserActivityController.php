<?php

namespace App\Http\Controllers\Traceability;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traceability\Concerns\AuthorizesTraceability;
use App\Models\AuditLog;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use App\Models\UserActivitySession;
use App\Models\UserSectionMetric;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UserActivityController extends Controller
{
    use AuthorizesTraceability;

    public function index(Request $request): View
    {
        $this->authorizeTraceabilityAdmin($request);
        $filters = $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'role' => ['nullable', 'string', 'max:50'],
            'section' => ['nullable', 'string', 'max:100'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $from = $filters['date_from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['date_to'] ?? now()->toDateString();
        $sessions = UserActivitySession::query()
            ->when(isset($filters['user_id']), fn (Builder $query) => $query->where('user_id', $filters['user_id']))
            ->when(isset($filters['client_id']), fn (Builder $query) => $query->where('client_id', $filters['client_id']))
            ->when(filled($filters['role'] ?? null), fn (Builder $query) => $query->where('user_role', $filters['role']))
            ->whereBetween('started_at', [$from.' 00:00:00', $to.' 23:59:59'])
            ->latest('started_at')
            ->paginate(30)
            ->withQueryString();
        $selectedUser = isset($filters['user_id']) ? User::query()->with(['role', 'client'])->find($filters['user_id']) : null;
        $metrics = $selectedUser instanceof User
            ? UserSectionMetric::query()
                ->where('user_id', $selectedUser->id)
                ->whereBetween('metric_date', [$from, $to])
                ->when(filled($filters['section'] ?? null), fn (Builder $query) => $query->where('section', $filters['section']))
                ->select(['section'])
                ->selectRaw('SUM(visits) as visits, SUM(active_seconds) as active_seconds, MAX(last_seen_at) as last_seen_at')
                ->groupBy('section')
                ->orderByDesc('visits')
                ->get()
            : collect();
        $actions = $selectedUser instanceof User
            ? AuditLog::query()->where('user_id', $selectedUser->id)->whereBetween('occurred_at', [$from.' 00:00:00', $to.' 23:59:59'])->latest('occurred_at')->limit(25)->get()
            : collect();

        return view('traceability.activity.index', [
            'sessions' => $sessions,
            'selectedUser' => $selectedUser,
            'metrics' => $metrics,
            'actions' => $actions,
            'users' => User::query()->with(['role', 'client'])->orderBy('name')->get(),
            'clients' => Client::query()->orderBy('name')->get(),
            'roles' => Role::defaults(),
            'filters' => [...$filters, 'date_from' => $from, 'date_to' => $to],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }
}
