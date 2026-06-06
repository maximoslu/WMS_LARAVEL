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

        return view('dashboard.index', [
            'navigationItems' => WmsNavigation::forUser($user),
            'currentRoleName' => $user->role?->name ?? 'Sin rol asignado',
        ]);
    }
}
