<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Support\Stock\StockOverviewBuilder;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

class GoodsDispatchNegativeStockTest extends TestCase
{
    use RefreshDatabase;

    public function test_sending_more_pallets_than_selected_stock_records_a_negative_balance(): void
    {
        [$client, $user, $item] = $this->context();
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-ONE',
            'units_per_pallet' => 100,
            'quantity_units' => 100,
            'warehouse_pallets' => 1,
            'peak_1' => 0,
        ]);
        $dispatch = $this->dispatch($client, $item, $user, 3, $stock);

        $this->actingAs($user)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertRedirect(route('dispatches.show', $dispatch))
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(GoodsDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertSame(0, $stock->fresh()->quantity_units);
        $debt = StockPallet::query()->where('item_id', $item->id)->where('quantity_units', '<', 0)->sole();
        $this->assertSame(-200, $debt->quantity_units);
        $this->assertSame(-2.0, (float) $debt->warehouse_pallets);
        $this->assertSame('LOT-ONE', $debt->lot);
        $this->assertSame(-300, (int) InventoryMovement::query()->where('source_id', $dispatch->id)
            ->where('movement_type', InventoryMovement::DISPATCH)->sum('units_delta'));
        $this->assertTrue((bool) InventoryMovement::query()->where('stock_pallet_id', $debt->id)
            ->where('movement_type', InventoryMovement::DISPATCH)->firstOrFail()->metadata['stock_deficit']);
        $overview = app(StockOverviewBuilder::class)->build($user, ['client_id' => $client->id, 'search' => $item->sku]);
        $this->assertSame(-200, $overview['summary']['total_units']);
        $this->assertTrue($overview['rows']->first()['has_deficit']);
    }

    public function test_selected_allocation_can_send_with_a_shortfall_without_changing_another_lot(): void
    {
        [$client, $user, $item] = $this->context();
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-ONE',
            'units_per_pallet' => 100,
            'quantity_units' => 100,
            'peak_1' => 0,
        ]);
        $otherLot = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-TWO',
            'units_per_pallet' => 100,
            'quantity_units' => 500,
            'peak_1' => 0,
        ]);
        $dispatch = $this->dispatch($client, $item, $user, 2, $stock);
        $dispatch->lines()->firstOrFail()->allocations()->create([
            'stock_pallet_id' => $stock->id,
            'lot' => $stock->lot,
            'units_per_pallet' => 100,
            'loaded_pallets' => 2,
            'loaded_partial_units' => 0,
            'selected_peaks' => [],
        ]);

        $this->actingAs($user)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(0, $stock->fresh()->quantity_units);
        $this->assertSame(500, $otherLot->fresh()->quantity_units);
        $this->assertSame(-100, StockPallet::query()->where('item_id', $item->id)
            ->where('quantity_units', '<', 0)->sole()->quantity_units);
    }

    public function test_sending_without_any_stock_keeps_the_negative_lot_balance_and_does_not_double_deduct(): void
    {
        [$client, $user, $item] = $this->context();
        $dispatch = $this->dispatch($client, $item, $user, 2);

        $this->actingAs($user)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertSessionDoesntHaveErrors();

        $debt = StockPallet::query()->where('item_id', $item->id)->sole();
        $this->assertSame(-200, $debt->quantity_units);
        $this->assertSame(-2.0, (float) $debt->warehouse_pallets);
        $this->assertSame('LOT-ONE', $debt->lot);

        $this->actingAs($user)
            ->patch(route('dispatches.update-status', $dispatch->fresh()), ['status' => GoodsDispatch::STATUS_SENT]);

        $this->assertSame(1, StockPallet::query()->where('item_id', $item->id)->count());
        $this->assertSame(-200, $debt->fresh()->quantity_units);
    }

    public function test_sending_more_partial_units_than_available_records_only_the_shortfall_as_negative(): void
    {
        [$client, $user, $item] = $this->context();
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-ONE',
            'units_per_pallet' => 100,
            'quantity_units' => 50,
            'warehouse_pallets' => 1,
            'peak_1' => 50,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
            'created_by' => $user->id,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'line_type' => 'peak',
            'lot' => 'LOT-ONE',
            'units_per_pallet' => 100,
            'requested_peaks' => 1,
            'loaded_pallets' => 0,
            'loaded_peaks' => 0,
            'loaded_partial_units' => 70,
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertSessionDoesntHaveErrors();

        $this->assertSame(0, $stock->fresh()->quantity_units);
        $this->assertSame(-20, StockPallet::query()->where('item_id', $item->id)
            ->where('quantity_units', '<', 0)->sole()->quantity_units);
        $this->assertSame(-70, (int) InventoryMovement::query()->where('source_id', $dispatch->id)
            ->where('movement_type', InventoryMovement::DISPATCH)->sum('units_delta'));
    }

    /** @return array{Client, User, Item} */
    private function context(): array
    {
        Bus::fake();
        $this->seed([RoleSeeder::class, ClientSeeder::class]);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', Role::ALMACEN)->firstOrFail()->id]);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);

        return [$client, $user, $item];
    }

    private function dispatch(Client $client, Item $item, User $user, int $pallets, ?StockPallet $stock = null): GoodsDispatch
    {
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
            'created_by' => $user->id,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'stock_pallet_id' => $stock?->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => 'LOT-ONE',
            'units_per_pallet' => 100,
            'requested_pallets' => $pallets,
            'requested_units' => $pallets * 100,
            'loaded_pallets' => $pallets,
            'confirmed_at' => now(),
            'confirmed_by' => $user->id,
        ]);

        return $dispatch;
    }
}
