<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Role;
use App\Support\WmsNavigation;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $navigationSections = WmsNavigation::sectionsForUser($user);
        $upcomingBookings = Booking::query()
            ->with(['client'])
            ->when($user->hasRole(Role::CLIENTE), fn ($query) => $query->where('client_id', $user->client_id))
            ->whereDate('scheduled_date', '>=', now()->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time_from')
            ->limit(7)
            ->get();

        return view('dashboard.index', [
            'navigationSections' => $navigationSections,
            'currentRoleName' => $user->role?->name ?? 'Sin rol asignado',
            'visibleModuleCount' => collect($navigationSections)
                ->sum(fn (array $section): int => count($section['children'])),
            'upcomingBookings' => $upcomingBookings,
            'recentNotifications' => $user->notifications()
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }
}
