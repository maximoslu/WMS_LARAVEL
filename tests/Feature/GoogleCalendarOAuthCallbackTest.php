<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Services\GoogleCalendarService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Mockery;
use Tests\TestCase;

class GoogleCalendarOAuthCallbackTest extends TestCase
{
    use RefreshDatabase;

    public function test_callback_with_google_error_redirects_with_clear_message(): void
    {
        $this->seed(RoleSeeder::class);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $response = $this->actingAs($administracion)
            ->withSession(['google_calendar_oauth_state' => 'state-ok'])
            ->get(route('google-calendar.oauth.callback', [
                'state' => 'state-ok',
                'error' => 'access_denied',
                'error_description' => 'User denied access',
            ]));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('warning', 'No se ha podido conectar Google Calendar. Revisa la configuracion OAuth o consulta los logs.');
    }

    public function test_callback_without_code_redirects_with_clear_message(): void
    {
        $this->seed(RoleSeeder::class);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $response = $this->actingAs($administracion)
            ->withSession(['google_calendar_oauth_state' => 'state-ok'])
            ->get(route('google-calendar.oauth.callback', [
                'state' => 'state-ok',
            ]));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('warning', 'No se ha podido conectar Google Calendar. Revisa la configuracion OAuth o consulta los logs.');
    }

    public function test_callback_with_invalid_state_redirects_with_clear_message_and_logs_safely(): void
    {
        Log::spy();
        $this->seed(RoleSeeder::class);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $response = $this->actingAs($administracion)
            ->withSession(['google_calendar_oauth_state' => 'state-ok'])
            ->get(route('google-calendar.oauth.callback', [
                'state' => 'state-bad',
                'code' => 'oauth-code',
            ]));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('warning', 'No se ha podido conectar Google Calendar. Revisa la configuracion OAuth o consulta los logs.');

        Log::shouldHaveReceived('warning')
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'Fallo de validacion del state OAuth de Google Calendar.'
                    && ($context['channel'] ?? null) === 'google_calendar_oauth'
                    && ! array_key_exists('code', $context)
                    && ! array_key_exists('client_secret', $context)
                    && ! array_key_exists('access_token', $context)
                    && ! array_key_exists('refresh_token', $context);
            })
            ->once();
    }

    public function test_callback_with_valid_state_and_code_can_connect(): void
    {
        $this->seed(RoleSeeder::class);
        $administracion = $this->makeUserWithRole(Role::ADMINISTRACION);

        $service = Mockery::mock(GoogleCalendarService::class);
        $service->shouldReceive('redirectUri')->atLeast()->once()->andReturn('http://127.0.0.1:8000/google-calendar/oauth/callback');
        $service->shouldReceive('tokenPath')->atLeast()->once()->andReturn(storage_path('app/google/calendar-token.json'));
        $service->shouldReceive('handleOAuthCallback')
            ->once()
            ->with('oauth-code')
            ->andReturn([
                'success' => true,
                'reason' => 'connected',
                'has_refresh_token' => true,
            ]);
        $this->app->instance(GoogleCalendarService::class, $service);

        $response = $this->actingAs($administracion)
            ->withSession(['google_calendar_oauth_state' => 'state-ok'])
            ->withCookie('google_calendar_oauth_state', 'state-ok')
            ->get(route('google-calendar.oauth.callback', [
                'state' => 'state-ok',
                'code' => 'oauth-code',
            ]));

        $response->assertRedirect(route('dashboard'));
        $response->assertSessionHas('status', 'Google Calendar conectado correctamente.');
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => null,
        ]);
    }
}
