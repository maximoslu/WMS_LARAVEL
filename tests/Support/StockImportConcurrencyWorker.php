<?php

use App\Models\StockImport;
use App\Models\User;
use App\Services\Stock\StockExcelImportService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $importId, $userId, $readyPath, $resultPath] = $argv;

file_put_contents($readyPath, 'ready');

try {
    $stockImport = StockImport::query()->findOrFail((int) $importId);
    $user = User::query()->findOrFail((int) $userId);
    $result = app(StockExcelImportService::class)->confirm($stockImport, $user, true);
    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'status' => 'imported',
        'imported_rows' => $result['imported_rows'],
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'status' => 'rejected',
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
}
