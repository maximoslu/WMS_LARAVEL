<?php

namespace Tests\Unit;

use App\Services\GoogleCalendarService;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class GoogleCalendarServiceTest extends TestCase
{
    public function test_service_returns_empty_if_google_calendar_is_disabled(): void
    {
        config()->set('google-calendar.enabled', false);

        $events = app(GoogleCalendarService::class)->getEventsBetween(now(), now()->addDay());

        $this->assertTrue($events->isEmpty());
    }

    public function test_service_returns_empty_if_token_is_missing(): void
    {
        $missingTokenPath = storage_path('app/google/test-missing-token.json');
        File::delete($missingTokenPath);

        config()->set('google-calendar.enabled', true);
        config()->set('google-calendar.auth_mode', 'oauth');
        config()->set('google-calendar.calendar_id', 'calendar-id@test');
        config()->set('google-calendar.client_id', 'client-id');
        config()->set('google-calendar.client_secret', 'client-secret');
        config()->set('google-calendar.redirect_uri', 'http://127.0.0.1:8000/google-calendar/oauth/callback');
        config()->set('google-calendar.token_path', $missingTokenPath);

        $events = app(GoogleCalendarService::class)->getEventsBetween(now(), now()->addDay());

        $this->assertTrue($events->isEmpty());
    }
}
