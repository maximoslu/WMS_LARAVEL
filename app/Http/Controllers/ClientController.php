<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientReceiptEmailRecipientRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\ClientReceiptEmailRecipient;
use App\Models\Role;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ClientController extends Controller
{
    public function index(Request $request): View
    {
        $status = (string) $request->string('status', 'active');
        $search = trim((string) $request->string('search'));

        $clients = Client::query()
            ->when($status === 'active', fn ($query) => $query->where('active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('active', false))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query->where('name', 'like', '%'.$search.'%')
                        ->orWhere('code', 'like', '%'.$search.'%')
                        ->orWhere('delivery_city', 'like', '%'.$search.'%')
                        ->orWhere('delivery_country', 'like', '%'.$search.'%');
                });
            })
            ->orderBy('name')
            ->paginate(20)
            ->withQueryString();

        return view('clients.index', [
            'clients' => $clients,
            'filters' => [
                'status' => in_array($status, ['all', 'active', 'inactive'], true) ? $status : 'active',
                'search' => $search,
            ],
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function create(Request $request): View
    {
        return view('clients.create', [
            'client' => new Client([
                'active' => true,
                'delivery_country' => 'Espana',
            ]),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function store(StoreClientRequest $request): RedirectResponse
    {
        Client::query()->create($this->payload($request->validated()));

        return redirect()
            ->route('clients.index')
            ->with('status', 'Cliente creado correctamente.');
    }

    public function edit(Request $request, Client $client): View
    {
        return view('clients.edit', [
            'client' => $client,
            'receiptEmailRecipients' => $client->receiptEmailRecipients()->orderBy('email')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function storeReceiptEmailRecipient(StoreClientReceiptEmailRecipientRequest $request, Client $client): RedirectResponse
    {
        $client->receiptEmailRecipients()->create($request->validated());

        return redirect()
            ->route('clients.edit', $client)
            ->with('status', 'Email para albaranes anadido correctamente.');
    }

    public function destroyReceiptEmailRecipient(Request $request, Client $client, ClientReceiptEmailRecipient $clientReceiptEmailRecipient): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ADMINISTRACION), 403);
        abort_unless((int) $clientReceiptEmailRecipient->client_id === (int) $client->id, 404);

        $clientReceiptEmailRecipient->delete();

        return redirect()
            ->route('clients.edit', $client)
            ->with('status', 'Email para albaranes eliminado correctamente.');
    }

    public function update(UpdateClientRequest $request, Client $client): RedirectResponse
    {
        $client->update($this->payload($request->validated()));

        return redirect()
            ->route('clients.index')
            ->with('status', 'Cliente actualizado correctamente.');
    }

    public function toggleActive(Client $client): RedirectResponse
    {
        $client->update([
            'active' => ! $client->active,
        ]);

        return redirect()
            ->route('clients.index')
            ->with('status', $client->active
                ? 'Cliente activado correctamente.'
                : 'Cliente desactivado correctamente.');
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function payload(array $validated): array
    {
        return [
            'name' => $validated['name'],
            'code' => strtoupper(trim((string) $validated['code'])),
            'delivery_address' => $validated['delivery_address'] ?? null,
            'delivery_postal_code' => $validated['delivery_postal_code'] ?? null,
            'delivery_city' => $validated['delivery_city'] ?? null,
            'delivery_province' => $validated['delivery_province'] ?? null,
            'delivery_country' => $validated['delivery_country'] ?? null,
            'active' => (bool) ($validated['active'] ?? false),
        ];
    }
}
