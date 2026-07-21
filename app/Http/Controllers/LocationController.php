<?php

namespace App\Http\Controllers;

use App\Http\Requests\CreateLocationRangeRequest;
use App\Http\Requests\PurgeLocationsRequest;
use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use App\Models\Role;
use App\Models\Warehouse;
use App\Services\Locations\LocationCatalogService;
use App\Services\Locations\LocationIntegrityService;
use App\Services\Locations\LocationPurgeService;
use App\Services\Warehouses\WarehouseIntegrityService;
use App\Support\Locations\LocationCode;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function __construct(
        private readonly LocationIntegrityService $locations,
        private readonly LocationCatalogService $catalog,
        private readonly LocationPurgeService $purge,
    ) {}

    public function index(Request $request): View
    {
        $warehouseFilter = $request->integer('warehouse_id');
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status', 'active');

        $locationsQuery = Location::query()
            ->with(['warehouse.client'])
            ->whereHas('warehouse', fn ($query) => $query->where('active', true))
            ->when($warehouseFilter > 0, fn ($query) => $query->where('warehouse_id', $warehouseFilter))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('code', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->orderBy('warehouse_id');

        $locations = LocationCode::applyNaturalOrder($locationsQuery)
            ->paginate(20)
            ->withQueryString();

        return view('locations.index', [
            'locations' => $locations,
            'warehouses' => $this->warehouseOptions(),
            'filters' => [
                'warehouse_id' => $warehouseFilter > 0 ? $warehouseFilter : null,
                'search' => $search,
                'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'active',
            ],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
            'locationTypes' => $this->catalog->typeOptions(),
        ]);
    }

    public function create(Request $request): View
    {
        return view('locations.create', [
            'location' => new Location([
                'active' => true,
            ]),
            'warehouses' => $this->warehouseOptions(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
            'locationTypes' => $this->catalog->typeOptions(),
        ]);
    }

    public function store(StoreLocationRequest $request): RedirectResponse
    {
        Location::query()->create($this->payload($request->validated()));

        return redirect()
            ->route('locations.index')
            ->with('status', 'Ubicacion creada correctamente.');
    }

    public function edit(Request $request, Location $location): View
    {
        return view('locations.edit', [
            'location' => $location,
            'warehouses' => $this->warehouseOptions(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
            'locationTypes' => $this->catalog->typeOptions(),
        ]);
    }

    public function update(UpdateLocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($this->payload($request->validated()));

        return redirect()
            ->route('locations.index')
            ->with('status', 'Ubicacion actualizada correctamente.');
    }

    public function storeRange(CreateLocationRangeRequest $request): RedirectResponse
    {
        $result = $this->catalog->createRange(
            warehouseId: $request->warehouseId(),
            type: $request->type(),
            from: $request->from(),
            to: $request->to(),
            apply: true,
        );

        return redirect()
            ->route('locations.index', ['warehouse_id' => $request->warehouseId()])
            ->with('status', sprintf(
                'Rango procesado: %d creadas, %d ya existentes, %d errores.',
                $result['created'],
                $result['existing'],
                $result['errors'],
            ));
    }

    public function purge(PurgeLocationsRequest $request): RedirectResponse
    {
        $warehouse = $request->warehouseId() !== null
            ? Warehouse::query()->findOrFail($request->warehouseId())
            : null;
        $result = $this->purge->apply($warehouse);

        return redirect()
            ->route('locations.index', $warehouse instanceof Warehouse ? ['warehouse_id' => $warehouse->id, 'status' => 'all'] : ['status' => 'all'])
            ->with('status', sprintf(
                'Purga completada: %d ubicaciones eliminadas, %d partidas de stock sin ubicacion, %d articulos y %d lineas de entrada desvinculados. Movimientos historicos intactos: %d.',
                $result['deleted'],
                $result['stock'],
                $result['items'],
                $result['receipt_lines'],
                $result['movements'],
            ));
    }

    public function toggleActive(Location $location): RedirectResponse
    {
        $location->update([
            'active' => ! $location->active,
        ]);

        return redirect()
            ->route('locations.index')
            ->with('status', $location->active
                ? 'Ubicacion activada correctamente.'
                : 'Ubicacion desactivada correctamente.');
    }

    public function destroy(Request $request, Location $location): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::SUPERADMIN), 403);

        $references = $this->locations->referenceCounts($location->id);

        if (array_sum($references) > 0) {
            return redirect()
                ->route('locations.index', $request->only(['warehouse_id', 'search', 'status']))
                ->with('status', 'No se puede borrar esta ubicacion porque tiene stock o movimientos asociados. Puedes desactivarla.');
        }

        $location->delete();

        return redirect()
            ->route('locations.index', $request->only(['warehouse_id', 'search', 'status']))
            ->with('status', 'Ubicacion eliminada correctamente.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return $this->catalog->payload(
            warehouseId: (int) $validated['warehouse_id'],
            type: (string) ($validated['type'] ?? 'libre'),
            code: (string) $validated['code'],
            name: $validated['name'] ?? null,
            zone: $validated['zone'] ?? null,
            aisle: $validated['aisle'] ?? null,
            rack: $validated['rack'] ?? null,
            level: $validated['level'] ?? null,
            position: $validated['position'] ?? null,
            active: (bool) ($validated['active'] ?? false),
        );
    }

    /** @return Collection<int, Warehouse> */
    private function warehouseOptions(): Collection
    {
        return app(WarehouseIntegrityService::class)->canonicalActiveWarehouses(
            Warehouse::query()->with('client')->where('active', true)->get(),
        );
    }
}
