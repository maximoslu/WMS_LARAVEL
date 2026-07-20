<?php

namespace App\Http\Controllers;

use App\Http\Requests\AddMerchandiseRequestLineRequest;
use App\Http\Requests\StoreMerchandiseRequestRequest;
use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestLine;
use App\Models\Role;
use App\Services\Audit\AuditLogService;
use App\Services\GoodsDispatches\GoodsDispatchWorkflowService;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use App\Services\MerchandiseRequests\MerchandiseRequestScheduleService;
use App\Support\Stock\StockVariantCatalog;
use App\Support\WmsNavigation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MerchandiseRequestController extends Controller
{
    public function index(Request $request): View
    {
        $user = $request->user();
        $isClient = $user->hasRole(Role::CLIENTE);
        $status = (string) $request->string('status', 'all');
        $search = trim((string) $request->string('search'));
        $clientId = $request->integer('client_id');

        $requests = MerchandiseRequest::query()
            ->with(['client', 'requestedBy', 'lines.item', 'dispatch'])
            ->when($isClient, fn (Builder $query) => $query->where('client_id', $user->client_id))
            ->when(! $isClient && $clientId > 0, fn (Builder $query) => $query->where('client_id', $clientId))
            ->when($status !== 'all' && in_array($status, MerchandiseRequest::statuses(), true), fn (Builder $query) => $query->where('status', $status))
            ->when($search !== '', function (Builder $query) use ($search): void {
                $query->where(function (Builder $query) use ($search): void {
                    $normalizedCode = preg_replace('/\D+/', '', $search);

                    if (Schema::hasColumn('merchandise_requests', 'request_code')) {
                        $query->where('request_code', 'like', '%'.$search.'%');
                    } elseif ($normalizedCode !== '') {
                        $query->whereKey((int) $normalizedCode);
                    }

                    $query->orWhereHas('lines', function (Builder $query) use ($search): void {
                        $query
                            ->where('lot', 'like', '%'.$search.'%')
                            ->orWhereHas('item', function (Builder $query) use ($search): void {
                                $query
                                    ->where('sku', 'like', '%'.$search.'%')
                                    ->orWhere('description', 'like', '%'.$search.'%');
                            });
                    });
                });
            })
            ->when(
                Schema::hasColumn('merchandise_requests', 'submitted_at'),
                fn (Builder $query) => $query->latest('submitted_at'),
                fn (Builder $query) => $query->latest('requested_date')
            )
            ->latest()
            ->paginate(12)
            ->withQueryString();

        return view('merchandise-requests.index', [
            'requests' => $requests,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'filters' => [
                'status' => in_array($status, [...MerchandiseRequest::statuses(), 'all'], true) ? $status : 'all',
                'search' => $search,
                'client_id' => $clientId > 0 ? $clientId : null,
            ],
            'isClient' => $isClient,
            'canCreate' => ($user->hasRole(Role::CLIENTE) && $user->client_id !== null) || $user->canAccessRole(Role::ALMACEN),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function create(
        Request $request,
        StockVariantCatalog $variantCatalog,
        MerchandiseRequestScheduleService $scheduleService,
    ): View {
        $user = $request->user();

        abort_unless(
            ($user->hasRole(Role::CLIENTE) && $user->client_id !== null) || $user->canAccessRole(Role::ALMACEN),
            403
        );

        $canChooseClient = ! $user->hasRole(Role::CLIENTE) && $user->canAccessRole(Role::ALMACEN);
        $selectedClientId = $user->hasRole(Role::CLIENTE)
            ? (int) $user->client_id
            : $request->integer('client_id');
        $selectedClient = $selectedClientId > 0
            ? Client::query()->where('active', true)->find($selectedClientId)
            : null;

        if ($selectedClientId > 0 && ! $selectedClient instanceof Client) {
            abort(404);
        }

        $pendingRequests = MerchandiseRequest::query()
            ->with(['lines', 'dispatch'])
            ->when($selectedClient instanceof Client, fn (Builder $query) => $query->where('client_id', $selectedClient->id))
            ->when(! ($selectedClient instanceof Client), fn (Builder $query) => $query->whereRaw('1 = 0'))
            ->whereIn('status', [
                MerchandiseRequest::STATUS_PENDING,
                MerchandiseRequest::STATUS_PREPARING,
            ])
            ->latest('id')
            ->get();

        return view('merchandise-requests.create', [
            'hasActiveItems' => Item::query()
                ->when($selectedClient instanceof Client, fn (Builder $query) => $query->where('client_id', $selectedClient->id))
                ->when(! ($selectedClient instanceof Client), fn (Builder $query) => $query->whereRaw('1 = 0'))
                ->where('active', true)
                ->exists(),
            'selectedItems' => $selectedClient instanceof Client
                ? $variantCatalog->hydrateSelections(old('lines', old('quantities', [])), (int) $selectedClient->id)
                : [],
            'client' => $selectedClient,
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'canChooseClient' => $canChooseClient,
            'selectedClientId' => $selectedClient?->id,
            'searchEndpoint' => route('merchandise-requests.items.search'),
            'contractualWindowWarning' => $scheduleService->preSubmissionWarning(),
            'pendingRequests' => $pendingRequests,
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function searchItems(Request $request, StockVariantCatalog $variantCatalog): JsonResponse
    {
        $user = $request->user();
        abort_unless(
            ($user->hasRole(Role::CLIENTE) && $user->client_id !== null) || $user->canAccessRole(Role::ALMACEN),
            403
        );

        $clientId = $user->hasRole(Role::CLIENTE)
            ? (int) $user->client_id
            : $request->integer('client_id');

        if ($clientId <= 0 || ! Client::query()->whereKey($clientId)->where('active', true)->exists()) {
            return response()->json([
                'data' => [],
            ]);
        }

        $search = trim((string) ($request->string('search')->value() ?: $request->string('q')->value()));

        if (mb_strlen($search) < 2) {
            return response()->json([
                'data' => [],
            ]);
        }

        return response()->json([
            'data' => $variantCatalog->search($search, $clientId, 15, true),
        ], 200, [], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public function store(
        StoreMerchandiseRequestRequest $request,
        MerchandiseRequestScheduleService $scheduleService,
        MerchandiseRequestNotificationService $notificationService,
        AuditLogService $audit,
    ): RedirectResponse {
        $user = $request->user();
        $requestedLines = $request->validatedLines();
        $clientId = $request->effectiveClientId();

        $merchandiseRequest = DB::transaction(function () use ($request, $requestedLines, $user, $clientId, $audit): MerchandiseRequest {
            $requestModel = MerchandiseRequest::query()->create([
                'client_id' => $clientId,
                'requested_by' => $user->id,
                'status' => MerchandiseRequest::STATUS_PENDING,
                'requested_date' => now()->toDateString(),
                'camion_propio' => $request->boolean('camion_propio'),
            ]);

            foreach ($requestedLines as $line) {
                $requestModel->lines()->create([
                    'item_id' => $line['item_id'],
                    'stock_pallet_id' => $line['stock_pallet_id'],
                    'line_type' => $line['line_type'],
                    'stock_peak_index' => $line['stock_peak_index'],
                    'lot' => $line['lot'],
                    'destination_location' => $line['destination_location'],
                    'units_per_pallet' => $line['units_per_pallet'],
                    'units_per_peak' => $line['units_per_peak'],
                    'requested_pallets' => $line['requested_pallets'],
                    'requested_peaks' => $line['requested_peaks'],
                    'requested_units' => $line['requested_units'],
                ]);
            }

            $audit->record(
                event: 'merchandise_request_created',
                module: 'merchandise_requests',
                description: 'Pedido de mercancia creado.',
                auditable: $requestModel,
                user: $user,
                clientId: $clientId,
                newValues: [
                    'status' => $requestModel->status,
                    'line_count' => count($requestedLines),
                    'requested_pallets' => collect($requestedLines)->sum('requested_pallets'),
                    'requested_peaks' => collect($requestedLines)->sum('requested_peaks'),
                    'requested_units' => collect($requestedLines)->sum('requested_units'),
                ],
            );

            return $requestModel;
        });

        $notificationService->notifySubmitted($merchandiseRequest);

        $response = redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', 'Solicitud registrada y notificada correctamente.');

        $outsideWindowWarning = $scheduleService->postSubmissionWarning($merchandiseRequest->submittedAt());

        if ($outsideWindowWarning !== null) {
            $response
                ->with('warning', $outsideWindowWarning)
                ->with('scheduleWarning', true);
        }

        return $response;
    }

    public function show(Request $request, MerchandiseRequest $merchandiseRequest): View
    {
        $user = $request->user();

        if ($user->hasRole(Role::CLIENTE)) {
            abort_unless((int) $user->client_id === (int) $merchandiseRequest->client_id, 403);
        } else {
            abort_unless($user->canAccessRole(Role::ALMACEN), 403);
        }

        $merchandiseRequest->load([
                'client',
                'requestedBy',
                'lines.item',
                'lines.stockPallet.location.warehouse',
                'dispatch.lines.item',
                'dispatch.lines.stockPallet.location.warehouse',
                'dispatch.lines.allocations.stockPallet.location.warehouse',
                'dispatch.lines.sourceRequestLine',
            ]);
        $canAddInternalLine = ! $user->hasRole(Role::CLIENTE)
            && $user->canAccessRole(Role::ALMACEN)
            && $this->canAcceptInternalLines($merchandiseRequest);

        return view('merchandise-requests.show', [
            'merchandiseRequest' => $merchandiseRequest,
            'isClient' => $user->hasRole(Role::CLIENTE),
            'canAddInternalLine' => $canAddInternalLine,
            'selectedItems' => $canAddInternalLine
                ? app(StockVariantCatalog::class)->hydrateSelections(old('lines', []), (int) $merchandiseRequest->client_id)
                : [],
            'searchEndpoint' => route('merchandise-requests.items.search'),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function storeLine(
        AddMerchandiseRequestLineRequest $request,
        MerchandiseRequest $merchandiseRequest,
        AuditLogService $audit,
    ): RedirectResponse {
        if (! $this->canAcceptInternalLines($merchandiseRequest)) {
            return redirect()
                ->route('merchandise-requests.show', $merchandiseRequest)
                ->withErrors([
                    'lines' => 'No se pueden anadir lineas a un pedido enviado, completado o cancelado.',
                ]);
        }

        $requestedLines = $request->validatedLines();

        DB::transaction(function () use ($request, $merchandiseRequest, $requestedLines, $audit): void {
            $lockedRequest = MerchandiseRequest::query()
                ->whereKey($merchandiseRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRequest->load(['dispatch', 'lines']);

            if (! $this->canAcceptInternalLines($lockedRequest)) {
                throw ValidationException::withMessages([
                    'lines' => 'No se pueden anadir lineas a un pedido enviado, completado o cancelado.',
                ]);
            }

            $createdLines = collect();

            foreach ($requestedLines as $line) {
                $createdLine = $lockedRequest->lines()->create([
                    'item_id' => $line['item_id'],
                    'stock_pallet_id' => $line['stock_pallet_id'],
                    'line_type' => $line['line_type'],
                    'stock_peak_index' => $line['stock_peak_index'],
                    'lot' => $line['lot'],
                    'destination_location' => $line['destination_location'],
                    'units_per_pallet' => $line['units_per_pallet'],
                    'units_per_peak' => $line['units_per_peak'],
                    'requested_pallets' => $line['requested_pallets'],
                    'requested_peaks' => $line['requested_peaks'],
                    'requested_units' => $line['requested_units'],
                ]);

                $createdLines->push($createdLine);
                $this->syncPendingDispatchLine($lockedRequest->dispatch, $createdLine, $line);
            }

            $audit->record(
                event: 'merchandise_request_line_added',
                module: 'merchandise_requests',
                description: 'Linea anadida a pedido existente por usuario interno.',
                auditable: $lockedRequest,
                user: $request->user(),
                clientId: $lockedRequest->client_id,
                newValues: [
                    'line_count' => $createdLines->count(),
                    'requested_pallets' => collect($requestedLines)->sum('requested_pallets'),
                    'requested_peaks' => collect($requestedLines)->sum('requested_peaks'),
                    'requested_units' => collect($requestedLines)->sum('requested_units'),
                    'dispatch_synced' => $lockedRequest->dispatch instanceof GoodsDispatch
                        && in_array($lockedRequest->dispatch->status, [GoodsDispatch::STATUS_DRAFT, GoodsDispatch::STATUS_PREPARING], true),
                ],
            );
        });

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', 'Linea anadida al pedido. No se descuenta stock hasta la confirmacion de carga.');
    }

    public function updateStatus(
        Request $request,
        MerchandiseRequest $merchandiseRequest,
        GoodsDispatchWorkflowService $workflowService,
        MerchandiseRequestNotificationService $notificationService,
        AuditLogService $audit,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in(MerchandiseRequest::statuses())],
        ]);

        $previousStatus = $merchandiseRequest->status;

        if ($previousStatus === $validated['status']) {
            return back()->with('status', 'La solicitud ya estaba en ese estado.');
        }

        if ($merchandiseRequest->dispatch !== null) {
            $warning = $workflowService->changeStatus($merchandiseRequest->dispatch, $validated['status'], $request->user());

            $response = redirect()
                ->route('merchandise-requests.show', $merchandiseRequest)
                ->with('status', 'Estado de la solicitud actualizado correctamente.');

            if ($warning !== null) {
                $response->with('warning', $warning);
            }

            return $response;
        }

        $payload = [
            'status' => $validated['status'],
        ];

        if ($validated['status'] === MerchandiseRequest::STATUS_PREPARING) {
            $payload['prepared_by'] = $request->user()->id;
            $payload['prepared_at'] = $merchandiseRequest->prepared_at ?? now();
        }

        if ($validated['status'] === MerchandiseRequest::STATUS_CANCELLED) {
            $payload['cancelled_at'] = $merchandiseRequest->cancelled_at ?? now();
        }

        if (in_array($validated['status'], [MerchandiseRequest::STATUS_SENT, MerchandiseRequest::STATUS_COMPLETED], true)) {
            return redirect()
                ->route('merchandise-requests.show', $merchandiseRequest)
                ->withErrors([
                    'status' => 'Debes generar primero una salida y confirmar la carga real antes de marcar este pedido como enviado o completado.',
                ]);
        }

        DB::transaction(function () use ($merchandiseRequest, $payload, $previousStatus, $validated, $request, $audit): void {
            $merchandiseRequest->update($payload);
            $audit->record(
                event: 'merchandise_request_status_changed',
                module: 'merchandise_requests',
                description: "Estado de pedido cambiado de {$previousStatus} a {$validated['status']}.",
                auditable: $merchandiseRequest,
                user: $request->user(),
                clientId: $merchandiseRequest->client_id,
                oldValues: ['status' => $previousStatus],
                newValues: ['status' => $validated['status']],
                severity: $validated['status'] === MerchandiseRequest::STATUS_CANCELLED ? 'warning' : 'info',
            );
        });

        $notificationService->notifyStatusChanged($merchandiseRequest, $previousStatus);

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', 'Estado de la solicitud actualizado correctamente.');
    }

    public function preparationPdf(
        Request $request,
        MerchandiseRequest $merchandiseRequest,
        AuditLogService $audit,
    ) {
        abort_unless($request->user()->canAccessRole(Role::ALMACEN), 403);

        $merchandiseRequest->load([
            'client',
            'requestedBy',
            'lines.item',
            'lines.stockPallet.location.warehouse',
            'dispatch.lines.stockPallet.location.warehouse',
            'dispatch.lines.allocations.stockPallet.location.warehouse',
            'dispatch.lines.sourceRequestLine',
        ]);
        $audit->record(
            event: 'merchandise_request_preparation_pdf_generated',
            module: 'merchandise_requests',
            description: 'Hoja de preparacion del pedido generada.',
            auditable: $merchandiseRequest,
            user: $request->user(),
            clientId: $merchandiseRequest->client_id,
        );

        return Pdf::loadView('merchandise-requests.preparation-pdf', [
            'merchandiseRequest' => $merchandiseRequest,
        ])->stream($merchandiseRequest->referenceCode().'-preparacion.pdf');
    }

    private function canAcceptInternalLines(MerchandiseRequest $merchandiseRequest): bool
    {
        return in_array($merchandiseRequest->status, [
            MerchandiseRequest::STATUS_PENDING,
            MerchandiseRequest::STATUS_PREPARING,
        ], true)
            && ! in_array($merchandiseRequest->dispatch?->status, [
                GoodsDispatch::STATUS_SENT,
                GoodsDispatch::STATUS_COMPLETED,
                GoodsDispatch::STATUS_CANCELLED,
            ], true);
    }

    /**
     * @param  array{
     *     item_id:int,
     *     sku:string,
     *     description:string,
     *     stock_pallet_id:int|null,
     *     line_type:string,
     *     stock_peak_index:int|null,
     *     lot:string|null,
     *     destination_location:string|null,
     *     units_per_pallet:int,
     *     units_per_peak:int|null,
     *     requested_pallets:int,
     *     requested_peaks:int,
     *     requested_units:int
     * }  $resolvedLine
     */
    private function syncPendingDispatchLine(?GoodsDispatch $dispatch, MerchandiseRequestLine $requestLine, array $resolvedLine): void
    {
        if (! $dispatch instanceof GoodsDispatch) {
            return;
        }

        if (! in_array($dispatch->status, [GoodsDispatch::STATUS_DRAFT, GoodsDispatch::STATUS_PREPARING], true)) {
            return;
        }

        $dispatch->lines()->create([
            'item_id' => $resolvedLine['item_id'],
            'stock_pallet_id' => $resolvedLine['stock_pallet_id'],
            'line_type' => $resolvedLine['line_type'],
            'stock_peak_index' => $resolvedLine['stock_peak_index'],
            'sku' => $resolvedLine['sku'],
            'description' => $resolvedLine['description'],
            'lot' => $resolvedLine['lot'],
            'destination_location' => $resolvedLine['destination_location'],
            'units_per_pallet' => $resolvedLine['units_per_pallet'],
            'units_per_peak' => $resolvedLine['units_per_peak'],
            'pallets' => $requestLine->requestedPalletsCount(),
            'requested_units' => $resolvedLine['requested_units'],
            'requested_pallets' => $requestLine->requestedPalletsCount(),
            'requested_peaks' => $requestLine->requestedPeaksCount(),
            'source_request_line_id' => $requestLine->id,
            'notes' => $requestLine->notes,
        ]);
    }
}
