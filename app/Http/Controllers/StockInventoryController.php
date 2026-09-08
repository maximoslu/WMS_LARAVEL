<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Role;
use App\Models\StockInventoryLocation;
use App\Models\StockInventorySession;
use App\Services\Stock\StockInventoryExportService;
use App\Services\Stock\StockInventorySessionService;
use App\Support\Stock\StockOverviewBuilder;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockInventoryController extends Controller
{
    /** @var list<string> */
    private const FILTERS = [
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
        'per_page',
    ];

    public function __construct(
        private readonly StockOverviewBuilder $overviewBuilder,
        private readonly StockInventoryExportService $exportService,
        private readonly StockInventorySessionService $sessionService,
    ) {}

    public function index(Request $request): View
    {
        $user = $request->user();
        abort_if($user?->hasRole(Role::CLIENTE) && $user->client_id === null, 403);

        $isClient = $user?->hasRole(Role::CLIENTE) === true;
        $clients = $isClient
            ? collect()
            : Client::query()->where('active', true)->orderBy('name')->get();
        $requestedFilters = $request->only(self::FILTERS);

        if (! $isClient && blank($requestedFilters['client_id'] ?? null) && $clients->count() === 1) {
            $requestedFilters['client_id'] = $clients->first()->id;
        }

        $inventory = $this->overviewBuilder->inventory($user, $requestedFilters);
        $paginator = $this->paginate($inventory['rows'], $inventory['filters']['per_page'], $request);
        $openSession = $this->sessionService->openForClient($inventory['filters']['client_id']);

        return view('stock.inventory.index', [
            'rows' => collect($paginator->items()),
            'paginator' => $paginator,
            'summary' => $inventory['summary'],
            'filters' => $inventory['filters'],
            'options' => $inventory['options'],
            'clients' => $clients,
            'isClient' => $isClient,
            'scopePreview' => $this->sessionService->previewScopes($inventory['rows']),
            'openSession' => $openSession,
            'openSummary' => $openSession ? $this->sessionService->summary($openSession) : null,
            'history' => $this->sessionService->historyForClient($inventory['filters']['client_id']),
            'canOperateLocations' => ! $isClient || (bool) $user?->client?->show_storage_occupancy_to_client,
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function start(Request $request): RedirectResponse
    {
        $this->authorizeLocationOperation($request);
        $request->validate([
            'client_id' => ['nullable', 'integer'],
            'warehouse_id' => ['nullable', 'integer'],
            'stock_category' => ['nullable', 'string'],
            'item_state' => ['nullable', 'string'],
            'batch_status' => ['nullable', 'string'],
            'location_state' => ['nullable', 'string'],
            'location_id' => ['nullable', 'integer'],
            'location_ids' => ['nullable', 'array'],
            'location_ids.*' => ['integer'],
            'location_from' => ['nullable', 'string', 'max:100'],
            'location_to' => ['nullable', 'string', 'max:100'],
            'stock_state' => ['nullable', 'string'],
            'scope_keys' => ['required', 'array', 'min:1'],
            'scope_keys.*' => ['required', 'string', 'max:191'],
        ]);

        $session = $this->sessionService->start(
            $request->user(),
            $request->only(self::FILTERS),
            array_values($request->input('scope_keys', [])),
        );

        return redirect()
            ->route('stock.inventory.show', $session)
            ->with('status', 'Inventario iniciado. El progreso quedará guardado hasta su finalización.');
    }

    public function show(Request $request, StockInventorySession $stockInventorySession): View
    {
        $this->authorizeSession($request, $stockInventorySession);
        $this->authorizeLocationOperation($request);
        $inventory = $this->sessionService->viewData(
            $request->user(),
            $stockInventorySession,
            $request->only(['status', 'warehouse_id', 'order']),
        );
        $paginator = $this->paginate($inventory['locations'], 25, $request);

        return view('stock.inventory.show', [
            'inventorySession' => $stockInventorySession->loadMissing(['client', 'starter', 'completer']),
            'locations' => collect($paginator->items()),
            'paginator' => $paginator,
            'summary' => $inventory['summary'],
            'warehouses' => $inventory['warehouses'],
            'filters' => $inventory['filters'],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function check(
        Request $request,
        StockInventorySession $stockInventorySession,
        StockInventoryLocation $stockInventoryLocation,
    ): RedirectResponse {
        $this->authorizeSession($request, $stockInventorySession);
        $this->authorizeLocationOperation($request);
        abort_unless(
            (int) $stockInventoryLocation->stock_inventory_session_id === (int) $stockInventorySession->id
            && (int) $stockInventoryLocation->client_id === (int) $stockInventorySession->client_id,
            403,
        );
        $request->validate(['notes' => ['nullable', 'string', 'max:2000']]);

        $this->sessionService->check(
            $request->user(),
            $stockInventorySession,
            $stockInventoryLocation,
            $request->string('notes')->toString(),
        );

        return back()->with('status', 'Ubicación comprobada. Se ha guardado el usuario, la hora y la fotografía teórica.');
    }

    public function complete(Request $request, StockInventorySession $stockInventorySession): RedirectResponse
    {
        $this->authorizeSession($request, $stockInventorySession);
        $this->authorizeLocationOperation($request);
        $request->validate(['confirmed' => ['accepted']]);
        $this->sessionService->complete($request->user(), $stockInventorySession);

        return redirect()
            ->route('stock.inventory.index', ['client_id' => $stockInventorySession->client_id])
            ->with('status', 'Inventario finalizado y conservado en el histórico.');
    }

    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        $user = $request->user();
        abort_if($user?->hasRole(Role::CLIENTE) && $user->client_id === null, 403);

        $inventory = $this->overviewBuilder->inventory($user, $request->only(self::FILTERS));
        $client = $inventory['options']['client'];

        if (! $client instanceof Client) {
            return redirect()
                ->route('stock.inventory.index', $request->query())
                ->withErrors(['client_id' => 'Selecciona un cliente para descargar el inventario.']);
        }

        return $this->exportService->toXlsxResponse($client, $inventory['rows']);
    }

    /** @param Collection<int, array<string, mixed>> $rows */
    private function paginate(Collection $rows, int $perPage, Request $request): LengthAwarePaginator
    {
        $page = LengthAwarePaginator::resolveCurrentPage('page');

        return new LengthAwarePaginator(
            items: $rows->forPage($page, $perPage)->values(),
            total: $rows->count(),
            perPage: $perPage,
            currentPage: $page,
            options: [
                'path' => $request->url(),
                'pageName' => 'page',
                'query' => $request->except('page'),
            ],
        );
    }

    private function authorizeSession(Request $request, StockInventorySession $session): void
    {
        $user = $request->user();

        if ($user?->hasRole(Role::CLIENTE)) {
            abort_unless($user->client_id !== null && (int) $user->client_id === (int) $session->client_id, 403);
        }
    }

    private function authorizeLocationOperation(Request $request): void
    {
        $user = $request->user();

        if ($user?->hasRole(Role::CLIENTE)) {
            abort_unless((bool) $user->client?->show_storage_occupancy_to_client, 403);
        }
    }
}
