<?php

namespace App\Http\Controllers;

use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Services\MerchandiseRequests\MerchandiseRequestForecastService;
use App\Support\WmsNavigation;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class MerchandiseRequestForecastController extends Controller
{
    public function index(Request $request, MerchandiseRequestForecastService $forecastService): View
    {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);

        $filters = $this->filters($request);
        $query = $forecastService->draftQuery($filters);
        $requests = $query->paginate(20)->withQueryString();
        $filterOptions = $forecastService->filterOptions();
        $requests->getCollection()->transform(function (MerchandiseRequest $merchandiseRequest) use ($forecastService): MerchandiseRequest {
            $merchandiseRequest->setAttribute('forecast_totals', $forecastService->totalsFor($merchandiseRequest));

            return $merchandiseRequest;
        });

        return view('merchandise-requests.forecast.index', [
            'requests' => $requests,
            'summary' => $forecastService->summary($query),
            'clients' => $filterOptions['clients'],
            'creators' => $filterOptions['creators'],
            'filters' => $filters,
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function show(
        Request $request,
        MerchandiseRequest $merchandiseRequest,
        MerchandiseRequestForecastService $forecastService,
    ): View {
        abort_unless($request->user()?->canAccessRole(Role::ALMACEN), 403);
        abort_unless($merchandiseRequest->isDraft(), 404);

        $merchandiseRequest->load([
            'client',
            'requestedBy',
            'lines.item',
            'lines.stockPallet.location.warehouse',
        ]);

        return view('merchandise-requests.forecast.show', [
            'merchandiseRequest' => $merchandiseRequest,
            'totals' => $forecastService->totalsFor($merchandiseRequest),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    /**
     * @return array{client_id:int|null,creator_id:int|null,date_from:string|null,date_to:string|null,has_notes:bool,has_fill_truck:bool,sort:string}
     */
    private function filters(Request $request): array
    {
        $validated = $request->validate([
            'client_id' => ['nullable', 'integer', 'exists:clients,id'],
            'creator_id' => ['nullable', 'integer', 'exists:users,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d'],
            'has_notes' => ['nullable', Rule::in(['1'])],
            'has_fill_truck' => ['nullable', Rule::in(['1'])],
            'sort' => ['nullable', Rule::in([
                MerchandiseRequestForecastService::SORT_UPDATED,
                MerchandiseRequestForecastService::SORT_CLIENT,
                MerchandiseRequestForecastService::SORT_VOLUME,
                MerchandiseRequestForecastService::SORT_CREATED,
            ])],
        ]);

        return [
            'client_id' => isset($validated['client_id']) ? (int) $validated['client_id'] : null,
            'creator_id' => isset($validated['creator_id']) ? (int) $validated['creator_id'] : null,
            'date_from' => $validated['date_from'] ?? null,
            'date_to' => $validated['date_to'] ?? null,
            'has_notes' => isset($validated['has_notes']),
            'has_fill_truck' => isset($validated['has_fill_truck']),
            'sort' => $validated['sort'] ?? MerchandiseRequestForecastService::SORT_UPDATED,
        ];
    }
}
