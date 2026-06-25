<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreLocationRequest;
use App\Http\Requests\UpdateLocationRequest;
use App\Models\Location;
use App\Models\Warehouse;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class LocationController extends Controller
{
    public function index(Request $request): View
    {
        $warehouseFilter = $request->integer('warehouse_id');
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status', 'active');

        $locations = Location::query()
            ->with(['warehouse.client'])
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
            ->orderBy('warehouse_id')
            ->orderBy('code')
            ->paginate(20)
            ->withQueryString();

        return view('locations.index', [
            'locations' => $locations,
            'warehouses' => Warehouse::query()->orderBy('code')->get(),
            'filters' => [
                'warehouse_id' => $warehouseFilter > 0 ? $warehouseFilter : null,
                'search' => $search,
                'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'active',
            ],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('locations.create', [
            'location' => new Location([
                'active' => true,
            ]),
            'warehouses' => Warehouse::query()->orderBy('code')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
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
            'warehouses' => Warehouse::query()->orderBy('code')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function update(UpdateLocationRequest $request, Location $location): RedirectResponse
    {
        $location->update($this->payload($request->validated()));

        return redirect()
            ->route('locations.index')
            ->with('status', 'Ubicacion actualizada correctamente.');
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

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return [
            'warehouse_id' => $validated['warehouse_id'],
            'code' => $validated['code'],
            'name' => $validated['name'] ?? null,
            'zone' => $validated['zone'] ?? null,
            'aisle' => $validated['aisle'] ?? null,
            'rack' => $validated['rack'] ?? null,
            'level' => $validated['level'] ?? null,
            'position' => $validated['position'] ?? null,
            'active' => (bool) ($validated['active'] ?? false),
        ];
    }
}
