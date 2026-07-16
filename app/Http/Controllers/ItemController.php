<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Support\Locations\LocationCode;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ItemController extends Controller
{
    public function index(Request $request): View
    {
        $clientFilter = $request->integer('client_id');
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status', 'active');
        $view = (string) $request->string('view', 'list');
        $view = in_array($view, ['list', 'cards'], true) ? $view : 'list';

        $items = Item::query()
            ->with(['client', 'defaultLocation.warehouse'])
            ->when($clientFilter > 0, fn ($query) => $query->where('client_id', $clientFilter))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('sku', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->orderBy('client_id')
            ->orderBy('sku')
            ->paginate($view === 'cards' ? 12 : 20)
            ->withQueryString();

        return view('items.index', [
            'items' => $items,
            'clients' => Client::query()->orderBy('name')->get(),
            'filters' => [
                'client_id' => $clientFilter > 0 ? $clientFilter : null,
                'search' => $search,
                'status' => in_array($status, [...Item::statuses(), 'all'], true) ? $status : Item::STATUS_ACTIVE,
                'view' => $view,
            ],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('items.create', [
            'item' => new Item([
                'status' => Item::STATUS_ACTIVE,
                'active' => true,
            ]),
            'clients' => Client::query()->orderBy('name')->get(),
            'locations' => $this->locationOptions(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function store(StoreItemRequest $request): RedirectResponse
    {
        Item::query()->create($this->payload($request->validated()));

        return redirect()
            ->route('items.index')
            ->with('status', 'Articulo creado correctamente.');
    }

    public function edit(Request $request, Item $item): View
    {
        return view('items.edit', [
            'item' => $item,
            'clients' => Client::query()->orderBy('name')->get(),
            'locations' => $this->locationOptions(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function update(UpdateItemRequest $request, Item $item): RedirectResponse
    {
        $item->update($this->payload($request->validated()));

        return redirect()
            ->route('items.index')
            ->with('status', 'Articulo actualizado correctamente.');
    }

    public function toggleActive(Request $request, Item $item): RedirectResponse
    {
        $item->update([
            'status' => $item->status === Item::STATUS_ACTIVE
                ? Item::STATUS_BLOCKED
                : Item::STATUS_ACTIVE,
        ]);

        return redirect()
            ->route('items.index')
            ->with('status', $item->fresh()->status === Item::STATUS_ACTIVE
                ? 'Artículo activado correctamente.'
                : 'Artículo bloqueado correctamente.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return [
            'client_id' => $validated['client_id'],
            'sku' => $validated['sku'],
            'description' => $validated['description'],
            'units_per_pallet' => $validated['units_per_pallet'],
            'status' => $validated['status'],
            'default_location_id' => $validated['default_location_id'] ?? null,
            'active' => $validated['status'] === Item::STATUS_ACTIVE,
        ];
    }

    private function locationOptions()
    {
        return LocationCode::applyNaturalOrder(
            Location::query()->where('active', true)->with('warehouse')
        )->get();
    }
}
