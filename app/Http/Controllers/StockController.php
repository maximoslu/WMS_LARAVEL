<?php

namespace App\Http\Controllers;

use App\Models\Client;
use App\Support\Stock\StockOverviewBuilder;
use App\Support\WmsNavigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class StockController extends Controller
{
    public function __construct(
        private readonly StockOverviewBuilder $overviewBuilder,
    ) {}

    public function index(Request $request): View
    {
        $overview = $this->overviewBuilder->build($request->only([
            'client_id',
            'search',
            'lot',
            'stock_state',
            'batch_status',
            'location',
        ]));

        return view('stock.index', [
            'rows' => $overview['rows'],
            'summary' => $overview['summary'],
            'filters' => $overview['filters'],
            'clients' => Client::query()->orderBy('name')->get(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }
}
