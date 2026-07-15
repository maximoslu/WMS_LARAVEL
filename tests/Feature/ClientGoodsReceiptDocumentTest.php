<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\ClientReceiptEmailRecipient;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\GoodsReceipt;
use App\Models\MerchandiseRequest;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Notifications\ClientGoodsReceiptDocumentAvailableNotification;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
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
            ->assertSee('ALBARANES')
            ->assertDontSee('Mis albaranes')
            ->assertSee(route('client-goods-receipts.index'), false);
    }

    public function test_dashboard_cliente_muestra_albaranes_una_sola_vez_en_el_contenido_principal(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();

        // Scoped to <main>...</main>: the persistent sidebar drawer (outside
        // <main>) also lists ALBARANES as a normal nav link and is not part
        // of this duplication check.
        $mainContent = preg_match('/<main.*?<\/main>/s', $response->getContent(), $matches) === 1
            ? $matches[0]
            : '';

        $this->assertNotSame('', $mainContent);
        $this->assertSame(1, substr_count($mainContent, 'ALBARANES'));
    }

    public function test_dashboard_cliente_mantiene_acceso_a_albaranes_dentro_de_operaciones(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('Operaciones')
            ->assertSee('ALBARANES')
            ->assertSee(route('client-goods-receipts.index'), false);
    }

    public function test_dashboard_cliente_no_muestra_bloque_duplicado_independiente_de_albaranes(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('dashboard-mis-albaranes-card', false);
    }

    public function test_almacen_no_ve_enlace_mis_albaranes_en_dashboard(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertDontSee('Mis albaranes')
            ->assertDontSee(route('client-goods-receipts.index'), false);
    }

    public function test_cliente_accede_a_mis_albaranes(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertSee('ALBARANES')
            ->assertSee('ALBARANES DE ENTRADA')
            ->assertSee('ALBARANES DE SALIDA')
            ->assertDontSee('Mis albaranes');
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
            ->assertSee('Sin albaranes.');
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

    public function test_cliente_ve_albaranes_de_salida_de_su_cliente_si_existen(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->createDispatchWithDeliveryNote($edelvives, '2026-07-17', 'SAL-EDELVIVES-001', 'Destino Edelvives');

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertSee('ALBARANES DE SALIDA')
            ->assertSee('Salida_Edelvives_17')
            ->assertSee('SAL-EDELVIVES-001')
            ->assertSee('Destino Edelvives');
    }

    public function test_cliente_no_ve_albaranes_de_salida_de_otro_cliente(): void
    {
        [$edelvives, $friesland] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->createDispatchWithDeliveryNote($friesland, '2026-07-17', 'SAL-FRIESLAND-001', 'Destino Friesland');

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertDontSee('SAL-FRIESLAND-001')
            ->assertDontSee('Destino Friesland')
            ->assertSee('Sin albaranes.');
    }

    public function test_cliente_puede_descargar_albaran_de_su_salida(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $dispatch = $this->createDispatchWithDeliveryNote($edelvives, '2026-07-17');

        $this->actingAs($user)
            ->get(route('client-goods-receipts.dispatches.download', $dispatch))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_cliente_no_puede_descargar_albaran_de_salida_de_otro_cliente(): void
    {
        [$edelvives, $friesland] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $dispatch = $this->createDispatchWithDeliveryNote($friesland, '2026-07-17');

        $this->actingAs($user)
            ->get(route('client-goods-receipts.dispatches.download', $dispatch))
            ->assertForbidden();
    }

    public function test_estado_vacio_sin_documentos(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($user)
            ->get(route('client-goods-receipts.index'))
            ->assertOk()
            ->assertSee('Sin albaranes.');
    }

    public function test_filtra_por_mes(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');
        $this->createReceiptWithDocument($edelvives, 'LECTA', '2026-06-10');
        $this->createDispatchWithDeliveryNote($edelvives, '2026-07-18', 'SAL-JULIO-001');
        $this->createDispatchWithDeliveryNote($edelvives, '2026-06-18', 'SAL-JUNIO-001');

        $response = $this->actingAs($user)
            ->get(route('client-goods-receipts.index', ['month' => '2026-07']));

        $response->assertOk();
        $response->assertSee('Entrada_Saica_17');
        $response->assertDontSee('Entrada_Lecta_10');
        $response->assertSee('SAL-JULIO-001');
        $response->assertDontSee('SAL-JUNIO-001');
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
        $this->createDispatchWithDeliveryNote($edelvives, '2026-07-18', 'SAL-SAICA-001', 'Destino Saica');
        $this->createDispatchWithDeliveryNote($edelvives, '2026-07-18', 'SAL-LECTA-001', 'Destino Lecta');

        $response = $this->actingAs($user)
            ->get(route('client-goods-receipts.index', ['search' => 'saica']));

        $response->assertOk();
        $response->assertSee('Entrada_Saica_17');
        $response->assertDontSee('Entrada_Lecta_10');
        $response->assertSee('SAL-SAICA-001');
        $response->assertDontSee('SAL-LECTA-001');
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

    public function test_email_link_signed_allows_the_recipient_to_download_without_session(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');
        $message = (new ClientGoodsReceiptDocumentAvailableNotification($receipt, ['mail']))->toMail($user);

        $this->assertIsString($message->actionUrl);
        $this->assertStringContainsString('signature=', $message->actionUrl);
        $this->get($message->actionUrl)
            ->assertOk()
            ->assertHeader('content-disposition', 'attachment; filename=Entrada_Saica_17.pdf');
    }

    public function test_unsigned_or_expired_email_document_link_is_forbidden(): void
    {
        [$edelvives] = $this->seedClients();
        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');

        $this->get(route('client-goods-receipts.signed-download', $receipt))->assertForbidden();

        $expiredUrl = URL::temporarySignedRoute(
            'client-goods-receipts.signed-download',
            now()->subMinute(),
            ['goodsReceipt' => $receipt],
        );
        $this->get($expiredUrl)->assertForbidden();
    }

    public function test_authenticated_other_client_cannot_use_signed_document_link(): void
    {
        [$edelvives, $friesland] = $this->seedClients();
        $otherClientUser = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');
        $signedUrl = URL::temporarySignedRoute(
            'client-goods-receipts.signed-download',
            now()->addDays(15),
            ['goodsReceipt' => $receipt],
        );

        $this->actingAs($otherClientUser)->get($signedUrl)->assertForbidden();
    }

    public function test_signed_link_for_missing_document_returns_404(): void
    {
        [$edelvives] = $this->seedClients();
        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $edelvives->id,
            'document_path' => null,
        ]);
        $signedUrl = URL::temporarySignedRoute(
            'client-goods-receipts.signed-download',
            now()->addDays(15),
            ['goodsReceipt' => $receipt],
        );

        $this->get($signedUrl)->assertNotFound();
    }

    public function test_external_recipient_email_keeps_private_attachment_and_signed_link(): void
    {
        [$edelvives] = $this->seedClients();
        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');
        $message = (new ClientGoodsReceiptDocumentAvailableNotification($receipt, ['mail']))
            ->toMail(new AnonymousNotifiable);

        $this->assertStringContainsString('signature=', (string) $message->actionUrl);
        $this->assertNotEmpty($message->rawAttachments);
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

    public function test_entrada_edelvives_con_documento_notifica_usuarios_y_emails_adicionales_edelvives(): void
    {
        Notification::fake();
        [$edelvives] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $clienteUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        ClientReceiptEmailRecipient::factory()->create([
            'client_id' => $edelvives->id,
            'email' => 'administracion@edelvives-externo.com',
        ]);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.store'), $this->storePayload($edelvives, 'SAICA'))
            ->assertRedirect();

        Notification::assertSentTo($clienteUser, ClientGoodsReceiptDocumentAvailableNotification::class);
        Notification::assertSentOnDemand(
            ClientGoodsReceiptDocumentAvailableNotification::class,
            fn ($notification, array $channels, $notifiable): bool => $notifiable->routeNotificationFor('mail', $notification) === 'administracion@edelvives-externo.com'
        );
    }

    public function test_emails_adicionales_de_friesland_no_reciben_aviso_de_entrada_edelvives(): void
    {
        Notification::fake();
        [$edelvives, $friesland] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        ClientReceiptEmailRecipient::factory()->create([
            'client_id' => $friesland->id,
            'email' => 'administracion@friesland-externo.com',
        ]);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.store'), $this->storePayload($edelvives, 'SAICA'))
            ->assertRedirect();

        Notification::assertSentToTimes(new AnonymousNotifiable, ClientGoodsReceiptDocumentAvailableNotification::class, 0);
    }

    public function test_email_adicional_que_coincide_con_usuario_cliente_no_duplica_envio(): void
    {
        Notification::fake();
        [$edelvives] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $clienteUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        ClientReceiptEmailRecipient::factory()->create([
            'client_id' => $edelvives->id,
            'email' => mb_strtoupper($clienteUser->email),
        ]);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.store'), $this->storePayload($edelvives, 'SAICA'))
            ->assertRedirect();

        Notification::assertSentToTimes($clienteUser, ClientGoodsReceiptDocumentAvailableNotification::class, 1);
        Notification::assertSentToTimes(new AnonymousNotifiable, ClientGoodsReceiptDocumentAvailableNotification::class, 0);
    }

    public function test_sustituir_documento_vuelve_a_notificar_emails_adicionales(): void
    {
        Notification::fake();
        [$edelvives] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        ClientReceiptEmailRecipient::factory()->create([
            'client_id' => $edelvives->id,
            'email' => 'administracion@edelvives-externo.com',
        ]);

        $receipt = $this->createReceiptWithDocument($edelvives, 'SAICA', '2026-07-17');

        Notification::assertNothingSent();

        $this->actingAs($almacen)
            ->post(route('goods-receipts.attach-document', $receipt), [
                'document' => UploadedFile::fake()->create('nuevo.pdf', 50, 'application/pdf'),
            ])
            ->assertRedirect();

        Notification::assertSentOnDemand(
            ClientGoodsReceiptDocumentAvailableNotification::class,
            fn ($notification, array $channels, $notifiable): bool => $notifiable->routeNotificationFor('mail', $notification) === 'administracion@edelvives-externo.com'
        );
    }

    public function test_crear_entrada_sin_documento_no_envia_aviso_de_albaran(): void
    {
        Notification::fake();
        [$edelvives] = $this->seedClients();

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        ClientReceiptEmailRecipient::factory()->create([
            'client_id' => $edelvives->id,
            'email' => 'administracion@edelvives-externo.com',
        ]);

        $payload = $this->storePayload($edelvives, 'SAICA');
        unset($payload['document']);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.store'), $payload)
            ->assertRedirect();

        Notification::assertNothingSent();
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

    private function createDispatchWithDeliveryNote(
        Client $client,
        string $sentAt,
        ?string $dispatchNumber = null,
        ?string $deliveryReference = null,
    ): GoodsDispatch {
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $merchandiseRequest = MerchandiseRequest::factory()->create([
            'client_id' => $client->id,
            'requested_by' => $cliente->id,
            'status' => MerchandiseRequest::STATUS_SENT,
            'delivery_reference' => $deliveryReference,
            'delivery_address' => $deliveryReference,
        ]);

        $dispatch = GoodsDispatch::factory()->create([
            'dispatch_number' => $dispatchNumber,
            'client_id' => $client->id,
            'merchandise_request_id' => $merchandiseRequest->id,
            'type' => GoodsDispatch::TYPE_REQUEST,
            'status' => GoodsDispatch::STATUS_SENT,
            'created_by' => $almacen->id,
            'sent_at' => $sentAt,
        ]);

        GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'requested_pallets' => 2,
            'requested_peaks' => 0,
            'loaded_pallets' => 2,
            'loaded_peaks' => 0,
            'confirmed_at' => $sentAt,
            'confirmed_by' => $almacen->id,
        ]);

        return $dispatch->fresh(['client', 'merchandiseRequest', 'lines']);
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
