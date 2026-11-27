<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\Item;
use App\Models\Role;
use App\Models\User;
use App\Services\GoodsDispatches\TiAutoWordDispatchImportService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;
use ZipArchive;

class TiAutoWordDispatchImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_single_table_word_sums_locations_into_one_reviewable_line_per_reference(): void
    {
        $this->seed(RoleSeeder::class);
        $client = Client::factory()->create(['code' => 'TIAUTO']);
        Item::factory()->create(['client_id' => $client->id, 'sku' => 'PMBE0001ACF73']);
        Item::factory()->create(['client_id' => $client->id, 'sku' => 'PMBE0015ABF73']);
        $user = $this->warehouseUser();

        $file = $this->word(
            [['Etiquetas de fila', 'POPURRI', 'R2999BDN', 'R-8046-BDG', 'Total general'],
                ['PMBE0001ACF73', '', '480', '', '471'],
                ['PMBE0015ABF73', '', '1.050', '672', '1722'],
                ['', '', '', '', '2193']],
        );
        $this->actingAs($user)->post(route('dispatches.ti-auto-word.store'), ['word' => $file])->assertRedirect();
        $dispatch = GoodsDispatch::query()->sole();

        $this->assertSame(GoodsDispatch::STATUS_DRAFT, $dispatch->status);
        $this->assertSame(2, $dispatch->lines()->count());
        $this->assertSame(2202, $dispatch->lines()->sum('requested_units'));
        $this->assertDatabaseHas('goods_dispatch_lines', ['goods_dispatch_id' => $dispatch->id, 'sku' => 'PMBE0001ACF73', 'requested_units' => 480, 'lot' => 'NO LOTE']);
        $this->assertDatabaseHas('goods_dispatch_lines', ['goods_dispatch_id' => $dispatch->id, 'sku' => 'PMBE0015ABF73', 'requested_units' => 1722, 'lot' => 'NO LOTE']);
        $this->assertStringContainsString('PMBE0001ACF73', $dispatch->notes);
        $this->assertStringContainsString('total general impreso', mb_strtolower($dispatch->notes));
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->actingAs($user)->get(route('dispatches.show', $dispatch))->assertOk()->assertSee('480 uds')->assertSee('1.722 uds');
    }

    public function test_word_creates_reviewable_draft_without_duplicating_the_lot_breakdown(): void
    {
        $this->seed(RoleSeeder::class);
        $client = Client::factory()->create(['code' => 'TIAUTO']);
        $item = Item::factory()->create(['client_id' => $client->id, 'sku' => 'PMBE0017ABF73']);
        $user = $this->warehouseUser();

        $dispatch = app(TiAutoWordDispatchImportService::class)->createDraft($this->word(
            [['Etiquetas de fila', 'POPURRI', 'R3446BDG', 'Total general'], ['PMBE0017ABF73', '49', '20', '69'], ['Total general', '49', '20', '69']],
            [['Etiquetas de fila', 'POPURRI', 'Total general'], ['PMBE0017ABF73', '49', '49'], ['TR001', '28', '28'], ['TR002', '21', '21'], ['Total general', '49', '49']],
        ), $user);

        $this->assertSame(GoodsDispatch::STATUS_DRAFT, $dispatch->status);
        $this->assertSame($client->id, $dispatch->client_id);
        $this->assertSame(69, $dispatch->lines()->sum('requested_units'));
        $this->assertSame(3, $dispatch->lines()->count());
        $this->assertDatabaseHas('goods_dispatch_lines', ['goods_dispatch_id' => $dispatch->id, 'item_id' => $item->id, 'lot' => 'TR001', 'requested_units' => 28, 'notes' => 'Columna del pedido: POPURRI']);
        $this->assertDatabaseHas('goods_dispatch_lines', ['goods_dispatch_id' => $dispatch->id, 'lot' => 'TR002', 'requested_units' => 21]);
        $this->assertDatabaseHas('goods_dispatch_lines', ['goods_dispatch_id' => $dispatch->id, 'requested_units' => 20, 'notes' => 'Columna del pedido: R3446BDG']);
        $this->assertDatabaseCount('inventory_movements', 0);

        $this->actingAs($user)->get(route('dispatches.show', $dispatch))->assertOk()->assertSee('TR001')->assertSee('Columna del pedido: POPURRI');
    }

    public function test_word_rejects_a_detail_that_does_not_match_the_general_order(): void
    {
        $this->seed(RoleSeeder::class);
        Client::factory()->create(['code' => 'TIAUTO']);
        $file = $this->word(
            [['Etiquetas de fila', 'POPURRI', 'Total general'], ['REF1', '49', '49'], ['Total general', '49', '49']],
            [['Etiquetas de fila', 'POPURRI', 'Total general'], ['REF1', '49', '49'], ['TR001', '60', '60'], ['Total general', '60', '60']],
        );

        $this->expectException(ValidationException::class);
        try {
            app(TiAutoWordDispatchImportService::class)->createDraft($file, $this->warehouseUser());
        } finally {
            $this->assertDatabaseCount('goods_dispatches', 0);
        }
    }

    public function test_missing_lot_rows_and_missing_detail_footer_create_a_flagged_draft(): void
    {
        $this->seed(RoleSeeder::class);
        $client = Client::factory()->create(['code' => 'TIAUTO']);
        Item::factory()->create(['client_id' => $client->id, 'sku' => 'REF1']);
        Item::factory()->create(['client_id' => $client->id, 'sku' => 'REF2']);
        $user = $this->warehouseUser();

        $dispatch = app(TiAutoWordDispatchImportService::class)->createDraft($this->word(
            [['Etiquetas de fila', 'POPURRI', 'Total general'], ['REF1', '49', '49'], ['REF2', '320', '320'], ['Total general', '369', '369']],
            [['Etiquetas de fila', 'POPURRI', 'Total general'], ['REF1', '49', '49'], ['TR001', '28', '28'], ['TR002', '21', '21'], ['', '', '']],
        ), $user);

        $this->assertSame(369, $dispatch->lines()->sum('requested_units'));
        $this->assertSame(3, $dispatch->lines()->count());
        $this->assertStringContainsString('320 uds sin desglose de lote', $dispatch->notes);
        $this->assertDatabaseHas('goods_dispatch_lines', ['goods_dispatch_id' => $dispatch->id, 'sku' => 'REF2', 'requested_units' => 320, 'lot' => 'NO LOTE']);
        $this->actingAs($user)->get(route('dispatches.show', $dispatch))->assertOk()->assertSee('LOTE PENDIENTE DE IDENTIFICAR');
        $this->assertDatabaseCount('inventory_movements', 0);
    }

    public function test_client_cannot_open_the_import_screen(): void
    {
        $this->seed(RoleSeeder::class);
        $client = Client::factory()->create();
        $user = User::factory()->create(['role_id' => Role::query()->where('slug', Role::CLIENTE)->value('id'), 'client_id' => $client->id]);

        $this->actingAs($user)->get(route('dispatches.ti-auto-word.create'))->assertForbidden();
    }

    private function warehouseUser(): User
    {
        return User::factory()->create(['role_id' => Role::query()->where('slug', Role::ALMACEN)->value('id')]);
    }

    /** @param list<list<string>> $general @param list<list<string>>|null $detail */
    private function word(array $general, ?array $detail = null): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'ti-auto-word-');
        $zip = new ZipArchive;
        $zip->open($path, ZipArchive::OVERWRITE);
        $table = static function (array $rows): string {
            return '<w:tbl>'.implode('', array_map(static fn (array $row): string => '<w:tr>'.implode('', array_map(static fn (string $cell): string => '<w:tc><w:p><w:r><w:t>'.htmlspecialchars($cell, ENT_XML1).'</w:t></w:r></w:p></w:tc>', $row)).'</w:tr>', $rows)).'</w:tbl>';
        };
        $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body>'.$table($general).($detail === null ? '' : $table($detail)).'</w:body></w:document>');
        $zip->close();
        $file = UploadedFile::fake()->createWithContent('pedido.docx', file_get_contents($path));
        unlink($path);

        return $file;
    }
}
