<?php

namespace App\Services\Stock;

use App\Models\Client;
use App\Support\Stock\StockOverviewBuilder;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

class StockExportService
{
    private const HEADERS = ['SKU', 'DESCRIPCIÓN', 'LOTE', 'CANTIDAD', 'PALÉS TOTALES'];

    public function __construct(
        private readonly StockOverviewBuilder $overviewBuilder,
    ) {}

    /**
     * @return Collection<int, array{sku: string, description: string, lot: string, quantity: int, total_pallets: int}>
     */
    public function rows(int $clientId): Collection
    {
        return $this->overviewBuilder->exportRows($clientId);
    }

    /**
     * @param  Collection<int, array{sku: string, description: string, lot: string, quantity: int, total_pallets: int}>  $rows
     */
    public function toXlsxResponse(Client $client, Collection $rows): BinaryFileResponse
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'stock_export_xlsx_');

        $writer = new XlsxWriter;
        $writer->openToFile($tempPath);
        $writer->getCurrentSheet()->setName('STOCK OFICIAL');
        $writer->getCurrentSheet()->setColumnWidth(18, 1);
        $writer->getCurrentSheet()->setColumnWidth(42, 2);
        $writer->getCurrentSheet()->setColumnWidth(16, 3);
        $writer->getCurrentSheet()->setColumnWidth(14, 4);
        $writer->getCurrentSheet()->setColumnWidth(16, 5);

        $writer->addRow(Row::fromValues(self::HEADERS, (new Style)->setFontBold()));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues([$row['sku'], $row['description'], $row['lot'], $row['quantity'], $row['total_pallets']]));
        }

        $writer->close();

        return response()
            ->download($tempPath, $this->fileName($client, 'xlsx'))
            ->deleteFileAfterSend(true);
    }

    /**
     * @param  Collection<int, array{sku: string, description: string, lot: string, quantity: int, total_pallets: int}>  $rows
     */
    public function toCsvResponse(Client $client, Collection $rows): BinaryFileResponse
    {
        $tempPath = tempnam(sys_get_temp_dir(), 'stock_export_csv_');

        $writer = new CsvWriter;
        $writer->getOptions()->FIELD_DELIMITER = ';';
        $writer->openToFile($tempPath);

        $writer->addRow(Row::fromValues(self::HEADERS));

        foreach ($rows as $row) {
            $writer->addRow(Row::fromValues([$row['sku'], $row['description'], $row['lot'], $row['quantity'], $row['total_pallets']]));
        }

        $writer->close();

        return response()
            ->download($tempPath, $this->fileName($client, 'csv'))
            ->deleteFileAfterSend(true);
    }

    /**
     * @param  Collection<int, array{sku: string, description: string, lot: string, quantity: int, total_pallets: int}>  $rows
     */
    public function toPdfResponse(Client $client, Collection $rows): Response
    {
        return Pdf::loadView('stock.export-pdf', [
            'client' => $client,
            'rows' => $rows,
            'generatedAt' => now(),
        ])->download($this->fileName($client, 'pdf'));
    }

    private function fileName(Client $client, string $extension): string
    {
        $slug = Str::slug($client->code !== '' ? $client->code : $client->name, '_');

        return sprintf('stock_%s_%s.%s', $slug, now()->format('Y-m-d'), $extension);
    }
}
