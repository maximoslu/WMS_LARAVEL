<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
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
            ->assertSee('Bienvenido, Operador MAXIMO');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_access_request_can_be_submitted(): void
    {
        $this->configureBrevo();

        Http::fake([
            'https://api.brevo.com/*' => Http::response([
                'messageId' => 'access-request-1',
            ], 201),
        ]);

        $this->post(route('access-requests.store'), [
            'name' => 'Ana Responsable',
            'company' => 'Friesland',
            'email' => 'ana@friesland.test',
            'notes' => 'Necesito acceso para validar operativa de stock.',
        ])->assertRedirect(route('access-requests.create'))
            ->assertSessionHas(
                'status',
                'Solicitud enviada. El equipo de MAXIMO revisara tu peticion y te contactara.'
            );

        $this->assertDatabaseHas('access_requests', [
            'email' => 'ana@friesland.test',
            'status' => 'pending',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request['subject'] === 'MAXIMO WMS - Nueva solicitud de acceso'
                && $request['to'][0]['email'] === 'administracion@maximosl.com'
                && $request['sender']['email'] === 'sistema@maximosl.com';
        });
    }

    public function test_access_request_keeps_working_when_brevo_is_not_configured(): void
    {
        config([
            'services.brevo.key' => null,
            'mail.from.address' => 'sistema@maximosl.com',
            'mail.from.name' => 'MAXIMO WMS',
        ]);

        Http::fake();

        $this->post(route('access-requests.store'), [
            'name' => 'Ana Responsable',
            'company' => 'Friesland',
            'email' => 'ana@friesland.test',
            'notes' => 'Necesito acceso para validar operativa de stock.',
        ])->assertRedirect(route('access-requests.create'))
            ->assertSessionHas(
                'status',
                'Solicitud enviada. La notificacion interna por correo no ha podido verificarse.'
            );

        $this->assertDatabaseHas('access_requests', [
            'email' => 'ana@friesland.test',
        ]);

        Http::assertNothingSent();
    }

    public function test_password_reset_link_can_be_requested(): void
    {
        $this->configureBrevo();

        Http::fake([
            'https://api.brevo.com/*' => Http::response([
                'messageId' => 'password-reset-1',
            ], 201),
        ]);

        $user = User::factory()->create([
            'email' => 'operador@maximo.test',
        ]);

        $this->post(route('password.email'), [
            'email' => $user->email,
        ])->assertSessionHas(
            'status',
            'Si el correo pertenece a una cuenta activa, recibiras un enlace para restablecer la contrasena.'
        );

        Http::assertSent(function ($request) use ($user): bool {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request['subject'] === 'MAXIMO WMS - Recuperacion de contrasena'
                && $request['to'][0]['email'] === $user->email
                && str_contains(
                    $request['htmlContent'],
                    '/reset-password/'
                )
                && str_contains($request['htmlContent'], urlencode($user->email));
        });
    }

    public function test_password_reset_request_for_unknown_email_keeps_generic_response(): void
    {
        $this->configureBrevo();

        Http::fake();

        $this->post(route('password.email'), [
            'email' => 'desconocido@maximo.test',
        ])->assertSessionHas(
            'status',
            'Si el correo pertenece a una cuenta activa, recibiras un enlace para restablecer la contrasena.'
        );

        Http::assertNothingSent();
    }

    public function test_password_reset_request_requires_brevo_configuration(): void
    {
        config([
            'services.brevo.key' => null,
            'mail.from.address' => 'sistema@maximosl.com',
            'mail.from.name' => 'MAXIMO WMS',
        ]);

        $this->from(route('password.request'))
            ->post(route('password.email'), [
                'email' => 'operador@maximo.test',
            ])
            ->assertRedirect(route('password.request'))
            ->assertSessionHasErrors([
                'email' => 'El sistema de correo no esta configurado correctamente. Contacta con administracion.',
            ]);
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

        $this->post(route('password.store'), [
            'token' => 'token-invalido',
            'email' => $user->email,
            'password' => 'NuevaClave123!',
            'password_confirmation' => 'NuevaClave123!',
        ])->assertSessionHasErrors('email');

        $this->assertFalse(Hash::check('NuevaClave123!', $user->fresh()->password));
    }

    private function configureBrevo(): void
    {
        config([
            'services.brevo.key' => 'test-brevo-key',
            'services.brevo.base_url' => 'https://api.brevo.com/v3',
            'mail.from.address' => 'sistema@maximosl.com',
            'mail.from.name' => 'MAXIMO WMS',
            'wms.access_request_notification_email' => 'administracion@maximosl.com',
        ]);
    }
}
