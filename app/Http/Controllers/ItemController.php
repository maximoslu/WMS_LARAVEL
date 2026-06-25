<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreItemRequest;
use App\Http\Requests\UpdateItemRequest;
use App\Models\Client;
use App\Models\Item;
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
            ->with('client')
            ->when($clientFilter > 0, fn ($query) => $query->where('client_id', $clientFilter))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('sku', 'like', '%'.$search.'%')
                        ->orWhere('description', 'like', '%'.$search.'%');
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->orderBy('client_id')
            ->orderBy('sku')
            ->orderBy('lot_key')
            ->paginate($view === 'cards' ? 12 : 20)
            ->withQueryString();

        return view('items.index', [
            'items' => $items,
            'clients' => Client::query()->orderBy('name')->get(),
            'filters' => [
                'client_id' => $clientFilter > 0 ? $clientFilter : null,
                'search' => $search,
                'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'active',
                'view' => $view,
            ],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('items.create', [
            'item' => new Item([
                'active' => true,
            ]),
            'clients' => Client::query()->orderBy('name')->get(),
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
            'active' => ! $item->active,
        ]);

        return redirect()
            ->route('items.index')
            ->with('status', $item->active
                ? 'Articulo activado correctamente.'
                : 'Articulo desactivado correctamente.');
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
            'lot' => $validated['lot'],
            'lot_key' => $validated['lot_key'] ?? '',
            'units_per_pallet' => $validated['units_per_pallet'],
            'active' => (bool) ($validated['active'] ?? false),
        ];
    }
}
