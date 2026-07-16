<?php

namespace App\Http\Controllers;

use App\Support\WmsNavigation;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class NotificationController extends Controller
{
    public function index(Request $request): View
    {
        return view('notifications.index', [
            'notifications' => $request->user()
                ->notifications()
                ->latest()
                ->paginate(10)
                ->withQueryString(),
            'navigationSections' => WmsNavigation::sectionsForUser($request->user()),
        ]);
    }

    public function markAsRead(Request $request, string $notification): RedirectResponse
    {
        $record = $request->user()
            ->notifications()
            ->whereKey($notification)
            ->firstOrFail();

        if ($record->read_at === null) {
            $record->markAsRead();
        }

        return back()->with('status', 'Notificacion marcada como leida.');
    }

    public function markAllAsRead(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $now = now();
        $query = DB::table('notifications')
            ->whereNull('read_at')
            ->when(! $user->isSuperAdmin(), fn ($query) => $query
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->id));

        $count = $query->update([
            'read_at' => $now,
            'updated_at' => $now,
        ]);

        $message = $count > 0
            ? 'Se han marcado '.$count.' notificaciones como leidas.'
            : 'No habia notificaciones pendientes.';

        return back()->with('status', $message);
    }

    public function destroyAllUnread(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $query = DB::table('notifications')
            ->whereNull('read_at')
            ->when(! $user->isSuperAdmin(), fn ($query) => $query
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->id));

        $count = $query->delete();

        $message = $count > 0
            ? 'Se han eliminado '.$count.' notificaciones no leidas.'
            : 'No habia notificaciones para eliminar.';

        return back()->with('status', $message);
    }

    public function destroyAll(Request $request): RedirectResponse
    {
        $user = $request->user();
        abort_unless($user !== null, 403);

        $query = DB::table('notifications')
            ->when(! $user->isSuperAdmin(), fn ($query) => $query
                ->where('notifiable_type', $user->getMorphClass())
                ->where('notifiable_id', $user->id));

        $count = $query->delete();

        $message = $count > 0
            ? 'Se han eliminado '.$count.' notificaciones.'
            : 'No habia notificaciones para eliminar.';

        return back()->with('status', $message);
    }
}
