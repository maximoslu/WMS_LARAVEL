<!DOCTYPE html>
<html lang="es">
    <body style="margin:0;padding:24px;background:#edf3f7;font-family:Arial,sans-serif;color:#40515e;">
        <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #d8e3ea;border-radius:16px;padding:32px;">
            <p style="margin:0 0 12px;color:#0d8b9d;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;">Solicitud revisada</p>
            <h1 style="margin:0 0 16px;color:#13222e;font-size:28px;line-height:1.1;">No hemos podido aprobar el acceso</h1>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Hola {{ $accessRequest->name }}, hemos revisado tu solicitud y por ahora no podemos activarla.
            </p>
            <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
                Motivo: {{ $accessRequest->rejection_reason ?: 'Sin detalle adicional.' }}
            </p>
            <p style="margin:0;font-size:12px;color:#6d7d89;">MAXIMO Servicios Logisticos</p>
        </div>
    </body>
</html>

