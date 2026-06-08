<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\AccessRequestSubmitted;
use App\Notifications\ResetPasswordNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Tests\TestCase;

class AuthenticationFlowTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_root_redirects_to_login(): void
    {
        $this->get('/')
            ->assertRedirect(route('login'));
    }

    public function test_login_screen_is_available(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('MAXIMO WMS')
            ->assertSee('Iniciar sesion');
    }

    public function test_user_can_authenticate_and_reach_dashboard(): void
    {
        $user = User::factory()->create([
            'name' => 'Operador MAXIMO',
            'password' => Hash::make('password'),
        ]);

        $this->post(route('login.store'), [
            'email' => $user->email,
            'password' => 'password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);

        $this->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Rol')
            ->assertSee('Sin rol asignado');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_access_request_can_be_submitted_and_internal_notification_is_sent(): void
    {
        Notification::fake();

        $this->post(route('access-requests.store'), [
            'name' => 'Ana Responsable',
            'company' => 'Friesland',
            'email' => 'ana@friesland.test',
            'notes' => 'Necesito acceso para validar operativa de stock.',
        ])->assertRedirect(route('access-requests.create'));

        $this->assertDatabaseHas('access_requests', [
            'email' => 'ana@friesland.test',
            'status' => 'pending',
        ]);

        Notification::assertSentOnDemand(
            AccessRequestSubmitted::class,
            function (AccessRequestSubmitted $notification, array $channels, object $notifiable): bool {
                return $channels === ['mail']
                    && ($notifiable->routes['mail'] ?? null) === config('wms.access_request_notification_email')
                    && $notification->accessRequest->email === 'ana@friesland.test';
            }
        );
    }

    public function test_password_reset_link_can_be_requested_and_notification_is_sent(): void
    {
        Notification::fake();

        $user = User::factory()->create([
            'email' => 'operador@maximo.test',
        ]);

        $this->post(route('password.email'), [
            'email' => $user->email,
        ])->assertSessionHas('status');

        Notification::assertSentTo($user, ResetPasswordNotification::class);
    }

    public function test_password_reset_link_request_returns_generic_response_for_unknown_email(): void
    {
        Notification::fake();

        $this->post(route('password.email'), [
            'email' => 'desconocido@maximo.test',
        ])->assertSessionHas('status');

        Notification::assertNothingSent();
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'operador@maximo.test',
        ]);

        $token = Password::broker()->createToken($user);

        $this->post(route('password.store'), [
            'token' => $token,
            'email' => $user->email,
            'password' => 'NuevaClave123!',
            'password_confirmation' => 'NuevaClave123!',
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check('NuevaClave123!', $user->fresh()->password));
    }

    public function test_password_cannot_be_reset_with_invalid_token(): void
    {
        $user = User::factory()->create([
            'email' => 'operador@maximo.test',
        ]);

        $previousHash = $user->password;

        $this->from(route('password.reset', ['token' => 'token-invalido', 'email' => $user->email]))
            ->post(route('password.store'), [
                'token' => 'token-invalido',
                'email' => $user->email,
                'password' => 'NuevaClave123!',
                'password_confirmation' => 'NuevaClave123!',
            ])->assertSessionHasErrors('email');

        $this->assertSame($previousHash, $user->fresh()->password);
    }
}
