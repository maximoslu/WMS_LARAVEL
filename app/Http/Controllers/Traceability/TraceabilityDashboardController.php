<?php

namespace App\Http\Controllers\Traceability;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Traceability\Concerns\AuthorizesTraceability;
use App\Models\Role;
use App\Services\Traceability\TraceabilityDashboardService;
use App\Support\WmsNavigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class TraceabilityDashboardController extends Controller
{
    use AuthorizesTraceability;

    public function __invoke(Request $request, TraceabilityDashboardService $dashboard): View
    {
        $this->authorizeTraceabilityRead($request);

        return view('traceability.index', [
            'summary' => $dashboard->summary(30),
            'canAdminister' => $request->user()->canAccessRole(Role::ADMINISTRACION),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }
}
