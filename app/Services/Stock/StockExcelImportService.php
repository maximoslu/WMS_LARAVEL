<?php

namespace App\Services\Stock;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockImport;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
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
    private const MAX_DETAILED_MESSAGES = 5;
    private const PROFILE_STANDARD = 'standard_multisheet';
    private const PROFILE_EDELVIVES = 'edelvives_single_sheet';
    private const EDELVIVES_WAREHOUSE_CODE = '38';
    private const EDELVIVES_WAREHOUSE_NAME = 'NAVE 38';
    private const EDELVIVES_DEFAULT_LOT = 'SIN LOTE';

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
        'reported_total_pallets' => ['total pallets', 'total palets', 'total pallets archivo', 'total palets archivo'],
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

        $status = $preview['fatal_errors'] !== []
            ? StockImport::STATUS_FAILED
            : StockImport::STATUS_PENDING_CONFIRMATION;

        $stockImport = StockImport::query()->create([
            'client_id' => $client->id,
            'uploaded_by' => $user->id,
            'original_filename' => $file->getClientOriginalName(),
            'stored_path' => $storedPath,
            'status' => $status,
            'total_rows' => $preview['totals']['total_rows'],
            'imported_rows' => 0,
            'skipped_rows' => $preview['totals']['skipped_rows'],
            'available_rows' => $preview['totals']['available_rows'],
            'blocked_rows' => $preview['totals']['blocked_rows'],
            'detected_sheets_json' => $preview['detected_sheets'],
            'summary_json' => $preview['totals'],
            'warnings_json' => $preview['warnings'],
            'errors_json' => $preview['all_errors'],
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
        if ($stockImport->status === StockImport::STATUS_IMPORTED) {
            throw new InvalidArgumentException('Esta importacion ya fue confirmada previamente.');
        }

        if ($stockImport->status === StockImport::STATUS_FAILED) {
            throw new InvalidArgumentException('No se puede confirmar una importacion fallida.');
        }

        if (! in_array($stockImport->status, [StockImport::STATUS_PREVIEWED, StockImport::STATUS_PENDING_CONFIRMATION], true)) {
            throw new InvalidArgumentException('Esta importacion no esta disponible para confirmar.');
        }

        if (! Storage::disk('local')->exists($stockImport->stored_path)) {
            $stockImport->forceFill([
                'status' => StockImport::STATUS_FAILED,
                'errors_json' => ['El fichero temporal de la importacion ya no existe. Vuelve a subir el Excel.'],
            ])->save();

            throw new InvalidArgumentException('El fichero temporal de la importacion ya no existe. Vuelve a subir el Excel.');
        }

        $path = Storage::disk('local')->path($stockImport->stored_path);
        $preview = $this->parseWorkbook($path, $stockImport->client);

        if ($preview['fatal_errors'] !== []) {
            $stockImport->forceFill([
                'status' => StockImport::STATUS_FAILED,
                'errors_json' => $preview['all_errors'],
                'warnings_json' => $preview['warnings'],
                'summary_json' => $preview['totals'],
            ])->save();

            throw new InvalidArgumentException('La importacion contiene errores fatales y no se puede confirmar.');
        }

        if ($preview['can_confirm'] !== true) {
            throw new InvalidArgumentException('No hay filas validas para importar.');
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
            $createdItems = 0;
            $updatedItems = 0;
            $edelvivesLocations = ($preview['profile'] ?? null) === self::PROFILE_EDELVIVES
                ? $this->ensureEdelvivesLocations(
                    $lockedImport->client,
                    collect($preview['rows'])
                        ->pluck('location_code')
                        ->filter()
                        ->unique()
                        ->values()
                        ->all(),
                )
                : [];

            foreach ($preview['catalog_items'] as $catalogItem) {
                $skuKey = Str::upper($catalogItem['sku']);
                $item = $existingItems[$skuKey] ?? null;

                if ($item === null) {
                    $item = Item::query()->create([
                        'client_id' => $lockedImport->client_id,
                        'sku' => $catalogItem['sku'],
                        'description' => $catalogItem['description'],
                        'lot' => null,
                        'lot_key' => '',
                        'units_per_pallet' => $catalogItem['units_per_pallet'] ?? 1,
                        'active' => true,
                        'status' => Item::STATUS_ACTIVE,
                    ]);

                    $existingItems[$skuKey] = $item;
                    $createdItems++;
                } else {
                    $changes = [
                        'description' => $catalogItem['description'],
                    ];

                    if (($catalogItem['units_per_pallet'] ?? 0) > 0) {
                        $changes['units_per_pallet'] = $catalogItem['units_per_pallet'];
                    }

                    $item->forceFill($changes)->save();
                    $updatedItems++;
                }
            }

            foreach ($preview['rows'] as $row) {
                $skuKey = Str::upper($row['sku']);
                $item = $existingItems[$skuKey] ?? null;

                if ($item === null) {
                    throw new InvalidArgumentException('No se ha podido resolver el articulo maestro para SKU '.$row['sku'].'.');
                }

                $locationCode = $row['location_code'] ?? null;

                $attributes = [
                    'client_id' => $lockedImport->client_id,
                    'item_id' => $item->id,
                    'stock_import_id' => $lockedImport->id,
                    'goods_receipt_id' => null,
                    'location_id' => $locationCode !== null && isset($edelvivesLocations[$locationCode])
                        ? $edelvivesLocations[$locationCode]->id
                        : null,
                    'location_text' => $row['location_text'] ?? null,
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
                'errors_json' => $preview['row_errors'],
                'imported_at' => now(),
            ])->save();

            return [
                'imported_rows' => $importedRows,
                'skipped_rows' => $preview['totals']['skipped_rows'],
                'created_items' => $createdItems,
                'updated_items' => $updatedItems,
            ];
        });
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     catalog_items: list<array<string, mixed>>,
     *     sample_rows: list<array<string, mixed>>,
     *     detected_sheets: array<string, mixed>,
     *     warnings: list<string>,
     *     row_errors: list<string>,
     *     fatal_errors: list<string>,
     *     errors: list<string>,
     *     all_errors: list<string>,
     *     can_confirm: bool,
     *     totals: array<string, int>,
     *     profile: string,
     *     profile_label: string,
     *     warehouse_name: ?string
     * }
     */
    public function parseWorkbook(string $path, Client $client): array
    {
        return $this->usesEdelvivesProfile($client)
            ? $this->parseEdelvivesWorkbook($path, $client)
            : $this->parseStandardWorkbook($path, $client);
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     catalog_items: list<array<string, mixed>>,
     *     sample_rows: list<array<string, mixed>>,
     *     detected_sheets: array<string, mixed>,
     *     warnings: list<string>,
     *     row_errors: list<string>,
     *     fatal_errors: list<string>,
     *     errors: list<string>,
     *     all_errors: list<string>,
     *     can_confirm: bool,
     *     totals: array<string, int>,
     *     profile: string,
     *     profile_label: string,
     *     warehouse_name: ?string
     * }
     */
    private function parseStandardWorkbook(string $path, Client $client): array
    {
        $reader = ReaderFactory::createFromFile($path);
        $reader->open($path);

        $existingItems = Item::query()
            ->where('client_id', $client->id)
            ->get()
            ->keyBy(fn (Item $item): string => Str::upper(trim($item->sku)));

        $rows = [];
        $catalogItems = [];
        $warnings = [];
        $rowErrors = [];
        $fatalErrors = [];
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
            'total_peaks_count' => 0,
            'total_logistic_units' => 0,
            'total_peak_units' => 0,
            'excluded_rows' => 0,
            'empty_rows_ignored' => 0,
            'missing_sku_rows' => 0,
            'real_errors' => 0,
            'invalid_rows_ignored' => 0,
            'catalog_items_detected' => 0,
            'catalog_items_created' => 0,
            'catalog_items_updated' => 0,
            'catalog_items_without_stock' => 0,
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
                            $fatalErrors[] = 'La hoja '.$sheetName.' no contiene una columna de SKU/referencia.';
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

                    if ($parsedRow['catalog_item'] !== null) {
                        $catalogKey = Str::upper($parsedRow['catalog_item']['sku']);
                        $alreadySeen = array_key_exists($catalogKey, $catalogItems);

                        if (! $alreadySeen) {
                            $catalogItems[$catalogKey] = $parsedRow['catalog_item'];
                            $totals['catalog_items_detected']++;

                            if ($existingItems->has($catalogKey)) {
                                $totals['catalog_items_updated']++;
                            } else {
                                $totals['catalog_items_created']++;
                            }
                        } else {
                            $catalogItems[$catalogKey] = $this->mergeCatalogItems($catalogItems[$catalogKey], $parsedRow['catalog_item']);
                        }
                    }

                    if ($parsedRow['warning_group'] !== null && $parsedRow['skip'] === false) {
                        $this->applyWarningCounters($totals, $parsedRow['warning_group']['key']);
                        $this->addGroupedMessage(
                            $warningGroups,
                            $parsedRow['warning_group']['key'],
                            $parsedRow['warning_group']['summary'],
                            $parsedRow['warning_group']['detail'],
                        );
                    }

                    if ($parsedRow['error'] !== null) {
                        $totals['skipped_rows']++;
                        $totals['real_errors']++;
                        $totals['invalid_rows_ignored']++;

                        if ($parsedRow['error_group'] !== null) {
                            $this->addGroupedMessage(
                                $errorGroups,
                                $parsedRow['error_group']['key'],
                                $parsedRow['error_group']['summary'],
                                $parsedRow['error_group']['detail'],
                            );
                        } else {
                            $rowErrors[] = $parsedRow['error'];
                        }

                        continue;
                    }

                    if ($parsedRow['row'] === null) {
                        $totals['skipped_rows']++;

                        if ($parsedRow['catalog_item'] !== null) {
                            $totals['catalog_items_without_stock']++;
                        }

                        continue;
                    }

                    $rows[] = $parsedRow['row'];
                    $totals['total_units'] += $parsedRow['row']['quantity_units'];
                    $totals['total_full_pallets'] += $parsedRow['row']['full_pallets'];
                    $totals['total_peaks_count'] += $parsedRow['row']['peaks_count'];
                    $totals['total_logistic_units'] += $parsedRow['row']['full_pallets'] + $parsedRow['row']['peaks_count'];
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
            $fatalErrors[] = 'No se han encontrado hojas importables. Usa STOCK, BOBINAS o BLOQUEADO.';
        }

        if ($totals['excluded_rows'] > 0) {
            $warnings[] = 'Se han ignorado referencias internas que empiezan por * o _.';
        }

        $compiledWarnings = array_values(array_unique(array_merge($warnings, $this->compileGroupedMessages($warningGroups))));
        $compiledRowErrors = array_values(array_unique(array_merge($rowErrors, $this->compileGroupedMessages($errorGroups))));
        $compiledFatalErrors = array_values(array_unique($fatalErrors));
        $canConfirm = $rows !== [] && $compiledFatalErrors === [];

        return [
            'rows' => $rows,
            'catalog_items' => array_values($catalogItems),
            'sample_rows' => array_slice($rows, 0, 10),
            'detected_sheets' => $detectedSheets,
            'warnings' => $compiledWarnings,
            'row_errors' => $compiledRowErrors,
            'fatal_errors' => $compiledFatalErrors,
            'errors' => array_values(array_merge($compiledFatalErrors, $compiledRowErrors)),
            'all_errors' => array_values(array_merge($compiledFatalErrors, $compiledRowErrors)),
            'can_confirm' => $canConfirm,
            'totals' => $totals,
            'profile' => self::PROFILE_STANDARD,
            'profile_label' => 'Stock multihoja',
            'warehouse_name' => null,
        ];
    }

    /**
     * @return array{
     *     rows: list<array<string, mixed>>,
     *     catalog_items: list<array<string, mixed>>,
     *     sample_rows: list<array<string, mixed>>,
     *     detected_sheets: array<string, mixed>,
     *     warnings: list<string>,
     *     row_errors: list<string>,
     *     fatal_errors: list<string>,
     *     errors: list<string>,
     *     all_errors: list<string>,
     *     can_confirm: bool,
     *     totals: array<string, int>,
     *     profile: string,
     *     profile_label: string,
     *     warehouse_name: ?string
     * }
     */
    private function parseEdelvivesWorkbook(string $path, Client $client): array
    {
        $reader = ReaderFactory::createFromFile($path);
        $reader->open($path);

        $existingItems = Item::query()
            ->where('client_id', $client->id)
            ->get()
            ->keyBy(fn (Item $item): string => Str::upper(trim($item->sku)));

        $rows = [];
        $catalogItems = [];
        $warnings = [];
        $rowErrors = [];
        $fatalErrors = [];
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
            'total_peaks_count' => 0,
            'total_logistic_units' => 0,
            'total_peak_units' => 0,
            'excluded_rows' => 0,
            'empty_rows_ignored' => 0,
            'missing_sku_rows' => 0,
            'real_errors' => 0,
            'invalid_rows_ignored' => 0,
            'catalog_items_detected' => 0,
            'catalog_items_created' => 0,
            'catalog_items_updated' => 0,
            'catalog_items_without_stock' => 0,
            'locations_detected' => 0,
        ];
        $detectedLocations = [];

        try {
            $sheet = null;
            foreach ($reader->getSheetIterator() as $candidateSheet) {
                $sheet = $candidateSheet;
                break;
            }

            if ($sheet === null) {
                $fatalErrors[] = 'El fichero de Edelvives no contiene ninguna hoja para importar.';
            } else {
                $sheetName = trim($sheet->getName());
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
                        $headerMap = $this->buildEdelvivesColumnMap($row);

                        if ($headerMap === null) {
                            $fatalErrors[] = 'La hoja '.$sheetName.' no tiene el formato esperado para Edelvives.';
                            break;
                        }

                        continue;
                    }

                    $totals['total_rows']++;

                    $parsedRow = $this->parseEdelvivesRow(
                        $row,
                        $headerMap,
                        $sheetName,
                        $existingItems,
                        $lineNumber,
                    );

                    if ($parsedRow['skip']) {
                        $totals['skipped_rows']++;

                        foreach ($parsedRow['warning_groups'] as $warningGroup) {
                            $this->applyWarningCounters($totals, $warningGroup['key']);
                            $this->addGroupedMessage(
                                $warningGroups,
                                $warningGroup['key'],
                                $warningGroup['summary'],
                                $warningGroup['detail'],
                            );
                        }

                        continue;
                    }

                    if ($parsedRow['catalog_item'] !== null) {
                        $catalogKey = Str::upper($parsedRow['catalog_item']['sku']);
                        $alreadySeen = array_key_exists($catalogKey, $catalogItems);

                        if (! $alreadySeen) {
                            $catalogItems[$catalogKey] = $parsedRow['catalog_item'];
                            $totals['catalog_items_detected']++;

                            if ($existingItems->has($catalogKey)) {
                                $totals['catalog_items_updated']++;
                            } else {
                                $totals['catalog_items_created']++;
                            }
                        } else {
                            $catalogItems[$catalogKey] = $this->mergeCatalogItems($catalogItems[$catalogKey], $parsedRow['catalog_item']);
                        }
                    }

                    foreach ($parsedRow['warning_groups'] as $warningGroup) {
                        $this->applyWarningCounters($totals, $warningGroup['key']);
                        $this->addGroupedMessage(
                            $warningGroups,
                            $warningGroup['key'],
                            $warningGroup['summary'],
                            $warningGroup['detail'],
                        );
                    }

                    if ($parsedRow['error'] !== null) {
                        $totals['skipped_rows']++;
                        $totals['real_errors']++;
                        $totals['invalid_rows_ignored']++;

                        $this->addGroupedMessage(
                            $errorGroups,
                            $parsedRow['error_group']['key'],
                            $parsedRow['error_group']['summary'],
                            $parsedRow['error_group']['detail'],
                        );

                        continue;
                    }

                    if ($parsedRow['row'] === null) {
                        $totals['skipped_rows']++;

                        if ($parsedRow['catalog_item'] !== null) {
                            $totals['catalog_items_without_stock']++;
                        }

                        continue;
                    }

                    $rows[] = $parsedRow['row'];
                    $totals['total_units'] += $parsedRow['row']['quantity_units'];
                    $totals['total_full_pallets'] += $parsedRow['row']['full_pallets'];
                    $totals['total_peaks_count'] += $parsedRow['row']['peaks_count'];
                    $totals['total_logistic_units'] += $parsedRow['row']['full_pallets'] + $parsedRow['row']['peaks_count'];
                    $totals['total_peak_units'] += $this->sumPeakUnits($parsedRow['row']);
                    $totals['available_rows']++;
                    $detectedLocations[$parsedRow['row']['location_code']] = true;
                }
            }
        } finally {
            $reader->close();
        }

        $totals['locations_detected'] = count($detectedLocations);
        $warnings[] = 'Gramaje detectado en archivo, no se importara como propiedad independiente.';

        $compiledWarnings = array_values(array_unique(array_merge($warnings, $this->compileGroupedMessages($warningGroups))));
        $compiledRowErrors = array_values(array_unique(array_merge($rowErrors, $this->compileGroupedMessages($errorGroups))));
        $compiledFatalErrors = array_values(array_unique($fatalErrors));
        $canConfirm = $rows !== [] && $compiledFatalErrors === [];

        return [
            'rows' => $rows,
            'catalog_items' => array_values($catalogItems),
            'sample_rows' => array_slice($rows, 0, 10),
            'detected_sheets' => $detectedSheets,
            'warnings' => $compiledWarnings,
            'row_errors' => $compiledRowErrors,
            'fatal_errors' => $compiledFatalErrors,
            'errors' => array_values(array_merge($compiledFatalErrors, $compiledRowErrors)),
            'all_errors' => array_values(array_merge($compiledFatalErrors, $compiledRowErrors)),
            'can_confirm' => $canConfirm,
            'totals' => $totals,
            'profile' => self::PROFILE_EDELVIVES,
            'profile_label' => 'Stock Edelvives',
            'warehouse_name' => self::EDELVIVES_WAREHOUSE_NAME,
        ];
    }

    /**
     * @param  array<string, int>  $headerMap
     * @param  \Illuminate\Support\Collection<string, Item>  $existingItems
     * @return array{
     *     row: array<string, mixed>|null,
     *     catalog_item: array<string, mixed>|null,
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
                    'catalog_item' => null,
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
                'catalog_item' => null,
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
                'catalog_item' => null,
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

        $unitsPerPallet = $this->integerValue($values[$headerMap['units_per_pallet'] ?? -1] ?? null)
            ?? (int) ($existingItem?->units_per_pallet ?? 0);

        $catalogItem = [
            'sku' => $sku,
            'description' => $description,
            'units_per_pallet' => $unitsPerPallet > 0 ? $unitsPerPallet : null,
            'source_sheet' => $sheetName,
        ];

        $fullPalletsFromFile = $this->integerValue($values[$headerMap['full_pallets'] ?? -1] ?? null);
        $peaks = [];

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $peaks[$peakNumber] = max(0, $this->integerValue($values[$headerMap['peak_'.$peakNumber] ?? -1] ?? null) ?? 0);
        }

        $peakUnits = array_sum($peaks);
        $hasPositiveStock = ($quantityFromFile ?? 0) > 0 || ($fullPalletsFromFile ?? 0) > 0 || $peakUnits > 0;
        $isBobinasSheet = $this->canonicalSheetName($sheetName) === 'BOBINAS';

        if (! $hasPositiveStock) {
            return [
                'row' => null,
                'catalog_item' => $catalogItem,
                'skip' => false,
                'message' => null,
                'warning' => null,
                'warning_group' => [
                    'key' => 'non_positive_stock_'.$sheetName,
                    'summary' => 'Se han omitido :count filas sin stock positivo en '.$sheetName.'.',
                    'detail' => null,
                ],
                'error' => null,
                'error_group' => null,
            ];
        }

        if ($unitsPerPallet <= 0) {
            if ($isBobinasSheet && ($quantityFromFile ?? 0) > 0) {
                $rowData = [
                    'sku' => $sku,
                    'description' => $description,
                    'lot' => $lot,
                    'location_code' => null,
                    'location_text' => $locationText,
                    'quantity_units' => (int) $quantityFromFile,
                    'units_per_pallet' => 0,
                    'full_pallets' => 0,
                    'peaks_count' => 0,
                    'received_at' => $this->dateValue($values[$headerMap['received_at'] ?? -1] ?? null),
                    'status' => $status,
                    'blocked_reason' => $blockedReason,
                    'source_sheet' => $sheetName,
                ];

                foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
                    $rowData['peak_'.$peakNumber] = 0;
                }

                return [
                    'row' => $rowData,
                    'catalog_item' => $catalogItem,
                    'skip' => false,
                    'message' => null,
                    'warning' => null,
                    'warning_group' => [
                        'key' => 'bobinas_missing_units_'.$sheetName,
                        'summary' => 'Se han importado :count filas de '.$sheetName.' sin unidades por pallet validas, conservando solo el stock operativo.',
                        'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' importada sin unidades por pallet validas para SKU '.$sku.'. Se conserva la cantidad total sin desglose de pallets.',
                    ],
                    'error' => null,
                    'error_group' => null,
                ];
            }

            return [
                'row' => null,
                'catalog_item' => $catalogItem,
                'skip' => false,
                'message' => null,
                'warning' => null,
                'warning_group' => null,
                'error' => 'Fila '.$lineNumber.' de '.$sheetName.' no se importara por no tener unidades por pallet validas para SKU '.$sku.'.',
                'error_group' => [
                    'key' => 'invalid_units_'.$sheetName,
                    'summary' => 'Se han encontrado :count filas en '.$sheetName.' que no se importaran por no tener unidades por pallet validas.',
                    'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' no se importara por no tener unidades por pallet validas para SKU '.$sku.'.',
                ],
            ];
        }

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
                'catalog_item' => $catalogItem,
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
                'catalog_item' => $catalogItem,
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
            'location_code' => null,
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
            'catalog_item' => $catalogItem,
            'skip' => false,
            'message' => $message,
            'warning' => null,
            'warning_group' => null,
            'error' => null,
            'error_group' => null,
        ];
    }

    /**
     * @param  array<string, int>  $headerMap
     * @param  \Illuminate\Support\Collection<string, Item>  $existingItems
     * @return array{
     *     row: array<string, mixed>|null,
     *     catalog_item: array<string, mixed>|null,
     *     skip: bool,
     *     warning_groups: list<array{key: string, summary: string, detail: ?string}>,
     *     error: ?string,
     *     error_group: ?array{key: string, summary: string, detail: ?string}
     * }
     */
    private function parseEdelvivesRow(
        Row $row,
        array $headerMap,
        string $sheetName,
        $existingItems,
        int $lineNumber,
    ): array {
        $values = $row->toArray();
        $sku = $this->normalizeSku($this->stringValue($values[$headerMap['sku']] ?? null));

        if ($sku === '') {
            return [
                'row' => null,
                'catalog_item' => null,
                'skip' => true,
                'warning_groups' => [[
                    'key' => 'missing_sku_'.$sheetName,
                    'summary' => 'Se han ignorado :count filas sin SKU valido en '.$sheetName.'.',
                    'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' ignorada por no tener SKU.',
                ]],
                'error' => null,
                'error_group' => null,
            ];
        }

        $existingItem = $existingItems->get(Str::upper($sku));
        $description = $this->normalizeText($this->stringValue($values[$headerMap['description'] ?? -1] ?? null)) ?: ($existingItem?->description ?? $sku);
        $resolvedLocation = $this->resolveEdelvivesLocation($values[$headerMap['location_text']] ?? null, $sheetName, $sku, $lineNumber);
        $locationCode = $resolvedLocation['code'];
        $warningGroups = $resolvedLocation['warning_groups'];
        $metrics = $this->parseEdelvivesMetrics($values, $headerMap);
        $unitsPerPallet = $metrics['units_per_pallet'] !== null
            ? max(0, $metrics['units_per_pallet'])
            : max(0, (int) ($existingItem?->units_per_pallet ?? 0));
        $quantityFromFileRaw = $metrics['quantity_units'];
        $quantityFromFile = max(0, $quantityFromFileRaw ?? 0);
        $fullPalletsFromFile = max(0, $metrics['full_pallets'] ?? 0);
        $peaksCountFromFileRaw = $metrics['peaks_count'];
        $peaksCountFromFile = max(0, $peaksCountFromFileRaw ?? 0);
        $reportedTotalPalletsRaw = $metrics['reported_total_pallets'];
        $reportedTotalPallets = max(0, $reportedTotalPalletsRaw ?? 0);

        $catalogItem = [
            'sku' => $sku,
            'description' => $description,
            'units_per_pallet' => $unitsPerPallet > 0 ? $unitsPerPallet : null,
            'source_sheet' => $sheetName,
        ];

        if ($quantityFromFile === 0 && $fullPalletsFromFile === 0 && $peaksCountFromFile === 0 && $metrics['peak_units'] === 0) {
            return [
                'row' => null,
                'catalog_item' => $catalogItem,
                'skip' => false,
                'warning_groups' => [[
                    'key' => 'non_positive_stock_'.$sheetName,
                    'summary' => 'Se han omitido :count filas sin stock positivo en '.$sheetName.'.',
                    'detail' => null,
                ]],
                'error' => null,
                'error_group' => null,
            ];
        }

        $peaks = $metrics['peaks'];
        $peakUnits = $metrics['peak_units'];
        $computedPeaksCount = $metrics['computed_peaks_count'];
        $calculatedQuantity = ($unitsPerPallet > 0 || $fullPalletsFromFile === 0)
            ? ($fullPalletsFromFile * $unitsPerPallet) + $peakUnits
            : null;

        if ($peaksCountFromFileRaw !== null && $peaksCountFromFile > 0 && $peaksCountFromFile !== $computedPeaksCount) {
            $warningGroups[] = [
                'key' => 'peaks_count_mismatch_'.$sheetName,
                'summary' => 'Se han detectado :count filas con numero de picos distinto al detalle en '.$sheetName.'.',
                'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' con numero de picos '.$peaksCountFromFile.' pero detalle calculado '.$computedPeaksCount.' para SKU '.$sku.'.',
            ];
        }

        if ($reportedTotalPalletsRaw !== null && $reportedTotalPallets > 0 && $reportedTotalPallets !== ($fullPalletsFromFile + $computedPeaksCount)) {
            $warningGroups[] = [
                'key' => 'reported_total_mismatch_'.$sheetName,
                'summary' => 'Se han detectado :count filas con total pallets distinto al calculado en '.$sheetName.'.',
                'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' con total pallets '.$reportedTotalPallets.' y calculado '.($fullPalletsFromFile + $computedPeaksCount).' para SKU '.$sku.'.',
            ];
        }

        if ($unitsPerPallet === 0 && $fullPalletsFromFile === 0 && $peakUnits > 0) {
            $warningGroups[] = [
                'key' => 'peak_only_without_units_'.$sheetName,
                'summary' => 'Se importaran :count filas de '.$sheetName.' sin unidades por pallet, usando solo picos.',
                'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' para SKU '.$sku.' sin unidades por pallet. Se importa como stock en picos.',
            ];
        }

        if ($unitsPerPallet === 0 && $fullPalletsFromFile > 0) {
            $warningGroups[] = [
                'key' => 'pallets_without_units_'.$sheetName,
                'summary' => 'Se importaran :count filas de '.$sheetName.' con pallets declarados pero sin unidades por pallet.',
                'detail' => $quantityFromFile > 0 || $peakUnits > 0
                    ? 'Fila '.$lineNumber.' de '.$sheetName.' para SKU '.$sku.' con pallets declarados y sin unidades por pallet. Se conserva la cantidad del archivo.'
                    : 'Fila '.$lineNumber.' de '.$sheetName.' para SKU '.$sku.' con pallets declarados y sin unidades por pallet. Se conserva como stock logistico con cantidad cero.',
            ];
        }

        if ($calculatedQuantity !== null && $quantityFromFileRaw !== null && $quantityFromFile > 0 && $quantityFromFile !== $calculatedQuantity) {
            $warningGroups[] = [
                'key' => 'quantity_mismatch_'.$sheetName,
                'summary' => 'Se han detectado :count filas con cantidad distinta al desglose calculado en '.$sheetName.'.',
                'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' con cantidad '.$quantityFromFile.' y calculado '.$calculatedQuantity.' para SKU '.$sku.'. Se usara el desglose de pallets y picos.',
            ];
        }

        $quantityUnits = $calculatedQuantity !== null && $calculatedQuantity > 0
            ? $calculatedQuantity
            : $quantityFromFile;

        if ($quantityUnits <= 0 && $peakUnits > 0) {
            $quantityUnits = $peakUnits;
        }

        if ($quantityUnits <= 0 && $fullPalletsFromFile === 0 && $peakUnits === 0) {
            return [
                'row' => null,
                'catalog_item' => $catalogItem,
                'skip' => false,
                'warning_groups' => $warningGroups,
                'error' => 'Fila '.$lineNumber.' de '.$sheetName.' sin cantidad importable para SKU '.$sku.'.',
                'error_group' => [
                    'key' => 'invalid_quantity_'.$sheetName,
                    'summary' => 'Se han encontrado :count filas en '.$sheetName.' sin cantidad importable.',
                    'detail' => 'Fila '.$lineNumber.' de '.$sheetName.' sin cantidad importable para SKU '.$sku.'.',
                ],
            ];
        }

        $rowData = [
            'sku' => $sku,
            'description' => $description,
            'lot' => self::EDELVIVES_DEFAULT_LOT,
            'location_code' => $locationCode,
            'location_text' => $locationCode,
            'quantity_units' => $quantityUnits,
            'units_per_pallet' => $unitsPerPallet,
            'full_pallets' => $fullPalletsFromFile,
            'peaks_count' => $computedPeaksCount,
            'received_at' => null,
            'status' => StockPallet::STATUS_AVAILABLE,
            'blocked_reason' => null,
            'source_sheet' => $sheetName,
        ];

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $rowData['peak_'.$peakNumber] = $peaks[$peakNumber];
        }

        return [
            'row' => $rowData,
            'catalog_item' => $catalogItem,
            'skip' => false,
            'warning_groups' => $warningGroups,
            'error' => null,
            'error_group' => null,
        ];
    }

    /**
     * @param  array<int, mixed>  $values
     * @param  array<string, int>  $headerMap
     * @return array{
     *     quantity_units: ?int,
     *     units_per_pallet: ?int,
     *     full_pallets: ?int,
     *     peaks_count: ?int,
     *     reported_total_pallets: ?int,
     *     peaks: array<int, int>,
     *     peak_units: int,
     *     computed_peaks_count: int
     * }
     */
    private function parseEdelvivesMetrics(array $values, array $headerMap): array
    {
        $peaks = [];

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $peaks[$peakNumber] = max(0, $this->parseEdelvivesIntegerValue($values[$headerMap['peak_'.$peakNumber] ?? -1] ?? null) ?? 0);
        }

        return [
            'quantity_units' => $this->parseEdelvivesIntegerValue($values[$headerMap['quantity_units'] ?? -1] ?? null),
            'units_per_pallet' => $this->parseEdelvivesIntegerValue($values[$headerMap['units_per_pallet'] ?? -1] ?? null),
            'full_pallets' => $this->parseEdelvivesIntegerValue($values[$headerMap['full_pallets'] ?? -1] ?? null),
            'peaks_count' => $this->parseEdelvivesIntegerValue($values[$headerMap['peaks_count'] ?? -1] ?? null),
            'reported_total_pallets' => $this->parseEdelvivesIntegerValue($values[$headerMap['reported_total_pallets'] ?? -1] ?? null),
            'peaks' => $peaks,
            'peak_units' => array_sum($peaks),
            'computed_peaks_count' => count(array_filter($peaks, fn (int $value): bool => $value > 0)),
        ];
    }

    /**
     * @return array<string, int>|null
     */
    private function buildEdelvivesColumnMap(Row $row): ?array
    {
        $values = $row->toArray();

        $matchesSeparatedDescriptionStructure = $this->normalizeHeaderCellValue($values[1] ?? null) === 'gramaje'
            && $this->matchesHeaderAlias('sku', $this->normalizeHeaderCellValue($values[2] ?? null))
            && $this->matchesHeaderAlias('quantity_units', $this->normalizeHeaderCellValue($values[4] ?? null))
            && $this->matchesHeaderAlias('units_per_pallet', $this->normalizeHeaderCellValue($values[5] ?? null))
            && $this->matchesHeaderAlias('full_pallets', $this->normalizeHeaderCellValue($values[6] ?? null))
            && $this->matchesHeaderAlias('peaks_count', $this->normalizeHeaderCellValue($values[7] ?? null))
            && preg_match('/^(pico|peak)\s*1$/', $this->normalizeHeaderCellValue($values[8] ?? null)) === 1
            && $this->matchesHeaderAlias('reported_total_pallets', $this->normalizeHeaderCellValue($values[18] ?? null));

        if ($matchesSeparatedDescriptionStructure) {
            $map = [
                'location_text' => 0,
                'sku' => 2,
                'description' => 3,
                'quantity_units' => 4,
                'units_per_pallet' => 5,
                'full_pallets' => 6,
                'peaks_count' => 7,
                'reported_total_pallets' => 18,
            ];

            foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
                $map['peak_'.$peakNumber] = 7 + $peakNumber;
            }

            return $map;
        }

        $matchesCombinedReferenceStructure = $this->normalizeHeaderCellValue($values[1] ?? null) === 'gramaje'
            && $this->matchesHeaderAlias('quantity_units', $this->normalizeHeaderCellValue($values[3] ?? null))
            && $this->matchesHeaderAlias('units_per_pallet', $this->normalizeHeaderCellValue($values[4] ?? null))
            && $this->matchesHeaderAlias('full_pallets', $this->normalizeHeaderCellValue($values[5] ?? null))
            && $this->matchesHeaderAlias('peaks_count', $this->normalizeHeaderCellValue($values[6] ?? null))
            && preg_match('/^(pico|peak)\s*1$/', $this->normalizeHeaderCellValue($values[7] ?? null)) === 1
            && $this->matchesHeaderAlias('reported_total_pallets', $this->normalizeHeaderCellValue($values[17] ?? null));

        if (! $matchesCombinedReferenceStructure) {
            return null;
        }

        $map = [
            'location_text' => 0,
            'sku' => 2,
            'description' => 2,
            'quantity_units' => 3,
            'units_per_pallet' => 4,
            'full_pallets' => 5,
            'peaks_count' => 6,
            'reported_total_pallets' => 17,
        ];

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $map['peak_'.$peakNumber] = 6 + $peakNumber;
        }

        return $map;
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
                $this->matchesHeaderAlias('reported_total_pallets', $canonical) => 'reported_total_pallets',
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

    private function normalizeHeaderCellValue(mixed $value): string
    {
        return $this->canonicalizeHeaderAlias($this->normalizeHeaderLabel($this->stringValue($value)));
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

        $normalized = $this->normalizeIntegerString((string) $value);

        return $normalized !== null && $normalized !== '' && is_numeric($normalized)
            ? (int) $normalized
            : null;
    }

    private function normalizeIntegerString(string $value): ?string
    {
        $normalized = trim(str_replace(["\xc2\xa0", ' '], '', $value));

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $normalized) === 1) {
            return $normalized;
        }

        if (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $normalized) === 1) {
            return str_replace([',', '.'], '', $normalized);
        }

        if (preg_match('/^-?\d+[.,]\d+$/', $normalized) === 1) {
            $separator = str_contains($normalized, ',') ? ',' : '.';
            [$wholePart, $fractionPart] = explode($separator, $normalized, 2);

            if ($fractionPart !== '' && preg_match('/^0+$/', $fractionPart) === 1) {
                return $wholePart;
            }

            if (strlen($fractionPart) === 3) {
                return $wholePart.$fractionPart;
            }

            return (string) (int) round((float) str_replace(',', '.', $normalized));
        }

        if (preg_match('/^-?\d{1,3}([.,]\d{3})+[.,]\d+$/', $normalized) === 1) {
            $lastDot = strrpos($normalized, '.');
            $lastComma = strrpos($normalized, ',');
            $decimalPosition = max($lastDot === false ? -1 : $lastDot, $lastComma === false ? -1 : $lastComma);
            $wholePart = substr($normalized, 0, $decimalPosition);
            $fractionPart = substr($normalized, $decimalPosition + 1);
            $wholePart = str_replace([',', '.'], '', $wholePart);

            if ($fractionPart !== '' && preg_match('/^0+$/', $fractionPart) === 1) {
                return $wholePart;
            }

            if (strlen($fractionPart) === 3) {
                return $wholePart.$fractionPart;
            }

            return (string) (int) round((float) str_replace(',', '.', $wholePart.'.'.$fractionPart));
        }

        $digitsOnly = preg_replace('/[^0-9\-]/', '', $normalized);

        return $digitsOnly !== null && $digitsOnly !== '' ? $digitsOnly : null;
    }

    private function parseEdelvivesIntegerValue(mixed $value): ?int
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

        $normalized = trim(str_replace(["\xc2\xa0", ' '], '', (string) $value));

        if ($normalized === '') {
            return null;
        }

        if (preg_match('/^-?\d+$/', $normalized) === 1) {
            return (int) $normalized;
        }

        if (preg_match('/^-?\d{1,3}([.,]\d{3})+$/', $normalized) === 1) {
            return (int) str_replace([',', '.'], '', $normalized);
        }

        if (preg_match('/^-?\d+[.,]0+$/', $normalized) === 1) {
            [$wholePart] = preg_split('/[.,]/', $normalized, 2);

            return (int) $wholePart;
        }

        if (preg_match('/^-?\d+[.,]\d{3}$/', $normalized) === 1) {
            [$wholePart, $fractionPart] = preg_split('/[.,]/', $normalized, 2);

            return (int) ($wholePart.$fractionPart);
        }

        if (preg_match('/^-?\d{1,3}([.,]\d{3})+[.,]0+$/', $normalized) === 1) {
            $sanitized = preg_replace('/[.,]0+$/', '', $normalized);

            return $sanitized !== null ? (int) str_replace([',', '.'], '', $sanitized) : null;
        }

        return null;
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

            $remaining = $group['count'] - count($group['details']);

            if ($remaining > 0) {
                $compiled[] = 'y '.$remaining.' mas.';
            }
        }

        return $compiled;
    }

    /**
     * @param  array<string, mixed>  $existing
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeCatalogItems(array $existing, array $incoming): array
    {
        return [
            'sku' => $existing['sku'],
            'description' => $incoming['description'] !== '' ? $incoming['description'] : $existing['description'],
            'units_per_pallet' => ($incoming['units_per_pallet'] ?? 0) > 0
                ? $incoming['units_per_pallet']
                : $existing['units_per_pallet'],
            'source_sheet' => $existing['source_sheet'],
        ];
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

    private function usesEdelvivesProfile(Client $client): bool
    {
        return Str::upper(trim($client->code)) === 'EDELVIVES';
    }

    /**
     * @return array<string, Location>
     */
    private function ensureEdelvivesLocations(Client $client, array $locationCodes = []): array
    {
        $warehouse = Warehouse::query()
            ->where(function ($query) use ($client): void {
                $query
                    ->where(fn ($warehouseQuery) => $warehouseQuery
                        ->where('code', self::EDELVIVES_WAREHOUSE_CODE)
                        ->whereIn('client_id', [$client->id, null]))
                    ->orWhere(fn ($warehouseQuery) => $warehouseQuery
                        ->where('name', self::EDELVIVES_WAREHOUSE_NAME)
                        ->whereIn('client_id', [$client->id, null]));
            })
            ->orderByRaw('client_id is null desc')
            ->first();

        if ($warehouse === null) {
            $warehouse = Warehouse::query()->create([
                'client_id' => null,
                'code' => self::EDELVIVES_WAREHOUSE_CODE,
                'name' => self::EDELVIVES_WAREHOUSE_NAME,
                'active' => true,
            ]);
        }

        $codes = collect(range(0, 45))
            ->map(fn (int $value): string => (string) $value)
            ->merge(['A', 'B', 'C', 'D', 'E', 'F', 'FONDO', 'SIN UBICACION'])
            ->merge($locationCodes)
            ->map(fn (mixed $code): string => Str::upper($this->normalizeText((string) $code)))
            ->filter(fn (string $code): bool => $code !== '')
            ->unique()
            ->values();

        foreach ($codes as $code) {
            Location::query()->updateOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'code' => $code,
                ],
                [
                    'name' => 'Calle '.$code,
                    'aisle' => $code,
                    'active' => true,
                ],
            );
        }

        return Location::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('code', $codes->all())
            ->get()
            ->keyBy('code')
            ->all();
    }

    private function normalizeSku(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function normalizeText(string $value): string
    {
        return preg_replace('/\s+/u', ' ', trim($value)) ?? trim($value);
    }

    private function normalizeEdelvivesLocation(mixed $value): ?string
    {
        return $this->resolveEdelvivesLocation($value, 'STOCK', 'SKU', 0)['code'];
    }

    /**
     * @return array{code: string, warning_groups: list<array{key: string, summary: string, detail: ?string}>}
     */
    private function resolveEdelvivesLocation(mixed $value, string $sheetName, string $sku, int $lineNumber): array
    {
        $parsedInteger = $this->integerValue($value);

        if ($parsedInteger !== null && $parsedInteger >= 0 && $parsedInteger <= 45) {
            return [
                'code' => (string) $parsedInteger,
                'warning_groups' => [],
            ];
        }

        $normalized = Str::upper($this->normalizeText($this->stringValue($value)));

        if ($normalized === '') {
            return [
                'code' => 'SIN UBICACION',
                'warning_groups' => [[
                    'key' => 'missing_location_'.$sheetName,
                    'summary' => 'Se importaran :count filas de '.$sheetName.' sin ubicacion en SIN UBICACION.',
                    'detail' => $lineNumber > 0 ? 'Fila '.$lineNumber.' de '.$sheetName.' para SKU '.$sku.' sin ubicacion. Se importa en SIN UBICACION.' : null,
                ]],
            ];
        }

        if (preg_match('/^FONDO\s*\?*$/', $normalized) === 1) {
            return [
                'code' => 'FONDO',
                'warning_groups' => $normalized === 'FONDO'
                    ? []
                    : [[
                        'key' => 'normalized_location_'.$sheetName,
                        'summary' => 'Se normalizaran :count ubicaciones especiales en '.$sheetName.'.',
                        'detail' => $lineNumber > 0 ? 'Fila '.$lineNumber.' de '.$sheetName.' para SKU '.$sku.' con ubicacion '.$normalized.'. Se normaliza a FONDO.' : null,
                    ]],
            ];
        }

        if (in_array($normalized, ['A', 'B', 'C', 'D', 'E', 'F'], true)) {
            return [
                'code' => $normalized,
                'warning_groups' => [],
            ];
        }

        $normalizedRange = preg_replace('/\s*-\s*/u', '-', $normalized) ?? $normalized;

        if (preg_match('/^(\d{1,2})-(\d{1,2})$/', $normalizedRange, $matches) === 1) {
            $from = (int) $matches[1];
            $to = (int) $matches[2];

            if ($from >= 0 && $from <= 45 && $to >= 0 && $to <= 45) {
                return [
                    'code' => $normalizedRange,
                    'warning_groups' => [],
                ];
            }
        }

        return [
            'code' => 'SIN UBICACION',
            'warning_groups' => [[
                'key' => 'unknown_location_'.$sheetName,
                'summary' => 'Se importaran :count filas de '.$sheetName.' con ubicacion no reconocida en SIN UBICACION.',
                'detail' => $lineNumber > 0 ? 'Fila '.$lineNumber.' de '.$sheetName.' para SKU '.$sku.' con ubicacion '.$normalized.'. Se importa en SIN UBICACION.' : null,
            ]],
        ];
    }
}
