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

    public function test_stock_cliente_muestra_pales_totales_y_boton_descargar_separado(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $response = $this->actingAs($user)->get(route('stock.index'));
        $response->assertOk();
        $response->assertSeeText('Palés almacenados');
        $response->assertSeeText('Stock físico total');

        $content = $response->getContent();

        $this->assertStringContainsString('data-stock-export-trigger', $content);
        $this->assertMatchesRegularExpression('/Palés almacenados.*?data-stock-export-trigger/s', $content);
        $this->assertStringNotContainsString('stock-summary-toolbar', $content);
    }

    public function test_friesland_sin_ocupacion_mantiene_descarga_modal_y_export_sin_huecos_ni_internos(): void
    {
        [, $friesland] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $location = Location::factory()->create(['code' => 'HUECO-DESCARGA-01']);
        $visible = $this->createStockRow($friesland, 'FR-DESCARGA', 'Visible descarga', 'LOTE-FR', 300);
        $visible->update([
            'location_id' => $location->id,
            'location_text' => $location->code,
            'full_pallets' => 3,
            'warehouse_pallets' => 3,
        ]);
        $internalItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => '_FR-INTERNO-DESCARGA',
            'description' => 'Interno descarga',
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $internalItem->id,
            'quantity_units' => 50,
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);

        $index = $this->actingAs($user)->get(route('stock.index'));
        $index->assertOk();
        $index->assertDontSeeText('Palés almacenados');
        $index->assertDontSeeText('Stock físico total');
        $index->assertDontSee('data-stock-total-summary', false);
        $index->assertDontSeeText('Huecos usados');
        $index->assertDontSeeText('Total de ubicaciones ocupadas');
        $index->assertSee('Descargar');
        $index->assertSee('data-stock-export-trigger', false);
        $index->assertSee('>Excel<', false);
        $index->assertSee('>PDF<', false);
        $index->assertSee('>CSV<', false);

        $xlsx = $this->actingAs($user)->get(route('stock.export', ['format' => 'xlsx']));
        $xlsx->assertOk();
        $xlsxRows = [];
        $reader = new XlsxReader;
        $reader->open($xlsx->baseResponse->getFile()->getPathname());
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $xlsxRows[] = $row->toArray();
            }
        }
        $reader->close();
        $xlsxFlat = collect($xlsxRows)->flatten()->implode('|');
        $this->assertStringContainsString('FR-DESCARGA', $xlsxFlat);
        $this->assertStringNotContainsStringIgnoringCase('hueco', $xlsxFlat);
        $this->assertStringNotContainsStringIgnoringCase('ubicacion', $xlsxFlat);
        $this->assertStringNotContainsString('_FR-INTERNO-DESCARGA', $xlsxFlat);
        $this->assertStringNotContainsString('Interno descarga', $xlsxFlat);

        $csv = $this->actingAs($user)->get(route('stock.export', ['format' => 'csv']));
        $csv->assertOk();
        $csvContent = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('FR-DESCARGA', $csvContent);
        $this->assertStringNotContainsStringIgnoringCase('hueco', $csvContent);
        $this->assertStringNotContainsStringIgnoringCase('ubicacion', $csvContent);
        $this->assertStringNotContainsString('_FR-INTERNO-DESCARGA', $csvContent);
        $this->assertStringNotContainsString('Interno descarga', $csvContent);

        $pdf = $this->actingAs($user)->get(route('stock.export', ['format' => 'pdf']));
        $pdf->assertOk();
        $pdf->assertHeader('content-type', 'application/pdf');
        $this->assertStringNotContainsStringIgnoringCase('hueco', $pdf->getContent());
        $this->assertStringNotContainsString('_FR-INTERNO-DESCARGA', $pdf->getContent());
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

        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];
        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }
        $reader->close();

        $this->assertSame(['SKU', 'DESCRIPCIÓN', 'LOTE', 'CANTIDAD', 'PALÉS TOTALES'], $rows[0]);
        $this->assertCount(5, $rows[0]);
        $this->assertSame(['SKU-COLS', 'Articulo columnas', 'LOTE-1', 25, 1], $rows[1]);
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

        $this->assertStringContainsString('SKU;DESCRIPCIÓN;LOTE;CANTIDAD;"PALÉS TOTALES"', $content);
        $this->assertStringContainsString('SKU-CSVCOL;"Articulo csv columnas";LOTE-9;7;1', $content);
        $this->assertStringNotContainsStringIgnoringCase('pico', $content);
        $this->assertStringNotContainsStringIgnoringCase('ubicacion', $content);
    }

    public function test_pdf_contiene_solo_columnas_minimas(): void
    {
        [$edelvives] = $this->seedClients();
        $rows = collect([
            ['sku' => 'SKU-PDFCOL', 'description' => 'Articulo pdf columnas', 'lot' => 'LOTE-3', 'quantity' => 40, 'total_pallets' => 8],
        ]);

        $html = view('stock.export-pdf', [
            'client' => $edelvives,
            'rows' => $rows,
            'generatedAt' => now(),
        ])->render();

        $this->assertStringContainsString('SKU', $html);
        $this->assertStringContainsString('DESCRIPCI&Oacute;N', $html);
        $this->assertStringContainsString('LOTE', $html);
        $this->assertStringContainsString('CANTIDAD', $html);
        $this->assertStringContainsString('PAL&Eacute;S TOTALES', $html);
        $this->assertStringContainsString('SKU-PDFCOL', $html);
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

        $this->assertStringContainsString('110X89135;"Referencia multiubicacion";LOTE-MULTI;100;2', $content);
        $this->assertSame(1, substr_count($content, '110X89135'));
    }

    public function test_export_sin_lote_muestra_sin_lote(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $this->createStockRow($edelvives, 'SKU-NOLOT', 'Sin lote articulo', null, 5);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'csv']));
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('SKU-NOLOT;"Sin lote articulo";"SIN LOTE";5;1', $content);
    }

    public function test_export_calcula_siete_pales_completos_mas_un_pico_como_ocho(): void
    {
        [$edelvives] = $this->seedClients();
        $user = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $item = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-TOTAL-8',
            'description' => 'Total operativo',
            'units_per_pallet' => 100,
        ]);
        StockPallet::factory()->create([
            'item_id' => $item->id,
            'client_id' => $edelvives->id,
            'lot' => 'LOTE-8',
            'units_per_pallet' => 100,
            'quantity_units' => 725,
            'peak_1' => 25,
        ]);

        $response = $this->actingAs($user)->get(route('stock.export', ['format' => 'csv']));
        $content = file_get_contents($response->baseResponse->getFile()->getPathname());

        $this->assertStringContainsString('SKU-TOTAL-8;"Total operativo";LOTE-8;725;8', $content);
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

    public function test_descargas_oficiales_incluyen_activo_bloqueado_y_excluyen_obsoleto_varios_y_sku_interno(): void
    {
        [, $friesland] = $this->seedClients();
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $this->createStockRow($friesland, 'FR-ACTIVO-OFICIAL', 'Activo oficial', 'LOT-A', 100, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE, 10);
        $this->createStockRow($friesland, 'FR-BLOQUEADO-OFICIAL', 'Bloqueado oficial', 'LOT-B', 50, StockPallet::CATEGORY_BLOCKED, StockPallet::STATUS_BLOCKED, 5);
        $this->createStockRow($friesland, 'FR-OBSOLETO-NO', 'Obsoleto no oficial', 'LOT-O', 30, StockPallet::CATEGORY_OBSOLETE, StockPallet::STATUS_OBSOLETE, 3);
        $this->createStockRow($friesland, 'FR-VARIOS-NO', 'Varios no oficial', 'LOT-V', 20, StockPallet::CATEGORY_MISC, StockPallet::STATUS_AVAILABLE, 2);
        $this->createStockRow($friesland, '_FR-MAL-CATEGORIZADO', 'Interno defensivo', 'LOT-I', 10, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE, 1);

        $index = $this->actingAs($clientUser)->get(route('stock.index'));
        $index->assertOk()
            ->assertSee('FR-ACTIVO-OFICIAL')
            ->assertSee('FR-BLOQUEADO-OFICIAL')
            ->assertSee('FR-OBSOLETO-NO')
            ->assertSee('FR-VARIOS-NO')
            ->assertSee('_FR-MAL-CATEGORIZADO');

        $csv = $this->actingAs($clientUser)->get(route('stock.export', ['format' => 'csv']));
        $csv->assertOk();
        $csvContent = file_get_contents($csv->baseResponse->getFile()->getPathname());
        $this->assertStringContainsString('FR-ACTIVO-OFICIAL', $csvContent);
        $this->assertStringContainsString('FR-BLOQUEADO-OFICIAL', $csvContent);
        $this->assertStringNotContainsString('FR-OBSOLETO-NO', $csvContent);
        $this->assertStringNotContainsString('FR-VARIOS-NO', $csvContent);
        $this->assertStringNotContainsString('_FR-MAL-CATEGORIZADO', $csvContent);
        $this->assertSame(15.0, (float) app(\App\Services\Stock\StockExportService::class)->rows($friesland->id)->sum('total_pallets'));

        $xlsx = $this->actingAs($clientUser)->get(route('stock.export', ['format' => 'xlsx']));
        $xlsx->assertOk();
        $xlsxRows = [];
        $reader = new XlsxReader;
        $reader->open($xlsx->baseResponse->getFile()->getPathname());
        foreach ($reader->getSheetIterator() as $sheet) {
            $this->assertSame('STOCK OFICIAL', $sheet->getName());
            foreach ($sheet->getRowIterator() as $row) {
                $xlsxRows[] = $row->toArray();
            }
        }
        $reader->close();
        $xlsxFlat = collect($xlsxRows)->flatten()->implode('|');
        $this->assertStringContainsString('FR-ACTIVO-OFICIAL', $xlsxFlat);
        $this->assertStringContainsString('FR-BLOQUEADO-OFICIAL', $xlsxFlat);
        $this->assertStringNotContainsString('FR-OBSOLETO-NO', $xlsxFlat);
        $this->assertStringNotContainsString('FR-VARIOS-NO', $xlsxFlat);
        $this->assertStringNotContainsString('_FR-MAL-CATEGORIZADO', $xlsxFlat);

        $pdfRows = app(\App\Services\Stock\StockExportService::class)->rows($friesland->id);
        $pdfHtml = view('stock.export-pdf', [
            'client' => $friesland,
            'rows' => $pdfRows,
            'generatedAt' => now(),
        ])->render();
        $this->assertStringContainsString('STOCK OFICIAL', $pdfHtml);
        $this->assertStringContainsString('ACTIVA y BLOQUEADA', $pdfHtml);
        $this->assertStringContainsString('FR-ACTIVO-OFICIAL', $pdfHtml);
        $this->assertStringContainsString('FR-BLOQUEADO-OFICIAL', $pdfHtml);
        $this->assertStringNotContainsString('FR-OBSOLETO-NO', $pdfHtml);
        $this->assertStringNotContainsString('FR-VARIOS-NO', $pdfHtml);
        $this->assertStringNotContainsString('_FR-MAL-CATEGORIZADO', $pdfHtml);
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

    private function createStockRow(
        Client $client,
        string $sku,
        string $description,
        ?string $lot,
        int $quantity,
        string $stockCategory = StockPallet::CATEGORY_IN_USE,
        string $status = StockPallet::STATUS_AVAILABLE,
        ?float $warehousePallets = null,
    ): StockPallet
    {
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => $sku,
            'description' => $description,
            'units_per_pallet' => 100,
            'stock_category' => $stockCategory,
            'status' => match ($stockCategory) {
                StockPallet::CATEGORY_BLOCKED => Item::STATUS_BLOCKED,
                StockPallet::CATEGORY_OBSOLETE => Item::STATUS_OBSOLETE,
                default => Item::STATUS_ACTIVE,
            },
        ]);

        return StockPallet::factory()->create([
            'item_id' => $item->id,
            'client_id' => $client->id,
            'lot' => $lot,
            'units_per_pallet' => 100,
            'quantity_units' => $quantity,
            'stock_category' => $stockCategory,
            'status' => $status,
            'warehouse_pallets' => $warehousePallets,
        ]);
    }
}
