<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <title>Nueva solicitud de mercancia</title>
</head>
<body style="margin:0;padding:24px;background:#f4f8fb;font-family:Arial,sans-serif;color:#13222e;">
    <div style="max-width:720px;margin:0 auto;background:#ffffff;border-radius:16px;padding:24px;border:1px solid rgba(53,83,107,0.12);">
        <h1 style="margin:0 0 16px;font-size:24px;line-height:1.2;">Nueva solicitud de mercancía - {{ $merchandiseRequest->client->name }}</h1>

        <p style="margin:0 0 16px;font-size:15px;line-height:1.6;">
            Se ha creado una nueva solicitud de mercancía y queda pendiente de preparación.
        </p>

        <table style="width:100%;border-collapse:collapse;margin:0 0 20px;">
            <tbody>
                <tr>
                    <td style="padding:8px 0;font-weight:700;">Cliente</td>
                    <td style="padding:8px 0;">{{ $merchandiseRequest->client->name }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;font-weight:700;">Solicitante</td>
                    <td style="padding:8px 0;">{{ $merchandiseRequest->requester?->name ?: 'Usuario no disponible' }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;font-weight:700;">Fecha</td>
                    <td style="padding:8px 0;">{{ optional($merchandiseRequest->requested_date)->format('d/m/Y') ?: '-' }}</td>
                </tr>
                <tr>
                    <td style="padding:8px 0;font-weight:700;">Referencia</td>
                    <td style="padding:8px 0;">{{ $merchandiseRequest->delivery_reference ?: '-' }}</td>
                </tr>
            </tbody>
        </table>

        <h2 style="margin:0 0 12px;font-size:18px;">Líneas</h2>

        <table style="width:100%;border-collapse:collapse;margin:0 0 20px;border:1px solid #d9e3ea;">
            <thead>
                <tr style="background:#f4f8fb;">
                    <th style="padding:10px;text-align:left;font-size:12px;text-transform:uppercase;">SKU</th>
                    <th style="padding:10px;text-align:left;font-size:12px;text-transform:uppercase;">Descripción</th>
                    <th style="padding:10px;text-align:left;font-size:12px;text-transform:uppercase;">Lote</th>
                    <th style="padding:10px;text-align:right;font-size:12px;text-transform:uppercase;">Palets</th>
                    <th style="padding:10px;text-align:right;font-size:12px;text-transform:uppercase;">Uds/palet</th>
                    <th style="padding:10px;text-align:right;font-size:12px;text-transform:uppercase;">Total uds</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($merchandiseRequest->lines as $line)
                    <tr>
                        <td style="padding:10px;border-top:1px solid #d9e3ea;">{{ $line->item->sku }}</td>
                        <td style="padding:10px;border-top:1px solid #d9e3ea;">{{ $line->item->description }}</td>
                        <td style="padding:10px;border-top:1px solid #d9e3ea;">{{ $line->lot ?: 'Sin lote' }}</td>
                        <td style="padding:10px;border-top:1px solid #d9e3ea;text-align:right;">{{ number_format($line->requested_pallets, 0, ',', '.') }}</td>
                        <td style="padding:10px;border-top:1px solid #d9e3ea;text-align:right;">{{ number_format($line->units_per_pallet, 0, ',', '.') }}</td>
                        <td style="padding:10px;border-top:1px solid #d9e3ea;text-align:right;">{{ number_format($line->requested_units, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p style="margin:0 0 16px;font-size:14px;line-height:1.6;">
            Enlace a la solicitud:
            <a href="{{ $requestUrl }}" style="color:#0d8b9d;">{{ $requestUrl }}</a>
        </p>
    </div>
</body>
</html>
