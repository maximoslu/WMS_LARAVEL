<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Role;
use App\Support\WmsNavigation;
use Illuminate\Support\Carbon;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(Request $request): View
    {
        $user = $request->user();
        $navigationSections = WmsNavigation::sectionsForUser($user);
        $calendarStart = now()->startOfWeek(Carbon::MONDAY);
        $calendarEnd = $calendarStart->copy()->addDays(6);
        $upcomingBookings = Booking::query()
            ->with(['client'])
            ->when($user->hasRole(Role::CLIENTE), fn ($query) => $query->where('client_id', $user->client_id))
            ->whereDate('scheduled_date', '>=', now()->toDateString())
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time_from')
            ->limit(7)
            ->get();
        $calendarBookings = Booking::query()
            ->with(['client'])
            ->when($user->hasRole(Role::CLIENTE), fn ($query) => $query->where('client_id', $user->client_id))
            ->whereBetween('scheduled_date', [$calendarStart->toDateString(), $calendarEnd->toDateString()])
            ->orderBy('scheduled_date')
            ->orderBy('scheduled_time_from')
            ->get();
        $calendarDays = collect(range(0, 6))
            ->map(function (int $offset) use ($calendarStart, $calendarBookings) {
                $date = $calendarStart->copy()->addDays($offset);

                return [
                    'date' => $date,
                    'bookings' => $calendarBookings
                        ->filter(fn (Booking $booking) => $booking->scheduled_date?->isSameDay($date))
                        ->values(),
                ];
            });

        return view('dashboard.index', [
            'navigationSections' => $navigationSections,
            'currentRoleName' => $user->role?->name ?? 'Sin rol asignado',
            'visibleModuleCount' => collect($navigationSections)
                ->sum(fn (array $section): int => count($section['children'])),
            'bookingCalendarDays' => $calendarDays,
            'bookingCalendarStart' => $calendarStart,
            'bookingCalendarEnd' => $calendarEnd,
            'googleBookingCalendarEmbedUrl' => config('wms.google_booking_calendar_embed_url'),
            'upcomingBookings' => $upcomingBookings,
            'recentNotifications' => $user->notifications()
                ->latest()
                ->limit(5)
                ->get(),
        ]);
    }
}
