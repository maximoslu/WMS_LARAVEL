<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Albaran {{ $dispatch->dispatchNumber() }}</title>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #13222e; }
            h1 { font-size: 20px; margin-bottom: 8px; }
            table { width: 100%; border-collapse: collapse; margin-top: 12px; }
            th, td { border: 1px solid #cdd7df; padding: 8px; text-align: left; }
            th { background: #eef5f8; }
            .meta p { margin: 4px 0; }
            .box { margin-top: 18px; border: 1px solid #cdd7df; padding: 12px; min-height: 92px; }
        </style>
    </head>
    <body>
        <h1>Albaran de salida</h1>

        <div class="meta">
            <p><strong>Numero:</strong> {{ $dispatch->dispatchNumber() }}</p>
            <p><strong>Fecha de salida:</strong> {{ ($dispatch->sent_at ?? now())->format('d/m/Y H:i') }}</p>
            <p><strong>Cliente:</strong> {{ $dispatch->client?->name ?? 'Sin cliente' }}</p>
            <p><strong>Direccion de entrega:</strong> {{ $dispatch->client?->formattedDeliveryAddress() ?: 'Pendiente en ficha de cliente' }}</p>
            <p><strong>Observaciones:</strong> {{ $dispatch->notes ?: 'Sin observaciones' }}</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Mercancia</th>
                    <th>Descripcion</th>
                    <th>Lote</th>
                    <th>Pallets</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($dispatch->lines as $line)
                    <tr>
                        <td>{{ $line->sku }}</td>
                        <td>{{ $line->description }}</td>
                        <td>{{ $line->lot ?: 'Sin lote' }}</td>
                        <td>{{ number_format($line->pallets, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <p><strong>Total pallets:</strong> {{ number_format($dispatch->palletsCount(), 0, ',', '.') }}</p>

        <div class="box"><strong>Firma / Recibi:</strong></div>
    </body>
</html>
