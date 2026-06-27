<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmGoodsDispatchLoadingRequest;
use App\Http\Requests\StoreGoodsDispatchRequest;
use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Services\GoodsDispatches\GoodsDispatchWorkflowService;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use App\Support\WmsNavigation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class GoodsDispatchController extends Controller
{
    public function index(Request $request): View
    {
        $pendingRequests = MerchandiseRequest::query()
            ->with(['client', 'requestedBy', 'dispatch'])
            ->whereIn('status', [
                MerchandiseRequest::STATUS_PENDING,
                MerchandiseRequest::STATUS_PREPARING,
                MerchandiseRequest::STATUS_SENT,
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

    public function create(Request $request): View
    {
        $oldQuantities = collect(old('quantities', []))
            ->filter(fn ($quantity, $itemId) => is_numeric((string) $itemId) && (int) $quantity > 0);

        return view('dispatches.create', [
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'selectedItems' => Item::query()
                ->whereIn('id', $oldQuantities->keys()->map(fn ($itemId) => (int) $itemId))
                ->orderBy('sku')
                ->get()
                ->map(fn (Item $item): array => [
                    'id' => $item->id,
                    'label' => $item->sku.' - '.$item->description,
                    'value' => $item->sku,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'units_per_pallet' => $item->units_per_pallet,
                    'client_id' => $item->client_id,
                    'requested_pallets' => (int) $oldQuantities->get((string) $item->id, 0),
                ])
                ->values()
                ->all(),
            'searchEndpoint' => route('ajax.items'),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function store(StoreGoodsDispatchRequest $request): RedirectResponse
    {
        $validatedLines = $request->validatedLines();
        $items = Item::query()
            ->whereIn('id', array_column($validatedLines, 'item_id'))
            ->get()
            ->keyBy('id');

        $dispatch = DB::transaction(function () use ($request, $validatedLines, $items): GoodsDispatch {
            $dispatch = GoodsDispatch::query()->create([
                'client_id' => $request->integer('client_id'),
                'type' => GoodsDispatch::TYPE_MANUAL,
                'status' => GoodsDispatch::STATUS_DRAFT,
                'created_by' => $request->user()->id,
                'notes' => $request->input('notes'),
            ]);

            foreach ($validatedLines as $line) {
                $item = $items->get($line['item_id']);

                if ($item === null) {
                    continue;
                }

                $dispatch->lines()->create([
                    'item_id' => $item->id,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'lot' => null,
                    'units_per_pallet' => $item->units_per_pallet,
                    'pallets' => $line['pallets'],
                    'requested_units' => $line['pallets'] * $item->units_per_pallet,
                    'requested_pallets' => $line['pallets'],
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
            ->with(['client', 'requestedBy', 'dispatch'])
            ->whereIn('status', [
                MerchandiseRequest::STATUS_PENDING,
                MerchandiseRequest::STATUS_PREPARING,
                MerchandiseRequest::STATUS_SENT,
            ])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('dispatches.pending-requests', [
            'requests' => $requests,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function showRequest(Request $request, MerchandiseRequest $merchandiseRequest): View
    {
        $merchandiseRequest->load(['client', 'requestedBy', 'lines.item', 'dispatch.lines.item', 'dispatch.lines.sourceRequestLine']);

        return view('dispatches.request', [
            'merchandiseRequest' => $merchandiseRequest,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function generateFromRequest(
        Request $request,
        MerchandiseRequest $merchandiseRequest,
        MerchandiseRequestNotificationService $notificationService,
    ): RedirectResponse {
        $merchandiseRequest->load(['lines.item', 'dispatch']);

        if ($merchandiseRequest->dispatch !== null) {
            return redirect()
                ->route('dispatches.show', $merchandiseRequest->dispatch)
                ->with('status', 'La solicitud ya tiene una salida asociada.');
        }

        $dispatch = DB::transaction(function () use ($request, $merchandiseRequest): GoodsDispatch {
            $dispatch = GoodsDispatch::query()->create([
                'client_id' => $merchandiseRequest->client_id,
                'merchandise_request_id' => $merchandiseRequest->id,
                'type' => GoodsDispatch::TYPE_REQUEST,
                'status' => GoodsDispatch::STATUS_PREPARING,
                'created_by' => $request->user()->id,
                'notes' => $merchandiseRequest->notes,
            ]);

            foreach ($merchandiseRequest->lines as $line) {
                $dispatch->lines()->create([
                    'item_id' => $line->item_id,
                    'sku' => $line->item?->sku ?? 'Articulo',
                    'description' => $line->item?->description ?? 'Sin descripcion',
                    'lot' => $line->lot,
                    'units_per_pallet' => $line->units_per_pallet,
                    'pallets' => $line->requested_pallets,
                    'requested_units' => $line->requested_units,
                    'requested_pallets' => $line->requested_pallets,
                    'source_request_line_id' => $line->id,
                    'notes' => $line->notes,
                ]);
            }

            $merchandiseRequest->update([
                'status' => MerchandiseRequest::STATUS_PREPARING,
                'prepared_by' => $request->user()->id,
                'prepared_at' => now(),
            ]);

            return $dispatch;
        });

        $notificationService->notifyStatusChanged($merchandiseRequest, MerchandiseRequest::STATUS_PENDING);

        return redirect()
            ->route('dispatches.show', $dispatch)
            ->with('status', 'Salida generada desde la solicitud correctamente.');
    }

    public function show(Request $request, GoodsDispatch $goodsDispatch): View
    {
        $goodsDispatch->load(['client', 'creator', 'merchandiseRequest.lines.item', 'merchandiseRequest', 'lines.item', 'lines.sourceRequestLine']);

        return view('dispatches.show', [
            'dispatch' => $goodsDispatch,
            'searchEndpoint' => route('ajax.items'),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function confirmLoading(
        ConfirmGoodsDispatchLoadingRequest $request,
        GoodsDispatch $goodsDispatch,
        GoodsDispatchWorkflowService $workflowService,
    ): RedirectResponse {
        $workflowService->confirmLoading($goodsDispatch, $request->validated('lines'), $request->user());

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

        $response = redirect()
            ->route('dispatches.show', $goodsDispatch->fresh())
            ->with('status', 'Estado de salida actualizado correctamente.');

        if ($warning !== null) {
            $response->with('warning', $warning);
        }

        return $response;
    }

    public function deliveryNotePdf(
        Request $request,
        GoodsDispatch $goodsDispatch,
        GoodsDispatchWorkflowService $workflowService,
    ) {
        $goodsDispatch->load(['client', 'merchandiseRequest', 'lines.item']);

        abort_unless(
            in_array($goodsDispatch->status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true),
            403
        );

        $workflowService->ensureDeliveryNoteCanBeGenerated($goodsDispatch);

        return Pdf::loadView('dispatches.delivery-note-pdf', [
            'dispatch' => $goodsDispatch,
        ])->stream($goodsDispatch->dispatchNumber().'.pdf');
    }
}
