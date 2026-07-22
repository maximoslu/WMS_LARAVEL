<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: A4 portrait;
            margin: 10mm 12mm;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #111827;
            font-family: DejaVu Sans, Arial, sans-serif;
            font-size: 11px;
        }

        .sheet {
            position: relative;
            height: 277mm;
            page-break-after: always;
        }

        .sheet:last-child {
            page-break-after: auto;
        }

        .label {
            position: absolute;
            left: 0;
            right: 0;
            height: 110mm;
            padding: 7mm 12mm;
            border: 1.4px solid #111827;
            border-radius: 6px;
            position: relative;
        }

        .label + .label {
            margin-top: 0;
        }

        .label-top-slot {
            top: 0;
        }

        .label-bottom-slot {
            top: 45mm;
        }

        .label-empty {
            border-color: #d1d5db;
        }

        .label-top {
            margin-bottom: 6mm;
            padding-right: 46mm;
        }

        .label-top div {
            display: block;
        }

        .label-top .right {
            position: absolute;
            top: 7mm;
            right: 12mm;
            text-align: right;
        }

        .eyebrow {
            color: #374151;
            font-size: 10px;
            text-transform: uppercase;
        }

        .client {
            margin-top: 2mm;
            font-size: 16px;
            font-weight: 700;
            letter-spacing: 0;
        }

        .type {
            display: inline-block;
            padding: 2mm 4mm;
            border: 1px solid #111827;
            font-size: 14px;
            font-weight: 700;
        }

        .field {
            margin-bottom: 5mm;
        }

        .field-label {
            display: block;
            margin-bottom: 1mm;
            color: #111827;
            font-size: 16px;
            font-weight: 700;
        }

        .field-value {
            display: block;
            min-height: 10mm;
            padding: 1mm 0;
            border-bottom: 2px solid #111827;
            font-size: 23px;
            font-weight: 700;
            line-height: 1.1;
        }

        .field-value.units {
            font-size: 30px;
        }

        .meta {
            margin-top: 5mm;
            color: #374151;
            font-size: 10px;
        }

        .meta span {
            display: block;
        }

        .meta span:last-child {
            margin-top: 1mm;
            text-align: right;
        }
    </style>
</head>
<body>
    @foreach ($labels->chunk(2) as $pair)
        <section class="sheet">
            @foreach ($pair as $label)
                <article class="label {{ $loop->first ? 'label-top-slot' : 'label-bottom-slot' }}">
                    <div class="label-top">
                        <div>
                            <span class="eyebrow">MAXIMO WMS - Etiqueta mercancia</span>
                            <div class="client">{{ $label['client_name'] ?: $label['client_code'] }}</div>
                        </div>
                        <div class="right">
                            <span class="type">{{ $label['type'] }}</span>
                            <div class="eyebrow">{{ $label['number'] }}</div>
                        </div>
                    </div>

                    <div class="field">
                        <span class="field-label">ARTICULO</span>
                        <span class="field-value">{{ $label['article'] }}</span>
                    </div>

                    <div class="field">
                        <span class="field-label">LOTE</span>
                        <span class="field-value">{{ $label['lot'] }}</span>
                    </div>

                    <div class="field">
                        <span class="field-label">UNIDADES</span>
                        <span class="field-value units">{{ number_format((int) $label['units'], 0, ',', '.') }}</span>
                    </div>

                    <div class="meta">
                        <span>
                            Entrada: {{ $label['receipt_number'] ?: '-' }} · Fecha: {{ $label['received_at'] ?: '-' }}
                            @if ($label['location'])
                                · Ubicacion: {{ $label['location'] }}
                            @endif
                        </span>
                        <span>{{ $label['traceability'] }}</span>
                    </div>
                </article>
            @endforeach

            @if ($pair->count() === 1)
                <article class="label label-empty label-bottom-slot"></article>
            @endif
        </section>
    @endforeach
</body>
</html>
