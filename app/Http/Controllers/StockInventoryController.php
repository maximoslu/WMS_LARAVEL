<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Models\Role;
use App\Services\Stock\StockInventoryExportService;
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
        'stock_state',
        'per_page',
    ];

    public function __construct(
        private readonly StockOverviewBuilder $overviewBuilder,
        private readonly StockInventoryExportService $exportService,
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

        return view('stock.inventory.index', [
            'rows' => collect($paginator->items()),
            'paginator' => $paginator,
            'summary' => $inventory['summary'],
            'filters' => $inventory['filters'],
            'options' => $inventory['options'],
            'clients' => $clients,
            'isClient' => $isClient,
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
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
}
