<?php

namespace App\Services\Backups;

use App\Models\BackupExport;
use App\Models\Client;
use App\Models\StockPallet;
use App\Models\User;
use App\Services\Audit\AuditLogService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\Process\Process;
use Throwable;
use ZipArchive;

class BackupService
{
    private const SENSITIVE_KEY_PATTERN = '/password|passwd|secret|token|authorization|cookie|api[_-]?key|remember/i';

    public function createManual(string $type, ?Client $client = null, ?User $user = null): BackupExport
    {
        if (! in_array($type, BackupExport::manualTypes(), true)) {
            throw new RuntimeException('Tipo de backup no valido.');
        }

        if ($type === BackupExport::TYPE_STOCK_CLIENT && ! $client instanceof Client) {
            throw new RuntimeException('El backup de stock por cliente requiere un cliente.');
        }

        $backup = BackupExport::query()->create([
            'type' => $type,
            'scope' => $client instanceof Client ? $client->code : $type,
            'client_id' => $client?->id,
            'status' => BackupExport::STATUS_PENDING,
            'disk' => $this->diskName(),
            'created_by' => $user?->id,
            'metadata' => [
                'requested_from' => app()->runningInConsole() ? 'cli' : 'web',
                'mysqldump_available' => $this->mysqldumpPath() !== null,
                'secrets_policy' => 'env_excluded_sensitive_columns_redacted',
            ],
        ]);

        return $this->run($backup, $client, $user);
    }

    public function run(BackupExport $backup, ?Client $client = null, ?User $user = null): BackupExport
    {
        $backup->forceFill([
            'status' => BackupExport::STATUS_RUNNING,
            'started_at' => now(),
            'error_message' => null,
        ])->save();

        try {
            $artifact = match ($backup->type) {
                BackupExport::TYPE_FULL_SYSTEM => $this->createFullSystemArchive($backup),
                BackupExport::TYPE_DATABASE => $this->createDatabaseExport($backup),
                BackupExport::TYPE_MOVEMENTS => $this->createTableExport($backup, $this->tableGroup('movements'), 'movimientos'),
                BackupExport::TYPE_OPERATIONS => $this->createTableExport($backup, $this->tableGroup('operations'), 'operaciones'),
                BackupExport::TYPE_STOCK => $this->createStockExport($backup),
                BackupExport::TYPE_STOCK_CLIENT => $this->createStockExport($backup, $client ?? $backup->client),
                default => throw new RuntimeException('Tipo de backup no valido.'),
            };

            $backup->forceFill([
                'status' => BackupExport::STATUS_COMPLETED,
                'disk' => $artifact['disk'],
                'path' => $artifact['path'],
                'filename' => $artifact['filename'],
                'mime_type' => $artifact['mime_type'],
                'size_bytes' => $artifact['size_bytes'],
                'checksum' => $artifact['checksum'],
                'finished_at' => now(),
                'metadata' => array_merge($backup->metadata ?? [], $artifact['metadata']),
            ])->save();

            $this->audit('backup.created', 'Backup generado: '.$backup->typeLabel(), $backup, $user);
        } catch (Throwable $exception) {
            $backup->forceFill([
                'status' => BackupExport::STATUS_FAILED,
                'finished_at' => now(),
                'error_message' => Str::limit($exception->getMessage(), 2000, ''),
            ])->save();

            $this->audit('backup.failed', 'Backup fallido: '.$backup->typeLabel(), $backup, $user, 'warning');
        }

        return $backup->fresh(['client', 'creator']);
    }

    public function recordDownload(BackupExport $backup, ?User $user = null): void
    {
        $this->audit('backup.downloaded', 'Backup descargado: '.$backup->typeLabel(), $backup, $user);
    }

    public function delete(BackupExport $backup, ?User $user = null): void
    {
        if ($this->isSafeBackupPath((string) $backup->path) && Storage::disk($backup->disk)->exists((string) $backup->path)) {
            Storage::disk($backup->disk)->delete((string) $backup->path);
        }

        $this->audit('backup.deleted', 'Backup eliminado: '.$backup->typeLabel(), $backup, $user, 'warning');
        $backup->delete();
    }

    public function isSafeBackupPath(string $path): bool
    {
        return $path !== ''
            && str_starts_with(str_replace('\\', '/', $path), $this->basePath().'/')
            && ! str_contains($path, '..');
    }

    /**
     * @return array{disk:string,path:string,filename:string,mime_type:string,size_bytes:int,checksum:string,metadata:array<string,mixed>}
     */
    public function createStockExport(BackupExport $backup, ?Client $client = null, ?string $date = null, ?string $path = null): array
    {
        $date ??= now()->toDateString();
        $scope = $client instanceof Client ? $this->clientCodeSegment($client) : 'TODOS';
        $filename = ($backup->type === BackupExport::TYPE_STOCK_SNAPSHOT_DAILY ? $date.'_stock_' : 'backup_stock_')
            .$scope.'.csv.gz';
        $path ??= $this->basePath().'/stock/'.now()->format('Y/m/d/His').'_'.$filename;

        $rows = $this->stockRows($client, $date);
        $csv = $this->csv([
            'snapshot_date',
            'client_id',
            'client_code',
            'client_name',
            'item_id',
            'sku',
            'description',
            'lot',
            'stock_status',
            'stock_category',
            'quantity',
            'units_per_pallet',
            'warehouse_pallets',
            'full_pallets',
            'peaks',
            'warehouse',
            'location_code',
            'location_label',
            'created_at',
            'exported_at',
        ], $rows);

        Storage::disk($this->diskName())->put($path, gzencode($csv, 9));

        return $this->artifact($path, $filename, 'application/gzip', [
            'format' => 'csv.gz',
            'rows' => count($rows),
            'client_code' => $client?->code,
            'snapshot_date' => $date,
            'contains_env' => false,
            'stored_outside_public' => true,
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function stockRows(?Client $client, string $date): array
    {
        return StockPallet::query()
            ->with(['client', 'item', 'location.warehouse'])
            ->where('active', true)
            ->when($client instanceof Client, fn (Builder $query) => $query->where('client_id', $client->id))
            ->orderBy('client_id')
            ->orderBy('item_id')
            ->orderBy('lot')
            ->get()
            ->map(function (StockPallet $stockPallet) use ($date): array {
                return [
                    'snapshot_date' => $date,
                    'client_id' => $stockPallet->client_id,
                    'client_code' => $stockPallet->client?->code,
                    'client_name' => $stockPallet->client?->name,
                    'item_id' => $stockPallet->item_id,
                    'sku' => $stockPallet->item?->sku,
                    'description' => $stockPallet->item?->description,
                    'lot' => $stockPallet->lot ?: 'SIN LOTE',
                    'stock_status' => $stockPallet->status,
                    'stock_category' => $stockPallet->stock_category,
                    'quantity' => (int) $stockPallet->quantity_units,
                    'units_per_pallet' => (int) $stockPallet->units_per_pallet,
                    'warehouse_pallets' => (string) $stockPallet->warehouse_pallets,
                    'full_pallets' => (int) $stockPallet->full_pallets,
                    'peaks' => (int) $stockPallet->peaks_count,
                    'warehouse' => $stockPallet->location?->warehouse?->name ?? $stockPallet->location?->warehouse?->code,
                    'location_code' => $stockPallet->location?->code ?? $stockPallet->location_text,
                    'location_label' => $stockPallet->pickingLocationLabel(),
                    'created_at' => optional($stockPallet->created_at)->toDateTimeString(),
                    'exported_at' => now()->toDateTimeString(),
                ];
            })
            ->all();
    }

    /**
     * @return array{disk:string,path:string,filename:string,mime_type:string,size_bytes:int,checksum:string,metadata:array<string,mixed>}
     */
    private function createDatabaseExport(BackupExport $backup): array
    {
        if ($this->canUseMysqlDump()) {
            $dump = $this->mysqldump();
            if ($dump !== null) {
                $filename = 'backup_database_'.now()->format('Ymd_His').'.sql.gz';
                $path = $this->basePath().'/database/'.now()->format('Y/m/d/His').'_'.$filename;
                Storage::disk($this->diskName())->put($path, gzencode($dump, 9));

                return $this->artifact($path, $filename, 'application/gzip', [
                    'format' => 'sql.gz',
                    'mysqldump' => true,
                    'contains_env' => false,
                    'stored_outside_public' => true,
                ]);
            }
        }

        return $this->createTableExport($backup, $this->allKnownTables(), 'base_datos', [
            'mysqldump' => false,
            'fallback_reason' => 'mysqldump no esta disponible o no esta habilitado para dumps con secretos.',
        ]);
    }

    /**
     * @return array{disk:string,path:string,filename:string,mime_type:string,size_bytes:int,checksum:string,metadata:array<string,mixed>}
     */
    private function createTableExport(BackupExport $backup, array $tables, string $scope, array $extraMetadata = []): array
    {
        $existingTables = collect($tables)->filter(fn (string $table): bool => Schema::hasTable($table))->values();
        $payload = [
            'manifest' => $this->manifest($backup->type, [
                'scope' => $scope,
                'tables' => $existingTables->all(),
                'format' => 'json.gz',
            ] + $extraMetadata),
            'tables' => [],
        ];

        foreach ($existingTables as $table) {
            $payload['tables'][$table] = $this->tableRows($table)
                ->map(fn (object $row): array => $this->sanitizeRow((array) $row))
                ->all();
        }

        $filename = 'backup_'.$scope.'_'.now()->format('Ymd_His').'.json.gz';
        $path = $this->basePath().'/'.$scope.'/'.now()->format('Y/m/d/His').'_'.$filename;
        Storage::disk($this->diskName())->put($path, gzencode(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 9));

        return $this->artifact($path, $filename, 'application/gzip', [
            'format' => 'json.gz',
            'tables' => $existingTables->all(),
            'contains_env' => false,
            'sensitive_columns_redacted' => true,
            'stored_outside_public' => true,
        ] + $extraMetadata);
    }

    /**
     * @return array{disk:string,path:string,filename:string,mime_type:string,size_bytes:int,checksum:string,metadata:array<string,mixed>}
     */
    private function createFullSystemArchive(BackupExport $backup): array
    {
        if (! class_exists(ZipArchive::class)) {
            throw new RuntimeException('ZipArchive no esta disponible en este entorno.');
        }

        $filename = 'backup_sistema_completo_'.now()->format('Ymd_His').'.zip';
        $path = $this->basePath().'/full-system/'.now()->format('Y/m/d/His').'_'.$filename;
        $absolutePath = Storage::disk($this->diskName())->path($path);

        if (! is_dir(dirname($absolutePath))) {
            mkdir(dirname($absolutePath), 0775, true);
        }

        $zip = new ZipArchive();
        if ($zip->open($absolutePath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('No se ha podido crear el ZIP del sistema completo.');
        }

        $zip->addFromString('manifest.json', json_encode($this->manifest($backup->type, [
            'includes_database_export' => true,
            'includes_private_storage' => true,
            'excludes_env' => true,
            'excludes_backups' => true,
            'excludes_vendor' => true,
            'excludes_node_modules' => true,
        ]), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        $databasePayload = $this->databasePayloadForZip($backup);
        $zip->addFromString('database/database.json.gz', gzencode(json_encode($databasePayload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), 9));

        $privateRoot = Storage::disk($this->diskName())->path('');
        if (is_dir($privateRoot)) {
            $this->addDirectoryToZip($zip, $privateRoot, 'storage', [
                $this->normalizePath(Storage::disk($this->diskName())->path($this->basePath())),
            ]);
        }

        $zip->close();

        return $this->artifact($path, $filename, 'application/zip', [
            'format' => 'zip',
            'contains_env' => false,
            'stored_outside_public' => true,
            'database_format' => 'json.gz',
            'sensitive_columns_redacted' => true,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function databasePayloadForZip(BackupExport $backup): array
    {
        $tables = collect($this->allKnownTables())->filter(fn (string $table): bool => Schema::hasTable($table))->values();

        return [
            'manifest' => $this->manifest($backup->type, [
                'scope' => 'sistema_completo',
                'tables' => $tables->all(),
                'format' => 'json.gz',
            ]),
            'tables' => $tables->mapWithKeys(fn (string $table): array => [
                $table => $this->tableRows($table)
                    ->map(fn (object $row): array => $this->sanitizeRow((array) $row))
                    ->all(),
            ])->all(),
        ];
    }

    /**
     * @param  list<string>  $excludedRoots
     */
    private function addDirectoryToZip(ZipArchive $zip, string $root, string $zipPrefix, array $excludedRoots): void
    {
        $root = rtrim($this->normalizePath($root), '/');
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::SELF_FIRST
        );

        foreach ($iterator as $file) {
            $path = $this->normalizePath($file->getPathname());

            if ($this->pathIsExcluded($path, $excludedRoots) || $file->isDir()) {
                continue;
            }

            $relative = ltrim(substr($path, strlen($root)), '/');
            if ($relative === '' || str_starts_with($relative, 'backups/')) {
                continue;
            }

            $zip->addFile($path, $zipPrefix.'/'.$relative);
        }
    }

    /**
     * @param  list<string>  $excludedRoots
     */
    private function pathIsExcluded(string $path, array $excludedRoots): bool
    {
        foreach ($excludedRoots as $excludedRoot) {
            if ($excludedRoot !== '' && str_starts_with($path, rtrim($excludedRoot, '/'))) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     */
    private function csv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        fputcsv($handle, $headers, ';');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(fn (string $header): mixed => $row[$header] ?? null, $headers), ';');
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return (string) $csv;
    }

    /**
     * @return array<string, mixed>
     */
    private function sanitizeRow(array $row): array
    {
        return collect($row)
            ->reject(fn (mixed $value, string $key): bool => preg_match(self::SENSITIVE_KEY_PATTERN, $key) === 1)
            ->map(fn (mixed $value): mixed => is_string($value) ? Str::limit($value, 10000, '') : $value)
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    private function manifest(string $type, array $extra = []): array
    {
        return [
            'app' => config('app.name', 'MAXIMO WMS'),
            'type' => $type,
            'generated_at' => now()->toIso8601String(),
            'commit' => trim((string) @shell_exec('git rev-parse --short HEAD 2>NUL')),
            'database' => config('database.default'),
            'contains_env' => false,
            'stored_outside_public' => true,
        ] + $extra;
    }

    /**
     * @return array{disk:string,path:string,filename:string,mime_type:string,size_bytes:int,checksum:string,metadata:array<string,mixed>}
     */
    private function artifact(string $path, string $filename, string $mimeType, array $metadata): array
    {
        $absolutePath = Storage::disk($this->diskName())->path($path);

        return [
            'disk' => $this->diskName(),
            'path' => $path,
            'filename' => $filename,
            'mime_type' => $mimeType,
            'size_bytes' => is_file($absolutePath) ? filesize($absolutePath) : 0,
            'checksum' => is_file($absolutePath) ? hash_file('sha256', $absolutePath) : '',
            'metadata' => $metadata,
        ];
    }

    private function tableRows(string $table): Collection
    {
        $query = DB::table($table);

        if (Schema::hasColumn($table, 'id')) {
            $query->orderBy('id');
        } elseif (Schema::hasColumn($table, 'created_at')) {
            $query->orderBy('created_at');
        }

        return $query->get();
    }

    private function canUseMysqlDump(): bool
    {
        return (bool) config('wms.backups.allow_sensitive_database_dump', false)
            && config('database.default') === 'mysql'
            && $this->mysqldumpPath() !== null;
    }

    private function mysqldump(): ?string
    {
        $path = $this->mysqldumpPath();
        $connection = config('database.connections.mysql');

        if ($path === null || ! is_array($connection)) {
            return null;
        }

        $process = new Process(array_filter([
            $path,
            '--single-transaction',
            '--quick',
            '--skip-comments',
            '-h', (string) ($connection['host'] ?? '127.0.0.1'),
            '-P', (string) ($connection['port'] ?? '3306'),
            '-u', (string) ($connection['username'] ?? ''),
            (string) ($connection['database'] ?? ''),
        ]), base_path(), [
            'MYSQL_PWD' => (string) ($connection['password'] ?? ''),
        ]);
        $process->setTimeout(300);
        $process->run();

        return $process->isSuccessful() ? $process->getOutput() : null;
    }

    private function mysqldumpPath(): ?string
    {
        $command = PHP_OS_FAMILY === 'Windows' ? 'where mysqldump' : 'command -v mysqldump';
        $process = Process::fromShellCommandline($command);
        $process->run();

        if (! $process->isSuccessful()) {
            return null;
        }

        $path = trim(strtok($process->getOutput(), PHP_EOL) ?: '');

        return $path !== '' ? $path : null;
    }

    /**
     * @return list<string>
     */
    private function tableGroup(string $group): array
    {
        return config('wms.backups.table_groups.'.$group, []);
    }

    /**
     * @return list<string>
     */
    private function allKnownTables(): array
    {
        return collect(config('wms.backups.table_groups', []))
            ->flatten()
            ->merge([
                'users',
                'roles',
                'access_requests',
                'backup_exports',
                'audit_logs',
                'stock_alert_rules',
                'stock_alert_events',
                'client_receipt_email_recipients',
                'client_dispatch_email_recipients',
                'client_stock_alert_email_recipients',
                'notifications',
                'jobs',
                'cache',
                'cache_locks',
                'failed_jobs',
                'password_reset_tokens',
                'sessions',
            ])
            ->unique()
            ->values()
            ->all();
    }

    private function diskName(): string
    {
        return (string) config('wms.backups.disk', 'local');
    }

    private function basePath(): string
    {
        return trim((string) config('wms.backups.path', 'backups'), '/');
    }

    private function normalizePath(string $path): string
    {
        return str_replace('\\', '/', $path);
    }

    private function clientCodeSegment(Client $client): string
    {
        $code = $client->code ?: $client->name;
        $segment = preg_replace('/[^A-Za-z0-9_-]+/', '_', (string) $code);

        return trim((string) $segment, '_') !== '' ? Str::upper(trim((string) $segment, '_')) : 'CLIENTE_'.$client->id;
    }

    private function audit(string $event, string $description, BackupExport $backup, ?User $user = null, string $severity = 'info'): void
    {
        if (! Schema::hasTable('audit_logs')) {
            return;
        }

        app(AuditLogService::class)->record(
            event: $event,
            module: 'backups',
            description: $description,
            auditable: $backup,
            subject: $backup->client,
            user: $user,
            clientId: $backup->client_id,
            metadata: [
                'type' => $backup->type,
                'path' => $backup->path,
                'filename' => $backup->filename,
                'size_bytes' => $backup->size_bytes,
            ],
            source: app()->runningInConsole() ? 'cli' : 'web',
            severity: $severity,
        );
    }
}
