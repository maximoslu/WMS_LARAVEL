<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Preparacion {{ $merchandiseRequest->referenceCode() }}</title>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #13222e; }
            h1 { font-size: 20px; margin: 0 0 6px; }
            h2 { font-size: 12px; margin: 0; text-transform: uppercase; letter-spacing: .08em; color: #5d7282; }
            table { width: 100%; border-collapse: collapse; margin-top: 16px; }
            th, td { border: 1px solid #cdd7df; padding: 6px; text-align: left; vertical-align: top; }
            th { background: #eef5f8; }
            .picking-location { color: #0a5f70; font-weight: bold; }
            .picking-quantity { display: block; margin-top: 2px; color: #5d7282; font-size: 10px; font-weight: normal; }
            .meta-grid { width: 100%; margin-top: 12px; }
            .meta-grid td { border: 0; padding: 0 12px 8px 0; }
            .meta-label { color: #6b7d8a; font-size: 10px; text-transform: uppercase; letter-spacing: .08em; }
            .brand { width: 100%; margin-bottom: 16px; padding-bottom: 12px; border-bottom: 2px solid #d9e4ea; }
            .brand td { border: 0; padding: 0; vertical-align: middle; }
            .brand-logo { width: 140px; }
            .brand strong { display: block; font-size: 18px; }
            .notes { margin-top: 24px; border: 1px solid #cdd7df; min-height: 120px; padding: 12px; }
        </style>
    </head>
    <body>
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
                    <strong>Hoja de preparación de pedido</strong>
                </td>
            </tr>
        </table>

        <table class="meta-grid">
            <tr>
                <td>
                    <div class="meta-label">Solicitud</div>
                    <strong>{{ $merchandiseRequest->referenceCode() }}</strong>
                </td>
                <td>
                    <div class="meta-label">Cliente</div>
                    <strong>{{ $merchandiseRequest->client?->name ?? 'Sin cliente' }}</strong>
                </td>
                <td>
                    <div class="meta-label">Fecha</div>
                    <strong>{{ $merchandiseRequest->submittedAt()?->format('d/m/Y H:i') }}</strong>
                </td>
            </tr>
            <tr>
                <td>
                    <div class="meta-label">Estado</div>
                    <strong>{{ $merchandiseRequest->statusLabel() }}</strong>
                </td>
                <td>
                    <div class="meta-label">Pallets</div>
                    <strong>{{ number_format($merchandiseRequest->requestedPalletsCount(), 0, ',', '.') }}</strong>
                </td>
                <td>
                    <div class="meta-label">Picos</div>
                    <strong>{{ number_format($merchandiseRequest->requestedPeaksCount(), 0, ',', '.') }}</strong>
                </td>
            </tr>
        </table>

        <table>
            <thead>
                <tr>
                    <th>Tipo</th>
                    <th>Mercancia</th>
                    <th>Lote</th>
                    <th>Detalle</th>
                    <th>Solicitado</th>
                    <th>Ubicación destino</th>
                    <th>Ubicación de recogida</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($merchandiseRequest->lines as $line): ?>
                    <?php
                        $dispatchLine = $merchandiseRequest->dispatch?->lines->first(
                            fn ($candidate) => (int) $candidate->source_request_line_id === (int) $line->id
                        ) ?? $merchandiseRequest->dispatch?->lines->first(
                            fn ($candidate) => ! $candidate->is_extra_line
                                && (int) $candidate->item_id === (int) $line->item_id
                                && (string) $candidate->line_type === (string) $line->line_type
                                && (int) ($candidate->stock_peak_index ?? 0) === (int) ($line->stock_peak_index ?? 0)
                        );
                        $pickingLocationSummaries = $dispatchLine?->pickingLocationSummaries() ?? collect();
                        if ($pickingLocationSummaries->isEmpty() && $line->stockPallet !== null) {
                            $pickingLocationSummaries = collect([[
                                'location' => $line->stockPallet->pickingLocationLabel() ?? 'Sin ubicación registrada',
                                'quantity' => null,
                            ]]);
                        }
                    ?>
                    <tr>
                        <td>{{ $line->lineTypeLabel() }}</td>
                        <td>
                            <strong>{{ $line->item?->sku ?? 'Articulo eliminado' }}</strong><br>
                            {{ $line->item?->description ?? 'Sin descripción' }}
                        </td>
                        <td>{{ $line->lot ?: 'Sin lote' }}</td>
                        <td>{{ $line->unitsLabel() }}</td>
                        <td>{{ $line->requestedQuantityLabel() }}</td>
                        <td>{{ $line->destination_location ?: '-' }}</td>
                        <td class="picking-location">
                            @forelse ($pickingLocationSummaries as $pickingSummary)
                                <div>
                                    {{ $pickingSummary['location'] }}
                                    @if ($pickingSummary['quantity'])
                                        <span class="picking-quantity">{{ $pickingSummary['quantity'] }}</span>
                                    @endif
                                </div>
                            @empty
                                Pendiente de asignar ubicación
                            @endforelse
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>

        <h2>Observaciones internas</h2>
        <div class="notes"></div>
    </body>
</html>
