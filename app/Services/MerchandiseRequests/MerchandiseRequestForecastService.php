<?php

namespace App\Services\MerchandiseRequests;

use App\Models\Client;
use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestLine;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class MerchandiseRequestForecastService
{
    public const SORT_UPDATED = 'updated';

    public const SORT_CLIENT = 'client';

    public const SORT_VOLUME = 'volume';

    public const SORT_CREATED = 'created';

    /**
     * @param  array<string, mixed>  $filters
     */
    public function draftQuery(array $filters): Builder
    {
        $query = MerchandiseRequest::query()
            ->select('merchandise_requests.*')
            ->with([
                'client',
                'requestedBy',
                'lines.item',
                'lines.stockPallet.location.warehouse',
            ])
            ->where('status', MerchandiseRequest::STATUS_DRAFT)
            ->when(($filters['client_id'] ?? null) !== null, fn (Builder $query) => $query->where('client_id', $filters['client_id']))
            ->when(($filters['creator_id'] ?? null) !== null, fn (Builder $query) => $query->where('requested_by', $filters['creator_id']))
            ->when(filled($filters['date_from'] ?? null), fn (Builder $query) => $query->whereDate('created_at', '>=', $filters['date_from']))
            ->when(filled($filters['date_to'] ?? null), fn (Builder $query) => $query->whereDate('created_at', '<=', $filters['date_to']))
            ->when(($filters['has_notes'] ?? false) === true, fn (Builder $query) => $query->whereNotNull('notes')->where('notes', '<>', ''))
            ->when(($filters['has_fill_truck'] ?? false) === true, fn (Builder $query) => $query->whereHas('lines', fn (Builder $lineQuery) => $lineQuery->where('fill_truck', true)));

        return match ($filters['sort'] ?? self::SORT_UPDATED) {
            self::SORT_CLIENT => $query->orderByRaw('(SELECT name FROM clients WHERE clients.id = merchandise_requests.client_id) ASC')->orderByDesc('updated_at'),
            self::SORT_VOLUME => $query->orderByRaw('(SELECT COALESCE(SUM(COALESCE(requested_pallets, 0) + COALESCE(requested_peaks, 0)), 0) FROM merchandise_request_lines WHERE merchandise_request_lines.merchandise_request_id = merchandise_requests.id) DESC')->orderByDesc('updated_at'),
            self::SORT_CREATED => $query->orderByDesc('created_at')->orderByDesc('id'),
            default => $query->orderByDesc('updated_at')->orderByDesc('id'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Builder $query): array
    {
        $ids = (clone $query)->reorder()->pluck('merchandise_requests.id');
        $lines = $ids->isEmpty()
            ? collect()
            : MerchandiseRequestLine::query()
                ->whereIn('merchandise_request_id', $ids)
                ->get([
                    'id',
                    'merchandise_request_id',
                    'line_type',
                    'requested_pallets',
                    'requested_peaks',
                    'requested_units',
                    'units_per_pallet',
                    'units_per_peak',
                    'fill_truck',
                ]);
        $totals = $this->totalsForLines($lines);
        $latestUpdated = (clone $query)->reorder()->max('merchandise_requests.updated_at');

        return [
            'drafts' => $ids->count(),
            'clients' => $ids->isEmpty()
                ? 0
                : MerchandiseRequest::query()->whereKey($ids)->distinct()->count('client_id'),
            'lines' => $lines->count(),
            ...$totals,
            'latest_updated' => $latestUpdated !== null ? Carbon::parse($latestUpdated) : null,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function totalsFor(MerchandiseRequest $merchandiseRequest): array
    {
        return [
            'lines' => $merchandiseRequest->lines->count(),
            ...$this->totalsForLines($merchandiseRequest->lines),
        ];
    }

    /**
     * @return array{clients: Collection<int, Client>, creators: Collection<int, User>}
     */
    public function filterOptions(): array
    {
        return [
            'clients' => Client::query()->orderBy('name')->get(['id', 'name']),
            'creators' => User::query()
                ->whereHas('requestedMerchandiseRequests', fn (Builder $query) => $query->where('status', MerchandiseRequest::STATUS_DRAFT))
                ->orderBy('name')
                ->get(['id', 'name']),
        ];
    }

    /**
     * @param  Collection<int, MerchandiseRequestLine>  $lines
     * @return array{pallets:int, peaks:int, units:int, fill_truck_lines:int}
     */
    private function totalsForLines(Collection $lines): array
    {
        return [
            'pallets' => (int) $lines->sum(fn (MerchandiseRequestLine $line): int => $line->requestedPalletsCount()),
            'peaks' => (int) $lines->sum(fn (MerchandiseRequestLine $line): int => $line->requestedPeaksCount()),
            'units' => (int) $lines->sum(fn (MerchandiseRequestLine $line): int => $line->requestedUnitsTotal()),
            'fill_truck_lines' => (int) $lines->filter(fn (MerchandiseRequestLine $line): bool => (bool) $line->fill_truck)->count(),
        ];
    }
}
