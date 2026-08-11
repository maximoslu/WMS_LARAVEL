<?php

use App\Exceptions\NotificationDeliveryInProgressException;
use App\Services\Notifications\NotificationDeliveryService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[$script, $workerToken, $startPath, $readyPath, $resultPath] = $argv;

file_put_contents($readyPath, 'ready');
$deadline = microtime(true) + 5;

while (! is_file($startPath) && microtime(true) < $deadline) {
    usleep(10_000);
}

try {
    app(NotificationDeliveryService::class)->deliver(
        'integration.concurrent',
        'integration_fixture',
        1,
        'v1',
        'mail',
        'same@example.test',
        function () use ($workerToken): ?string {
            DB::table('notification_delivery_test_sends')->insert([
                'worker_token' => $workerToken,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            usleep(400_000);

            return 'provider-'.$workerToken;
        },
    );
    $payload = ['ok' => true, 'outcome' => 'sent'];
} catch (NotificationDeliveryInProgressException) {
    $payload = ['ok' => true, 'outcome' => 'in_progress'];
} catch (Throwable $exception) {
    $payload = [
        'ok' => false,
        'outcome' => 'failed',
        'type' => $exception::class,
        'message' => $exception->getMessage(),
    ];
}

file_put_contents($resultPath, json_encode($payload, JSON_THROW_ON_ERROR));
exit($payload['ok'] ? 0 : 1);
