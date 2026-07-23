<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreClientDispatchEmailRecipientRequest;
use App\Http\Requests\StoreClientReceiptEmailRecipientRequest;
use App\Http\Requests\StoreClientRequest;
use App\Http\Requests\StoreClientStockAlertEmailRecipientRequest;
use App\Http\Requests\UpdateClientRequest;
use App\Models\Client;
use App\Models\ClientDispatchEmailRecipient;
use App\Models\ClientReceiptEmailRecipient;
use App\Models\ClientStockAlertEmailRecipient;
use App\Models\Role;
use App\Services\Audit\AuditLogService;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    public function store(StoreClientRequest $request, AuditLogService $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $audit): void {
            $client = Client::query()->create($this->payload(
                $request->validated(),
                defaultStorageOccupancyVisibility: true,
                defaultStockTotalVisibility: true,
            ));
            $audit->record(
                event: 'client_created',
                module: 'clients',
                description: 'Cliente creado.',
                auditable: $client,
                user: $request->user(),
                clientId: $client->id,
                newValues: $client->toArray(),
            );
        });

        return redirect()
            ->route('clients.index')
            ->with('status', 'Cliente creado correctamente.');
    }

    public function edit(Request $request, Client $client): View
    {
        return view('clients.edit', [
            'client' => $client,
            'receiptEmailRecipients' => $client->receiptEmailRecipients()->orderBy('email')->get(),
            'dispatchEmailRecipients' => $client->dispatchEmailRecipients()->orderBy('email')->get(),
            'stockAlertEmailRecipients' => $client->stockAlertEmailRecipients()->orderBy('email')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function storeReceiptEmailRecipient(StoreClientReceiptEmailRecipientRequest $request, Client $client, AuditLogService $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $client, $audit): void {
            $recipient = $client->receiptEmailRecipients()->create($request->validated());
            $audit->record(event: 'receipt_email_added', module: 'clients', description: 'Destinatario de albaranes de entrada anadido.', auditable: $client, subject: $recipient, user: $request->user(), clientId: $client->id, newValues: $recipient->toArray());
        });

        return redirect()
            ->route('clients.edit', $client)
            ->with('status', 'Email para albaranes anadido correctamente.');
    }

    public function destroyReceiptEmailRecipient(Request $request, Client $client, ClientReceiptEmailRecipient $clientReceiptEmailRecipient, AuditLogService $audit): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ADMINISTRACION), 403);
        abort_unless((int) $clientReceiptEmailRecipient->client_id === (int) $client->id, 404);

        DB::transaction(function () use ($request, $client, $clientReceiptEmailRecipient, $audit): void {
            $old = $clientReceiptEmailRecipient->toArray();
            $clientReceiptEmailRecipient->delete();
            $audit->record(event: 'receipt_email_removed', module: 'clients', description: 'Destinatario de albaranes de entrada eliminado.', auditable: $client, user: $request->user(), clientId: $client->id, oldValues: $old);
        });

        return redirect()
            ->route('clients.edit', $client)
            ->with('status', 'Email para albaranes eliminado correctamente.');
    }

    public function storeDispatchEmailRecipient(StoreClientDispatchEmailRecipientRequest $request, Client $client, AuditLogService $audit): RedirectResponse
    {
        DB::transaction(function () use ($request, $client, $audit): void {
            $recipient = $client->dispatchEmailRecipients()->create($request->validated());
            $audit->record(event: 'dispatch_email_added', module: 'clients', description: 'Destinatario de albaranes de salida anadido.', auditable: $client, subject: $recipient, user: $request->user(), clientId: $client->id, newValues: $recipient->toArray());
        });

        return redirect()
            ->route('clients.edit', $client)
            ->with('status', 'Email para albaranes de salida anadido correctamente.');
    }

    public function destroyDispatchEmailRecipient(Request $request, Client $client, ClientDispatchEmailRecipient $clientDispatchEmailRecipient, AuditLogService $audit): RedirectResponse
    {
        abort_unless($request->user()?->canAccessRole(Role::ADMINISTRACION), 403);
        abort_unless((int) $clientDispatchEmailRecipient->client_id === (int) $client->id, 404);

        DB::transaction(function () use ($request, $client, $clientDispatchEmailRecipient, $audit): void {
            $old = $clientDispatchEmailRecipient->toArray();
            $clientDispatchEmailRecipient->delete();
            $audit->record(event: 'dispatch_email_removed', module: 'clients', description: 'Destinatario de albaranes de salida eliminado.', auditable: $client, user: $request->user(), clientId: $client->id, oldValues: $old);
        });

        return redirect()
            ->route('clients.edit', $client)
            ->with('status', 'Email para albaranes de salida eliminado correctamente.');
    }

    public function storeStockAlertEmailRecipient(
        StoreClientStockAlertEmailRecipientRequest $request,
        Client $client,
        AuditLogService $audit,
    ): RedirectResponse {
        DB::transaction(function () use ($request, $client, $audit): void {
            $recipient = $client->stockAlertEmailRecipients()->create($request->validated());
            $audit->record(
                event: 'stock_alert_email_added',
                module: 'clients',
                description: 'Destinatario de avisos de stock anadido.',
                auditable: $client,
                subject: $recipient,
                user: $request->user(),
                clientId: $client->id,
                newValues: ['email' => $recipient->email, 'active' => $recipient->active],
            );
        });

        return to_route('clients.edit', $client)->with('status', 'Email para avisos de stock anadido correctamente.');
    }

    public function destroyStockAlertEmailRecipient(
        Request $request,
        Client $client,
        ClientStockAlertEmailRecipient $clientStockAlertEmailRecipient,
        AuditLogService $audit,
    ): RedirectResponse {
        abort_unless($request->user()?->canAccessRole(Role::ADMINISTRACION), 403);
        abort_unless((int) $clientStockAlertEmailRecipient->client_id === (int) $client->id, 404);
        DB::transaction(function () use ($request, $client, $clientStockAlertEmailRecipient, $audit): void {
            $old = ['email' => $clientStockAlertEmailRecipient->email, 'active' => $clientStockAlertEmailRecipient->active];
            $clientStockAlertEmailRecipient->delete();
            $audit->record(
                event: 'stock_alert_email_removed',
                module: 'clients',
                description: 'Destinatario de avisos de stock eliminado.',
                auditable: $client,
                user: $request->user(),
                clientId: $client->id,
                oldValues: $old,
            );
        });

        return to_route('clients.edit', $client)->with('status', 'Email para avisos de stock eliminado correctamente.');
    }

    public function update(UpdateClientRequest $request, Client $client, AuditLogService $audit): RedirectResponse
    {
        $old = $client->toArray();
        DB::transaction(function () use ($request, $client, $audit, $old): void {
            $client->update($this->payload(
                $request->validated(),
                defaultStorageOccupancyVisibility: false,
                defaultStockTotalVisibility: false,
            ));
            $audit->record(event: 'client_updated', module: 'clients', description: 'Cliente actualizado.', auditable: $client, user: $request->user(), clientId: $client->id, oldValues: $old, newValues: $client->fresh()->toArray());
        });

        return redirect()
            ->route('clients.index')
            ->with('status', 'Cliente actualizado correctamente.');
    }

    public function toggleActive(Request $request, Client $client, AuditLogService $audit): RedirectResponse
    {
        $old = ['active' => $client->active];
        DB::transaction(function () use ($request, $client, $audit, $old): void {
            $client->update(['active' => ! $client->active]);
            $audit->record(event: $client->active ? 'client_activated' : 'client_deactivated', module: 'clients', description: $client->active ? 'Cliente activado.' : 'Cliente desactivado.', auditable: $client, user: $request->user(), clientId: $client->id, oldValues: $old, newValues: ['active' => $client->active]);
        });

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
    private function payload(array $validated, bool $defaultStorageOccupancyVisibility, bool $defaultStockTotalVisibility): array
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
            'show_storage_occupancy_to_client' => (bool) ($validated['show_storage_occupancy_to_client'] ?? $defaultStorageOccupancyVisibility),
            'show_stock_total_to_client' => (bool) ($validated['show_stock_total_to_client'] ?? $defaultStockTotalVisibility),
        ];
    }
}
