<?php

namespace App\Services;

use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleCalendarEvent;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class GoogleCalendarService
{
    public function getConnectionStatus(): array
    {
        if (! $this->isEnabled()) {
            return [
                'state' => 'disabled',
                'label' => 'Google Calendar desactivado',
                'message' => 'La capa Google Calendar esta desactivada en la configuracion del entorno.',
            ];
        }

        if (! $this->usesOAuth()) {
            return [
                'state' => 'error',
                'label' => 'Error de configuracion',
                'message' => 'El modo de autenticacion configurado no esta soportado en esta fase.',
            ];
        }

        if (! $this->hasRequiredConfiguration()) {
            return [
                'state' => 'error',
                'label' => 'Error de configuracion',
                'message' => 'Faltan variables obligatorias de Google Calendar en el entorno.',
            ];
        }

        $token = $this->readStoredToken();

        if ($token === null) {
            return [
                'state' => 'pending',
                'label' => 'Pendiente de conectar',
                'message' => 'Todavia no hay un token OAuth local guardado para esta integracion.',
            ];
        }

        return [
            'state' => 'connected',
            'label' => 'Conectado',
            'message' => 'Google Calendar esta listo para mostrar eventos en modo solo lectura.',
        ];
    }

    public function getAuthorizationUrl(?string $state = null): ?string
    {
        if (! $this->canStartOAuthFlow()) {
            return null;
        }

        $client = $this->makeOAuthClient();

        if (filled($state)) {
            $client->setState($state);
        }

        return $client->createAuthUrl();
    }

    public function handleOAuthCallback(string $code): bool
    {
        if (! $this->canStartOAuthFlow()) {
            return false;
        }

        $client = $this->makeOAuthClient();
        $existingToken = $this->readStoredToken();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (! is_array($token) || isset($token['error']) || ! isset($token['access_token'])) {
            $this->logWarning('No se pudo intercambiar el code OAuth por un token valido.', [
                'has_error' => is_array($token) && isset($token['error']),
            ]);

            return false;
        }

        if (! isset($token['refresh_token']) && isset($existingToken['refresh_token'])) {
            $token['refresh_token'] = $existingToken['refresh_token'];
        }

        $this->storeToken($token);

        // TODO: crear eventos Google al aprobar booking.
        // TODO: actualizar evento Google al modificar booking.
        // TODO: cancelar evento Google al cancelar booking.

        return true;
    }

    public function disconnect(): void
    {
        $token = $this->readStoredToken();

        if ($token !== null) {
            try {
                $client = $this->makeOAuthClient();
                $client->setAccessToken($token);
                $client->revokeToken($token['access_token'] ?? null);
            } catch (Throwable $exception) {
                $this->logWarning('No se pudo revocar el token OAuth de Google Calendar.', [
                    'exception' => $exception::class,
                ]);
            }
        }

        $path = $this->resolvedTokenPath();

        if ($path !== null && File::exists($path)) {
            File::delete($path);
        }
    }

    public function getEventsBetween(Carbon $startsAt, Carbon $endsAt): Collection
    {
        if (! $this->isEnabled()) {
            return collect();
        }

        if (! $this->usesOAuth()) {
            return collect();
        }

        if (! $this->hasRequiredConfiguration()) {
            $this->logWarning('Google Calendar activo pero con configuracion incompleta.');

            return collect();
        }

        $token = $this->readStoredToken();

        if ($token === null) {
            $this->logWarning('Google Calendar activo pero sin token OAuth local.');

            return collect();
        }

        try {
            $client = $this->makeOAuthClient();
            $client->setAccessToken($token);

            if ($client->isAccessTokenExpired()) {
                $refreshToken = $client->getRefreshToken();

                if (blank($refreshToken)) {
                    $this->logWarning('El token de Google Calendar ha expirado y no hay refresh token.');

                    return collect();
                }

                $refreshedToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

                if (! is_array($refreshedToken) || isset($refreshedToken['error'])) {
                    $this->logWarning('No se pudo refrescar el token de Google Calendar.', [
                        'has_error' => is_array($refreshedToken) && isset($refreshedToken['error']),
                    ]);

                    return collect();
                }

                $this->storeToken($client->getAccessToken());
            }

            $calendar = new GoogleCalendar($client);
            $events = $calendar->events->listEvents($this->calendarId(), [
                'timeMin' => $startsAt->copy()->startOfDay()->toRfc3339String(),
                'timeMax' => $endsAt->copy()->endOfDay()->toRfc3339String(),
                'singleEvents' => true,
                'orderBy' => 'startTime',
            ]);

            return collect($events->getItems() ?? [])
                ->map(fn (GoogleCalendarEvent $event) => $this->normalizeEvent($event))
                ->filter()
                ->sortBy('starts_at')
                ->values();
        } catch (Throwable $exception) {
            $this->logWarning('Fallo controlado al consultar Google Calendar.', [
                'exception' => $exception::class,
            ]);

            return collect();
        }
    }

    private function normalizeEvent(GoogleCalendarEvent $event): ?array
    {
        $start = $event->getStart();
        $end = $event->getEnd();

        if ($start === null) {
            return null;
        }

        $isAllDay = filled($start->getDate());
        $timezone = $start->getTimeZone() ?: config('app.timezone', 'Europe/Madrid');
        $startsAt = $isAllDay
            ? Carbon::parse($start->getDate(), $timezone)->startOfDay()
            : Carbon::parse($start->getDateTime(), $timezone);

        $endsAt = null;

        if ($end !== null && filled($end->getDate())) {
            $endsAt = Carbon::parse($end->getDate(), $end->getTimeZone() ?: $timezone)->startOfDay();
        } elseif ($end !== null && filled($end->getDateTime())) {
            $endsAt = Carbon::parse($end->getDateTime(), $end->getTimeZone() ?: $timezone);
        }

        return [
            'source' => 'google',
            'id' => (string) $event->getId(),
            'title' => filled($event->getSummary()) ? (string) $event->getSummary() : 'Evento sin titulo',
            'starts_at' => $startsAt,
            'ends_at' => $endsAt ?? $startsAt->copy(),
            'all_day' => $isAllDay,
            'location' => $event->getLocation() ?: null,
            'description' => $event->getDescription() ?: null,
        ];
    }

    private function makeOAuthClient(): GoogleClient
    {
        $client = new GoogleClient();
        $client->setApplicationName('MAXIMO WMS');
        $client->setClientId((string) config('google-calendar.client_id'));
        $client->setClientSecret((string) config('google-calendar.client_secret'));
        $client->setRedirectUri((string) config('google-calendar.redirect_uri'));
        $client->setScopes([GoogleCalendar::CALENDAR_READONLY]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');

        return $client;
    }

    private function canStartOAuthFlow(): bool
    {
        return $this->isEnabled()
            && $this->usesOAuth()
            && $this->hasRequiredConfiguration();
    }

    private function isEnabled(): bool
    {
        return (bool) config('google-calendar.enabled', false);
    }

    private function usesOAuth(): bool
    {
        return config('google-calendar.auth_mode') === 'oauth';
    }

    private function hasRequiredConfiguration(): bool
    {
        return filled($this->calendarId())
            && filled(config('google-calendar.client_id'))
            && filled(config('google-calendar.client_secret'))
            && filled(config('google-calendar.redirect_uri'))
            && filled(config('google-calendar.token_path'));
    }

    private function calendarId(): ?string
    {
        $calendarId = config('google-calendar.calendar_id');

        return filled($calendarId) ? (string) $calendarId : null;
    }

    private function resolvedTokenPath(): ?string
    {
        $configuredPath = config('google-calendar.token_path');

        if (! filled($configuredPath)) {
            return null;
        }

        $configuredPath = (string) $configuredPath;

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $configuredPath) === 1 || Str::startsWith($configuredPath, ['/','\\'])) {
            return $configuredPath;
        }

        return base_path($configuredPath);
    }

    private function readStoredToken(): ?array
    {
        $path = $this->resolvedTokenPath();

        if ($path === null || ! File::exists($path)) {
            return null;
        }

        $decoded = json_decode((string) File::get($path), true);

        if (! is_array($decoded) || ! isset($decoded['access_token'])) {
            $this->logWarning('El token local de Google Calendar no tiene un formato valido.');

            return null;
        }

        return $decoded;
    }

    private function storeToken(array $token): void
    {
        $path = $this->resolvedTokenPath();

        if ($path === null) {
            return;
        }

        File::ensureDirectoryExists(dirname($path));
        File::put($path, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }

    private function logWarning(string $message, array $context = []): void
    {
        Log::warning($message, array_merge([
            'channel' => 'google_calendar',
        ], $context));
    }
}
