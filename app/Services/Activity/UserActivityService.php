<?php

namespace App\Services\Activity;

use App\Models\User;
use App\Models\UserActivitySession;
use App\Models\UserSectionMetric;
use App\Services\Audit\AuditLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class UserActivityService
{
    public const HEARTBEAT_SECONDS = 60;

    public const MAX_COUNTABLE_INTERVAL = 90;

    public const INACTIVITY_TIMEOUT_SECONDS = 180;

    public function __construct(private readonly AuditLogService $audit) {}

    public function startSession(Request $request, User $user): UserActivitySession
    {
        $sessionHash = $this->sessionHash($request);

        $activity = UserActivitySession::query()->create([
            'uuid' => (string) Str::uuid(),
            'user_id' => $user->id,
            'client_id' => $user->client_id,
            'user_name' => $user->name,
            'user_role' => $user->role?->slug,
            'session_hash' => $sessionHash,
            'started_at' => now(),
            'last_seen_at' => now(),
            'active_seconds' => 0,
            'ip_address' => $this->anonymizeIp($request->ip()),
            'user_agent' => Str::limit((string) $request->userAgent(), 255, ''),
        ]);

        $this->audit->record(
            event: 'login',
            module: 'users',
            description: 'Inicio de sesion correcto.',
            auditable: $user,
            user: $user,
            request: $request,
        );

        return $activity;
    }

    public function closeSession(Request $request, User $user, string $reason = 'logout'): void
    {
        $activity = $this->currentSessionQuery($request, $user)->first();

        if (! $activity instanceof UserActivitySession) {
            $activity = UserActivitySession::query()
                ->where('user_id', $user->id)
                ->whereNull('ended_at')
                ->where('ip_address', $this->anonymizeIp($request->ip()))
                ->where('user_agent', Str::limit((string) $request->userAgent(), 255, ''))
                ->latest('id')
                ->first();
        }

        if ($activity instanceof UserActivitySession && $activity->ended_at === null) {
            $activity->forceFill([
                'last_seen_at' => now(),
                'ended_at' => now(),
                'closure_reason' => $reason,
            ])->save();
        }

        $this->audit->record(
            event: 'logout',
            module: 'users',
            description: 'Cierre de sesion.',
            auditable: $user,
            user: $user,
            request: $request,
        );
    }

    public function recordVisit(Request $request, User $user): void
    {
        $routeName = $request->route()?->getName();

        if (! $this->isTrackableRequest($request, $routeName)) {
            return;
        }

        $section = $this->normalizeSection((string) $routeName);
        $metricDate = today();
        $metric = UserSectionMetric::query()->firstOrCreate(
            [
                'user_id' => $user->id,
                'metric_date' => $metricDate,
                'section' => $section,
            ],
            [
                'client_id' => $user->client_id,
                'visits' => 0,
                'active_seconds' => 0,
            ],
        );

        $metric->forceFill([
            'client_id' => $user->client_id,
            'visits' => (int) $metric->visits + 1,
            'last_seen_at' => now(),
        ])->save();
    }

    /** @return array{counted_seconds: int, active_seconds: int} */
    public function heartbeat(Request $request, User $user, string $routeName, bool $visible): array
    {
        return DB::transaction(function () use ($request, $user, $routeName, $visible): array {
            $activity = $this->currentSessionQuery($request, $user, lock: true)->first();

            if (! $activity instanceof UserActivitySession || $activity->ended_at !== null) {
                $activity = $this->startSession($request, $user);
            }

            $elapsed = max(0, (int) $activity->last_seen_at?->diffInSeconds(now()));
            $counted = $visible && $elapsed <= self::INACTIVITY_TIMEOUT_SECONDS
                ? min($elapsed, self::MAX_COUNTABLE_INTERVAL)
                : 0;

            $activity->forceFill([
                'last_seen_at' => now(),
                'active_seconds' => (int) $activity->active_seconds + $counted,
            ])->save();

            if ($visible) {
                $section = $this->normalizeSection($routeName);
                $metricDate = today();
                $metric = UserSectionMetric::query()
                    ->where('user_id', $user->id)
                    ->where('metric_date', $metricDate)
                    ->where('section', $section)
                    ->lockForUpdate()
                    ->first();

                if (! $metric instanceof UserSectionMetric) {
                    $metric = UserSectionMetric::query()->create([
                        'user_id' => $user->id,
                        'client_id' => $user->client_id,
                        'metric_date' => $metricDate,
                        'section' => $section,
                        'visits' => 0,
                        'active_seconds' => 0,
                    ]);
                }

                $metric->forceFill([
                    'client_id' => $user->client_id,
                    'active_seconds' => (int) $metric->active_seconds + $counted,
                    'last_seen_at' => now(),
                ])->save();
            }

            return [
                'counted_seconds' => $counted,
                'active_seconds' => (int) $activity->active_seconds,
            ];
        });
    }

    public function normalizeSection(string $routeName): string
    {
        $routeName = Str::lower(trim($routeName));
        $prefix = Str::before($routeName, '.');

        return match ($prefix) {
            'client-goods-receipts' => 'albaranes-cliente',
            'delivery-notes' => 'albaranes-gestion',
            'goods-receipts' => 'entradas',
            'dispatches' => 'salidas',
            'merchandise-requests' => 'pedidos',
            'traceability' => 'trazabilidad',
            default => Str::limit($prefix !== '' ? $prefix : 'otros', 100, ''),
        };
    }

    public function isTrackableRequest(Request $request, ?string $routeName): bool
    {
        if (! $request->isMethod('GET') || $request->ajax() || $request->expectsJson() || ! filled($routeName)) {
            return false;
        }

        return ! Str::is([
            'traceability.activity.heartbeat',
            'signed.*',
            'ajax.*',
            'password.*',
        ], (string) $routeName);
    }

    private function currentSessionQuery(Request $request, User $user, bool $lock = false)
    {
        $query = UserActivitySession::query()
            ->where('user_id', $user->id)
            ->where('session_hash', $this->sessionHash($request))
            ->whereNull('ended_at')
            ->latest('id');

        return $lock ? $query->lockForUpdate() : $query;
    }

    private function sessionHash(Request $request): string
    {
        return hash('sha256', $request->session()->getId());
    }

    private function anonymizeIp(?string $ip): ?string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return preg_replace('/\.\d+$/', '.0', (string) $ip);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return implode(':', array_slice(explode(':', (string) $ip), 0, 4)).'::';
        }

        return null;
    }
}
