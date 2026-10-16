<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PartialDispatchClosureWithDecaTest extends TestCase
{
    use RefreshDatabase;

    private User $warehouseUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->travelTo(now()->setDate(2026, 10, 16)->setTime(10, 0));
        $this->seed(RoleSeeder::class);
        Bus::fake();
        Storage::fake('local');

        config([
            'deca.dispatch.enabled' => true,
            'deca.dispatch.shipper_tax_id' => 'B00000000',
            'deca.carriers.monge.tax_id' => '00000000T',
            'deca.carriers.monge.address' => 'Dirección Transportes Monge',
            'deca.public_base_url' => 'https://wms.example.test',
        ]);

        $this->warehouseUser = User::factory()->create([
            'role_id' => Role::query()->where('slug', Role::ALMACEN)->value('id'),
        ]);
    }

    public function test_partial_closure_choice_is_available_before_the_operator_reduces_the_prefilled_load(): void
    {
        [, $request] = $this->createEdelvivesRequest(13);
        $this->generateDispatch($request);

        $this->actingAs($this->warehouseUser)
            ->get(route('dispatches.requests.show', $request))
            ->assertOk()
            ->assertSee('data-completion-decision', false)
            ->assertSee('name="completion_mode"', false)
            ->assertSeeText('Cerrar esta carga parcial y dejar el resto para otro viaje')
            ->assertSeeText('Cerrar el pedido con lo cargado y cancelar el resto')
            ->assertSeeText('DECA de esta salida');
    }

    public function test_edelvives_can_send_eight_of_thirteen_pallets_with_deca_and_leave_five_for_the_next_trip(): void
    {
        [$stock, $request] = $this->createEdelvivesRequest(13);
        $dispatch = $this->generateDispatch($request);
        $line = $dispatch->lines()->firstOrFail();

        $this->actingAs($this->warehouseUser)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'return_to_request' => '1',
                'finalize_dispatch' => '1',
                'completion_mode' => 'leave_pending',
                'transport_mode' => 'monge',
                'transport' => [
                    'carrier_key' => 'monge',
                    'weight_kg' => '8000',
                    'tractor_plate' => '1933MYN',
                    'transport_date' => today()->toDateString(),
                    'articulated' => '0',
                    'special_authorization' => '0',
                ],
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'item_id' => $line->item_id,
                        'line_type' => 'pallet',
                        'loaded_pallets' => 8,
                        'loaded_partial_units' => 0,
                        'allocations' => [[
                            'stock_pallet_id' => $stock->id,
                            'loaded_pallets' => 8,
                            'loaded_partial_units' => 0,
                        ]],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.requests.show', $request))
            ->assertSessionHasNoErrors();

        $this->assertSame(GoodsDispatch::STATUS_SENT, $dispatch->fresh()->status);
        $this->assertNotNull($dispatch->fresh()->deca_document_id);
        $this->assertSame(MerchandiseRequest::STATUS_PARTIALLY_FULFILLED, $request->fresh()->status);
        $this->assertSame(1200, (int) $stock->fresh()->quantity_units);

        $this->actingAs($this->warehouseUser)
            ->post(route('dispatches.requests.generate', $request->fresh()), ['return_to_request' => '1'])
            ->assertRedirect(route('dispatches.requests.show', $request));

        $secondDispatch = GoodsDispatch::query()
            ->where('merchandise_request_id', $request->id)
            ->whereKeyNot($dispatch->id)
            ->firstOrFail();

        $this->assertSame(5, $secondDispatch->lines()->firstOrFail()->requestedPallets());
    }

    public function test_eight_pallet_partial_preparation_can_be_saved_without_deciding_the_final_closure_yet(): void
    {
        [$stock, $request] = $this->createEdelvivesRequest(13);
        $dispatch = $this->generateDispatch($request);
        $line = $dispatch->lines()->firstOrFail();

        $this->actingAs($this->warehouseUser)
            ->patch(route('dispatches.confirm-loading', $dispatch), [
                'return_to_request' => '1',
                'finalize_dispatch' => '0',
                'lines' => [
                    'line_'.$line->id => [
                        'line_id' => $line->id,
                        'loaded_pallets' => 8,
                        'loaded_partial_units' => 0,
                        'allocations' => [[
                            'stock_pallet_id' => $stock->id,
                            'loaded_pallets' => 8,
                            'loaded_partial_units' => 0,
                        ]],
                    ],
                ],
            ])
            ->assertRedirect(route('dispatches.requests.show', $request))
            ->assertSessionHasNoErrors();

        $this->assertSame(GoodsDispatch::STATUS_PREPARING, $dispatch->fresh()->status);
        $this->assertSame(MerchandiseRequest::STATUS_PREPARING, $request->fresh()->status);
        $this->assertSame(8, $dispatch->fresh()->lines()->firstOrFail()->loadedPallets());
        $this->assertSame(2000, (int) $stock->fresh()->quantity_units);
    }

    /**
     * @return array{StockPallet, MerchandiseRequest}
     */
    private function createEdelvivesRequest(int $requestedPallets): array
    {
        $client = Client::factory()->create([
            'name' => 'EDELVIVES',
            'code' => 'EDELVIVES',
            'delivery_address' => 'Destino Edelvives',
            'delivery_postal_code' => '50012',
            'delivery_city' => 'Zaragoza',
            'delivery_province' => 'Zaragoza',
            'delivery_country' => 'ESPAÑA',
        ]);
        $customer = User::factory()->create([
            'client_id' => $client->id,
            'role_id' => Role::query()->where('slug', Role::CLIENTE)->value('id'),
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'EDELVIVES-PARCIAL-13',
            'units_per_pallet' => 100,
        ]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'units_per_pallet' => 100,
            'quantity_units' => 2000,
            'full_pallets' => 20,
            'warehouse_pallets' => 20,
            'peak_1' => 0,
        ]);
        $request = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $customer->id,
            'status' => MerchandiseRequest::STATUS_PENDING,
        ]);
        $request->lines()->create([
            'item_id' => $item->id,
            'stock_pallet_id' => $stock->id,
            'line_type' => 'pallet',
            'units_per_pallet' => 100,
            'requested_pallets' => $requestedPallets,
            'requested_units' => $requestedPallets * 100,
        ]);

        return [$stock, $request];
    }

    private function generateDispatch(MerchandiseRequest $request): GoodsDispatch
    {
        $this->actingAs($this->warehouseUser)
            ->post(route('dispatches.requests.generate', $request), ['return_to_request' => '1'])
            ->assertRedirect(route('dispatches.requests.show', $request));

        return GoodsDispatch::query()
            ->where('merchandise_request_id', $request->id)
            ->firstOrFail();
    }
}
