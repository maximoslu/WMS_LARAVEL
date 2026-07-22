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

        .label-block {
            width: 210mm;
            height: 140mm;
            page-break-inside: avoid;
        }

        .label-break {
            page-break-after: always;
        }

        .label-pad {
            height: 140mm;
            padding: 7mm 7mm 0;
            overflow: hidden;
        }

        .article-table {
            width: 196mm;
            height: 68mm;
            border: 1.6mm solid #000;
            border-collapse: collapse;
            text-align: center;
        }

        .article-table td {
            width: 196mm;
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

        .details-table {
            width: 196mm;
            margin-top: 3mm;
            border-collapse: separate;
            border-spacing: 3mm 0;
        }

        .detail-box {
            width: 50%;
            height: 33mm;
            padding: 5mm 6mm 3mm;
            border: 1.3mm solid #000;
            border-radius: 6mm;
            vertical-align: top;
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
            font-size: 16mm;
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
            margin: 3mm 8mm 0 auto;
        }

        .label-mark {
            width: 42mm;
            margin: 3mm 8mm 0 auto;
            text-align: right;
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

    @foreach ($labels as $label)
        @php($article = $label['sku'] ?: $label['article'])
        @php($articleClass = strlen($article) > 34 ? 'article-value-wrap' : (strlen($article) > 18 ? 'article-value-long' : (strlen($article) > 10 ? 'article-value-medium' : '')))
        <section class="label-block {{ $loop->iteration % 2 === 0 && ! $loop->last ? 'label-break' : '' }}">
            <div class="label-pad {{ $loop->iteration % 2 === 1 ? 'label-top' : 'label-bottom' }}">
                <table class="article-table" role="presentation">
                    <tr>
                        <td><span class="article-value {{ $articleClass }}">{{ $article }}</span></td>
                    </tr>
                </table>

                <table class="details-table" aria-label="Datos principales de etiqueta">
                    <tr>
                        <td class="detail-box">
                            <span class="detail-label">LOTE:</span>
                            <span class="detail-value">{{ $label['lot'] }}</span>
                        </td>
                        <td class="detail-box">
                            <span class="detail-label">UNIDADES:</span>
                            <span class="detail-value detail-value-units">{{ number_format((int) $label['units'], 0, ',', '.') }}</span>
                        </td>
                    </tr>
                </table>

                <div class="label-mark">
                    @if ($logoAvailable)
                        <img src="{{ $logoPath }}" alt="Maximo Servicios Logisticos" class="label-logo">
                    @else
                        <span class="label-logo-fallback">MAXIMO<small>SERVICIOS LOGISTICOS</small></span>
                    @endif
                </div>
            </div>
        </section>
    @endforeach
</body>
</html>
