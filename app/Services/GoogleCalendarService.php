<?php

namespace App\Services;

use App\Models\Booking;
use Google\Client as GoogleClient;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Calendar\Event as GoogleCalendarEvent;
use Google\Service\Calendar\EventDateTime as GoogleCalendarEventDateTime;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use RuntimeException;
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
            'message' => 'Google Calendar esta listo para leer la agenda y sincronizar bookings.',
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

    public function handleOAuthCallback(string $code): array
    {
        if (! $this->canStartOAuthFlow()) {
            $this->logWarning('No se puede completar el callback OAuth porque falta configuracion local.', [
                'redirect_uri' => $this->redirectUri(),
                'token_path' => $this->resolvedTokenPath(),
            ]);

            return [
                'success' => false,
                'reason' => 'configuration',
            ];
        }

        $client = $this->makeOAuthClient();
        $existingToken = $this->readStoredToken();
        $token = $client->fetchAccessTokenWithAuthCode($code);

        if (! is_array($token) || isset($token['error']) || ! isset($token['access_token'])) {
            $this->logWarning('No se pudo intercambiar el code OAuth por un token valido.', [
                'reason' => 'token_exchange',
                'has_error' => is_array($token) && isset($token['error']),
                'google_error' => is_array($token) ? ($token['error'] ?? null) : null,
                'google_error_description' => is_array($token) ? ($token['error_description'] ?? null) : null,
                'redirect_uri' => $this->redirectUri(),
                'token_path' => $this->resolvedTokenPath(),
            ]);

            return [
                'success' => false,
                'reason' => 'token_exchange',
            ];
        }

        if (! isset($token['refresh_token']) && isset($existingToken['refresh_token'])) {
            $token['refresh_token'] = $existingToken['refresh_token'];
        }

        if (! isset($token['refresh_token'])) {
            $this->logWarning('Google Calendar devolvio access_token sin refresh_token.', [
                'reason' => 'missing_refresh_token',
                'redirect_uri' => $this->redirectUri(),
                'token_path' => $this->resolvedTokenPath(),
            ]);
        }

        $this->storeToken($token);

        return [
            'success' => true,
            'reason' => 'connected',
            'has_refresh_token' => isset($token['refresh_token']),
        ];
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
        try {
            $calendar = $this->makeAuthorizedCalendar();
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

    public function syncBookingEvent(Booking $booking): array
    {
        if ($booking->status === Booking::STATUS_CANCELLED) {
            return $this->deleteBookingEvent($booking);
        }

        if (filled($booking->google_calendar_event_id)) {
            return $this->updateBookingEvent($booking);
        }

        return $this->createBookingEvent($booking);
    }

    public function createBookingEvent(Booking $booking): array
    {
        return $this->runBookingSync($booking, 'create', function (GoogleCalendar $calendar, Booking $booking): array {
            return $this->upsertBookingEvent($calendar, $booking, null);
        });
    }

    public function updateBookingEvent(Booking $booking): array
    {
        return $this->runBookingSync($booking, 'update', function (GoogleCalendar $calendar, Booking $booking): array {
            if (blank($booking->google_calendar_event_id)) {
                return $this->upsertBookingEvent($calendar, $booking, null);
            }

            try {
                $eventId = (string) $booking->google_calendar_event_id;
                $updated = $calendar->events->update(
                    $this->calendarId(),
                    $eventId,
                    $this->makeBookingEvent($booking, $eventId)
                );

                return $this->markBookingSyncSuccess($booking, $updated->getId(), 'updated');
            } catch (Throwable $exception) {
                if (! $this->isMissingGoogleEvent($exception)) {
                    throw $exception;
                }

                return $this->upsertBookingEvent($calendar, $booking, (string) $booking->google_calendar_event_id);
            }
        });
    }

    public function deleteBookingEvent(Booking $booking): array
    {
        return $this->runBookingSync($booking, 'delete', function (GoogleCalendar $calendar, Booking $booking): array {
            $candidateIds = array_values(array_unique(array_filter([
                $booking->google_calendar_event_id,
                $this->defaultBookingEventId($booking),
            ])));

            foreach ($candidateIds as $candidateId) {
                try {
                    $calendar->events->delete($this->calendarId(), $candidateId);
                    break;
                } catch (Throwable $exception) {
                    if ($this->isMissingGoogleEvent($exception)) {
                        continue;
                    }

                    throw $exception;
                }
            }

            return $this->markBookingSyncSuccess($booking, null, 'deleted', true);
        });
    }

    public function redirectUri(): ?string
    {
        $redirectUri = config('google-calendar.redirect_uri');

        return filled($redirectUri) ? (string) $redirectUri : null;
    }

    public function tokenPath(): ?string
    {
        return $this->resolvedTokenPath();
    }

    private function runBookingSync(Booking $booking, string $action, callable $callback): array
    {
        $booking->loadMissing(['client', 'requestedBy', 'warehouse']);

        try {
            $calendar = $this->makeAuthorizedCalendar();

            return $callback($calendar, $booking);
        } catch (Throwable $exception) {
            return $this->markBookingSyncFailure($booking, $action, $exception);
        }
    }

    private function upsertBookingEvent(GoogleCalendar $calendar, Booking $booking, ?string $preferredEventId): array
    {
        $eventId = filled($preferredEventId) ? (string) $preferredEventId : $this->defaultBookingEventId($booking);

        try {
            $created = $calendar->events->insert(
                $this->calendarId(),
                $this->makeBookingEvent($booking, $eventId)
            );

            return $this->markBookingSyncSuccess($booking, $created->getId(), 'created');
        } catch (Throwable $exception) {
            if (! $this->isGoogleConflict($exception)) {
                throw $exception;
            }

            $updated = $calendar->events->update(
                $this->calendarId(),
                $eventId,
                $this->makeBookingEvent($booking, $eventId)
            );

            return $this->markBookingSyncSuccess($booking, $updated->getId(), 'updated');
        }
    }

    private function makeBookingEvent(Booking $booking, string $eventId): GoogleCalendarEvent
    {
        $timezone = config('app.timezone', 'Europe/Madrid');
        [$start, $end, $allDay] = $this->bookingEventWindow($booking, $timezone);
        $event = new GoogleCalendarEvent();
        $event->setId($eventId);
        $event->setSummary($this->bookingEventSummary($booking));
        $event->setDescription($this->bookingEventDescription($booking));
        $event->setLocation($this->bookingEventLocation($booking));
        $event->setColorId($this->bookingEventColorId($booking));
        $event->setStart($this->makeEventDateTime($start, $timezone, $allDay));
        $event->setEnd($this->makeEventDateTime($end, $timezone, $allDay));

        return $event;
    }

    /**
     * @return array{0: Carbon, 1: Carbon, 2: bool}
     */
    private function bookingEventWindow(Booking $booking, string $timezone): array
    {
        $date = $booking->scheduled_date?->copy()->setTimezone($timezone) ?? now($timezone);
        $timeFrom = $booking->scheduled_time_from;
        $timeTo = $booking->scheduled_time_to;

        if ($timeFrom === null && $timeTo === null) {
            $start = $date->copy()->startOfDay();
            $end = $start->copy()->addDay();

            return [$start, $end, true];
        }

        $anchorTime = $timeFrom ?? $timeTo ?? '09:00:00';
        [$startHours, $startMinutes, $startSeconds] = $this->explodeTimeString($anchorTime);
        $start = $date->copy()->setTime($startHours, $startMinutes, $startSeconds);

        if ($timeTo !== null) {
            [$endHours, $endMinutes, $endSeconds] = $this->explodeTimeString($timeTo);
            $end = $date->copy()->setTime($endHours, $endMinutes, $endSeconds);

            if ($end->lte($start)) {
                $end = $start->copy()->addHour();
            }
        } else {
            $end = $start->copy()->addHour();
        }

        return [$start, $end, false];
    }

    /**
     * @return array{0: int, 1: int, 2: int}
     */
    private function explodeTimeString(string $time): array
    {
        $parts = array_pad(explode(':', $time), 3, '0');

        return [
            (int) $parts[0],
            (int) $parts[1],
            (int) $parts[2],
        ];
    }

    private function makeEventDateTime(Carbon $value, string $timezone, bool $allDay): GoogleCalendarEventDateTime
    {
        $dateTime = new GoogleCalendarEventDateTime();

        if ($allDay) {
            $dateTime->setDate($value->copy()->setTimezone($timezone)->toDateString());

            return $dateTime;
        }

        $dateTime->setDateTime($value->copy()->setTimezone($timezone)->toRfc3339String());
        $dateTime->setTimeZone($timezone);

        return $dateTime;
    }

    private function bookingEventSummary(Booking $booking): string
    {
        $clientName = Str::upper((string) ($booking->client?->name ?? 'MAXIMO'));
        $type = $booking->typeLabel();

        return trim($clientName.' - Booking '.$type);
    }

    private function bookingEventDescription(Booking $booking): string
    {
        $lines = [
            'Codigo booking WMS: '.$booking->referenceCode(),
            'Cliente: '.($booking->client?->name ?? 'Sin cliente'),
            'Solicitante: '.($booking->requestedBy?->name ?? 'Sin usuario'),
            'Email solicitante: '.($booking->requestedBy?->email ?? 'Sin email'),
            'Telefono contacto: '.($booking->contact_phone ?: 'Sin telefono'),
            'Tipo de operacion: '.$booking->typeLabel(),
            'Fecha y hora: '.$booking->scheduledWindowLabel(),
            'Estado: '.$booking->statusLabel(),
            'Transportista: '.($booking->carrier_name ?: 'Sin transportista'),
            'Matricula: '.($booking->vehicle_plate ?: 'Sin matricula'),
            'Conductor: '.($booking->driver_name ?: 'Sin conductor'),
            'Pallets previstos: '.number_format($booking->pallets_expected ?? 0, 0, ',', '.'),
            'Observaciones: '.($booking->notes ?: 'Sin observaciones'),
            'Enlace WMS: '.route('bookings.show', $booking),
        ];

        if (filled($booking->origin_destination)) {
            $lines[] = 'Origen / destino: '.$booking->origin_destination;
        }

        if (filled($booking->document_reference)) {
            $lines[] = 'Referencia documental: '.$booking->document_reference;
        }

        if (filled($booking->loading_dock)) {
            $lines[] = 'Muelle: '.$booking->loading_dock;
        }

        if (filled($booking->internal_notes)) {
            $lines[] = 'Notas internas: '.$booking->internal_notes;
        }

        return implode("\n", $lines);
    }

    private function bookingEventLocation(Booking $booking): string
    {
        $parts = array_filter([
            $booking->warehouse?->name,
            $booking->loading_dock,
        ]);

        if ($parts === []) {
            return 'Maximo Servicios Logisticos';
        }

        return implode(' - ', $parts);
    }

    private function bookingEventColorId(Booking $booking): string
    {
        return match ($booking->status) {
            Booking::STATUS_REQUESTED => '5',
            Booking::STATUS_APPROVED, Booking::STATUS_PLANNED => '9',
            Booking::STATUS_IN_PROGRESS => '10',
            Booking::STATUS_COMPLETED => '2',
            Booking::STATUS_REJECTED, Booking::STATUS_CANCELLED => '11',
            default => '8',
        };
    }

    private function defaultBookingEventId(Booking $booking): string
    {
        return 'booking'.Str::lower(base_convert((string) $booking->id, 10, 32));
    }

    private function markBookingSyncSuccess(Booking $booking, ?string $eventId, string $action, bool $clearEventId = false): array
    {
        $attributes = [
            'google_calendar_synced_at' => now(),
            'google_calendar_sync_error' => null,
        ];

        if ($clearEventId) {
            $attributes['google_calendar_event_id'] = null;
        } elseif (filled($eventId)) {
            $attributes['google_calendar_event_id'] = (string) $eventId;
        }

        $booking->forceFill($attributes)->saveQuietly();

        return [
            'success' => true,
            'action' => $action,
            'event_id' => $booking->fresh()->google_calendar_event_id,
            'warning' => null,
        ];
    }

    private function markBookingSyncFailure(Booking $booking, string $action, Throwable $exception): array
    {
        $summary = $this->summarizeException($exception);

        $booking->forceFill([
            'google_calendar_sync_error' => $summary,
        ])->saveQuietly();

        $this->logWarning('Fallo al sincronizar booking con Google Calendar.', [
            'booking_id' => $booking->id,
            'action' => $action,
            'event_id' => $booking->google_calendar_event_id,
            'exception' => $exception::class,
            'error' => $summary,
        ]);

        return [
            'success' => false,
            'action' => $action,
            'event_id' => $booking->google_calendar_event_id,
            'warning' => 'Booking registrado en WMS, pero no se pudo sincronizar con Google Calendar. Revisalo desde administracion.',
            'error' => $summary,
        ];
    }

    private function summarizeException(Throwable $exception): string
    {
        $message = trim((string) preg_replace('/\s+/', ' ', $exception->getMessage()));

        if ($message === '') {
            $message = $exception::class;
        }

        return Str::limit($message, 1000, '...');
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
        $client->setScopes([GoogleCalendar::CALENDAR_EVENTS]);
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        return $client;
    }

    private function makeAuthorizedCalendar(): GoogleCalendar
    {
        if (! $this->isEnabled()) {
            throw new RuntimeException('Google Calendar esta desactivado en el entorno.');
        }

        if (! $this->usesOAuth()) {
            throw new RuntimeException('El modo de autenticacion configurado no esta soportado.');
        }

        if (! $this->hasRequiredConfiguration()) {
            $this->logWarning('Google Calendar activo pero con configuracion incompleta.');
            throw new RuntimeException('Faltan variables obligatorias de Google Calendar en el entorno.');
        }

        $token = $this->readStoredToken();

        if ($token === null) {
            $this->logWarning('Google Calendar activo pero sin token OAuth local.');
            throw new RuntimeException('Todavia no hay un token OAuth local guardado para Google Calendar.');
        }

        $client = $this->makeOAuthClient();
        $client->setAccessToken($token);

        if ($client->isAccessTokenExpired()) {
            $refreshToken = $client->getRefreshToken();

            if (blank($refreshToken)) {
                $this->logWarning('El token de Google Calendar ha expirado y no hay refresh token.');
                throw new RuntimeException('El token de Google Calendar ha expirado y no se puede refrescar.');
            }

            $refreshedToken = $client->fetchAccessTokenWithRefreshToken($refreshToken);

            if (! is_array($refreshedToken) || isset($refreshedToken['error'])) {
                $this->logWarning('No se pudo refrescar el token de Google Calendar.', [
                    'has_error' => is_array($refreshedToken) && isset($refreshedToken['error']),
                ]);

                throw new RuntimeException('No se pudo refrescar el token de Google Calendar.');
            }

            $this->storeToken($client->getAccessToken());
        }

        return new GoogleCalendar($client);
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

        if (preg_match('/^[A-Za-z]:[\\\\\\/]/', $configuredPath) === 1 || Str::startsWith($configuredPath, ['/', '\\'])) {
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
            throw new RuntimeException('No hay una ruta configurada para guardar el token OAuth.');
        }

        $directory = dirname($path);
        File::ensureDirectoryExists($directory);

        if (! File::isDirectory($directory)) {
            throw new RuntimeException('No se pudo preparar la carpeta del token OAuth.');
        }

        $written = File::put($path, json_encode($token, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        if ($written === false) {
            throw new RuntimeException('No se pudo escribir el token OAuth de Google Calendar.');
        }
    }

    private function isMissingGoogleEvent(Throwable $exception): bool
    {
        return $exception->getCode() === 404
            || str_contains(Str::lower($exception->getMessage()), 'not found')
            || str_contains((string) $exception->getMessage(), '404');
    }

    private function isGoogleConflict(Throwable $exception): bool
    {
        $message = Str::lower($exception->getMessage());

        return $exception->getCode() === 409
            || str_contains($message, 'already exists')
            || str_contains((string) $exception->getMessage(), '409');
    }

    private function logWarning(string $message, array $context = []): void
    {
        Log::warning($message, array_merge([
            'channel' => 'google_calendar',
        ], $context));
    }
}
