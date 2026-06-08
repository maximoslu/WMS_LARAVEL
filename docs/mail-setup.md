# WMS Mail Setup

## Variables necesarias en local y Forge

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=
MAIL_PORT=
MAIL_USERNAME=
MAIL_PASSWORD=
MAIL_ENCRYPTION=
MAIL_FROM_ADDRESS=
MAIL_FROM_NAME="MAXIMO WMS"
WMS_ACCESS_REQUEST_NOTIFICATION_EMAIL=administracion@maximosl.com
```

No incluyas credenciales reales en el repositorio.

## Flujos cubiertos

- Recuperacion de contrasena con el broker estandar de Laravel.
- Correo corporativo base de MAXIMO WMS con tema markdown sobrio.
- Notificacion interna al registrar una solicitud de acceso.
- Comando de prueba local seguro.

## Como probar local

### Correo de prueba

```bash
php artisan wms:test-mail correo@dominio.com
```

Si falla, revisa la configuracion `MAIL_*`, el mailer activo y los logs.

### Recuperacion de contrasena

1. Accede a `/forgot-password`.
2. Solicita el enlace con un usuario existente.
3. Verifica que Laravel dispare la notificacion de reset.
4. Abre el enlace recibido.
5. Cambia la contrasena con token valido.

## Solicitudes de acceso

Cada envio en `/solicitar-acceso`:

1. Guarda el registro en `access_requests`.
2. Intenta enviar una notificacion interna al correo definido en:

```dotenv
WMS_ACCESS_REQUEST_NOTIFICATION_EMAIL
```

Si el envio falla, la solicitud sigue quedando registrada y el error se reporta en logs.

## Que revisar en Forge

- Variables `MAIL_*` en el `.env` del servidor.
- `MAIL_FROM_NAME="MAXIMO WMS"`.
- `WMS_ACCESS_REQUEST_NOTIFICATION_EMAIL`.
- Logs de Laravel si el envio falla.
- Si mas adelante se decide usar cola, revisar workers y jobs fallidos.
- Entrega real del correo: SPF, DKIM, DMARC, reputacion y carpeta spam.

## Estado por defecto en codigo

- El mailer por defecto sigue dependiendo de `MAIL_MAILER`.
- Si no se configura SMTP real, Laravel cae en `log` por defecto.
- Eso permite pruebas seguras sin credenciales en el repositorio.
