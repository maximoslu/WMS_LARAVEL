<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\StockPallet;
use App\Services\Stock\StockBatchIdentityService;
use App\Support\Stock\StockBatchIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockBatchIdentityServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_coordination_migration_is_reversible_without_touching_stock(): void
    {
        $migration = require database_path('migrations/2026_08_11_000001_create_stock_batch_identity_locks_table.php');

        $this->assertTrue(Schema::hasTable('stock_batch_identity_locks'));
        $migration->down();
        $this->assertFalse(Schema::hasTable('stock_batch_identity_locks'));
        $migration->up();
        $this->assertTrue(Schema::hasTable('stock_batch_identity_locks'));
    }

    public function test_same_identity_uses_one_coordination_row_and_different_identity_uses_another(): void
    {
        $first = $this->identity('LOT-A');
        $second = $this->identity('LOT-B');

        DB::transaction(function () use ($first, $second): void {
            $service = app(StockBatchIdentityService::class);
            $service->lockIdentities([$first]);
            $service->lockIdentities([$first]);
            $service->lockIdentities([$second]);
        });

        $this->assertSame(2, DB::table('stock_batch_identity_locks')->count());
        $this->assertSame(1, DB::table('stock_batch_identity_locks')->where('identity_hash', $first->hash())->count());
    }

    public function test_lot_lookup_matches_database_equivalent_case_without_changing_stored_display(): void
    {
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'Lot-Mixto-01',
            'location_id' => null,
            'location_text' => null,
            'units_per_pallet' => 100,
            'status' => StockPallet::STATUS_AVAILABLE,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'active' => true,
        ]);
        $identity = new StockBatchIdentity(
            clientId: (int) $client->id,
            itemId: (int) $item->id,
            lot: 'LOT-MIXTO-01',
            locationId: null,
            locationText: null,
            unitsPerPallet: 100,
            status: StockPallet::STATUS_AVAILABLE,
            stockCategory: StockPallet::CATEGORY_IN_USE,
            blockedReason: null,
        );

        $matches = DB::transaction(fn () => app(StockBatchIdentityService::class)->lockAndGet($identity));

        $this->assertCount(1, $matches);
        $this->assertSame($stock->id, $matches->first()->id);
        $this->assertSame('Lot-Mixto-01', $matches->first()->lot);
    }

    private function identity(string $lot): StockBatchIdentity
    {
        return new StockBatchIdentity(
            clientId: 1,
            itemId: 1,
            lot: $lot,
            locationId: null,
            locationText: null,
            unitsPerPallet: 100,
            status: StockPallet::STATUS_AVAILABLE,
            stockCategory: StockPallet::CATEGORY_IN_USE,
            blockedReason: null,
        );
    }
}
