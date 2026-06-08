<?php

namespace App\Console\Commands;

use App\Mail\WmsTestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

class WmsTestMailCommand extends Command
{
    protected $signature = 'wms:test-mail {recipient : Email de destino para la prueba}';

    protected $description = 'Envía un correo de prueba de MAXIMO WMS al destinatario indicado.';

    public function handle(): int
    {
        $recipient = (string) $this->argument('recipient');

        $validator = Validator::make([
            'recipient' => $recipient,
        ], [
            'recipient' => ['required', 'email'],
        ]);

        if ($validator->fails()) {
            $this->error('El destinatario indicado no es un email valido.');

            return self::FAILURE;
        }

        try {
            Mail::to($recipient)->send(new WmsTestMail());
        } catch (Throwable $exception) {
            $this->error('No se pudo enviar el correo de prueba. Revisa la configuracion MAIL_* y los logs.');
            $this->line('Detalle: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Correo de prueba enviado correctamente a '.$recipient.'.');

        return self::SUCCESS;
    }
}
