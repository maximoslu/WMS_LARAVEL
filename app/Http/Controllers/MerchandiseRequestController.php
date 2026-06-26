<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMerchandiseRequestRequest;
use App\Models\Client;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Services\GoodsDispatches\GoodsDispatchWorkflowService;
use App\Services\MerchandiseRequests\MerchandiseRequestNotificationService;
use App\Services\MerchandiseRequests\MerchandiseRequestScheduleService;
use App\Support\WmsNavigation;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
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
            ->with(['client', 'requestedBy', 'lines.item'])
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
            'canCreate' => $user->hasRole(Role::CLIENTE) && $user->client_id !== null,
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function create(Request $request): View
    {
        $user = $request->user();

        abort_unless($user->hasRole(Role::CLIENTE) && $user->client_id !== null, 403);

        return view('merchandise-requests.create', [
            'items' => Item::query()
                ->where('client_id', $user->client_id)
                ->where('active', true)
                ->orderBy('sku')
                ->get(),
            'client' => $user->client,
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function store(
        StoreMerchandiseRequestRequest $request,
        MerchandiseRequestScheduleService $scheduleService,
        MerchandiseRequestNotificationService $notificationService,
    ): RedirectResponse {
        $user = $request->user();
        $requestedLines = $request->validatedLines();
        $items = Item::query()
            ->whereIn('id', array_column($requestedLines, 'item_id'))
            ->get()
            ->keyBy('id');

        $merchandiseRequest = DB::transaction(function () use ($requestedLines, $items, $user): MerchandiseRequest {
            $requestModel = MerchandiseRequest::query()->create([
                'client_id' => $user->client_id,
                'requested_by' => $user->id,
                'status' => MerchandiseRequest::STATUS_PENDING,
                'requested_date' => now()->toDateString(),
            ]);

            foreach ($requestedLines as $line) {
                $item = $items->get($line['item_id']);

                if ($item === null) {
                    continue;
                }

                $requestModel->lines()->create([
                    'item_id' => $item->id,
                    'lot' => $item->lot,
                    'units_per_pallet' => $item->units_per_pallet,
                    'requested_pallets' => $line['requested_pallets'],
                    'requested_units' => $line['requested_pallets'] * $item->units_per_pallet,
                ]);
            }

            return $requestModel;
        });

        $merchandiseRequest->load(['client', 'requestedBy', 'lines.item']);
        $notificationService->notifySubmitted($merchandiseRequest);

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', $scheduleService->submissionNotice($merchandiseRequest->submittedAt()).' Solicitud registrada y notificada correctamente.');
    }

    public function show(Request $request, MerchandiseRequest $merchandiseRequest): View
    {
        $user = $request->user();

        if ($user->hasRole(Role::CLIENTE)) {
            abort_unless((int) $user->client_id === (int) $merchandiseRequest->client_id, 403);
        } else {
            abort_unless($user->canAccessRole(Role::ALMACEN), 403);
        }

        return view('merchandise-requests.show', [
            'merchandiseRequest' => $merchandiseRequest->load(['client', 'requestedBy', 'lines.item', 'dispatch.lines.item', 'dispatch.lines.sourceRequestLine']),
            'isClient' => $user->hasRole(Role::CLIENTE),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }

    public function updateStatus(
        Request $request,
        MerchandiseRequest $merchandiseRequest,
        GoodsDispatchWorkflowService $workflowService,
        MerchandiseRequestNotificationService $notificationService,
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
        } else {
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

            $merchandiseRequest->update($payload);

            $notificationService->notifyStatusChanged(
                $merchandiseRequest->fresh(['client', 'requestedBy', 'lines.item']),
                $previousStatus
            );
        }

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', 'Estado de la solicitud actualizado correctamente.');
    }

    public function preparationPdf(Request $request, MerchandiseRequest $merchandiseRequest)
    {
        abort_unless($request->user()->canAccessRole(Role::ALMACEN), 403);

        $merchandiseRequest->load(['client', 'requestedBy', 'lines.item']);

        return Pdf::loadView('merchandise-requests.preparation-pdf', [
            'merchandiseRequest' => $merchandiseRequest,
        ])->stream($merchandiseRequest->referenceCode().'-preparacion.pdf');
    }
}
