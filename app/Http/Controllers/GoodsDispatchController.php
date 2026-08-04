<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmGoodsDispatchLoadingRequest;
use App\Http\Requests\StoreGoodsDispatchRequest;
use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestLine;
use App\Models\StockPallet;
use App\Services\Audit\AuditLogService;
use App\Services\GoodsDispatches\GoodsDispatchWorkflowService;
use App\Services\MerchandiseRequests\MerchandiseRequestFulfillmentService;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use App\Support\Stock\StockVariantCatalog;
use App\Support\WmsNavigation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class GoodsDispatchController extends Controller
{
    public function index(Request $request): View
    {
        $pendingRequests = MerchandiseRequest::query()
            ->with(['client', 'requestedBy', 'dispatch', 'openDispatch'])
            ->whereIn('status', [
                MerchandiseRequest::STATUS_PENDING,
                MerchandiseRequest::STATUS_PREPARING,
                MerchandiseRequest::STATUS_SENT,
                MerchandiseRequest::STATUS_PARTIALLY_FULFILLED,
            ])
            ->latest('id')
            ->limit(8)
            ->get();

        $dispatches = GoodsDispatch::query()
            ->with(['client', 'creator', 'merchandiseRequest'])
            ->latest('id')
            ->paginate(12)
            ->withQueryString();

        return view('dispatches.index', [
            'pendingRequests' => $pendingRequests,
            'dispatches' => $dispatches,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function create(Request $request, StockVariantCatalog $variantCatalog): View
    {
        return view('dispatches.create', [
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'selectedItems' => $variantCatalog->hydrateSelections(old('lines', old('quantities', []))),
            'searchEndpoint' => route('ajax.stock-variants'),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function store(StoreGoodsDispatchRequest $request): RedirectResponse
    {
        $validatedLines = $request->validatedLines();

        $dispatch = DB::transaction(function () use ($request, $validatedLines): GoodsDispatch {
            $dispatch = GoodsDispatch::query()->create([
                'client_id' => $request->integer('client_id'),
                'type' => GoodsDispatch::TYPE_MANUAL,
                'status' => GoodsDispatch::STATUS_DRAFT,
                'created_by' => $request->user()->id,
                'notes' => $request->input('notes'),
                'camion_propio' => $request->boolean('camion_propio'),
            ]);

            foreach ($validatedLines as $line) {
                $dispatch->lines()->create([
                    'item_id' => $line['item_id'],
                    'stock_pallet_id' => $line['stock_pallet_id'],
                    'line_type' => $line['line_type'],
                    'stock_peak_index' => $line['stock_peak_index'],
                    'sku' => $line['sku'],
                    'description' => $line['description'],
                    'lot' => $line['lot'],
                    'units_per_pallet' => $line['units_per_pallet'],
                    'units_per_peak' => $line['units_per_peak'],
                    'pallets' => $line['requested_pallets'],
                    'requested_units' => $line['requested_units'],
                    'requested_pallets' => $line['requested_pallets'],
                    'requested_peaks' => $line['requested_peaks'],
                ]);
            }

            return $dispatch;
        });

        return redirect()
            ->route('dispatches.show', $dispatch)
            ->with('status', 'Salida manual creada correctamente.');
    }

    public function pendingRequests(Request $request): View
    {
        $requests = MerchandiseRequest::query()
            ->with(['client', 'requestedBy', 'dispatch', 'openDispatch'])
            ->whereIn('status', [
                MerchandiseRequest::STATUS_PENDING,
                MerchandiseRequest::STATUS_PREPARING,
                MerchandiseRequest::STATUS_SENT,
                MerchandiseRequest::STATUS_PARTIALLY_FULFILLED,
            ])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('dispatches.pending-requests', [
            'requests' => $requests,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function showRequest(
        Request $request,
        MerchandiseRequest $merchandiseRequest,
        MerchandiseRequestFulfillmentService $fulfillmentService,
    ): View
    {
        $merchandiseRequest->load([
            'client',
            'requestedBy',
            'lines.item',
            'lines.stockPallet.location.warehouse',
            'dispatch.lines.item',
            'dispatch.lines.stockPallet.location.warehouse',
            'dispatch.lines.allocations.stockPallet.location.warehouse',
            'dispatch.lines.sourceRequestLine',
            'openDispatch.lines.item',
            'openDispatch.lines.stockPallet.location.warehouse',
            'openDispatch.lines.allocations.stockPallet.location.warehouse',
            'openDispatch.lines.sourceRequestLine',
            'goodsDispatches.lines.allocations',
            'goodsDispatches.lines.sourceRequestLine',
        ]);

        $activeDispatch = $merchandiseRequest->openDispatch ?? $merchandiseRequest->dispatch;

        return view('dispatches.request', [
            'merchandiseRequest' => $merchandiseRequest,
            'activeDispatch' => $activeDispatch,
            'fulfillmentSummary' => $fulfillmentService->summary($merchandiseRequest, $activeDispatch),
            'stockOptionsByItem' => $this->stockOptionsByItem($merchandiseRequest),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function generateFromRequest(
        Request $request,
        MerchandiseRequest $merchandiseRequest,
        MerchandiseRequestFulfillmentService $fulfillmentService,
        MerchandiseRequestNotificationService $notificationService,
    ): RedirectResponse {
        $merchandiseRequest->load(['lines.item', 'lines.stockPallet', 'dispatch', 'openDispatch', 'goodsDispatches.lines.allocations']);

        if ($merchandiseRequest->openDispatch !== null) {
            if ($request->boolean('return_to_request')) {
                return redirect()
                    ->route('dispatches.requests.show', $merchandiseRequest)
                    ->with('status', 'El pedido ya tiene una carga abierta.');
            }

            return redirect()
                ->route('dispatches.show', $merchandiseRequest->openDispatch)
                ->with('status', 'La solicitud ya tiene una carga abierta.');
        }

        if (in_array($merchandiseRequest->status, [MerchandiseRequest::STATUS_DRAFT, MerchandiseRequest::STATUS_COMPLETED, MerchandiseRequest::STATUS_CANCELLED], true)) {
            return redirect()
                ->route('dispatches.requests.show', $merchandiseRequest)
                ->withErrors(['dispatch' => 'No se puede generar una carga para un pedido en borrador, completado o cancelado.']);
        }

        $dispatch = DB::transaction(function () use ($request, $merchandiseRequest, $fulfillmentService): GoodsDispatch {
            $lockedRequest = MerchandiseRequest::query()
                ->whereKey($merchandiseRequest->id)
                ->lockForUpdate()
                ->firstOrFail();
            $lockedRequest->load(['lines.item', 'lines.stockPallet', 'openDispatch', 'goodsDispatches.lines.allocations']);

            if ($lockedRequest->openDispatch !== null) {
                return $lockedRequest->openDispatch;
            }

            $pendingLines = $fulfillmentService->pendingLinesForNextDispatch($lockedRequest);

            if ($pendingLines->isEmpty()) {
                throw ValidationException::withMessages([
                    'dispatch' => 'El pedido no tiene cantidades pendientes para preparar otra carga.',
                ]);
            }

            $nextSequence = ((int) $lockedRequest->goodsDispatches()->max('shipment_sequence')) + 1;
            $dispatch = GoodsDispatch::query()->create([
                'client_id' => $lockedRequest->client_id,
                'merchandise_request_id' => $lockedRequest->id,
                'shipment_sequence' => $nextSequence,
                'type' => GoodsDispatch::TYPE_REQUEST,
                'status' => GoodsDispatch::STATUS_PREPARING,
                'created_by' => $request->user()->id,
                'notes' => $lockedRequest->notes,
                'camion_propio' => true,
            ]);

            foreach ($pendingLines as $pendingLine) {
                /** @var MerchandiseRequestLine $line */
                $line = $pendingLine['request_line'];
                $pendingUnits = (int) $pendingLine['pending_units'];
                $requestedPallets = 0;
                $requestedPeaks = 0;

                if ($line->requiredUnits() === null && $line->isPalletLine()) {
                    $unitsPerPallet = max(1, (int) $line->units_per_pallet);
                    $requestedPallets = min($line->requestedPalletsCount(), (int) ceil($pendingUnits / $unitsPerPallet));
                }

                if ($line->requiredUnits() === null && $line->isPeakLine()) {
                    $unitsPerPeak = max(1, (int) $line->units_per_peak);
                    $requestedPeaks = min($line->requestedPeaksCount(), (int) ceil($pendingUnits / $unitsPerPeak));
                }

                $dispatch->lines()->create([
                    'item_id' => $line->item_id,
                    'stock_pallet_id' => $line->stock_pallet_id,
                    'line_type' => $line->lineType(),
                    'stock_peak_index' => $line->stock_peak_index,
                    'sku' => $line->item?->sku ?? 'Articulo',
                    'description' => $line->item?->description ?? 'Sin descripcion',
                    'lot' => $line->lot,
                    'destination_location' => $line->destination_location,
                    'units_per_pallet' => $line->units_per_pallet,
                    'units_per_peak' => $line->units_per_peak,
                    'pallets' => $requestedPallets,
                    'requested_units' => $pendingUnits,
                    'requested_pallets' => $requestedPallets,
                    'requested_peaks' => $requestedPeaks,
                    'source_request_line_id' => $line->id,
                    'fill_truck' => $line->fill_truck,
                    'notes' => $line->notes,
                ]);
            }

            $lockedRequest->update([
                'status' => MerchandiseRequest::STATUS_PREPARING,
                'prepared_by' => $request->user()->id,
                'prepared_at' => $lockedRequest->prepared_at ?? now(),
            ]);

            return $dispatch;
        });

        $notificationService->notifyStatusChanged($merchandiseRequest, MerchandiseRequest::STATUS_PENDING);

        if ($request->boolean('return_to_request')) {
            return redirect()
                ->route('dispatches.requests.show', $merchandiseRequest)
                ->with('status', 'Salida preparada. Confirma ahora la carga real.');
        }

        return redirect()
            ->route('dispatches.show', $dispatch)
            ->with('status', 'Salida generada desde la solicitud correctamente.');
    }

    public function show(Request $request, GoodsDispatch $goodsDispatch): View
    {
        $goodsDispatch->load([
            'client',
            'creator',
            'merchandiseRequest.lines.item',
            'merchandiseRequest.lines.stockPallet.location.warehouse',
            'merchandiseRequest',
            'lines.item',
            'lines.stockPallet.location.warehouse',
            'lines.allocations.stockPallet.location.warehouse',
            'lines.sourceRequestLine',
        ]);

        return view('dispatches.show', [
            'dispatch' => $goodsDispatch,
            'searchEndpoint' => route('ajax.stock-variants'),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function confirmLoading(
        ConfirmGoodsDispatchLoadingRequest $request,
        GoodsDispatch $goodsDispatch,
        GoodsDispatchWorkflowService $workflowService,
    ): RedirectResponse {
        if ($request->has('camion_propio')) {
            $goodsDispatch->update([
                'camion_propio' => $request->boolean('camion_propio'),
            ]);
        }

        $workflowService->confirmLoading($goodsDispatch, $request->validatedLines(), $request->user());

        if ($request->boolean('finalize_dispatch')) {
            $workflowService->changeStatus(
                $goodsDispatch->fresh(),
                GoodsDispatch::STATUS_SENT,
                $request->user(),
                $request->input('completion_mode'),
                $request->input('remainder_close_reason'),
            );

            $redirectRoute = $goodsDispatch->merchandise_request_id !== null
                ? route('dispatches.requests.show', $goodsDispatch->merchandiseRequest)
                : route('dispatches.show', $goodsDispatch->fresh());

            return redirect($redirectRoute)
                ->with('status', 'Pedido enviado correctamente. El albarán está disponible para abrirlo en una pestaña nueva.');
        }

        if ($request->boolean('return_to_request') && $goodsDispatch->merchandise_request_id !== null) {
            return redirect()
                ->route('dispatches.requests.show', $goodsDispatch->merchandiseRequest)
                ->with('status', 'Preparación guardada correctamente.');
        }

        return redirect()
            ->route('dispatches.show', $goodsDispatch)
            ->with('status', 'Carga confirmada correctamente con las cantidades reales.');
    }

    public function updateStatus(
        Request $request,
        GoodsDispatch $goodsDispatch,
        GoodsDispatchWorkflowService $workflowService,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in(GoodsDispatch::statuses())],
        ]);

        if ($goodsDispatch->status === $validated['status']) {
            return back()->with('status', 'La salida ya estaba en ese estado.');
        }

        $warning = $workflowService->changeStatus($goodsDispatch, $validated['status'], $request->user());

        $statusMessage = $validated['status'] === GoodsDispatch::STATUS_SENT
            ? 'Salida enviada y stock actualizado correctamente.'
            : 'Estado de salida actualizado correctamente.';

        $response = redirect()
            ->route('dispatches.show', $goodsDispatch->fresh())
            ->with('status', $statusMessage);

        if ($warning !== null) {
            $response->with('warning', $warning);
        }

        return $response;
    }

    public function updateOwnTruck(Request $request, GoodsDispatch $goodsDispatch): RedirectResponse
    {
        $validated = $request->validate([
            'camion_propio' => ['boolean'],
        ]);

        $goodsDispatch->update([
            'camion_propio' => (bool) ($validated['camion_propio'] ?? false),
        ]);

        return redirect()
            ->route('dispatches.show', $goodsDispatch)
            ->with('status', 'Transporte actualizado correctamente.');
    }

    public function deliveryNotePdf(
        Request $request,
        GoodsDispatch $goodsDispatch,
        GoodsDispatchWorkflowService $workflowService,
        AuditLogService $audit,
    ) {
        $goodsDispatch->load(['client', 'merchandiseRequest', 'lines.item', 'lines.stockPallet', 'lines.allocations', 'lines.sourceRequestLine']);

        abort_unless(
            in_array($goodsDispatch->status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true),
            403
        );

        $workflowService->ensureDeliveryNoteCanBeGenerated($goodsDispatch);
        $audit->record(event: 'dispatch_delivery_note_generated', module: 'dispatches', description: 'Albaran de salida generado.', auditable: $goodsDispatch, user: $request->user(), clientId: $goodsDispatch->client_id);

        return Pdf::loadView('dispatches.delivery-note-pdf', [
            'dispatch' => $goodsDispatch,
        ])->stream($goodsDispatch->dispatchNumber().'.pdf');
    }

    /**
     * @return array<int, Collection<int, StockPallet>>
     */
    private function stockOptionsByItem(MerchandiseRequest $merchandiseRequest): array
    {
        $itemIds = $merchandiseRequest->lines
            ->pluck('item_id')
            ->filter()
            ->map(fn ($itemId) => (int) $itemId)
            ->unique()
            ->values()
            ->all();

        if ($itemIds === []) {
            return [];
        }

        return StockPallet::query()
            ->with('location.warehouse')
            ->where('client_id', $merchandiseRequest->client_id)
            ->whereIn('item_id', $itemIds)
            ->where('active', true)
            ->where('status', StockPallet::STATUS_AVAILABLE)
            ->where(function ($query): void {
                $query
                    ->where('quantity_units', '>', 0)
                    ->orWhere('full_pallets', '>', 0)
                    ->orWhere('peaks_count', '>', 0)
                    ->orWhere('warehouse_pallets', '>', 0);
            })
            ->orderBy('received_at')
            ->orderBy('lot')
            ->orderBy('id')
            ->get()
            ->groupBy('item_id')
            ->all();
    }
}
