<?php

namespace App\Http\Controllers\Traceability;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traceability\Concerns\AuthorizesTraceability;
use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\Supplier;
use App\Services\Traceability\LotTraceabilityService;
use App\Support\Locations\LocationCode;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LotTraceabilityController extends Controller
{
    use AuthorizesTraceability;

    public function index(Request $request, LotTraceabilityService $traceability): View
    {
        $this->authorizeTraceabilityRead($request);
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'lot' => ['nullable', 'string', 'max:100'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'location_id' => ['nullable', 'integer', 'exists:locations,id'],
            'status' => ['nullable', 'in:all,active,historical,available,blocked,obsolete'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $clientId = $filters['client_id'] ?? null;
        $lot = trim((string) ($filters['lot'] ?? ''));
        $trace = $clientId !== null && $lot !== ''
            ? $traceability->trace(
                (int) $clientId,
                $lot,
                isset($filters['item_id']) ? (int) $filters['item_id'] : null,
                $filters,
            )
            : null;

        return view('traceability.lots.index', [
            'trace' => $trace,
            'clients' => Client::query()->orderBy('name')->get(),
            'items' => $clientId !== null ? Item::query()->where('client_id', $clientId)->orderBy('sku')->get() : collect(),
            'suppliers' => $clientId !== null ? Supplier::query()
                ->where(fn (Builder $query) => $query->whereNull('client_id')->orWhere('client_id', $clientId))
                ->orderBy('name')
                ->get() : collect(),
            'locations' => $clientId !== null ? LocationCode::applyNaturalOrder(Location::query()
                ->with('warehouse')
                ->whereHas('warehouse', fn (Builder $query) => $query->whereNull('client_id')->orWhere('client_id', $clientId)))
                ->limit(500)
                ->get() : collect(),
            'filters' => ['status' => 'all', ...$filters],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }
}
