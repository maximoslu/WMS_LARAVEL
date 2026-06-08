<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest as AccessRequestModel;
use App\Notifications\AccessRequestSubmitted;
use Illuminate\Support\Facades\Notification;
use Throwable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class AccessRequestController extends Controller
{
    public function create(): View
    {
        return view('access-requests.create');
    }

    public function store(Request $request): RedirectResponse
    {
        $payload = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'company' => ['nullable', 'string', 'max:255'],
            'email' => ['required', 'email', 'max:255'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $accessRequest = AccessRequestModel::create($payload);

        try {
            Notification::route('mail', (string) config('wms.access_request_notification_email'))
                ->notify(new AccessRequestSubmitted($accessRequest));
        } catch (Throwable $exception) {
            report($exception);
        }

        return redirect()
            ->route('access-requests.create')
            ->with('status', 'Solicitud enviada. El equipo de MAXIMO revisará tu petición y te contactará.');
    }
}
