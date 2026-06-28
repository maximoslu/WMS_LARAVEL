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
