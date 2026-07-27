<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockPallet;
use App\Models\Warehouse;
use App\Support\Stock\LotNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class LotCanonicalizationCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_dry_run_detects_variants_without_modifying_data(): void
    {
        [$client, $item, $location] = $this->stockContext();
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'lot' => 'Sin Lote',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
        ]);
        DB::table('stock_pallets')->where('id', $stock->id)->update(['lot' => 'Sin Lote']);

        $this->artisan('wms:lots:canonicalize --dry-run')
            ->expectsOutputToContain('DRY-RUN wms:lots:canonicalize')
            ->expectsOutputToContain('stock_pallets')
            ->expectsOutputToContain('Sin Lote: 1')
            ->assertSuccessful();

        $this->assertSame('Sin Lote', DB::table('stock_pallets')->where('id', $stock->id)->value('lot'));
    }

    public function test_apply_normalizes_simple_aliases(): void
    {
        [$client, $item, $location] = $this->stockContext();
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'lot' => 'SIN LOTE',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
        ]);
        DB::table('stock_pallets')->where('id', $stock->id)->update(['lot' => 'SIN LOTE']);

        $receipt = GoodsReceipt::factory()->create(['client_id' => $client->id]);
        $line = GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'lot' => ' sin   lote ',
        ]);
        DB::table('goods_receipt_lines')->where('id', $line->id)->update(['lot' => ' sin   lote ']);

        $this->artisan('wms:lots:canonicalize --apply')->assertSuccessful();

        $this->assertSame(LotNormalizer::NO_LOT, DB::table('stock_pallets')->where('id', $stock->id)->value('lot'));
        $this->assertSame(LotNormalizer::NO_LOT, DB::table('goods_receipt_lines')->where('id', $line->id)->value('lot'));
    }

    public function test_apply_detects_spaced_no_lot_aliases_and_preserves_real_lots(): void
    {
        [$client, $item, $location] = $this->stockContext();

        $spaced = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'lot' => LotNormalizer::NO_LOT,
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
        ]);
        DB::table('stock_pallets')->where('id', $spaced->id)->update(['lot' => ' NO LOTE ']);

        $collapsed = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 1000])->id,
            'location_id' => $location->id,
            'lot' => LotNormalizer::NO_LOT,
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
        ]);
        DB::table('stock_pallets')->where('id', $collapsed->id)->update(['lot' => 'NO   LOTE']);

        foreach (['NO LOTE', 'SIN-LOTE-2026', 'NO LOTE 2', 'LOTE SIN MARCAR'] as $lot) {
            StockPallet::factory()->create([
                'client_id' => $client->id,
                'item_id' => Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 1000])->id,
                'location_id' => $location->id,
                'lot' => $lot,
                'quantity_units' => 1000,
                'units_per_pallet' => 1000,
            ]);
        }

        $this->artisan('wms:lots:canonicalize --client='.$client->id.' --dry-run')
            ->expectsOutputToContain('stock_pallets | escaneados: 6 | a normalizar: 2')
            ->expectsOutputToContain(' NO LOTE : 1')
            ->expectsOutputToContain('NO   LOTE: 1')
            ->assertSuccessful();

        $this->assertSame(' NO LOTE ', DB::table('stock_pallets')->where('id', $spaced->id)->value('lot'));
        $this->assertSame('NO   LOTE', DB::table('stock_pallets')->where('id', $collapsed->id)->value('lot'));

        $this->artisan('wms:lots:canonicalize --client='.$client->id.' --apply')
            ->expectsOutputToContain('Registros cambiados: 2')
            ->assertSuccessful();

        $this->assertSame(LotNormalizer::NO_LOT, DB::table('stock_pallets')->where('id', $spaced->id)->value('lot'));
        $this->assertSame(LotNormalizer::NO_LOT, DB::table('stock_pallets')->where('id', $collapsed->id)->value('lot'));
        $this->assertSame('SIN-LOTE-2026', DB::table('stock_pallets')->where('lot', 'SIN-LOTE-2026')->value('lot'));
        $this->assertSame('NO LOTE 2', DB::table('stock_pallets')->where('lot', 'NO LOTE 2')->value('lot'));
        $this->assertSame('LOTE SIN MARCAR', DB::table('stock_pallets')->where('lot', 'LOTE SIN MARCAR')->value('lot'));
    }

    public function test_apply_consolidates_only_compatible_stock_batches(): void
    {
        [$client, $item, $location] = $this->stockContext();

        $first = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'location_text' => $location->code,
            'lot' => 'NO LOTE',
            'quantity_units' => 1500,
            'units_per_pallet' => 1000,
            'peak_1' => 500,
            'status' => StockPallet::STATUS_AVAILABLE,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'active' => true,
        ]);
        $second = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'location_text' => $location->code,
            'lot' => 'Sin Lote',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
            'status' => StockPallet::STATUS_AVAILABLE,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'active' => true,
        ]);
        DB::table('stock_pallets')->where('id', $second->id)->update(['lot' => 'Sin Lote']);

        $this->artisan('wms:lots:canonicalize --apply')->assertSuccessful();

        $this->assertDatabaseMissing('stock_pallets', ['id' => $second->id]);
        $this->assertDatabaseHas('stock_pallets', [
            'id' => $first->id,
            'lot' => LotNormalizer::NO_LOT,
            'quantity_units' => 2500,
            'location_id' => $location->id,
        ]);
    }

    public function test_apply_does_not_consolidate_different_locations(): void
    {
        [$client, $item, $location] = $this->stockContext();
        $otherLocation = Location::factory()->create(['warehouse_id' => $location->warehouse_id, 'code' => 'B2']);

        $first = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'lot' => 'NO LOTE',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
        ]);
        $second = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $otherLocation->id,
            'lot' => 'Sin Lote',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
        ]);
        DB::table('stock_pallets')->where('id', $second->id)->update(['lot' => 'Sin Lote']);

        $this->artisan('wms:lots:canonicalize --apply')->assertSuccessful();

        $this->assertDatabaseHas('stock_pallets', ['id' => $first->id, 'lot' => LotNormalizer::NO_LOT]);
        $this->assertDatabaseHas('stock_pallets', ['id' => $second->id, 'lot' => LotNormalizer::NO_LOT]);
    }

    public function test_apply_normalizes_but_does_not_consolidate_ambiguous_null_location_stock(): void
    {
        [$client, $item] = $this->stockContext();

        $canonical = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => null,
            'location_text' => 'A1',
            'lot' => LotNormalizer::NO_LOT,
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
            'full_pallets' => 1,
            'warehouse_pallets' => 1.0,
            'status' => StockPallet::STATUS_AVAILABLE,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'active' => true,
        ]);
        $alias = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => null,
            'location_text' => 'A1',
            'lot' => 'Sin Lote',
            'quantity_units' => 500,
            'units_per_pallet' => 1000,
            'full_pallets' => 0,
            'warehouse_pallets' => 1.0,
            'peaks_count' => 1,
            'peak_1' => 500,
            'status' => StockPallet::STATUS_AVAILABLE,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'active' => true,
        ]);
        DB::table('stock_pallets')->where('id', $alias->id)->update(['lot' => 'Sin Lote']);

        DB::table('inventory_movements')->insert([
            'uuid' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'client_id' => $client->id,
            'item_id' => $item->id,
            'sku' => $item->sku,
            'lot' => 'Sin Lote',
            'stock_pallet_id' => $alias->id,
            'movement_type' => 'test',
            'units_delta' => 500,
            'effective_at' => now(),
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $this->artisan('wms:lots:canonicalize --client='.$client->id.' --table=stock_pallets --dry-run')
            ->expectsOutputToContain('grupos con colision: 1')
            ->expectsOutputToContain('ubicacion/almacen ambiguo sin location_id')
            ->expectsOutputToContain('Dry-run: no se ha modificado ningun dato')
            ->assertSuccessful();

        $this->assertSame('Sin Lote', DB::table('stock_pallets')->where('id', $alias->id)->value('lot'));
        $this->assertDatabaseHas('inventory_movements', ['stock_pallet_id' => $alias->id]);

        $this->artisan('wms:lots:canonicalize --client='.$client->id.' --table=stock_pallets --apply')
            ->expectsOutputToContain('Registros cambiados: 1')
            ->expectsOutputToContain('Registros eliminados por consolidacion: 0')
            ->expectsOutputToContain('Conflictos pendientes de revision manual: 1')
            ->assertSuccessful();

        $this->assertDatabaseHas('stock_pallets', [
            'id' => $canonical->id,
            'lot' => LotNormalizer::NO_LOT,
            'quantity_units' => 1000,
        ]);
        $this->assertDatabaseHas('stock_pallets', [
            'id' => $alias->id,
            'lot' => LotNormalizer::NO_LOT,
            'quantity_units' => 500,
        ]);
        $this->assertDatabaseHas('inventory_movements', ['stock_pallet_id' => $alias->id]);

        $this->artisan('wms:lots:canonicalize --client='.$client->id.' --table=stock_pallets --apply')
            ->expectsOutputToContain('Registros cambiados: 0')
            ->expectsOutputToContain('Registros eliminados por consolidacion: 0')
            ->assertSuccessful();
    }

    public function test_apply_preserves_totals_peaks_and_reassigns_historical_movements(): void
    {
        [$client, $item, $location] = $this->stockContext();

        $alias = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'location_text' => $location->code,
            'lot' => 'Sin Lote',
            'quantity_units' => 1500,
            'units_per_pallet' => 1000,
            'full_pallets' => 1,
            'warehouse_pallets' => 1.5,
            'peaks_count' => 1,
            'peak_1' => 500,
            'status' => StockPallet::STATUS_AVAILABLE,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'active' => true,
        ]);
        DB::table('stock_pallets')->where('id', $alias->id)->update(['lot' => 'Sin Lote']);

        $canonical = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'location_text' => $location->code,
            'lot' => LotNormalizer::NO_LOT,
            'quantity_units' => 2000,
            'units_per_pallet' => 1000,
            'full_pallets' => 2,
            'warehouse_pallets' => 2.0,
            'peaks_count' => 0,
            'peak_1' => 0,
            'status' => StockPallet::STATUS_AVAILABLE,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'active' => true,
        ]);

        DB::table('inventory_movements')->insert([
            'uuid' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => (string) Str::uuid(),
            'client_id' => $client->id,
            'item_id' => $item->id,
            'sku' => $item->sku,
            'lot' => 'Sin Lote',
            'stock_pallet_id' => $alias->id,
            'movement_type' => 'test',
            'units_delta' => 1500,
            'effective_at' => now(),
            'recorded_at' => now(),
            'created_at' => now(),
        ]);

        $this->artisan('wms:lots:canonicalize --apply')
            ->expectsOutputToContain('Unidades antes/despues: 3500 / 3500')
            ->expectsOutputToContain('Pallets completos antes/despues: 3 / 3')
            ->expectsOutputToContain('Picos antes/despues: 1 / 1')
            ->assertSuccessful();

        $this->assertDatabaseMissing('stock_pallets', ['id' => $alias->id]);
        $this->assertDatabaseHas('stock_pallets', [
            'id' => $canonical->id,
            'lot' => LotNormalizer::NO_LOT,
            'quantity_units' => 3500,
            'full_pallets' => 3,
            'warehouse_pallets' => 3.5,
            'peaks_count' => 1,
            'peak_1' => 500,
        ]);
        $this->assertDatabaseHas('inventory_movements', [
            'stock_pallet_id' => $canonical->id,
            'lot' => LotNormalizer::NO_LOT,
        ]);
    }

    public function test_client_filter_limits_apply_and_second_run_is_idempotent(): void
    {
        [$client, $item, $location] = $this->stockContext();
        [$otherClient, $otherItem, $otherLocation] = $this->stockContext();

        $targetStock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'lot' => 'Sin Lote',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
        ]);
        $otherStock = StockPallet::factory()->create([
            'client_id' => $otherClient->id,
            'item_id' => $otherItem->id,
            'location_id' => $otherLocation->id,
            'lot' => 'Sin Lote',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
        ]);
        DB::table('stock_pallets')->where('id', $targetStock->id)->update(['lot' => 'Sin Lote']);
        DB::table('stock_pallets')->where('id', $otherStock->id)->update(['lot' => 'Sin Lote']);

        $this->artisan('wms:lots:canonicalize --client='.$client->id.' --apply')
            ->expectsOutputToContain('Registros cambiados: 1')
            ->assertSuccessful();

        $this->assertSame(LotNormalizer::NO_LOT, DB::table('stock_pallets')->where('id', $targetStock->id)->value('lot'));
        $this->assertSame('Sin Lote', DB::table('stock_pallets')->where('id', $otherStock->id)->value('lot'));

        $this->artisan('wms:lots:canonicalize --client='.$client->id.' --apply')
            ->expectsOutputToContain('Registros cambiados: 0')
            ->assertSuccessful();
    }

    public function test_normalizes_lot_attribute_presents_raw_null_without_dirtying_model(): void
    {
        [$client, $item, $location] = $this->stockContext();
        $receipt = GoodsReceipt::factory()->create(['client_id' => $client->id]);
        $line = GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'lot' => LotNormalizer::NO_LOT,
        ]);
        DB::table('goods_receipt_lines')->where('id', $line->id)->update(['lot' => null]);

        $line = GoodsReceiptLine::query()->findOrFail($line->id);

        $this->assertNull($line->getRawOriginal('lot'));
        $this->assertSame(LotNormalizer::NO_LOT, $line->lot);
        $this->assertFalse($line->isDirty('lot'));
        $this->assertNull(DB::table('goods_receipt_lines')->where('id', $line->id)->value('lot'));

        $line->lot = $line->lot;
        $line->save();

        $this->assertSame(LotNormalizer::NO_LOT, DB::table('goods_receipt_lines')->where('id', $line->id)->value('lot'));
    }

    /**
     * @return array{0:Client, 1:Item, 2:Location}
     */
    private function stockContext(): array
    {
        $client = Client::factory()->create();
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id]);
        $location = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => 'A1']);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 1000,
        ]);

        return [$client, $item, $location];
    }
}
