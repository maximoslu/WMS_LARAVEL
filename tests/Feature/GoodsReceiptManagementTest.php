<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\Supplier;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\GoodsReceipts\GoodsReceiptAiExtractionResult;
use App\Services\GoodsReceipts\GoodsReceiptAiExtractorInterface;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
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

    public function test_created_goods_receipt_appears_in_index_with_default_filters(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-HIST-001',
                'external_document_number' => 'DOC-HIST-001',
                'received_at' => '2026-06-29',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-HIST-001',
                        'description' => 'Historico visible',
                        'lot' => 'LOT-HIST',
                        'quantity_units' => 1000,
                        'units_per_pallet' => 1000,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('goods-receipts.index'))
            ->assertOk()
            ->assertSee('ALB-HIST-001')
            ->assertSee('DOC-HIST-001')
            ->assertSee($supplier->name);
    }

    public function test_goods_receipts_index_supports_pagination_and_filters(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$clientA, $supplierA] = $this->makeReceiptContext();
        [$clientB, $supplierB] = $this->makeReceiptContext();

        foreach (range(1, 28) as $index) {
            GoodsReceipt::factory()->create([
                'client_id' => $index <= 14 ? $clientA->id : $clientB->id,
                'supplier_id' => $index <= 14 ? $supplierA->id : $supplierB->id,
                'receipt_number' => sprintf('ALB-PAG-%03d', $index),
                'external_document_number' => sprintf('DOC-PAG-%03d', $index),
                'status' => $index <= 14 ? GoodsReceipt::STATUS_DRAFT : GoodsReceipt::STATUS_CONFIRMED,
                'received_at' => $index <= 14 ? '2026-06-28' : '2026-06-29',
                'created_by' => $user->id,
            ]);
        }

        $this->actingAs($user)
            ->get(route('goods-receipts.index'))
            ->assertOk()
            ->assertSee('ALB-PAG-028')
            ->assertSee('ALB-PAG-004')
            ->assertDontSee('ALB-PAG-001');

        $this->actingAs($user)
            ->get(route('goods-receipts.index', ['client_id' => $clientA->id]))
            ->assertOk()
            ->assertSee('ALB-PAG-014')
            ->assertDontSee('ALB-PAG-028');

        $this->actingAs($user)
            ->get(route('goods-receipts.index', ['status' => GoodsReceipt::STATUS_CONFIRMED]))
            ->assertOk()
            ->assertSee('ALB-PAG-028')
            ->assertDontSee('ALB-PAG-014');

        $this->actingAs($user)
            ->get(route('goods-receipts.index', ['search' => 'DOC-PAG-003']))
            ->assertOk()
            ->assertSee('DOC-PAG-003')
            ->assertDontSee('DOC-PAG-028');

        $this->actingAs($user)
            ->get(route('goods-receipts.index', [
                'date_from' => '2026-06-29',
                'date_to' => '2026-06-29',
            ]))
            ->assertOk()
            ->assertSee('ALB-PAG-028')
            ->assertDontSee('ALB-PAG-014');
    }

    public function test_goods_receipt_detail_renders_refined_structure(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$receipt] = $this->createConfirmedReceipt($user, 'SKU-DETAIL-001');

        $this->actingAs($user)
            ->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertSee('goods-receipt-header-card', false)
            ->assertSee('goods-receipt-card--document-secondary', false)
            ->assertSee('Guardar documento')
            ->assertDontSee('Procesar con IA')
            ->assertDontSee('Partidas generadas')
            ->assertDontSee('Documento e IA futura');
    }

    public function test_goods_receipt_create_form_is_simplified_and_uses_cards_without_line_notes(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('goods-receipts.create'))
            ->assertOk()
            ->assertDontSee('Documento externo')
            ->assertSee('Documento del proveedor / albaran')
            ->assertSee('goods-receipt-line-list', false)
            ->assertDontSee('goods-receipt-lines-table', false)
            ->assertDontSee('[notes]', false)
            ->assertSee('Referencia y cantidades')
            ->assertSee('Trazabilidad de esta entrada. No se guarda como maestro del articulo.')
            ->assertSee('Crear borrador e interpretar con IA')
            ->assertSee('Si vas a interpretar un albaran con IA, puedes dejar las lineas vacias.');
    }

    public function test_crear_borrador_con_documento_muestra_cta_ia(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('goods-receipts.create'))
            ->assertOk()
            ->assertSee('Crear borrador')
            ->assertSee('Crear borrador e interpretar con IA')
            ->assertSee('Adjunta un albaran para activar la interpretacion IA desde este paso.');
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

    public function test_almacen_can_view_create_and_edit_suppliers(): void
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
            ->assertOk();

        $this->actingAs($almacen)
            ->get(route('suppliers.edit', $supplier))
            ->assertOk();
    }

    public function test_can_create_draft_goods_receipt_with_one_line(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_draft',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-2026-001',
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

    public function test_crear_borrador_manual_sigue_funcionando(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_draft',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-MANUAL-001',
                'received_at' => '2026-07-06',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-MANUAL-001',
                        'description' => 'Entrada manual intacta',
                        'lot' => 'LOT-MANUAL',
                        'quantity_units' => 1200,
                        'units_per_pallet' => 600,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-MANUAL-001')->firstOrFail();

        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->status);
        $this->assertSame(1, $receipt->lines()->count());
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
            'lot' => null,
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
            'lot' => null,
            'units_per_pallet' => 700,
            'pallet_count' => 21,
            'pico_units' => 300,
        ]);
    }

    public function test_almacen_puede_crear_entrada_con_articulo_existente(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-EXIST-001',
            'description' => 'Articulo existente',
            'units_per_pallet' => 600,
        ]);

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-ALM-EXIST-001',
                'lines' => [
                    [
                        'item_id' => $item->id,
                        'sku' => '',
                        'description' => '',
                        'lot' => 'LOT-EXIST-001',
                        'quantity_units' => 1200,
                        'units_per_pallet' => '',
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-ALM-EXIST-001')->firstOrFail();

        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => 'SKU-EXIST-001',
            'description' => 'Articulo existente',
            'units_per_pallet' => 600,
        ]);
    }

    public function test_almacen_puede_crear_entrada_con_articulo_nuevo_desde_linea(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-ALM-NEW-001',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'NEW-SKU-001',
                        'description' => 'Articulo creado en entrada',
                        'lot' => 'LOT-NEW-001',
                        'quantity_units' => 900,
                        'units_per_pallet' => 450,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseHas('items', [
            'client_id' => $client->id,
            'sku' => 'NEW-SKU-001',
            'description' => 'Articulo creado en entrada',
            'units_per_pallet' => 450,
            'status' => Item::STATUS_ACTIVE,
            'active' => true,
        ]);
    }

    public function test_articulo_nuevo_desde_entrada_guarda_sku_descripcion_y_uds_pallet(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-NEW-MASTER-001',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'MASTER-001',
                        'description' => 'Master creado desde entrada',
                        'lot' => 'LOT-MASTER-001',
                        'quantity_units' => 700,
                        'units_per_pallet' => 700,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $item = Item::query()
            ->where('client_id', $client->id)
            ->where('sku', 'MASTER-001')
            ->firstOrFail();

        $this->assertSame('Master creado desde entrada', $item->description);
        $this->assertSame(700, $item->units_per_pallet);
    }

    public function test_lote_no_se_guarda_en_articulo_maestro(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-LOT-NO-MASTER',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'LOT-FREE-001',
                        'description' => 'Articulo sin lote maestro',
                        'lot' => 'LOTE-OPERATIVO-001',
                        'quantity_units' => 800,
                        'units_per_pallet' => 400,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $item = Item::query()
            ->where('client_id', $client->id)
            ->where('sku', 'LOT-FREE-001')
            ->firstOrFail();

        $this->assertNull($item->lot);
        $this->assertSame('', $item->lot_key);
    }

    public function test_no_duplica_articulo_si_sku_ya_existe(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-DUP-ENTRY',
            'description' => 'Articulo ya creado',
            'units_per_pallet' => 300,
        ]);

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-DUP-ENTRY',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'sku-dup-entry',
                        'description' => 'Texto alternativo ignorado',
                        'lot' => 'LOT-DUP',
                        'quantity_units' => 600,
                        'units_per_pallet' => 333,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $this->assertDatabaseCount('items', 1);

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-DUP-ENTRY')->firstOrFail();

        $this->assertDatabaseHas('goods_receipt_lines', [
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => 'SKU-DUP-ENTRY',
            'description' => 'Articulo ya creado',
            'units_per_pallet' => 333,
        ]);
    }

    public function test_valida_descripcion_si_articulo_no_existe(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->from(route('goods-receipts.create'))
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-VALID-DESC',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-NO-DESC',
                        'description' => '',
                        'lot' => 'LOT-NO-DESC',
                        'quantity_units' => 500,
                        'units_per_pallet' => 250,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect(route('goods-receipts.create'))
            ->assertSessionHasErrors('lines.0.description');
    }

    public function test_valida_uds_pallet_si_articulo_no_existe(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->from(route('goods-receipts.create'))
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-VALID-UPP',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-NO-UPP',
                        'description' => 'Articulo sin paletizado',
                        'lot' => 'LOT-NO-UPP',
                        'quantity_units' => 500,
                        'units_per_pallet' => '',
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect(route('goods-receipts.create'))
            ->assertSessionHasErrors('lines.0.units_per_pallet');
    }

    public function test_entrada_con_articulo_nuevo_sigue_generando_linea_correcta(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-LINE-NEW-001',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-LINE-NEW',
                        'description' => 'Linea correcta',
                        'lot' => 'LOT-LINE-NEW',
                        'quantity_units' => 1500,
                        'units_per_pallet' => 700,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-LINE-NEW-001')->firstOrFail();
        $line = $receipt->lines()->firstOrFail();

        $this->assertSame('SKU-LINE-NEW', $line->sku);
        $this->assertSame('Linea correcta', $line->description);
        $this->assertSame(700, $line->units_per_pallet);
        $this->assertSame(2, $line->pallet_count);
        $this->assertSame(100, $line->pico_units);
        $this->assertNotNull($line->item_id);
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
        Storage::fake('local');
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
        Storage::disk('local')->assertExists($receipt->document_path);
    }

    public function test_attached_document_can_be_downloaded_only_by_internal_user(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->createDraftReceipt($almacen);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.attach-document', $receipt), [
                'document' => UploadedFile::fake()->create('albaran.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $receipt->refresh();

        $this->actingAs($almacen)
            ->get(route('goods-receipts.document', $receipt))
            ->assertOk()
            ->assertDownload('albaran.pdf');

        $cliente = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($cliente)
            ->get(route('goods-receipts.document', $receipt))
            ->assertForbidden();
    }

    public function test_almacen_ve_boton_interpretar_ia_si_entrada_tiene_documento(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));

        $this->actingAs($almacen)
            ->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertSee('Interpretar albaran con IA')
            ->assertSee('Estado IA');
    }

    public function test_crear_borrador_e_interpretar_ia_guarda_entrada_y_documento(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => false]);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_and_extract_ai',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-AI-STORE-001',
                'received_at' => '2026-07-06',
                'lines' => [],
                'document' => UploadedFile::fake()->create('albaran-ai.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-AI-STORE-001')->firstOrFail();

        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->status);
        $this->assertNotNull($receipt->document_path);
        Storage::disk('local')->assertExists($receipt->document_path);
    }

    public function test_crear_borrador_e_interpretar_ia_guarda_resultado_si_ia_ok(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);
        $this->bindFakeAiExtractor($this->sampleAiProposal());

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_and_extract_ai',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-AI-STORE-OK',
                'received_at' => '2026-07-06',
                'lines' => [],
                'document' => UploadedFile::fake()->create('albaran-ai.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-AI-STORE-OK')->firstOrFail();

        $this->assertSame(GoodsReceipt::AI_STATUS_COMPLETED, $receipt->ai_status);
        $this->assertSame('IA-SKU-001', data_get($receipt->ai_extracted_data, 'lines.0.sku'));
    }

    public function test_crear_borrador_e_interpretar_ia_no_confirma_stock(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);
        $this->bindFakeAiExtractor($this->sampleAiProposal());

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_and_extract_ai',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-AI-NO-STOCK',
                'lines' => [],
                'document' => UploadedFile::fake()->create('albaran-ai.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-AI-NO-STOCK')->firstOrFail();

        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->status);
        $this->assertNull($receipt->stock_applied_at);
        $this->assertDatabaseMissing('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
        ]);
    }

    public function test_crear_borrador_e_interpretar_ia_con_ia_desactivada_crea_borrador_y_avisa(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => false]);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier] = $this->makeReceiptContext();

        $response = $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_and_extract_ai',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-AI-DISABLED',
                'lines' => [],
                'document' => UploadedFile::fake()->create('albaran-ai.pdf', 120, 'application/pdf'),
            ]);

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-AI-DISABLED')->firstOrFail();

        $response
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHas('status', 'Entrada creada. La interpretacion IA esta pendiente de activar en configuracion.');

        $this->assertSame(GoodsReceipt::AI_STATUS_PENDING, $receipt->ai_status);
    }

    public function test_crear_borrador_e_interpretar_ia_sin_documento_muestra_error_claro(): void
    {
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier] = $this->makeReceiptContext();

        $response = $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_and_extract_ai',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-AI-NO-DOC',
                'lines' => [],
            ]);

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-AI-NO-DOC')->firstOrFail();

        $response
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHasErrors('goods_receipt')
            ->assertSessionHas('status', 'Entrada creada correctamente como borrador.');

        $this->assertNull($receipt->document_path);
        $this->assertSame(0, $receipt->lines()->count());
    }

    public function test_fallo_ia_no_borra_entrada_ni_documento(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);
        $this->bindFakeAiExtractor([], 'Fallo IA en alta.');

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier] = $this->makeReceiptContext();

        $response = $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_and_extract_ai',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-AI-FAIL-STORE',
                'lines' => [],
                'document' => UploadedFile::fake()->create('albaran-ai.pdf', 120, 'application/pdf'),
            ]);

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-AI-FAIL-STORE')->firstOrFail();

        $response
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHasErrors('goods_receipt')
            ->assertSessionHas('status', 'Entrada creada, pero no se pudo interpretar el documento con IA. Puedes rellenar las lineas manualmente o reintentar.');

        $this->assertSame(GoodsReceipt::AI_STATUS_FAILED, $receipt->ai_status);
        $this->assertNotNull($receipt->document_path);
        Storage::disk('local')->assertExists($receipt->document_path);
    }

    public function test_almacen_no_puede_interpretar_ia_sin_documento(): void
    {
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->createDraftReceipt($almacen);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.ai-extract', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHasErrors('goods_receipt');
    }

    public function test_cliente_no_puede_lanzar_interpretacion_ia(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $cliente = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($cliente)
            ->post(route('goods-receipts.ai-extract', $receipt))
            ->assertForbidden();
    }

    public function test_interpretar_ia_guarda_resultado_estructurado(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $this->bindFakeAiExtractor($this->sampleAiProposal());

        $this->actingAs($almacen)
            ->post(route('goods-receipts.ai-extract', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $receipt->refresh();

        $this->assertSame(GoodsReceipt::AI_STATUS_COMPLETED, $receipt->ai_status);
        $this->assertSame('PROVEEDOR IA DEMO', data_get($receipt->ai_extracted_data, 'supplier_name'));
        $this->assertSame('ALB-IA-001', data_get($receipt->ai_extracted_data, 'delivery_note_number'));
        $this->assertSame('IA-SKU-001', data_get($receipt->ai_extracted_data, 'lines.0.sku'));
        $this->assertNotNull($receipt->document_processed_at);
    }

    public function test_interpretar_ia_muestra_propuesta_en_detalle(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $this->bindFakeAiExtractor($this->sampleAiProposal());

        $this->actingAs($almacen)
            ->post(route('goods-receipts.ai-extract', $receipt))
            ->assertRedirect();

        $this->actingAs($almacen)
            ->get(route('goods-receipts.show', $receipt->fresh()))
            ->assertOk()
            ->assertSee('Propuesta IA del albaran')
            ->assertSee('Aplicar lineas a la entrada')
            ->assertSee('PROVEEDOR IA DEMO')
            ->assertSee('IA-SKU-001');
    }

    public function test_aplicar_propuesta_ia_crea_lineas_de_entrada(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $proposal = $this->sampleAiProposal();
        $this->bindFakeAiExtractor($proposal);

        $this->actingAs($almacen)
            ->post(route('goods-receipts.ai-extract', $receipt))
            ->assertRedirect();

        $this->actingAs($almacen)
            ->post(route('goods-receipts.ai-apply', $receipt->fresh()), $this->proposalApplyPayload($receipt->fresh(), $proposal))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $receipt->refresh();
        $line = $receipt->lines()->firstOrFail();

        $this->assertSame(GoodsReceipt::AI_STATUS_REVIEWED, $receipt->ai_status);
        $this->assertSame('ALB-IA-001', $receipt->receipt_number);
        $this->assertSame('IA-SKU-001', $line->sku);
        $this->assertSame(25000, $line->quantity_units);
        $this->assertSame(5000, $line->units_per_pallet);
        $this->assertSame(5, $line->pallet_count);
        $this->assertSame($receipt->supplier_id, $receipt->supplier?->id);
    }

    public function test_aplicar_propuesta_ia_crea_articulo_si_sku_no_existe(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $proposal = $this->sampleAiProposal();
        $this->bindFakeAiExtractor($proposal);

        $this->actingAs($almacen)->post(route('goods-receipts.ai-extract', $receipt));
        $this->actingAs($almacen)->post(route('goods-receipts.ai-apply', $receipt->fresh()), $this->proposalApplyPayload($receipt->fresh(), $proposal));

        $this->assertDatabaseHas('items', [
            'client_id' => $receipt->client_id,
            'sku' => 'IA-SKU-001',
            'description' => 'Papel IA detectado',
            'units_per_pallet' => 5000,
        ]);
    }

    public function test_lote_detectado_por_ia_no_se_guarda_en_articulo(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $proposal = $this->sampleAiProposal();
        $this->bindFakeAiExtractor($proposal);

        $this->actingAs($almacen)->post(route('goods-receipts.ai-extract', $receipt));
        $this->actingAs($almacen)->post(route('goods-receipts.ai-apply', $receipt->fresh()), $this->proposalApplyPayload($receipt->fresh(), $proposal));

        $item = Item::query()
            ->where('client_id', $receipt->client_id)
            ->where('sku', 'IA-SKU-001')
            ->firstOrFail();

        $this->assertNull($item->lot);
        $this->assertSame('', $item->lot_key);
    }

    public function test_uds_pallet_detectado_por_ia_se_guarda_en_articulo_nuevo(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $proposal = $this->sampleAiProposal();
        $this->bindFakeAiExtractor($proposal);

        $this->actingAs($almacen)->post(route('goods-receipts.ai-extract', $receipt));
        $this->actingAs($almacen)->post(route('goods-receipts.ai-apply', $receipt->fresh()), $this->proposalApplyPayload($receipt->fresh(), $proposal));

        $item = Item::query()
            ->where('client_id', $receipt->client_id)
            ->where('sku', 'IA-SKU-001')
            ->firstOrFail();

        $this->assertSame(5000, $item->units_per_pallet);
    }

    public function test_aplicar_propuesta_ia_no_confirma_stock_automaticamente(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $proposal = $this->sampleAiProposal();
        $this->bindFakeAiExtractor($proposal);

        $this->actingAs($almacen)->post(route('goods-receipts.ai-extract', $receipt));
        $this->actingAs($almacen)->post(route('goods-receipts.ai-apply', $receipt->fresh()), $this->proposalApplyPayload($receipt->fresh(), $proposal));

        $receipt->refresh();

        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->status);
        $this->assertNull($receipt->stock_applied_at);
        $this->assertDatabaseMissing('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
        ]);
    }

    public function test_error_ia_se_guarda_y_muestra_sin_romper_entrada(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $this->bindFakeAiExtractor([], 'Fallo de interpretacion IA de prueba.');

        $this->actingAs($almacen)
            ->post(route('goods-receipts.ai-extract', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHasErrors('goods_receipt');

        $receipt->refresh();

        $this->assertSame(GoodsReceipt::AI_STATUS_FAILED, $receipt->ai_status);
        $this->assertStringContainsString('Fallo de interpretacion IA', (string) $receipt->ai_error);

        $this->actingAs($almacen)
            ->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertSee('Error IA')
            ->assertSee('Fallo de interpretacion IA de prueba.');
    }

    public function test_detalle_entrada_con_documento_pendiente_muestra_boton_interpretar(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));

        $this->actingAs($almacen)
            ->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertSee('Albaran adjunto pendiente de interpretar')
            ->assertSee('Interpretar albaran con IA');
    }

    public function test_detalle_entrada_con_error_ia_muestra_reintentar(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $receipt->update([
            'ai_status' => GoodsReceipt::AI_STATUS_FAILED,
            'ai_error' => 'Fallo en detalle.',
        ]);

        $this->actingAs($almacen)
            ->get(route('goods-receipts.show', $receipt))
            ->assertOk()
            ->assertSee('No se pudo interpretar el albaran')
            ->assertSee('Reintentar interpretacion IA');
    }

    public function test_entrada_con_ia_permite_lineas_vacias_en_borrador(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => false]);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_and_extract_ai',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-AI-EMPTY-LINES',
                'lines' => [],
                'document' => UploadedFile::fake()->create('albaran-ai.pdf', 120, 'application/pdf'),
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-AI-EMPTY-LINES')->firstOrFail();

        $this->assertSame(0, $receipt->lines()->count());
        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->status);
    }

    public function test_documento_de_cliente_no_es_accesible_por_otro_cliente(): void
    {
        Storage::fake('local');
        $this->seed(RoleSeeder::class);

        $almacen = $this->makeUserWithRole(Role::ALMACEN);
        $receipt = $this->attachStoredDocument($this->createDraftReceipt($almacen));
        $otroCliente = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($otroCliente)
            ->get(route('goods-receipts.document', $receipt))
            ->assertForbidden();
    }

    public function test_confirmar_entrada_sigue_requiriendo_lineas_validas_si_corresponde(): void
    {
        $this->seed(RoleSeeder::class);
        config(['services.openai.receipt_enabled' => true]);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'action' => 'create_and_extract_ai',
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-AI-NO-LINES-CONFIRM',
                'lines' => [],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-AI-NO-LINES-CONFIRM')->firstOrFail();

        $this->actingAs($user)
            ->from(route('goods-receipts.show', $receipt))
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHasErrors('goods_receipt');

        $this->assertDatabaseMissing('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
        ]);
    }

    public function test_confirming_goods_receipt_generates_one_aggregated_stock_batch_and_prevents_duplicates(): void
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
        $this->assertDatabaseCount('stock_pallets', 1);
        $this->assertDatabaseHas('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
            'quantity_units' => 2500,
            'units_per_pallet' => 1000,
            'full_pallets' => 2,
            'peaks_count' => 1,
            'peak_1' => 500,
            'location_id' => $location->id,
            'lot' => 'LOT-CF1',
            'status' => 'available',
            'active' => true,
            'pallet_code' => null,
        ]);
        $this->assertDatabaseHas('items', [
            'client_id' => $client->id,
            'sku' => 'SKU-CONF-001',
            'lot_key' => '',
        ]);

        $this->actingAs($user)
            ->from(route('goods-receipts.show', $receipt))
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHasErrors('goods_receipt');

        $this->assertDatabaseCount('stock_pallets', 1);
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
            ->assertSee('SKU-STOCK-001')
            ->assertSee('LOT-STK')
            ->assertSee('26/06/2026');
    }

    public function test_confirming_70000_units_with_1080_units_per_pallet_generates_one_batch_with_64_full_pallets_and_one_peak_of_880(): void
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
            'units_per_pallet' => 1080,
        ]);

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-CONF-1080',
                'received_at' => '2026-06-26',
                'lines' => [
                    [
                        'item_id' => $item->id,
                        'sku' => '',
                        'description' => '',
                        'lot' => 'LOTE 1',
                        'quantity_units' => 70000,
                        'units_per_pallet' => '',
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-CONF-1080')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $this->assertDatabaseCount('stock_pallets', 1);
        $this->assertSame(1, $receipt->fresh()->stockPallets()->count());
        $this->assertSame(64, $receipt->lines()->firstOrFail()->fresh()->pallet_count);
        $this->assertSame(880, $receipt->lines()->firstOrFail()->fresh()->pico_units);
        $this->assertDatabaseHas('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
            'quantity_units' => 70000,
            'units_per_pallet' => 1080,
            'full_pallets' => 64,
            'peaks_count' => 1,
            'peak_1' => 880,
            'location_id' => $location->id,
            'lot' => 'LOTE 1',
            'active' => true,
            'pallet_code' => null,
        ]);
    }

    public function test_entrada_borrador_no_suma_stock(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-DRAFT-NO-STOCK',
                'received_at' => '2026-07-04',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-DRAFT-001',
                        'description' => 'Borrador sin stock',
                        'lot' => 'LOT-DRAFT',
                        'quantity_units' => 1200,
                        'units_per_pallet' => 600,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-DRAFT-NO-STOCK')->firstOrFail();

        $this->assertSame(GoodsReceipt::STATUS_DRAFT, $receipt->status);
        $this->assertNull($receipt->stock_applied_at);
        $this->assertDatabaseMissing('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
        ]);
    }

    public function test_confirmar_entrada_suma_stock_y_marca_stock_aplicado(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-STOCK-APPLY-001',
                'received_at' => '2026-07-04',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-STOCK-APPLY',
                        'description' => 'Entrada con stock aplicado',
                        'lot' => 'LOT-APPLY',
                        'quantity_units' => 1800,
                        'units_per_pallet' => 600,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-STOCK-APPLY-001')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHas('status', 'Entrada confirmada y stock actualizado correctamente.');

        $receipt->refresh();

        $this->assertSame(GoodsReceipt::STATUS_CONFIRMED, $receipt->status);
        $this->assertNotNull($receipt->stock_applied_at);
        $this->assertSame($user->id, $receipt->stock_applied_by);
        $this->assertDatabaseHas('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
            'client_id' => $client->id,
            'lot' => 'LOT-APPLY',
            'quantity_units' => 1800,
            'full_pallets' => 3,
            'location_id' => $location->id,
        ]);
    }

    public function test_confirmar_entrada_no_suma_dos_veces(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $client->id,
                'supplier_id' => $supplier->id,
                'receipt_number' => 'ALB-IDEMP-001',
                'received_at' => '2026-07-04',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-IDEMP-001',
                        'description' => 'Entrada idempotente',
                        'lot' => 'LOT-IDEMP',
                        'quantity_units' => 2500,
                        'units_per_pallet' => 1000,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-IDEMP-001')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $firstAppliedAt = $receipt->fresh()->stock_applied_at;

        $this->actingAs($user)
            ->from(route('goods-receipts.show', $receipt))
            ->patch(route('goods-receipts.confirm', $receipt->fresh()))
            ->assertRedirect(route('goods-receipts.show', $receipt))
            ->assertSessionHasErrors('goods_receipt');

        $this->assertDatabaseCount('stock_pallets', 1);
        $this->assertEquals($firstAppliedAt, $receipt->fresh()->stock_applied_at);
        $this->assertDatabaseHas('stock_pallets', [
            'quantity_units' => 2500,
            'full_pallets' => 2,
            'peak_1' => 500,
        ]);
    }

    public function test_entrada_suma_stock_en_cliente_correcto(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$clientA, $supplierA, $location] = $this->makeReceiptContext();
        [$clientB] = $this->makeReceiptContext();

        Item::factory()->create([
            'client_id' => $clientA->id,
            'sku' => 'SKU-CLIENTE',
            'description' => 'Cliente A',
            'units_per_pallet' => 500,
        ]);
        Item::factory()->create([
            'client_id' => $clientB->id,
            'sku' => 'SKU-CLIENTE',
            'description' => 'Cliente B',
            'units_per_pallet' => 500,
        ]);

        $this->actingAs($user)
            ->post(route('goods-receipts.store'), [
                'client_id' => $clientA->id,
                'supplier_id' => $supplierA->id,
                'receipt_number' => 'ALB-CLIENT-A',
                'received_at' => '2026-07-04',
                'lines' => [
                    [
                        'item_id' => '',
                        'sku' => 'SKU-CLIENTE',
                        'description' => 'Cliente A',
                        'lot' => 'LOT-CLIENT-A',
                        'quantity_units' => 1000,
                        'units_per_pallet' => 500,
                        'pallet_count' => '',
                        'pico_units' => '',
                        'location_id' => $location->id,
                    ],
                ],
            ])
            ->assertRedirect();

        $receipt = GoodsReceipt::query()->where('receipt_number', 'ALB-CLIENT-A')->firstOrFail();

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $this->assertDatabaseHas('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
            'client_id' => $clientA->id,
            'lot' => 'LOT-CLIENT-A',
            'quantity_units' => 1000,
        ]);

        $this->assertDatabaseMissing('stock_pallets', [
            'client_id' => $clientB->id,
            'goods_receipt_id' => $receipt->id,
        ]);
    }

    public function test_entrada_crea_partida_si_no_existe(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-CREATE-BATCH',
            'description' => 'Nueva partida',
            'units_per_pallet' => 1000,
        ]);

        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'receipt_number' => 'ALB-CREATE-BATCH',
            'created_by' => $user->id,
            'received_at' => '2026-07-04',
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => 'LOT-CREATE',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
            'pallet_count' => 1,
            'pico_units' => null,
            'location_id' => $location->id,
        ]);

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $this->assertDatabaseHas('stock_pallets', [
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'lot' => 'LOT-CREATE',
            'location_id' => $location->id,
            'quantity_units' => 1000,
        ]);
    }

    public function test_entrada_actualiza_partida_existente_si_coincide_item_lote_ubicacion(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);
        [$client, $supplier, $location] = $this->makeReceiptContext();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-MERGE-001',
            'description' => 'Partida acumulable',
            'units_per_pallet' => 1000,
        ]);

        $existingBatch = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'goods_receipt_id' => null,
            'location_id' => $location->id,
            'location_text' => $location->code,
            'lot' => 'LOT-MERGE',
            'quantity_units' => 1500,
            'units_per_pallet' => 1000,
            'full_pallets' => 1,
            'peaks_count' => 1,
            'peak_1' => 500,
            'peak_2' => 0,
            'peak_3' => 0,
            'peak_4' => 0,
            'peak_5' => 0,
            'peak_6' => 0,
            'peak_7' => 0,
            'peak_8' => 0,
            'peak_9' => 0,
            'peak_10' => 0,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        $receipt = GoodsReceipt::factory()->create([
            'client_id' => $client->id,
            'supplier_id' => $supplier->id,
            'receipt_number' => 'ALB-MERGE-001',
            'created_by' => $user->id,
            'received_at' => '2026-07-04',
        ]);
        GoodsReceiptLine::factory()->create([
            'goods_receipt_id' => $receipt->id,
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->description,
            'lot' => 'LOT-MERGE',
            'quantity_units' => 1000,
            'units_per_pallet' => 1000,
            'pallet_count' => 1,
            'pico_units' => null,
            'location_id' => $location->id,
        ]);

        $this->actingAs($user)
            ->patch(route('goods-receipts.confirm', $receipt))
            ->assertRedirect(route('goods-receipts.show', $receipt));

        $existingBatch->refresh();

        $this->assertSame(2500, $existingBatch->quantity_units);
        $this->assertSame(2, $existingBatch->full_pallets);
        $this->assertSame(1, $existingBatch->peaks_count);
        $this->assertSame(500, $existingBatch->peak_1);
        $this->assertSame($receipt->id, $existingBatch->goods_receipt_id);
        $this->assertDatabaseCount('stock_pallets', 1);
    }

    public function test_autocomplete_entrada_renderiza_sin_mojibake_y_con_contenedor_visible(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('goods-receipts.create'))
            ->assertOk()
            ->assertSee('Nueva entrada de mercancia')
            ->assertSee('Añadir linea')
            ->assertSee('data-autocomplete-floating="fixed"', false)
            ->assertSee('goods-receipt-line-picker', false)
            ->assertDontSee('AÃ±adir')
            ->assertDontSee('mercancÃ­a');
    }

    public function test_flujo_entrada_sigue_funcionando_para_superadmin_almacen_administracion(): void
    {
        $this->seed(RoleSeeder::class);

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('goods-receipts.create'))
                ->assertOk()
                ->assertSee('Nueva entrada de mercancia')
                ->assertSee('Crear borrador');
        }
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

    private function attachStoredDocument(GoodsReceipt $receipt, string $filename = 'albaran.pdf'): GoodsReceipt
    {
        $path = 'goods-receipts/'.$receipt->id.'-'.$filename;

        Storage::disk('local')->put($path, 'contenido de prueba');

        $receipt->update([
            'document_path' => $path,
            'document_original_name' => $filename,
            'document_mime' => 'application/pdf',
            'ai_status' => GoodsReceipt::AI_STATUS_PENDING,
            'ai_extracted_data' => null,
            'ai_error' => null,
        ]);

        return $receipt->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function sampleAiProposal(array $overrides = []): array
    {
        return array_replace_recursive([
            'supplier_name' => 'PROVEEDOR IA DEMO',
            'delivery_note_number' => 'ALB-IA-001',
            'received_date' => '2026-07-06',
            'confidence' => 0.86,
            'warnings' => [
                'Ubicacion no detectada. Revisar manualmente.',
            ],
            'lines' => [
                [
                    'sku' => 'IA-SKU-001',
                    'description' => 'Papel IA detectado',
                    'lot' => 'L202607',
                    'units_per_pallet' => 5000,
                    'total_units' => 25000,
                    'full_pallets' => 5,
                    'peak_units' => 0,
                    'confidence' => 0.91,
                    'warnings' => [],
                ],
            ],
        ], $overrides);
    }

    /**
     * @param  array<string, mixed>  $proposal
     * @return array<string, mixed>
     */
    private function proposalApplyPayload(GoodsReceipt $receipt, array $proposal): array
    {
        return [
            'supplier_id' => $receipt->supplier_id,
            'receipt_number' => $proposal['delivery_note_number'] ?? $receipt->receipt_number,
            'received_at' => $proposal['received_date'] ?? $receipt->received_at?->format('Y-m-d'),
            'lines' => collect($proposal['lines'] ?? [])
                ->map(fn (array $line): array => [
                    'item_id' => '',
                    'sku' => $line['sku'] ?? '',
                    'description' => $line['description'] ?? '',
                    'lot' => $line['lot'] ?? '',
                    'quantity_units' => $line['total_units'] ?? 0,
                    'units_per_pallet' => $line['units_per_pallet'] ?? '',
                    'pallet_count' => $line['full_pallets'] ?? 0,
                    'pico_units' => (($line['peak_units'] ?? 0) > 0) ? $line['peak_units'] : '',
                    'location_id' => '',
                ])
                ->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function bindFakeAiExtractor(array $payload, ?string $failureMessage = null): void
    {
        app()->bind(GoodsReceiptAiExtractorInterface::class, function () use ($payload, $failureMessage) {
            return new class($payload, $failureMessage) implements GoodsReceiptAiExtractorInterface
            {
                /**
                 * @param  array<string, mixed>  $payload
                 */
                public function __construct(
                    private readonly array $payload,
                    private readonly ?string $failureMessage,
                ) {}

                public function extractFromDocument(GoodsReceipt $receipt): GoodsReceiptAiExtractionResult
                {
                    if ($this->failureMessage !== null) {
                        throw new RuntimeException($this->failureMessage);
                    }

                    return GoodsReceiptAiExtractionResult::fromArray($this->payload);
                }
            };
        });
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
