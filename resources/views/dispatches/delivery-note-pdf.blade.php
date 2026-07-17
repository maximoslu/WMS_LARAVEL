<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Albaran {{ $dispatch->dispatchNumber() }}</title>
        <style>
            @page { margin: 26px 28px; }
            body { margin: 0; font-family: DejaVu Sans, sans-serif; font-size: 9px; color: #13222e; }
            h2 { margin: 0; color: #5d7282; font-size: 9px; letter-spacing: .08em; text-transform: uppercase; }
            table { width: 100%; border-collapse: collapse; }
            .meta-grid { margin-top: 7px; }
            .meta-grid td { border: 0; padding: 0 10px 5px 0; }
            .meta-label { color: #6b7d8a; font-size: 7px; letter-spacing: .08em; text-transform: uppercase; }
            .brand { margin-bottom: 7px; padding-bottom: 7px; border-bottom: 2px solid #d9e4ea; }
            .brand td { border: 0; padding: 0; vertical-align: middle; }
            .brand-logo { width: 125px; }
            .brand strong { display: block; font-size: 15px; }
            .delivery-lines { margin-top: 9px; table-layout: fixed; }
            .delivery-lines th,
            .delivery-lines td { border: 1px solid #aebdc8; padding: 4px 5px; text-align: left; vertical-align: top; }
            .delivery-lines th { background: #e8f0f4; color: #263b4a; font-size: 7px; line-height: 1.15; text-transform: uppercase; }
            .delivery-lines td { line-height: 1.2; word-wrap: break-word; }
            .delivery-lines tr { page-break-inside: avoid; }
            .delivery-lines .col-sku { width: 12%; }
            .delivery-lines .col-description { width: 39%; }
            .delivery-lines .col-lot { width: 12%; }
            .delivery-lines .col-delivered { width: 15%; }
            .delivery-lines .col-quantity { width: 9%; }
            .delivery-lines .col-destination { width: 13%; }
            .delivery-lines .number { text-align: right; white-space: nowrap; }
            .delivery-lines .description { font-size: 8.5px; line-height: 1.15; }
            .totals { width: 62%; margin: 9px 0 0 auto; }
            .totals td { border: 1px solid #c4d0d8; padding: 4px 6px; }
            .totals .label { background: #eef3f6; color: #526675; font-size: 7px; text-transform: uppercase; }
            .totals .value { width: 24%; text-align: right; font-weight: bold; }
            .signature { margin-top: 12px; border: 1px solid #cdd7df; padding: 7px; min-height: 58px; }
        </style>
    </head>
    <body>
        @php($deliveredLines = $dispatch->lines->filter(fn ($line) => $line->hasDeliveredQuantity()))
        @php($totalDeliveredPeaks = $deliveredLines->sum(function ($line) {
            if ($line->allocations->isNotEmpty()) {
                return $line->allocations->sum(function ($allocation) {
                    $selectedPeakUnits = collect($allocation->selectedPeakUnitsByIndex())->sum();
                    $manualPartialUnits = max(0, $allocation->loadedPartialUnits() - $selectedPeakUnits);

                    return count($allocation->selectedPeakUnitsByIndex()) + ($manualPartialUnits > 0 ? 1 : 0);
                });
            }

            return $line->isPeakLine()
                ? $line->loadedPeaks()
                : ($line->loadedPartialUnits() > 0 ? 1 : 0);
        }))
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

        <table class="delivery-lines">
            <colgroup>
                <col style="width: 12%;">
                <col style="width: 39%;">
                <col style="width: 12%;">
                <col style="width: 15%;">
                <col style="width: 9%;">
                <col style="width: 13%;">
            </colgroup>
            <thead>
                <tr>
                    <th class="col-sku">SKU</th>
                    <th class="col-description">Descripción</th>
                    <th class="col-lot">Lote</th>
                    <th class="col-delivered">Pallets entregados</th>
                    <th class="col-quantity">Cantidad</th>
                    <th class="col-destination">Ubicación destino</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($deliveredLines as $line)
                    <tr>
                        <td class="col-sku"><strong>{{ $line->sku }}</strong></td>
                        <td class="col-description description">{{ $line->deliveryNoteDescription() }}</td>
                        <td class="col-lot">{{ $line->lot ?: 'Sin lote' }}</td>
                        <td class="col-delivered">{{ $line->loadedQuantityLabel() }}</td>
                        <td class="col-quantity number">{{ number_format($line->loadedUnitsTotal(), 0, ',', '.') }} uds</td>
                        <td class="col-destination">{{ $line->destination_location ?: '-' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <table class="totals">
            <tr><td class="label">Total pallets entregados</td><td class="value">{{ number_format($dispatch->loadedPalletsCount(), 0, ',', '.') }}</td></tr>
            <tr><td class="label">Total picos entregados</td><td class="value">{{ number_format($totalDeliveredPeaks, 0, ',', '.') }}</td></tr>
            <tr><td class="label">Total unidades entregadas</td><td class="value">{{ number_format($dispatch->loadedUnitsCount(), 0, ',', '.') }}</td></tr>
        </table>

        <div class="signature"><strong>Firma / Recibí:</strong></div>
    </body>
</html>
