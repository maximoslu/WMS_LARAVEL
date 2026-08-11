<?php

use App\Models\GoodsReceipt;
use App\Models\User;
use App\Services\GoodsReceipts\GoodsReceiptConfirmationService;
use Illuminate\Contracts\Console\Kernel;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $receiptId, $userId, $readyPath, $resultPath] = $argv;

file_put_contents($readyPath, 'ready');

try {
    $receipt = GoodsReceipt::query()->findOrFail((int) $receiptId);
    $user = User::query()->findOrFail((int) $userId);
    app(GoodsReceiptConfirmationService::class)->confirm($receipt, $user);
    file_put_contents($resultPath, json_encode(['ok' => true], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
