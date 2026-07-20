<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreStockRelocationRequest;
use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockPallet;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Locations\LocationIntegrityService;
use App\Support\Locations\LocationCode;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class StockRelocationController extends Controller
{
    public function __construct(
        private readonly InventoryMovementService $movements,
        private readonly AuditLogService $audit,
        private readonly LocationIntegrityService $locations,
    ) {}

    public function create(Request $request): View
    {
        $filters = [
            'client_id' => $request->integer('client_id') > 0 ? $request->integer('client_id') : null,
            'item_id' => $request->integer('item_id') > 0 ? $request->integer('item_id') : null,
            'stock_pallet_id' => $request->integer('stock_pallet_id') > 0 ? $request->integer('stock_pallet_id') : null,
            'destination_location_id' => $request->integer('destination_location_id') > 0 ? $request->integer('destination_location_id') : null,
        ];

        return view('stock.relocate', [
            'clients' => $this->clientsWithStock(),
            'items' => $filters['client_id'] !== null ? $this->itemsWithStock($filters['client_id']) : collect(),
            'stockPallets' => $filters['client_id'] !== null && $filters['item_id'] !== null
                ? $this->relocatableStock($filters['client_id'], $filters['item_id'])->get()
                : collect(),
            'locations' => $filters['client_id'] !== null ? $this->destinationLocations($filters['client_id']) : collect(),
            'filters' => $filters,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function store(StoreStockRelocationRequest $request): RedirectResponse
    {
        $stockPallet = null;
        $destination = null;

        DB::transaction(function () use ($request, &$stockPallet, &$destination): void {
            $stockPallet = StockPallet::query()
                ->with(['client', 'item', 'location.warehouse'])
                ->lockForUpdate()
                ->findOrFail($request->stockPalletId());
            $destination = Location::query()
                ->with('warehouse')
                ->findOrFail($request->destinationLocationId());
            $correlationId = $this->audit->correlationId();
            $before = $this->movements->snapshot($stockPallet);
            $oldValues = [
                'location_id' => $stockPallet->location_id,
                'location_text' => $stockPallet->location_text,
            ];

            StockPallet::query()
                ->whereKey($stockPallet->id)
                ->update([
                    'location_id' => $destination->id,
                    'location_text' => $destination->code,
                    'updated_at' => now(),
                ]);

            $fresh = $stockPallet->fresh(['client', 'item', 'location.warehouse']);
            $after = $this->movements->snapshot($fresh);

            $this->movements->record(
                before: $before,
                after: $after,
                movementType: InventoryMovement::TRANSFER,
                idempotencyKey: "stock-relocation:{$fresh->id}:{$correlationId}",
                correlationId: $correlationId,
                source: $fresh,
                user: $request->user(),
                metadata: ['reason' => 'Reubicacion operativa de stock sin cambio de cantidad.'],
            );

            $this->audit->record(
                event: 'stock_batch_relocated',
                module: 'stock',
                description: 'Partida de stock reubicada sin modificar cantidades.',
                auditable: $fresh,
                user: $request->user(),
                clientId: $fresh->client_id,
                oldValues: $oldValues,
                newValues: [
                    'location_id' => $fresh->location_id,
                    'location_text' => $fresh->location_text,
                ],
                metadata: [
                    'item_id' => $fresh->item_id,
                    'sku' => $fresh->item?->sku,
                    'lot' => $fresh->lot,
                ],
                correlationId: $correlationId,
                severity: 'important',
            );
        });

        return redirect()
            ->route('stock.relocations.create', [
                'client_id' => $request->clientId(),
                'item_id' => $request->itemId(),
                'stock_pallet_id' => $stockPallet?->id,
                'destination_location_id' => $destination?->id,
            ])
            ->with('status', 'Stock reubicado correctamente. Solo se ha cambiado la ubicacion fisica.');
    }

    /** @return Collection<int, Client> */
    private function clientsWithStock(): Collection
    {
        return Client::query()
            ->where('active', true)
            ->whereHas('stockPallets', fn (Builder $query) => $this->relocatableScope($query))
            ->orderBy('name')
            ->get();
    }

    /** @return Collection<int, Item> */
    private function itemsWithStock(int $clientId): Collection
    {
        return Item::query()
            ->where('client_id', $clientId)
            ->whereHas('stockPallets', fn (Builder $query) => $this->relocatableScope($query))
            ->orderBy('sku')
            ->get();
    }

    private function relocatableStock(int $clientId, int $itemId): Builder
    {
        $query = StockPallet::query()
            ->with(['item', 'client', 'location.warehouse'])
            ->where('client_id', $clientId)
            ->where('item_id', $itemId);

        $this->relocatableScope($query);

        return $query
            ->orderBy('lot')
            ->orderBy('location_text')
            ->orderBy('id');
    }

    /** @return Collection<int, Location> */
    private function destinationLocations(int $clientId): Collection
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

    private function relocatableScope(Builder $query): void
    {
        $query
            ->where('active', true)
            ->where(function (Builder $scope): void {
                $scope
                    ->where('quantity_units', '>', 0)
                    ->orWhere('full_pallets', '>', 0)
                    ->orWhere('warehouse_pallets', '>', 0);

                foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
                    $scope->orWhere('peak_'.$peakNumber, '>', 0);
                }
            });
    }
}
