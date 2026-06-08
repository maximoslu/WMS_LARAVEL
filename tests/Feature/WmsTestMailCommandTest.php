<?php

namespace Tests\Feature;

use App\Mail\WmsTestMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class WmsTestMailCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_wms_test_mail_command_sends_mail_to_requested_recipient(): void
    {
        Mail::fake();

        $this->artisan('wms:test-mail', [
            'recipient' => 'correo@example.com',
        ])->assertSuccessful();

        Mail::assertSent(WmsTestMail::class, function (WmsTestMail $mail): bool {
            return $mail->hasTo('correo@example.com');
        });
    }

    public function test_wms_test_mail_command_rejects_invalid_email(): void
    {
        Mail::fake();

        $this->artisan('wms:test-mail', [
            'recipient' => 'correo-invalido',
        ])->assertFailed();

        Mail::assertNothingSent();
    }
}
