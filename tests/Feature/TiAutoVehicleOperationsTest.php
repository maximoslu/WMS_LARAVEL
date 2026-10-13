<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\DailyOperationLine;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Services\DailyOperations\DailyOperationRecalculationService;
use App\Services\DailyOperations\VehicleOperationReport;
use App\Services\Deca\DispatchDecaService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class TiAutoVehicleOperationsTest extends TestCase
{
    use RefreshDatabase;

    private Client $client;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(now()->setDate(2026, 10, 13)->setTime(10, 0));
        $this->seed(RoleSeeder::class);
        $this->client = Client::factory()->create(['code' => 'TIAUTO', 'name' => 'TI AUTOMOTIVE']);
        $this->operator = User::factory()->create(['role_id' => Role::where('slug', Role::ALMACEN)->value('id')]);
        Storage::fake('local');
    }

    private function receipt(?string $type, bool $own = false, int $pallets = 2): GoodsReceipt
    {
        $receipt = GoodsReceipt::factory()->create(['client_id' => $this->client->id, 'supplier_id' => null, 'received_at' => today(), 'vehicle_type' => $type, 'camion_propio' => $own, 'transport_mode' => $own ? 'subcontracted' : 'client', 'transport_data' => ['tractor_plate' => '1234ABC', 'trailer_plate' => 'R1234ABC', 'carrier_name' => 'Carrier Test', 'carrier_tax_id' => 'B00000000']]);
        $item = Item::factory()->create(['client_id' => $this->client->id, 'units_per_pallet' => 100]);
        GoodsReceiptLine::factory()->create(['goods_receipt_id' => $receipt->id, 'item_id' => $item->id, 'sku' => $item->sku, 'location_id' => null, 'quantity_units' => $pallets * 100, 'units_per_pallet' => 100, 'pallet_count' => $pallets, 'pico_units' => null]);

        return $receipt;
    }

    private function dispatch(?string $type, bool $own = true): GoodsDispatch
    {
        $dispatch = GoodsDispatch::factory()->create(['client_id' => $this->client->id, 'status' => GoodsDispatch::STATUS_PREPARING, 'vehicle_type' => $type, 'camion_propio' => $own, 'transport_mode' => $own ? 'subcontracted' : 'client', 'transport_data' => ['tractor_plate' => '1234ABC', 'trailer_plate' => 'R1234ABC', 'carrier_name' => 'Carrier Test', 'carrier_tax_id' => 'B00000000'], 'delivery_address_override' => true, 'delivery_address_text' => 'Destino de prueba']);
        $item = Item::factory()->create(['client_id' => $this->client->id, 'units_per_pallet' => 100]);
        $stock = StockPallet::factory()->create(['client_id' => $this->client->id, 'item_id' => $item->id, 'units_per_pallet' => 100, 'quantity_units' => 1000, 'full_pallets' => 10, 'peak_1' => 0, 'peaks_count' => 0]);
        GoodsDispatchLine::factory()->create(['goods_dispatch_id' => $dispatch->id, 'item_id' => $item->id, 'stock_pallet_id' => $stock->id, 'units_per_pallet' => 100, 'loaded_pallets' => 2, 'requested_pallets' => 2, 'confirmed_at' => now(), 'confirmed_by' => $this->operator->id]);

        return $dispatch;
    }

    public function test_ti_receipt_requires_type_before_applying_stock_and_other_clients_do_not(): void
    {
        $receipt = $this->receipt(null);
        $this->actingAs($this->operator)->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasErrors('vehicle_type');
        $this->assertNull($receipt->fresh()->stock_applied_at);
        $receipt->update(['vehicle_type' => 'rigid']);
        $this->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasNoErrors();
        $this->assertNotNull($receipt->fresh()->stock_applied_at);
        $this->client->update(['code' => 'OTHER']);
        $other = $this->receipt(null);
        $this->patch(route('goods-receipts.confirm', $other))->assertSessionHasNoErrors();
        $this->assertNotNull($other->fresh()->stock_applied_at);
    }

    public function test_receipt_creation_and_inline_update_store_both_independent_fields(): void
    {
        $item = Item::factory()->create(['client_id' => $this->client->id, 'units_per_pallet' => 100]);
        $payload = ['client_id' => $this->client->id, 'received_at' => '2026-10-13', 'vehicle_type' => 'trailer', 'camion_propio' => '0', 'lines' => [['item_id' => $item->id, 'quantity_units' => 1000, 'units_per_pallet' => 100, 'pallet_count' => 10]]];
        $this->actingAs($this->operator)->post(route('goods-receipts.store'), $payload)->assertSessionHasNoErrors();
        $receipt = GoodsReceipt::latest('id')->firstOrFail();
        $this->assertSame('trailer', $receipt->vehicle_type);
        $this->assertFalse($receipt->camion_propio);
        $this->get(route('goods-receipts.show', $receipt))->assertOk()->assertSee('Tipo de vehículo');
        $payload['vehicle_type'] = 'rigid';
        $payload['camion_propio'] = '1';
        $this->put(route('goods-receipts.update', $receipt), $payload)->assertSessionHasNoErrors();
        $this->assertSame('rigid', $receipt->fresh()->vehicle_type);
        $this->assertTrue($receipt->fresh()->camion_propio);
        $payload['vehicle_type'] = 'van';
        $this->put(route('goods-receipts.update', $receipt), $payload)->assertSessionHasErrors('vehicle_type');
    }

    public function test_status_endpoint_cannot_bypass_vehicle_validation_even_without_deca(): void
    {
        $dispatch = $this->dispatch(null);
        $this->actingAs($this->operator)->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])->assertSessionHasErrors('vehicle_type');
        $this->assertNull($dispatch->fresh()->stock_applied_at);
        $this->assertSame(GoodsDispatch::STATUS_PREPARING, $dispatch->fresh()->status);
    }

    public function test_loading_saves_type_and_own_transport_separately_from_deca_carrier(): void
    {
        config(['deca.dispatch.enabled' => true]);
        $dispatch = $this->dispatch(null);
        $this->actingAs($this->operator)->patch(route('dispatches.confirm-loading', $dispatch), [
            'vehicle_type' => 'trailer', 'transport_mode' => 'client', 'camion_propio' => '1', 'transport' => ['carrier_key' => 'monge', 'articulated' => '0'],
            'lines' => [['line_id' => $dispatch->lines->first()->id, 'loaded_quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $dispatch->refresh();
        $this->assertSame('trailer', $dispatch->vehicle_type);
        $this->assertFalse($dispatch->camion_propio);
        $this->assertSame('1', $dispatch->transport_data['articulated']);
        $this->get(route('dispatches.show', $dispatch))->assertOk()->assertSee('Vehículo · TI Automotive');
    }

    public function test_trailer_requires_remolque_in_deca_and_rigid_does_not(): void
    {
        config(['deca.dispatch.enabled' => true, 'deca.dispatch.shipper_tax_id' => 'B00000000', 'deca.carriers.monge.tax_id' => '00000000T', 'deca.public_base_url' => 'https://wms.example.test']);
        $dispatch = $this->dispatch('trailer', false);
        app(DispatchDecaService::class)->saveDraft($dispatch, ['carrier_key' => 'external', 'trailer_plate' => null, 'carrier_name' => 'Carrier Test', 'carrier_tax_id' => 'B00000000', 'weight_kg' => '1000', 'tractor_plate' => '1234ABC', 'transport_date' => today()->toDateString()]);
        $this->actingAs($this->operator)->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])->assertSessionHasErrors('transport.trailer_plate');
        $this->assertDatabaseCount('deca_documents', 0);
        $dispatch->update(['vehicle_type' => 'rigid']);
        $this->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('deca_documents', 1);
        $this->assertFalse((bool) $dispatch->fresh()->decaDocument->snapshot['articulated']);
        $this->assertFalse($dispatch->fresh()->camion_propio);
    }

    public function test_recalculation_counts_vehicles_not_pallets_and_is_repeatable_with_unknown_history(): void
    {
        foreach ([['rigid', true, 2], ['rigid', false, 10], ['trailer', true, 33], [null, false, 5]] as [$type, $own, $pallets]) {
            $this->receipt($type, $own, $pallets)->update(['status' => GoodsReceipt::STATUS_CONFIRMED]);
        }
        $this->dispatch('trailer', false)->update(['status' => GoodsDispatch::STATUS_SENT, 'sent_at' => now()]);
        $this->dispatch('rigid', true)->update(['status' => GoodsDispatch::STATUS_SENT, 'sent_at' => now()]);
        $service = app(DailyOperationRecalculationService::class);
        $day = $service->rebuildForDateAndClient(today()->toDateString(), $this->client->id, $this->operator->id);
        $report = app(VehicleOperationReport::class)->forDay($day);
        $this->assertSame(2, $report['totals']['Entrada']['rigid']['management']);
        $this->assertSame(1, $report['totals']['Entrada']['rigid']['trips']);
        $this->assertSame(1, $report['totals']['Entrada']['trailer']['management']);
        $this->assertSame(1, $report['totals']['Entrada']['unknown']['management']);
        $this->assertSame(0, $report['totals']['Salida']['trailer']['trips']);
        $this->assertSame(1, $report['totals']['Salida']['rigid']['trips']);
        $again = $service->rebuildForDateAndClient(today()->toDateString(), $this->client->id, $this->operator->id);
        $this->assertSame($report, app(VehicleOperationReport::class)->forDay($again));
        $this->assertSame(6, (int) $again->lines->where('section', DailyOperationLine::SECTION_GESTION_CAMION)->sum('pallets'));
        $this->actingAs($this->operator)->get(route('daily-operations.index', ['client_id' => $this->client->id, 'date' => today()->toDateString()]))->assertOk()->assertSee('Gestiones y viajes por vehículo')->assertSee('Sin clasificar')->assertSee('FactuSol');
    }

    public function test_completing_a_dispatch_later_does_not_move_or_duplicate_the_service(): void
    {
        $dispatch = $this->dispatch('trailer');
        $dispatch->update(['status' => GoodsDispatch::STATUS_SENT, 'sent_at' => now()]);
        $service = app(DailyOperationRecalculationService::class);
        $day = $service->rebuildForDateAndClient('2026-10-13', $this->client->id, $this->operator->id);
        $dispatch->update(['status' => GoodsDispatch::STATUS_COMPLETED, 'completed_at' => now()->addDay()]);
        $sameDay = $service->rebuildForDateAndClient('2026-10-13', $this->client->id, $this->operator->id);
        $this->travelTo(now()->addDay());
        $nextDay = $service->rebuildForDateAndClient('2026-10-14', $this->client->id, $this->operator->id);
        $this->assertSame(1, app(VehicleOperationReport::class)->forDay($sameDay)['totals']['Salida']['trailer']['management']);
        $this->assertSame(0, app(VehicleOperationReport::class)->forDay($nextDay)['totals']['Salida']['trailer']['management']);
    }

    public function test_other_clients_have_no_vehicle_report_or_required_type(): void
    {
        $this->client->update(['code' => 'OTHER']);
        $receipt = $this->receipt(null);
        $receipt->update(['status' => GoodsReceipt::STATUS_CONFIRMED]);
        $day = app(DailyOperationRecalculationService::class)->rebuildForDateAndClient(today()->toDateString(), $this->client->id, $this->operator->id);
        $this->assertSame([], app(VehicleOperationReport::class)->forDay($day)['totals']);
        $this->actingAs($this->operator)->get(route('daily-operations.index', ['client_id' => $this->client->id]))->assertOk()->assertDontSee('Gestiones y viajes por vehículo');
    }

    public function test_subcontracted_receipt_counts_one_maximo_trip_and_client_receipt_counts_none(): void
    {
        foreach (['subcontracted' => 1, 'client' => 0, 'monge' => 1] as $mode => $trips) {
            $item = Item::factory()->create(['client_id' => $this->client->id, 'units_per_pallet' => 100]);
            $this->actingAs($this->operator)->post(route('goods-receipts.store'), [
                'client_id' => $this->client->id, 'received_at' => today()->toDateString(), 'vehicle_type' => 'rigid',
                'transport_mode' => $mode, 'camion_propio' => $trips ? '0' : '1',
                'transport' => ['tractor_plate' => $mode === 'monge' ? '1933MYN' : '1234ABC', 'carrier_name' => 'Carrier Test', 'carrier_tax_id' => 'B00000000'],
                'lines' => [['item_id' => $item->id, 'quantity_units' => 1000, 'units_per_pallet' => 100, 'pallet_count' => 10]],
            ])->assertSessionHasNoErrors();
            $receipt = GoodsReceipt::latest('id')->firstOrFail();
            $this->assertSame((bool) $trips, $receipt->camion_propio);
            $this->assertSame($mode, $receipt->transport_mode);
            $this->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasNoErrors();
        }
        $day = app(DailyOperationRecalculationService::class)->rebuildForDateAndClient(today()->toDateString(), $this->client->id, $this->operator->id);
        $row = app(VehicleOperationReport::class)->forDay($day)['totals']['Entrada']['rigid'];
        $this->assertSame(3, $row['management']);
        $this->assertSame(2, $row['trips']);
        $this->assertSame(1, $row['monge']);
        $this->assertSame(1, $row['subcontracted']);
    }

    public function test_monge_cannot_be_registered_as_trailer_or_unknown_plate(): void
    {
        $receipt = $this->receipt('trailer');
        $receipt->update(['transport_mode' => 'monge', 'transport_data' => ['tractor_plate' => '1933MYN']]);
        $this->actingAs($this->operator)->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasErrors('vehicle_type');
        $receipt->update(['vehicle_type' => 'rigid', 'transport_data' => ['tractor_plate' => 'FAKE']]);
        $this->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasErrors('transport.tractor_plate');
        $this->assertNull($receipt->fresh()->stock_applied_at);
        $receipt->update(['transport_data' => ['tractor_plate' => '3100KGC']]);
        $this->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasNoErrors();
    }

    public function test_missing_subcontractor_fields_block_confirmation_without_affecting_stock(): void
    {
        $receipt = $this->receipt('trailer', true);
        $receipt->update(['transport_data' => []]);
        $this->actingAs($this->operator)->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasErrors(['transport.carrier_name', 'transport.carrier_tax_id', 'transport.tractor_plate', 'transport.trailer_plate']);
        $this->assertNull($receipt->fresh()->stock_applied_at);
        $receipt->update(['transport_mode' => null]);
        $this->patch(route('goods-receipts.confirm', $receipt))->assertSessionHasErrors('transport_mode');
    }

    public function test_subcontracted_deca_names_actual_carrier_and_transport_is_frozen_after_sending(): void
    {
        config(['deca.dispatch.enabled' => true, 'deca.dispatch.shipper_tax_id' => 'B00000000', 'deca.public_base_url' => 'https://wms.example.test']);
        $dispatch = $this->dispatch('trailer', true);
        $this->actingAs($this->operator)->patch(route('dispatches.confirm-loading', $dispatch), [
            'vehicle_type' => 'trailer', 'transport_mode' => 'subcontracted', 'camion_propio' => '0', 'finalize_dispatch' => '1',
            'transport' => ['carrier_key' => 'monge', 'weight_kg' => '1000', 'tractor_plate' => '1234ABC', 'trailer_plate' => 'R1234ABC', 'carrier_name' => 'Carrier Test', 'carrier_tax_id' => 'B00000000', 'transport_date' => today()->toDateString()],
            'lines' => [['line_id' => $dispatch->lines->first()->id, 'loaded_quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $dispatch->refresh();
        $this->assertTrue($dispatch->camion_propio);
        $this->assertSame('external', $dispatch->transport_data['carrier_key']);
        $this->assertSame('Carrier Test', $dispatch->decaDocument->snapshot['carrier_name']);
        $this->assertSame('R1234ABC', $dispatch->decaDocument->snapshot['trailer_plate']);
        $this->put(route('dispatches.own-truck.update', $dispatch), ['camion_propio' => '0'])->assertSessionHasErrors('transport');
        $this->assertTrue($dispatch->fresh()->camion_propio);
        $day = app(DailyOperationRecalculationService::class)->rebuildForDateAndClient(today()->toDateString(), $this->client->id, $this->operator->id);
        $row = app(VehicleOperationReport::class)->forDay($day)['totals']['Salida']['trailer'];
        $this->assertSame(1, $row['subcontracted']);
        $this->assertSame(1, $row['trips']);
        $this->assertSame(1, $row['management']);
    }

    public function test_legacy_own_services_remain_unclassified_and_do_not_become_monge(): void
    {
        $receipt = $this->receipt('rigid', true);
        $receipt->update(['status' => GoodsReceipt::STATUS_CONFIRMED, 'transport_mode' => null]);
        $day = app(DailyOperationRecalculationService::class)->rebuildForDateAndClient(today()->toDateString(), $this->client->id, $this->operator->id);
        $row = app(VehicleOperationReport::class)->forDay($day)['totals']['Entrada']['rigid'];
        $this->assertSame(1, $row['unclassified']);
        $this->assertSame(0, $row['monge']);
        $this->assertSame(0, $row['subcontracted']);
        $this->assertSame(1, $row['trips']);
    }

    public function test_dispatch_without_deca_still_uses_mode_not_submitted_own_flag(): void
    {
        config(['deca.dispatch.enabled' => false]);
        $dispatch = $this->dispatch('rigid', false);
        $this->actingAs($this->operator)->patch(route('dispatches.confirm-loading', $dispatch), [
            'vehicle_type' => 'rigid', 'transport_mode' => 'monge', 'camion_propio' => '0', 'finalize_dispatch' => '1',
            'transport' => ['tractor_plate' => '1933MYN'],
            'lines' => [['line_id' => $dispatch->lines->first()->id, 'loaded_quantity' => 2]],
        ])->assertSessionHasNoErrors();
        $this->assertTrue($dispatch->fresh()->camion_propio);
        $this->assertSame('monge', $dispatch->fresh()->transport_mode);
        $this->assertSame(GoodsDispatch::STATUS_SENT, $dispatch->fresh()->status);
    }
}
