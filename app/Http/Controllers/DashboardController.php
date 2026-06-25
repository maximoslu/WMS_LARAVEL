<?php

namespace App\Http\Controllers;

use App\Support\WmsNavigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $navigationSections = WmsNavigation::sectionsForUser($user);

        return view('dashboard.index', [
            'navigationSections' => $navigationSections,
            'currentRoleName' => $user->role?->name ?? 'Sin rol asignado',
            'visibleModuleCount' => collect($navigationSections)
                ->sum(fn (array $section): int => count($section['children'])),
        ]);
    }
}
