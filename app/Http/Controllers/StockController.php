<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use App\Support\Stock\StockOverviewBuilder;
use Illuminate\Http\Request;
use Illuminate\View\View;
use App\Http\Requests\UpdateStockPalletRequest;

class StockController extends Controller
{
    public function __construct(
        private readonly StockOverviewBuilder $overviewBuilder,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();

        abort_if($user?->hasRole(Role::CLIENTE) && $user->client_id === null, 403);

        $overview = $this->overviewBuilder->build($user, $request->only([
            'client_id',
            'item_id',
            'search',
            'lot',
            'only_peaks',
            'per_page',
            'stock_state',
            'batch_status',
            'location',
            'location_id',
        ]));
        $isClient = $user?->hasRole(Role::CLIENTE) === true;
        $canFilterClients = $user?->canAccessRole(Role::ALMACEN) === true;

        return view('stock.index', [
            'rows' => $overview['rows'],
            'paginator' => $overview['paginator'],
            'summary' => $overview['summary'],
            'filters' => $overview['filters'],
            'clients' => $canFilterClients
                ? Client::query()->orderBy('name')->get()
                : collect(),
            'isClient' => $isClient,
            'canFilterClients' => $canFilterClients,
            'pageTitle' => $isClient ? 'Mi inventario' : 'Stock actual',
            'pageSubtitle' => $isClient
                ? 'Consulta tus existencias, lotes, pallets y picos disponibles.'
                : 'Consulta existencias, lotes, pallets y picos operativos.',
            'itemSearchEndpoint' => route('ajax.items'),
            'lotSearchEndpoint' => route('ajax.lots'),
            'locationSearchEndpoint' => route('ajax.locations'),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function edit(Request $request, StockPallet $stockPallet): View
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $stockPallet->loadMissing(['client', 'item', 'location.warehouse']);

        return view('stock.edit', [
            'stockPallet' => $stockPallet,
            'locations' => Location::query()
                ->where('active', true)
                ->with('warehouse')
                ->orderBy('code')
                ->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function update(UpdateStockPalletRequest $request, StockPallet $stockPallet): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $stockPallet->update($request->payload());

        return redirect()
            ->route('stock.index', ['client_id' => $stockPallet->client_id])
            ->with('status', 'Partida de stock actualizada correctamente.');
    }
}
