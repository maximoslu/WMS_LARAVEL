<!DOCTYPE html>
<html lang="es"><head><meta charset="utf-8"><title>DECA {{ $document->number }}</title>
<style>
@page { margin: 30px 36px; }
body { font-family: DejaVu Sans, sans-serif; color: #152b38; font-size: 10px; line-height: 1.45; }
h1 { font-size: 23px; margin: 0; } h2 { font-size: 12px; margin: 16px 0 5px; border-bottom: 1px solid #718593; padding-bottom: 4px; }
p { margin: 3px 0 8px; } .muted { color: #4a606e; font-size: 9px; }
.header { width: 100%; border-bottom: 2px solid #152b38; } .header td { vertical-align: top; } .header .qr { width: 125px; text-align: right; }
.qr img { width: 120px; height: 120px; } .label { font-size: 9px; color: #4a606e; }
.block { page-break-inside: avoid; word-wrap: break-word; } .long-text { white-space: pre-wrap; word-wrap: break-word; } .url { font-size: 7px; word-wrap: break-word; }
</style></head><body>
<table class="header"><tr><td><h1>DECA</h1><p>Documento electrónico de control administrativo</p><strong>{{ $document->number }}</strong>
<p class="muted">Emitido: {{ $document->issued_at->timezone('Europe/Madrid')->format('d/m/Y H:i:s') }} (Europe/Madrid)</p>
<p><strong>Fecha del transporte: {{ $document->transport_date->format('d/m/Y') }}</strong></p>
</td><td class="qr"><img src="{{ $qr }}" alt="QR del documento"><div class="muted">Descarga del PDF original</div></td></tr></table>
<section class="block"><h2>1. Cargador contractual</h2><p><strong>{{ $data['shipper_name'] }}</strong><br>NIF: {{ $data['shipper_tax_id'] }}<br>{{ $data['shipper_address'] }}</p></section>
<section class="block"><h2>2. Transportista efectivo</h2><p><strong>{{ $data['carrier']['name'] }}</strong><br>{{ $data['carrier']['label'] }}<br>NIF: {{ $data['carrier']['tax_id'] }}<br>{{ $data['carrier']['address'] ?? '' }}</p></section>
<section class="block"><h2>3. Trayecto</h2><p><span class="label">Lugar de origen / carga</span><br>{{ $data['origin'] }}</p><p><span class="label">Lugar de destino / descarga</span><br>{{ $data['destination'] }}</p></section>
<h2>4. Mercancía</h2><p class="long-text">{{ $data['goods'] }}</p><p><strong>Peso total: {{ number_format((float) $data['weight_kg'], 3, ',', '.') }} kg</strong></p>
<section class="block"><h2>5. Vehículo y autorización</h2><p>Matrícula vehículo / tractora: <strong>{{ $data['tractor_plate'] }}</strong><br>Remolque / semirremolque: <strong>{{ $data['trailer_plate'] ?? 'No lleva' }}</strong><br>Autorización especial de circulación: {{ $data['authorization_number'] ?? 'No requiere' }}</p></section>
@if(!empty($data['notes']))<h2>6. Observaciones y reservas</h2><p class="long-text">{{ $data['notes'] }}</p>@endif
<div class="block"><h2>Consulta del documento</h2><p class="url">{{ $document->public_url }}</p><p class="muted">Documento emitido digitalmente antes del inicio del servicio según confirmación del emisor. Orden FOM/2861/2012 y Resolución de 5 de junio de 2026. Documento de control administrativo.</p></div>
</body></html>
