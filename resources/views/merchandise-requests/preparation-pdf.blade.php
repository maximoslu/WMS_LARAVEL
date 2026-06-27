<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Preparacion {{ $merchandiseRequest->referenceCode() }}</title>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #13222e; }
            h1 { font-size: 20px; margin-bottom: 8px; }
            h2 { font-size: 14px; margin: 20px 0 8px; }
            table { width: 100%; border-collapse: collapse; margin-top: 12px; }
            th, td { border: 1px solid #cdd7df; padding: 8px; text-align: left; }
            th { background: #eef5f8; }
            .meta { margin-bottom: 12px; }
            .meta p { margin: 4px 0; }
            .notes { margin-top: 24px; border: 1px solid #cdd7df; min-height: 120px; padding: 12px; }
        </style>
    </head>
    <body>
        <h1>Hoja de preparación de pedido</h1>
        <div class="meta">
            <p><strong>Solicitud:</strong> {{ $merchandiseRequest->referenceCode() }}</p>
            <p><strong>Cliente:</strong> {{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</p>
            <p><strong>Fecha:</strong> {{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') }}</p>
            <p><strong>Estado:</strong> {{ $merchandiseRequest->statusLabel() }}</p>
            <p><strong>Total pallets:</strong> {{ number_format($merchandiseRequest->requestedPalletsCount(), 0, ',', '.') }}</p>
        </div>

        <table>
            <thead>
                <tr>
                    <th>Mercancia</th>
                    <th>Descripcion</th>
                    <th>Lote</th>
                    <th>Uds/pallet</th>
                    <th>Pallets</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($merchandiseRequest->lines as $line)
                    <tr>
                        <td>{{ $line->item?->sku ?? 'Articulo eliminado' }}</td>
                        <td>{{ $line->item?->description ?? 'Sin descripcion' }}</td>
                        <td>{{ $line->lot ?: 'Sin lote' }}</td>
                        <td>{{ number_format($line->units_per_pallet, 0, ',', '.') }}</td>
                        <td>{{ number_format($line->requested_pallets, 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <h2>Observaciones internas</h2>
        <div class="notes"></div>
    </body>
</html>
