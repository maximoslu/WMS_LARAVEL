<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMerchandiseRequestRequest;
use App\Http\Requests\UpdateMerchandiseRequestRequest;
use App\Models\Client;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestLine;
use App\Models\Role;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use App\Services\MerchandiseRequests\MerchandiseRequestPreparationService;
use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Throwable;

class MerchandiseRequestController extends Controller
{
    public function __construct(
        private readonly MerchandiseRequestPreparationService $preparationService,
        private readonly MerchandiseRequestNotificationService $notificationService,
    ) {
    }

    public function index(Request $request): View
    {
        $user = $request->user();
        $clientFilter = $request->integer('client_id');
        $status = (string) $request->string('status', 'all');
        $search = trim((string) $request->string('search'));
        $requestedDate = (string) $request->string('requested_date');

        $requests = MerchandiseRequest::query()
            ->with(['client', 'requester'])
            ->withCount('lines')
            ->when($user->hasRole(Role::CLIENTE), fn ($query) => $query->where('client_id', $user->client_id))
            ->when(! $user->hasRole(Role::CLIENTE) && $clientFilter > 0, fn ($query) => $query->where('client_id', $clientFilter))
            ->when($status !== 'all', fn ($query) => $query->where('status', $status))
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('delivery_reference', 'like', '%'.$search.'%')
                        ->orWhereHas('client', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($requestedDate !== '', fn ($query) => $query->whereDate('requested_date', $requestedDate))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('merchandise-requests.index', [
            'requests' => $requests,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'filters' => [
                'client_id' => $user->hasRole(Role::CLIENTE) ? $user->client_id : ($clientFilter > 0 ? $clientFilter : null),
                'status' => in_array($status, ['all', MerchandiseRequest::STATUS_DRAFT, MerchandiseRequest::STATUS_CREATED, MerchandiseRequest::STATUS_PREPARED, MerchandiseRequest::STATUS_SHIPPED, MerchandiseRequest::STATUS_CANCELLED], true)
                    ? $status
                    : 'all',
                'search' => $search,
                'requested_date' => $requestedDate,
            ],
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();
        abort_unless($this->canCreate($user), 403);

        return view('merchandise-requests.create', $this->formData($request, new MerchandiseRequest([
            'status' => MerchandiseRequest::STATUS_CREATED,
            'requested_date' => today(),
            'client_id' => $user->hasRole(Role::CLIENTE) ? $user->client_id : null,
        ])));
    }

    public function store(StoreMerchandiseRequestRequest $request): RedirectResponse
    {
        abort_unless($this->canCreate($request->user()), 403);

        $validated = $request->validated();

        $merchandiseRequest = DB::transaction(function () use ($request, $validated): MerchandiseRequest {
            $merchandiseRequest = MerchandiseRequest::query()->create([
                'client_id' => $validated['client_id'],
                'requested_by' => $request->user()->id,
                'status' => MerchandiseRequest::STATUS_CREATED,
                'delivery_reference' => $validated['delivery_reference'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'requested_date' => $validated['requested_date'] ?? today()->toDateString(),
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->syncLines($merchandiseRequest, $validated['lines']);

            $merchandiseRequest->events()->create([
                'user_id' => $request->user()->id,
                'event_type' => 'created',
                'title' => 'Pedido creado',
                'description' => 'La solicitud se ha registrado y queda pendiente de preparacion.',
            ]);

            return $merchandiseRequest;
        });

        $statusMessage = 'Solicitud creada correctamente.';

        try {
            $this->notificationService->sendCreated($merchandiseRequest);
        } catch (Throwable $exception) {
            report($exception);
            $statusMessage = 'Solicitud creada correctamente. El envio del correo interno no ha podido confirmarse.';
        }

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', $statusMessage);
    }

    public function show(Request $request, MerchandiseRequest $merchandiseRequest): View
    {
        abort_unless($this->canView($request->user(), $merchandiseRequest), 403);

        $merchandiseRequest->load([
            'client',
            'requester',
            'preparer',
            'shipper',
            'lines.item',
            'events.user',
        ]);

        return view('merchandise-requests.show', [
            'merchandiseRequest' => $merchandiseRequest,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function edit(Request $request, MerchandiseRequest $merchandiseRequest): View
    {
        abort_unless($this->canEdit($request->user(), $merchandiseRequest), 403);

        $merchandiseRequest->load('lines');

        return view('merchandise-requests.edit', $this->formData($request, $merchandiseRequest));
    }

    public function update(UpdateMerchandiseRequestRequest $request, MerchandiseRequest $merchandiseRequest): RedirectResponse
    {
        abort_unless($this->canEdit($request->user(), $merchandiseRequest), 403);

        $validated = $request->validated();

        DB::transaction(function () use ($validated, $merchandiseRequest): void {
            $merchandiseRequest->update([
                'client_id' => $validated['client_id'],
                'delivery_reference' => $validated['delivery_reference'] ?? null,
                'delivery_address' => $validated['delivery_address'] ?? null,
                'requested_date' => $validated['requested_date'] ?? today()->toDateString(),
                'notes' => $validated['notes'] ?? null,
            ]);

            $this->syncLines($merchandiseRequest, $validated['lines']);
        });

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', 'Solicitud actualizada correctamente.');
    }

    public function cancel(Request $request, MerchandiseRequest $merchandiseRequest): RedirectResponse
    {
        abort_unless($this->canCancel($request->user(), $merchandiseRequest), 403);

        if (! $merchandiseRequest->isEditableByClient()) {
            return redirect()
                ->route('merchandise-requests.show', $merchandiseRequest)
                ->withErrors([
                    'merchandise_request' => 'La solicitud ya no puede cancelarse porque esta preparada, enviada o cerrada.',
                ]);
        }

        if ($merchandiseRequest->status !== MerchandiseRequest::STATUS_CANCELLED) {
            $merchandiseRequest->forceFill([
                'status' => MerchandiseRequest::STATUS_CANCELLED,
                'cancelled_at' => now(),
            ])->save();

            $merchandiseRequest->events()->create([
                'user_id' => $request->user()->id,
                'event_type' => 'cancelled',
                'title' => 'Pedido cancelado',
                'description' => 'La solicitud se ha cancelado antes de la preparacion.',
            ]);
        }

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', 'Solicitud cancelada correctamente.');
    }

    public function prepare(Request $request, MerchandiseRequest $merchandiseRequest): RedirectResponse
    {
        abort_unless($this->canPrepareOrShip($request->user()), 403);

        try {
            $this->preparationService->prepare($merchandiseRequest, $request->user());
        } catch (ValidationException $exception) {
            return redirect()
                ->route('merchandise-requests.show', $merchandiseRequest)
                ->withErrors($exception->errors());
        }

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', 'Solicitud preparada y stock descontado correctamente.');
    }

    public function ship(Request $request, MerchandiseRequest $merchandiseRequest): RedirectResponse
    {
        abort_unless($this->canPrepareOrShip($request->user()), 403);

        if ($merchandiseRequest->status === MerchandiseRequest::STATUS_SHIPPED) {
            return redirect()
                ->route('merchandise-requests.show', $merchandiseRequest)
                ->withErrors([
                    'merchandise_request' => 'La solicitud ya esta enviada.',
                ]);
        }

        if ($merchandiseRequest->status !== MerchandiseRequest::STATUS_PREPARED) {
            return redirect()
                ->route('merchandise-requests.show', $merchandiseRequest)
                ->withErrors([
                    'merchandise_request' => 'Solo se pueden enviar solicitudes ya preparadas.',
                ]);
        }

        $merchandiseRequest->forceFill([
            'status' => MerchandiseRequest::STATUS_SHIPPED,
            'shipped_by' => $request->user()->id,
            'shipped_at' => now(),
        ])->save();

        $merchandiseRequest->events()->create([
            'user_id' => $request->user()->id,
            'event_type' => 'shipped',
            'title' => 'Pedido enviado',
            'description' => 'La solicitud ya ha salido de almacen.',
        ]);

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', 'Solicitud marcada como enviada correctamente.');
    }

    /**
     * @return array<string, mixed>
     */
    private function formData(Request $request, MerchandiseRequest $merchandiseRequest): array
    {
        $user = $request->user();
        $items = Item::query()->where('active', true)->with('client')->orderBy('sku')->get();

        return [
            'merchandiseRequest' => $merchandiseRequest,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'items' => $items,
            'itemsCatalog' => $items->map(fn (Item $item): array => [
                'id' => $item->id,
                'client_id' => $item->client_id,
                'sku' => $item->sku,
                'description' => $item->description,
                'lot' => $item->lot,
                'units_per_pallet' => $item->units_per_pallet,
            ])->values()->all(),
            'lineValues' => $this->lineValues($request, $merchandiseRequest),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function lineValues(Request $request, MerchandiseRequest $merchandiseRequest): array
    {
        $oldLines = $request->old('lines');

        if (is_array($oldLines) && $oldLines !== []) {
            return array_values($oldLines);
        }

        if ($merchandiseRequest->exists) {
            return $merchandiseRequest->lines
                ->map(fn (MerchandiseRequestLine $line): array => [
                    'item_id' => $line->item_id,
                    'lot' => $line->lot,
                    'requested_pallets' => $line->requested_pallets,
                    'units_per_pallet' => $line->units_per_pallet,
                    'requested_units' => $line->requested_units,
                    'notes' => $line->notes,
                ])
                ->all();
        }

        return [[
            'item_id' => null,
            'lot' => null,
            'requested_pallets' => null,
            'units_per_pallet' => null,
            'requested_units' => null,
            'notes' => null,
        ]];
    }

    /**
     * @param  array<int, array<string, mixed>>  $lines
     */
    private function syncLines(MerchandiseRequest $merchandiseRequest, array $lines): void
    {
        $merchandiseRequest->lines()->delete();

        foreach ($lines as $line) {
            $merchandiseRequest->lines()->create([
                'item_id' => $line['item_id'],
                'lot' => $line['lot'] ?? null,
                'requested_pallets' => $line['requested_pallets'],
                'units_per_pallet' => $line['units_per_pallet'],
                'requested_units' => $line['requested_units'],
                'notes' => $line['notes'] ?? null,
            ]);
        }
    }

    private function canView($user, MerchandiseRequest $merchandiseRequest): bool
    {
        if ($user->hasRole(Role::CLIENTE)) {
            return (int) $user->client_id === (int) $merchandiseRequest->client_id;
        }

        return $user->canAccessRole(Role::ALMACEN);
    }

    private function canCreate($user): bool
    {
        if ($user->hasRole(Role::CLIENTE)) {
            return $user->client_id !== null;
        }

        return $user->canAccessRole(Role::ADMINISTRACION);
    }

    private function canEdit($user, MerchandiseRequest $merchandiseRequest): bool
    {
        if (! $merchandiseRequest->isEditableByClient()) {
            return false;
        }

        if ($user->hasRole(Role::CLIENTE)) {
            return (int) $user->client_id === (int) $merchandiseRequest->client_id;
        }

        return $user->canAccessRole(Role::ADMINISTRACION);
    }

    private function canCancel($user, MerchandiseRequest $merchandiseRequest): bool
    {
        if ($user->hasRole(Role::CLIENTE)) {
            return (int) $user->client_id === (int) $merchandiseRequest->client_id;
        }

        return $user->canAccessRole(Role::ADMINISTRACION);
    }

    private function canPrepareOrShip($user): bool
    {
        return $user->canAccessRole(Role::ALMACEN) && ! $user->hasRole(Role::CLIENTE);
    }
}
