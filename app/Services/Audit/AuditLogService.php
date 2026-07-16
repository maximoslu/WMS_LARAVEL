<?php

namespace App\Services\Audit;

use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class AuditLogService
{
    private const SENSITIVE_KEY_PATTERN = '/password|passwd|secret|token|authorization|cookie|api[_-]?key/i';

    public function correlationId(?string $correlationId = null): string
    {
        return Str::isUuid((string) $correlationId) ? (string) $correlationId : (string) Str::uuid();
    }

    /**
     * @param  array<string, mixed>  $oldValues
     * @param  array<string, mixed>  $newValues
     * @param  array<string, mixed>  $metadata
     */
    public function record(
        string $event,
        string $module,
        string $description,
        ?Model $auditable = null,
        ?Model $subject = null,
        ?User $user = null,
        ?int $clientId = null,
        array $oldValues = [],
        array $newValues = [],
        array $metadata = [],
        ?string $correlationId = null,
        string $source = 'web',
        string $severity = 'info',
        ?Request $request = null,
    ): AuditLog {
        $request ??= app()->bound('request') ? request() : null;
        $user ??= $request?->user();

        return AuditLog::query()->create([
            'uuid' => (string) Str::uuid(),
            'client_id' => $clientId ?? $this->resolveClientId($auditable, $subject, $user),
            'user_id' => $user?->id,
            'user_name' => $user?->name,
            'user_role' => $user?->role?->slug,
            'event' => $event,
            'module' => $module,
            'source' => $source,
            'severity' => $severity,
            'auditable_type' => $auditable?->getMorphClass(),
            'auditable_id' => $auditable?->getKey(),
            'subject_type' => $subject?->getMorphClass(),
            'subject_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $oldValues === [] ? null : $this->sanitize($oldValues),
            'new_values' => $newValues === [] ? null : $this->sanitize($newValues),
            'metadata' => $metadata === [] ? null : $this->sanitize($metadata),
            'route' => $request?->route()?->getName(),
            'method' => $request?->method(),
            'correlation_id' => $this->correlationId($correlationId),
            'ip_address' => $this->anonymizeIp($request?->ip()),
            'user_agent' => Str::limit((string) $request?->userAgent(), 255, ''),
            'occurred_at' => now(),
            'created_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $values */
    public function sanitize(array $values): array
    {
        $clean = [];

        foreach ($values as $key => $value) {
            if (preg_match(self::SENSITIVE_KEY_PATTERN, (string) $key) === 1) {
                continue;
            }

            if (is_array($value)) {
                $clean[$key] = $this->sanitize($value);
            } elseif (is_scalar($value) || $value === null) {
                $clean[$key] = is_string($value) ? Str::limit($value, 2000, '...') : $value;
            }
        }

        return $clean;
    }

    private function resolveClientId(?Model $auditable, ?Model $subject, ?User $user): ?int
    {
        foreach ([$auditable, $subject, $user] as $model) {
            $clientId = $model?->getAttribute('client_id');

            if ($clientId !== null) {
                return (int) $clientId;
            }
        }

        return null;
    }

    private function anonymizeIp(?string $ip): ?string
    {
        if (! filled($ip)) {
            return null;
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            $parts = explode('.', $ip);
            $parts[3] = '0';

            return implode('.', $parts);
        }

        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $parts = array_slice(explode(':', $ip), 0, 4);

            return implode(':', $parts).'::';
        }

        return null;
    }
}
