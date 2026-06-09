# Configuracion de correo para WMS_LARAVEL

## Contexto

En Forge, los envios SMTP por los puertos 25, 465 y 587 dan timeout. Por ese motivo, WMS_LARAVEL no debe depender de SMTP para los correos operativos del sistema.

La integracion activa usa la API HTTPS de Brevo:

- Endpoint: `https://api.brevo.com/v3/smtp/email`
- Transporte: Laravel Http client
- Casos cubiertos:
  - `php artisan wms:test-mail destinatario@dominio.com`
  - recuperacion de contrasena
  - notificacion interna de solicitud de acceso

## Variables necesarias

Configurar estas variables en el entorno del servidor o del entorno local correspondiente:

- `BREVO_API_KEY`
- `MAIL_FROM_ADDRESS=sistema@maximosl.com`
- `MAIL_FROM_NAME="MAXIMO WMS"`
- `WMS_ACCESS_REQUEST_NOTIFICATION_EMAIL=administracion@maximosl.com`

No incluir credenciales reales en el repositorio.

## Pruebas recomendadas

1. Configurar las variables anteriores en el entorno.
2. Ejecutar `php artisan wms:test-mail administracion@maximosl.com`.
3. Solicitar un reseteo de contrasena desde `/forgot-password`.
4. Enviar una solicitud desde `/solicitar-acceso`.

Si falta `BREVO_API_KEY`, el sistema devuelve un error claro en el comando y evita un fallo opaco en la aplicacion web.
