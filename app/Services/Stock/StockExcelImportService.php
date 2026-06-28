<?php

namespace App\Services\Stock;

use App\Models\Client;
use App\Models\Item;
use App\Models\StockImport;
use App\Models\StockPallet;
use App\Models\User;
use App\Support\Stock\StockBatchCalculator;
use DateTimeInterface;
use Illuminate\Database\DatabaseManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\Common\Creator\ReaderFactory;

class StockExcelImportService
{
    private const MAX_DETAILED_MESSAGES = 20;

    /**
     * @var array<string, string>
     */
    private const IMPORTABLE_SHEETS = [
        'STOCK' => StockPallet::STATUS_AVAILABLE,
        'BOBINAS' => StockPallet::STATUS_AVAILABLE,
        'BLOQUEADO' => StockPallet::STATUS_BLOCKED,
    ];

    /**
     * @var list<string>
     */
    private const IGNORED_SHEETS = [
        'ETIQUETAS',
    ];

    /**
     * @var array<string, list<string>>
     */
    private const HEADER_SYNONYMS = [
        'sku' => ['sku', 'referencia', 'codigo', 'codigo producto', 'articulo'],
        'description' => ['descripcion', 'descripcion articulo', 'producto', 'articulo descripcion'],
        'lot' => ['lote', 'partida', 'batch'],
        'location_text' => ['ubicacion', 'localizacion'],
        'units_per_pallet' => [
            'unidades x palet',
            'unidades x pallet',
            'unidades/palet',
            'unidades/pallet',
            'uds/palet',
            'uds/pallet',
            'uds palet',
            'uds pallet',
            'unidades por palet',
            'unidades por pallet',
        ],
        'quantity_units' => ['cantidad', 'unidades', 'stock', 'uds', 'existencias', 'total unidades'],
        'full_pallets' => ['palets', 'pallets', 'palets completos', 'pallets completos'],
        'peaks_count' => ['picos'],
        'received_at' => ['fecha entrada', 'fecha'],
        'blocked_reason' => ['motivo bloqueo', 'bloqueo', 'observaciones bloqueo'],
    ];

    public function __construct(
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @return array{stock_import: StockImport, preview: array<string, mixed>}
     */
    public function createPreview(Client $client, User $user, UploadedFile $file): array
    {
        $storedPath = $file->storeAs(
            'stock-imports',
            Str::uuid()->toString().'.'.$file->getClientOriginalExtension(),
            'local',
        );

        if (! is_string($storedPath) || $storedPath === '') {
            throw new InvalidArgumentException('No se ha podido guardar temporalmente el fichero de stock.');
        }

        $preview = $this->parseWorkbook(Storage::disk('local')->path($storedPath), $client);

        $stockImport = StockImport::query()->create([
            'client_id' => $client->id,
            'uploaded_by' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => empty($preview['errors']) ? StockImport::STATUS_PREVIEWED : StockImport::STATUS_FAILED,
            'total_rows' => $preview['totals']['total_rows'],
            'imported_rows' => 0,
            'skipped_rows' => $preview['totals']['skipped_rows'],
            'available_rows' => $preview['totals']['available_rows'],
            'blocked_rows' => $preview['totals']['blocked_rows'],
            'detected_sheets_json' => $preview['detected_sheets'],
            'summary_json' => $preview['totals'],
            'warnings_json' => $preview['warnings'],
            'errors_json' => $preview['errors'],
        ]);

        return [
            'stock_import' => $stockImport,
            'preview' => $preview,
        ];
    }

    /**
     * @return array{imported_rows: int, skipped_rows: int}
     */
    public function confirm(StockImport $stockImport, User $user): array
    {
        $path = Storage::disk('local')->path($stockImport->stored_path);
        $preview = $this->parseWorkbook($path, $stockImport->client);

        if ($preview['errors'] !== []) {
            $stockImport->forceFill([
                'status' => StockImport::STATUS_FAILED,
                'errors_json' => $preview['errors'],
                'warnings_json' => $preview['warnings'],
                'summary_json' => $preview['totals'],
            ])->save();

            throw new InvalidArgumentException('La previsualizacion contiene errores y no se puede confirmar.');
        }

        return $this->db->transaction(function () use ($stockImport, $user, $preview): array {
            $lockedImport = StockImport::query()
                ->whereKey($stockImport->id)
                ->lockForUpdate()
                ->firstOrFail();

            StockImport::query()
                ->where('client_id', $lockedImport->client_id)
                ->lockForUpdate()
                ->get();

            if ($lockedImport->status === StockImport::STATUS_IMPORTED) {
                throw new InvalidArgumentException('Esta importacion ya fue confirmada previamente.');
            }

            $lockedImport->forceFill([
                'status' => StockImport::STATUS_IMPORTING,
                'uploaded_by' => $user->id,
            ])->save();

            StockPallet::query()
                ->where('client_id', $lockedImport->client_id)
                ->delete();

            $existingItems = Item::query()
                ->where('client_id', $lockedImport->client_id)
                ->get()
                ->keyBy(fn (Item $item): string => Str::upper(trim($item->sku)));

            $importedRows = 0;

            foreach ($preview['rows'] as $row) {
                $skuKey = Str::upper($row['sku']);
                $item = $existingItems[$skuKey] ?? null;

                if ($item === null) {
                    $item = Item::query()->create([
                        'client_id' => $lockedImport->client_id,
                        'sku' => $row['sku'],
                        'description' => $row['description'],
                        'lot' => $row['lot'],
                        'lot_key' => (string) ($row['lot'] ?? ''),
                        'units_per_pallet' => $row['units_per_pallet'],
                        'active' => true,
                        'status' => Item::STATUS_ACTIVE,
                    ]);

                    $existingItems[$skuKey] = $item;
                } else {
                    $item->forceFill([
                        'description' => $row['description'],
                        'units_per_pallet' => $row['units_per_pallet'],
                    ])->save();
                }

                $attributes = [
                    'client_id' => $lockedImport->client_id,
                    'item_id' => $item->id,
                    'stock_import_id' => $lockedImport->id,
                    'goods_receipt_id' => null,
                    'location_id' => null,
                    'location_text' => $row['location_text'],
                    'pallet_code' => null,
                    'lot' => $row['lot'],
                    'quantity_units' => $row['quantity_units'],
                    'units_per_pallet' => $row['units_per_pallet'],
                    'full_pallets' => $row['full_pallets'],
                    'peaks_count' => $row['peaks_count'],
                    'received_at' => $row['received_at'],
                    'imported_at' => now(),
                    'status' => $row['status'],
                    'blocked_reason' => $row['blocked_reason'],
                    'source_sheet' => $row['source_sheet'],
                    'notes' => 'Importado desde Excel: '.$lockedImport->original_filename,
                    'active' => true,
                ];

                foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
                    $attributes['peak_'.$peakNumber] = $row['peak_'.$peakNumber];
                }

                StockPallet::query()->create($attributes);
                $importedRows++;
            }

            $lockedImport->forceFill([
                'status' => StockImport::STATUS_IMPORTED,
                'total_rows' => $preview['totals']['total_rows'],
                'imported_rows' => $importedRows,
                'skipped_rows' => $preview['totals']['skipped_rows'],
                'available_rows' => $preview['totals']['available_rows'],
                'blocked_rows' => $preview['totals']['blocked_rows'],
                'detected_sheets_json' => $preview['detected_sheets'],
                'summary_json' => $preview['totals'],
                'warnings_json' => $preview['warnings'],
                'errors_json' => [],
                'imported_at' => now(),
            ])->save();

            return [
                'imported_rows' => $importedRows,
                'skipped_rows' => $preview['totals']['skipped_rows'],
            ];
        });
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     sample_rows: list<array<string, mixed>>,
     *     detected_sheets: array<string, mixed>,
     *     warnings: list<string>,
     *     errors: list<string>,
     *     totals: array<string, int>
     * }
     */
    public function parseWorkbook(string $path, Client $client): array
    {
        $reader = ReaderFactory::createFromFile($path);
        $reader->open($path);

        $existingItems = Item::query()
            ->where('client_id', $client->id)
            ->get()
            ->keyBy(fn (Item $item): string => Str::upper(trim($item->sku)));

        $rows = [];
        $warnings = [];
        $errors = [];
        $warningGroups = [];
        $errorGroups = [];
        $detectedSheets = [
            'processed' => [],
            'ignored' => [],
            'unsupported' => [],
        ];
        $totals = [
            'total_rows' => 0,
            'skipped_rows' => 0,
            'available_rows' => 0,
            'blocked_rows' => 0,
            'total_units' => 0,
            'total_full_pallets' => 0,
            'total_peak_units' => 0,
            'excluded_rows' => 0,
            'empty_rows_ignored' => 0,
            'missing_sku_rows' => 0,
            'real_errors' => 0,
        ];

        try {
            foreach ($reader->getSheetIterator() as $sheet) {
                $sheetName = trim($sheet->getName());
                $canonicalName = $this->canonicalSheetName($sheetName);

                if (in_array($canonicalName, self::IGNORED_SHEETS, true)) {
                    $detectedSheets['ignored'][] = $sheetName;
                    $warnings[] = 'Hoja ignorada: '.$sheetName.'.';

                    continue;
                }

                if (! array_key_exists($canonicalName, self::IMPORTABLE_SHEETS)) {
                    $detectedSheets['unsupported'][] = $sheetName;

                    continue;
                }

                $detectedSheets['processed'][] = $sheetName;
                $headerMap = null;
                $lineNumber = 0;

                foreach ($sheet->getRowIterator() as $row) {
                    $lineNumber++;

                    if ($row->isEmpty()) {
                        if ($headerMap !== null) {
                            $totals['skipped_rows']++;
                            $totals['empty_rows_ignored']++;
                            $this->addGroupedMessage(
                                $warningGroups,
                                'empty_rows_'.$sheetName,
                                'Se han ignorado :count filas vacias en '.$sheetName.'.',
                                'Fila vacia ignorada en '.$sheetName.'.',
                            );
                        }

                        continue;
                    }

                    if ($headerMap === null) {
                        $headerMap = $this->buildHeaderMap($row);

                        if (! isset($headerMap['sku'])) {
                            $errors[] = 'La hoja '.$sheetName.' no contiene una columna de SKU/referencia.';
                            break;
                        }

                        continue;
                    }

                    $totals['total_rows']++;

                    $parsedRow = $this->parseDataRow(
                        $row,
                        $headerMap,
                        $sheetName,
                        self::IMPORTABLE_SHEETS[$canonicalName],
                        $existingItems,
                        $lineNumber,
                    );

                    if ($parsedRow['skip']) {
                        $totals['skipped_rows']++;

                        if ($parsedRow['warning'] !== null) {
                            $warnings[] = $parsedRow['warning'];
                        }

                        if ($parsedRow['warning_group'] !== null) {
                            $this->applyWarningCounters($totals, $parsedRow['warning_group']['key']);
                            $this->addGroupedMessage(
                                $warningGroups,
                                $parsedRow['warning_group']['key'],
                                $parsedRow['warning_group']['summary'],
                                $parsedRow['warning_group']['detail'],
                            );
                        }

                        continue;
                    }

                    if ($parsedRow['error'] !== null) {
                        $totals['skipped_rows']++;
                        $totals['real_errors']++;

                        if ($parsedRow['error_group'] !== null) {
                            $this->addGroupedMessage(
                                $errorGroups,
                                $parsedRow['error_group']['key'],
                                $parsedRow['error_group']['summary'],
                                $parsedRow['error_group']['detail'],
                            );
                        } else {
                            $errors[] = $parsedRow['error'];
                        }

                        continue;
                    }

                    $rows[] = $parsedRow['row'];
                    $totals['total_units'] += $parsedRow['row']['quantity_units'];
                    $totals['total_full_pallets'] += $parsedRow['row']['full_pallets'];
                    $totals['total_peak_units'] += $this->sumPeakUnits($parsedRow['row']);

                    if ($parsedRow['row']['status'] === StockPallet::STATUS_BLOCKED) {
                        $totals['blocked_rows']++;
                    } else {
                        $totals['available_rows']++;
                    }

                    if ($parsedRow['message'] !== null) {
                        $warnings[] = $parsedRow['message'];
                    }
                }
            }
        } finally {
            $reader->close();
        }

        if ($detectedSheets['processed'] === []) {
            $errors[] = 'No se han encontrado hojas importables. Usa STOCK, BOBINAS o BLOQUEADO.';
        }

        if ($totals['excluded_rows'] > 0) {
            $warnings[] = 'Se han ignorado referencias internas que empiezan por * o _.';
        }

        return [
            'rows' => $rows,
            'sample_rows' => array_slice($rows, 0, 10),
            'detected_sheets' => $detectedSheets,
            'warnings' => array_values(array_unique(array_merge($warnings, $this->compileGroupedMessages($warningGroups)))),
            'errors' => array_values(array_unique(array_merge($errors, $this->compileGroupedMessages($errorGroups)))),
            'totals' => $totals,
        ];
    }

    /**
     * @param  array<string, int>  $headerMap
     * @param  \Illuminate\Support\Collection<string, Item>  $existingItems
     * @return array{
     *     row: array<string, mixed>|null,
     *     skip: bool,
     *     message: ?string,
     *     warning: ?string,
     *     warning_group: ?array{key: string, summary: string, detail: ?string},
     *     error: ?string,
     *     error_group: ?array{key: string, summary: string, detail: ?string}
     * }
     */
    private function parseDataRow(
        Row $row,
        array $headerMap,
        string $sheetName,
        string $status,
        $existingItems,
        int $lineNumber,
    ): array {
        $values = $row->toArray();
        $sku = $this->stringValue($values[$headerMap['sku']] ?? null);
        $quantityFromFile = $this->integerValue($values[$headerMap['quantity_units'] ?? -1] ?? null);

        if ($sku === '') {
            if ($this->rowHasNonEmptyData($values, $headerMap, ['sku'])) {
                return [
                    'row' => null,
                    'skip' => true,
                    'message' => null,
                    'warning' => null,
                    'warning_group' => [
                        'key' => 'missing_sku_'.$sheetName,
                        'summary' => 'Se han ignorado :count filas sin SKU valido en '.$sheetName.'.',
                        'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' ignorada por no tener SKU.',
                    ],
                    'error' => null,
                    'error_group' => null,
                ];
            }

            return [
                'row' => null,
                'skip' => true,
                'message' => null,
                'warning' => null,
                'warning_group' => null,
                'error' => null,
                'error_group' => null,
            ];
        }

        if ($this->shouldIgnoreSku($sku)) {
            return [
                'row' => null,
                'skip' => true,
                'message' => null,
                'warning' => null,
                'warning_group' => [
                    'key' => 'excluded_sku_'.$sheetName,
                    'summary' => 'Se han ignorado :count referencias internas o excluidas en '.$sheetName.'.',
                    'detail' => null,
                ],
                'error' => null,
                'error_group' => null,
            ];
        }

        $existingItem = $existingItems->get(Str::upper($sku));
        $description = $this->stringValue($values[$headerMap['description'] ?? -1] ?? null) ?: ($existingItem?->description ?? $sku);
        $lot = $this->nullableStringValue($values[$headerMap['lot'] ?? -1] ?? null);
        $locationText = $this->nullableStringValue($values[$headerMap['location_text'] ?? -1] ?? null);
        $blockedReason = $this->nullableStringValue($values[$headerMap['blocked_reason'] ?? -1] ?? null);

        if ($status === StockPallet::STATUS_BLOCKED && $blockedReason === null) {
            $blockedReason = 'Importado desde pestana BLOQUEADO';
        }

        if (($quantityFromFile ?? 0) <= 0 && ! $this->hasPositivePeakValues($values, $headerMap)) {
            return [
                'row' => null,
                'skip' => true,
                'message' => null,
                'warning' => null,
                'warning_group' => [
                    'key' => 'non_positive_stock_'.$sheetName,
                    'summary' => 'Se han ignorado :count filas sin stock positivo en '.$sheetName.'.',
                    'detail' => null,
                ],
                'error' => null,
                'error_group' => null,
            ];
        }

        $unitsPerPallet = $this->integerValue($values[$headerMap['units_per_pallet'] ?? -1] ?? null)
            ?? (int) ($existingItem?->units_per_pallet ?? 0);

        if ($unitsPerPallet <= 0) {
            return [
                'row' => null,
                'skip' => false,
                'message' => null,
                'warning' => null,
                'warning_group' => null,
                'error' => 'Fila '.$lineNumber.' de '.$sheetName.' sin unidades por pallet validas para SKU '.$sku.'.',
                'error_group' => [
                    'key' => 'invalid_units_'.$sheetName,
                    'summary' => 'Se han encontrado :count filas con unidades por pallet no validas en '.$sheetName.'.',
                    'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' sin unidades por pallet validas para SKU '.$sku.'.',
                ],
            ];
        }

        $fullPalletsFromFile = $this->integerValue($values[$headerMap['full_pallets'] ?? -1] ?? null);

        $peaks = [];

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $peaks[$peakNumber] = max(0, $this->integerValue($values[$headerMap['peak_'.$peakNumber] ?? -1] ?? null) ?? 0);
        }

        $peakUnits = array_sum($peaks);
        $explicitTotal = $fullPalletsFromFile !== null
            ? ($fullPalletsFromFile * $unitsPerPallet) + $peakUnits
            : null;

        $quantityUnits = $explicitTotal ?? $quantityFromFile ?? 0;
        $message = null;

        if ($quantityFromFile !== null && $explicitTotal !== null && $quantityFromFile !== $explicitTotal) {
            $message = 'Fila '.$lineNumber.' de '.$sheetName.' con cantidad distinta al desglose de pallets/picos. Se usa el desglose explicito.';
        }

        if ($quantityUnits <= 0 && $quantityFromFile === null && $peakUnits === 0) {
            return [
                'row' => null,
                'skip' => true,
                'message' => null,
                'warning' => null,
                'warning_group' => [
                    'key' => 'non_positive_stock_'.$sheetName,
                    'summary' => 'Se han ignorado :count filas sin stock positivo en '.$sheetName.'.',
                    'detail' => null,
                ],
                'error' => null,
                'error_group' => null,
            ];
        }

        if ($quantityUnits < $peakUnits) {
            return [
                'row' => null,
                'skip' => false,
                'message' => null,
                'warning' => null,
                'warning_group' => null,
                'error' => 'Fila '.$lineNumber.' de '.$sheetName.' con picos superiores a la cantidad total para SKU '.$sku.'.',
                'error_group' => [
                    'key' => 'invalid_peaks_'.$sheetName,
                    'summary' => 'Se han encontrado :count filas con picos inconsistentes en '.$sheetName.'.',
                    'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' con picos superiores a la cantidad total para SKU '.$sku.'.',
                ],
            ];
        }

        if ($peakUnits === 0 && $quantityUnits > 0) {
            $autoBreakdown = StockBatchCalculator::calculateBreakdown($quantityUnits, $unitsPerPallet);

            foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
                $peaks[$peakNumber] = $autoBreakdown['peak_'.$peakNumber] ?? 0;
            }

            $fullPallets = $autoBreakdown['full_pallets'];
        } else {
            $remainingUnits = max(0, $quantityUnits - $peakUnits);
            $fullPallets = intdiv($remainingUnits, $unitsPerPallet);
        }

        $receivedAt = $this->dateValue($values[$headerMap['received_at'] ?? -1] ?? null);
        $rowData = [
            'sku' => $sku,
            'description' => $description,
            'lot' => $lot,
            'location_text' => $locationText,
            'quantity_units' => $quantityUnits,
            'units_per_pallet' => $unitsPerPallet,
            'full_pallets' => $fullPallets,
            'peaks_count' => count(array_filter($peaks, fn (int $value): bool => $value > 0)),
            'received_at' => $receivedAt,
            'status' => $status,
            'blocked_reason' => $blockedReason,
            'source_sheet' => $sheetName,
        ];

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $rowData['peak_'.$peakNumber] = $peaks[$peakNumber];
        }

        return [
            'row' => $rowData,
            'skip' => false,
            'message' => $message,
            'warning' => null,
            'warning_group' => null,
            'error' => null,
            'error_group' => null,
        ];
    }

    /**
     * @return array<string, int>
     */
    private function buildHeaderMap(Row $row): array
    {
        $map = [];

        foreach ($row->toArray() as $index => $value) {
            $normalized = $this->normalizeHeaderLabel($this->stringValue($value));
            $canonical = $this->canonicalizeHeaderAlias($normalized);

            if ($canonical === '') {
                continue;
            }

            $field = match (true) {
                $this->matchesHeaderAlias('sku', $canonical) => 'sku',
                $this->matchesHeaderAlias('description', $canonical) => 'description',
                $this->matchesHeaderAlias('lot', $canonical) => 'lot',
                $this->matchesHeaderAlias('location_text', $canonical) => 'location_text',
                $this->matchesHeaderAlias('units_per_pallet', $canonical) => 'units_per_pallet',
                $this->matchesHeaderAlias('quantity_units', $canonical) => 'quantity_units',
                $this->matchesHeaderAlias('full_pallets', $canonical) => 'full_pallets',
                $this->matchesHeaderAlias('peaks_count', $canonical) => 'peaks_count',
                $this->matchesHeaderAlias('received_at', $canonical) => 'received_at',
                $this->matchesHeaderAlias('blocked_reason', $canonical) => 'blocked_reason',
                preg_match('/^(pico|peak)\s*(\d{1,2})$/', $canonical, $matches) === 1
                    && (int) $matches[2] >= 1
                    && (int) $matches[2] <= StockPallet::MAX_PEAK_COLUMNS => 'peak_'.(int) $matches[2],
                default => null,
            };

            if ($field !== null && ! array_key_exists($field, $map)) {
                $map[$field] = $index;
            }
        }

        return $map;
    }

    private function canonicalSheetName(string $sheetName): string
    {
        return Str::upper($this->normalizeHeaderToken($sheetName));
    }

    private function normalizeHeaderLabel(string $value): string
    {
        $normalized = preg_replace('/\s+/u', ' ', trim(Str::of($value)
            ->replaceMatches('/[\x{00A0}\x{200B}-\x{200D}\x{FEFF}]/u', ' ')
            ->ascii()
            ->lower()
            ->toString()));

        return $normalized !== null ? trim($normalized) : '';
    }

    private function normalizeHeaderToken(string $value): string
    {
        return str_replace(' ', '', $this->canonicalizeHeaderAlias($this->normalizeHeaderLabel($value)));
    }

    private function canonicalizeHeaderAlias(string $normalized): string
    {
        if ($normalized === '') {
            return '';
        }

        $normalized = str_replace(['/', '-', '_'], ' ', $normalized);
        $normalized = preg_replace('/\s*[x]\s*/u', ' x ', $normalized) ?? $normalized;
        $normalized = preg_replace('/\s+/u', ' ', trim($normalized)) ?? trim($normalized);
        $normalized = str_replace('pallet', 'palet', $normalized);
        $normalized = str_replace('palets', 'palet', $normalized);

        return trim((string) $normalized);
    }

    private function matchesHeaderAlias(string $field, string $canonical): bool
    {
        foreach (self::HEADER_SYNONYMS[$field] ?? [] as $alias) {
            if ($this->canonicalizeHeaderAlias($this->normalizeHeaderLabel($alias)) === $canonical) {
                return true;
            }
        }

        return false;
    }

    private function stringValue(mixed $value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        return trim((string) $value);
    }

    private function nullableStringValue(mixed $value): ?string
    {
        $string = $this->stringValue($value);

        return $string !== '' ? $string : null;
    }

    private function integerValue(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_int($value)) {
            return $value;
        }

        if (is_float($value)) {
            return (int) round($value);
        }

        $normalized = preg_replace('/[^0-9\-]/', '', str_replace(',', '.', (string) $value));

        return $normalized !== null && $normalized !== '' && is_numeric($normalized)
            ? (int) $normalized
            : null;
    }

    private function shouldIgnoreSku(string $sku): bool
    {
        $trimmedSku = trim($sku);

        return $trimmedSku === ''
            || Str::startsWith($trimmedSku, '*')
            || Str::startsWith($trimmedSku, '_');
    }

    private function dateValue(mixed $value): ?string
    {
        if ($value instanceof DateTimeInterface) {
            return Carbon::instance(Carbon::parse($value))->toDateString();
        }

        $string = $this->nullableStringValue($value);

        if ($string === null) {
            return null;
        }

        try {
            return Carbon::parse($string)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function sumPeakUnits(array $row): int
    {
        $total = 0;

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $total += (int) ($row['peak_'.$peakNumber] ?? 0);
        }

        return $total;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, int>  $headerMap
     * @param  list<string>  $ignoredFields
     */
    private function rowHasNonEmptyData(array $values, array $headerMap, array $ignoredFields = []): bool
    {
        foreach ($headerMap as $field => $index) {
            if (in_array($field, $ignoredFields, true)) {
                continue;
            }

            if ($this->stringValue($values[$index] ?? null) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, int>  $headerMap
     */
    private function hasPositivePeakValues(array $values, array $headerMap): bool
    {
        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $value = $this->integerValue($values[$headerMap['peak_'.$peakNumber] ?? -1] ?? null) ?? 0;

            if ($value > 0) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, array{count: int, summary: string, details: list<string>}>  $groups
     */
    private function addGroupedMessage(array &$groups, string $key, string $summary, ?string $detail): void
    {
        if (! isset($groups[$key])) {
            $groups[$key] = [
                'count' => 0,
                'summary' => $summary,
                'details' => [],
            ];
        }

        $groups[$key]['count']++;

        if ($detail !== null && count($groups[$key]['details']) < self::MAX_DETAILED_MESSAGES) {
            $groups[$key]['details'][] = $detail;
        }
    }

    /**
     * @param  array<string, array{count: int, summary: string, details: list<string>}>  $groups
     * @return list<string>
     */
    private function compileGroupedMessages(array $groups): array
    {
        $compiled = [];

        foreach ($groups as $group) {
            $compiled[] = str_replace(':count', (string) $group['count'], $group['summary']);

            foreach ($group['details'] as $detail) {
                $compiled[] = $detail;
            }
        }

        return $compiled;
    }

    /**
     * @param  array<string, int>  $totals
     */
    private function applyWarningCounters(array &$totals, string $key): void
    {
        if (str_starts_with($key, 'excluded_sku_')) {
            $totals['excluded_rows']++;
            return;
        }

        if (str_starts_with($key, 'empty_rows_')) {
            $totals['empty_rows_ignored']++;
            return;
        }

        if (str_starts_with($key, 'missing_sku_')) {
            $totals['missing_sku_rows']++;
        }
    }
}
