<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GoodsDispatchUnitsCalculationTest extends TestCase
{
    use RefreshDatabase;

    public function test_preparation_shows_final_units_control_with_the_real_pallet_size(): void
    {
        $this->seed([RoleSeeder::class, ClientSeeder::class]);

        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $warehouseUser = $this->makeWarehouseUser();
        $customerUser = User::factory()->create([
            'client_id' => $client->id,
            'role_id' => Role::query()->where('slug', Role::CLIENTE)->value('id'),
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'UNITS-CALC-001',
            'units_per_pallet' => 6500,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 6500,
            'quantity_units' => 13000,
            'full_pallets' => 2,
            'warehouse_pallets' => 2,
        ]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $customerUser->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $requestLine = $request->lines()->create([
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 6500,
            'requested_pallets' => 1,
            'requested_units' => 6500,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $request->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'source_request_line_id' => $requestLine->id,
            'stock_pallet_id' => $stock->id,
            'line_type' => 'pallet',
            'sku' => $item->sku,
            'description' => $item->description,
            'units_per_pallet' => 6500,
            'requested_pallets' => 1,
            'requested_units' => 6500,
        ]);

        $this->actingAs($warehouseUser)
            ->get(route('dispatches.requests.show', $request))
            ->assertOk()
            ->assertSee('Unidades finales')
            ->assertSee('data-loaded-final-units', false)
            ->assertSee('data-units-per-pallet="6500"', false);
    }

    private function makeWarehouseUser(): User
    {
        $user = User::factory()->create();
        $user->update(['role_id' => Role::query()->where('slug', Role::ALMACEN)->value('id')]);

        return $user;
    }
}
