<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WmsTestMailCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_sends_test_mail_via_brevo(): void
    {
        $this->configureBrevo();

        Http::fake([
            'https://api.brevo.com/*' => Http::response([
                'messageId' => 'test-mail-1',
            ], 201),
        ]);

        $this->artisan('wms:test-mail', [
            'recipient' => 'administracion@maximosl.com',
        ])->expectsOutput('Correo de prueba enviado correctamente a administracion@maximosl.com.')
            ->assertSuccessful();

        Http::assertSent(function ($request): bool {
            return $request->url() === 'https://api.brevo.com/v3/smtp/email'
                && $request['subject'] === 'MAXIMO WMS - Prueba de correo'
                && $request['to'][0]['email'] === 'administracion@maximosl.com';
        });
    }

    public function test_command_rejects_invalid_email(): void
    {
        $this->artisan('wms:test-mail', [
            'recipient' => 'correo-invalido',
        ])->expectsOutput('El destinatario indicado no es un email valido.')
            ->assertFailed();
    }

    public function test_command_fails_with_clear_error_when_brevo_key_is_missing(): void
    {
        config([
            'services.brevo.key' => null,
            'mail.from.address' => 'sistema@maximosl.com',
            'mail.from.name' => 'MAXIMO WMS',
        ]);

        $this->artisan('wms:test-mail', [
            'recipient' => 'administracion@maximosl.com',
        ])->expectsOutput('Falta configurar BREVO_API_KEY para el envio de correo.')
            ->assertFailed();
    }

    private function configureBrevo(): void
    {
        config([
            'services.brevo.key' => 'test-brevo-key',
            'services.brevo.base_url' => 'https://api.brevo.com/v3',
            'mail.from.address' => 'sistema@maximosl.com',
            'mail.from.name' => 'MAXIMO WMS',
        ]);
    }
}
