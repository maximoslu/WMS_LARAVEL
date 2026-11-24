<?php

namespace App\Services\GoodsReceipts;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OpenSpout\Reader\XLSX\Reader;

/**
 * Imports the carrier workbook used by TI Automotive as a receipt draft.
 *
 * Every source row represents one physical package. Rows are only combined
 * when both the customer reference and its actual units per package match.
 */
class TiAutoGoodsReceiptExcelImportService
{
    private const CLIENT_CODE = 'TIAUTO';

    /** @var array<string, string> */
    private const REQUIRED_HEADERS = [
        'customer_part_number' => 'Customer Part Number',
        'description' => 'Description',
    ];

    public function __construct(private readonly GoodsReceiptItemResolver $itemResolver) {}

    public function createDraft(UploadedFile $file, User $user): GoodsReceipt
    {
        $client = Client::query()
            ->whereRaw('UPPER(TRIM(code)) = ?', [self::CLIENT_CODE])
            ->where('active', true)
            ->first();

        if (! $client instanceof Client) {
            throw ValidationException::withMessages([
                'excel' => 'No existe un cliente activo con código TIAUTO para crear esta entrada.',
            ]);
        }

        $import = $this->parseWorkbook((string) $file->getRealPath());

        return DB::transaction(function () use ($client, $file, $import, $user): GoodsReceipt {
            $receipt = GoodsReceipt::query()->create([
                'client_id' => $client->id,
                'receipt_number' => $this->receiptNumber($import['received_at']),
                'external_document_number' => $this->externalDocumentNumber($import['delivery_notes']),
                'status' => GoodsReceipt::STATUS_DRAFT,
                'received_at' => $import['received_at']->toDateString(),
                'notes' => $this->receiptNotes($file, $import),
                'camion_propio' => false,
                'created_by' => $user->id,
            ]);

            foreach ($import['lines'] as $line) {
                $item = $this->itemResolver->resolveForPayload($client->id, [
                    'sku' => $line['sku'],
                    'description' => $line['description'],
                    // A new article needs a base palletization; individual lines
                    // still preserve the size recorded in the spreadsheet.
                    'units_per_pallet' => $line['units_per_pallet'],
                ]);

                if ($item instanceof Item && ! $item->isOperational()) {
                    throw ValidationException::withMessages([
                        'excel' => "El artículo {$line['sku']} está bloqueado u obsoleto y no puede recibirse.",
                    ]);
                }

                GoodsReceiptLine::query()->create([
                    'goods_receipt_id' => $receipt->id,
                    'item_id' => $item?->id,
                    'sku' => $line['sku'],
                    'description' => $line['description'],
                    'quantity_units' => $line['quantity_units'],
                    'units_per_pallet' => $line['units_per_pallet'],
                    'pallet_count' => $line['pallet_count'],
                    'pico_units' => null,
                    'notes' => $this->lineNotes($line),
                ]);
            }

            return $receipt;
        });
    }

    /**
     * @return array{received_at: Carbon, delivery_notes: list<string>, carrier: ?string, raw_rows: int, lines: list<array{sku: string, description: string, units_per_pallet: int, pallet_count: int, quantity_units: int, labels: list<string>}>}
     */
    private function parseWorkbook(string $path): array
    {
        // Uploaded files are held in a temporary path without the .xlsx
        // extension, so select the format from this endpoint's validation.
        $reader = new Reader;
        $reader->open($path);

        $rows = [];
        $headerFound = false;
        $receivedAt = null;
        $headerMap = [];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                foreach ($sheet->getRowIterator() as $row) {
                    if ($row->isEmpty()) {
                        continue;
                    }

                    $values = $row->toArray();
                    if (! $headerFound) {
                        $candidate = $this->headerMap($values);
                        if (! $this->hasRequiredHeaders($candidate)) {
                            continue;
                        }

                        $dateColumn = $this->dateColumn($values);
                        if ($dateColumn === null) {
                            throw $this->invalid('No se ha encontrado la columna de cantidad con fecha YYYYMMDD del Excel de TI Auto.');
                        }

                        $headerMap = $candidate;
                        $headerMap['quantity'] = $dateColumn['index'];
                        $receivedAt = $dateColumn['date'];
                        $headerFound = true;

                        continue;
                    }

                    $this->assertRowLimit(count($rows) + 1);
                    $sku = $this->text($values[$headerMap['customer_part_number']] ?? null);
                    $quantity = $this->positiveInteger($values[$headerMap['quantity']] ?? null);

                    if ($sku === '' && $quantity === null) {
                        continue;
                    }

                    if ($sku === '' || $quantity === null) {
                        throw $this->invalid('Hay una fila con referencia o cantidad no válida en el Excel de TI Auto.');
                    }

                    $rows[] = [
                        'sku' => Str::upper($sku),
                        'description' => $this->text($values[$headerMap['description']] ?? null) ?: $sku,
                        'quantity' => $quantity,
                        'delivery_note' => $this->text($values[$headerMap['delivery_note'] ?? -1] ?? null),
                        'carrier' => $this->text($values[$headerMap['means_of_transport'] ?? -1] ?? null),
                        'label' => $this->text($values[$headerMap['lot_number'] ?? -1] ?? null),
                    ];
                }

                if ($headerFound) {
                    break;
                }
            }
        } finally {
            $reader->close();
        }

        if (! $headerFound || $receivedAt === null) {
            throw $this->invalid('No se reconoce la plantilla de TI Auto. Debe incluir Customer Part Number, Description y una columna de fecha YYYYMMDD con las cantidades.');
        }

        if ($rows === []) {
            throw $this->invalid('El Excel no contiene líneas de entrada con cantidad positiva.');
        }

        $groups = [];
        foreach ($rows as $row) {
            $key = $row['sku'].'|'.$row['quantity'];
            if (! isset($groups[$key])) {
                $groups[$key] = [
                    'sku' => $row['sku'],
                    'description' => $row['description'],
                    'units_per_pallet' => $row['quantity'],
                    'pallet_count' => 0,
                    'quantity_units' => 0,
                    'labels' => [],
                ];
            }

            $groups[$key]['pallet_count']++;
            $groups[$key]['quantity_units'] += $row['quantity'];
            if ($row['label'] !== '') {
                $groups[$key]['labels'][] = $row['label'];
            }
        }

        return [
            'received_at' => $receivedAt,
            'delivery_notes' => collect($rows)->pluck('delivery_note')->filter()->unique()->values()->all(),
            'carrier' => collect($rows)->pluck('carrier')->filter()->unique()->implode(' / ') ?: null,
            'raw_rows' => count($rows),
            'lines' => array_values($groups),
        ];
    }

    /** @param array<int, mixed> $values
     * @return array<string, int>
     */
    private function headerMap(array $values): array
    {
        $map = [];
        foreach ($values as $index => $value) {
            $normalized = Str::of($this->text($value))->ascii()->lower()->replaceMatches('/\s+/', ' ')->trim()->toString();
            $field = match ($normalized) {
                'customer part number' => 'customer_part_number',
                'description' => 'description',
                'delivery note' => 'delivery_note',
                'lot number' => 'lot_number',
                'means of transport' => 'means_of_transport',
                default => null,
            };
            if ($field !== null && ! isset($map[$field])) {
                $map[$field] = $index;
            }
        }

        return $map;
    }

    /** @param array<string, int> $map */
    private function hasRequiredHeaders(array $map): bool
    {
        return count(array_intersect(array_keys(self::REQUIRED_HEADERS), array_keys($map))) === count(self::REQUIRED_HEADERS);
    }

    /** @param array<int, mixed> $values
     * @return array{index: int, date: Carbon}|null
     */
    private function dateColumn(array $values): ?array
    {
        foreach ($values as $index => $value) {
            $value = trim((string) $value);
            if (preg_match('/^\d{8}$/', $value) !== 1) {
                continue;
            }

            try {
                return ['index' => $index, 'date' => Carbon::createFromFormat('!Ymd', $value)];
            } catch (\Throwable) {
                continue;
            }
        }

        return null;
    }

    private function positiveInteger(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value > 0 ? $value : null;
        }

        if (is_float($value) && floor($value) === $value) {
            return $value > 0 ? (int) $value : null;
        }

        $value = trim((string) $value);

        return preg_match('/^\d+$/', $value) === 1 && (int) $value > 0 ? (int) $value : null;
    }

    private function text(mixed $value): string
    {
        return trim((string) $value);
    }

    private function assertRowLimit(int $rowCount): void
    {
        if ($rowCount > (int) config('wms.stock_imports.max_rows', 1000)) {
            throw $this->invalid('El Excel supera el máximo permitido de '.config('wms.stock_imports.max_rows', 1000).' etiquetas.');
        }
    }

    private function receiptNumber(Carbon $receivedAt): string
    {
        return 'TIAUTO-EXCEL-'.$receivedAt->format('Ymd').'-'.now()->format('His');
    }

    /** @param list<string> $deliveryNotes */
    private function externalDocumentNumber(array $deliveryNotes): ?string
    {
        return $deliveryNotes === [] ? null : Str::limit(implode(' / ', $deliveryNotes), 255, '');
    }

    /** @param array{carrier: ?string, raw_rows: int, delivery_notes: list<string>} $import */
    private function receiptNotes(UploadedFile $file, array $import): string
    {
        $details = [
            'Entrada creada desde Excel TI Auto.',
            'Archivo: '.$file->getClientOriginalName().'.',
            'Etiquetas agrupadas: '.$import['raw_rows'].'.',
        ];
        if ($import['carrier'] !== null) {
            $details[] = 'Transporte: '.$import['carrier'].'.';
        }

        return implode(' ', $details);
    }

    /** @param array{pallet_count: int, units_per_pallet: int, labels: list<string>} $line */
    private function lineNotes(array $line): string
    {
        $note = 'Importado desde Excel TI Auto: '.$line['pallet_count'].' etiquetas de '.$line['units_per_pallet'].' uds.';
        if ($line['labels'] !== []) {
            $note .= ' Identificadores origen: '.implode(', ', $line['labels']).'.';
        }

        return $note;
    }

    private function invalid(string $message): ValidationException
    {
        return ValidationException::withMessages(['excel' => $message]);
    }
}
