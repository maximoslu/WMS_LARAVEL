<?php

namespace App\Services\GoodsDispatches;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\User;
use DOMDocument;
use DOMXPath;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class TiAutoWordDispatchImportService
{
    public function createDraft(UploadedFile $file, User $user): GoodsDispatch
    {
        $client = Client::query()->whereRaw('UPPER(TRIM(code)) = ?', ['TIAUTO'])->where('active', true)->first();
        if (! $client) {
            throw $this->invalid('No existe un cliente TI Auto activo.');
        }

        ['lines' => $lines, 'warnings' => $warnings] = $this->parse((string) $file->getRealPath());
        $items = Item::query()->where('client_id', $client->id)->whereIn('sku', array_unique(array_column($lines, 'sku')))->get()->keyBy('sku');
        foreach ($lines as $line) {
            if (! $items->get($line['sku'])?->isOperational()) {
                throw $this->invalid("La referencia {$line['sku']} no existe o no está activa para TI Auto. Revisa el Word antes de importar.");
            }
        }

        return DB::transaction(function () use ($client, $file, $user, $lines, $items, $warnings): GoodsDispatch {
            $aggregatedByReference = ($lines[0]['source'] ?? null) === 'Suma de ubicaciones del Word';
            $missingLotLines = array_filter($lines, fn (array $line): bool => ! empty($line['missing_lot_detail']));
            $reviewNotice = count($missingLotLines) > 0
                ? ' ATENCIÓN: '.count($missingLotLines).' referencia(s) tienen '.number_format(array_sum(array_column($missingLotLines, 'units')), 0, ',', '.').' uds sin desglose de lote en el Word. Identificar antes de cargar.'
                : '';
            if ($warnings !== []) {
                $reviewNotice .= ' ATENCIÓN: '.implode(' ', $warnings);
            }
            $dispatch = GoodsDispatch::query()->create([
                'client_id' => $client->id,
                'type' => GoodsDispatch::TYPE_MANUAL,
                'status' => GoodsDispatch::STATUS_DRAFT,
                'created_by' => $user->id,
                'notes' => 'Pedido TI Auto importado de '.basename($file->getClientOriginalName()).'. '
                    .($aggregatedByReference
                        ? 'Las cantidades son unidades totales por referencia; la distribución en palés y partidas es provisional y debe confirmarse durante la carga.'
                        : 'Revisar cantidades, lotes y partidas antes de confirmar la carga.')
                    .$reviewNotice,
            ]);

            foreach ($lines as $line) {
                $item = $items->get($line['sku']);
                if (isset($line['reported_units'])) {
                    $lineNotes = 'Suma de ubicaciones del Word: '.number_format($line['units'], 0, ',', '.').' uds. Total impreso: '.number_format($line['reported_units'], 0, ',', '.').' uds; revisar discrepancia.';
                } elseif (! empty($line['missing_lot_detail'])) {
                    $lineNotes = 'Columna del pedido: '.$line['source'].' · LOTE NO DETALLADO EN EL WORD: revisar '.number_format($line['units'], 0, ',', '.').' uds antes de cargar';
                } elseif ($aggregatedByReference) {
                    $lineNotes = 'Cantidad solicitada: suma de todas las ubicaciones del Word.';
                } else {
                    $lineNotes = 'Columna del pedido: '.$line['source'];
                }

                $dispatch->lines()->create([
                    'item_id' => $item->id,
                    'line_type' => 'pallet',
                    'sku' => $item->sku,
                    'description' => $item->description,
                    'lot' => $line['lot'],
                    'units_per_pallet' => $line['units'],
                    'pallets' => 1,
                    'requested_units' => $line['units'],
                    'requested_pallets' => 1,
                    'requested_peaks' => 0,
                    'loaded_pallets' => 0,
                    'loaded_partial_units' => 0,
                    'notes' => $lineNotes,
                ]);
            }

            return $dispatch;
        });
    }

    /** @return array{lines:list<array{sku:string,source:string,lot:?string,units:int,missing_lot_detail?:bool,reported_units?:int}>,warnings:list<string>} */
    private function parse(string $path): array
    {
        $zip = new ZipArchive;
        if ($zip->open($path) !== true) {
            throw $this->invalid('No se puede abrir el archivo Word.');
        }
        try {
            $xml = $zip->getFromName('word/document.xml');
        } finally {
            $zip->close();
        }
        if ($xml === false || strlen($xml) > 5_000_000) {
            throw $this->invalid('El archivo no contiene un documento Word válido.');
        }

        $document = new DOMDocument;
        if (! @$document->loadXML($xml, LIBXML_NONET)) {
            throw $this->invalid('No se pueden leer las tablas del Word.');
        }
        $xpath = new DOMXPath($document);
        $xpath->registerNamespace('w', 'http://schemas.openxmlformats.org/wordprocessingml/2006/main');
        $tables = $xpath->query('/w:document/w:body/w:tbl');
        if ($tables === false || ! in_array($tables->length, [1, 2], true)) {
            throw $this->invalid('El Word debe contener un cuadro general y, opcionalmente, un desglose de lotes.');
        }
        $general = $this->rows($xpath, $tables->item(0));
        if ($tables->length === 1) {
            return $this->parseGeneralOnly($general);
        }

        $detail = $this->rows($xpath, $tables->item(1));
        if (count($general) < 3 || count($detail) < 2 || mb_strtolower($general[0][0] ?? '') !== 'etiquetas de fila') {
            throw $this->invalid('No se reconoce el formato del pedido de TI Auto.');
        }

        $headers = array_slice($general[0], 1, -1);
        $requested = [];
        $totals = [];
        foreach (array_slice($general, 1, -1) as $row) {
            $sku = mb_strtoupper(trim($row[0] ?? ''));
            if ($sku === '' || isset($requested[$sku])) {
                throw $this->invalid('Hay referencias vacías o duplicadas en el cuadro general.');
            }
            $requested[$sku] = [];
            foreach ($headers as $index => $header) {
                $quantity = $this->integer($row[$index + 1] ?? '', true);
                if ($quantity > 0) {
                    $requested[$sku][$header] = $quantity;
                    $totals[$header] = ($totals[$header] ?? 0) + $quantity;
                }
            }
            if (array_sum($requested[$sku]) !== $this->integer($row[count($headers) + 1] ?? '')) {
                throw $this->invalid("El total de {$sku} no coincide con sus columnas.");
            }
        }
        $footer = end($general);
        foreach ($headers as $index => $header) {
            if (($totals[$header] ?? 0) !== $this->integer($footer[$index + 1] ?? '', true)) {
                throw $this->invalid("El total de la columna {$header} no coincide.");
            }
        }
        if (array_sum($totals) !== $this->integer($footer[count($headers) + 1] ?? '')) {
            throw $this->invalid('El total general del pedido no coincide.');
        }

        $detailSource = trim($detail[0][1] ?? '');
        if ($detailSource === '' || ! in_array($detailSource, $headers, true)) {
            throw $this->invalid('La columna del desglose no figura en el cuadro general.');
        }
        $lines = [];
        $detailedTotals = [];
        $currentSku = null;
        $detailFooter = null;
        foreach (array_slice($detail, 1) as $row) {
            $label = trim($row[0] ?? '');
            if ($label === '' && trim($row[1] ?? '') === '') {
                continue;
            }
            if (mb_strtolower($label) === 'total general') {
                $detailFooter = $this->integer($row[1] ?? '');
                break;
            }
            $quantity = $this->integer($row[1] ?? '');
            if (isset($requested[mb_strtoupper($label)])) {
                $currentSku = mb_strtoupper($label);
                if (($requested[$currentSku][$detailSource] ?? 0) !== $quantity) {
                    throw $this->invalid("El subtotal de {$currentSku} no coincide con el cuadro general.");
                }

                continue;
            }
            if ($currentSku === null || $label === '') {
                throw $this->invalid('El desglose contiene un lote sin referencia.');
            }
            $lines[] = ['sku' => $currentSku, 'source' => $detailSource, 'lot' => $label, 'units' => $quantity];
            $detailedTotals[$currentSku] = ($detailedTotals[$currentSku] ?? 0) + $quantity;
        }
        foreach ($requested as $sku => $bySource) {
            $missing = ($bySource[$detailSource] ?? 0) - ($detailedTotals[$sku] ?? 0);
            if ($missing < 0) {
                throw $this->invalid("Los lotes de {$sku} superan la cantidad solicitada.");
            }
            if ($missing > 0) {
                $lines[] = ['sku' => $sku, 'source' => $detailSource, 'lot' => null, 'units' => $missing, 'missing_lot_detail' => true];
            }
            foreach ($bySource as $source => $units) {
                if ($source !== $detailSource) {
                    $lines[] = ['sku' => $sku, 'source' => $source, 'lot' => null, 'units' => $units];
                }
            }
        }
        if (($detailFooter !== null && array_sum($detailedTotals) !== $detailFooter) || count($lines) > 500) {
            throw $this->invalid('El desglose de lotes no cuadra o supera 500 líneas.');
        }

        return ['lines' => $lines, 'warnings' => []];
    }

    /** @param list<list<string>> $general
     * @return array{lines:list<array{sku:string,source:string,lot:null,units:int,reported_units?:int}>,warnings:list<string>}
     */
    private function parseGeneralOnly(array $general): array
    {
        $headers = $general[0] ?? [];
        if (count($headers) < 3 || mb_strtolower(trim($headers[0])) !== 'etiquetas de fila'
            || mb_strtolower(trim(end($headers))) !== 'total general') {
            throw $this->invalid('No se reconoce el cuadro general del pedido de TI Auto.');
        }

        $lines = [];
        $warnings = [];
        $seen = [];
        $sum = 0;
        $lastColumn = count($headers) - 1;
        foreach (array_slice($general, 1) as $row) {
            $sku = mb_strtoupper(trim($row[0] ?? ''));
            if ($sku === '' || $sku === 'TOTAL GENERAL') {
                $printedTotal = $this->integer($row[$lastColumn] ?? '', true);
                if ($printedTotal > 0 && $printedTotal !== $sum) {
                    $warnings[] = 'El total general impreso ('.number_format($printedTotal, 0, ',', '.').' uds) difiere de la suma de las ubicaciones ('.number_format($sum, 0, ',', '.').' uds).';
                }

                continue;
            }
            if (isset($seen[$sku])) {
                throw $this->invalid("La referencia {$sku} aparece más de una vez en el Word.");
            }
            $seen[$sku] = true;
            $units = 0;
            for ($column = 1; $column < $lastColumn; $column++) {
                $units += $this->integer($row[$column] ?? '', true);
            }
            if ($units <= 0) {
                throw $this->invalid("La referencia {$sku} no tiene unidades en las columnas de ubicaciones.");
            }
            $sum += $units;
            $line = ['sku' => $sku, 'source' => 'Suma de ubicaciones del Word', 'lot' => null, 'units' => $units];
            $printedTotal = $this->integer($row[$lastColumn] ?? '', true);
            if ($printedTotal !== $units) {
                $line['reported_units'] = $printedTotal;
                $warnings[] = "{$sku}: el total impreso (".number_format($printedTotal, 0, ',', '.').' uds) difiere de la suma de ubicaciones ('.number_format($units, 0, ',', '.').' uds).';
            }
            $lines[] = $line;
            if (count($lines) > 500) {
                throw $this->invalid('El pedido supera 500 referencias.');
            }
        }
        if ($lines === []) {
            throw $this->invalid('El Word no contiene referencias con cantidades.');
        }

        return ['lines' => $lines, 'warnings' => $warnings];
    }

    /** @return list<list<string>> */
    private function rows(DOMXPath $xpath, ?\DOMNode $table): array
    {
        $rows = [];
        foreach ($xpath->query('./w:tr', $table) as $row) {
            $cells = [];
            foreach ($xpath->query('./w:tc', $row) as $cell) {
                $parts = [];
                foreach ($xpath->query('.//w:t', $cell) as $text) {
                    $parts[] = $text->textContent;
                }
                $cells[] = trim(implode('', $parts));
            }
            $rows[] = $cells;
        }

        return $rows;
    }

    private function integer(string $value, bool $allowBlank = false): int
    {
        $value = preg_replace('/[.\s\x{00A0}]/u', '', trim($value));
        if ($allowBlank && $value === '') {
            return 0;
        }
        if (! preg_match('/^[1-9][0-9]*$/', $value)) {
            throw $this->invalid('Hay una cantidad no válida en el Word.');
        }

        return (int) $value;
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['word' => $message]);
    }
}
