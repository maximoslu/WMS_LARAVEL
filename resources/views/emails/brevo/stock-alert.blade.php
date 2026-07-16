<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <title>Alerta de stock</title>
</head>
<body style="font-family: Arial, sans-serif; color: #162536; line-height: 1.5;">
    <h1 style="font-size: 22px;">Alerta de stock {{ strtoupper($event->severity) }}</h1>
    <p><strong>Cliente:</strong> {{ $event->client?->name }}</p>
    <p><strong>Articulo:</strong> {{ $event->item?->sku }} - {{ $event->item?->description }}</p>
    <p><strong>Stock actual:</strong> {{ number_format((int) $event->observed_units, 0, ',', '.') }} uds</p>
    @if($event->threshold_units !== null)
        <p><strong>Umbral:</strong> {{ number_format((int) $event->threshold_units, 0, ',', '.') }} uds</p>
    @endif
    @if($event->coverage_days !== null)
        <p><strong>Dias de cobertura:</strong> {{ number_format((float) $event->coverage_days, 1, ',', '.') }}</p>
    @endif
    @if($event->estimated_exhaustion_date !== null)
        <p><strong>Agotamiento estimado:</strong> {{ $event->estimated_exhaustion_date->format('d/m/Y') }}</p>
    @endif
    <p><strong>Motivo:</strong> {{ $event->reason }}</p>
    <p><strong>Evaluado:</strong> {{ $evaluatedAt?->format('d/m/Y H:i') }}</p>
    <p><a href="{{ $stockUrl }}">Abrir stock en MAXIMO WMS</a></p>
</body>
</html>
