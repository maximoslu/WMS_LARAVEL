<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class OldDocumentCleanupTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_ve_el_bloque_de_limpieza_de_archivos_antiguos(): void
    {
        $this->seed(RoleSeeder::class);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($superadmin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertSee('Limpieza de archivos antiguos')
            ->assertSee('Limpiar archivos de más de 12 meses');
    }

    public function test_administracion_no_ve_el_bloque_de_limpieza_de_archivos(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($admin)
            ->get(route('audit.index'))
            ->assertOk()
            ->assertDontSee('Limpieza de archivos antiguos');
    }

    public function test_administracion_no_puede_ejecutar_la_limpieza_de_archivos(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($admin)
            ->post(route('audit.documents-cleanup.execute'))
            ->assertForbidden();
    }

    public function test_almacen_no_puede_acceder_ni_ejecutar_la_limpieza(): void
    {
        $this->seed(RoleSeeder::class);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->get(route('audit.index'))
            ->assertForbidden();

        $this->actingAs($almacen)
            ->post(route('audit.documents-cleanup.execute'))
            ->assertForbidden();
    }

    public function test_cliente_no_puede_acceder_ni_ejecutar_la_limpieza(): void
    {
        $this->seed(RoleSeeder::class);
        $cliente = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($cliente)
            ->get(route('audit.index'))
            ->assertForbidden();

        $this->actingAs($cliente)
            ->post(route('audit.documents-cleanup.execute'))
            ->assertForbidden();
    }

    public function test_resumen_muestra_solo_candidatos_de_mas_de_12_meses(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->createReceiptWithDocument(now()->subMonths(13), 'antiguo.pdf');
        $this->createReceiptWithDocument(now()->subMonths(1), 'reciente.pdf');

        $response = $this->actingAs($superadmin)->get(route('audit.index'));

        $response->assertOk();
        $response->assertSee('1 candidatos', false);
    }

    public function test_ejecutar_limpieza_elimina_fisicamente_el_archivo_antiguo(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $receipt = $this->createReceiptWithDocument(now()->subMonths(13), 'antiguo.pdf');

        $this->actingAs($superadmin)
            ->post(route('audit.documents-cleanup.execute'))
            ->assertRedirect(route('audit.index'));

        $this->assertFalse(Storage::disk('local')->exists('goods-receipts/antiguo-'.$receipt->id.'.pdf'));

        $receipt->refresh();
        $this->assertNull($receipt->document_path);
        $this->assertNull($receipt->document_mime);
    }

    public function test_la_entrada_las_lineas_y_el_stock_se_conservan_tras_la_limpieza(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $receipt = $this->createReceiptWithDocument(now()->subMonths(13), 'antiguo.pdf');
        $line = GoodsReceiptLine::factory()->create(['goods_receipt_id' => $receipt->id]);
        $pallet = StockPallet::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'quantity_units' => 500,
        ]);

        $this->actingAs($superadmin)
            ->post(route('audit.documents-cleanup.execute'))
            ->assertRedirect(route('audit.index'));

        $this->assertDatabaseHas('goods_receipts', ['id' => $receipt->id]);
        $this->assertDatabaseHas('goods_receipt_lines', ['id' => $line->id]);
        $this->assertDatabaseHas('stock_pallets', [
            'id' => $pallet->id,
            'quantity_units' => 500,
        ]);
        $this->assertNotNull($receipt->fresh()->document_original_name);
    }

    public function test_archivo_reciente_no_se_elimina(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $receipt = $this->createReceiptWithDocument(now()->subMonths(1), 'reciente.pdf');

        $this->actingAs($superadmin)
            ->post(route('audit.documents-cleanup.execute'))
            ->assertRedirect(route('audit.index'));

        $receipt->refresh();
        $this->assertNotNull($receipt->document_path);
        $this->assertTrue(Storage::disk('local')->exists($receipt->document_path));
    }

    public function test_no_se_pueden_pasar_rutas_arbitrarias_a_la_ejecucion(): void
    {
        Storage::fake('local');
        Storage::disk('local')->put('goods-receipts/no-referenciado.pdf', 'contenido ajeno');

        $this->seed(RoleSeeder::class);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $receipt = $this->createReceiptWithDocument(now()->subMonths(13), 'antiguo.pdf');

        $this->actingAs($superadmin)
            ->post(route('audit.documents-cleanup.execute'), [
                'path' => '../../.env',
                'document_path' => 'goods-receipts/no-referenciado.pdf',
            ])
            ->assertRedirect(route('audit.index'));

        // Only the DB-known candidate is touched; the unrelated file on disk
        // (not referenced by any receipt) must survive regardless of request input.
        $this->assertTrue(Storage::disk('local')->exists('goods-receipts/no-referenciado.pdf'));
        $this->assertFalse(Storage::disk('local')->exists('goods-receipts/antiguo-'.$receipt->id.'.pdf'));
    }

    public function test_referencia_a_archivo_inexistente_en_disco_se_sanea_sin_romper_el_proceso(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $receipt = GoodsReceipt::factory()->create([
            'received_at' => now()->subMonths(13),
            'document_path' => 'goods-receipts/fantasma.pdf',
            'document_original_name' => 'fantasma.pdf',
            'document_mime' => 'application/pdf',
        ]);

        $response = $this->actingAs($superadmin)
            ->post(route('audit.documents-cleanup.execute'));

        $response->assertRedirect(route('audit.index'));
        $response->assertSessionHas('status');

        $receipt->refresh();
        $this->assertNull($receipt->document_path);
        $this->assertNull($receipt->document_mime);
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }

    private function createReceiptWithDocument(\Illuminate\Support\Carbon $receivedAt, string $originalName): GoodsReceipt
    {
        $client = Client::factory()->create();
        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'received_at' => $receivedAt,
        ]);

        $path = 'goods-receipts/'.pathinfo($originalName, PATHINFO_FILENAME).'-'.$receipt->id.'.pdf';
        Storage::disk('local')->put($path, 'contenido de prueba');

        $receipt->update([
            'document_path' => $path,
            'document_original_name' => $originalName,
            'document_mime' => 'application/pdf',
        ]);

        return $receipt->fresh();
    }
}
