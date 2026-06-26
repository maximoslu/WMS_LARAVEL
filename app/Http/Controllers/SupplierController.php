<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreSupplierRequest;
use App\Http\Requests\UpdateSupplierRequest;
use App\Models\Client;
use App\Models\Supplier;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupplierController extends Controller
{
    public function index(Request $request): View
    {
        $clientFilter = $request->integer('client_id');
        $search = trim((string) $request->string('search'));
        $status = (string) $request->string('status', 'active');

        $suppliers = Supplier::query()
            ->with('client')
            ->when($clientFilter > 0, fn ($query) => $query->where('client_id', $clientFilter))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('tax_id', 'like', '%'.$search.'%')
                        ->orWhere('contact_name', 'like', '%'.$search.'%');
                });
            })
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('suppliers.index', [
            'suppliers' => $suppliers,
            'clients' => Client::query()->orderBy('name')->get(),
            'filters' => [
                'client_id' => $clientFilter > 0 ? $clientFilter : null,
                'search' => $search,
                'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'active',
            ],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('suppliers.create', [
            'supplier' => new Supplier(['active' => true]),
            'clients' => Client::query()->orderBy('name')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function store(StoreSupplierRequest $request): RedirectResponse
    {
        Supplier::query()->create($this->payload($request->validated()));

        return redirect()
            ->route('suppliers.index')
            ->with('status', 'Proveedor creado correctamente.');
    }

    public function edit(Request $request, Supplier $supplier): View
    {
        return view('suppliers.edit', [
            'supplier' => $supplier,
            'clients' => Client::query()->orderBy('name')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function update(UpdateSupplierRequest $request, Supplier $supplier): RedirectResponse
    {
        $supplier->update($this->payload($request->validated()));

        return redirect()
            ->route('suppliers.index')
            ->with('status', 'Proveedor actualizado correctamente.');
    }

    public function toggleActive(Supplier $supplier): RedirectResponse
    {
        $supplier->update([
            'active' => ! $supplier->active,
        ]);

        return redirect()
            ->route('suppliers.index')
            ->with('status', $supplier->active
                ? 'Proveedor activado correctamente.'
                : 'Proveedor desactivado correctamente.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return [
            'client_id' => $validated['client_id'] ?? null,
            'name' => $validated['name'],
            'tax_id' => $validated['tax_id'] ?? null,
            'email' => $validated['email'] ?? null,
            'phone' => $validated['phone'] ?? null,
            'contact_name' => $validated['contact_name'] ?? null,
            'notes' => $validated['notes'] ?? null,
            'active' => (bool) ($validated['active'] ?? false),
        ];
    }
}
