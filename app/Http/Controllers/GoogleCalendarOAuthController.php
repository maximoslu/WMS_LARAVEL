<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarOAuthController extends Controller
{
    public function redirect(Request $request, GoogleCalendarService $googleCalendarService): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put('google_calendar_oauth_state', $state);
        $authorizationUrl = $googleCalendarService->getAuthorizationUrl($state);

        if ($authorizationUrl === null) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'No se puede iniciar la conexion de Google Calendar porque falta configuracion local.');
        }

        return redirect()->away($authorizationUrl);
    }

    public function callback(Request $request, GoogleCalendarService $googleCalendarService): RedirectResponse
    {
        $expectedState = (string) $request->session()->pull('google_calendar_oauth_state', '');
        $receivedState = (string) $request->string('state');

        if ($expectedState === '' || ! hash_equals($expectedState, $receivedState)) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'No se ha podido validar la respuesta OAuth de Google Calendar.');
        }

        if (filled($request->string('error'))) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'La conexion con Google Calendar no se ha completado.');
        }

        $code = (string) $request->string('code');

        if ($code === '') {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'No se ha recibido el codigo OAuth de Google Calendar.');
        }

        try {
            if (! $googleCalendarService->handleOAuthCallback($code)) {
                return redirect()
                    ->route('dashboard')
                    ->with('warning', 'No se ha podido guardar la conexion de Google Calendar.');
            }

            return redirect()
                ->route('dashboard')
                ->with('status', 'Google Calendar conectado correctamente.');
        } catch (Throwable) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'Google Calendar no ha podido conectarse en este momento.');
        }
    }

    public function disconnect(GoogleCalendarService $googleCalendarService): RedirectResponse
    {
        try {
            $googleCalendarService->disconnect();

            return redirect()
                ->route('dashboard')
                ->with('status', 'Google Calendar desconectado correctamente.');
        } catch (Throwable) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'No se ha podido desconectar Google Calendar.');
        }
    }
}
