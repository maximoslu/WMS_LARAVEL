<?php

namespace App\Console\Commands;

use App\Exceptions\BrevoMailConfigurationException;
use App\Services\BrevoMailService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

class WmsTestMailCommand extends Command
{
    protected $signature = 'wms:test-mail {recipient : Email de destino para la prueba}';

    protected $description = 'Envia un correo de prueba de MAXIMO WMS usando Brevo API.';

    public function handle(BrevoMailService $brevoMailService): int
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
            $brevoMailService->sendTestMail($recipient);
        } catch (BrevoMailConfigurationException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (Throwable $exception) {
            $this->error('No se pudo enviar el correo de prueba por Brevo API.');
            $this->line('Detalle: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Correo de prueba enviado correctamente a '.$recipient.'.');

        return self::SUCCESS;
    }
}
