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

        $lines = $this->parse((string) $file->getRealPath());
        $items = Item::query()->where('client_id', $client->id)->whereIn('sku', array_unique(array_column($lines, 'sku')))->get()->keyBy('sku');
        foreach ($lines as $line) {
            if (! $items->get($line['sku'])?->isOperational()) {
                throw $this->invalid("La referencia {$line['sku']} no existe o no está activa para TI Auto. Revisa el Word antes de importar.");
            }
        }

        return DB::transaction(function () use ($client, $file, $user, $lines, $items): GoodsDispatch {
            $missingLotLines = array_filter($lines, fn (array $line): bool => ! empty($line['missing_lot_detail']));
            $reviewNotice = count($missingLotLines) > 0
                ? ' ATENCIÓN: '.count($missingLotLines).' referencia(s) tienen '.number_format(array_sum(array_column($missingLotLines, 'units')), 0, ',', '.').' uds sin desglose de lote en el Word. Identificar antes de cargar.'
                : '';
            $dispatch = GoodsDispatch::query()->create([
                'client_id' => $client->id,
                'type' => GoodsDispatch::TYPE_MANUAL,
                'status' => GoodsDispatch::STATUS_DRAFT,
                'created_by' => $user->id,
                'notes' => 'Pedido TI Auto importado de '.basename($file->getClientOriginalName()).'. Revisar cantidades, lotes y partidas antes de confirmar la carga.'.$reviewNotice,
            ]);

            foreach ($lines as $line) {
                $item = $items->get($line['sku']);
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
                    'notes' => ! empty($line['missing_lot_detail'])
                        ? 'Columna del pedido: '.$line['source'].' · LOTE NO DETALLADO EN EL WORD: revisar '.number_format($line['units'], 0, ',', '.').' uds antes de cargar'
                        : 'Columna del pedido: '.$line['source'],
                ]);
            }

            return $dispatch;
        });
    }

    /** @return list<array{sku:string,source:string,lot:?string,units:int,missing_lot_detail?:bool}> */
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
        if ($tables === false || $tables->length !== 2) {
            throw $this->invalid('El Word debe contener el cuadro general y el desglose de lotes.');
        }
        $general = $this->rows($xpath, $tables->item(0));
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

        return $lines;
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
