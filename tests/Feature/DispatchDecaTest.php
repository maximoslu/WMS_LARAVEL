<?php

namespace Tests\Feature;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Services\Deca\DecaPdfService;
use App\Services\Deca\DispatchDecaService;
use App\Services\GoodsDispatches\GoodsDispatchWorkflowService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class DispatchDecaTest extends TestCase
{
    use RefreshDatabase;

    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RoleSeeder::class);
        Storage::fake('local');
        config([
            'deca.dispatch.enabled' => true,
            'deca.dispatch.shipper_tax_id' => 'B00000000',
            'deca.carriers.monge.tax_id' => '00000000T',
            'deca.public_base_url' => 'https://wms.example.test',
        ]);
        $this->operator = User::factory()->create(['role_id' => Role::where('slug', Role::ALMACEN)->value('id')]);
    }

    private function dispatch(array $transport = []): GoodsDispatch
    {
        $dispatch = GoodsDispatch::factory()->create([
            'status' => GoodsDispatch::STATUS_PREPARING,
            'delivery_address_override' => true,
            'delivery_address_text' => 'Destino de prueba, Zaragoza',
            'transport_data' => array_replace([
                'carrier_key' => 'monge', 'weight_kg' => '1500.250', 'tractor_plate' => '1234ABC',
                'transport_date' => now('Europe/Madrid')->toDateString(), 'articulated' => '0', 'special_authorization' => '0',
            ], $transport),
        ]);
        $item = Item::factory()->create(['client_id' => $dispatch->client_id]);
        $stock = StockPallet::factory()->create(['client_id' => $dispatch->client_id, 'item_id' => $item->id, 'full_pallets' => 10, 'peak_1' => 0, 'peaks_count' => 0, 'quantity_units' => 1000, 'units_per_pallet' => 100]);
        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id, 'stock_pallet_id' => $stock->id, 'item_id' => $stock->item_id,
            'sku' => 'PAPEL-001', 'description' => 'Papel de prueba', 'units_per_pallet' => 100,
            'loaded_pallets' => 2, 'requested_pallets' => 3, 'confirmed_at' => now(), 'confirmed_by' => $this->operator->id,
        ]);

        return $dispatch;
    }

    private function send(GoodsDispatch $dispatch): void
    {
        app(GoodsDispatchWorkflowService::class)->changeStatus($dispatch, GoodsDispatch::STATUS_SENT, $this->operator);
    }

    public function test_sending_creates_one_frozen_deca_and_reprinting_reuses_it(): void
    {
        $dispatch = $this->dispatch();
        $this->send($dispatch);
        $document = $dispatch->fresh()->decaDocument;
        $this->assertNotNull($document);
        $this->assertSame('Destino de prueba, Zaragoza', $document->snapshot['destination']);
        $this->assertStringContainsString('200 unidades', $document->snapshot['goods']);
        $this->assertSame('MÁXIMO SERVICIOS LOGÍSTICOS S.L.U.', $document->snapshot['shipper_name']);
        $this->assertSame('1500.250', $document->snapshot['weight_kg']);
        $bytes = Storage::disk('local')->get($document->pdf_path);
        $this->send($dispatch->fresh());
        foreach (range(1, 2) as $attempt) {
            $this->actingAs($this->operator)->get(route('dispatches.delivery-note', $dispatch))->assertOk()->assertHeader('content-type', 'application/pdf');
        }
        $this->assertDatabaseCount('deca_documents', 1);
        $this->get($document->public_url)->assertContent($bytes);
        $dispatch->client->update(['name' => 'Nombre posterior']);
        config(['deca.carriers.monge.name' => 'Transportista posterior']);
        $this->get($document->public_url)->assertContent($bytes);
        $html = view('dispatches.delivery-note-pdf', ['dispatch' => $dispatch->fresh()])->render();
        $this->assertStringContainsString($document->number, $html);
        $this->assertStringContainsString($document->public_url, $html);
        $this->assertStringContainsString('1234ABC', $html);
    }

    public function test_external_carrier_requires_its_own_identity_and_is_snapshotted(): void
    {
        $dispatch = $this->dispatch(['carrier_key' => 'external']);
        try {
            $this->send($dispatch);
            $this->fail('Expected validation failure');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('transport.carrier_name', $exception->errors());
            $this->assertArrayHasKey('transport.carrier_tax_id', $exception->errors());
        }
        $this->assertDatabaseCount('deca_documents', 0);
        $this->assertSame(GoodsDispatch::STATUS_PREPARING, $dispatch->fresh()->status);
        app(DispatchDecaService::class)->saveDraft($dispatch->fresh(), ['carrier_name' => 'Transportista de prueba', 'carrier_tax_id' => 'B11111111']);
        $this->send($dispatch->fresh());
        $this->assertSame('B11111111', $dispatch->fresh()->decaDocument->snapshot['carrier']['tax_id']);
        $this->assertFalse($dispatch->fresh()->camion_propio);
    }

    public function test_missing_weight_plate_and_trailer_block_sending_without_deducting_stock(): void
    {
        $dispatch = $this->dispatch(['weight_kg' => '', 'tractor_plate' => '', 'articulated' => '1']);
        $stock = $dispatch->lines->first()->stockPallet;
        try {
            $this->send($dispatch);
            $this->fail('Expected validation failure');
        } catch (ValidationException $exception) {
            foreach (['weight_kg', 'tractor_plate', 'trailer_plate'] as $field) {
                $this->assertArrayHasKey('transport.'.$field, $exception->errors());
            }
        }
        $this->assertSame(1000, $stock->fresh()->quantity_units);
        $this->assertNull($dispatch->fresh()->sent_at);
        $this->assertDatabaseCount('deca_documents', 0);
    }

    public function test_pdf_failure_rolls_back_dispatch_stock_and_document(): void
    {
        $dispatch = $this->dispatch();
        $stock = $dispatch->lines->first()->stockPallet;
        $this->mock(DecaPdfService::class)->shouldReceive('render')->once()->andThrow(new \RuntimeException('PDF failure'));
        try {
            $this->send($dispatch);
            $this->fail('Expected PDF failure');
        } catch (\RuntimeException $exception) {
            $this->assertSame('PDF failure', $exception->getMessage());
        }
        $this->assertSame(1000, $stock->fresh()->quantity_units);
        $this->assertSame(GoodsDispatch::STATUS_PREPARING, $dispatch->fresh()->status);
        $this->assertDatabaseCount('deca_documents', 0);
        $this->assertSame([], Storage::disk('local')->allFiles('deca'));
    }

    public function test_historical_sent_dispatch_is_not_issued_retroactively(): void
    {
        $dispatch = $this->dispatch();
        $dispatch->update(['status' => GoodsDispatch::STATUS_SENT, 'sent_at' => now()->subDays(3), 'stock_applied_at' => now(), 'transport_data' => null]);
        app(GoodsDispatchWorkflowService::class)->changeStatus($dispatch, GoodsDispatch::STATUS_COMPLETED, $this->operator);
        $this->assertDatabaseCount('deca_documents', 0);
    }

    public function test_issued_document_blocks_even_superadmin_loading_corrections(): void
    {
        $dispatch = $this->dispatch();
        $this->send($dispatch);
        $admin = User::factory()->create(['role_id' => Role::where('slug', Role::SUPERADMIN)->value('id')]);
        $this->expectException(ValidationException::class);
        app(GoodsDispatchWorkflowService::class)->confirmLoading($dispatch->fresh(), [], $admin);
    }

    public function test_draft_normalizes_weight_and_plate_but_does_not_issue_a_document(): void
    {
        $dispatch = $this->dispatch();
        app(DispatchDecaService::class)->saveDraft($dispatch, ['weight_kg' => '1234,500', 'tractor_plate' => ' 4567abc ']);
        $this->assertSame('1234.500', $dispatch->fresh()->transport_data['weight_kg']);
        $this->assertSame('4567ABC', $dispatch->fresh()->transport_data['tractor_plate']);
        $this->assertDatabaseCount('deca_documents', 0);
        $this->actingAs($this->operator)->get(route('dispatches.show', $dispatch))->assertOk()->assertSee('DECA de esta salida');
    }

    public function test_http_confirmation_saves_transport_and_generates_deca(): void
    {
        Bus::fake();
        $dispatch = $this->dispatch();
        $line = $dispatch->lines->first();
        $this->actingAs($this->operator)->patch(route('dispatches.confirm-loading', $dispatch), [
            'finalize_dispatch' => '1',
            'transport' => array_replace($dispatch->transport_data, ['weight_kg' => '2222,500', 'tractor_plate' => ' 5678def ']),
            'lines' => [['line_id' => $line->id, 'loaded_quantity' => 2]],
        ])->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame('2222.500', $dispatch->fresh()->decaDocument->snapshot['weight_kg']);
        $this->assertSame('5678DEF', $dispatch->fresh()->decaDocument->snapshot['tractor_plate']);
        $this->assertSame(GoodsDispatch::STATUS_SENT, $dispatch->fresh()->status);
    }

    public function test_partial_loads_of_one_order_have_distinct_documents(): void
    {
        Bus::fake();
        $first = $this->dispatch();
        $request = MerchandiseRequest::factory()->create(['client_id' => $first->client_id, 'status' => MerchandiseRequest::STATUS_PREPARING]);
        $line = $first->lines->first();
        $requestLine = $request->lines()->create(['item_id' => $line->item_id, 'sku' => $line->sku, 'description' => $line->description, 'requested_units' => 400, 'requested_pallets' => 4, 'units_per_pallet' => 100]);
        $first->update(['merchandise_request_id' => $request->id, 'shipment_sequence' => 1]);
        $line->update(['source_request_line_id' => $requestLine->id]);
        app(GoodsDispatchWorkflowService::class)->changeStatus($first->fresh(), GoodsDispatch::STATUS_SENT, $this->operator, 'leave_pending');
        $second = GoodsDispatch::factory()->create([
            'client_id' => $first->client_id, 'merchandise_request_id' => $request->id, 'shipment_sequence' => 2,
            'status' => GoodsDispatch::STATUS_PREPARING, 'delivery_address_override' => true, 'delivery_address_text' => 'Otro destino de prueba',
            'transport_data' => array_replace($first->transport_data, ['tractor_plate' => '9876XYZ', 'weight_kg' => '900']),
        ]);
        $secondLine = $line->replicate();
        $secondLine->goods_dispatch_id = $second->id;
        $secondLine->save();
        $this->send($second);
        $this->assertDatabaseCount('deca_documents', 2);
        $this->assertNotSame($first->fresh()->deca_document_id, $second->fresh()->deca_document_id);
        $this->assertSame('9876XYZ', $second->fresh()->decaDocument->snapshot['tractor_plate']);
        $this->assertSame('1234ABC', $first->fresh()->decaDocument->snapshot['tractor_plate']);
    }

    public function test_missing_fiscal_configuration_blocks_emission(): void
    {
        config(['deca.dispatch.shipper_tax_id' => null]);
        $dispatch = $this->dispatch();
        $this->actingAs($this->operator)->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertSessionHasErrors('transport');
        $this->assertDatabaseCount('deca_documents', 0);
        $this->assertNull($dispatch->fresh()->stock_applied_at);
    }

    public function test_invalid_weight_or_date_is_rejected_and_fixed_shipper_cannot_be_spoofed(): void
    {
        $dispatch = $this->dispatch(['weight_kg' => '-1', 'transport_date' => now('Europe/Madrid')->subDay()->toDateString()]);
        $this->actingAs($this->operator)->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_SENT])
            ->assertSessionHasErrors(['transport.weight_kg', 'transport.transport_date']);
        app(DispatchDecaService::class)->saveDraft($dispatch->fresh(), [
            'weight_kg' => '100', 'transport_date' => now('Europe/Madrid')->toDateString(),
            'shipper_name' => 'Spoofed', 'shipper_tax_id' => 'Spoofed', 'origin' => 'Spoofed',
        ]);
        $this->send($dispatch->fresh());
        $this->assertSame('MÁXIMO SERVICIOS LOGÍSTICOS S.L.U.', $dispatch->fresh()->decaDocument->snapshot['shipper_name']);
        $this->assertSame(config('deca.dispatch.origin'), $dispatch->fresh()->decaDocument->snapshot['origin']);
    }

    public function test_client_can_download_own_albaran_but_not_another_clients_and_qr_is_public(): void
    {
        $dispatch = $this->dispatch();
        $this->send($dispatch);
        $clientUser = User::factory()->create(['role_id' => Role::where('slug', Role::CLIENTE)->value('id'), 'client_id' => $dispatch->client_id]);
        $this->actingAs($clientUser)->get(route('client-goods-receipts.dispatches.download', $dispatch))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('dispatches.show', $dispatch))->assertForbidden();
        $this->patch(route('dispatches.update-status', $dispatch), ['status' => GoodsDispatch::STATUS_COMPLETED])->assertForbidden();
        $other = $this->dispatch();
        $this->get(route('client-goods-receipts.dispatches.download', $other))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $document = $dispatch->fresh()->decaDocument;
        $this->get($document->public_url)->assertOk()->assertContent(Storage::disk('local')->get($document->pdf_path));
        $this->get(route('deca.public-download', ['token' => str_repeat('0', 64)]))->assertNotFound();
    }

    public function test_issued_transport_cannot_be_overwritten_and_plain_drafts_can_be_saved(): void
    {
        $dispatch = $this->dispatch();
        $this->send($dispatch);
        $this->actingAs($this->operator)->put(route('dispatches.own-truck.update', $dispatch), [
            'camion_propio' => '0', 'delivery_address_override' => '1', 'delivery_address_text' => 'Cambio inválido',
        ])->assertSessionHasErrors('transport');
        $this->assertSame('Destino de prueba, Zaragoza', $dispatch->fresh()->delivery_address_text);
        $draft = $this->dispatch();
        app(DispatchDecaService::class)->saveDraft($draft, ['weight_kg' => '', 'tractor_plate' => '']);
        $this->assertNull($draft->fresh()->deca_document_id);
    }

    public function test_failed_http_emission_rolls_back_loading_and_transport_together(): void
    {
        Bus::fake();
        $dispatch = $this->dispatch();
        $line = $dispatch->lines->first();
        $this->actingAs($this->operator)->patch(route('dispatches.confirm-loading', $dispatch), [
            'finalize_dispatch' => '1',
            'transport' => ['weight_kg' => '', 'tractor_plate' => '9999XYZ'],
            'lines' => [['line_id' => $line->id, 'loaded_quantity' => 3]],
        ])->assertSessionHasErrors('transport.weight_kg');
        $this->assertSame(2, $line->fresh()->loaded_pallets);
        $this->assertSame('1234ABC', $dispatch->fresh()->transport_data['tractor_plate']);
        $this->assertDatabaseCount('deca_documents', 0);
    }

    public function test_cleanup_does_not_delete_a_committed_document(): void
    {
        $dispatch = $this->dispatch();
        $this->send($dispatch);
        $document = $dispatch->fresh()->decaDocument;
        app(DispatchDecaService::class)->discardRolledBackDocument($document);
        Storage::disk('local')->assertExists($document->pdf_path);
    }

    public function test_existing_external_choice_is_preserved_in_deca_form(): void
    {
        $dispatch = $this->dispatch();
        $dispatch->update(['transport_data' => null, 'camion_propio' => false]);
        $this->assertSame('external', app(DispatchDecaService::class)->defaults($dispatch)['carrier_key']);
    }
}
