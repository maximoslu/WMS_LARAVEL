<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\TestCase;

class StockExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_cliente_ve_boton_descargar_en_stock(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Descargar')
            ->assertSee('data-stock-export-trigger', false);
    }

    public function test_stock_cliente_no_muestra_cabecera_mi_inventario_ni_textos_explicativos(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSee('Mi inventario')
            ->assertDontSee('Consulta tus existencias')
            ->assertDontSee('Usa el buscador para localizar');
    }

    public function test_stock_cliente_muestra_stock_disponible_con_boton_descargar_integrado(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $response = $this->actingAs($user)->get(route('stock.index'));
        $response->assertOk();
        $response->assertSee('Stock disponible');
        $response->assertDontSee('Pallets totales');

        $content = $response->getContent();

        $this->assertMatchesRegularExpression(
            '/<article[^>]*stock-summary-card--with-action[^>]*>.*?Stock disponible.*?data-stock-export-trigger.*?<\/article>/s',
            $content
        );
        $this->assertStringNotContainsString('stock-summary-toolbar', $content);
    }

    public function test_modal_de_descarga_muestra_los_tres_formatos_y_cancelar(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $response = $this->actingAs($user)->get(route('stock.index'));

        $response->assertOk();
        $response->assertSee('Descargar stock');
        $response->assertSee('Elige formato');
        $response->assertSee('>Excel<', false);
        $response->assertSee('>PDF<', false);
        $response->assertSee('>CSV<', false);
        $response->assertSee('>Cancelar<', false);
    }

    public function test_cliente_descarga_excel_de_su_stock(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $this->createStockRow($edelvives, 'SKU-XLS', 'Articulo excel', 'LOTE-1', 10);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'xlsx']));

        $response->assertOk();
        $response->assertDownload('stock_edelvives_'.now()->format('Y-m-d').'.xlsx');
    }

    public function test_cliente_descarga_pdf_de_su_stock(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $this->createStockRow($edelvives, 'SKU-PDF', 'Articulo pdf', 'LOTE-1', 10);

        $this->actingAs($user)
            ->get(route('stock.export', ['format' => 'pdf']))
            ->assertOk()
            ->assertHeader('content-type', 'application/pdf');
    }

    public function test_cliente_descarga_csv_de_su_stock(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $this->createStockRow($edelvives, 'SKU-CSV', 'Articulo csv', 'LOTE-1', 10);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'csv']));

        $response->assertOk();
        $response->assertDownload('stock_edelvives_'.now()->format('Y-m-d').'.csv');
    }

    public function test_excel_contiene_solo_columnas_minimas(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $this->createStockRow($edelvives, 'SKU-COLS', 'Articulo columnas', 'LOTE-1', 25);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'xlsx']));
        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();

        $reader = new XlsxReader();
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(['SKU', 'DESCRIPCIÓN', 'LOTE', 'CANTIDAD'], $rows[0]);
        $this->assertCount(4, $rows[0]);
        $this->assertSame(['SKU-COLS', 'Articulo columnas', 'LOTE-1', 25], $rows[1]);
    }

    public function test_csv_contiene_solo_columnas_minimas(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $this->createStockRow($edelvives, 'SKU-CSVCOL', 'Articulo csv columnas', 'LOTE-9', 7);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'csv']));
        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        $content = file_get_contents($path);

        $this->assertStringContainsString('SKU;DESCRIPCIÓN;LOTE;CANTIDAD', $content);
        $this->assertStringContainsString('SKU-CSVCOL;"Articulo csv columnas";LOTE-9;7', $content);
        $this->assertStringNotContainsStringIgnoringCase('pallet', $content);
        $this->assertStringNotContainsStringIgnoringCase('pico', $content);
        $this->assertStringNotContainsStringIgnoringCase('ubicacion', $content);
    }

    public function test_pdf_contiene_solo_columnas_minimas(): void
    {
        [$edelvives] = $this->seedClients();
        $rows = collect([
            ['sku' => 'SKU-PDFCOL', 'description' => 'Articulo pdf columnas', 'lot' => 'LOTE-3', 'quantity' => 40],
        ]);

        $html = view('stock.export-pdf', [
            'client' => $edelvives,
            'rows' => $rows,
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('SKU', $html);
        $this->assertStringContainsString('DESCRIPCIÓN', $html);
        $this->assertStringContainsString('LOTE', $html);
        $this->assertStringContainsString('CANTIDAD', $html);
        $this->assertStringContainsString('SKU-PDFCOL', $html);
        $this->assertStringNotContainsStringIgnoringCase('pallet', $html);
        $this->assertStringNotContainsStringIgnoringCase('pico', $html);
        $this->assertStringNotContainsStringIgnoringCase('ubicacion', $html);
    }

    public function test_export_agrupa_por_sku_y_lote_sumando_cantidades_de_varias_ubicaciones(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $item = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => '110X89135',
            'description' => 'Referencia multiubicacion',
            'units_per_pallet' => 100,
        ]);
        $locationA = Location::factory()->create();
        $locationB = Location::factory()->create();

        StockPallet::factory()->create([
            'item_id' => $item->id,
            'client_id' => $edelvives->id,
            'lot' => 'LOTE-MULTI',
            'location_id' => $locationA->id,
            'units_per_pallet' => 100,
            'quantity_units' => 60,
        ]);
        StockPallet::factory()->create([
            'item_id' => $item->id,
            'client_id' => $edelvives->id,
            'lot' => 'LOTE-MULTI',
            'location_id' => $locationB->id,
            'units_per_pallet' => 100,
            'quantity_units' => 40,
        ]);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'csv']));
        $response->assertOk();

        $content = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('110X89135;"Referencia multiubicacion";LOTE-MULTI;100', $content);
        $this->assertSame(1, substr_count($content, '110X89135'));
    }

    public function test_export_sin_lote_muestra_sin_lote(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $this->createStockRow($edelvives, 'SKU-NOLOT', 'Sin lote articulo', null, 5);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'csv']));
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('SKU-NOLOT;"Sin lote articulo";"SIN LOTE";5', $content);
    }

    public function test_cliente_no_exporta_referencias_internas_varios(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $visible = $this->createStockRow($edelvives, 'SKU-VISIBLE', 'Visible cliente', 'LOTE-V', 10);

        $internalItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => '_INTERNAL-EXPORT',
            'description' => 'Interno export',
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);
        StockPallet::factory()->create([
            'item_id' => $internalItem->id,
            'client_id' => $edelvives->id,
            'lot' => 'LOTE-I',
            'quantity_units' => 5,
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'csv']));
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString($visible->item->sku, $content);
        $this->assertStringNotContainsString('_INTERNAL-EXPORT', $content);
        $this->assertStringNotContainsString('Interno export', $content);
    }

    public function test_cliente_edelvives_no_puede_descargar_stock_friesland(): void
    {
        [$edelvives, $friesland] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $this->createStockRow($friesland, 'SKU-FRIES', 'Solo friesland', 'LOTE-1', 15);

        $response = $this->actingAs($user)
            ->get(route('stock.export', ['format' => 'csv', 'client_id' => $friesland->id]));

        $response->assertOk();
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringNotContainsString('SKU-FRIES', $content);
    }

    public function test_cliente_friesland_no_puede_descargar_stock_edelvives(): void
    {
        [$edelvives, $friesland] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $this->createStockRow($edelvives, 'SKU-EDEL', 'Solo edelvives', 'LOTE-1', 15);

        $response = $this->actingAs($user)
            ->get(route('stock.export', ['format' => 'csv', 'client_id' => $edelvives->id]));

        $response->assertOk();
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringNotContainsString('SKU-EDEL', $content);
    }

    public function test_usuario_cliente_sin_client_id_recibe_403(): void
    {
        $this->seed(RoleSeeder::class);
        $roleId = Role::query()->where('slug', Role::CLIENTE)->value('id');
        $user = User::factory()->create(['role_id' => $roleId, 'client_id' => null]);

        $this->actingAs($user)
            ->get(route('stock.export', ['format' => 'csv']))
            ->assertForbidden();
    }

    public function test_superadmin_puede_exportar_stock_del_cliente_seleccionado(): void
    {
        [$edelvives] = $this->seedClients();
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $this->createStockRow($edelvives, 'SKU-SUPER', 'Articulo superadmin', 'LOTE-1', 8);

        $response = $this->actingAs($superadmin)
            ->get(route('stock.export', ['format' => 'csv', 'client_id' => $edelvives->id]));

        $response->assertOk();
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('SKU-SUPER', $content);
    }

    public function test_superadmin_sin_cliente_seleccionado_es_redirigido_al_listado(): void
    {
        $this->seedClients();
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($superadmin)
            ->get(route('stock.export', ['format' => 'csv']))
            ->assertRedirect(route('stock.index'));
    }

    public function test_export_respeta_exclusion_de_stock_cero(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $item = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-CERO',
            'description' => 'Sin stock disponible',
            'units_per_pallet' => 50,
        ]);
        StockPallet::factory()->create([
            'item_id' => $item->id,
            'client_id' => $edelvives->id,
            'units_per_pallet' => 50,
            'quantity_units' => 0,
            'full_pallets' => 0,
            'peak_1' => 0,
        ]);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'csv']));
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringNotContainsString('SKU-CERO', $content);
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

    private function createStockRow(Client $client, string $sku, string $description, ?string $lot, int $quantity): StockPallet
    {
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => $sku,
            'description' => $description,
            'units_per_pallet' => 100,
        ]);

        return StockPallet::factory()->create([
            'item_id' => $item->id,
            'client_id' => $client->id,
            'lot' => $lot,
            'units_per_pallet' => 100,
            'quantity_units' => $quantity,
        ]);
    }
}
