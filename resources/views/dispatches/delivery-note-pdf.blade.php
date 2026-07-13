<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Albaran {{ $dispatch->dispatchNumber() }}</title>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #13222e; }
            h1 { font-size: 20px; margin: 0 0 6px; }
            h2 { font-size: 12px; margin: 0; text-transform: uppercase; letter-spacing: .08em; color: #5d7282; }
            table { width: 100%; border-collapse: collapse; margin-top: 16px; }
            th, td { border: 1px solid #cdd7df; padding: 8px; text-align: left; vertical-align: top; }
            th { background: #eef5f8; }
            .meta-grid { width: 100%; margin-top: 12px; }
            .meta-grid td { border: 0; padding: 0 12px 8px 0; }
            .meta-label { color: #6b7d8a; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; }
            .brand { width: 100%; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #d9e4ea; }
            .brand td { border: 0; padding: 0; vertical-align: middle; }
            .brand-logo { width: 140px; }
            .brand strong { display: block; font-size: 18px; }
            .box { margin-top: 20px; border: 1px solid #cdd7df; padding: 12px; min-height: 92px; }
            .totals { margin-top: 16px; }
        </style>
    </head>
    <body>
        @php($deliveredLines = $dispatch->lines->filter(fn ($line) => $line->hasDeliveredQuantity()))
        @php($logoPath = public_path('brand/maximo-logo-horizontal.png'))
        @php($logoAvailable = file_exists($logoPath) && (extension_loaded('gd') || extension_loaded('imagick')))

        <table class="brand">
            <tr>
                @if ($logoAvailable)
                    <td class="brand-logo">
                        <img src="{{ $logoPath }}" alt="Maximo Servicios Logisticos" width="130">
                    </td>
                @endif
                <td>
                    <h2>MAXIMO SERVICIOS LOGISTICOS</h2>
                    <strong>Albarán de salida</strong>
                </td>
            </tr>
        </table>

        <table class="meta-grid">
            <tr>
                <td>
                    <div class="meta-label">Número</div>
                    <strong>{{ $dispatch->dispatchNumber() }}</strong>
                </td>
                <td>
                    <div class="meta-label">Fecha de salida</div>
                    <strong>{{ ($dispatch->sent_at ?? now())->format('d/m/Y H:i') }}</strong>
                </td>
                <td>
                    <div class="meta-label">Estado</div>
                    <strong>{{ $dispatch->statusLabel() }}</strong>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="meta-label">Cliente</div>
                    <strong>{{ $dispatch->client?->name ?? 'Sin cliente' }}</strong>
                </td>
                <td colspan="2">
                    <div class="meta-label">Dirección de entrega</div>
                    <strong>{{ $dispatch->client?->formattedDeliveryAddress() ?: 'Pendiente en ficha de cliente' }}</strong>
                </td>
            </tr>
        </table>

        <table>
            <thead>
                <tr>
                    <th>Origen</th>
                    <th>Tipo</th>
                    <th>Mercancia</th>
                    <th>Lote</th>
                    <th>Ubicación destino</th>
                    <th>Solicitado</th>
                    <th>Pallets entregados / Picos entregados</th>
                    <th>Detalle</th>
                    <th>Observaciones</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($deliveredLines as $line)
                    <tr>
                        <td>{{ $line->lineOriginLabel() }}</td>
                        <td>{{ $line->lineTypeLabel() }}</td>
                        <td>
                            <strong>{{ $line->sku }}</strong><br>
                            {{ $line->description }}
                        </td>
                        <td>{{ $line->lot ?: 'Sin lote' }}</td>
                        <td>{{ $line->destination_location ?: '-' }}</td>
                        <td>{{ $line->requestedQuantityLabel() }}</td>
                        <td>{{ $line->loadedQuantityLabel() }}</td>
                        <td>{{ $line->unitsLabel() }}</td>
                        <td>{{ $line->loading_notes ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="totals">
            <p><strong>Total pallets entregados:</strong> {{ number_format($dispatch->loadedPalletsCount(), 0, ',', '.') }}</p>
            <p><strong>Total picos entregados:</strong> {{ number_format($dispatch->loadedPeaksCount(), 0, ',', '.') }}</p>
        </div>

        <div class="box"><strong>Firma / Recibí:</strong></div>
    </body>
</html>
