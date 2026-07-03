<!DOCTYPE html>
<html lang="es">
    <body style="margin:0;padding:24px;background:#edf3f7;font-family:Arial,sans-serif;color:#40515e;">
        <div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #d8e3ea;border-radius:16px;padding:32px;">
            <p style="margin:0 0 12px;color:#0d8b9d;font-size:12px;font-weight:700;letter-spacing:0.12em;text-transform:uppercase;">Solicitud registrada</p>
            <h1 style="margin:0 0 16px;color:#13222e;font-size:28px;line-height:1.1;">MAXIMO WMS</h1>
            <p style="margin:0 0 20px;font-size:15px;line-height:1.6;">Se ha recibido una nueva solicitud de acceso.</p>
            <table style="width:100%;border-collapse:collapse;font-size:14px;">
                <tr>
                    <td style="padding:8px 0;font-weight:700;color:#13222e;">Nombre</td>
                    <td style="padding:8px 0;">{{ $accessRequest->name }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;font-weight:700;color:#13222e;">Email</td>
                    <td style="padding:8px 0;">{{ $accessRequest->email }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;font-weight:700;color:#13222e;">Empresa</td>
                    <td style="padding:8px 0;">{{ $accessRequest->company ?: 'No indicada' }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;font-weight:700;color:#13222e;">Mensaje</td>
                    <td style="padding:8px 0;">{{ $accessRequest->notes ?: 'Sin observaciones' }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;font-weight:700;color:#13222e;">Fecha</td>
                    <td style="padding:8px 0;">{{ $accessRequest->created_at?->format('Y-m-d H:i:s') }}</td>
                </tr>
            </table>
            <hr style="border:none;border-top:1px solid #d8e3ea;margin:24px 0;">
            <p style="margin:0 0 18px;font-size:14px;line-height:1.6;">
                Revisa la solicitud desde el panel interno:
                <a href="{{ $reviewUrl }}" style="color:#0d8b9d;font-weight:700;">Solicitudes de acceso</a>
            </p>
            <p style="margin:0;font-size:12px;color:#6d7d89;">MAXIMO Servicios Logisticos</p>
        </div>
    </body>
</html>

