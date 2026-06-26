<?php

namespace Tests\Feature;

use App\Models\AccessRequest;
use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\DB;
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
            ->assertSee('Panel operativo')
            ->assertSee('Operador MAXIMO');
    }

    public function test_dashboard_requires_authentication(): void
    {
        $this->get(route('dashboard'))
            ->assertRedirect(route('login'));
    }

    public function test_access_request_can_be_submitted(): void
    {
        $this->seed(RoleSeeder::class);
        $this->configureBrevo();

        $adminRole = Role::query()->where('slug', Role::ADMINISTRACION)->firstOrFail();
        $superadminRole = Role::query()->where('slug', Role::SUPERADMIN)->firstOrFail();

        User::factory()->create([
            'email' => 'administracion@maximosl.com',
            'role_id' => $adminRole->id,
            'active' => true,
        ]);

        User::factory()->create([
            'email' => 'superadmin@maximosl.com',
            'role_id' => $superadminRole->id,
            'active' => true,
        ]);

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
                'Solicitud recibida. Revisaremos el alta y te avisaremos por correo.'
            );

        $this->assertDatabaseHas('access_requests', [
            'email' => 'ana@friesland.test',
            'status' => 'pending',
        ]);

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request['subject'] === 'MAXIMO WMS - Nueva solicitud de acceso'
                && collect($request['to'])->pluck('email')->all() === [
                    'administracion@maximosl.com',
                    'superadmin@maximosl.com',
                ]
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
                'Solicitud recibida. Revisaremos el alta y te avisaremos por correo.'
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

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);

        Http::assertSent(function ($request) use ($user): bool {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request['subject'] === 'Restablecer contrasena - MAXIMO WMS'
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

    public function test_password_reset_request_with_missing_brevo_configuration_keeps_generic_response(): void
    {
        config([
            'services.brevo.key' => null,
            'mail.from.address' => 'sistema@maximosl.com',
            'mail.from.name' => 'MAXIMO WMS',
        ]);

        $user = User::factory()->create([
            'email' => 'operador@maximo.test',
            'active' => true,
        ]);

        $this->post(route('password.email'), [
                'email' => 'operador@maximo.test',
            ])->assertSessionHas(
                'status',
                'Si el correo pertenece a una cuenta activa, recibiras un enlace para restablecer la contrasena.'
            );

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);
    }

    public function test_inactive_user_does_not_receive_password_reset_email(): void
    {
        $this->configureBrevo();

        Http::fake();

        $user = User::factory()->create([
            'email' => 'inactivo@maximo.test',
            'active' => false,
        ]);

        $this->post(route('password.email'), [
            'email' => $user->email,
        ])->assertSessionHas(
            'status',
            'Si el correo pertenece a una cuenta activa, recibiras un enlace para restablecer la contrasena.'
        );

        $this->assertDatabaseMissing('password_reset_tokens', [
            'email' => $user->email,
        ]);

        Http::assertNothingSent();
    }

    public function test_password_reset_request_creates_valid_token(): void
    {
        $this->configureBrevo();

        Http::fake([
            'https://api.brevo.com/*' => Http::response([
                'messageId' => 'password-reset-token',
            ], 201),
        ]);

        $user = User::factory()->create([
            'email' => 'token@maximo.test',
            'active' => true,
        ]);

        $this->post(route('password.email'), [
            'email' => $user->email,
        ])->assertSessionHas(
            'status',
            'Si el correo pertenece a una cuenta activa, recibiras un enlace para restablecer la contrasena.'
        );

        Http::assertSent(function ($request) use ($user): bool {
            preg_match('#/reset-password/([^"?]+)#', (string) $request['htmlContent'], $matches);

            return isset($matches[1]) && Password::broker()->tokenExists($user, $matches[1]);
        });
    }

    public function test_user_approved_from_access_request_can_request_password_reset(): void
    {
        $this->configureBrevo();

        Http::fake([
            'https://api.brevo.com/*' => Http::response([
                'messageId' => 'password-reset-approved',
            ], 201),
        ]);

        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $clientRole = Role::query()->where('slug', Role::CLIENTE)->firstOrFail();
        $user = User::factory()->create([
            'name' => 'Cliente Aprobado',
            'email' => 'aprobado@friesland.test',
            'role_id' => $clientRole->id,
            'client_id' => $client->id,
            'active' => true,
        ]);

        AccessRequest::query()->create([
            'name' => $user->name,
            'company' => 'Friesland',
            'email' => $user->email,
            'notes' => 'Alta aprobada',
            'status' => AccessRequest::STATUS_APPROVED,
            'user_id' => $user->id,
            'client_id' => $client->id,
            'approved_at' => now(),
        ]);

        $this->post(route('password.email'), [
            'email' => $user->email,
        ])->assertSessionHas(
            'status',
            'Si el correo pertenece a una cuenta activa, recibiras un enlace para restablecer la contrasena.'
        );

        $this->assertDatabaseHas('password_reset_tokens', [
            'email' => $user->email,
        ]);

        Http::assertSent(fn ($request): bool => $request['to'][0]['email'] === $user->email);
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
