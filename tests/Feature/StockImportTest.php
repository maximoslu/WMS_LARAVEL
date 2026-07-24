<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockImport;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
use App\Support\Stock\StockOverviewBuilder;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use Tests\TestCase;

class StockImportTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_superadmin_can_access_stock_import_screen(): void
    {
        $this->seedBaseData();

        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($superadmin)
            ->get(route('stock.import'))
            ->assertOk();

        $this->actingAs($almacen)
            ->get(route('stock.import'))
            ->assertForbidden();
    }

    public function test_preview_detects_friesland_supported_sheets_including_etiquetas(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'GENERAL' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Pico 1'],
                ['FR-AGG', 'Producto agregado', 'LOT-A', 70000, 1080, 64, 880],
            ],
            'BOBINAS' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FR-BOB', 'Bobina', 'LOT-B', 1200, 600, 2],
            ],
            'BLOQUEADO' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FR-BLK', 'Bloqueado', 'LOT-C', 600, 600, 1],
            ],
            'ETIQUETAS' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FR-ETQ', 'Etiqueta', 'LOT-D', 1000, 500, 2],
            ],
            'OBSOLETO' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FR-OBS', 'Obsoleto', 'LOT-E', 300, 300, 1],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('stock.import.preview'), [
                'client_id' => $friesland->id,
                'file' => $file,
            ])
            ->assertOk()
            ->assertSee('GENERAL')
            ->assertSee('BOBINAS')
            ->assertSee('BLOQUEADO')
            ->assertSee('ETIQUETAS')
            ->assertSee('OBSOLETO');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(['GENERAL', 'BOBINAS', 'BLOQUEADO', 'ETIQUETAS', 'OBSOLETO'], $stockImport->detected_sheets_json['processed']);
        $this->assertSame([], $stockImport->detected_sheets_json['ignored']);
        $this->assertSame(5, $stockImport->summary_json['catalog_items_detected']);
        $this->assertSame(StockImport::STATUS_PENDING_CONFIRMATION, $stockImport->status);
    }

    public function test_preview_normalizes_excel_headers_for_units_and_peaks(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => [
                [' REFERENCIA ', 'DESCRIPCION', 'LOTE', 'CANTIDAD', ' UNIDADES x PALET ', 'PALETS', 'PICO 5 ', 'UBICACION'],
                ['FR-PALET', 'Cabecera palet', 'LOT-1', 5000, 1000, 4, 25, 'A1-01'],
            ],
            'BOBINAS' => [
                ['REFERENCIA', 'DESCRIPCION', 'LOTE', 'CANTIDAD', 'UNIDADES x PALLET', 'PALLETS', 'PICO 1', 'PICO 10'],
                ['FR-PALLET', 'Cabecera pallet', 'LOT-2', 1500, 1000, 1, 200, 300],
            ],
            'BLOQUEADO' => [
                ['REFERENCIA', 'DESCRIPCION', 'LOTE', 'CANTIDAD', 'UDS/PALET', 'PALETS'],
                ['FR-UDS', 'Cabecera uds', 'LOT-3', 600, 600, 1],
            ],
        ]);

        $response = $this->actingAs($user)
            ->post(route('stock.import.preview'), [
                'client_id' => $friesland->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertSee('Errores bloqueantes')
            ->assertSee('Articulos detectados')
            ->assertSee('Confirmar importacion');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(0, $stockImport->summary_json['real_errors']);
        $this->assertSame(0, $stockImport->summary_json['excluded_rows']);

        $this->actingAs($user)
            ->post(route('stock.import.confirm'), [
                'stock_import_id' => $stockImport->id,
            ])
            ->assertRedirect(route('stock.index', ['client_id' => $friesland->id]));

        $stockWithPalet = StockPallet::query()->whereHas('item', fn ($query) => $query->where('sku', 'FR-PALET'))->firstOrFail();
        $stockWithPallet = StockPallet::query()->whereHas('item', fn ($query) => $query->where('sku', 'FR-PALLET'))->firstOrFail();
        $stockWithUds = StockPallet::query()->whereHas('item', fn ($query) => $query->where('sku', 'FR-UDS'))->firstOrFail();

        $this->assertSame(1000, $stockWithPalet->units_per_pallet);
        $this->assertSame(25, $stockWithPalet->peak_5);
        $this->assertSame('A1-01', $stockWithPalet->location_text);

        $this->assertSame(1000, $stockWithPallet->units_per_pallet);
        $this->assertSame(200, $stockWithPallet->peak_1);
        $this->assertSame(300, $stockWithPallet->peak_10);

        $this->assertSame(600, $stockWithUds->units_per_pallet);
        $this->assertSame(StockPallet::STATUS_BLOCKED, $stockWithUds->status);
    }

    public function test_preview_ignores_summary_rows_and_imports_underscore_references_as_internal_misc(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'GENERAL' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos'],
                ['***BLOQUEADO***', 'Interna', 'LOT-X', 200, null, 0],
                ['_BANDEJA23', 'Interna 2', 'LOT-Y', 100, 100, 1, 0.5],
                ['FR-VALID', 'Valida', 'LOT-Z', 1000, 1000, 1, 0],
            ],
        ]);

        $response = $this->actingAs($user)
            ->post(route('stock.import.preview'), [
                'client_id' => $friesland->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertSee('Se han ignorado filas de resumen que empiezan por *.')
            ->assertDontSee('no se importara por no tener unidades por pallet validas para SKU ***BLOQUEADO***')
            ->assertDontSee('no se importara por no tener unidades por pallet validas para SKU _BANDEJA23');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $stockImport->summary_json['excluded_rows']);
        $this->assertSame(1, $stockImport->summary_json['summary_rows_ignored']);
        $this->assertSame(1, $stockImport->summary_json['internal_rows']);
        $this->assertSame(1.5, (float) $stockImport->summary_json['internal_warehouse_pallets']);
        $this->assertSame(0, $stockImport->summary_json['real_errors']);

        $this->actingAs($user)
            ->post(route('stock.import.confirm'), [
                'stock_import_id' => $stockImport->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseMissing('items', [
            'client_id' => $friesland->id,
            'sku' => '***BLOQUEADO***',
        ]);

        $this->assertDatabaseHas('items', [
            'client_id' => $friesland->id,
            'sku' => '_BANDEJA23',
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);

        $this->assertDatabaseMissing('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'LOT-X',
        ]);

        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'LOT-Y',
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);

        $this->assertDatabaseHas('items', [
            'client_id' => $friesland->id,
            'sku' => 'FR-VALID',
        ]);
    }

    public function test_friesland_import_classifies_all_supported_sheets_and_uses_picos_as_warehouse_pallets(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'GENERAL' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos', 'Pico 1'],
                ['FR-GEN', 'General', 'LOT-G', 2050, 1000, 2, 0.5, 50],
                ['***TOTAL***', 'Resumen', null, 0, null, 0, 0],
            ],
            'BOBINAS' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos'],
                ['FR-BOB-CAT', 'Bobina', 'LOT-B', 1200, 600, 2, 0],
            ],
            'ETIQUETAS' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos'],
                ['FR-ETQ-CAT', 'Etiqueta', 'LOT-E', 1000, 500, 2, 0],
            ],
            'BLOQUEADO' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos'],
                ['FR-BLK-CAT', 'Bloqueado', 'LOT-L', 600, 600, 1, 0],
            ],
            'OBSOLETO' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos'],
                ['FR-OBS-CAT', 'Obsoleto', 'LOT-O', 300, 300, 1, 0],
            ],
            'VARIOS' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos'],
                ['_FR-MISC-CAT', 'Interno', 'LOT-V', 100, 100, 1, 0.33],
            ],
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ])->assertOk()
            ->assertSee('Totales por categoria')
            ->assertSee('VARIOS');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(6, $stockImport->summary_json['catalog_items_detected']);
        $this->assertSame(1, $stockImport->summary_json['summary_rows_ignored']);
        $this->assertSame(1, $stockImport->summary_json['internal_rows']);
        $this->assertSame(9.83, (float) $stockImport->summary_json['total_warehouse_pallets']);
        $this->assertSame(1.33, (float) $stockImport->summary_json['internal_warehouse_pallets']);
        $this->assertSame(3, $stockImport->summary_json['category_rows'][StockPallet::CATEGORY_IN_USE]);
        $this->assertSame(1, $stockImport->summary_json['category_rows'][StockPallet::CATEGORY_BLOCKED]);
        $this->assertSame(1, $stockImport->summary_json['category_rows'][StockPallet::CATEGORY_OBSOLETE]);
        $this->assertSame(1, $stockImport->summary_json['category_rows'][StockPallet::CATEGORY_MISC]);

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect(route('stock.index', ['client_id' => $friesland->id]));

        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'LOT-L',
            'status' => StockPallet::STATUS_BLOCKED,
            'stock_category' => StockPallet::CATEGORY_BLOCKED,
        ]);
        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'LOT-O',
            'status' => StockPallet::STATUS_OBSOLETE,
            'stock_category' => StockPallet::CATEGORY_OBSOLETE,
        ]);
        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'LOT-V',
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);

        $miscStock = StockPallet::query()->where('client_id', $friesland->id)->where('lot', 'LOT-V')->firstOrFail();
        $this->assertSame(1.33, (float) $miscStock->warehouse_pallets);
    }

    public function test_friesland_import_keeps_stock_category_per_batch_when_same_sku_appears_in_multiple_sheets(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'GENERAL' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FR-SAME-SKU', 'Mismo SKU activo', 'LOT-ACT', 1000, 100, 10],
            ],
            'BLOQUEADO' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FR-SAME-SKU', 'Mismo SKU bloqueado', 'LOT-BLK', 500, 100, 5],
            ],
            'OBSOLETO' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FR-SAME-SKU', 'Mismo SKU obsoleto', 'LOT-OBS', 300, 100, 3],
            ],
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ])->assertOk();

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect(route('stock.index', ['client_id' => $friesland->id]));

        $categoriesByLot = StockPallet::query()
            ->where('client_id', $friesland->id)
            ->whereHas('item', fn ($query) => $query->where('sku', 'FR-SAME-SKU'))
            ->pluck('stock_category', 'lot')
            ->all();

        $this->assertSame(StockPallet::CATEGORY_IN_USE, $categoriesByLot['LOT-ACT']);
        $this->assertSame(StockPallet::CATEGORY_BLOCKED, $categoriesByLot['LOT-BLK']);
        $this->assertSame(StockPallet::CATEGORY_OBSOLETE, $categoriesByLot['LOT-OBS']);
    }

    public function test_bobinas_with_zero_units_but_declared_pallets_preserves_logistic_stock(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'BOBINAS' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos'],
                ['FILM-ZERO-UNITS', 'Film sin unidades', 'LOT-FILM', '=', 0, 2, 0.5],
            ],
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ])->assertOk()
            ->assertSee('2,50');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(1, $stockImport->summary_json['valid_rows']);
        $this->assertSame(2.5, (float) $stockImport->summary_json['total_warehouse_pallets']);
        $this->assertSame(0, $stockImport->summary_json['real_errors']);

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect(route('stock.index', ['client_id' => $friesland->id]));

        $stock = StockPallet::query()->where('client_id', $friesland->id)->where('lot', 'LOT-FILM')->firstOrFail();
        $this->assertSame(0, $stock->quantity_units);
        $this->assertSame(0, $stock->units_per_pallet);
        $this->assertSame(2, $stock->full_pallets);
        $this->assertSame(2.5, (float) $stock->warehouse_pallets);
    }

    public function test_quantity_zero_is_ignored_without_units_per_pallet_error(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FR-ZERO', 'Sin stock', 'LOT-0', 0, null, 0],
                ['FR-VALID', 'Con stock', 'LOT-1', 1200, 600, 2],
            ],
        ]);

        $response = $this->actingAs($user)
            ->post(route('stock.import.preview'), [
                'client_id' => $friesland->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertDontSee('no se importara por no tener unidades por pallet validas para SKU FR-ZERO')
            ->assertDontSee('FR-ZERO');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(0, $stockImport->summary_json['real_errors']);
        $this->assertSame(1, $stockImport->summary_json['skipped_rows']);
        $this->assertSame(2, $stockImport->summary_json['catalog_items_detected']);
        $this->assertSame(1, $stockImport->summary_json['catalog_items_without_stock']);

        $this->actingAs($user)
            ->post(route('stock.import.confirm'), [
                'stock_import_id' => $stockImport->id,
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('items', [
            'client_id' => $friesland->id,
            'sku' => 'FR-ZERO',
        ]);

        $this->assertDatabaseMissing('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'LOT-0',
        ]);
    }

    public function test_preview_without_valid_rows_does_not_show_confirm_button(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['***VARIOS***', 'Interna', 'LOT-X', 0, null, 0],
                ['_BANDEJA23', 'Interna', 'LOT-Y', 0, null, 0],
                ['', 'Sin sku', 'LOT-Z', 0, null, 0],
            ],
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('No hay filas validas para importar.')
            ->assertDontSee('Confirmar importacion');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();
        $this->assertSame(StockImport::STATUS_PENDING_CONFIRMATION, $stockImport->status);
    }

    public function test_invalid_rows_do_not_block_confirmation_when_valid_rows_exist(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Pico 1'],
                ['FR-VALID', 'Valida', 'LOT-1', 1200, 600, 2, 0],
                ['FR-INVALID', 'Invalida', 'LOT-2', 300, null, 0, 0],
            ],
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('Errores bloqueantes en filas')
            ->assertSee('Confirmar importacion');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();
        $this->assertSame(1, $stockImport->summary_json['invalid_rows_ignored']);

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('items', [
            'client_id' => $friesland->id,
            'sku' => 'FR-VALID',
        ]);

        $this->assertDatabaseHas('items', [
            'client_id' => $friesland->id,
            'sku' => 'FR-INVALID',
        ]);
    }

    public function test_bobinas_positive_quantity_without_units_per_pallet_is_imported_as_operational_stock(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'BOBINAS' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FILM0727', 'Film tecnico', 'LOT-FILM', 1100, null, 0],
            ],
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('conservando solo el stock operativo')
            ->assertSee('FILM0727')
            ->assertSee('LOT-FILM')
            ->assertSee('1.100');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();
        $this->assertSame(0, $stockImport->summary_json['invalid_rows_ignored']);

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $item = Item::query()->where('client_id', $friesland->id)->where('sku', 'FILM0727')->firstOrFail();
        $stock = StockPallet::query()->where('item_id', $item->id)->firstOrFail();

        $this->assertSame(1100, $stock->quantity_units);
        $this->assertSame(0, $stock->units_per_pallet);
        $this->assertSame(0, $stock->full_pallets);
        $this->assertSame(0, $stock->peaks_count);
    }

    public function test_repeated_sku_with_two_lots_creates_one_item_and_two_stock_batches(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Pico 1'],
                ['SKU 11', 'FECULA DE PATATA', 'LOT-A', 8000, 1000, 8, 0],
                ['SKU 11', 'FECULA DE PATATA', 'LOT-B', 8000, 1000, 8, 0],
                ['CAJA0008', 'Caja 8', 'LOT-C', 0, 500, 0, 0],
                ['CAJA0019', 'Caja 19', 'LOT-D', 0, 500, 0, 0],
                ['CAJA0023', 'Caja 23', 'LOT-E', 0, 500, 0, 0],
            ],
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('Partidas de stock que se importaran')
            ->assertSee('SKU 11')
            ->assertSee('LOT-A')
            ->assertSee('LOT-B')
            ->assertDontSee('LOT-C')
            ->assertDontSee('LOT-D')
            ->assertDontSee('LOT-E')
            ->assertDontSee('CAJA0008')
            ->assertDontSee('CAJA0019')
            ->assertDontSee('CAJA0023');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(4, $stockImport->summary_json['catalog_items_detected']);
        $this->assertSame(3, $stockImport->summary_json['catalog_items_without_stock']);

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $this->assertSame(1, Item::query()->where('client_id', $friesland->id)->where('sku', 'SKU 11')->count());
        $this->assertSame(2, StockPallet::query()->whereHas('item', fn ($query) => $query->where('sku', 'SKU 11'))->count());

        $this->assertDatabaseHas('items', [
            'client_id' => $friesland->id,
            'sku' => 'CAJA0008',
        ]);

        $this->assertDatabaseHas('items', [
            'client_id' => $friesland->id,
            'sku' => 'CAJA0019',
        ]);

        $this->assertDatabaseHas('items', [
            'client_id' => $friesland->id,
            'sku' => 'CAJA0023',
        ]);

        $this->assertDatabaseMissing('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'LOT-C',
        ]);
    }

    public function test_confirm_import_creates_item_masters_and_preserves_multi_peak_rows(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Pico 1', 'Ubicacion', 'Fecha entrada', 'Pico 2', 'Pico 3', 'Pico 4', 'Pico 5', 'Pico 6', 'Pico 7', 'Pico 8', 'Pico 9', 'Pico 10'],
                ['FR-AGG', 'Producto agregado', 'LOT-A', 70000, 1080, 64, 880, 'A1-01', '2026-06-28', 0, 0, 0, 0, 0, 0, 0, 0, 0],
                ['FR-MULTI', 'Producto multipico', 'LOT-M', 550, 1000, 0, 10, 'B1-02', '2026-06-28', 20, 30, 40, 50, 60, 70, 80, 90, 100],
            ],
            'BLOQUEADO' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['FR-BLK', 'Bloqueado', 'LOT-C', 600, 600, 1],
            ],
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ])->assertOk();

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)
            ->post(route('stock.import.confirm'), [
                'stock_import_id' => $stockImport->id,
            ])
            ->assertRedirect(route('stock.index', ['client_id' => $friesland->id]));

        $aggregateItem = Item::query()->where('client_id', $friesland->id)->where('sku', 'FR-AGG')->firstOrFail();
        $multiItem = Item::query()->where('client_id', $friesland->id)->where('sku', 'FR-MULTI')->firstOrFail();
        $blockedItem = Item::query()->where('client_id', $friesland->id)->where('sku', 'FR-BLK')->firstOrFail();

        $aggregateStock = StockPallet::query()->where('item_id', $aggregateItem->id)->firstOrFail();
        $multiStock = StockPallet::query()->where('item_id', $multiItem->id)->firstOrFail();
        $blockedStock = StockPallet::query()->where('item_id', $blockedItem->id)->firstOrFail();

        $this->assertNull($aggregateStock->pallet_code);
        $this->assertSame(70000, $aggregateStock->quantity_units);
        $this->assertSame(64, $aggregateStock->full_pallets);
        $this->assertSame(880, $aggregateStock->peak_1);

        $this->assertSame(10, $multiStock->peaks_count);
        $this->assertSame(10, $multiStock->peak_1);
        $this->assertSame(20, $multiStock->peak_2);
        $this->assertSame(30, $multiStock->peak_3);
        $this->assertSame(40, $multiStock->peak_4);
        $this->assertSame(50, $multiStock->peak_5);
        $this->assertSame(60, $multiStock->peak_6);
        $this->assertSame(70, $multiStock->peak_7);
        $this->assertSame(80, $multiStock->peak_8);
        $this->assertSame(90, $multiStock->peak_9);
        $this->assertSame(100, $multiStock->peak_10);
        $this->assertSame('STOCK', $multiStock->source_sheet);

        $this->assertSame(StockPallet::STATUS_BLOCKED, $blockedStock->status);
        $this->assertSame('Importado desde pestana BLOQUEADO', $blockedStock->blocked_reason);
        $this->assertSame('BLOQUEADO', $blockedStock->source_sheet);
    }

    public function test_confirm_replaces_only_selected_client_stock_and_stock_view_shows_imported_data(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $legacyItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'FR-OLD',
            'description' => 'Legacy FR',
            'units_per_pallet' => 500,
        ]);

        $otherClientItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'ED-KEEP',
            'description' => 'Mantener',
            'units_per_pallet' => 300,
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $legacyItem->id,
            'lot' => 'OLD-LOT',
            'quantity_units' => 500,
            'units_per_pallet' => 500,
            'full_pallets' => 1,
        ]);

        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $otherClientItem->id,
            'lot' => 'ED-LOT',
            'quantity_units' => 300,
            'units_per_pallet' => 300,
            'full_pallets' => 1,
        ]);

        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Pico 1'],
                ['FR-NEW', 'Nuevo importado', 'LOT-NEW', 70000, 1080, 64, 880],
            ],
        ]);

        $this->actingAs($superadmin)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ])->assertOk();

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($superadmin)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $friesland->id,
            'item_id' => $legacyItem->id,
            'active' => false,
            'quantity_units' => 0,
            'full_pallets' => 0,
        ]);

        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $edelvives->id,
            'item_id' => $otherClientItem->id,
            'lot' => 'ED-LOT',
        ]);

        $this->actingAs($almacen)
            ->get(route('stock.index', ['client_id' => $friesland->id]))
            ->assertOk()
            ->assertSee('FR-NEW')
            ->assertSee('70.000')
            ->assertSee('880')
            ->assertDontSee('FR-OLD')
            ->assertDontSee('Codigo pallet');
    }

    public function test_cannot_confirm_failed_import(): void
    {
        [$friesland] = $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $stockImport = StockImport::query()->create([
            'client_id' => $friesland->id,
            'uploaded_by' => $user->id,
            'original_filename' => 'bad.xlsx',
            'stored_path' => 'missing.xlsx',
            'status' => StockImport::STATUS_FAILED,
            'total_rows' => 0,
            'imported_rows' => 0,
            'skipped_rows' => 0,
            'available_rows' => 0,
            'blocked_rows' => 0,
            'detected_sheets_json' => [],
            'summary_json' => [],
            'warnings_json' => [],
            'errors_json' => ['fallo'],
        ]);

        $this->actingAs($user)
            ->post(route('stock.import.confirm'), [
                'stock_import_id' => $stockImport->id,
            ])
            ->assertSessionHasErrors();
    }

    public function test_cannot_confirm_import_twice(): void
    {
        [$friesland] = $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $stockImport = StockImport::query()->create([
            'client_id' => $friesland->id,
            'uploaded_by' => $user->id,
            'original_filename' => 'done.xlsx',
            'stored_path' => 'done.xlsx',
            'status' => StockImport::STATUS_IMPORTED,
            'total_rows' => 1,
            'imported_rows' => 1,
            'skipped_rows' => 0,
            'available_rows' => 1,
            'blocked_rows' => 0,
            'detected_sheets_json' => [],
            'summary_json' => [],
            'warnings_json' => [],
            'errors_json' => [],
        ]);

        $this->actingAs($user)
            ->post(route('stock.import.confirm'), [
                'stock_import_id' => $stockImport->id,
            ])
            ->assertSessionHasErrors();
    }

    public function test_grouped_warnings_are_limited_to_five_examples(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $rows = [['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets']];

        foreach (range(1, 8) as $index) {
            $rows[] = ['', 'Sin sku '.$index, 'LOT-'.$index, 10, 10, 1];
        }

        $rows[] = ['FR-VALID', 'Valida', 'LOT-OK', 10, 10, 1];

        $file = $this->makeWorkbookUpload([
            'STOCK' => $rows,
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('Se han ignorado 8 filas sin SKU valido en STOCK.')
            ->assertSee('y 3 mas.')
            ->assertDontSee('Fila 8 de STOCK ignorada por no tener SKU.');
    }

    public function test_edelvives_preview_accepts_real_header_positions_and_ignores_grammage_as_functional_property(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('D', 135, '100x127 135', '100x127 135 - MATT COATED', 1998, 4500, 0, 1, [1998], 1, 'ignorar'),
                $this->makeRealEdelvivesDataRow('18.0', 110, '100x131 110', '100x131 110 INASET PLUS OFFSET', 38500, 5500, 7, 0, [], 7, 'aux'),
            ]),
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('Stock Edelvives')
            ->assertSee('Gramaje detectado en archivo, no se importara como propiedad independiente.')
            ->assertSee('Se usara el almacen NAVE 38 y se aseguraran las calles 0-45, A-F, FONDO y SIN UBICACION.')
            ->assertSee('100x127 135')
            ->assertSee('100x131 110')
            ->assertSee('SIN LOTE')
            ->assertDontSee('La hoja STOCK no tiene el formato esperado para Edelvives.');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(StockImport::STATUS_PENDING_CONFIRMATION, $stockImport->status);
        $this->assertSame(['STOCK'], $stockImport->detected_sheets_json['processed']);
        $this->assertSame(2, $stockImport->summary_json['total_rows']);
        $this->assertSame(2, $stockImport->summary_json['available_rows']);
        $this->assertSame(2, $stockImport->summary_json['locations_detected']);
    }

    public function test_edelvives_confirm_creates_nave_38_locations_and_imports_stock_with_default_lot_and_states(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('Calle 18', 80, '70x100 80', 'Papel offset 70x100 80', 1880, 1000, 1, 1, [880], 2),
                $this->makeRealEdelvivesDataRow('A', 110, '100x127 110', 'Papel estucado 100x127 110', 2500, 1000, 2, 1, [500], 3),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk();

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect(route('stock.index', ['client_id' => $edelvives->id]));

        $warehouse = Warehouse::query()
            ->where('name', 'NAVE 38')
            ->orWhere('code', '38')
            ->firstOrFail();

        $this->assertSame(54, Location::query()->where('warehouse_id', $warehouse->id)->whereIn('code', array_merge(array_map('strval', range(0, 45)), ['A', 'B', 'C', 'D', 'E', 'F', 'FONDO', 'SIN UBICACION']))->count());

        $firstItem = Item::query()->where('client_id', $edelvives->id)->where('sku', '70x100 80')->firstOrFail();
        $firstStock = StockPallet::query()->where('item_id', $firstItem->id)->firstOrFail();
        $secondItem = Item::query()->where('client_id', $edelvives->id)->where('sku', '100x127 110')->firstOrFail();
        $secondStock = StockPallet::query()->where('item_id', $secondItem->id)->firstOrFail();

        $this->assertSame(Item::STATUS_ACTIVE, $firstItem->status);
        $this->assertTrue($firstItem->active);
        $this->assertSame('Papel offset 70x100 80', $firstItem->description);
        $this->assertSame('SIN LOTE', $firstStock->lot);
        $this->assertSame('18', $firstStock->location?->code);
        $this->assertSame(StockPallet::STATUS_AVAILABLE, $firstStock->status);
        $this->assertSame(1880, $firstStock->quantity_units);
        $this->assertSame(1000, $firstStock->units_per_pallet);
        $this->assertSame(1, $firstStock->full_pallets);
        $this->assertSame(1, $firstStock->peaks_count);
        $this->assertSame(880, $firstStock->peak_1);
        $this->assertNotNull($firstStock->location_id);
        $this->assertSame('18', $firstStock->location_text);
        $this->assertSame('Papel estucado 100x127 110', $secondItem->description);
        $this->assertSame('A', $secondStock->location_text);
    }

    public function test_edelvives_import_reuses_legacy_zero_padded_location_as_canonical_without_creating_duplicate(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');
        $warehouse = Warehouse::factory()->create([
            'client_id' => null,
            'code' => '38',
            'name' => 'NAVE 38',
        ]);
        $legacyLocationId = DB::table('locations')->insertGetId([
            'warehouse_id' => $warehouse->id,
            'code' => '06',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('6', 80, 'ED-LEGACY-LOC', 'Ubicacion existente', 1000, 1000, 1, 0, [], 1),
            ]),
        ]);
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk();

        $stockImport = StockImport::query()->latest('id')->firstOrFail();
        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $this->assertSame(1, Location::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('code', ['6', '06'])
            ->count());
        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $edelvives->id,
            'location_id' => $legacyLocationId,
            'location_text' => '6',
            'quantity_units' => 1000,
        ]);
        $this->assertDatabaseHas('locations', [
            'id' => $legacyLocationId,
            'code' => '6',
        ]);
    }

    public function test_edelvives_import_canonicalizes_equivalent_numeric_locations(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');
        $warehouse = Warehouse::factory()->create([
            'client_id' => null,
            'code' => '38',
            'name' => 'NAVE 38',
        ]);
        $legacyLocationId = DB::table('locations')->insertGetId([
            'warehouse_id' => $warehouse->id,
            'code' => '09',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('9', 80, 'ED-LOC-9-A', 'Ubicacion 9 A', 1000, 1000, 1, 0, [], 1),
                $this->makeRealEdelvivesDataRow('09', 80, 'ED-LOC-9-B', 'Ubicacion 9 B', 1000, 1000, 1, 0, [], 1),
                $this->makeRealEdelvivesDataRow('Calle 9', 80, 'ED-LOC-9-C', 'Ubicacion 9 C', 1000, 1000, 1, 0, [], 1),
            ]),
        ]);
        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk();

        $stockImport = StockImport::query()->latest('id')->firstOrFail();
        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $this->assertSame(1, Location::query()
            ->where('warehouse_id', $warehouse->id)
            ->whereIn('code', ['9', '09', 'Calle 9'])
            ->count());
        $this->assertSame(['9'], StockPallet::query()
            ->where('client_id', $edelvives->id)
            ->whereIn('item_id', Item::query()->where('client_id', $edelvives->id)->whereIn('sku', ['ED-LOC-9-A', 'ED-LOC-9-B', 'ED-LOC-9-C'])->pluck('id'))
            ->pluck('location_text')
            ->unique()
            ->values()
            ->all());
        $this->assertSame([$legacyLocationId], StockPallet::query()
            ->where('client_id', $edelvives->id)
            ->whereIn('item_id', Item::query()->where('client_id', $edelvives->id)->whereIn('sku', ['ED-LOC-9-A', 'ED-LOC-9-B', 'ED-LOC-9-C'])->pluck('id'))
            ->pluck('location_id')
            ->unique()
            ->values()
            ->all());
    }

    public function test_edelvives_import_reuses_existing_sku_per_client_without_mixing_clients(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $sharedSku = '70x100 80';

        $frieslandItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => $sharedSku,
            'description' => 'Producto Friesland',
            'units_per_pallet' => 700,
        ]);

        Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => $sharedSku,
            'description' => 'Version antigua Edelvives',
            'units_per_pallet' => 900,
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frieslandItem->id,
            'lot' => 'FR-LOT',
            'quantity_units' => 700,
            'units_per_pallet' => 700,
            'full_pallets' => 1,
        ]);

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('1', 80, $sharedSku, 'Producto Edelvives actualizado', 1880, 1000, 1, 1, [880], 2),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk();

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $this->assertSame(1, Item::query()->where('client_id', $edelvives->id)->where('sku', $sharedSku)->count());
        $this->assertSame(1, Item::query()->where('client_id', $friesland->id)->where('sku', $sharedSku)->count());
        $this->assertSame('Producto Edelvives actualizado', Item::query()->where('client_id', $edelvives->id)->where('sku', $sharedSku)->firstOrFail()->description);
        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'FR-LOT',
        ]);
    }

    public function test_edelvives_preview_warns_when_reported_total_pallets_does_not_match_breakdown(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('B', 90, '90x120 90', 'Papel mismatch', 1880, 1000, 1, 1, [880], 7),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk()
            ->assertSee('Se han detectado 1 filas con total pallets distinto al calculado en STOCK.');
    }

    public function test_edelvives_imports_peak_only_rows_and_parses_numeric_text_values(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('D', '135', '100x127 135', '100x127 135 - MATT COATED', '1.998', '4.500', '0', '1', ['1.998'], '1'),
                $this->makeRealEdelvivesDataRow('18.0', 110, '100x131 110', '100x131 110 INASET PLUS OFFSET', 38500, 5500.0, 7, 0, [], 7),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk()
            ->assertSee('100x127 135')
            ->assertSee('1.998')
            ->assertDontSee('222.222.222.222');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $peakOnlyItem = Item::query()->where('client_id', $edelvives->id)->where('sku', '100x127 135')->firstOrFail();
        $peakOnlyStock = StockPallet::query()->where('item_id', $peakOnlyItem->id)->firstOrFail();
        $palletItem = Item::query()->where('client_id', $edelvives->id)->where('sku', '100x131 110')->firstOrFail();
        $palletStock = StockPallet::query()->where('item_id', $palletItem->id)->firstOrFail();

        $this->assertSame(1998, $peakOnlyStock->quantity_units);
        $this->assertSame(4500, $peakOnlyStock->units_per_pallet);
        $this->assertSame(0, $peakOnlyStock->full_pallets);
        $this->assertSame(1, $peakOnlyStock->peaks_count);
        $this->assertSame(1998, $peakOnlyStock->peak_1);

        $this->assertSame(38500, $palletStock->quantity_units);
        $this->assertSame(5500, $palletStock->units_per_pallet);
        $this->assertSame(7, $palletStock->full_pallets);
        $this->assertSame(0, $palletStock->peaks_count);
        $this->assertSame('18', $palletStock->location_text);
    }

    public function test_edelvives_real_row_two_does_not_generate_false_quantity_or_total_pallet_warnings(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('D', 135, '100x127 135', '100x127 135 - MATT COATED', 1998, 4500, 0, 1, [1998], 1),
                $this->makeRealEdelvivesDataRow('18', 110, '100x131 110', '100x131 110 INASET PLUS OFFSET', 38500, 5500, 7, 0, [], 7),
            ]),
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('100x127 135')
            ->assertSee('1.998')
            ->assertDontSee('Fila 2 de STOCK con total pallets')
            ->assertDontSee('Fila 2 de STOCK con cantidad');
    }

    public function test_edelvives_real_row_two_as_string_thousands_does_not_generate_false_warnings(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('D', '135', '100x127 135', '100x127 135 - MATT COATED', '1.998', '4.500', '0', '1', ['1.998'], '1'),
            ]),
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('1.998')
            ->assertSee('4.500')
            ->assertDontSee('Fila 2 de STOCK con total pallets')
            ->assertDontSee('Fila 2 de STOCK con cantidad');
    }

    public function test_edelvives_row_with_seven_pallets_and_two_peaks_and_total_nine_does_not_warn(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('7', 356, '101x67 356', 'Caso fila 9', 12660, 1400, 7, 2, [1430, 1430], 9),
            ]),
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('12.660')
            ->assertSee('7')
            ->assertSee('2')
            ->assertDontSee('Fila 2 de STOCK con total pallets')
            ->assertDontSee('Fila 2 de STOCK con cantidad')
            ->assertDontSee('Fila 2 de STOCK con numero de picos');
    }

    public function test_edelvives_imports_peak_larger_than_units_per_pallet_when_total_matches(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('C', 170, '130x90 170', 'Referencia con pico grande', 13000, 3750, 2, 1, [5500], 3),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk()
            ->assertDontSee('picos superiores a la cantidad total');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $stock = StockPallet::query()
            ->whereHas('item', fn ($query) => $query->where('client_id', $edelvives->id)->where('sku', '130x90 170'))
            ->firstOrFail();

        $this->assertSame(13000, $stock->quantity_units);
        $this->assertSame(3750, $stock->units_per_pallet);
        $this->assertSame(2, $stock->full_pallets);
        $this->assertSame(1, $stock->peaks_count);
        $this->assertSame(5500, $stock->peak_1);
    }

    public function test_edelvives_imports_rows_without_units_per_pallet_when_stock_is_in_peaks(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('B', 125, '102x72 125', 'Solo pico sin uds/pallet', 800, 0, 0, 1, [800], 1),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk()
            ->assertSee('Se importaran 1 filas de STOCK sin unidades por pallet, usando solo picos.');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $stock = StockPallet::query()
            ->whereHas('item', fn ($query) => $query->where('client_id', $edelvives->id)->where('sku', '102x72 125'))
            ->firstOrFail();

        $this->assertSame(800, $stock->quantity_units);
        $this->assertSame(0, $stock->units_per_pallet);
        $this->assertSame(0, $stock->full_pallets);
        $this->assertSame(1, $stock->peaks_count);
        $this->assertSame(800, $stock->peak_1);
    }

    public function test_edelvives_imports_same_sku_in_multiple_locations_without_overwriting(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeCurrentEdelvivesWorkbookRows([
                $this->makeCurrentEdelvivesDataRow('21', 80, 'ED-MULTI-LOC', '=', 500, 2, 0, [], 2),
                $this->makeCurrentEdelvivesDataRow('40-41', 80, 'ED-MULTI-LOC', '=', 500, 1, 1, [200], 2),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk()
            ->assertDontSee('ubicacion no reconocida')
            ->assertSee('1.700')
            ->assertSee('3')
            ->assertSee('1')
            ->assertSee('4');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(2, $stockImport->summary_json['available_rows']);
        $this->assertSame(1, $stockImport->summary_json['catalog_items_detected']);
        $this->assertSame(1700, $stockImport->summary_json['total_units']);
        $this->assertSame(3, $stockImport->summary_json['total_full_pallets']);
        $this->assertSame(1, $stockImport->summary_json['total_peaks_count']);
        $this->assertSame(4, $stockImport->summary_json['total_logistic_units']);

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect(route('stock.index', ['client_id' => $edelvives->id]));

        $stocks = StockPallet::query()
            ->where('client_id', $edelvives->id)
            ->whereHas('item', fn ($query) => $query->where('sku', 'ED-MULTI-LOC'))
            ->orderBy('location_text')
            ->get();

        $this->assertCount(2, $stocks);
        $this->assertSame(['21', '40-41'], $stocks->pluck('location_text')->all());
        $this->assertSame([1000, 700], $stocks->pluck('quantity_units')->all());
        $this->assertSame([2, 1], $stocks->pluck('full_pallets')->all());
        $this->assertSame([0, 1], $stocks->pluck('peaks_count')->all());
        $this->assertSame([0, 200], $stocks->pluck('peak_1')->all());
        $this->assertTrue($stocks->every(fn (StockPallet $stock): bool => $stock->lot === 'SIN LOTE'));

        $this->assertDatabaseHas('locations', [
            'code' => '40-41',
        ]);

        $overview = app(StockOverviewBuilder::class)->build($user, [
            'client_id' => $edelvives->id,
            'search' => 'ED-MULTI-LOC',
            'stock_state' => 'with_stock',
        ]);

        $this->assertSame(1, $overview['summary']['references_with_stock']);
        $this->assertSame(1700, $overview['summary']['total_units']);
        $this->assertSame(3, $overview['summary']['total_full_pallets']);
        $this->assertSame(1, $overview['summary']['total_peaks']);
        $this->assertSame(4, $overview['summary']['total_logistic_units']);
        $this->assertEqualsCanonicalizing(['21', '40-41'], $overview['rows']->pluck('location_label')->all());
    }

    public function test_edelvives_preview_and_import_align_with_realistic_workbook_totals_and_logistic_rows(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealisticEdelvivesWorkbookRows(),
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('CONTRACOLADOS')
            ->assertSee('413')
            ->assertSee('157')
            ->assertSee('178')
            ->assertSee('5.149.956')
            ->assertSee('858')
            ->assertSee('95')
            ->assertSee('953')
            ->assertDontSee('no se puede cuantificar para SKU CONTRACOLADOS');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(413, $stockImport->summary_json['total_rows']);
        $this->assertSame(178, $stockImport->summary_json['available_rows']);
        $this->assertSame(235, $stockImport->summary_json['skipped_rows']);
        $this->assertSame(235, $stockImport->summary_json['missing_sku_rows']);
        $this->assertSame(157, $stockImport->summary_json['catalog_items_detected']);
        $this->assertSame(5149956, $stockImport->summary_json['total_units']);
        $this->assertSame(858, $stockImport->summary_json['total_full_pallets']);
        $this->assertSame(95, $stockImport->summary_json['total_peaks_count']);
        $this->assertSame(953, $stockImport->summary_json['total_logistic_units']);
        $this->assertSame(0, $stockImport->summary_json['invalid_rows_ignored']);
        $this->assertSame(0, $stockImport->summary_json['real_errors']);

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect(route('stock.index', ['client_id' => $edelvives->id]));

        $this->assertSame(178, StockPallet::query()->where('client_id', $edelvives->id)->count());
        $this->assertSame(157, Item::query()->where('client_id', $edelvives->id)->count());

        $contracolados = StockPallet::query()
            ->where('client_id', $edelvives->id)
            ->whereHas('item', fn ($query) => $query->where('sku', 'CONTRACOLADOS'))
            ->firstOrFail();

        $this->assertSame(0, $contracolados->quantity_units);
        $this->assertSame(0, $contracolados->units_per_pallet);
        $this->assertSame(24, $contracolados->full_pallets);
        $this->assertSame(0, $contracolados->peaks_count);
        $this->assertSame('SIN LOTE', $contracolados->lot);

        $overview = app(StockOverviewBuilder::class)->build($user, [
            'client_id' => $edelvives->id,
            'stock_state' => 'with_stock',
        ]);

        $this->assertSame(157, $overview['summary']['references_with_stock']);
        $this->assertSame(5149956, $overview['summary']['total_units']);
        $this->assertSame(858, $overview['summary']['total_full_pallets']);
        $this->assertSame(95, $overview['summary']['total_peaks']);
        $this->assertSame(953, $overview['summary']['total_logistic_units']);
    }

    public function test_edelvives_normalizes_special_locations_without_losing_stock(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('FONDO?', 90, '90x118 90', 'Ubicacion fondo', 1200, 0, 0, 1, [1200], 1),
                $this->makeRealEdelvivesDataRow('', 80, '80x60 80', 'Sin ubicacion', 600, 0, 0, 1, [600], 1),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk()
            ->assertSee('Se normalizaran 1 ubicaciones especiales en STOCK.')
            ->assertSee('Se importaran 1 filas de STOCK sin ubicacion en SIN UBICACION.');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $fondoStock = StockPallet::query()
            ->whereHas('item', fn ($query) => $query->where('client_id', $edelvives->id)->where('sku', '90x118 90'))
            ->firstOrFail();
        $pendingStock = StockPallet::query()
            ->whereHas('item', fn ($query) => $query->where('client_id', $edelvives->id)->where('sku', '80x60 80'))
            ->firstOrFail();

        $this->assertSame('FONDO', $fondoStock->location_text);
        $this->assertSame('SIN UBICACION', $pendingStock->location_text);
    }

    public function test_edelvives_reimport_replaces_only_its_previous_snapshot_without_touching_friesland(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $frieslandItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'FR-STABLE',
            'description' => 'Friesland estable',
            'units_per_pallet' => 1000,
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frieslandItem->id,
            'lot' => 'FR-1',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
            'full_pallets' => 1,
        ]);

        $firstFile = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('A', 100, 'ED-ONE', 'Primera carga', 1000, 1000, 1, 0, [], 1),
                $this->makeRealEdelvivesDataRow('B', 120, 'ED-TWO', 'Primera carga dos', 800, 0, 0, 1, [800], 1),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $firstFile,
        ])->assertOk();

        $firstImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $firstImport->id,
        ])->assertRedirect();

        $this->assertSame(2, StockPallet::query()->where('client_id', $edelvives->id)->count());

        $secondFile = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('C', 90, 'ED-THREE', 'Segunda carga', 600, 0, 0, 1, [600], 1),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $secondFile,
        ])->assertOk();

        $secondImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $secondImport->id,
        ])->assertRedirect();

        $this->assertSame(1, StockPallet::query()->where('client_id', $edelvives->id)->where('active', true)->count());
        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $edelvives->id,
            'location_text' => 'C',
        ]);
        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $edelvives->id,
            'location_text' => 'A',
            'active' => false,
            'quantity_units' => 0,
        ]);
        $this->assertDatabaseHas('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'FR-1',
        ]);
    }

    public function test_edelvives_import_preserves_historical_allocations_and_keeps_current_snapshot_idempotent(): void
    {
        [, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $clientUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);
        $historicalItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'ED-HISTORY',
            'units_per_pallet' => 500,
        ]);
        $historicalStock = StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $historicalItem->id,
            'lot' => 'HIST-001',
            'quantity_units' => 1500,
            'units_per_pallet' => 500,
            'full_pallets' => 2,
            'peaks_count' => 1,
            'warehouse_pallets' => 3,
            'peak_1' => 500,
            'active' => true,
        ]);
        $dispatch = GoodsDispatch::factory()->create([
            'client_id' => $edelvives->id,
            'created_by' => $superadmin->id,
            'status' => GoodsDispatch::STATUS_SENT,
        ]);
        $dispatchLine = GoodsDispatchLine::factory()->create([
            'goods_dispatch_id' => $dispatch->id,
            'item_id' => $historicalItem->id,
            'stock_pallet_id' => $historicalStock->id,
            'sku' => $historicalItem->sku,
        ]);
        $allocation = $dispatchLine->allocations()->create([
            'stock_pallet_id' => $historicalStock->id,
            'lot' => $historicalStock->lot,
            'loaded_pallets' => 1,
            'loaded_partial_units' => 0,
        ]);

        foreach (range(1, 2) as $importNumber) {
            $file = $this->makeWorkbookUpload([
                'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                    $this->makeRealEdelvivesDataRow('C', 100, 'ED-CURRENT', 'Foto actual', 600, 600, 1, 0, [], 1),
                ]),
            ]);

            $this->actingAs($superadmin)->post(route('stock.import.preview'), [
                'client_id' => $edelvives->id,
                'file' => $file,
            ])->assertOk();

            $stockImport = StockImport::query()->latest('id')->firstOrFail();

            $this->actingAs($superadmin)->post(route('stock.import.confirm'), [
                'stock_import_id' => $stockImport->id,
            ])->assertRedirect();

            $this->assertSame(StockImport::STATUS_IMPORTED, $stockImport->fresh()->status, 'Import '.$importNumber.' was not completed.');
            $this->assertSame(1, StockPallet::query()
                ->where('client_id', $edelvives->id)
                ->where('active', true)
                ->count());
            $this->assertSame(600, (int) StockPallet::query()
                ->where('client_id', $edelvives->id)
                ->where('active', true)
                ->sum('quantity_units'));
        }

        $historicalStock->refresh();

        $this->assertFalse($historicalStock->active);
        $this->assertSame(0, $historicalStock->quantity_units);
        $this->assertSame(0, $historicalStock->full_pallets);
        $this->assertSame(0, $historicalStock->peaks_count);
        $this->assertSame('0.00', $historicalStock->warehouse_pallets);
        $this->assertSame(0, $historicalStock->peak_1);
        $this->assertDatabaseHas('goods_dispatch_line_allocations', [
            'id' => $allocation->id,
            'stock_pallet_id' => $historicalStock->id,
        ]);

        $this->actingAs($clientUser)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('ED-CURRENT')
            ->assertDontSee('ED-HISTORY');
    }

    public function test_edelvives_client_sees_only_imported_edelvives_inventory_after_import(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $frieslandClientUser = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $edelvivesClientUser = $this->makeUserWithRole(Role::CLIENTE, $edelvives);

        $frieslandItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'FR-VISIBLE',
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frieslandItem->id,
            'lot' => 'FR-LOT',
            'quantity_units' => 700,
            'units_per_pallet' => 700,
            'full_pallets' => 1,
        ]);

        $file = $this->makeWorkbookUpload([
            'STOCK' => $this->makeRealEdelvivesWorkbookRows([
                $this->makeRealEdelvivesDataRow('C', 100, 'ED-VISIBLE', 'Papel Edelvives', 1880, 1000, 1, 1, [880], 2),
            ]),
        ]);

        $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $edelvives->id,
            'file' => $file,
        ])->assertOk();

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect();

        $this->actingAs($edelvivesClientUser)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('ED-VISIBLE')
            ->assertDontSee('FR-VISIBLE');

        $this->actingAs($frieslandClientUser)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('FR-VISIBLE')
            ->assertDontSee('ED-VISIBLE');
    }

    public function test_friesland_formula_quantity_cells_do_not_break_import_or_create_phantom_rows(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        // La columna CANTIDAD del Excel real de Friesland es una formula (=(F*G)+picos).
        // OpenSpout devuelve el texto de la formula, no su valor: nunca debe interpretarse como numero.
        $file = $this->makeWorkbookUpload([
            'GENERAL' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos', 'Pico 1'],
                ['11', 'Fecula de patata', 'NO LOTE', '=(E2*F2)+(H2)', 1000, 0, 0, 0],
                ['SKU-A', 'Producto A', 'LOT-A', '=(E3*F3)+(H3)', 1000, 5, 0, 0],
                ['LASTOPP248', 'Media pallet', 'LOT-HALF', '=(E4*F4)', 2000, 0.5, 0, 0],
            ],
            'BOBINAS' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos', 'Pico 1', 'Pico 2'],
                ['CRYOVAC5', 'Film sin uds', 'LOT-CRYO', '=(E2*F2)+(H2)', 0, 0, 0, 55000, 0],
                ['_FILM0519', 'Interno bobina', 'LOT-INT', '=(E3*F3)', 14400, 0, 3, 4000, 5000],
            ],
            'VARIOS' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets', 'Picos', 'Pico 1'],
                ['_CAJA057', 'Caja A', 'LOT-C1', '=(E2*F2)', 60, 1, 0, 0],
                ['_CAJA057', 'Caja B', 'LOT-C2', '=(E3*F3)', 80, 1, 0, 0],
                ['_PALLET EUR', 'Sin stock', 'LOT-EUR', '=(E4*F4)', 9, 0, 0, 0],
            ],
        ]);

        $response = $this->actingAs($user)->post(route('stock.import.preview'), [
            'client_id' => $friesland->id,
            'file' => $file,
        ]);

        $response->assertOk()
            ->assertSee('Refs internas detectadas')
            ->assertSee('Refs internas con stock')
            ->assertSee('import-error-detail', false)
            ->assertSee('sin pallets ni picos operativos');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();
        $totals = $stockImport->summary_json;

        // Sin errores bloqueantes: CRYOVAC5 (pico sin pallets/picos operativos) es aviso, no error SQL.
        $this->assertSame(0, $totals['real_errors']);
        $this->assertSame(0, $totals['invalid_rows_ignored']);

        // La formula no genera cantidades gigantes. Cantidades del desglose:
        // SKU-A 5000 + LASTOPP248 2000 + _FILM0519 9000 (picos) + _CAJA057 60 + 80 = 16140.
        $this->assertSame(16140, $totals['total_units']);

        // Palets almacen = PALLETS + PICOS con decimales: 5 + 0.5 + 3 + 1 + 1 = 10.5.
        $this->assertSame(10.5, (float) $totals['total_warehouse_pallets']);
        $this->assertSame(5.5, (float) $totals['category_warehouse_pallets'][StockPallet::CATEGORY_IN_USE]);
        $this->assertSame(5.0, (float) $totals['category_warehouse_pallets'][StockPallet::CATEGORY_MISC]);

        // 4 lineas internas "_" detectadas (incluida la que no tiene stock y el SKU repetido); 3 con stock.
        $this->assertSame(4, $totals['internal_references_detected']);
        $this->assertSame(3, $totals['internal_rows']);

        // La confirmacion no debe lanzar SQLSTATE 22003 (out of range).
        $this->actingAs($user)->post(route('stock.import.confirm'), [
            'stock_import_id' => $stockImport->id,
        ])->assertRedirect(route('stock.index', ['client_id' => $friesland->id]));

        // 5 partidas con stock: SKU-A, LASTOPP248, _FILM0519, _CAJA057 x2. CRYOVAC5 y _PALLET EUR quedan fuera.
        $this->assertSame(5, StockPallet::query()->where('client_id', $friesland->id)->count());

        // El item fantasma "11" se crea como articulo sin stock, pero no como partida.
        $this->assertDatabaseHas('items', ['client_id' => $friesland->id, 'sku' => '11']);
        $this->assertDatabaseMissing('stock_pallets', ['client_id' => $friesland->id, 'lot' => 'NO LOTE']);

        // CRYOVAC5: item creado, sin partida logistica.
        $this->assertDatabaseHas('items', ['client_id' => $friesland->id, 'sku' => 'CRYOVAC5']);
        $this->assertDatabaseMissing('stock_pallets', ['client_id' => $friesland->id, 'lot' => 'LOT-CRYO']);

        // Media pallet: 0.5 palets de almacen (no redondeado a 1) y cantidad razonable (no gigante).
        $halfPallet = StockPallet::query()
            ->where('client_id', $friesland->id)->where('lot', 'LOT-HALF')->firstOrFail();
        $this->assertSame(0.5, (float) $halfPallet->warehouse_pallets);
        $this->assertLessThan(1_000_000, $halfPallet->quantity_units);

        // SKU-A: cantidad del desglose, no de la formula.
        $skuA = StockPallet::query()
            ->where('client_id', $friesland->id)->where('lot', 'LOT-A')->firstOrFail();
        $this->assertSame(5000, $skuA->quantity_units);
        $this->assertSame(5.0, (float) $skuA->warehouse_pallets);

        // Ninguna partida con cantidad 0 y palets almacen 0.
        $this->assertSame(0, StockPallet::query()
            ->where('client_id', $friesland->id)
            ->where('quantity_units', 0)
            ->where('warehouse_pallets', 0)
            ->count());

        // El maximo quantity_units cabe con holgura en la columna (nunca fuera de rango).
        $this->assertLessThan(1_000_000, (int) StockPallet::query()
            ->where('client_id', $friesland->id)->max('quantity_units'));
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
            Client::query()->where('code', 'FRIESLAND')->firstOrFail(),
            Client::query()->where('code', 'EDELVIVES')->firstOrFail(),
        ];
    }

    private function makeUserWithRole(string $roleSlug, ?Client $client = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $roleSlug === Role::CLIENTE ? $client?->id : null,
        ]);
    }

    /**
     * @param  array<string, array<int, array<int, mixed>>>  $sheets
     */
    private function makeWorkbookUpload(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'stock-import-');
        $xlsxPath = $path.'.xlsx';

        if ($path !== false && file_exists($path)) {
            unlink($path);
        }

        $writer = new Writer;
        $writer->openToFile($xlsxPath);

        $firstSheet = true;

        foreach ($sheets as $sheetName => $rows) {
            $sheet = $firstSheet
                ? $writer->getCurrentSheet()
                : $writer->addNewSheetAndMakeItCurrent();

            $sheet->setName($sheetName);
            $firstSheet = false;

            foreach ($rows as $rowValues) {
                $writer->addRow(Row::fromValues($rowValues));
            }
        }

        $writer->close();

        return UploadedFile::fake()->createWithContent('stock.xlsx', file_get_contents($xlsxPath) ?: '');
    }

    /**
     * @param  array<int, array<int, mixed>>  $dataRows
     * @return array<int, array<int, mixed>>
     */
    private function makeRealEdelvivesWorkbookRows(array $dataRows, mixed $descriptionHeader = 13.0, mixed $auxHeader = 953): array
    {
        return array_merge([[
            'NAVE 19',
            'GRAMAJE',
            'SKU',
            $descriptionHeader,
            'CANTIDAD',
            'UNIDADES x PALLET',
            'PALLETS',
            'PICOS',
            'PICO 1',
            'PICO 2',
            'PICO 3',
            'PICO 4',
            'PICO 5',
            'PICO 6',
            'PICO 7',
            'PICO 8',
            'PICO 9',
            'PICO 10',
            'TOTAL PALLETS',
            $auxHeader,
        ]], $dataRows);
    }

    /**
     * @param  array<int, array<int, mixed>>  $dataRows
     * @return array<int, array<int, mixed>>
     */
    private function makeCurrentEdelvivesWorkbookRows(array $dataRows, mixed $formatHeader = 13.0, mixed $auxHeader = '=SUM(R2:R1000)'): array
    {
        return array_merge([[
            'NAVE 19',
            'GRAMAJE',
            $formatHeader,
            'CANTIDAD',
            'UNIDADES x PALLET',
            'PALLETS',
            'PICOS',
            'PICO 1',
            'PICO 2',
            'PICO 3',
            'PICO 4',
            'PICO 5 ',
            'PICO 6',
            'PICO 7',
            'PICO 8',
            'PICO 9',
            'PICO 10',
            'TOTAL PALLETS',
            $auxHeader,
        ]], $dataRows);
    }

    /**
     * @param  array<int, mixed>  $peakValues
     * @return array<int, mixed>
     */
    private function makeRealEdelvivesDataRow(
        mixed $location,
        mixed $grammage,
        string $sku,
        string $description,
        mixed $quantity,
        mixed $unitsPerPallet,
        mixed $fullPallets,
        mixed $peaksCount,
        array $peakValues = [],
        mixed $reportedTotalPallets = null,
        mixed $ignoredAux = null,
    ): array {
        $row = [
            $location,
            $grammage,
            $sku,
            $description,
            $quantity,
            $unitsPerPallet,
            $fullPallets,
            $peaksCount,
        ];

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $row[] = $peakValues[$peakNumber - 1] ?? 0;
        }

        $row[] = $reportedTotalPallets;
        $row[] = $ignoredAux;

        return $row;
    }

    /**
     * @param  array<int, mixed>  $peakValues
     * @return array<int, mixed>
     */
    private function makeCurrentEdelvivesDataRow(
        mixed $location,
        mixed $grammage,
        string $reference,
        mixed $quantity,
        mixed $unitsPerPallet,
        mixed $fullPallets,
        mixed $peaksCount,
        array $peakValues = [],
        mixed $reportedTotalPallets = null,
        mixed $ignoredAux = null,
    ): array {
        $row = [
            $location,
            $grammage,
            $reference,
            $quantity,
            $unitsPerPallet,
            $fullPallets,
            $peaksCount,
        ];

        foreach (range(1, StockPallet::MAX_PEAK_COLUMNS) as $peakNumber) {
            $row[] = $peakValues[$peakNumber - 1] ?? 0;
        }

        $row[] = $reportedTotalPallets;
        $row[] = $ignoredAux;

        return $row;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function makeRealisticEdelvivesWorkbookRows(): array
    {
        $rows = [
            $this->makeRealEdelvivesDataRow('0', 0, 'CONTRACOLADOS', 'Stock logistico sin unidades', 0, 0, 24, 0, [], 24),
        ];

        $locations = array_merge(array_map('strval', range(0, 45)), ['A', 'B', 'C', 'D', 'E', 'F', 'FONDO', 'SIN UBICACION']);
        $peakValues = array_merge(array_fill(0, 94, 1536), [1572]);
        $peakValueIndex = 0;

        for ($rowIndex = 1; $rowIndex <= 177; $rowIndex++) {
            $skuIndex = $rowIndex <= 156 ? $rowIndex : $rowIndex - 156;
            $sku = sprintf('ED-SKU-%03d', $skuIndex);
            $location = $locations[$rowIndex % count($locations)];
            $fullPallets = $rowIndex <= 126 ? 5 : 4;
            $peaksCount = 0;
            $rowPeakValues = [];

            if ($rowIndex <= 69) {
                $peaksCount = 1;
                $rowPeakValues[] = $peakValues[$peakValueIndex++];
            } elseif ($rowIndex <= 82) {
                $peaksCount = 2;
                $rowPeakValues[] = $peakValues[$peakValueIndex++];
                $rowPeakValues[] = $peakValues[$peakValueIndex++];
            }

            $rows[] = $this->makeRealEdelvivesDataRow(
                $location,
                80 + ($skuIndex % 10) * 10,
                $sku,
                'Producto '.$sku,
                ($fullPallets * 6000) + array_sum($rowPeakValues),
                6000,
                $fullPallets,
                $peaksCount,
                $rowPeakValues,
                $fullPallets + $peaksCount,
            );
        }

        for ($rowIndex = 1; $rowIndex <= 235; $rowIndex++) {
            $rows[] = $this->makeRealEdelvivesDataRow(
                $locations[$rowIndex % count($locations)],
                '',
                '',
                'Fila sin SKU '.$rowIndex,
                0,
                0,
                0,
                0,
                [],
                null,
            );
        }

        return $this->makeRealEdelvivesWorkbookRows($rows);
    }
}
