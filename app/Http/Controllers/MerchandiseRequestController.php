<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreMerchandiseRequestRequest;
use App\Models\Client;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Services\MerchandiseRequests\MerchandiseRequestScheduleService;
use App\Support\WmsNavigation;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                    $query
                        ->where('request_code', 'like', '%'.$search.'%')
                        ->orWhereHas('lines', function (Builder $query) use ($search): void {
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
            ->latest('submitted_at')
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

        return redirect()
            ->route('merchandise-requests.show', $merchandiseRequest)
            ->with('status', $scheduleService->submissionNotice($merchandiseRequest->submittedAt()));
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
            'merchandiseRequest' => $merchandiseRequest->load(['client', 'requestedBy', 'lines.item']),
            'isClient' => $user->hasRole(Role::CLIENTE),
            'navigationSections' => WmsNavigation::sectionsForUser($user),
        ]);
    }
}
