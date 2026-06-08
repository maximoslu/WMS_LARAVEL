<x-mail::message>
# MAXIMO WMS

Correo de prueba generado desde el comando `wms:test-mail`.

- Entorno: {{ config('app.env') }}
- Fecha: {{ $sentAt }}
- Mailer: {{ config('mail.default') }}

Si has recibido este mensaje, el flujo base de correo de MAXIMO WMS esta operativo.

Gracias,<br>
MAXIMO WMS
</x-mail::message>
