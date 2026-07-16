<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsReceipt;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class DeliveryNoteManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_roles_autorizados_acceden_a_gestion_de_albaranes(): void
    {
        $this->seedBaseData();

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION] as $roleSlug) {
            $this->actingAs($this->makeUserWithRole($roleSlug))
                ->get(route('delivery-notes.management.index'))
                ->assertOk()
                ->assertSee('Albaranes')
                ->assertSee('Selecciona un cliente para buscar albaranes');
        }
    }

    public function test_roles_no_autorizados_no_acceden_a_gestion_de_albaranes(): void
    {
        $this->seedBaseData();
        $client = Client::query()->where('code', 'EDELVIVES')->firstOrFail();

        foreach ([Role::ALMACEN, Role::CLIENTE] as $roleSlug) {
            $this->actingAs($this->makeUserWithRole($roleSlug, $roleSlug === Role::CLIENTE ? $client : null))
                ->get(route('delivery-notes.management.index'))
                ->assertForbidden();
        }
    }

    public function test_sin_cliente_no_carga_documentos_masivos(): void
    {
        [$edelvives] = $this->seedBaseData();
        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-10', 'DOC-OCULTO');
        $this->createDispatchWithDeliveryNote($edelvives, '2026-07-11', 'SAL-OCULTO');

        $this->actingAs($admin)
            ->get(route('delivery-notes.management.index'))
            ->assertOk()
            ->assertSee('0 documentos')
            ->assertSee('No se cargan documentos de todos los clientes sin criterio.')
            ->assertDontSee('DOC-OCULTO')
            ->assertDontSee('SAL-OCULTO');
    }

    public function test_filtro_por_cliente_aisla_documentos_y_muestra_acciones(): void
    {
        [$edelvives, $friesland] = $this->seedBaseData();
        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);

        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-10', 'DOC-EDELVIVES');
        $dispatch = $this->createDispatchWithDeliveryNote($edelvives, '2026-07-11', 'SAL-EDELVIVES', 'Destino Edelvives');
        $otherReceipt = $this->createReceiptWithDocument($friesland, 'MONDI', '2026-07-12', 'DOC-FRIESLAND');
        $otherDispatch = $this->createDispatchWithDeliveryNote($friesland, '2026-07-13', 'SAL-FRIESLAND');

        $this->actingAs($admin)
            ->get(route('delivery-notes.management.index', ['client_id' => $edelvives->id]))
            ->assertOk()
            ->assertSee('2 documentos')
            ->assertSee('DOC-EDELVIVES')
            ->assertSee('SAL-EDELVIVES')
            ->assertSee('Destino Edelvives')
            ->assertSee(route('goods-receipts.document', $receipt), false)
            ->assertSee(route('dispatches.delivery-note', $dispatch), false)
            ->assertSee(route('goods-receipts.show', $receipt), false)
            ->assertSee(route('dispatches.show', $dispatch), false)
            ->assertDontSee('DOC-FRIESLAND')
            ->assertDontSee('SAL-FRIESLAND')
            ->assertDontSee(route('goods-receipts.document', $otherReceipt), false)
            ->assertDontSee(route('dispatches.delivery-note', $otherDispatch), false);
    }

    public function test_filtros_internos_por_tipo_fecha_proveedor_estado_y_busqueda(): void
    {
        [$edelvives] = $this->seedBaseData();
        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);

        $saica = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-10', 'DOC-SAICA');
        $this->createReceiptWithDocument($edelvives, 'LECTA', '2026-07-15', 'DOC-LECTA');
        $this->createDispatchWithDeliveryNote($edelvives, '2026-07-11', 'SAL-ENVIADA', 'Destino Saica', GoodsDispatch::STATUS_SENT);
        $this->createDispatchWithDeliveryNote($edelvives, '2026-07-20', 'SAL-COMPLETADA', 'Destino Lecta', GoodsDispatch::STATUS_COMPLETED);

        $this->actingAs($admin)
            ->get(route('delivery-notes.management.index', [
                'client_id' => $edelvives->id,
                'type' => 'entry',
                'supplier_id' => $saica->supplier_id,
            ]))
            ->assertOk()
            ->assertSee('DOC-SAICA')
            ->assertDontSee('DOC-LECTA')
            ->assertDontSee('SAL-ENVIADA');

        $this->actingAs($admin)
            ->get(route('delivery-notes.management.index', [
                'client_id' => $edelvives->id,
                'type' => 'dispatch',
                'dispatch_status' => GoodsDispatch::STATUS_COMPLETED,
            ]))
            ->assertOk()
            ->assertSee('SAL-COMPLETADA')
            ->assertDontSee('SAL-ENVIADA')
            ->assertDontSee('DOC-SAICA');

        $this->actingAs($admin)
            ->get(route('delivery-notes.management.index', [
                'client_id' => $edelvives->id,
                'date_from' => '2026-07-10',
                'date_to' => '2026-07-11',
                'search' => 'saica',
            ]))
            ->assertOk()
            ->assertSee('DOC-SAICA')
            ->assertSee('SAL-ENVIADA')
            ->assertDontSee('DOC-LECTA')
            ->assertDontSee('SAL-COMPLETADA');
    }

    public function test_gestion_interna_pagina_veinte_documentos_y_conserva_filtros(): void
    {
        [$edelvives] = $this->seedBaseData();
        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);

        foreach (range(1, 22) as $day) {
            $this->createReceiptWithDocument(
                $edelvives,
                'SAICA '.$day,
                '2026-07-'.str_pad((string) $day, 2, '0', STR_PAD_LEFT),
                'DOC-PAG-'.str_pad((string) $day, 3, '0', STR_PAD_LEFT),
            );
        }

        $this->actingAs($admin)
            ->get(route('delivery-notes.management.index', [
                'client_id' => $edelvives->id,
                'type' => 'entry',
                'search' => 'DOC-PAG',
            ]))
            ->assertOk()
            ->assertSee('22 documentos')
            ->assertSee('DOC-PAG-022')
            ->assertDontSee('DOC-PAG-002')
            ->assertSee('client_id='.$edelvives->id, false)
            ->assertSee('type=entry', false)
            ->assertSee('search=DOC-PAG', false);

        $this->actingAs($admin)
            ->get(route('delivery-notes.management.index', [
                'client_id' => $edelvives->id,
                'type' => 'entry',
                'search' => 'DOC-PAG',
                'page' => 2,
            ]))
            ->assertOk()
            ->assertSee('DOC-PAG-002')
            ->assertSee('DOC-PAG-001')
            ->assertDontSee('DOC-PAG-022');
    }

    public function test_fecha_hasta_no_puede_ser_anterior_a_fecha_desde(): void
    {
        [$edelvives] = $this->seedBaseData();
        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($admin)
            ->from(route('delivery-notes.management.index'))
            ->get(route('delivery-notes.management.index', [
                'client_id' => $edelvives->id,
                'date_from' => '2026-07-20',
                'date_to' => '2026-07-10',
            ]))
            ->assertRedirect(route('delivery-notes.management.index'))
            ->assertSessionHasErrors('date_to');
    }

    /**
     * @return array{0: Client, 1: Client}
     */
    private function seedBaseData(): array
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);

        return [
            Client::query()->where('code', 'EDELVIVES')->firstOrFail(),
            Client::query()->where('code', 'FRIESLAND')->firstOrFail(),
        ];
    }

    private function makeUserWithRole(string $roleSlug, ?Client $client = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $client?->id,
        ]);
    }

    private function createReceiptWithDocument(
        Client $client,
        string $supplierName,
        string $receivedAt,
        string $receiptNumber,
    ): GoodsReceipt {
        Storage::fake('local');

        $supplier = Supplier::factory()->create([
            'client_id' => $client->id,
            'name' => $supplierName,
        ]);

        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'receipt_number' => $receiptNumber,
            'external_document_number' => 'EXT-'.$receiptNumber,
            'received_at' => $receivedAt,
        ]);

        $path = 'goods-receipts/'.$receipt->id.'-albaran.pdf';
        Storage::disk('local')->put($path, 'contenido de prueba');

        $receipt->update([
            'document_path' => $path,
            'document_original_name' => $receiptNumber.'.pdf',
            'document_mime' => 'application/pdf',
        ]);

        return $receipt->fresh(['client', 'supplier']);
    }

    private function createDispatchWithDeliveryNote(
        Client $client,
        string $sentAt,
        string $dispatchNumber,
        ?string $deliveryReference = null,
        string $status = GoodsDispatch::STATUS_SENT,
    ): GoodsDispatch {
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => $status,
            'delivery_reference' => $deliveryReference,
            'delivery_address' => $deliveryReference,
        ]);

        $dispatch = GoodsDispatch::factory()->create([
            'dispatch_number' => $dispatchNumber,
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'type' => GoodsDispatch::TYPE_REQUEST,
            'status' => $status,
            'created_by' => $almacen->id,
            'sent_at' => $sentAt,
            'completed_at' => $status === GoodsDispatch::STATUS_COMPLETED ? $sentAt : null,
        ]);

        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 1,
            'requested_peaks' => 0,
            'loaded_pallets' => 1,
            'loaded_peaks' => 0,
            'confirmed_at' => $sentAt,
            'confirmed_by' => $almacen->id,
        ]);

        return $dispatch->fresh(['client', 'merchandiseRequest', 'lines']);
    }
}
