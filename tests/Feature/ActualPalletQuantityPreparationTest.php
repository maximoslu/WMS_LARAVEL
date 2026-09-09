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
use App\Services\MerchandiseRequests\MerchandiseRequestFulfillmentService;
use App\Support\Stock\StockVariantCatalog;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ActualPalletQuantityPreparationTest extends TestCase
{
    use RefreshDatabase;

    public function test_mixed_real_pallet_sizes_are_not_calculated_with_the_item_standard(): void
    {
        $this->seed([RoleSeeder::class, ClientSeeder::class]);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $warehouseUser = $this->userWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'PAPEL-482X66-240',
            'description' => '48,2 x 66 240 gramos',
            'units_per_pallet' => 5700,
        ]);
        $stock5700 = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-A',
            'location_text' => '10',
            'units_per_pallet' => 5700,
            'quantity_units' => 5700,
            'peak_1' => 0,
        ]);
        $stock5500 = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-B',
            'location_text' => '11',
            'units_per_pallet' => 5500,
            'quantity_units' => 5500,
            'peak_1' => 0,
        ]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $requestLine = $request->lines()->create([
            'item_id' => $item->id,
            'stock_pallet_id' => $stock5700->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 5700,
            'requested_pallets' => 2,
            'requested_units' => 11200,
            'required_units' => 11200,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $request->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $dispatchLine = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'source_request_line_id' => $requestLine->id,
            'line_type' => 'pallet',
            'sku' => $item->sku,
            'description' => $item->description,
            'units_per_pallet' => 5700,
            'requested_pallets' => 2,
            'requested_units' => 11200,
        ]);

        $this->actingAs($warehouseUser)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'return_to_request' => '1',
                'lines' => [
                    'line_'.$dispatchLine->id => [
                        'line_id' => $dispatchLine->id,
                        'allocations' => [
                            ['stock_pallet_id' => $stock5700->id, 'loaded_pallets' => 1],
                            ['stock_pallet_id' => $stock5500->id, 'loaded_pallets' => 1],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.requests.show', $request));

        $dispatchLine->refresh()->load('allocations.stockPallet');

        $this->assertSame(11200, $dispatchLine->loadedUnitsTotal());
        $this->assertSame([5700, 5500], $dispatchLine->allocations->pluck('units_per_pallet')->all());

        $summary = app(MerchandiseRequestFulfillmentService::class)->summary($request->fresh(), $dispatch->fresh());
        $this->assertSame(11200, $summary['current_units']);
        $this->assertSame(0, $summary['pending_units_after_current']);

        $this->actingAs($warehouseUser)
            ->get(route('dispatches.requests.show', $request))
            ->assertOk()
            ->assertSee('Uds/pallet reales: 5.700')
            ->assertSee('Uds/pallet reales: 5.500')
            ->assertSee('1 pallet × 5.700 uds = 5.700 uds')
            ->assertSee('1 pallet × 5.500 uds = 5.500 uds')
            ->assertDontSee('2 pallets × 5.700 uds');
    }

    public function test_request_line_uses_the_selected_stock_pallet_quantity_instead_of_the_item_standard(): void
    {
        Bus::fake();
        $this->seed([RoleSeeder::class, ClientSeeder::class]);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $clientUser = $this->userWithRole(Role::CLIENTE, $client);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'REAL-5500',
            'units_per_pallet' => 5700,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-REAL',
            'units_per_pallet' => 5500,
            'quantity_units' => 11000,
            'peak_1' => 0,
        ]);

        $this->actingAs($clientUser)
            ->post(route('merchandise-requests.store'), [
                'lines' => [
                    'pallet_real' => [
                        'item_id' => $item->id,
                        'line_type' => 'pallet',
                        'stock_pallet_id' => $stock->id,
                        'quantity' => 2,
                    ],
                ],
            ])
            ->assertRedirect();

        $line = MerchandiseRequest::query()->firstOrFail()->lines()->firstOrFail();

        $this->assertSame(5500, $line->units_per_pallet);
        $this->assertSame(11000, $line->requested_units);
        $this->assertSame(11000, $line->requestedUnitsTotal());
    }

    public function test_stock_catalog_keeps_different_real_pallet_sizes_separate_even_in_the_same_location(): void
    {
        $this->seed([RoleSeeder::class, ClientSeeder::class]);
        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'MIX-SAME-LOCATION',
            'units_per_pallet' => 5700,
        ]);

        foreach ([5700, 5500, 4800] as $index => $unitsPerPallet) {
            StockPallet::factory()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'lot' => $index < 2 ? 'LOTE-COMUN' : 'LOTE-DOS',
                'location_text' => 'UB-12',
                'units_per_pallet' => $unitsPerPallet,
                'quantity_units' => $unitsPerPallet,
                'peak_1' => 0,
            ]);
        }

        $variants = collect(app(StockVariantCatalog::class)->search(
            'MIX-SAME-LOCATION',
            $client->id,
            20,
            true,
            true,
        ))->where('line_type', 'pallet')->values();

        $this->assertSame([4800, 5500, 5700], $variants->pluck('units_per_pallet')->sort()->values()->all());
        $this->assertCount(3, $variants->pluck('stock_pallet_id')->unique());
        $this->assertSame(['UB-12'], $variants->pluck('location_text')->unique()->values()->all());
        $this->assertStringContainsString(
            '5.500 uds/pallet reales',
            $variants->firstWhere('units_per_pallet', 5500)['meta'],
        );
    }

    public function test_equal_real_pallet_sizes_keep_the_existing_total(): void
    {
        $this->seed([RoleSeeder::class, ClientSeeder::class]);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 5700]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 5700,
            'quantity_units' => 28500,
            'peak_1' => 0,
        ]);
        $dispatch = GoodsDispatch::factory()->create(['client_id' => $client->id]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'units_per_pallet' => 5700,
            'loaded_pallets' => 5,
        ]);
        $line->allocations()->create([
            'stock_pallet_id' => $stock->id,
            'units_per_pallet' => 5700,
            'loaded_pallets' => 5,
            'loaded_partial_units' => 0,
        ]);

        $this->assertSame(28500, $line->fresh()->loadedUnitsTotal());
        $this->assertSame('5 pallets × 5.700 uds = 28.500 uds', $line->allocations()->first()->pickingQuantityLabel());
    }

    public function test_mixed_pallets_and_peak_are_sent_and_deducted_with_the_exact_physical_composition(): void
    {
        Bus::fake();
        $this->seed([RoleSeeder::class, ClientSeeder::class]);
        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();
        $warehouseUser = $this->userWithRole(Role::ALMACEN);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'PAPEL-MIXTO-CARGA',
            'units_per_pallet' => 5700,
        ]);
        $stockRows = collect([
            ['lot' => 'L-A', 'location_text' => '10', 'units_per_pallet' => 5700, 'quantity_units' => 11400, 'peak_1' => 0],
            ['lot' => 'L-B', 'location_text' => '11', 'units_per_pallet' => 5500, 'quantity_units' => 5500, 'peak_1' => 0],
            ['lot' => 'L-C', 'location_text' => '12', 'units_per_pallet' => 4900, 'quantity_units' => 4900, 'peak_1' => 0],
            ['lot' => 'L-D', 'location_text' => null, 'units_per_pallet' => 4800, 'quantity_units' => 1100, 'peak_1' => 1100],
        ])->map(fn (array $attributes) => StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            ...$attributes,
        ]));
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'status' => MerchandiseRequest::STATUS_PREPARING,
        ]);
        $requestLine = $request->lines()->create([
            'item_id' => $item->id,
            'stock_pallet_id' => $stockRows[0]->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 5700,
            'requested_pallets' => 4,
            'requested_units' => 22900,
            'required_units' => 22900,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'merchandise_request_id' => $request->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'source_request_line_id' => $requestLine->id,
            'line_type' => 'pallet',
            'sku' => $item->sku,
            'description' => $item->description,
            'units_per_pallet' => 5700,
            'requested_pallets' => 4,
            'requested_units' => 22900,
        ]);

        $this->actingAs($warehouseUser)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'return_to_request' => '1',
                'finalize_dispatch' => '1',
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'allocations' => [
                            ['stock_pallet_id' => $stockRows[0]->id, 'loaded_pallets' => 2],
                            ['stock_pallet_id' => $stockRows[1]->id, 'loaded_pallets' => 1],
                            ['stock_pallet_id' => $stockRows[2]->id, 'loaded_pallets' => 1],
                            ['stock_pallet_id' => $stockRows[3]->id, 'loaded_pallets' => 0, 'selected_peak_indices' => [1]],
                        ],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.requests.show', $request));

        $line->refresh()->load('allocations.stockPallet');

        $this->assertSame(22900, $line->loadedUnitsTotal());
        $this->assertSame([5700, 5500, 4900, 4800], $line->allocations->pluck('units_per_pallet')->all());
        $this->assertSame([0, 0, 0, 0], $stockRows->map(fn (StockPallet $stock) => $stock->fresh()->quantity_units)->all());
        $this->assertSame(GoodsDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertSame(22900, $dispatch->fresh()->load('lines.allocations.stockPallet')->loadedUnitsCount());

        $html = view('dispatches.delivery-note-pdf', [
            'dispatch' => $dispatch->fresh()->load('client', 'lines.allocations.stockPallet', 'lines.sourceRequestLine'),
        ])->render();
        $this->assertStringContainsString('2 pallets × 5.700 uds = 11.400 uds', $html);
        $this->assertStringContainsString('1 pallet × 5.500 uds = 5.500 uds', $html);
        $this->assertStringContainsString('pico 1.100 uds', $html);
        $this->assertStringContainsString('22.900', $html);
    }

    public function test_loading_rejects_a_real_pallet_without_a_physical_units_per_pallet_value(): void
    {
        $this->seed([RoleSeeder::class, ClientSeeder::class]);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $warehouseUser = $this->userWithRole(Role::ALMACEN);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 5700]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 5000,
            'quantity_units' => 5000,
            'full_pallets' => 1,
            'warehouse_pallets' => 1,
            'peak_1' => 0,
        ]);
        DB::table('stock_pallets')->where('id', $stock->id)->update(['units_per_pallet' => 0]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsDispatch::STATUS_PREPARING,
        ]);
        $line = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $item->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 5700,
            'requested_pallets' => 1,
            'requested_units' => 5700,
        ]);

        $this->actingAs($warehouseUser)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'allocations' => [[
                            'stock_pallet_id' => $stock->id,
                            'loaded_pallets' => 1,
                        ]],
                    ],
                ],
            ])
            ->assertSessionHasErrors('lines.line_'.$line->id.'.allocations.0.stock_pallet_id');

        $this->assertSame(5000, $stock->fresh()->quantity_units);
        $this->assertSame(0, $line->allocations()->count());
    }

    private function userWithRole(string $roleSlug, ?Client $client = null): User
    {
        return User::factory()->create([
            'role_id' => Role::query()->where('slug', $roleSlug)->value('id'),
            'client_id' => $client?->id,
        ]);
    }
}
