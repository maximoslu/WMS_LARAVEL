<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreWarehouseRequest;
use App\Http\Requests\UpdateWarehouseRequest;
use App\Models\Client;
use App\Models\Warehouse;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WarehouseController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status', 'active');

        $warehouses = Warehouse::query()
            ->with(['client', 'locations'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('code', 'like', '%'.$search.'%')
                        ->orWhere('name', 'like', '%'.$search.'%');
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->orderByRaw('client_id is null desc')
            ->orderBy('code')
            ->paginate(15)
            ->withQueryString();

        return view('warehouses.index', [
            'warehouses' => $warehouses,
            'filters' => [
                'search' => $search,
                'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'active',
            ],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('warehouses.create', [
            'warehouse' => new Warehouse([
                'active' => true,
            ]),
            'clients' => Client::query()->orderBy('name')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function store(StoreWarehouseRequest $request): RedirectResponse
    {
        Warehouse::query()->create($this->payload($request->validated()));

        return redirect()
            ->route('warehouses.index')
            ->with('status', 'Almacen creado correctamente.');
    }

    public function edit(Request $request, Warehouse $warehouse): View
    {
        return view('warehouses.edit', [
            'warehouse' => $warehouse,
            'clients' => Client::query()->orderBy('name')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function update(UpdateWarehouseRequest $request, Warehouse $warehouse): RedirectResponse
    {
        $warehouse->update($this->payload($request->validated()));

        return redirect()
            ->route('warehouses.index')
            ->with('status', 'Almacen actualizado correctamente.');
    }

    public function toggleActive(Warehouse $warehouse): RedirectResponse
    {
        $warehouse->update([
            'active' => ! $warehouse->active,
        ]);

        return redirect()
            ->route('warehouses.index')
            ->with('status', $warehouse->active
                ? 'Almacen activado correctamente.'
                : 'Almacen desactivado correctamente.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return [
            'client_id' => $validated['client_id'] ?? null,
            'code' => $validated['code'],
            'name' => $validated['name'],
            'active' => (bool) ($validated['active'] ?? false),
        ];
    }
}
