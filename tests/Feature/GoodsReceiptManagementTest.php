<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class GoodsReceiptManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_administracion_and_almacen_can_view_goods_receipts_index(): void
    {
        $this->seed(RoleSeeder::class);

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('goods-receipts.index'))
                ->assertOk()
                ->assertSee('Entradas de mercancía');
        }
    }

    public function test_cliente_cannot_view_goods_receipts_index(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('goods-receipts.index'))
            ->assertForbidden();
    }

    public function test_superadmin_can_create_supplier(): void
    {
        $this->seed(RoleSeeder::class);

        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $client = Client::factory()->create([
            'name' => 'CLIENTE DEMO',
            'code' => 'CLIENTE_DEMO',
        ]);

        $this->actingAs($superadmin)
            ->post(route('suppliers.store'), [
                'client_id' => $client->id,
                'name' => 'PROVEEDOR DEMO',
                'tax_id' => 'B12345678',
                'email' => 'proveedor@example.com',
                'phone' => '900100200',
                'contact_name' => 'Laura Demo',
                'notes' => 'Proveedor de prueba',
                'active' => '1',
            ])
            ->assertRedirect(route('suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'client_id' => $client->id,
            'name' => 'PROVEEDOR DEMO',
            'tax_id' => 'B12345678',
            'active' => true,
        ]);
    }

    public function test_almacen_can_view_suppliers_but_cannot_create_or_edit(): void
    {
        $this->seed(RoleSeeder::class);

        $supplier = Supplier::factory()->create([
            'name' => 'PROVEEDOR LECTURA',
        ]);
        $almacen = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($almacen)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee('PROVEEDOR LECTURA');

        $this->actingAs($almacen)
            ->get(route('suppliers.create'))
            ->assertForbidden();

        $this->actingAs($almacen)
            ->get(route('suppliers.edit', $supplier))
            ->assertForbidden();
    }

    public function test_can_create_draft_goods_receipt_with_one_line(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-2026-001',
                'external_document_number' => 'EXT-001',
                'received_at' => '2026-06-26',
                'notes' => 'Entrada inicial',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-ENT-001',
                        'description' => 'Producto de entrada',
                        'lot' => 'LOT-001',
                        'quantity_units' => 2500,
                        'units_per_pallet' => 1000,
                        'pallet_count' => 0,
                        'pico_units' => '',
                        'location_id' => $location->id,
                        'notes' => 'Linea principal',
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-2026-001')->firstOrFail();

        $this->assertDatabaseHas('goods_receipts', [
            'id' => $receipt->id,
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'status' => GoodsReceipt::STATUS_DRAFT,
            'created_by' => $user->id,
        ]);

        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'sku' => 'SKU-ENT-001',
            'quantity_units' => 2500,
            'units_per_pallet' => 1000,
            'pallet_count' => 2,
            'pico_units' => 500,
            'location_id' => $location->id,
        ]);
    }

    public function test_creating_goods_receipt_with_item_id_completes_sku_description_and_units_per_pallet(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        [$client, $supplier, $location] = $this->makeReceiptContext();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0001',
            'description' => 'Caja de prueba',
            'lot' => 'LOT-CAJA',
            'lot_key' => 'LOT-CAJA',
            'units_per_pallet' => 700,
        ]);

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-ITEM-001',
                'lines' => [
                    [
                        'item_id' => $item->id,
                        'sku' => '',
                        'description' => '',
                        'lot' => '',
                        'quantity_units' => 15000,
                        'units_per_pallet' => '',
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                        'notes' => 'Autocompletado desde item',
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-ITEM-001')->firstOrFail();

        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => 'CAJA0001',
            'description' => 'Caja de prueba',
            'lot' => 'LOT-CAJA',
            'units_per_pallet' => 700,
            'pallet_count' => 21,
            'pico_units' => 300,
        ]);
    }

    public function test_store_calculates_pallet_count_and_pico_for_15000_units_and_700_units_per_pallet(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-CALC-001',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'CAJA0001',
                        'description' => 'Caja calculada',
                        'lot' => 'LOT-CALC',
                        'quantity_units' => 15000,
                        'units_per_pallet' => 700,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-CALC-001')->firstOrFail();

        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'quantity_units' => 15000,
            'units_per_pallet' => 700,
            'pallet_count' => 21,
            'pico_units' => 300,
        ]);
    }

    public function test_store_respects_units_per_pallet_override_for_selected_item(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        [$client, $supplier, $location] = $this->makeReceiptContext();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0002',
            'description' => 'Caja con override',
            'lot' => null,
            'lot_key' => '',
            'units_per_pallet' => 700,
        ]);

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-OVERRIDE-001',
                'lines' => [
                    [
                        'item_id' => $item->id,
                        'sku' => '',
                        'description' => '',
                        'lot' => '',
                        'quantity_units' => 15000,
                        'units_per_pallet' => 750,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-OVERRIDE-001')->firstOrFail();

        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'units_per_pallet' => 750,
            'pallet_count' => 20,
            'pico_units' => null,
        ]);
    }

    public function test_received_at_defaults_to_today_when_creating_goods_receipt(): void
    {
        Carbon::setTestNow('2026-06-26 10:15:00');
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-TODAY-001',
                'received_at' => '',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-TODAY-001',
                        'description' => 'Entrada con fecha por defecto',
                        'lot' => '',
                        'quantity_units' => 700,
                        'units_per_pallet' => 700,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-TODAY-001')->firstOrFail();

        $this->assertSame('2026-06-26', $receipt->received_at?->format('Y-m-d'));

        Carbon::setTestNow();
    }

    public function test_can_attach_valid_document_to_goods_receipt(): void
    {
        Storage::fake('public');
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->createDraftReceipt($user);

        $this->actingAs($user)
            ->post(route('goods-receipts.attach-document', $receipt), [
                'document' => UploadedFile::fake()->create('albaran.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $receipt->refresh();

        $this->assertNotNull($receipt->document_path);
        $this->assertSame('albaran.pdf', $receipt->document_original_name);
        Storage::disk('public')->assertExists($receipt->document_path);
    }

    public function test_confirming_goods_receipt_generates_stock_pallets_and_prevents_duplicates(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-2026-002',
                'received_at' => '2026-06-26',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-CONF-001',
                        'description' => 'Producto confirmado',
                        'lot' => 'LOT-CF1',
                        'quantity_units' => 2500,
                        'units_per_pallet' => 1000,
                        'pallet_count' => 0,
                        'pico_units' => '',
                        'location_id' => $location->id,
                        'notes' => 'Linea con stock',
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-2026-002')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $receipt->refresh();

        $this->assertSame(GoodsReceipt::STATUS_CONFIRMED, $receipt->status);
        $this->assertNotNull($receipt->confirmed_at);
        $this->assertSame($user->id, $receipt->confirmed_by);
        $this->assertDatabaseCount('stock_pallets', 3);
        $this->assertDatabaseHas('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
            'quantity_units' => 1000,
            'location_id' => $location->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
            'quantity_units' => 500,
            'location_id' => $location->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('items', [
            'client_id' => $client->id,
            'sku' => 'SKU-CONF-001',
            'lot_key' => 'LOT-CF1',
        ]);

        $this->actingAs($user)
            ->from(route('goods-receipts.show', $receipt))
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHasErrors('goods_receipt');

        $this->assertDatabaseCount('stock_pallets', 3);
    }

    public function test_confirmed_goods_receipt_status_is_visible_and_stock_page_reflects_it(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$receipt] = $this->createConfirmedReceipt($user, 'SKU-STOCK-001');

        $this->actingAs($user)
            ->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertSee('Confirmada')
            ->assertSee('SKU-STOCK-001');

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('SKU-STOCK-001');
    }

    public function test_confirming_15000_units_with_700_units_per_pallet_generates_21_full_pallets_and_one_peak(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'CAJA0001',
            'description' => 'Caja confirmada',
            'lot' => 'LOT-C700',
            'lot_key' => 'LOT-C700',
            'units_per_pallet' => 700,
        ]);

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-CONF-700',
                'received_at' => '2026-06-26',
                'lines' => [
                    [
                        'item_id' => $item->id,
                        'sku' => '',
                        'description' => '',
                        'lot' => '',
                        'quantity_units' => 15000,
                        'units_per_pallet' => '',
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-CONF-700')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $this->assertDatabaseCount('stock_pallets', 22);
        $this->assertSame(22, $receipt->fresh()->stockPallets()->count());
        $this->assertSame(21, $receipt->lines()->firstOrFail()->fresh()->pallet_count);
        $this->assertSame(300, $receipt->lines()->firstOrFail()->fresh()->pico_units);
        $this->assertDatabaseHas('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
            'quantity_units' => 700,
            'location_id' => $location->id,
            'active' => true,
        ]);
        $this->assertDatabaseHas('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
            'quantity_units' => 300,
            'location_id' => $location->id,
            'active' => true,
        ]);
    }

    private function createDraftReceipt(User $user): GoodsReceipt
    {
        [$client, $supplier] = $this->makeReceiptContext();

        return GoodsReceipt::query()->create([
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'receipt_number' => 'ALB-DOC-001',
            'external_document_number' => 'DOC-001',
            'status' => GoodsReceipt::STATUS_DRAFT,
            'received_at' => '2026-06-26',
            'notes' => 'Pendiente de documento',
            'created_by' => $user->id,
        ]);
    }

    /**
     * @return array{0: GoodsReceipt, 1: GoodsReceiptLine, 2: Location}
     */
    private function createConfirmedReceipt(User $user, string $sku): array
    {
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => $sku,
            'description' => 'Articulo ya catalogado',
            'lot' => 'LOT-STK',
            'lot_key' => 'LOT-STK',
            'units_per_pallet' => 1000,
        ]);

        $receipt = GoodsReceipt::query()->create([
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'receipt_number' => 'ALB-STOCK-001',
            'status' => GoodsReceipt::STATUS_DRAFT,
            'received_at' => '2026-06-26',
            'created_by' => $user->id,
        ]);

        $line = GoodsReceiptLine::query()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => $sku,
            'description' => $item->description,
            'lot' => 'LOT-STK',
            'quantity_units' => 1500,
            'units_per_pallet' => 1000,
            'pallet_count' => 1,
            'pico_units' => 500,
            'location_id' => $location->id,
        ]);

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        return [$receipt->fresh(), $line->fresh(), $location];
    }

    /**
     * @return array{0: Client, 1: Supplier, 2: Location}
     */
    private function makeReceiptContext(): array
    {
        $client = Client::factory()->create();
        $supplier = Supplier::factory()->create([
            'client_id' => $client->id,
        ]);
        $warehouse = Warehouse::factory()->create([
            'client_id' => null,
            'code' => 'WH-ENT',
        ]);
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'ENT-A-01',
        ]);

        return [$client, $supplier, $location];
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }
}
