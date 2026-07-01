<?php

namespace App\Http\Controllers;

use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\Cookie;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarOAuthController extends Controller
{
    private const STATE_SESSION_KEY = 'google_calendar_oauth_state';

    private const STATE_COOKIE_KEY = 'google_calendar_oauth_state';

    public function redirect(Request $request, GoogleCalendarService $googleCalendarService): RedirectResponse
    {
        $state = Str::random(40);
        $request->session()->put(self::STATE_SESSION_KEY, $state);
        $request->session()->save();
        $authorizationUrl = $googleCalendarService->getAuthorizationUrl($state);

        $this->logInfo('Inicio de redireccion OAuth de Google Calendar.', [
            'redirect_uri' => $googleCalendarService->redirectUri(),
            'token_path' => $googleCalendarService->tokenPath(),
            'session_state_saved' => $request->session()->has(self::STATE_SESSION_KEY),
        ]);

        if ($authorizationUrl === null) {
            return redirect()
                ->route('dashboard')
                ->with('warning', 'No se ha podido conectar Google Calendar. Revisa la configuracion OAuth o consulta los logs.');
        }

        Cookie::queue(cookie()->make(
            self::STATE_COOKIE_KEY,
            $state,
            10,
            null,
            null,
            $request->isSecure(),
            true,
            false,
            'lax'
        ));

        return redirect()->away($authorizationUrl);
    }

    public function callback(Request $request, GoogleCalendarService $googleCalendarService): RedirectResponse
    {
        $sessionState = (string) $request->session()->get(self::STATE_SESSION_KEY, '');
        $cookieState = (string) $request->cookie(self::STATE_COOKIE_KEY, '');
        $receivedState = (string) $request->string('state');
        $googleError = (string) $request->string('error');
        $googleErrorDescription = (string) $request->string('error_description');
        $code = (string) $request->string('code');
        $expectedState = $sessionState !== '' ? $sessionState : $cookieState;

        $this->logInfo('Callback OAuth de Google Calendar recibido.', [
            'has_code' => $code !== '',
            'has_state' => $receivedState !== '',
            'has_session_state' => $sessionState !== '',
            'has_cookie_state' => $cookieState !== '',
            'has_google_error' => $googleError !== '',
            'google_error' => $googleError !== '' ? $googleError : null,
            'google_error_description' => $googleErrorDescription !== '' ? $googleErrorDescription : null,
            'redirect_uri' => $googleCalendarService->redirectUri(),
            'token_path' => $googleCalendarService->tokenPath(),
            'token_directory_exists' => ($googleCalendarService->tokenPath() !== null)
                ? is_dir(dirname($googleCalendarService->tokenPath()))
                : false,
        ]);

        if ($expectedState === '' || ! hash_equals($expectedState, $receivedState)) {
            $request->session()->forget(self::STATE_SESSION_KEY);
            Cookie::queue(Cookie::forget(self::STATE_COOKIE_KEY));

            $this->logWarning('Fallo de validacion del state OAuth de Google Calendar.', [
                'has_session_state' => $sessionState !== '',
                'has_cookie_state' => $cookieState !== '',
                'has_received_state' => $receivedState !== '',
                'redirect_uri' => $googleCalendarService->redirectUri(),
            ]);

            return redirect()
                ->route('dashboard')
                ->with('warning', 'No se ha podido conectar Google Calendar. Revisa la configuracion OAuth o consulta los logs.');
        }

        $request->session()->forget(self::STATE_SESSION_KEY);
        Cookie::queue(Cookie::forget(self::STATE_COOKIE_KEY));

        if ($googleError !== '') {
            $this->logWarning('Google devolvio un error durante el callback OAuth.', [
                'google_error' => $googleError,
                'google_error_description' => $googleErrorDescription !== '' ? $googleErrorDescription : null,
                'redirect_uri' => $googleCalendarService->redirectUri(),
            ]);

            return redirect()
                ->route('dashboard')
                ->with('warning', 'No se ha podido conectar Google Calendar. Revisa la configuracion OAuth o consulta los logs.');
        }

        if ($code === '') {
            $this->logWarning('Callback OAuth sin code para Google Calendar.', [
                'redirect_uri' => $googleCalendarService->redirectUri(),
            ]);

            return redirect()
                ->route('dashboard')
                ->with('warning', 'No se ha podido conectar Google Calendar. Revisa la configuracion OAuth o consulta los logs.');
        }

        try {
            $result = $googleCalendarService->handleOAuthCallback($code);

            $this->logInfo('Resultado del intercambio OAuth de Google Calendar.', [
                'success' => (bool) ($result['success'] ?? false),
                'reason' => $result['reason'] ?? 'unknown',
                'token_path' => $googleCalendarService->tokenPath(),
                'token_exists_after_write' => ($googleCalendarService->tokenPath() !== null)
                    ? file_exists($googleCalendarService->tokenPath())
                    : false,
                'has_refresh_token' => (bool) ($result['has_refresh_token'] ?? false),
            ]);

            if (! ($result['success'] ?? false)) {
                return redirect()
                    ->route('dashboard')
                    ->with('warning', 'No se ha podido conectar Google Calendar. Revisa la configuracion OAuth o consulta los logs.');
            }

            return redirect()
                ->route('dashboard')
                ->with('status', 'Google Calendar conectado correctamente.');
        } catch (Throwable $exception) {
            $this->logWarning('Excepcion al completar el callback OAuth de Google Calendar.', [
                'exception_class' => $exception::class,
                'exception_message' => $exception->getMessage(),
                'exception_line' => $exception->getLine(),
                'redirect_uri' => $googleCalendarService->redirectUri(),
                'token_path' => $googleCalendarService->tokenPath(),
            ]);

            return redirect()
                ->route('dashboard')
                ->with('warning', 'No se ha podido conectar Google Calendar. Revisa la configuracion OAuth o consulta los logs.');
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

    private function logInfo(string $message, array $context = []): void
    {
        Log::info($message, array_merge([
            'channel' => 'google_calendar_oauth',
        ], $context));
    }

    private function logWarning(string $message, array $context = []): void
    {
        Log::warning($message, array_merge([
            'channel' => 'google_calendar_oauth',
        ], $context));
    }
}
