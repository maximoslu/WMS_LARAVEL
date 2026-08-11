<?php

namespace Tests\Integration;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\StockPallet;
use App\Models\User;
use App\Services\GoodsReceipts\GoodsReceiptConfirmationService;
use App\Services\Stock\StockBatchIdentityService;
use App\Support\Stock\LotNormalizer;
use App\Support\Stock\StockBatchIdentity;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class StockBatchIdentityConcurrencyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        if (env('WMS_CONCURRENCY_DB') !== '1' || DB::getDriverName() !== 'mysql') {
            $this->markTestSkipped('Run with tests/Support/run-stock-concurrency-mysql.php against an isolated local MySQL/MariaDB database.');
        }
    }

    public function test_two_connections_create_one_unlocated_batch_and_preserve_both_receipts(): void
    {
        [$user, $client, $item] = $this->fixture();
        $first = $this->receipt($user, $client, $item, 'LOT-CONCURRENT', 4300, 100);
        $second = $this->receipt($user, $client, $item, 'LOT-CONCURRENT', 500, 100);
        $identity = $this->identity($client, $item, 'LOT-CONCURRENT');

        $worker = $this->beginBlockedWorker($identity, $second, $user);
        $this->confirmInsideHeldTransaction($first, $user);
        $this->finishWorker($worker);

        $stocks = app(StockBatchIdentityService::class)->query($identity)->get();
        $this->assertCount(1, $stocks);
        $this->assertSame(4800, (int) $stocks->first()->quantity_units);
        $this->assertSame('48.00', (string) $stocks->first()->warehouse_pallets);
        $this->assertSame(2, InventoryMovement::query()
            ->where('movement_type', InventoryMovement::RECEIPT)
            ->whereIn('source_id', [$first->id, $second->id])
            ->count());
        $this->assertSame(2, InventoryMovement::query()->whereIn('source_id', [$first->id, $second->id])->distinct()->count('source_id'));
        $this->assertNotNull($first->fresh()->stock_applied_at);
        $this->assertNotNull($second->fresh()->stock_applied_at);
    }

    public function test_different_lots_do_not_block_each_other_and_create_two_batches(): void
    {
        [$user, $client, $item] = $this->fixture();
        $first = $this->receipt($user, $client, $item, 'LOT-A-'.Str::uuid(), 300, 100);
        $second = $this->receipt($user, $client, $item, 'LOT-B-'.Str::uuid(), 400, 100);
        $heldIdentity = $this->identity($client, $item, $first->lines->first()->lot);

        DB::beginTransaction();
        app(StockBatchIdentityService::class)->lockIdentities([$heldIdentity]);
        $worker = $this->startWorker($second, $user);
        $this->waitForWorker($worker);
        $this->waitForCompletionUpTo($worker, 3.0);
        $this->assertFalse($worker['process']->isRunning(), 'A different lot was serialized by an unrelated identity lock.');
        app(GoodsReceiptConfirmationService::class)->confirm($first, $user);
        DB::commit();
        $this->finishWorker($worker);

        $this->assertSame(2, StockPallet::query()->where('client_id', $client->id)->where('active', true)->count());
    }

    public function test_different_clients_are_isolated_for_the_same_lot_label(): void
    {
        [$user, $firstClient, $firstItem] = $this->fixture();
        [, $secondClient, $secondItem] = $this->fixture($user);
        $lot = 'SHARED-LABEL-'.Str::uuid();
        $first = $this->receipt($user, $firstClient, $firstItem, $lot, 300, 100);
        $second = $this->receipt($user, $secondClient, $secondItem, $lot, 400, 100);

        DB::beginTransaction();
        app(StockBatchIdentityService::class)->lockIdentities([$this->identity($firstClient, $firstItem, $lot)]);
        $worker = $this->startWorker($second, $user);
        $this->waitForWorker($worker);
        $this->waitForCompletionUpTo($worker, 3.0);
        $this->assertFalse($worker['process']->isRunning(), 'A different client was serialized by an unrelated identity lock.');
        app(GoodsReceiptConfirmationService::class)->confirm($first, $user);
        DB::commit();
        $this->finishWorker($worker);

        $this->assertSame(1, StockPallet::query()->where('client_id', $firstClient->id)->where('active', true)->count());
        $this->assertSame(1, StockPallet::query()->where('client_id', $secondClient->id)->where('active', true)->count());
    }

    public function test_two_connections_increment_an_existing_batch_without_lost_update(): void
    {
        [$user, $client, $item] = $this->fixture();
        $lot = 'EXISTING-'.Str::uuid();
        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => null,
            'location_text' => null,
            'lot' => $lot,
            'quantity_units' => 4300,
            'units_per_pallet' => 100,
            'warehouse_pallets' => 43,
            'status' => StockPallet::STATUS_AVAILABLE,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'active' => true,
        ]);
        $first = $this->receipt($user, $client, $item, $lot, 500, 100);
        $second = $this->receipt($user, $client, $item, $lot, 200, 100);
        $identity = $this->identity($client, $item, $lot);

        $worker = $this->beginBlockedWorker($identity, $second, $user);
        $this->confirmInsideHeldTransaction($first, $user);
        $this->finishWorker($worker);

        $stock = app(StockBatchIdentityService::class)->query($identity)->sole();
        $this->assertSame(5000, (int) $stock->quantity_units);
        $this->assertSame('50.00', (string) $stock->warehouse_pallets);
        $this->assertSame(2, InventoryMovement::query()
            ->where('movement_type', InventoryMovement::RECEIPT)
            ->whereIn('source_id', [$first->id, $second->id])
            ->count());
    }

    public function test_no_lot_aliases_use_one_concurrent_identity(): void
    {
        [$user, $client, $item] = $this->fixture();
        $first = $this->receipt($user, $client, $item, null, 300, 100);
        $second = $this->receipt($user, $client, $item, ' sin   lote ', 200, 100);
        $identity = $this->identity($client, $item, LotNormalizer::NO_LOT);

        $worker = $this->beginBlockedWorker($identity, $second, $user);
        $this->confirmInsideHeldTransaction($first, $user);
        $this->finishWorker($worker);

        $stock = app(StockBatchIdentityService::class)->query($identity)->sole();
        $this->assertSame(LotNormalizer::NO_LOT, $stock->lot);
        $this->assertSame(500, (int) $stock->quantity_units);
    }

    public function test_rollback_releases_identity_for_another_connection(): void
    {
        [$user, $client, $item] = $this->fixture();
        $receipt = $this->receipt($user, $client, $item, 'ROLLBACK-'.Str::uuid(), 300, 100);
        $identity = $this->identity($client, $item, $receipt->lines->first()->lot);

        DB::beginTransaction();
        app(StockBatchIdentityService::class)->lockIdentities([$identity]);
        $worker = $this->startWorker($receipt, $user);
        $this->waitForWorker($worker);
        usleep(300_000);
        $this->assertTrue($worker['process']->isRunning(), 'The second connection did not wait for the uncommitted identity.');
        DB::rollBack();
        $this->finishWorker($worker);

        $this->assertCount(1, app(StockBatchIdentityService::class)->query($identity)->get());
        $this->assertNotNull($receipt->fresh()->stock_applied_at);
    }

    /** @return array{User, Client, Item} */
    private function fixture(?User $user = null): array
    {
        $user ??= User::query()->create([
            'name' => 'Concurrency Test User',
            'email' => Str::uuid().'@example.test',
            'password' => Hash::make('test-password'),
            'active' => true,
        ]);
        $client = Client::query()->create([
            'name' => 'Concurrency Client '.Str::uuid(),
            'code' => 'CONC-'.Str::uuid(),
            'active' => true,
        ]);
        $item = Item::query()->create([
            'client_id' => $client->id,
            'sku' => 'CONC-'.Str::uuid(),
            'description' => 'Concurrency test item',
            'lot_key' => '',
            'units_per_pallet' => 100,
            'active' => true,
            'status' => Item::STATUS_ACTIVE,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
        ]);

        return [$user, $client, $item];
    }

    private function receipt(User $user, Client $client, Item $item, ?string $lot, int $quantity, int $unitsPerPallet): GoodsReceipt
    {
        $receipt = GoodsReceipt::query()->create([
            'client_id' => $client->id,
            'supplier_id' => null,
            'receipt_number' => 'CONC-'.Str::uuid(),
            'created_by' => $user->id,
            'status' => GoodsReceipt::STATUS_DRAFT,
            'received_at' => now()->toDateString(),
        ]);
        GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => $lot,
            'quantity_units' => $quantity,
            'units_per_pallet' => $unitsPerPallet,
            'pallet_count' => intdiv($quantity, $unitsPerPallet),
            'pico_units' => $quantity % $unitsPerPallet,
            'location_id' => null,
        ]);

        return $receipt->fresh('lines');
    }

    private function identity(Client $client, Item $item, mixed $lot): StockBatchIdentity
    {
        return new StockBatchIdentity(
            clientId: (int) $client->id,
            itemId: (int) $item->id,
            lot: $lot,
            locationId: null,
            locationText: null,
            unitsPerPallet: 100,
            status: StockPallet::STATUS_AVAILABLE,
            stockCategory: StockPallet::CATEGORY_IN_USE,
            blockedReason: null,
        );
    }

    /** @return array{process: Process, ready: string, result: string} */
    private function beginBlockedWorker(StockBatchIdentity $identity, GoodsReceipt $receipt, User $user): array
    {
        DB::beginTransaction();
        app(StockBatchIdentityService::class)->lockIdentities([$identity]);
        $worker = $this->startWorker($receipt, $user);
        $this->waitForWorker($worker);
        usleep(300_000);
        $this->assertTrue($worker['process']->isRunning(), 'The second connection did not wait on the same identity.');

        return $worker;
    }

    private function confirmInsideHeldTransaction(GoodsReceipt $receipt, User $user): void
    {
        try {
            app(GoodsReceiptConfirmationService::class)->confirm($receipt, $user);
            DB::commit();
        } catch (\Throwable $exception) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $exception;
        }
    }

    /** @return array{process: Process, ready: string, result: string} */
    private function startWorker(GoodsReceipt $receipt, User $user): array
    {
        $token = bin2hex(random_bytes(8));
        $ready = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms-stock-ready-'.$token;
        $result = sys_get_temp_dir().DIRECTORY_SEPARATOR.'wms-stock-result-'.$token;
        $process = new Process([
            PHP_BINARY,
            base_path('tests/Support/StockConcurrencyWorker.php'),
            (string) $receipt->id,
            (string) $user->id,
            $ready,
            $result,
        ], base_path());
        $process->setTimeout(20);
        $process->start();

        return compact('process', 'ready', 'result');
    }

    /** @param array{process: Process, ready: string, result: string} $worker */
    private function waitForWorker(array $worker): void
    {
        $deadline = microtime(true) + 5;

        while (! is_file($worker['ready']) && $worker['process']->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }

        $this->assertFileExists($worker['ready'], 'The second connection did not reach the confirmation barrier.');
    }

    /** @param array{process: Process, ready: string, result: string} $worker */
    private function waitForCompletionUpTo(array $worker, float $seconds): void
    {
        $deadline = microtime(true) + $seconds;

        while ($worker['process']->isRunning() && microtime(true) < $deadline) {
            usleep(20_000);
        }
    }

    /** @param array{process: Process, ready: string, result: string} $worker */
    private function finishWorker(array $worker): void
    {
        $worker['process']->wait();
        $payload = is_file($worker['result'])
            ? json_decode((string) file_get_contents($worker['result']), true, flags: JSON_THROW_ON_ERROR)
            : null;

        @unlink($worker['ready']);
        @unlink($worker['result']);

        $this->assertSame(0, $worker['process']->getExitCode(), $worker['process']->getErrorOutput());
        $this->assertIsArray($payload);
        $this->assertTrue((bool) ($payload['ok'] ?? false), (string) ($payload['message'] ?? 'Worker failed without a result.'));
    }
}
