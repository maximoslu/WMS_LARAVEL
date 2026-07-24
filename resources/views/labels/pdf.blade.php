<!doctype html>
<html lang="es">
<head>
    <meta charset="utf-8">
    <style>
        @page {
            size: A4 portrait;
            margin: 0;
        }

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            color: #000;
            font-family: DejaVu Sans, Arial, sans-serif;
        }

        .label-page {
            position: relative;
            width: 210mm;
            height: 280mm;
            overflow: hidden;
        }

        .label-page-break {
            page-break-after: always;
        }

        .label-slot {
            position: absolute;
            left: 0;
            width: 210mm;
            height: 140mm;
            overflow: hidden;
        }

        .label-top {
            top: 0;
        }

        .label-bottom {
            top: 140mm;
        }

        .label-content {
            position: relative;
            width: 196mm;
            height: 126mm;
            margin: 7mm;
            overflow: hidden;
        }

        .article-table {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 68mm;
            border: 1.6mm solid #000;
            border-collapse: collapse;
            text-align: center;
        }

        .article-table td {
            width: 100%;
            height: 68mm;
            padding: 0 5mm;
            text-align: center;
            vertical-align: middle;
        }

        .article-value {
            display: block;
            font-size: 28mm;
            font-weight: 700;
            line-height: 1;
            white-space: nowrap;
        }

        .article-value-medium {
            font-size: 22mm;
        }

        .article-value-long {
            font-size: 10mm;
        }

        .article-value-wrap {
            font-size: 8mm;
            line-height: 1.18;
            white-space: normal;
        }

        .details-row {
            position: absolute;
            top: 72mm;
            left: 0;
            width: 100%;
            height: 42mm;
        }

        .detail-box {
            position: absolute;
            top: 0;
            width: 80mm;
            height: 42mm;
            padding: 5mm 6mm 3mm;
            border: 1.3mm solid #000;
            border-radius: 6mm;
            overflow: hidden;
        }

        .detail-box-lot {
            left: 3mm;
        }

        .detail-box-units {
            right: 3mm;
        }

        .detail-label {
            display: block;
            margin-bottom: 4mm;
            font-size: 6mm;
            font-weight: 700;
            line-height: 1;
        }

        .detail-value {
            display: block;
            text-align: center;
            font-size: 14mm;
            font-weight: 700;
            line-height: 1;
        }

        .detail-value-units {
            font-size: 23mm;
            margin-top: -1mm;
        }

        .label-logo {
            display: block;
            width: 42mm;
            height: auto;
        }

        .label-mark {
            position: absolute;
            right: 8mm;
            bottom: 1mm;
            width: 42mm;
            height: 10mm;
            text-align: right;
            overflow: hidden;
        }

        .label-logo-fallback {
            display: block;
            text-align: right;
            font-size: 3.2mm;
            font-weight: 700;
            line-height: 1.05;
        }

        .label-logo-fallback small {
            display: block;
            font-size: 1.8mm;
            font-weight: 700;
        }

    </style>
</head>
<body>
    @php
        $logoPath = public_path('brand/maximo-logo-horizontal.png');
        $logoAvailable = file_exists($logoPath) && (extension_loaded('gd') || extension_loaded('imagick'));
    @endphp

    @foreach ($labels->chunk(2) as $pageLabels)
        @php($pageLabels = $pageLabels->values())
        <div class="label-page {{ ! $loop->last ? 'label-page-break' : '' }}">
            @foreach ([0, 1] as $slotIndex)
                @php($label = $pageLabels->get($slotIndex))
                <div class="label-slot {{ $slotIndex === 0 ? 'label-top' : 'label-bottom' }}">
                    @if ($label)
                        @php($article = $label['sku'] ?: $label['article'])
                        @php($articleClass = strlen($article) > 34 ? 'article-value-wrap' : (strlen($article) > 18 ? 'article-value-long' : (strlen($article) > 10 ? 'article-value-medium' : '')))
                        <div class="label-content">
                            <table class="article-table" role="presentation">
                                <tr>
                                    <td><span class="article-value {{ $articleClass }}">{{ $article }}</span></td>
                                </tr>
                            </table>

                            <div class="details-row" aria-label="Datos principales de etiqueta">
                                <div class="detail-box detail-box-lot">
                                    <span class="detail-label">LOTE:</span>
                                    <span class="detail-value">{{ $label['lot'] }}</span>
                                </div>
                                <div class="detail-box detail-box-units">
                                    <span class="detail-label">UNIDADES:</span>
                                    <span class="detail-value detail-value-units">{{ number_format((int) $label['units'], 0, ',', '.') }}</span>
                                </div>
                            </div>

                            <div class="label-mark">
                                @if ($logoAvailable)
                                    <img src="{{ $logoPath }}" alt="Maximo Servicios Logisticos" class="label-logo">
                                @else
                                    <span class="label-logo-fallback">MAXIMO<small>SERVICIOS LOGISTICOS</small></span>
                                @endif
                            </div>
                        </div>
                    @endif
                </div>
            @endforeach
        </div>
    @endforeach
</body>
</html>
