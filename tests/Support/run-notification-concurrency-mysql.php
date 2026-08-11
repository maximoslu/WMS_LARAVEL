<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$config = config('database.connections.mysql');
$host = (string) ($config['host'] ?? '');

if (! in_array($host, ['127.0.0.1', 'localhost', '::1'], true) || app()->environment('production')) {
    fwrite(STDERR, "Refused: the notification concurrency regression may only use a local, non-production MySQL/MariaDB server.\n");
    exit(2);
}

$database = (string) ($config['database'] ?? '');

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
$prefix = '';
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

$root = dirname(__DIR__, 2);
$environment = [
    'APP_ENV' => 'testing',
    'DB_CONNECTION' => 'mysql',
    'DB_DATABASE' => $database,
    'DB_PREFIX' => $prefix,
    'WMS_NOTIFICATION_CONCURRENCY_DB' => '1',
    'QUEUE_CONNECTION' => 'sync',
];
$exitCode = 1;

try {
    $migrate = new Process([
        PHP_BINARY,
        'artisan',
        'migrate',
        '--force',
        '--no-interaction',
        '--path=database/migrations/2026_08_11_000002_create_notification_deliveries_table.php',
    ], $root, $environment);
    $migrate->setTimeout(30);
    $migrate->run();

    if (! $migrate->isSuccessful()) {
        fwrite(STDERR, $migrate->getOutput().$migrate->getErrorOutput());
        throw new RuntimeException('The isolated notification migration failed.');
    }

    $test = new Process([
        PHP_BINARY,
        'artisan',
        'test',
        'tests/Integration/NotificationDeliveryConcurrencyTest.php',
    ], $root, $environment);
    $test->setTimeout(60);
    $test->run(static fn (string $type, string $buffer) => print $buffer);
    $exitCode = $test->getExitCode() ?? 1;
} catch (Throwable $exception) {
    fwrite(STDERR, $exception->getMessage()."\n");
    $exitCode = 1;
} finally {
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
