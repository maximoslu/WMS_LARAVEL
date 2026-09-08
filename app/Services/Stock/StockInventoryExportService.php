<?php

namespace App\Services\Stock;

use App\Models\Client;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StockInventoryExportService
{
    private const HEADERS = [
        'CLIENTE',
        'ALMACÉN',
        'UBICACIÓN',
        'REFERENCIA / SKU',
        'DESCRIPCIÓN',
        'LOTE',
        'UNIDADES POR PALLET',
        'PALLETS TEÓRICOS',
        'PICOS/UDS TEÓRICAS',
        'TOTAL TEÓRICO',
        'PALLETS CONTADOS',
        'PICOS/UDS CONTADAS',
        'OBSERVACIONES',
    ];

    /** @param Collection<int, array<string, mixed>> $rows */
    public function toXlsxResponse(Client $client, Collection $rows): BinaryFileResponse
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'stock_inventory_xlsx_');
        $writer = new XlsxWriter;
        $writer->openToFile($tempPath);
        $sheet = $writer->getCurrentSheet();
        $sheet->setName('INVENTARIO');

        foreach ([18, 20, 18, 20, 42, 16, 20, 18, 22, 18, 20, 22, 36] as $index => $width) {
            $sheet->setColumnWidth($width, $index + 1);
        }

        $headerStyle = (new Style)->setFontBold()->setShouldWrapText();
        $writer->addRow(Row::fromValues(self::HEADERS, $headerStyle));

        foreach ($rows as $row) {
            $warehouse = trim((string) ($row['warehouse_name'] ?: $row['warehouse_code']));
            $writer->addRow(Row::fromValues([
                $row['client_name'],
                $warehouse !== '' ? $warehouse : 'Sin almacén',
                $row['location_label'],
                $row['sku'],
                $row['description'],
                $row['lot_label'],
                (int) $row['units_per_pallet'],
                (int) $row['full_pallets'],
                sprintf('%d / %d', (int) $row['peaks_count'], (int) $row['peak_units']),
                (int) $row['quantity_units'],
                '',
                '',
                '',
            ]));
        }

        $writer->close();

        return response()
            ->download($tempPath, $this->fileName($client))
            ->deleteFileAfterSend(true);
    }

    private function fileName(Client $client): string
    {
        $slug = Str::slug($client->code !== '' ? $client->code : $client->name, '_');

        return sprintf('inventario_%s_%s.xlsx', $slug, now()->format('Y-m-d'));
    }
}
