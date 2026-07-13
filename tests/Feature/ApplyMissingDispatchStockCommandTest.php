<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use App\Models\StockPallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ApplyMissingDispatchStockCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_lists_missing_dispatch_without_changing_stock(): void
    {
        [$dispatch, $stock] = $this->missingStockDispatch();

        $this->artisan('wms:dispatches:apply-missing-stock --dry-run')
            ->expectsOutputToContain($dispatch->dispatchNumber())
            ->expectsOutputToContain('No se ha modificado ningun dato')
            ->assertSuccessful();

        $this->assertSame(500, $stock->fresh()->quantity_units);
        $this->assertNull($dispatch->fresh()->stock_applied_at);
    }

    public function test_explicit_dispatch_applies_missing_stock_once(): void
    {
        [$dispatch, $stock] = $this->missingStockDispatch();

        $this->artisan("wms:dispatches:apply-missing-stock --dispatch={$dispatch->id}")
            ->expectsOutputToContain('Stock de unidades y pallets almacen aplicado')
            ->assertSuccessful();

        $this->assertSame(300, $stock->fresh()->quantity_units);
        $this->assertSame(3.0, (float) $stock->fresh()->warehouse_pallets);
        $this->assertNotNull($dispatch->fresh()->stock_applied_at);
        $this->assertNotNull($dispatch->fresh()->warehouse_stock_applied_at);

        $this->artisan("wms:dispatches:apply-missing-stock --dispatch={$dispatch->id}")
            ->expectsOutputToContain('Las unidades ya estaban aplicadas')
            ->assertSuccessful();

        $this->assertSame(300, $stock->fresh()->quantity_units);
        $this->assertSame(3.0, (float) $stock->fresh()->warehouse_pallets);
    }

    public function test_legacy_warehouse_repair_does_not_deduct_units_twice(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES']);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 100,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 100,
            'quantity_units' => 101600,
            'full_pallets' => 1016,
            'warehouse_pallets' => 1026,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
            'stock_applied_at' => now(),
            'warehouse_stock_applied_at' => null,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 100,
            'requested_pallets' => 8,
            'loaded_pallets' => 10,
            'confirmed_at' => now(),
        ]);

        $this->artisan("wms:dispatches:apply-missing-stock --dispatch={$dispatch->id} --repair-warehouse")
            ->expectsOutputToContain('Pallets almacen reparados sin volver a descontar unidades')
            ->assertSuccessful();

        $this->assertSame(101600, $stock->fresh()->quantity_units);
        $this->assertSame(1016.0, (float) $stock->fresh()->warehouse_pallets);
        $this->assertNotNull($dispatch->fresh()->warehouse_stock_applied_at);

        $this->artisan("wms:dispatches:apply-missing-stock --dispatch={$dispatch->id} --repair-warehouse")
            ->expectsOutputToContain('ya estaba aplicada')
            ->assertSuccessful();

        $this->assertSame(1016.0, (float) $stock->fresh()->warehouse_pallets);
    }

    /** @return array{GoodsDispatch, StockPallet} */
    private function missingStockDispatch(): array
    {
        $client = Client::factory()->create();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 100,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 100,
            'quantity_units' => 500,
            'warehouse_pallets' => 5,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_SENT,
            'sent_at' => now(),
            'stock_applied_at' => null,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 100,
            'requested_pallets' => 1,
            'loaded_pallets' => 2,
            'confirmed_at' => now(),
        ]);

        return [$dispatch, $stock];
    }
}
