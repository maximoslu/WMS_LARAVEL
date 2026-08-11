<?php

namespace Tests\Integration;

use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\StockImport;
use App\Models\StockPallet;
use App\Models\User;
use App\Services\Stock\StockExcelImportService;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StockImportSnapshotConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('WMS_IMPORT_CONCURRENCY_DB') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run with tests/Support/run-stock-import-concurrency-mysql.php against isolated local MySQL/MariaDB tables.');
        }
    }

    public function test_same_import_is_applied_exactly_once_by_two_connections(): void
    {
        [$user, $client] = $this->fixture();
        $stockImport = $this->preview($client, $user, 'ONCE');

        DB::beginTransaction();
        Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
        $worker = $this->startWorker($stockImport, $user);
        $this->waitForWorker($worker);
        $result = app(StockExcelImportService::class)->confirm($stockImport, $user);
        DB::commit();
        $workerPayload = $this->finishWorker($worker);

        $this->assertSame(1, $result['imported_rows']);
        $this->assertSame('rejected', $workerPayload['status']);
        $this->assertStringContainsString('ya fue confirmada', $workerPayload['message']);
        $this->assertSame(StockImport::STATUS_IMPORTED, $stockImport->fresh()->status);
        $this->assertSame(1, StockPallet::query()->where('client_id', $client->id)->where('active', true)->count());
        $this->assertSame(1, InventoryMovement::query()
            ->where('client_id', $client->id)
            ->where('movement_type', InventoryMovement::IMPORT)
            ->count());
    }

    public function test_two_different_snapshots_for_one_client_are_serialized_and_the_loser_becomes_stale(): void
    {
        [$user, $client] = $this->fixture();
        $firstImport = $this->preview($client, $user, 'FIRST');
        $secondImport = $this->preview($client, $user, 'SECOND');

        DB::beginTransaction();
        Client::query()->whereKey($client->id)->lockForUpdate()->firstOrFail();
        $worker = $this->startWorker($secondImport, $user);
        $this->waitForWorker($worker);
        app(StockExcelImportService::class)->confirm($firstImport, $user);
        DB::commit();
        $workerPayload = $this->finishWorker($worker);

        $this->assertSame('rejected', $workerPayload['status']);
        $this->assertStringContainsString('obsoleta', $workerPayload['message']);
        $this->assertSame(StockImport::STATUS_IMPORTED, $firstImport->fresh()->status);
        $this->assertSame(StockImport::STATUS_STALE, $secondImport->fresh()->status);
        $activeStock = StockPallet::query()->where('client_id', $client->id)->where('active', true)->sole();
        $this->assertSame('FIRST', $activeStock->item->sku);
        $this->assertSame(1, InventoryMovement::query()
            ->where('client_id', $client->id)
            ->where('movement_type', InventoryMovement::IMPORT)
            ->count());
    }

    public function test_different_clients_do_not_share_the_snapshot_lock(): void
    {
        [$user, $firstClient] = $this->fixture();
        [, $secondClient] = $this->fixture($user);
        $firstImport = $this->preview($firstClient, $user, 'CLIENT-A');
        $secondImport = $this->preview($secondClient, $user, 'CLIENT-B');

        DB::beginTransaction();
        Client::query()->whereKey($firstClient->id)->lockForUpdate()->firstOrFail();
        $worker = $this->startWorker($secondImport, $user);
        $this->waitForWorker($worker);
        $this->waitForCompletionUpTo($worker, 5.0);
        $this->assertFalse($worker['process']->isRunning(), 'A different client was blocked by an unrelated snapshot lock.');
        $workerPayload = $this->finishWorker($worker);
        app(StockExcelImportService::class)->confirm($firstImport, $user);
        DB::commit();

        $this->assertSame('imported', $workerPayload['status']);
        $this->assertSame(StockImport::STATUS_IMPORTED, $firstImport->fresh()->status);
        $this->assertSame(StockImport::STATUS_IMPORTED, $secondImport->fresh()->status);
        $this->assertSame(1, StockPallet::query()->where('client_id', $firstClient->id)->where('active', true)->count());
        $this->assertSame(1, StockPallet::query()->where('client_id', $secondClient->id)->where('active', true)->count());
    }

    /** @return array{User, Client} */
    private function fixture(?User $user = null): array
    {
        $user ??= User::query()->create([
            'name' => 'Import Concurrency User',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('test-password'),
            'active' => true,
        ]);
        $client = Client::query()->create([
            'name' => 'Import Client '.Str::uuid(),
            'code' => 'IMP-'.Str::uuid(),
            'active' => true,
        ]);

        return [$user, $client];
    }

    private function preview(Client $client, User $user, string $sku): StockImport
    {
        $path = tempnam(sys_get_temp_dir(), 'wms-import-concurrency-').'.xlsx';
        $writer = new Writer;
        $writer->openToFile($path);
        $writer->getCurrentSheet()->setName('STOCK');
        $writer->addRow(Row::fromValues(['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets']));
        $writer->addRow(Row::fromValues([$sku, 'Concurrency '.$sku, 'NO LOTE', 100, 100, 1]));
        $writer->close();
        $upload = UploadedFile::fake()->createWithContent('stock.xlsx', file_get_contents($path) ?: '');
        @unlink($path);

        return app(StockExcelImportService::class)->createPreview($client, $user, $upload)['stock_import'];
    }

    /** @return array{process:Process,ready:string,result:string} */
    private function startWorker(StockImport $stockImport, User $user): array
    {
        $token = bin2hex(random_bytes(8));
        $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms-import-ready-'.$token;
        $result = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms-import-result-'.$token;
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/StockImportConcurrencyWorker.php'),
            (string) $stockImport->id,
            (string) $user->id,
            $ready,
            $result,
        ], base_path());
        $process->setTimeout(30);
        $process->start();

        return compact('process', 'ready', 'result');
    }

    /** @param array{process:Process,ready:string,result:string} $worker */
    private function waitForWorker(array $worker): void
    {
        $deadline = microtime(true) + 5;

        while (! is_file($worker['ready']) && $worker['process']->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $this->assertFileExists($worker['ready']);
    }

    /** @param array{process:Process,ready:string,result:string} $worker */
    private function waitForCompletionUpTo(array $worker, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while ($worker['process']->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }
    }

    /**
     * @param  array{process:Process,ready:string,result:string}  $worker
     * @return array<string, mixed>
     */
    private function finishWorker(array $worker): array
    {
        $worker['process']->wait();
        $payload = is_file($worker['result'])
            ? json_decode((string) file_get_contents($worker['result']), true, flags: JSON_THROW_ON_ERROR)
            : null;
        @unlink($worker['ready']);
        @unlink($worker['result']);

        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false));

        return $payload;
    }
}
