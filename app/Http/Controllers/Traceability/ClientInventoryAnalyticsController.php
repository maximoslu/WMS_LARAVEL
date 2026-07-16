<?php

namespace App\Http\Controllers\Traceability;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traceability\Concerns\AuthorizesTraceability;
use App\Models\Client;
use App\Models\Item;
use App\Models\StockPallet;
use App\Models\Warehouse;
use App\Services\Traceability\ClientInventoryAnalyticsService;
use App\Support\WmsNavigation;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class ClientInventoryAnalyticsController extends Controller
{
    use AuthorizesTraceability;

    public function index(Request $request, ClientInventoryAnalyticsService $analytics): View
    {
        $this->authorizeTraceabilityAdmin($request);
        $filters = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'item_id' => ['nullable', 'integer', 'exists:items,id'],
            'category' => ['nullable', Rule::in(array_keys(StockPallet::stockCategoryOptions()))],
            'lot' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $from = Carbon::parse($filters['date_from'] ?? now()->subDays(90)->toDateString());
        $to = Carbon::parse($filters['date_to'] ?? now()->toDateString());
        abort_if($from->diffInDays($to) > 366, 422, 'El periodo maximo es de 366 dias.');
        $clientId = $filters['client_id'] ?? null;
        $results = $clientId !== null
            ? $analytics->analyze(
                (int) $clientId,
                $from,
                $to,
                isset($filters['item_id']) ? (int) $filters['item_id'] : null,
                $filters['category'] ?? null,
                $filters['lot'] ?? null,
                isset($filters['warehouse_id']) ? (int) $filters['warehouse_id'] : null,
            )
            : null;

        return view('traceability.analytics.index', [
            'results' => $results,
            'clients' => Client::query()->orderBy('name')->get(),
            'items' => $clientId !== null ? Item::query()->where('client_id', $clientId)->orderBy('sku')->get() : collect(),
            'categories' => StockPallet::stockCategoryOptions(),
            'warehouses' => Warehouse::query()->where('active', true)->orderBy('name')->get(),
            'filters' => [...$filters, 'date_from' => $from->toDateString(), 'date_to' => $to->toDateString()],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }
}
