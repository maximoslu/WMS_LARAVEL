<!DOCTYPE html>
<html lang="es">
    <body style="margin:0;padding:24px;background:#edf3f7;font-family:Arial,sans-serif;color:#40515e;">
        <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #d8e3ea;border-radius:16px;padding:32px;">
            <p style="margin:0 0 12px;color:#0d8b9d;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;">Acceso aprobado</p>
            <h1 style="margin:0 0 16px;color:#13222e;font-size:28px;line-height:1.1;">Tu acceso ya esta listo</h1>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Hola {{ $accessRequest->name }}, hemos aprobado tu solicitud para el cliente
                <strong>{{ $accessRequest->client?->name ?? 'asignado' }}</strong>.
            </p>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Puedes entrar en el WMS desde este enlace:
                <a href="{{ $loginUrl }}" style="color:#0d8b9d;font-weight:700;">Acceder a MAXIMO WMS</a>
            </p>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Por seguridad no enviamos contrasenas por correo. Si no conoces tu clave, usa
                <a href="{{ $resetUrl }}" style="color:#0d8b9d;font-weight:700;">Recuperar contrasena</a>
                para generar una nueva.
            </p>
            <p style="margin:0;font-size:12px;color:#6d7d89;">MAXIMO Servicios Logisticos</p>
        </div>
    </body>
</html>

