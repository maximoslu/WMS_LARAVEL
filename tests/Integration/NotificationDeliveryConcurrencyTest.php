<?php

namespace Tests\Integration;

use App\Models\NotificationDelivery;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class NotificationDeliveryConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('WMS_NOTIFICATION_CONCURRENCY_DB') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run with tests/Support/run-notification-concurrency-mysql.php against an isolated local MySQL/MariaDB database.');
        }

        if (! Schema::hasTable('notification_delivery_test_sends')) {
            Schema::create('notification_delivery_test_sends', function (Blueprint $table): void {
                $table->id();
                $table->string('worker_token', 100);
                $table->timestamps();
            });
        }
    }

    public function test_two_workers_make_one_provider_call_for_the_same_identity(): void
    {
        $token = (string) Str::uuid();
        $start = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms-notification-start-'.$token;
        $workers = [
            $this->startWorker($token.'-a', $start),
            $this->startWorker($token.'-b', $start),
        ];

        try {
            foreach ($workers as $worker) {
                $this->waitUntilReady($worker);
            }

            file_put_contents($start, 'start');

            $results = array_map(fn (array $worker): array => $this->finishWorker($worker), $workers);

            $this->assertSame(1, DB::table('notification_delivery_test_sends')->count());
            $this->assertSame(1, NotificationDelivery::query()->count());
            $delivery = NotificationDelivery::query()->sole();
            $this->assertSame(NotificationDelivery::STATUS_SENT, $delivery->status);
            $this->assertSame(1, $delivery->attempts);
            $this->assertContains('sent', array_column($results, 'outcome'));
            $this->assertContains('in_progress', array_column($results, 'outcome'));
        } finally {
            @unlink($start);

            foreach ($workers as $worker) {
                @unlink($worker['ready']);
                @unlink($worker['result']);
            }
        }
    }

    /** @return array{process: Process, ready: string, result: string} */
    private function startWorker(string $workerToken, string $start): array
    {
        $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms-notification-ready-'.$workerToken;
        $result = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms-notification-result-'.$workerToken;
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/NotificationDeliveryConcurrencyWorker.php'),
            $workerToken,
            $start,
            $ready,
            $result,
        ], base_path());
        $process->setTimeout(20);
        $process->start();

        return compact('process', 'ready', 'result');
    }

    /** @param array{process: Process, ready: string, result: string} $worker */
    private function waitUntilReady(array $worker): void
    {
        $deadline = microtime(true) + 5;

        while (! is_file($worker['ready']) && $worker['process']->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $this->assertFileExists($worker['ready']);
    }

    /**
     * @param  array{process: Process, ready: string, result: string}  $worker
     * @return array{ok: bool, outcome: string, message?: string}
     */
    private function finishWorker(array $worker): array
    {
        $worker['process']->wait();
        $payload = is_file($worker['result'])
            ? json_decode((string) file_get_contents($worker['result']), true, flags: JSON_THROW_ON_ERROR)
            : null;

        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false), (string) ($payload['message'] ?? 'Worker failed without a result.'));

        return $payload;
    }
}
