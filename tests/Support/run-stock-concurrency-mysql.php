<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$config = config('database.connections.mysql');
$host = (string) ($config['host'] ?? '');

if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || app()->environment('production')) {
    fwrite(STDERR, "Refused: the two-connection regression may only use a local, non-production MySQL/MariaDB server.\n");
    exit(2);
}

$database = (string) ($config['database'] ?? '');
$prefix = '';
$isolatedPrefixReady = false;

if ($database === '') {
    fwrite(STDERR, "Refused: the local MySQL database name is empty.\n");
    exit(3);
}

$dsn = sprintf(
    'mysql:host=%s;port=%d;dbname=%s;charset=%s',
    $host,
    (int) ($config['port'] ?? 3306),
    $database,
    (string) ($config['charset'] ?? 'utf8mb4'),
);
$pdo = new PDO($dsn, (string) ($config['username'] ?? ''), (string) ($config['password'] ?? ''), [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
]);
$existingPrefix = $pdo->prepare('SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LEFT(TABLE_NAME, 2) = ?');
$prefixStart = random_int(0, 255);

for ($offset = 0; $offset < 256; $offset++) {
    $candidate = sprintf('%02x', ($prefixStart + $offset) % 256);
    $existingPrefix->execute([$database, $candidate]);

    if ((int) $existingPrefix->fetchColumn() === 0) {
        $prefix = $candidate;
        break;
    }
}

if (! preg_match('/^[a-f0-9]{2}$/', $prefix)) {
    fwrite(STDERR, "Refused: no unused isolated table prefix is available.\n");
    exit(3);
}

$isolatedPrefixReady = true;
$exitCode = 1;

try {
    $environment = [
        'APP_ENV' => 'testing',
        'DB_CONNECTION' => 'mysql',
        'DB_DATABASE' => $database,
        'DB_PREFIX' => $prefix,
        'WMS_CONCURRENCY_DB' => '1',
        'QUEUE_CONNECTION' => 'sync',
    ];
    $root = dirname(__DIR__, 2);
    $migrations = [
        'database/migrations/0001_01_01_000000_create_users_table.php',
        'database/migrations/2026_06_06_000002_create_roles_table.php',
        'database/migrations/2026_06_06_000003_add_role_id_to_users_table.php',
        'database/migrations/2026_06_25_000001_create_clients_table.php',
        'database/migrations/2026_06_25_000007_add_profile_fields_to_users_table.php',
        'database/migrations/2026_06_25_000002_create_items_table.php',
        'database/migrations/2026_06_25_000003_create_stock_pallets_table.php',
        'database/migrations/2026_06_25_000004_create_warehouses_table.php',
        'database/migrations/2026_06_25_000005_create_locations_table.php',
        'database/migrations/2026_06_25_000006_add_location_id_to_stock_pallets_table.php',
        'database/migrations/2026_06_26_000008_create_suppliers_table.php',
        'database/migrations/2026_06_26_000009_create_goods_receipts_table.php',
        'database/migrations/2026_06_26_000010_create_goods_receipt_lines_table.php',
        'database/migrations/2026_06_26_000011_add_goods_receipt_id_to_stock_pallets_table.php',
        'database/migrations/2026_06_27_000024_add_item_status_and_batch_fields.php',
        'database/migrations/2026_06_27_000025_add_batch_breakdown_fields_to_stock_pallets_table.php',
        'database/migrations/2026_06_28_000001_create_stock_imports_table.php',
        'database/migrations/2026_06_28_000002_add_import_metadata_to_stock_pallets_table.php',
        'database/migrations/2026_07_04_000001_add_stock_applied_tracking_to_goods_receipts.php',
        'database/migrations/2026_07_12_000001_add_stock_category_and_warehouse_pallets.php',
        'database/migrations/2026_07_15_000001_add_peaks_to_goods_receipt_lines_table.php',
        'database/migrations/2026_07_16_000001_create_traceability_foundation_tables.php',
        'database/migrations/2026_08_11_000001_create_stock_batch_identity_locks_table.php',
    ];

    foreach ($migrations as $migration) {
        $migrate = new Process([
            PHP_BINARY,
            'artisan',
            'migrate',
            '--force',
            '--no-interaction',
            '--path='.$migration,
        ], $root, $environment);
        $migrate->setTimeout(30);
        $migrate->run();

        if (! $migrate->isSuccessful()) {
            fwrite(STDERR, $migrate->getOutput().$migrate->getErrorOutput());
            throw new RuntimeException('An isolated migration failed.');
        }
    }

    $test = new Process([
        PHP_BINARY,
        'artisan',
        'test',
        'tests/Integration/StockBatchIdentityConcurrencyTest.php',
    ], $root, $environment);
    $test->setTimeout(120);
    $test->run(static fn (string $type, string $buffer) => print $buffer);
    $exitCode = $test->getExitCode() ?? 1;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    $exitCode = 1;
} finally {
    if (! $isolatedPrefixReady) {
        $exitCode = 3;
    }

    if (! preg_match('/^[a-f0-9]{2}$/', $prefix)) {
        throw new RuntimeException('Refusing to drop tables for an unexpected prefix.');
    }

    $statement = $pdo->prepare('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND LEFT(TABLE_NAME, ?) = ?');
    $statement->execute([$database, strlen($prefix), $prefix]);
    $tables = $statement->fetchAll(PDO::FETCH_COLUMN);
    $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

    try {
        foreach ($tables as $table) {
            if (! is_string($table) || ! str_starts_with($table, $prefix)) {
                throw new RuntimeException('Refusing to drop a table outside the isolated prefix.');
            }

            $pdo->exec('DROP TABLE `'.str_replace('`', '``', $table).'`');
        }
    } finally {
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}

exit($exitCode);
