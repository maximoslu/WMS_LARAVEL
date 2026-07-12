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

    /**
     * Marca como leidas TODAS las notificaciones no leidas de TODOS los usuarios.
     * Accion reservada a superadmin. No borra ni oculta registros: solo fija read_at.
     */
    public function markAllAsRead(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->isSuperAdmin(), 403);

        $now = now();
        $count = DB::table('notifications')
            ->whereNull('read_at')
            ->update([
                'read_at' => $now,
                'updated_at' => $now,
            ]);

        $message = $count > 0
            ? 'Se han marcado '.$count.' notificaciones como leidas.'
            : 'No habia notificaciones pendientes.';

        return back()->with('status', $message);
    }
}
