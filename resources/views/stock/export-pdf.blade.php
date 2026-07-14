<!DOCTYPE html>
<html lang="es">
    <head>
        <meta charset="utf-8">
        <title>Stock {{ $client->name }}</title>
        <style>
            body { font-family: DejaVu Sans, sans-serif; font-size: 12px; color: #13222e; }
            h1 { font-size: 18px; margin: 0 0 4px; text-transform: uppercase; }
            .meta { color: #5d7282; font-size: 11px; margin: 0 0 16px; }
            table { width: 100%; border-collapse: collapse; margin-top: 8px; }
            th, td { border: 1px solid #cdd7df; padding: 6px 8px; text-align: left; vertical-align: top; }
            th { background: #eef5f8; }
            td.quantity, th.quantity { text-align: right; }
        </style>
    </head>
    <body>
        <h1>STOCK {{ mb_strtoupper($client->name) }}</h1>
        <p class="meta">Generado el {{ $generatedAt->format('d/m/Y H:i') }}</p>

        <table>
            <thead>
                <tr>
                    <th>SKU</th>
                    <th>DESCRIPCI&Oacute;N</th>
                    <th>LOTE</th>
                    <th class="quantity">CANTIDAD</th>
                    <th class="quantity">PAL&Eacute;S TOTALES</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($rows as $row)
                    <tr>
                        <td>{{ $row['sku'] }}</td>
                        <td>{{ $row['description'] }}</td>
                        <td>{{ $row['lot'] }}</td>
                        <td class="quantity">{{ number_format($row['quantity'], 0, ',', '.') }}</td>
                        <td class="quantity">{{ number_format($row['total_pallets'], 0, ',', '.') }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </body>
</html>
