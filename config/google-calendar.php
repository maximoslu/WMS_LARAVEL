<?php

return [
    'enabled' => env('GOOGLE_CALENDAR_ENABLED', false),
    'auth_mode' => env('GOOGLE_CALENDAR_AUTH_MODE', 'oauth'),
    'calendar_id' => env('GOOGLE_CALENDAR_ID'),
    'client_id' => env('GOOGLE_CALENDAR_CLIENT_ID'),
    'client_secret' => env('GOOGLE_CALENDAR_CLIENT_SECRET'),
    'redirect_uri' => env('GOOGLE_CALENDAR_REDIRECT_URI', 'http://127.0.0.1:8000/google-calendar/oauth/callback'),
    'token_path' => env('GOOGLE_CALENDAR_TOKEN_PATH', 'storage/app/google/calendar-token.json'),
];
