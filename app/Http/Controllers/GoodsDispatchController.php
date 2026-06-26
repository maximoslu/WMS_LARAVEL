<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreGoodsDispatchRequest;
use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\MerchandiseRequest;
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
        return view('dispatches.create', [
            'clients' => Client::query()->where('active', true)->orderBy('name')->get(),
            'items' => Item::query()->where('active', true)->with('client')->orderBy('sku')->get(),
            'itemsCatalog' => Item::query()
                ->where('active', true)
                ->orderBy('sku')
                ->get()
                ->map(fn (Item $item): array => [
                    'id' => $item->id,
                    'client_id' => $item->client_id,
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'lot' => $item->lot,
                    'units_per_pallet' => $item->units_per_pallet,
                ])
                ->values()
                ->all(),
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
                    'lot' => $item->lot,
                    'units_per_pallet' => $item->units_per_pallet,
                    'pallets' => $line['pallets'],
                    'requested_units' => $line['pallets'] * $item->units_per_pallet,
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
        $merchandiseRequest->load(['client', 'requestedBy', 'lines.item', 'dispatch']);

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

        $notificationService->notifyStatusChanged(
            $merchandiseRequest->fresh(['client', 'requestedBy', 'lines.item']),
            MerchandiseRequest::STATUS_PENDING
        );

        return redirect()
            ->route('dispatches.show', $dispatch)
            ->with('status', 'Salida generada desde la solicitud correctamente.');
    }

    public function show(Request $request, GoodsDispatch $goodsDispatch): View
    {
        $goodsDispatch->load(['client', 'creator', 'merchandiseRequest', 'lines.item']);

        return view('dispatches.show', [
            'dispatch' => $goodsDispatch,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function updateStatus(
        Request $request,
        GoodsDispatch $goodsDispatch,
        MerchandiseRequestNotificationService $notificationService,
    ): RedirectResponse {
        $validated = $request->validate([
            'status' => ['required', Rule::in(GoodsDispatch::statuses())],
        ]);

        $previousStatus = $goodsDispatch->status;

        if ($previousStatus === $validated['status']) {
            return back()->with('status', 'La salida ya estaba en ese estado.');
        }

        $payload = [
            'status' => $validated['status'],
        ];

        if ($validated['status'] === GoodsDispatch::STATUS_SENT) {
            $payload['sent_at'] = $goodsDispatch->sent_at ?? now();
        }

        DB::transaction(function () use ($goodsDispatch, $payload, $validated, $request, $notificationService): void {
            $goodsDispatch->update($payload);

            $merchandiseRequest = $goodsDispatch->merchandiseRequest;

            if ($merchandiseRequest === null) {
                return;
            }

            $previousRequestStatus = $merchandiseRequest->status;
            $requestPayload = [
                'status' => $validated['status'],
            ];

            if ($validated['status'] === MerchandiseRequest::STATUS_PREPARING) {
                $requestPayload['prepared_by'] = $request->user()->id;
                $requestPayload['prepared_at'] = $merchandiseRequest->prepared_at ?? now();
            }

            if ($validated['status'] === MerchandiseRequest::STATUS_SENT) {
                $requestPayload['shipped_by'] = $request->user()->id;
                $requestPayload['shipped_at'] = $merchandiseRequest->shipped_at ?? now();
            }

            if ($validated['status'] === MerchandiseRequest::STATUS_CANCELLED) {
                $requestPayload['cancelled_at'] = $merchandiseRequest->cancelled_at ?? now();
            }

            $merchandiseRequest->update($requestPayload);

            $notificationService->notifyStatusChanged(
                $merchandiseRequest->fresh(['client', 'requestedBy', 'lines.item']),
                $previousRequestStatus
            );
        });

        return redirect()
            ->route('dispatches.show', $goodsDispatch->fresh())
            ->with('status', 'Estado de salida actualizado correctamente.');
    }

    public function deliveryNotePdf(Request $request, GoodsDispatch $goodsDispatch)
    {
        $goodsDispatch->load(['client', 'merchandiseRequest', 'lines.item']);

        abort_unless(
            in_array($goodsDispatch->status, [GoodsDispatch::STATUS_SENT, GoodsDispatch::STATUS_COMPLETED], true),
            403
        );

        return Pdf::loadView('dispatches.delivery-note-pdf', [
            'dispatch' => $goodsDispatch,
        ])->stream($goodsDispatch->dispatchNumber().'.pdf');
    }
}
