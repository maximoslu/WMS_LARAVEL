<?php

namespace App\Services\Stock;

use App\Models\Client;
use App\Models\StockInventoryLocation;
use App\Models\StockInventorySession;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use App\Support\Locations\LocationCode;
use App\Support\Stock\StockOverviewBuilder;
use App\Support\Warehouses\WarehouseCode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class StockInventorySessionService
{
    /** @var list<string> */
    private const SCOPE_FILTERS = [
        'client_id',
        'warehouse_id',
        'stock_category',
        'item_state',
        'batch_status',
        'location_state',
        'location_id',
        'location_ids',
        'location_from',
        'location_to',
        'stock_state',
    ];

    public function __construct(
        private readonly StockOverviewBuilder $overviewBuilder,
        private readonly StockInventoryMovementDetector $movementDetector,
        private readonly AuditLogService $audit,
    ) {}

    public function openForClient(?int $clientId): ?StockInventorySession
    {
        if ($clientId === null) {
            return null;
        }

        return StockInventorySession::query()
            ->with(['client', 'starter'])
            ->where('client_id', $clientId)
            ->where('status', StockInventorySession::STATUS_IN_PROGRESS)
            ->first();
    }

    /** @return Collection<int, StockInventorySession> */
    public function historyForClient(?int $clientId): Collection
    {
        if ($clientId === null) {
            return collect();
        }

        return StockInventorySession::query()
            ->with(['client', 'starter', 'completer'])
            ->where('client_id', $clientId)
            ->where('status', StockInventorySession::STATUS_COMPLETED)
            ->latest('completed_at')
            ->limit(20)
            ->get();
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $rows
     * @return Collection<int, array<string, mixed>>
     */
    public function previewScopes(Collection $rows): Collection
    {
        return $rows
            ->groupBy(fn (array $row): string => $this->scopeKey($row['location_id'] ?? null))
            ->map(function (Collection $scopeRows, string $scopeKey): array {
                $first = $scopeRows->first();

                return [
                    'scope_key' => $scopeKey,
                    'location_id' => $first['location_id'],
                    'warehouse_id' => $first['warehouse_id'],
                    'warehouse_code' => $first['warehouse_code'],
                    'warehouse_name' => $first['warehouse_name'],
                    'location_code' => $first['location_code'],
                    'location_label' => $first['location_label'],
                    'references' => $scopeRows->pluck('item_id')->filter()->unique()->count(),
                    'full_pallets' => (int) $scopeRows->sum('full_pallets'),
                    'peaks' => (int) $scopeRows->sum('peaks_count'),
                    'peak_units' => (int) $scopeRows->sum('peak_units'),
                    'total_units' => (int) $scopeRows->sum('quantity_units'),
                ];
            })
            ->sort(fn (array $left, array $right): int => $this->compareScopeRows($left, $right))
            ->values();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @param  list<string>  $selectedScopeKeys
     */
    public function start(User $user, array $filters, array $selectedScopeKeys = []): StockInventorySession
    {
        $inventory = $this->overviewBuilder->inventory($user, $filters);
        $client = $inventory['options']['client'];

        if (! $client instanceof Client) {
            throw ValidationException::withMessages([
                'client_id' => 'Selecciona un cliente para iniciar el inventario.',
            ]);
        }

        $scopes = $this->previewScopes($inventory['rows']);

        if ($selectedScopeKeys !== []) {
            $selected = collect($selectedScopeKeys)->map(fn (mixed $key): string => (string) $key)->unique();
            $scopes = $scopes->whereIn('scope_key', $selected)->values();
        }

        if ($scopes->isEmpty()) {
            throw ValidationException::withMessages([
                'scope_keys' => 'El alcance seleccionado no contiene ninguna ubicación inventariable.',
            ]);
        }

        $scopeFilters = collect($inventory['filters'])
            ->only(self::SCOPE_FILTERS)
            ->all();

        return DB::transaction(function () use ($client, $scopeFilters, $scopes, $user): StockInventorySession {
            Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();

            if (StockInventorySession::query()
                ->where('client_id', $client->id)
                ->where('status', StockInventorySession::STATUS_IN_PROGRESS)
                ->exists()) {
                throw ValidationException::withMessages([
                    'client_id' => 'Este cliente ya tiene un inventario en curso. Continúa el inventario existente.',
                ]);
            }

            $session = StockInventorySession::query()->create([
                'uuid' => (string) Str::uuid(),
                'client_id' => $client->id,
                'status' => StockInventorySession::STATUS_IN_PROGRESS,
                'open_slot' => true,
                'scope_filters' => $scopeFilters,
                'started_by' => $user->id,
                'started_at' => now(),
            ]);

            foreach ($scopes as $scope) {
                $session->locations()->create([
                    'client_id' => $client->id,
                    'location_id' => $scope['location_id'],
                    'warehouse_id' => $scope['warehouse_id'],
                    'scope_key' => $scope['scope_key'],
                    'warehouse_code' => $scope['warehouse_code'],
                    'warehouse_name' => $scope['warehouse_name'],
                    'location_code' => $scope['location_code'],
                    'location_label' => $scope['location_label'],
                    'check_state' => StockInventoryLocation::STATUS_PENDING,
                ]);
            }

            $this->audit->record(
                event: 'stock_inventory_started',
                module: 'stock_inventory',
                description: 'Inventario físico iniciado.',
                auditable: $session,
                user: $user,
                clientId: $client->id,
                newValues: [
                    'status' => StockInventorySession::STATUS_IN_PROGRESS,
                    'locations' => $scopes->count(),
                    'scope_filters' => $scopeFilters,
                ],
                severity: 'important',
            );

            return $session->fresh(['client', 'starter', 'locations']);
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array{locations: Collection<int, array<string, mixed>>, summary: array<string, int|float>, warehouses: Collection<int, array<string, mixed>>, filters: array<string, string>}
     */
    public function viewData(User $user, StockInventorySession $session, array $filters = []): array
    {
        $session->loadMissing('client');
        $session->setRelation('locations', $session->locations()->with('checker')->get());
        $inventory = $this->overviewBuilder->inventory($user, (array) $session->scope_filters);
        $currentRows = $inventory['rows']->groupBy(fn (array $row): string => $this->scopeKey($row['location_id'] ?? null));
        $latestMovements = $session->isInProgress()
            ? $this->movementDetector->latestRelevantMovementIds((int) $session->client_id, $session->locations)
            : [];
        $locations = $session->locations->map(function (StockInventoryLocation $location) use ($currentRows, $latestMovements, $session): array {
            $rows = $currentRows->get($location->scope_key, collect())->values();
            $status = $this->effectiveStatus($location, $latestMovements, ! $session->isInProgress());

            return [
                'model' => $location,
                'status' => $status,
                'rows' => $rows,
                'references' => $rows->pluck('item_id')->filter()->unique()->count(),
                'full_pallets' => (int) $rows->sum('full_pallets'),
                'peaks' => (int) $rows->sum('peaks_count'),
                'peak_units' => (int) $rows->sum('peak_units'),
                'total_units' => (int) $rows->sum('quantity_units'),
                'first_sku' => (string) ($rows->pluck('sku')->filter()->sort(SORT_NATURAL | SORT_FLAG_CASE)->first() ?? ''),
            ];
        });
        $summary = $this->summaryFromLocations($locations);
        $normalizedFilters = [
            'status' => in_array((string) ($filters['status'] ?? 'all'), ['all', 'pending', 'checked', 'needs_review'], true)
                ? (string) ($filters['status'] ?? 'all')
                : 'all',
            'warehouse_id' => filled($filters['warehouse_id'] ?? null) ? (string) $filters['warehouse_id'] : '',
            'order' => (string) ($filters['order'] ?? 'location') === 'reference' ? 'reference' : 'location',
        ];
        $warehouses = $session->locations
            ->filter(fn (StockInventoryLocation $location): bool => $location->warehouse_id !== null)
            ->map(fn (StockInventoryLocation $location): array => [
                'id' => (int) $location->warehouse_id,
                'code' => (string) $location->warehouse_code,
                'name' => (string) $location->warehouse_name,
            ])
            ->unique('id')
            ->sortBy(fn (array $warehouse): array => [
                ...WarehouseCode::naturalSortKey($warehouse['code']),
                mb_strtoupper($warehouse['name']),
            ])
            ->values();

        $visible = $locations
            ->when($normalizedFilters['status'] !== 'all', fn (Collection $rows) => $rows->where('status', $normalizedFilters['status']))
            ->when($normalizedFilters['warehouse_id'] !== '', fn (Collection $rows) => $rows->filter(
                fn (array $row): bool => (string) $row['model']->warehouse_id === $normalizedFilters['warehouse_id']
            ));

        if ($normalizedFilters['order'] === 'reference') {
            $visible = $visible->sort(function (array $left, array $right): int {
                $comparison = strnatcasecmp($left['first_sku'], $right['first_sku']);

                return $comparison !== 0 ? $comparison : $this->compareLocationModels($left['model'], $right['model']);
            });
        } else {
            $visible = $visible->sort(fn (array $left, array $right): int => $this->compareLocationModels($left['model'], $right['model']));
        }

        return [
            'locations' => $visible->values(),
            'summary' => $summary,
            'warehouses' => $warehouses,
            'filters' => $normalizedFilters,
        ];
    }

    public function check(
        User $user,
        StockInventorySession $session,
        StockInventoryLocation $inventoryLocation,
        ?string $notes = null,
    ): StockInventoryLocation {
        return DB::transaction(function () use ($user, $session, $inventoryLocation, $notes): StockInventoryLocation {
            $lockedSession = StockInventorySession::query()->lockForUpdate()->findOrFail($session->id);

            if (! $lockedSession->isInProgress()) {
                throw ValidationException::withMessages([
                    'inventory' => 'El inventario ya está finalizado y no admite nuevas comprobaciones.',
                ]);
            }

            $location = StockInventoryLocation::query()
                ->where('stock_inventory_session_id', $lockedSession->id)
                ->lockForUpdate()
                ->findOrFail($inventoryLocation->id);
            $wasChecked = $location->checked_at !== null;
            $previousCheckedAt = $location->checked_at;
            $watermark = $this->movementDetector->currentWatermark((int) $lockedSession->client_id);
            $snapshot = $this->snapshotForLocation($user, $lockedSession, $location);
            $checkedAt = now();

            $location->forceFill([
                'check_state' => StockInventoryLocation::STATUS_CHECKED,
                'checked_by' => $user->id,
                'checked_at' => $checkedAt,
                'checked_movement_id' => $watermark,
                'checked_snapshot' => $snapshot,
                'notes' => filled($notes) ? trim((string) $notes) : null,
            ])->save();

            $this->audit->record(
                event: $wasChecked ? 'stock_inventory_location_rechecked' : 'stock_inventory_location_checked',
                module: 'stock_inventory',
                description: $wasChecked
                    ? 'Ubicación de inventario comprobada de nuevo.'
                    : 'Ubicación de inventario comprobada.',
                auditable: $lockedSession,
                subject: $location,
                user: $user,
                clientId: $lockedSession->client_id,
                oldValues: ['checked_at' => $previousCheckedAt?->toIso8601String()],
                newValues: [
                    'scope_key' => $location->scope_key,
                    'checked_at' => $checkedAt->toIso8601String(),
                    'checked_movement_id' => $watermark,
                    'snapshot_lines' => count($snapshot),
                    'notes' => filled($notes) ? trim((string) $notes) : null,
                ],
            );

            return $location->fresh('checker');
        }, 3);
    }

    public function complete(User $user, StockInventorySession $session): StockInventorySession
    {
        return DB::transaction(function () use ($user, $session): StockInventorySession {
            $locked = StockInventorySession::query()->lockForUpdate()->findOrFail($session->id);

            if (! $locked->isInProgress()) {
                return $locked;
            }

            $locations = StockInventoryLocation::query()
                ->where('stock_inventory_session_id', $locked->id)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();
            $latestMovements = $this->movementDetector->latestRelevantMovementIds((int) $locked->client_id, $locations);
            $statuses = $locations->map(fn (StockInventoryLocation $location): string => $this->effectiveStatus($location, $latestMovements));

            if ($statuses->contains(fn (string $status): bool => $status !== StockInventoryLocation::STATUS_CHECKED)) {
                throw ValidationException::withMessages([
                    'inventory' => 'No se puede finalizar: todavía quedan ubicaciones pendientes o con movimientos posteriores.',
                ]);
            }

            StockInventoryLocation::query()
                ->where('stock_inventory_session_id', $locked->id)
                ->update(['finalized_status' => StockInventoryLocation::STATUS_CHECKED, 'updated_at' => now()]);

            $summary = [
                'total' => $locations->count(),
                'checked' => $locations->count(),
                'pending' => 0,
                'needs_review' => 0,
                'progress' => 100,
            ];
            $completedAt = now();
            $locked->forceFill([
                'status' => StockInventorySession::STATUS_COMPLETED,
                'open_slot' => null,
                'completed_by' => $user->id,
                'completed_at' => $completedAt,
                'final_summary' => $summary,
            ])->save();

            $this->audit->record(
                event: 'stock_inventory_completed',
                module: 'stock_inventory',
                description: 'Inventario físico finalizado.',
                auditable: $locked,
                user: $user,
                clientId: $locked->client_id,
                oldValues: ['status' => StockInventorySession::STATUS_IN_PROGRESS],
                newValues: [
                    'status' => StockInventorySession::STATUS_COMPLETED,
                    'completed_at' => $completedAt->toIso8601String(),
                    ...$summary,
                ],
                severity: 'important',
            );

            return $locked->fresh(['client', 'starter', 'completer']);
        }, 3);
    }

    /** @return array<string, int|float> */
    public function summary(StockInventorySession $session): array
    {
        $session->setRelation('locations', $session->locations()->get());

        if (! $session->isInProgress() && is_array($session->final_summary)) {
            return $session->final_summary;
        }

        $latest = $this->movementDetector->latestRelevantMovementIds((int) $session->client_id, $session->locations);
        $locations = $session->locations->map(fn (StockInventoryLocation $location): array => [
            'status' => $this->effectiveStatus($location, $latest),
        ]);

        return $this->summaryFromLocations($locations);
    }

    /** @return list<array<string, mixed>> */
    private function snapshotForLocation(User $user, StockInventorySession $session, StockInventoryLocation $location): array
    {
        $filters = (array) $session->scope_filters;
        unset($filters['location_ids'], $filters['location_from'], $filters['location_to']);

        if ($location->location_id !== null) {
            $filters['location_id'] = $location->location_id;
            $filters['location_state'] = 'all';
        } else {
            $filters['warehouse_id'] = null;
            $filters['location_id'] = null;
            $filters['location_state'] = 'without_location';
        }

        return $this->overviewBuilder->inventory($user, $filters)['rows']
            ->map(fn (array $row): array => [
                'stock_pallet_id' => $row['id'],
                'item_id' => $row['item_id'],
                'sku' => $row['sku'],
                'description' => $row['description'],
                'lot' => $row['lot_label'],
                'units_per_pallet' => (int) $row['units_per_pallet'],
                'full_pallets' => (int) $row['full_pallets'],
                'peaks' => (int) $row['peaks_count'],
                'peak_units' => (int) $row['peak_units'],
                'quantity_units' => (int) $row['quantity_units'],
            ])
            ->values()
            ->all();
    }

    /**
     * @param  array<string, int>  $latestMovements
     */
    private function effectiveStatus(StockInventoryLocation $location, array $latestMovements, bool $completed = false): string
    {
        if ($completed) {
            return $location->finalized_status ?? $location->check_state;
        }

        if ($location->checked_at === null) {
            return StockInventoryLocation::STATUS_PENDING;
        }

        if (($latestMovements[$location->scope_key] ?? 0) > (int) $location->checked_movement_id) {
            return StockInventoryLocation::STATUS_NEEDS_REVIEW;
        }

        return StockInventoryLocation::STATUS_CHECKED;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $locations
     * @return array{total: int, checked: int, pending: int, needs_review: int, progress: float}
     */
    private function summaryFromLocations(Collection $locations): array
    {
        $total = $locations->count();
        $checked = $locations->where('status', StockInventoryLocation::STATUS_CHECKED)->count();

        return [
            'total' => $total,
            'checked' => $checked,
            'pending' => $locations->where('status', StockInventoryLocation::STATUS_PENDING)->count(),
            'needs_review' => $locations->where('status', StockInventoryLocation::STATUS_NEEDS_REVIEW)->count(),
            'progress' => $total > 0 ? round(($checked / $total) * 100, 1) : 0,
        ];
    }

    private function scopeKey(mixed $locationId): string
    {
        return $locationId === null ? 'unlocated' : 'location:'.(int) $locationId;
    }

    /** @param array<string, mixed> $left
     * @param  array<string, mixed>  $right
     */
    private function compareScopeRows(array $left, array $right): int
    {
        $comparison = strnatcasecmp(
            WarehouseCode::normalize($left['warehouse_code'] ?: $left['warehouse_name']),
            WarehouseCode::normalize($right['warehouse_code'] ?: $right['warehouse_name']),
        );

        return $comparison !== 0
            ? $comparison
            : LocationCode::compareNaturally($left['location_label'], $right['location_label']);
    }

    private function compareLocationModels(StockInventoryLocation $left, StockInventoryLocation $right): int
    {
        return $this->compareScopeRows([
            'warehouse_code' => $left->warehouse_code,
            'warehouse_name' => $left->warehouse_name,
            'location_label' => $left->location_code ?? $left->location_label,
        ], [
            'warehouse_code' => $right->warehouse_code,
            'warehouse_name' => $right->warehouse_name,
            'location_label' => $right->location_code ?? $right->location_label,
        ]);
    }
}
