<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsReceipt;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Services\DailyOperations\DailyOperationRecalculationService;
use App\Services\DailyOperations\VehicleOperationReport;
use App\Support\TransportMode;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class AllClientTransportTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(12, 0));
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        $this->operator = User::factory()->create(['role_id' => Role::where('slug', Role::ALMACEN)->value('id')]);
    }

    public static function transports(): array
    {
        $cases = [];
        foreach (['FRIESLAND', 'EDELVIVES'] as $client) {
            foreach (['monge', 'subcontracted', 'client'] as $mode) {
                $cases[$client.' '.$mode] = [$client, $mode];
            }
        }

        return $cases;
    }

    private function data(string $mode): array
    {
        return ['tractor_plate' => $mode === 'monge' ? '1933MYN' : '1234ABC', 'carrier_name' => 'Transportista prueba', 'carrier_tax_id' => 'B00000000'];
    }

    #[DataProvider('transports')]
    public function test_receipt_transport_counts_pickup_and_preserves_stock(string $code, string $mode): void
    {
        $client = Client::factory()->create(['code' => $code]);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);
        $this->actingAs($this->operator)->post(route('goods-receipts.store'), [
            'client_id' => $client->id, 'received_at' => today()->toDateString(), 'transport_mode' => $mode,
            'vehicle_type' => 'trailer', 'camion_propio' => ! TransportMode::own($mode), 'transport' => $this->data($mode),
            'lines' => [['item_id' => $item->id, 'quantity_units' => 1000, 'units_per_pallet' => 100, 'pallet_count' => 10]],
        ])->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::latest('id')->firstOrFail();
        $this->assertNull($receipt->vehicle_type);
        $this->assertSame($mode, $receipt->transport_mode);
        $this->assertSame(TransportMode::own($mode), $receipt->camion_propio);
        $this->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasNoErrors();
        $this->assertNotNull($receipt->fresh()->stock_applied_at);
        $this->assertSame(1000, (int) StockPallet::where('client_id', $client->id)->sum('quantity_units'));
        $service = app(DailyOperationRecalculationService::class);
        $day = $service->rebuildForDateAndClient(today()->toDateString(), $client->id, $this->operator->id);
        $row = app(VehicleOperationReport::class)->forDay($day)['totals']['Entrada']['all'];
        $this->assertSame(1, $row['management']);
        $this->assertSame((int) TransportMode::own($mode), $row['trips']);
        $this->assertSame($mode === 'monge' ? 1 : 0, $row['monge']);
        $this->assertSame($mode === 'subcontracted' ? 1 : 0, $row['subcontracted']);
        $again = $service->rebuildForDateAndClient(today()->toDateString(), $client->id, $this->operator->id);
        $this->assertSame($row, app(VehicleOperationReport::class)->forDay($again)['totals']['Entrada']['all']);
        $this->get(route('goods-receipts.show', $receipt))->assertOk()->assertSee(TransportMode::label($mode))->assertDontSee('Tipo de vehículo');
        $this->get(route('daily-operations.index', ['client_id' => $client->id, 'date' => today()->toDateString()]))->assertOk()->assertSee('Gestiones y viajes por transporte')->assertSee('Subcontratados')->assertDontSee('Camión rígido')->assertDontSee('Tráiler');
    }

    #[DataProvider('transports')]
    public function test_dispatch_uses_actual_carrier_and_counts_maximo_services(string $code, string $mode): void
    {
        config(['deca.dispatch.enabled' => true, 'deca.dispatch.shipper_tax_id' => 'B00000000', 'deca.carriers.monge.tax_id' => '00000000T', 'deca.public_base_url' => 'https://wms.example.test']);
        $client = Client::factory()->create(['code' => $code]);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);
        $stock = StockPallet::factory()->create(['client_id' => $client->id, 'item_id' => $item->id, 'quantity_units' => 1000, 'units_per_pallet' => 100, 'full_pallets' => 10, 'peak_1' => 0, 'peaks_count' => 0]);
        $dispatch = GoodsDispatch::factory()->create(['client_id' => $client->id, 'status' => GoodsDispatch::STATUS_PREPARING, 'delivery_address_override' => true, 'delivery_address_text' => 'Destino de prueba']);
        $line = GoodsDispatchLine::factory()->create(['goods_dispatch_id' => $dispatch->id, 'item_id' => $item->id, 'stock_pallet_id' => $stock->id, 'units_per_pallet' => 100, 'loaded_pallets' => 2, 'requested_pallets' => 2, 'confirmed_at' => now(), 'confirmed_by' => $this->operator->id]);
        $this->actingAs($this->operator)->get(route('dispatches.show', $dispatch))->assertOk()->assertSee('Servicio de transporte')->assertDontSee('Tipo de vehículo');
        $transport = [...$this->data($mode), 'weight_kg' => '1000', 'transport_date' => today()->toDateString(), 'articulated' => $mode === 'subcontracted' ? '1' : '0', 'trailer_plate' => $mode === 'subcontracted' ? 'R1234ABC' : null, 'special_authorization' => '0', 'carrier_key' => 'monge'];
        $this->patch(route('dispatches.confirm-loading', $dispatch), ['transport_mode' => $mode, 'transport' => $transport, 'finalize_dispatch' => '1', 'vehicle_type' => 'trailer', 'camion_propio' => ! TransportMode::own($mode), 'lines' => [['line_id' => $line->id, 'loaded_quantity' => 2]]])->assertSessionHasNoErrors();
        $dispatch->refresh();
        $this->assertSame(TransportMode::own($mode), $dispatch->camion_propio);
        $this->assertNull($dispatch->vehicle_type);
        $this->assertSame($mode === 'monge' ? 'monge' : 'external', $dispatch->transport_data['carrier_key']);
        $this->assertSame($mode === 'monge' ? config('deca.carriers.monge.name') : 'Transportista prueba', $dispatch->decaDocument->snapshot['carrier']['name']);
        $this->assertSame(800, (int) StockPallet::where('client_id', $client->id)->sum('quantity_units'));
        $service = app(DailyOperationRecalculationService::class);
        $dispatch->update(['status' => GoodsDispatch::STATUS_COMPLETED, 'completed_at' => now()->addDay()]);
        $day = $service->rebuildForDateAndClient(today()->toDateString(), $client->id, $this->operator->id);
        $this->assertSame((int) TransportMode::own($mode), app(VehicleOperationReport::class)->forDay($day)['totals']['Salida']['all']['trips']);
        $this->assertSame(1, app(VehicleOperationReport::class)->forDay($day)['totals']['Salida']['all']['management']);
        $this->travelTo(now()->addDay());
        $nextDay = $service->rebuildForDateAndClient(today()->toDateString(), $client->id, $this->operator->id);
        $this->assertSame(0, app(VehicleOperationReport::class)->forDay($nextDay)['totals']['Salida']['all']['management']);
    }

    public function test_new_forms_reject_an_explicitly_empty_transport_mode(): void
    {
        $this->actingAs($this->operator)->post(route('goods-receipts.store'), ['transport_mode' => ''])->assertSessionHasErrors('transport_mode');
        $this->post(route('dispatches.store'), ['transport_mode' => ''])->assertSessionHasErrors('transport_mode');
    }

    public function test_non_ti_receipt_cannot_confirm_incomplete_subcontractor_data(): void
    {
        $receipt = GoodsReceipt::factory()->create(['transport_mode' => 'subcontracted', 'transport_data' => []]);
        $this->actingAs($this->operator)->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasErrors(['transport.tractor_plate', 'transport.carrier_name', 'transport.carrier_tax_id']);
        $this->assertNull($receipt->fresh()->stock_applied_at);
    }

    public function test_manual_dispatch_stores_transport_mode_without_vehicle_tariff_type(): void
    {
        $client = Client::factory()->create(['code' => 'FRIESLAND']);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);
        $this->actingAs($this->operator)->post(route('dispatches.store'), [
            'client_id' => $client->id, 'quantities' => [$item->id => 2],
            'transport_mode' => 'subcontracted', 'transport' => $this->data('subcontracted'), 'camion_propio' => false,
        ])->assertSessionHasNoErrors();
        $dispatch = GoodsDispatch::latest('id')->firstOrFail();
        $this->assertSame('subcontracted', $dispatch->transport_mode);
        $this->assertTrue($dispatch->camion_propio);
        $this->assertNull($dispatch->vehicle_type);
        $this->assertSame('1234ABC', $dispatch->transport_data['tractor_plate']);
    }

    public function test_warehouse_user_can_save_external_carrier_and_confirm_edelvives_in_one_action(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES', 'name' => 'EDELVIVES']);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);
        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsReceipt::STATUS_DRAFT,
            'created_by' => $this->operator->id,
            'transport_mode' => null,
            'transport_data' => null,
        ]);

        $response = $this->actingAs($this->operator)->put(route('goods-receipts.update', $receipt), [
            'client_id' => $client->id,
            'received_at' => today()->toDateString(),
            'transport_mode' => 'client',
            'transport' => [
                'tractor_plate' => ' 1234 abc ',
                'carrier_name' => 'Transportista de la carta de porte',
                'carrier_tax_id' => ' b12345678 ',
            ],
            'confirm_after_save' => '1',
            'lines' => [[
                'item_id' => $item->id,
                'quantity_units' => 1000,
                'units_per_pallet' => 100,
                'pallet_count' => 10,
            ]],
        ]);

        $response->assertRedirect(route('goods-receipts.show', $receipt))->assertSessionHasNoErrors();
        $receipt->refresh();
        $this->assertSame(GoodsReceipt::STATUS_CONFIRMED, $receipt->status);
        $this->assertNotNull($receipt->stock_applied_at);
        $this->assertSame('client', $receipt->transport_mode);
        $this->assertSame('1234 ABC', $receipt->transport_data['tractor_plate']);
        $this->assertSame('B12345678', $receipt->transport_data['carrier_tax_id']);
        $this->assertSame('Transportista de la carta de porte', $receipt->transport_data['carrier_name']);
        $this->assertSame(1000, (int) StockPallet::where('goods_receipt_id', $receipt->id)->sum('quantity_units'));
    }

    public function test_save_and_confirm_keeps_external_carrier_errors_next_to_the_fields(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES']);
        $item = Item::factory()->create(['client_id' => $client->id, 'units_per_pallet' => 100]);
        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'status' => GoodsReceipt::STATUS_DRAFT,
            'created_by' => $this->operator->id,
        ]);

        $response = $this->actingAs($this->operator)->from(route('goods-receipts.show', $receipt))->put(route('goods-receipts.update', $receipt), [
            'client_id' => $client->id,
            'received_at' => today()->toDateString(),
            'transport_mode' => 'client',
            'transport' => ['tractor_plate' => ''],
            'confirm_after_save' => '1',
            'lines' => [[
                'item_id' => $item->id,
                'quantity_units' => 100,
                'units_per_pallet' => 100,
                'pallet_count' => 1,
            ]],
        ]);

        $response->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHasErrors(['transport.tractor_plate', 'transport.carrier_name', 'transport.carrier_tax_id']);
        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->fresh()->status);
        $this->assertNull($receipt->fresh()->stock_applied_at);
        $this->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertSee('Completa la matrícula para confirmar el transporte.')
            ->assertSee('Completa el transportista para confirmar el transporte.')
            ->assertSee('Completa el NIF del transportista para confirmar el transporte.');
    }
}
