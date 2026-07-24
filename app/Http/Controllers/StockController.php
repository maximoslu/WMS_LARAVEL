<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateStockPalletRequest;
use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Services\Audit\AuditLogService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Locations\LocationIntegrityService;
use App\Services\Stock\StockExportService;
use App\Support\Stock\StockOverviewBuilder;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\Response;

class StockController extends Controller
{
    public function __construct(
        private readonly StockOverviewBuilder $overviewBuilder,
        private readonly StockExportService $exportService,
        private readonly InventoryMovementService $movements,
        private readonly AuditLogService $audit,
        private readonly LocationIntegrityService $locations,
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
            'stock_category',
            'location',
            'location_id',
        ]));
        $isClient = $user?->hasRole(Role::CLIENTE) === true;
        $user?->loadMissing('client');
        $canFilterClients = $user?->canAccessRole(Role::ALMACEN) === true;
        $canAdjustStock = $user?->canAccessRole(Role::SUPERADMIN) === true;
        $canSeeStorageOccupancy = ! $isClient || (bool) ($user?->client?->show_storage_occupancy_to_client ?? false);
        $canSeeStockTotal = ! $isClient || (bool) ($user?->client?->show_stock_total_to_client ?? false);
        $exportClientId = $overview['filters']['client_id'];

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
            'canAdjustStock' => $canAdjustStock,
            'canSeeStorageOccupancy' => $canSeeStorageOccupancy,
            'canSeeStockTotal' => $canSeeStockTotal,
            'canExportStock' => $exportClientId !== null,
            'exportClientId' => $exportClientId,
            'pageTitle' => $isClient ? 'STOCK' : 'Stock actual',
            'pageSubtitle' => $isClient
                ? 'Consulta tus existencias, lotes, pallets y picos disponibles.'
                : 'Consulta existencias, lotes, pallets y picos operativos.',
            'itemSearchEndpoint' => route('ajax.items'),
            'lotSearchEndpoint' => route('ajax.lots'),
            'locationSearchEndpoint' => route('ajax.locations'),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function export(Request $request, string $format): Response
    {
        $user = $request->user();

        abort_if($user?->hasRole(Role::CLIENTE) && $user->client_id === null, 403);
        abort_unless(in_array($format, ['xlsx', 'csv', 'pdf'], true), 404);

        $clientId = $this->overviewBuilder->resolveExportClientId($user, $request->query('client_id'));

        if ($clientId === null) {
            return redirect()
                ->route('stock.index', $request->query())
                ->with('status', 'Selecciona un cliente para poder exportar su stock.');
        }

        $client = Client::query()->findOrFail($clientId);
        $rows = $this->exportService->rows($clientId);

        return match ($format) {
            'xlsx' => $this->exportService->toXlsxResponse($client, $rows),
            'csv' => $this->exportService->toCsvResponse($client, $rows),
            default => $this->exportService->toPdfResponse($client, $rows),
        };
    }

    public function edit(Request $request, StockPallet $stockPallet): View
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $stockPallet->loadMissing(['client', 'item', 'location.warehouse']);

        return view('stock.edit', [
            'stockPallet' => $stockPallet,
            'locations' => $this->locations->canonicalActiveLocationsForStock($stockPallet),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function update(UpdateStockPalletRequest $request, StockPallet $stockPallet): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        DB::transaction(function () use ($request, $stockPallet): void {
            $correlationId = $this->audit->correlationId();
            $stockPallet->loadMissing(['client', 'item', 'location.warehouse']);
            $before = $this->movements->snapshot($stockPallet);
            $oldValues = $stockPallet->only(['location_id', 'location_text']);
            $location = $request->locationId() !== null
                ? Location::query()->findOrFail($request->locationId())
                : null;

            DB::table('stock_pallets')->where('id', $stockPallet->id)->update([
                'location_id' => $location?->id,
                'location_text' => $location?->code,
                'updated_at' => now(),
            ]);

            $fresh = $stockPallet->fresh(['client', 'item', 'location.warehouse']);
            $after = $this->movements->snapshot($fresh);

            $this->movements->record(
                before: $before,
                after: $after,
                movementType: InventoryMovement::TRANSFER,
                idempotencyKey: "stock-manual:{$stockPallet->id}:{$correlationId}",
                correlationId: $correlationId,
                source: $fresh,
                user: $request->user(),
                metadata: ['reason' => 'Edicion directa de ubicacion de partida de stock.'],
            );
            $this->audit->record(
                event: 'stock_batch_location_updated',
                module: 'stock',
                description: 'Ubicacion de partida de stock actualizada manualmente.',
                auditable: $fresh,
                user: $request->user(),
                clientId: $fresh->client_id,
                oldValues: $oldValues,
                newValues: $fresh->only(array_keys($oldValues)),
                correlationId: $correlationId,
                severity: 'important',
            );
        });

        return redirect()
            ->route('stock.index', ['client_id' => $stockPallet->client_id])
            ->with('status', 'Ubicacion de la partida actualizada correctamente.');
    }
}
