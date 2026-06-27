<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\StockPallet;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConsolidateStockBatchesCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_reports_candidates_without_modifying_rows(): void
    {
        [$client, $item] = $this->makeLegacyRows();

        $this->artisan('wms:consolidate-stock-batches --dry-run')
            ->expectsOutputToContain('filas=3')
            ->expectsOutputToContain('Dry-run completado')
            ->assertSuccessful();

        $this->assertSame(3, StockPallet::query()->where('client_id', $client->id)->count());
        $this->assertSame(3, StockPallet::query()->where('item_id', $item->id)->where('active', true)->count());
    }

    public function test_command_consolidates_legacy_pallet_rows_into_one_batch(): void
    {
        [, $item] = $this->makeLegacyRows();

        $this->artisan('wms:consolidate-stock-batches')
            ->expectsOutputToContain('Consolidacion completada correctamente.')
            ->assertSuccessful();

        $this->assertSame(1, StockPallet::query()->where('item_id', $item->id)->where('active', true)->count());
        $this->assertSame(2, StockPallet::query()->where('item_id', $item->id)->where('active', false)->count());

        $this->assertDatabaseHas('stock_pallets', [
            'item_id' => $item->id,
            'quantity_units' => 2500,
            'units_per_pallet' => 1000,
            'full_pallets' => 2,
            'peaks_count' => 1,
            'peak_1' => 500,
            'pallet_code' => null,
            'active' => true,
        ]);
    }

    /**
     * @return array{0: Client, 1: Item}
     */
    private function makeLegacyRows(): array
    {
        $this->seed(RoleSeeder::class);

        $client = Client::factory()->create();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-LEGACY',
            'units_per_pallet' => 1000,
        ]);
        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'supplier_id' => null,
        ]);

        foreach ([1000, 1000, 500] as $index => $quantity) {
            StockPallet::query()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'goods_receipt_id' => $receipt->id,
                'location_text' => 'A1-01',
                'pallet_code' => 'GR-1-L1-P'.($index + 1),
                'lot' => 'LOT-CF1',
                'quantity_units' => $quantity,
                'units_per_pallet' => 1000,
                'received_at' => '2026-06-26',
                'status' => StockPallet::STATUS_AVAILABLE,
                'active' => true,
            ]);
        }

        return [$client, $item];
    }
}
