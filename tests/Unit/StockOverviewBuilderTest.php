<?php

namespace Tests\Unit;

use App\Models\Client;
use App\Models\Item;
use App\Models\StockPallet;
use App\Support\Stock\StockOverviewBuilder;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StockOverviewBuilderTest extends TestCase
{
    use RefreshDatabase;

    public function test_builder_calculates_total_units_full_pallets_peaks_and_total_pallets(): void
    {
        [$client] = $this->seedClients();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'FR-700',
            'description' => 'Palet estandar',
            'units_per_pallet' => 700,
        ]);

        foreach ([700, 700, 300] as $index => $quantity) {
            StockPallet::query()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'location_text' => 'A1-0'.($index + 1),
                'pallet_code' => 'PAL-FR-00'.($index + 1),
                'quantity_units' => $quantity,
                'active' => true,
            ]);
        }

        $result = app(StockOverviewBuilder::class)->build([
            'stock_state' => 'all',
        ]);

        $row = $result['rows']->firstWhere('sku', 'FR-700');

        $this->assertNotNull($row);
        $this->assertSame(1700, $row['total_units']);
        $this->assertSame(2, $row['full_pallets']);
        $this->assertSame(1, $row['pico_count']);
        $this->assertSame(3, $row['total_pallets']);
        $this->assertSame([300], $row['pico_quantities']);
    }

    public function test_builder_separates_same_sku_across_clients(): void
    {
        [$friesland, $edelvives] = $this->seedClients();

        $frItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'SKU-COMUN',
            'description' => 'Producto FR',
            'units_per_pallet' => 700,
        ]);

        $edItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-COMUN',
            'description' => 'Producto ED',
            'units_per_pallet' => 420,
        ]);

        StockPallet::query()->create([
            'client_id' => $friesland->id,
            'item_id' => $frItem->id,
            'location_text' => 'A1-01',
            'pallet_code' => 'PAL-COMUN-FR',
            'quantity_units' => 700,
            'active' => true,
        ]);

        StockPallet::query()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'location_text' => 'B1-01',
            'pallet_code' => 'PAL-COMUN-ED',
            'quantity_units' => 180,
            'active' => true,
        ]);

        $result = app(StockOverviewBuilder::class)->build([
            'stock_state' => 'all',
        ]);

        $frRow = $result['rows']->firstWhere('client_code', 'FRIESLAND');
        $edRow = $result['rows']->firstWhere('client_code', 'EDELVIVES');

        $this->assertNotNull($frRow);
        $this->assertNotNull($edRow);
        $this->assertSame('SKU-COMUN', $frRow['sku']);
        $this->assertSame('SKU-COMUN', $edRow['sku']);
        $this->assertSame(700, $frRow['total_units']);
        $this->assertSame(180, $edRow['total_units']);
    }

    public function test_builder_keeps_same_sku_split_by_lot(): void
    {
        [$client] = $this->seedClients();

        $lotA = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-LOTE',
            'description' => 'Lote A',
            'lot' => 'LOT-A',
            'lot_key' => 'LOT-A',
            'units_per_pallet' => 500,
        ]);

        $lotB = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-LOTE',
            'description' => 'Lote B',
            'lot' => 'LOT-B',
            'lot_key' => 'LOT-B',
            'units_per_pallet' => 500,
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $lotA->id,
            'location_text' => 'A3-01',
            'pallet_code' => 'PAL-LOTE-A',
            'quantity_units' => 500,
            'active' => true,
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $lotB->id,
            'location_text' => 'A3-02',
            'pallet_code' => 'PAL-LOTE-B',
            'quantity_units' => 300,
            'active' => true,
        ]);

        $result = app(StockOverviewBuilder::class)->build([
            'stock_state' => 'all',
        ]);

        $rows = $result['rows']->where('sku', 'SKU-LOTE')->values();

        $this->assertCount(2, $rows);
        $this->assertSame(['LOT-A', 'LOT-B'], $rows->pluck('lot')->all());
    }

    /**
     * @return array{0: Client, 1: Client}
     */
    private function seedClients(): array
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);

        return [
            Client::query()->where('code', 'FRIESLAND')->firstOrFail(),
            Client::query()->where('code', 'EDELVIVES')->firstOrFail(),
        ];
    }
}
