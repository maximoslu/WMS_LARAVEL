<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockImport;
use App\Models\StockPallet;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
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

    public function test_preview_detects_supported_sheets_and_ignores_etiquetas(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => [
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
                ['Texto'],
                ['Ignorar'],
            ],
        ]);

        $this->actingAs($user)
            ->post(route('stock.import.preview'), [
                'client_id' => $friesland->id,
                'file' => $file,
            ])
            ->assertOk()
            ->assertSee('STOCK')
            ->assertSee('BOBINAS')
            ->assertSee('BLOQUEADO')
            ->assertSee('ETIQUETAS')
            ->assertSee('Hoja ignorada: ETIQUETAS.');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(['STOCK', 'BOBINAS', 'BLOQUEADO'], $stockImport->detected_sheets_json['processed']);
        $this->assertSame(['ETIQUETAS'], $stockImport->detected_sheets_json['ignored']);
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
            ->assertSee('Errores reales')
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

    public function test_preview_ignores_internal_references_starting_with_asterisk_or_underscore_without_errors(): void
    {
        [$friesland] = $this->seedBaseData();
        Storage::fake('local');

        $user = $this->makeUserWithRole(Role::SUPERADMIN);
        $file = $this->makeWorkbookUpload([
            'STOCK' => [
                ['SKU', 'Descripcion', 'Lote', 'Cantidad', 'Uds/Pallet', 'Pallets'],
                ['***BLOQUEADO***', 'Interna', 'LOT-X', 200, null, 0],
                ['_BANDEJA23', 'Interna 2', 'LOT-Y', 100, null, 0],
                ['FR-VALID', 'Valida', 'LOT-Z', 1000, 1000, 1],
            ],
        ]);

        $response = $this->actingAs($user)
            ->post(route('stock.import.preview'), [
                'client_id' => $friesland->id,
                'file' => $file,
            ]);

        $response->assertOk()
            ->assertSee('Se han ignorado referencias internas que empiezan por * o _.')
            ->assertDontSee('no se importara por no tener unidades por pallet validas para SKU ***BLOQUEADO***')
            ->assertDontSee('no se importara por no tener unidades por pallet validas para SKU _BANDEJA23');

        $stockImport = StockImport::query()->latest('id')->firstOrFail();

        $this->assertSame(2, $stockImport->summary_json['excluded_rows']);
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

        $this->assertDatabaseMissing('items', [
            'client_id' => $friesland->id,
            'sku' => '_BANDEJA23',
        ]);

        $this->assertDatabaseMissing('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'LOT-X',
        ]);

        $this->assertDatabaseMissing('stock_pallets', [
            'client_id' => $friesland->id,
            'lot' => 'LOT-Y',
        ]);

        $this->assertDatabaseHas('items', [
            'client_id' => $friesland->id,
            'sku' => 'FR-VALID',
        ]);
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
            ->assertSee('Filas invalidas omitidas')
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

        $this->assertDatabaseMissing('stock_pallets', [
            'client_id' => $friesland->id,
            'item_id' => $legacyItem->id,
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

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }

    /**
     * @param  array<string, array<int, array<int, int|string|null>>>  $sheets
     */
    private function makeWorkbookUpload(array $sheets): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'stock-import-');
        $xlsxPath = $path.'.xlsx';

        if ($path !== false && file_exists($path)) {
            unlink($path);
        }

        $writer = new Writer();
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
}
