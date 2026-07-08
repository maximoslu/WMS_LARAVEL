<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\ClientGoodsReceiptDocumentAvailableNotification;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ClientGoodsReceiptDocumentTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_ve_enlace_mis_albaranes_en_dashboard(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Mis albaranes')
            ->assertSee(route('client-goods-receipts.index'), false);
    }

    public function test_almacen_no_ve_enlace_mis_albaranes_en_dashboard(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Mis albaranes');
    }

    public function test_cliente_accede_a_mis_albaranes(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertSee('Albaranes de entrada');
    }

    public function test_almacen_no_puede_acceder_a_mis_albaranes(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertForbidden();
    }

    public function test_cliente_ve_documentos_de_su_propio_cliente(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertSee('SAICA')
            ->assertSee('Entrada_Saica_17');
    }

    public function test_cliente_no_ve_documentos_de_otro_cliente(): void
    {
        [$edelvives, $friesland] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->createReceiptWithDocument($friesland, 'MONDI', '2026-07-05');

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertDontSee('MONDI')
            ->assertSee('No hay albaranes disponibles para este periodo.');
    }

    public function test_cliente_no_puede_descargar_documento_de_otro_cliente(): void
    {
        [$edelvives, $friesland] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $otherReceipt = $this->createReceiptWithDocument($friesland, 'MONDI', '2026-07-05');

        $this->actingAs($user)
            ->get(route('client-goods-receipts.download', $otherReceipt))
            ->assertForbidden();
    }

    public function test_estado_vacio_sin_documentos(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertSee('No hay albaranes disponibles para este periodo.');
    }

    public function test_filtra_por_mes(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');
        $this->createReceiptWithDocument($edelvives, 'LECTA', '2026-06-10');

        $response = $this->actingAs($user)
            ->get(route('client-goods-receipts.index', ['month' => '2026-07']));

        $response->assertOk();
        $response->assertSee('Entrada_Saica_17');
        $response->assertDontSee('Entrada_Lecta_10');
    }

    public function test_filtra_por_proveedor(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $saica = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');
        $this->createReceiptWithDocument($edelvives, 'LECTA', '2026-07-10');

        $response = $this->actingAs($user)
            ->get(route('client-goods-receipts.index', ['supplier_id' => $saica->supplier_id]));

        $response->assertOk();
        $response->assertSee('Entrada_Saica_17');
        $response->assertDontSee('Entrada_Lecta_10');
    }

    public function test_busqueda_por_proveedor_documento_o_albaran_funciona(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');
        $this->createReceiptWithDocument($edelvives, 'LECTA', '2026-07-10');

        $response = $this->actingAs($user)
            ->get(route('client-goods-receipts.index', ['search' => 'saica']));

        $response->assertOk();
        $response->assertSee('Entrada_Saica_17');
        $response->assertDontSee('Entrada_Lecta_10');
    }

    public function test_cliente_puede_descargar_documento_de_su_entrada(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');

        $response = $this->actingAs($user)
            ->get(route('client-goods-receipts.download', $receipt));

        $response->assertOk();
        $response->assertHeader(
            'content-disposition',
            'attachment; filename=Entrada_Saica_17.pdf'
        );
    }

    public function test_documento_se_sirve_desde_storage_privado(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');

        $this->assertTrue(Storage::disk('local')->exists($receipt->document_path));
        $this->assertFalse(Storage::disk('public')->exists($receipt->document_path));

        $this->actingAs($user)
            ->get(route('client-goods-receipts.download', $receipt))
            ->assertOk();
    }

    public function test_no_expone_path_real_de_storage_en_el_listado(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertDontSee($receipt->document_path);
    }

    public function test_dos_entradas_mismo_proveedor_mismo_dia_se_desambiguan_con_id(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $first = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');
        $second = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');

        $response = $this->actingAs($user)->get(route('client-goods-receipts.index'));

        $response->assertOk();
        $response->assertSee('Entrada_Saica_17_Entrada'.$first->id);
        $response->assertSee('Entrada_Saica_17_Entrada'.$second->id);
    }

    public function test_crear_entrada_con_documento_envia_correo_a_usuarios_cliente_asignados(): void
    {
        Notification::fake();
        [$edelvives] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $clienteUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.store'), $this->storePayload($edelvives, 'SAICA'))
            ->assertRedirect();

        Notification::assertSentTo(
            $clienteUser,
            ClientGoodsReceiptDocumentAvailableNotification::class
        );
    }

    public function test_entrada_edelvives_solo_notifica_a_usuarios_cliente_edelvives(): void
    {
        Notification::fake();
        [$edelvives, $friesland] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $edelvivesUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $frieslandUser = $this->makeUserWithRole(Role::CLIENTE, $friesland);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.store'), $this->storePayload($edelvives, 'SAICA'))
            ->assertRedirect();

        Notification::assertSentTo($edelvivesUser, ClientGoodsReceiptDocumentAvailableNotification::class);
        Notification::assertNotSentTo($frieslandUser, ClientGoodsReceiptDocumentAvailableNotification::class);
    }

    public function test_entrada_friesland_solo_notifica_a_usuarios_cliente_friesland(): void
    {
        Notification::fake();
        [$edelvives, $friesland] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $edelvivesUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $frieslandUser = $this->makeUserWithRole(Role::CLIENTE, $friesland);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.store'), $this->storePayload($friesland, 'MONDI'))
            ->assertRedirect();

        Notification::assertSentTo($frieslandUser, ClientGoodsReceiptDocumentAvailableNotification::class);
        Notification::assertNotSentTo($edelvivesUser, ClientGoodsReceiptDocumentAvailableNotification::class);
    }

    public function test_no_se_envia_a_usuarios_inactivos(): void
    {
        Notification::fake();
        [$edelvives] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $inactiveUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $inactiveUser->update(['active' => false]);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.store'), $this->storePayload($edelvives, 'SAICA'))
            ->assertRedirect();

        Notification::assertNotSentTo($inactiveUser, ClientGoodsReceiptDocumentAvailableNotification::class);
    }

    public function test_usuario_sin_email_valido_no_recibe_canal_mail(): void
    {
        Notification::fake();
        [$edelvives] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $userWithoutEmail = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $userWithoutEmail->forceFill(['email' => 'not-an-email'])->save();

        $this->actingAs($almacen)
            ->post(route('goods-receipts.store'), $this->storePayload($edelvives, 'SAICA'))
            ->assertRedirect();

        Notification::assertSentTo(
            $userWithoutEmail,
            ClientGoodsReceiptDocumentAvailableNotification::class,
            fn ($notification, array $channels): bool => ! in_array('mail', $channels, true)
        );
    }

    public function test_sustituir_documento_envia_aviso_del_nuevo_documento(): void
    {
        Notification::fake();
        [$edelvives] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $clienteUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');

        Notification::assertNothingSent();

        $this->actingAs($almacen)
            ->post(route('goods-receipts.attach-document', $receipt), [
                'document' => UploadedFile::fake()->create('nuevo.pdf', 50, 'application/pdf'),
            ])
            ->assertRedirect();

        Notification::assertSentTo($clienteUser, ClientGoodsReceiptDocumentAvailableNotification::class);
    }

    public function test_cliente_sin_client_id_no_ve_documentos_y_recibe_mensaje_claro(): void
    {
        $this->seed(RoleSeeder::class);
        $roleId = Role::query()->where('slug', Role::CLIENTE)->value('id');
        $user = User::factory()->create(['role_id' => $roleId, 'client_id' => null]);

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertSee('Tu usuario no tiene un cliente asignado.');
    }

    public function test_administracion_no_puede_acceder_a_mis_albaranes(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertForbidden();
    }

    public function test_superadmin_sigue_gestionando_entradas_con_documento_como_antes(): void
    {
        $this->seed(RoleSeeder::class);
        [$edelvives] = $this->seedClients();
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');

        $this->actingAs($superadmin)
            ->get(route('goods-receipts.document', $receipt))
            ->assertOk();
    }

    /**
     * @return array{0: Client, 1: Client}
     */
    private function seedClients(): array
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

    private function createReceiptWithDocument(Client $client, string $supplierName, string $receivedAt): GoodsReceipt
    {
        Storage::fake('local');

        $supplier = Supplier::factory()->create([
            'client_id' => $client->id,
            'name' => $supplierName,
        ]);

        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'received_at' => $receivedAt,
        ]);

        $path = 'goods-receipts/'.$receipt->id.'-albaran.pdf';
        Storage::disk('local')->put($path, 'contenido de prueba');

        $receipt->update([
            'document_path' => $path,
            'document_original_name' => 'albaran.pdf',
            'document_mime' => 'application/pdf',
        ]);

        return $receipt->fresh();
    }

    /**
     * @return array<string, mixed>
     */
    private function storePayload(Client $client, string $supplierName): array
    {
        $supplier = Supplier::factory()->create([
            'client_id' => $client->id,
            'name' => $supplierName,
        ]);

        return [
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'receipt_number' => 'ALB-NOTIF-001',
            'received_at' => '2026-07-17',
            'document' => UploadedFile::fake()->create('albaran.pdf', 50, 'application/pdf'),
            'lines' => [
                [
                    'item_id' => '',
                    'sku' => 'SKU-NOTIF-001',
                    'description' => 'Articulo para prueba de notificacion',
                    'lot' => 'LOT-NOTIF',
                    'quantity_units' => 100,
                    'units_per_pallet' => 100,
                    'pallet_count' => 1,
                    'pico_units' => '',
                    'location_id' => '',
                ],
            ],
        ];
    }
}
