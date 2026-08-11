<?php

use Symfony\Component\Process\Process;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$root = dirname(__DIR__, 2);
$exitCode = 0;

foreach ([100, 500, 1000] as $rows) {
    $process = new Process([
        PHP_BINARY,
        'artisan',
        'test',
        'tests/Benchmark/StockImportPerformanceBenchmark.php',
        '--colors=never',
    ], $root, ['BENCH_ROWS' => (string) $rows]);
    $process->setTimeout(120);
    $process->run(static fn (string $type, string $buffer) => print $buffer);

    if (! $process->isSuccessful()) {
        $exitCode = $process->getExitCode() ?? 1;
        break;
    }
}

exit($exitCode);
