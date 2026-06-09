<?php

namespace App\Http\Controllers;

use App\Models\AccessRequest as AccessRequestModel;
use App\Services\BrevoMailService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;
use Throwable;

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

        $statusMessage = 'Solicitud enviada. El equipo de MAXIMO revisara tu peticion y te contactara.';

        try {
            app(BrevoMailService::class)->sendAccessRequestNotification($accessRequest);
        } catch (Throwable $exception) {
            report($exception);

            $statusMessage = 'Solicitud enviada. La notificacion interna por correo no ha podido verificarse.';
        }

        return redirect()
            ->route('access-requests.create')
            ->with('status', $statusMessage);
    }
}
