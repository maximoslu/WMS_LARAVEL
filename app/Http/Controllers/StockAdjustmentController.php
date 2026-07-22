<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockAdjustmentRequest;
use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Services\Locations\LocationIntegrityService;
use App\Services\Stock\StockAdjustmentService;
use App\Support\Locations\LocationCode;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class StockAdjustmentController extends Controller
{
    public function __construct(
        private readonly StockAdjustmentService $adjustments,
        private readonly LocationIntegrityService $locations,
    ) {}

    public function create(Request $request): View
    {
        abort_unless($request->user()?->canAccessRole(Role::SUPERADMIN), 403);

        $filters = [
            'client_id' => $request->integer('client_id') > 0 ? $request->integer('client_id') : null,
            'item_id' => $request->integer('item_id') > 0 ? $request->integer('item_id') : null,
            'stock_pallet_id' => $request->integer('stock_pallet_id') > 0 ? $request->integer('stock_pallet_id') : null,
        ];

        $stockPallets = $filters['client_id'] !== null && $filters['item_id'] !== null
            ? $this->stockPallets($filters['client_id'], $filters['item_id'])->get()
            : collect();

        return view('stock.adjustments.create', [
            'clients' => $this->clients(),
            'items' => $filters['client_id'] !== null ? $this->items($filters['client_id']) : collect(),
            'stockPallets' => $stockPallets,
            'locations' => $filters['client_id'] !== null ? $this->locations($filters['client_id']) : collect(),
            'recentAdjustments' => $this->recentAdjustments(),
            'filters' => $filters,
            'statusOptions' => StockPallet::statusOptions(),
            'categoryOptions' => StockPallet::stockCategoryOptions(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function stockPallet(Request $request, StockPallet $stockPallet): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::SUPERADMIN), 403);

        return redirect()->route('stock.adjustments.create', [
            'client_id' => $stockPallet->client_id,
            'item_id' => $stockPallet->item_id,
            'stock_pallet_id' => $stockPallet->id,
        ]);
    }

    public function store(StoreStockAdjustmentRequest $request): RedirectResponse
    {
        $stockPallet = $this->adjustments->apply($request);

        return redirect()
            ->route('stock.adjustments.create', [
                'client_id' => $stockPallet->client_id,
                'item_id' => $stockPallet->item_id,
                'stock_pallet_id' => $stockPallet->id,
            ])
            ->with('status', 'Regularizacion aplicada correctamente. No se ha creado entrada ni salida.');
    }

    /** @return Collection<int, Client> */
    private function clients(): Collection
    {
        return Client::query()
            ->where('active', true)
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Item> */
    private function items(int $clientId): Collection
    {
        return Item::query()
            ->where('client_id', $clientId)
            ->orderBy('sku')
            ->get();
    }

    private function stockPallets(int $clientId, int $itemId): Builder
    {
        return StockPallet::query()
            ->with(['client', 'item', 'location.warehouse'])
            ->where('client_id', $clientId)
            ->where('item_id', $itemId)
            ->where('active', true)
            ->orderBy('lot')
            ->orderBy('location_text')
            ->orderBy('id');
    }

    /** @return Collection<int, Location> */
    private function locations(int $clientId): Collection
    {
        $locations = LocationCode::applyNaturalOrder(
            Location::query()
                ->with('warehouse')
                ->where('active', true)
                ->whereHas('warehouse', fn (Builder $query) => $query
                    ->where('active', true)
                    ->where(fn (Builder $scope) => $scope
                        ->whereNull('client_id')
                        ->orWhere('client_id', $clientId)))
        )->get();

        return $this->locations->canonicalActiveLocations($locations);
    }

    /** @return Collection<int, InventoryMovement> */
    private function recentAdjustments(): Collection
    {
        return InventoryMovement::query()
            ->where('movement_type', InventoryMovement::MANUAL_ADJUSTMENT)
            ->orderByDesc('effective_at')
            ->orderByDesc('id')
            ->limit(12)
            ->get();
    }
}
