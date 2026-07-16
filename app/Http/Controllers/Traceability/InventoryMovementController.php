<?php

namespace App\Http\Controllers\Traceability;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traceability\Concerns\AuthorizesTraceability;
use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Locations\LocationCode;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class InventoryMovementController extends Controller
{
    use AuthorizesTraceability;

    public function index(Request $request): View
    {
        $this->authorizeTraceabilityRead($request);
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'sku' => ['nullable', 'string', 'max:100'],
            'lot' => ['nullable', 'string', 'max:100'],
            'movement_type' => ['nullable', 'string', 'max:50'],
            'direction' => ['nullable', 'in:inbound,outbound'],
            'source_type' => ['nullable', 'string', 'max:255'],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'source_id' => ['nullable', 'integer'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $from = $filters['date_from'] ?? now()->subDays(30)->toDateString();
        $to = $filters['date_to'] ?? now()->toDateString();
        $clientId = $filters['client_id'] ?? null;
        $query = InventoryMovement::query()
            ->when($clientId !== null, fn (Builder $builder) => $builder->where('client_id', $clientId))
            ->when(isset($filters['item_id']), fn (Builder $builder) => $builder->where('item_id', $filters['item_id']))
            ->when(filled($filters['sku'] ?? null), fn (Builder $builder) => $builder->where('sku', 'like', '%'.$filters['sku'].'%'))
            ->when(filled($filters['lot'] ?? null), fn (Builder $builder) => $builder->where('lot', 'like', '%'.$filters['lot'].'%'))
            ->when(filled($filters['movement_type'] ?? null), fn (Builder $builder) => $builder->where('movement_type', $filters['movement_type']))
            ->when(($filters['direction'] ?? null) === 'inbound', fn (Builder $builder) => $builder->where('units_delta', '>', 0))
            ->when(($filters['direction'] ?? null) === 'outbound', fn (Builder $builder) => $builder->where('units_delta', '<', 0))
            ->when(filled($filters['source_type'] ?? null), fn (Builder $builder) => $builder->where('source_type', $filters['source_type']))
            ->when(isset($filters['user_id']), fn (Builder $builder) => $builder->where('user_id', $filters['user_id']))
            ->when(isset($filters['warehouse_id']), fn (Builder $builder) => $builder->where(fn (Builder $scope) => $scope
                ->where('warehouse_id', $filters['warehouse_id'])
                ->orWhere('from_warehouse_id', $filters['warehouse_id'])
                ->orWhere('to_warehouse_id', $filters['warehouse_id'])))
            ->when(isset($filters['location_id']), fn (Builder $builder) => $builder->where(fn (Builder $scope) => $scope
                ->where('location_id', $filters['location_id'])
                ->orWhere('from_location_id', $filters['location_id'])
                ->orWhere('to_location_id', $filters['location_id'])))
            ->when(isset($filters['source_id']), fn (Builder $builder) => $builder->where('source_id', $filters['source_id']))
            ->whereBetween('effective_at', [$from.' 00:00:00', $to.' 23:59:59']);
        $summary = $clientId !== null ? [
            'entries' => (int) (clone $query)->where('units_delta', '>', 0)->sum('units_delta'),
            'dispatches' => abs((int) (clone $query)->where('units_delta', '<', 0)->sum('units_delta')),
            'net' => (int) (clone $query)->sum('units_delta'),
            'count' => (clone $query)->count(),
        ] : ['entries' => 0, 'dispatches' => 0, 'net' => 0, 'count' => 0];
        $movements = $clientId !== null
            ? $query->latest('effective_at')->latest('id')->paginate(50)->withQueryString()
            : InventoryMovement::query()->whereRaw('1 = 0')->paginate(50)->withQueryString();

        return view('traceability.movements.index', [
            'movements' => $movements,
            'summary' => $summary,
            'clients' => Client::query()->orderBy('name')->get(),
            'items' => $clientId !== null ? Item::query()->where('client_id', $clientId)->orderBy('sku')->get() : collect(),
            'users' => User::query()->orderBy('name')->get(),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('name')->get(),
            'locations' => LocationCode::applyNaturalOrder(Location::query()
                ->where('active', true)
                ->when(isset($filters['warehouse_id']), fn (Builder $query) => $query->where('warehouse_id', $filters['warehouse_id'])))
                ->limit(500)
                ->get(),
            'types' => InventoryMovement::types(),
            'filters' => [...$filters, 'date_from' => $from, 'date_to' => $to],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }
}
