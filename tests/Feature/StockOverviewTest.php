<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
use App\Services\Stock\StockExportService;
use App\Support\Stock\StockOverviewBuilder;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockOverviewTest extends TestCase
{
    use RefreshDatabase;

    public function test_almacen_can_view_stock_index(): void
    {
        [$client] = $this->seedBaseData();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-STOCK-01',
            'description' => 'Stock operativo',
            'units_per_pallet' => 1080,
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-001',
            'location_text' => 'A1-01',
            'quantity_units' => 70000,
            'units_per_pallet' => 1080,
            'full_pallets' => 64,
            'peaks_count' => 1,
            'peak_1' => 880,
            'received_at' => '2026-06-26',
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Consulta existencias, lotes, pallets y picos operativos.')
            ->assertSee('SKU-STOCK-01')
            ->assertSee('LOT-001')
            ->assertSee('70.000')
            ->assertSee('65,00')
            ->assertSee('1 pico')
            ->assertDontSee('Codigo pallet');
    }

    public function test_stock_index_renders_expected_breadcrumb_segments_without_mojibake(): void
    {
        [$client] = $this->seedBaseData();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-BREAD-01',
            'description' => 'Stock con breadcrumb',
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-BREAD-01',
            'quantity_units' => 120,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Panel de control')
            ->assertSee('Stock')
            ->assertSee('Inventario')
            ->assertSee(route('dashboard'), false)
            ->assertSee('aria-current="page"', false)
            ->assertDontSee('â€º', false)
            ->assertDontSee('href="'.route('stock.index').'">Inventario', false);
    }

    public function test_cliente_can_view_only_own_stock_inventory(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();

        $frItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'SKU-FR-ONLY',
            'description' => 'Inventario Friesland',
        ]);

        $edItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-ED-HIDE',
            'description' => 'Inventario Edelvives',
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frItem->id,
            'lot' => 'LOT-FR-ONLY',
            'quantity_units' => 100,
        ]);

        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'lot' => 'LOT-ED-HIDE',
            'quantity_units' => 100,
        ]);

        $user = $this->makeUserWithRole(Role::CLIENTE, $friesland);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSee('Mi inventario')
            ->assertDontSee('Consulta tus existencias, lotes, pallets y picos disponibles.')
            ->assertDontSee('Usa el buscador para localizar por SKU, descripcion o lote.')
            ->assertSee('SKU-FR-ONLY')
            ->assertDontSee('SKU-ED-HIDE')
            ->assertDontSee('name="client_id"', false);
    }

    public function test_cliente_no_ve_referencias_varios_pero_si_bloqueadas_y_obsoletas(): void
    {
        [$client] = $this->seedBaseData();

        $visibleBlocked = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-BLOCKED-CLIENT',
            'description' => 'Bloqueado visible',
            'status' => Item::STATUS_BLOCKED,
            'stock_category' => StockPallet::CATEGORY_BLOCKED,
        ]);
        $visibleObsolete = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-OBSOLETE-CLIENT',
            'description' => 'Obsoleto visible',
            'status' => Item::STATUS_OBSOLETE,
            'stock_category' => StockPallet::CATEGORY_OBSOLETE,
        ]);
        $internal = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => '_INTERNAL-CLIENT',
            'description' => 'Interno oculto',
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $visibleBlocked->id,
            'quantity_units' => 100,
            'status' => StockPallet::STATUS_BLOCKED,
            'stock_category' => StockPallet::CATEGORY_BLOCKED,
        ]);
        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $visibleObsolete->id,
            'quantity_units' => 100,
            'status' => StockPallet::STATUS_OBSOLETE,
            'stock_category' => StockPallet::CATEGORY_OBSOLETE,
        ]);
        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $internal->id,
            'quantity_units' => 100,
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);

        $user = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('SKU-BLOCKED-CLIENT')
            ->assertSee('SKU-OBSOLETE-CLIENT')
            ->assertDontSee('_INTERNAL-CLIENT')
            ->assertDontSee('VARIOS');
    }

    public function test_cliente_friesland_ve_en_uso_bloqueado_obsoleto_y_oculta_internos(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();

        // Visibles para el cliente: EN USO, BLOQUEADO y OBSOLETO (no son internos).
        $item = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'CAJA0030',
            'description' => 'Caja visible',
            'units_per_pallet' => 100,
        ]);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $item->id,
            'quantity_units' => 700,
            'units_per_pallet' => 100,
            'full_pallets' => 7,
            'peaks_count' => 0,
            'warehouse_pallets' => 10,
        ]);
        $this->makeItemWithStock($friesland, 'CRYOVAC6', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);
        $this->makeItemWithStock($friesland, 'CAJA0077', Item::STATUS_BLOCKED, StockPallet::CATEGORY_BLOCKED, StockPallet::STATUS_BLOCKED);
        $this->makeItemWithStock($friesland, 'ET0336', Item::STATUS_OBSOLETE, StockPallet::CATEGORY_OBSOLETE, StockPallet::STATUS_OBSOLETE);
        // Ocultos para el cliente: categoria VARIOS y referencias que empiezan por "_".
        $this->makeItemWithStock($friesland, '_CAJA057', Item::STATUS_ACTIVE, StockPallet::CATEGORY_MISC, StockPallet::STATUS_AVAILABLE);
        // Caso fuga: SKU con "_" pero mal categorizado como EN USO. Debe ocultarse igualmente.
        $this->makeItemWithStock($friesland, '_FILM0519', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);
        // Stock de otro cliente: nunca visible.
        $this->makeItemWithStock($edelvives, 'ED-OTHER-CLIENT', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);

        $user = $this->makeUserWithRole(Role::CLIENTE, $friesland);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('CAJA0030')
            ->assertSee('CRYOVAC6')
            ->assertSee('CAJA0077')
            ->assertSee('ET0336')
            ->assertDontSee('_CAJA057')
            ->assertDontSee('_FILM0519')
            ->assertDontSee('ED-OTHER-CLIENT')
            ->assertDontSee('VARIOS');
    }

    public function test_superadmin_ve_internos_varios_y_columnas_logisticas(): void
    {
        [$friesland] = $this->seedBaseData();

        $this->makeItemWithStock($friesland, 'CAJA0030', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);
        $this->makeItemWithStock($friesland, '_CAJA057', Item::STATUS_ACTIVE, StockPallet::CATEGORY_MISC, StockPallet::STATUS_AVAILABLE);
        $this->makeItemWithStock($friesland, '_FILM0519', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);

        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('stock.index', ['client_id' => $friesland->id]))
            ->assertOk()
            ->assertSee('CAJA0030')
            ->assertSee('_CAJA057')
            ->assertSee('_FILM0519')
            ->assertSee('VARIOS')
            ->assertSeeText('Pallets almacen');
    }

    public function test_cliente_ve_kpi_fisico_total_pero_no_metricas_internas_de_almacen(): void
    {
        [$friesland] = $this->seedBaseData();
        $friesland->update(['show_stock_total_to_client' => true]);

        $this->makeItemWithStock($friesland, 'CAJA0030', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $friesland))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('CAJA0030')
            ->assertDontSeeText('Pallets almacen')
            ->assertSeeText('Palés almacenados')
            ->assertSeeText('Stock físico total')
            ->assertSeeText('10')
            ->assertDontSeeText('Palés completos + picos')
            ->assertSeeText('Uds/palé')
            ->assertSeeText('Picos');

        $this->actingAs($this->makeUserWithRole(Role::SUPERADMIN))
            ->get(route('stock.index', ['client_id' => $friesland->id]))
            ->assertOk()
            ->assertSeeText('Pallets almacen');
    }

    public function test_export_cliente_usa_misma_visibilidad_que_la_tabla(): void
    {
        [$friesland] = $this->seedBaseData();

        $this->makeItemWithStock($friesland, 'CAJA0030', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);
        $this->makeItemWithStock($friesland, 'CAJA0077', Item::STATUS_BLOCKED, StockPallet::CATEGORY_BLOCKED, StockPallet::STATUS_BLOCKED);
        $this->makeItemWithStock($friesland, 'ET0336', Item::STATUS_OBSOLETE, StockPallet::CATEGORY_OBSOLETE, StockPallet::STATUS_OBSOLETE);
        $this->makeItemWithStock($friesland, '_CAJA057', Item::STATUS_ACTIVE, StockPallet::CATEGORY_MISC, StockPallet::STATUS_AVAILABLE);
        $this->makeItemWithStock($friesland, '_FILM0519', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);

        $rows = app(StockExportService::class)->rows($friesland->id);
        $skus = $rows->pluck('sku')->all();

        // El export incluye lo mismo que ve el cliente en la tabla: EN USO, BLOQUEADO y OBSOLETO.
        $this->assertContains('CAJA0030', $skus);
        $this->assertContains('CAJA0077', $skus);
        $this->assertContains('ET0336', $skus);
        // Y excluye lo interno: VARIOS y referencias "_" (aunque esten mal categorizadas).
        $this->assertNotContains('_CAJA057', $skus);
        $this->assertNotContains('_FILM0519', $skus);
    }

    public function test_kpi_fisico_cliente_suma_internos_pero_tabla_y_export_los_ocultan(): void
    {
        [$friesland] = $this->seedBaseData();
        $friesland->update(['show_stock_total_to_client' => true]);

        $this->makeItemWithStock($friesland, 'VIS-INUSE-1', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);
        $this->makeItemWithStock($friesland, 'VIS-BLOCK', Item::STATUS_BLOCKED, StockPallet::CATEGORY_BLOCKED, StockPallet::STATUS_BLOCKED);
        $this->makeItemWithStock($friesland, 'VIS-OBS', Item::STATUS_OBSOLETE, StockPallet::CATEGORY_OBSOLETE, StockPallet::STATUS_OBSOLETE);
        $this->makeItemWithStock($friesland, '_HIDDEN-MISC', Item::STATUS_ACTIVE, StockPallet::CATEGORY_MISC, StockPallet::STATUS_AVAILABLE);
        $this->makeItemWithStock($friesland, '_HIDDEN-USCORE', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);

        $client = $this->makeUserWithRole(Role::CLIENTE, $friesland);
        $overview = app(StockOverviewBuilder::class)->build($client, []);

        $this->assertSame(3, $overview['summary']['references_with_stock']);
        $this->assertSame(5.0, $overview['summary']['total_physical_pallets']);
        $this->assertSame(3.0, $overview['summary']['total_warehouse_pallets']);
        $this->assertCount(3, $overview['rows']);
        $this->assertSame(
            ['VIS-BLOCK', 'VIS-INUSE-1', 'VIS-OBS'],
            collect($overview['rows'])->pluck('sku')->sort()->values()->all(),
        );

        $exportSkus = app(StockExportService::class)->rows($friesland->id)->pluck('sku')->all();
        $this->assertContains('VIS-INUSE-1', $exportSkus);
        $this->assertNotContains('_HIDDEN-MISC', $exportSkus);
        $this->assertNotContains('_HIDDEN-USCORE', $exportSkus);

        $this->actingAs($client)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Palés almacenados')
            ->assertSeeText('5')
            ->assertSee('VIS-INUSE-1')
            ->assertDontSee('_HIDDEN-MISC')
            ->assertDontSee('_HIDDEN-USCORE')
            ->assertDontSee('VARIOS');
    }

    public function test_cliente_y_superadmin_comparten_total_fisico_para_el_mismo_cliente(): void
    {
        [$friesland] = $this->seedBaseData();

        $visible = Item::factory()->create(['client_id' => $friesland->id, 'sku' => 'VISIBLE-FISICO']);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $visible->id,
            'quantity_units' => 700,
            'full_pallets' => 7,
            'peaks_count' => 0,
            'warehouse_pallets' => 10,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
        ]);
        $internal = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => '_INTERNO-FISICO',
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $internal->id,
            'quantity_units' => 100,
            'full_pallets' => 1,
            'peaks_count' => 0,
            'warehouse_pallets' => 2,
            'stock_category' => StockPallet::CATEGORY_MISC,
        ]);

        $clientOverview = app(StockOverviewBuilder::class)->build($this->makeUserWithRole(Role::CLIENTE, $friesland), []);
        $superadminOverview = app(StockOverviewBuilder::class)->build($this->makeUserWithRole(Role::SUPERADMIN), [
            'client_id' => $friesland->id,
        ]);

        $this->assertSame(12.0, $clientOverview['summary']['total_physical_pallets']);
        $this->assertSame(12.0, $superadminOverview['summary']['total_warehouse_pallets']);
    }

    public function test_cliente_no_puede_sumar_stock_de_otro_cliente_manipulando_client_id(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();

        $frItem = Item::factory()->create(['client_id' => $friesland->id, 'sku' => 'FR-KPI-SAFE']);
        $edItem = Item::factory()->create(['client_id' => $edelvives->id, 'sku' => 'ED-KPI-HIDDEN']);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frItem->id,
            'quantity_units' => 100,
            'full_pallets' => 1,
            'warehouse_pallets' => 3,
        ]);
        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'quantity_units' => 100,
            'full_pallets' => 1,
            'warehouse_pallets' => 50,
        ]);

        $overview = app(StockOverviewBuilder::class)->build($this->makeUserWithRole(Role::CLIENTE, $friesland), [
            'client_id' => $edelvives->id,
        ]);

        $this->assertSame(3.0, $overview['summary']['total_physical_pallets']);
        $this->assertSame($friesland->id, $overview['filters']['client_id']);
        $this->assertSame(['FR-KPI-SAFE'], $overview['rows']->pluck('sku')->all());
    }

    public function test_filtros_y_paginacion_no_reducen_kpi_fisico_total_del_cliente(): void
    {
        [$friesland] = $this->seedBaseData();

        foreach ([
            ['SKU-FILTRO-A', 'LOT-A', 3],
            ['SKU-FILTRO-B', 'LOT-B', 4],
            ['SKU-FILTRO-C', 'LOT-C', 5],
        ] as [$sku, $lot, $warehousePallets]) {
            $item = Item::factory()->create(['client_id' => $friesland->id, 'sku' => $sku]);
            StockPallet::factory()->create([
                'client_id' => $friesland->id,
                'item_id' => $item->id,
                'lot' => $lot,
                'quantity_units' => 100,
                'full_pallets' => 1,
                'warehouse_pallets' => $warehousePallets,
            ]);
        }

        $overview = app(StockOverviewBuilder::class)->build($this->makeUserWithRole(Role::CLIENTE, $friesland), [
            'search' => 'SKU-FILTRO-A',
            'lot' => 'LOT-A',
            'per_page' => 25,
        ]);

        $this->assertSame(12.0, $overview['summary']['total_physical_pallets']);
        $this->assertSame(3.0, $overview['summary']['total_warehouse_pallets']);
        $this->assertSame(['SKU-FILTRO-A'], $overview['rows']->pluck('sku')->all());
    }

    public function test_cliente_con_total_global_activado_ve_pales_almacenados_y_su_cifra(): void
    {
        [, $edelvives] = $this->seedBaseData();

        $edelvives->update(['show_stock_total_to_client' => true]);
        $item = Item::factory()->create(['client_id' => $edelvives->id, 'sku' => 'ED-TOTAL-ACTIVO']);
        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $item->id,
            'quantity_units' => 100,
            'full_pallets' => 1,
            'warehouse_pallets' => 12,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $edelvives))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Palés almacenados')
            ->assertSeeText('Stock físico total')
            ->assertSeeText('12')
            ->assertSee('data-stock-total-summary', false)
            ->assertSee('Descargar');
    }

    public function test_cliente_con_total_global_desactivado_no_recibe_kpi_ni_cifra_total(): void
    {
        [, $edelvives] = $this->seedBaseData();

        $edelvives->update(['show_stock_total_to_client' => false]);
        $item = Item::factory()->create(['client_id' => $edelvives->id, 'sku' => 'ED-TOTAL-OCULTO']);
        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $item->id,
            'quantity_units' => 100,
            'full_pallets' => 1,
            'warehouse_pallets' => 27,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $edelvives))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSeeText('Palés almacenados')
            ->assertDontSeeText('Stock físico total')
            ->assertDontSeeText('27')
            ->assertDontSee('data-stock-total-summary', false)
            ->assertSee('ED-TOTAL-OCULTO')
            ->assertSee('Descargar')
            ->assertSee('data-stock-export-trigger', false);
    }

    public function test_cliente_friesland_no_ve_total_global_2338_pero_mantiene_tabla_filtros_y_descarga(): void
    {
        [$friesland] = $this->seedBaseData();

        $friesland->update(['show_stock_total_to_client' => false]);
        $item = Item::factory()->create(['client_id' => $friesland->id, 'sku' => 'FR-SIN-TOTAL']);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $item->id,
            'lot' => 'LOT-FR-SIN-TOTAL',
            'quantity_units' => 100,
            'full_pallets' => 1,
            'warehouse_pallets' => 2338,
        ]);
        $this->makeItemWithStock($friesland, '_FR-TOTAL-INTERNO', Item::STATUS_ACTIVE, StockPallet::CATEGORY_MISC, StockPallet::STATUS_AVAILABLE);

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $friesland))
            ->get(route('stock.index', ['show_stock_total_to_client' => 1, 'canSeeStockTotal' => 1]))
            ->assertOk()
            ->assertDontSeeText('Palés almacenados')
            ->assertDontSeeText('Stock físico total')
            ->assertDontSeeText('2.338')
            ->assertDontSee('data-stock-total-summary', false)
            ->assertSee('Descargar')
            ->assertSee('data-stock-export-trigger', false)
            ->assertSee('Descargar stock')
            ->assertSee('>Excel<', false)
            ->assertSee('>PDF<', false)
            ->assertSee('>CSV<', false)
            ->assertSee('SKU, descripcion o referencia')
            ->assertSee('Estado de stock')
            ->assertSee('stock-table--client', false)
            ->assertSee('FR-SIN-TOTAL')
            ->assertSee('LOT-FR-SIN-TOTAL')
            ->assertDontSee('_FR-TOTAL-INTERNO');
    }

    public function test_configuracion_de_total_global_de_un_cliente_no_afecta_a_otro(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();

        $friesland->update(['show_stock_total_to_client' => false]);
        $edelvives->update(['show_stock_total_to_client' => true]);
        $frItem = Item::factory()->create(['client_id' => $friesland->id, 'sku' => 'FR-TOTAL-AISLADO']);
        $edItem = Item::factory()->create(['client_id' => $edelvives->id, 'sku' => 'ED-TOTAL-AISLADO']);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frItem->id,
            'quantity_units' => 100,
            'full_pallets' => 1,
            'warehouse_pallets' => 17,
        ]);
        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'quantity_units' => 100,
            'full_pallets' => 1,
            'warehouse_pallets' => 29,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $friesland))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSeeText('Palés almacenados')
            ->assertDontSee('data-stock-total-summary', false)
            ->assertSee('FR-TOTAL-AISLADO');

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $edelvives))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Palés almacenados')
            ->assertSeeText('29')
            ->assertSee('ED-TOTAL-AISLADO');
    }

    public function test_cliente_con_ocupacion_activada_ve_total_de_huecos_usados(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();

        $edelvives->update(['show_storage_occupancy_to_client' => true]);
        $locationA = Location::factory()->create(['code' => 'HUECO-ED-01']);
        $locationB = Location::factory()->create(['code' => 'HUECO-ED-02']);
        $item = Item::factory()->create(['client_id' => $edelvives->id, 'sku' => 'ED-HUECOS']);

        foreach ([$locationA, $locationB] as $location) {
            StockPallet::factory()->create([
                'client_id' => $edelvives->id,
                'item_id' => $item->id,
                'location_id' => $location->id,
                'location_text' => $location->code,
                'quantity_units' => 100,
                'units_per_pallet' => 100,
                'full_pallets' => 1,
            ]);
        }
        $this->makeItemWithStock($friesland, 'FR-NO-AFECTA', Item::STATUS_ACTIVE, StockPallet::CATEGORY_IN_USE, StockPallet::STATUS_AVAILABLE);

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $edelvives))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Palés almacenados')
            ->assertSeeText('Huecos usados')
            ->assertSeeText('Total de ubicaciones ocupadas')
            ->assertSee('data-storage-occupancy-summary', false)
            ->assertSeeText('2')
            ->assertSee('HUECO-ED-01')
            ->assertSee('HUECO-ED-02');
    }

    public function test_cliente_con_ocupacion_desactivada_no_ve_huecos_ni_ubicaciones_pero_si_pales_y_descarga(): void
    {
        [$friesland] = $this->seedBaseData();

        $friesland->update([
            'show_storage_occupancy_to_client' => false,
            'show_stock_total_to_client' => true,
        ]);
        $location = Location::factory()->create(['code' => 'HUECO-SECRETO-01']);
        $item = Item::factory()->create(['client_id' => $friesland->id, 'sku' => 'FR-PALES-VISIBLES']);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'location_text' => $location->code,
            'quantity_units' => 300,
            'units_per_pallet' => 100,
            'full_pallets' => 3,
            'warehouse_pallets' => 3,
        ]);
        $this->makeItemWithStock($friesland, '_OCULTO-HUECOS', Item::STATUS_ACTIVE, StockPallet::CATEGORY_MISC, StockPallet::STATUS_AVAILABLE);

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $friesland))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Palés almacenados')
            ->assertSeeText('Stock físico total')
            ->assertSee('FR-PALES-VISIBLES')
            ->assertSee('Descargar')
            ->assertSee('data-stock-export-trigger', false)
            ->assertDontSeeText('Huecos usados')
            ->assertDontSeeText('Total de ubicaciones ocupadas')
            ->assertDontSee('data-storage-occupancy-summary', false)
            ->assertDontSee('HUECO-SECRETO-01')
            ->assertDontSeeText('Ubicacion')
            ->assertDontSee('_OCULTO-HUECOS');
    }

    public function test_superadmin_administracion_y_almacen_siguen_viendo_huecos_aunque_cliente_los_oculte(): void
    {
        [$friesland] = $this->seedBaseData();

        $friesland->update(['show_storage_occupancy_to_client' => false]);
        $location = Location::factory()->create(['code' => 'HUECO-INTERNO-01']);
        $item = Item::factory()->create(['client_id' => $friesland->id, 'sku' => 'FR-HUECO-INTERNO']);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'location_text' => $location->code,
            'quantity_units' => 100,
        ]);

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $this->actingAs($this->makeUserWithRole($roleSlug))
                ->get(route('stock.index', ['client_id' => $friesland->id]))
                ->assertOk()
                ->assertSeeText('Huecos usados')
                ->assertSee('data-storage-occupancy-summary', false)
                ->assertSee('HUECO-INTERNO-01');
        }
    }

    public function test_roles_internos_siguen_viendo_total_global_aunque_cliente_lo_oculte(): void
    {
        [$friesland] = $this->seedBaseData();

        $friesland->update(['show_stock_total_to_client' => false]);
        $item = Item::factory()->create(['client_id' => $friesland->id, 'sku' => 'FR-TOTAL-INTERNO']);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $item->id,
            'quantity_units' => 100,
            'full_pallets' => 1,
            'warehouse_pallets' => 2338,
        ]);

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $this->actingAs($this->makeUserWithRole($roleSlug))
                ->get(route('stock.index', ['client_id' => $friesland->id]))
                ->assertOk()
                ->assertSeeText('Pallets almacen')
                ->assertSeeText('2.338,00')
                ->assertSee('data-stock-total-summary', false)
                ->assertSee('Descargar');
        }
    }

    public function test_cliente_no_puede_forzar_ocupacion_con_parametros_de_url(): void
    {
        [$friesland] = $this->seedBaseData();

        $friesland->update(['show_storage_occupancy_to_client' => false]);
        $location = Location::factory()->create(['code' => 'HUECO-FORZADO-01']);
        $item = Item::factory()->create(['client_id' => $friesland->id, 'sku' => 'FR-FORZADO']);
        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'location_text' => $location->code,
            'quantity_units' => 100,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $friesland))
            ->get(route('stock.index', [
                'show_storage_occupancy_to_client' => 1,
                'canSeeStorageOccupancy' => 1,
                'location' => 'HUECO-FORZADO-01',
                'location_id' => $location->id,
            ]))
            ->assertOk()
            ->assertSee('FR-FORZADO')
            ->assertDontSeeText('Huecos usados')
            ->assertDontSee('HUECO-FORZADO-01')
            ->assertDontSee('data-storage-occupancy-summary', false);
    }

    public function test_cliente_cannot_force_other_client_with_query_string(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();

        $frItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'SKU-FR-SAFE',
        ]);

        $edItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-ED-BLOCK',
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frItem->id,
            'lot' => 'LOT-FR-SAFE',
            'quantity_units' => 100,
        ]);

        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'lot' => 'LOT-ED-BLOCK',
            'quantity_units' => 100,
        ]);

        $user = $this->makeUserWithRole(Role::CLIENTE, $friesland);

        $this->actingAs($user)
            ->get(route('stock.index', ['client_id' => $edelvives->id]))
            ->assertOk()
            ->assertSee('SKU-FR-SAFE')
            ->assertDontSee('SKU-ED-BLOCK')
            ->assertDontSee('client_id='.$edelvives->id, false);
    }

    public function test_cliente_can_search_within_own_inventory(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();

        $frMatch = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'SKU-FR-MATCH',
            'description' => 'Mercancia visible',
        ]);

        $frOther = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'SKU-FR-OTHER',
            'description' => 'Otra referencia',
        ]);

        $edMatch = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-ED-MATCH',
            'description' => 'Mercancia visible',
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frMatch->id,
            'lot' => 'LOT-BUSQUEDA',
            'quantity_units' => 100,
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frOther->id,
            'lot' => 'LOT-OTRO',
            'quantity_units' => 100,
        ]);

        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edMatch->id,
            'lot' => 'LOT-BUSQUEDA',
            'quantity_units' => 100,
        ]);

        $user = $this->makeUserWithRole(Role::CLIENTE, $friesland);

        $this->actingAs($user)
            ->get(route('stock.index', [
                'search' => 'MATCH',
                'lot' => 'LOT-BUSQUEDA',
                'client_id' => $edelvives->id,
            ]))
            ->assertOk()
            ->assertSee('SKU-FR-MATCH')
            ->assertDontSee('SKU-FR-OTHER')
            ->assertDontSee('SKU-ED-MATCH');
    }

    public function test_stock_view_shows_received_at_and_batch_status(): void
    {
        [$client] = $this->seedBaseData();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-BATCH-01',
            'description' => 'Articulo con partida',
            'units_per_pallet' => 700,
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-B1',
            'location_text' => 'A1-02',
            'quantity_units' => 500,
            'units_per_pallet' => 700,
            'full_pallets' => 0,
            'peaks_count' => 1,
            'peak_1' => 500,
            'received_at' => '2026-06-20',
            'status' => StockPallet::STATUS_BLOCKED,
            'blocked_reason' => 'Retenido por calidad',
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('20/06/2026')
            ->assertSee('Bloqueado')
            ->assertSee('Retenido por calidad');
    }

    public function test_stock_view_shows_location_code_when_location_id_exists(): void
    {
        [$client] = $this->seedBaseData();

        $warehouse = Warehouse::factory()->create();
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A1-REAL',
        ]);

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-LOC-01',
            'units_per_pallet' => 700,
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-LOC',
            'location_id' => $location->id,
            'location_text' => 'ANTIGUA',
            'quantity_units' => 700,
            'units_per_pallet' => 700,
            'full_pallets' => 1,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('A1-REAL')
            ->assertDontSee('ANTIGUA');
    }

    public function test_stock_view_can_filter_references_without_stock(): void
    {
        [$client] = $this->seedBaseData();

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-CON-STOCK',
            'description' => 'Con stock',
        ]);

        $withoutStock = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-SIN-STOCK',
            'description' => 'Sin stock',
        ]);

        $itemWithStock = Item::query()->where('sku', 'SKU-CON-STOCK')->firstOrFail();

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $itemWithStock->id,
            'lot' => 'LOT-WITH',
            'location_text' => 'A2-01',
            'quantity_units' => 400,
            'units_per_pallet' => 700,
            'full_pallets' => 0,
            'peaks_count' => 1,
            'peak_1' => 400,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index', ['stock_state' => 'without_stock']))
            ->assertOk()
            ->assertSee($withoutStock->sku)
            ->assertDontSee('SKU-CON-STOCK');
    }

    public function test_internal_roles_see_edit_location_action_and_can_open_edit_screen(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-EDIT-STOCK',
            'description' => 'Editable',
            'units_per_pallet' => 500,
        ]);

        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-EDIT',
            'quantity_units' => 500,
            'units_per_pallet' => 500,
            'full_pallets' => 1,
        ]);

        foreach ([Role::ALMACEN, Role::ADMINISTRACION, Role::SUPERADMIN] as $role) {
            $user = $this->makeUserWithRole($role);

            $this->actingAs($user)
                ->get(route('stock.index'))
                ->assertOk()
                ->assertSee(route('stock.batches.edit', $stockPallet))
                ->assertSee('Editar ubicacion');

            $this->actingAs($user)
                ->get(route('stock.batches.edit', $stockPallet))
                ->assertOk()
                ->assertSee('Editar ubicacion de partida')
                ->assertSee('SKU-EDIT-STOCK')
                ->assertSee('Esta pantalla solo cambia la ubicacion fisica.');
        }
    }

    public function test_cliente_cannot_access_edit_stock_batch_route(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-NO-EDIT-CLI',
        ]);

        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
        ]);

        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('stock.batches.edit', $stockPallet))
            ->assertForbidden();
    }

    public function test_stock_batch_edit_location_select_hides_duplicate_locations(): void
    {
        [$client] = $this->seedBaseData();
        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
            'code' => '38',
            'name' => 'NAVE 38',
            'active' => true,
        ]);
        $canonicalLocation = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => '11',
            'active' => true,
        ]);
        $duplicateLocationId = DB::table('locations')->insertGetId([
            'warehouse_id' => $warehouse->id,
            'code' => 'Calle 11',
            'name' => 'Duplicada historica',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-LOC-DUP',
        ]);
        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
        ]);

        $response = $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('stock.batches.edit', $stockPallet))
            ->assertOk()
            ->assertSee('value="'.$canonicalLocation->id.'"', false)
            ->assertDontSee('value="'.$duplicateLocationId.'"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'NAVE 38 - Calle 11'));
    }

    public function test_stock_batch_update_rejects_duplicate_non_canonical_location(): void
    {
        [$client] = $this->seedBaseData();
        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
            'code' => '38',
            'name' => 'NAVE 38',
            'active' => true,
        ]);
        Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => '14',
            'active' => true,
        ]);
        $duplicateLocationId = DB::table('locations')->insertGetId([
            'warehouse_id' => $warehouse->id,
            'code' => 'Calle 14',
            'name' => 'Duplicada historica',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-LOC-POST',
        ]);
        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->from(route('stock.batches.edit', $stockPallet))
            ->put(route('stock.batches.update', $stockPallet), [
                'location_id' => $duplicateLocationId,
            ])
            ->assertRedirect(route('stock.batches.edit', $stockPallet))
            ->assertSessionHasErrors('location_id');

        $this->assertNull($stockPallet->fresh()->location_id);
    }

    public function test_internal_user_can_update_only_stock_batch_location(): void
    {
        [$client] = $this->seedBaseData();

        $warehouse = Warehouse::factory()->create([
            'client_id' => null,
        ]);
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'C1-07',
        ]);

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-UPD-STOCK',
            'description' => 'Editable',
            'units_per_pallet' => 800,
        ]);

        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-OLD',
            'quantity_units' => 1600,
            'units_per_pallet' => 800,
            'full_pallets' => 2,
            'peaks_count' => 0,
            'peak_1' => 0,
            'status' => StockPallet::STATUS_AVAILABLE,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->put(route('stock.batches.update', $stockPallet), [
                'lot' => 'LOT-NEW',
                'quantity_units' => 1100,
                'units_per_pallet' => 0,
                'location_id' => $location->id,
                'location_text' => '',
                'received_at' => '2026-06-28',
                'status' => StockPallet::STATUS_BLOCKED,
                'blocked_reason' => '',
            ])
            ->assertRedirect(route('stock.index', ['client_id' => $client->id]));

        $stockPallet->refresh();

        $this->assertSame('LOT-OLD', $stockPallet->lot);
        $this->assertSame(1600, $stockPallet->quantity_units);
        $this->assertSame(800, $stockPallet->units_per_pallet);
        $this->assertSame(2, $stockPallet->full_pallets);
        $this->assertSame(0, $stockPallet->peaks_count);
        $this->assertSame(StockPallet::STATUS_AVAILABLE, $stockPallet->status);
        $this->assertSame($location->id, $stockPallet->location_id);
        $this->assertSame('C1-07', $stockPallet->location_text);
    }

    public function test_stock_view_can_filter_by_selected_item_lot_and_location(): void
    {
        [$client] = $this->seedBaseData();

        $warehouse = Warehouse::factory()->create();
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => 'A9-01',
        ]);

        $matchingItem = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-FILTRO',
            'description' => 'Coincide',
            'units_per_pallet' => 1080,
        ]);

        $otherItem = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-OTRO',
            'description' => 'No coincide',
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $matchingItem->id,
            'lot' => 'LOTE-FILTRO',
            'location_id' => $location->id,
            'location_text' => $location->code,
            'quantity_units' => 70000,
            'units_per_pallet' => 1080,
            'full_pallets' => 64,
            'peaks_count' => 1,
            'peak_1' => 880,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $otherItem->id,
            'lot' => 'LOTE-OTRO',
            'location_text' => 'B1-99',
            'quantity_units' => 500,
            'units_per_pallet' => 500,
            'full_pallets' => 1,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index', [
                'item_id' => $matchingItem->id,
                'lot' => 'LOTE-FILTRO',
                'location_id' => $location->id,
            ]))
            ->assertOk()
            ->assertSee('SKU-FILTRO')
            ->assertSee('LOTE-FILTRO')
            ->assertSee('A9-01')
            ->assertDontSee('SKU-OTRO');
    }

    public function test_almacen_can_view_stock_for_multiple_clients(): void
    {
        $this->assertInternalRoleSeesMultipleClients(Role::ALMACEN);
    }

    public function test_administracion_can_view_stock_for_multiple_clients(): void
    {
        $this->assertInternalRoleSeesMultipleClients(Role::ADMINISTRACION);
    }

    public function test_superadmin_can_view_stock_for_multiple_clients(): void
    {
        $this->assertInternalRoleSeesMultipleClients(Role::SUPERADMIN);
    }

    public function test_internal_roles_can_filter_stock_by_client(): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();

        $frItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'SKU-FR-FILTER',
        ]);

        $edItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-ED-FILTER',
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frItem->id,
            'quantity_units' => 100,
        ]);

        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'quantity_units' => 100,
        ]);

        foreach ([Role::ALMACEN, Role::ADMINISTRACION, Role::SUPERADMIN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('stock.index', ['client_id' => $friesland->id]))
                ->assertOk()
                ->assertSee('SKU-FR-FILTER')
                ->assertDontSee('SKU-ED-FILTER');
        }
    }

    public function test_stock_muestra_pallets_almacen_como_kpi_principal(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-SUMMARY',
            'description' => 'Resumen',
            'units_per_pallet' => 1000,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => 2000,
            'units_per_pallet' => 1000,
            'full_pallets' => 2,
            'peak_1' => 0,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Pallets almacen')
            ->assertSeeText('2,00')
            ->assertDontSee('<th>Cliente</th>', false);
    }

    public function test_stock_no_muestra_unidades_logisticas_como_label_tecnico(): void
    {
        [$client] = $this->seedBaseData();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 1000,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => 2500,
            'units_per_pallet' => 1000,
            'full_pallets' => 2,
            'peaks_count' => 1,
            'peak_1' => 500,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSeeText('Unidades logisticas');
    }

    public function test_stock_no_muestra_metricas_excesivas_en_cabecera(): void
    {
        [$client] = $this->seedBaseData();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 1000,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => 2000,
            'units_per_pallet' => 1000,
            'full_pallets' => 2,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSeeText('Total referencias')
            ->assertDontSeeText('Pallets completos')
            ->assertDontSeeText('Total unidades')
            ->assertDontSeeText('Picos totales');
    }

    public function test_valor_pallets_almacen_equivale_a_total_warehouse_pallets(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-KPI-TOTAL',
            'units_per_pallet' => 1000,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => 3500,
            'units_per_pallet' => 1000,
            'full_pallets' => 3,
            'peaks_count' => 1,
            'warehouse_pallets' => 3.5,
            'peak_1' => 500,
        ]);

        $overview = app(StockOverviewBuilder::class)->build(
            $this->makeUserWithRole(Role::ALMACEN),
            []
        );

        $this->assertSame(4, $overview['summary']['total_logistic_units']);
        $this->assertSame(3.5, $overview['summary']['total_warehouse_pallets']);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Pallets almacen')
            ->assertSeeText('3,50');
    }

    public function test_stock_with_only_warehouse_pallets_is_visible_to_internal_users(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-WAREHOUSE-ONLY',
            'units_per_pallet' => 0,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => 0,
            'units_per_pallet' => 0,
            'full_pallets' => 0,
            'peaks_count' => 0,
            'warehouse_pallets' => 0.5,
            'status' => StockPallet::STATUS_AVAILABLE,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $overview = app(StockOverviewBuilder::class)->build($user, [
            'client_id' => $client->id,
            'search' => 'SKU-WAREHOUSE-ONLY',
        ]);

        $this->assertSame(1, $overview['summary']['references_with_stock']);
        $this->assertSame(0.5, $overview['summary']['total_warehouse_pallets']);

        $this->actingAs($user)
            ->get(route('stock.index', ['client_id' => $client->id, 'search' => 'SKU-WAREHOUSE-ONLY']))
            ->assertOk()
            ->assertSee('SKU-WAREHOUSE-ONLY')
            ->assertSee('0,50');
    }

    public function test_cliente_ve_total_de_pales_como_completos_mas_picos_y_su_detalle(): void
    {
        [$client] = $this->seedBaseData();
        $client->update(['show_stock_total_to_client' => true]);

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 800,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOTE-TOTAL-8',
            'quantity_units' => 5625,
            'units_per_pallet' => 800,
            'full_pallets' => 7,
            'peaks_count' => 1,
            'peak_1' => 25,
            'notes' => 'Manipular con cuidado.',
        ]);

        $user = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Palés almacenados')
            ->assertSeeText('Stock físico total')
            ->assertSeeText('LOTE-TOTAL-8')
            ->assertSeeText('7 completos')
            ->assertSeeText('1 pico')
            ->assertSeeText('Pico 1: 25 uds')
            ->assertSeeText('Manipular con cuidado.')
            ->assertDontSeeText('Pallets almacen')
            ->assertSeeText('Uds/palé');

        $overview = app(StockOverviewBuilder::class)->build($user, []);

        $this->assertSame(8, $overview['summary']['total_logistic_units']);
        $this->assertSame(8.0, $overview['summary']['total_physical_pallets']);
        $this->assertSame(8, $overview['rows']->first()['total_pallets']);
    }

    public function test_cliente_ve_una_linea_por_referencia_y_lote_con_partidas_en_detalle(): void
    {
        [$client] = $this->seedBaseData();
        $client->update(['show_storage_occupancy_to_client' => true]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-AGRUPADO',
            'description' => 'Articulo en dos ubicaciones',
            'units_per_pallet' => 100,
        ]);
        $locationA = Location::factory()->create(['code' => 'A-01']);
        $locationB = Location::factory()->create(['code' => 'B-02']);

        foreach ([[$locationA, 200], [$locationB, 35]] as [$location, $quantity]) {
            StockPallet::factory()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'location_id' => $location->id,
                'lot' => 'LOTE-UNICO',
                'quantity_units' => $quantity,
                'units_per_pallet' => 100,
                'peak_1' => 0,
            ]);
        }

        $user = $this->makeUserWithRole(Role::CLIENTE, $client);
        $overview = app(StockOverviewBuilder::class)->build($user, []);

        $this->assertCount(1, $overview['rows']);
        $this->assertSame(235, $overview['rows']->first()['quantity_units']);
        $this->assertSame(3, $overview['rows']->first()['total_pallets']);
        $this->assertCount(2, $overview['rows']->first()['batches']);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeInOrder(['SKU-AGRUPADO', 'LOTE-UNICO'])
            ->assertSeeText('A-01')
            ->assertSeeText('B-02')
            ->assertSee('stock-table--client', false);
    }

    public function test_superadmin_ve_pallets_totales(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'units_per_pallet' => 600,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => 1295,
            'units_per_pallet' => 600,
            'full_pallets' => 2,
            'peaks_count' => 1,
            'peak_1' => 95,
        ]);

        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Pallets almacen')
            ->assertSeeText('3,00');
    }

    public function test_stock_index_is_paginated_by_default(): void
    {
        [$client] = $this->seedBaseData();

        for ($index = 1; $index <= 30; $index++) {
            $item = Item::factory()->create([
                'client_id' => $client->id,
                'sku' => 'SKU-PAG-'.$index,
                'description' => 'Articulo '.$index,
                'units_per_pallet' => 100,
            ]);

            StockPallet::factory()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'lot' => 'LOT-PAG-'.$index,
                'quantity_units' => 100,
                'units_per_pallet' => 100,
                'full_pallets' => 1,
                'status' => StockPallet::STATUS_AVAILABLE,
            ]);
        }

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Mostrando 1-25 de 30 registros')
            ->assertSee('page=2', false);
    }

    public function test_stock_pagination_keeps_filters_in_query_string(): void
    {
        [$client] = $this->seedBaseData();

        for ($index = 1; $index <= 30; $index++) {
            $item = Item::factory()->create([
                'client_id' => $client->id,
                'sku' => 'SKU-FILTRO-PAG-'.$index,
                'description' => 'Articulo filtro '.$index,
                'units_per_pallet' => 100,
            ]);

            StockPallet::factory()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'lot' => 'LOT-FILTRO-PAG-'.$index,
                'quantity_units' => 100,
                'units_per_pallet' => 100,
                'full_pallets' => 1,
                'status' => StockPallet::STATUS_AVAILABLE,
            ]);
        }

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index', [
                'stock_state' => 'with_stock',
                'per_page' => 25,
                'only_peaks' => 1,
            ]))
            ->assertOk()
            ->assertSee('stock_state=with_stock', false)
            ->assertSee('per_page=25', false)
            ->assertSee('only_peaks=1', false);
    }

    public function test_stock_headers_do_not_show_peak_columns_and_detail_contains_peaks(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-PEAK-DETAIL',
            'description' => 'Detalle con picos',
            'units_per_pallet' => 500,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-PEAK',
            'quantity_units' => 1400,
            'units_per_pallet' => 500,
            'full_pallets' => 2,
            'peaks_count' => 1,
            'peak_1' => 400,
            'status' => StockPallet::STATUS_AVAILABLE,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('<th class="stock-table-center">Picos</th>', false)
            ->assertDontSee('<th>Pico 1</th>', false)
            ->assertDontSee('<th>Pico 10</th>', false)
            ->assertSee('Distribucion de picos')
            ->assertSee('Pico 1')
            ->assertSee('400');
    }

    public function test_stock_view_marks_blocked_rows_with_visual_class(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-BLOCK-VISUAL',
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'status' => StockPallet::STATUS_BLOCKED,
            'blocked_reason' => 'Calidad',
            'quantity_units' => 400,
            'units_per_pallet' => 400,
            'full_pallets' => 1,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('stock-row stock-row--blocked', false)
            ->assertSee('stock-mobile-card--blocked', false);
    }

    public function test_stock_view_marks_obsolete_rows_with_visual_class(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-OBSOLETE-VISUAL',
            'status' => Item::STATUS_OBSOLETE,
            'active' => false,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'status' => StockPallet::STATUS_AVAILABLE,
            'quantity_units' => 300,
            'units_per_pallet' => 300,
            'full_pallets' => 1,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('stock-row stock-row--obsolete', false)
            ->assertSee('stock-mobile-card--obsolete', false);
    }

    public function test_stock_view_includes_mobile_card_structure(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-MOBILE',
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-MOBILE',
            'quantity_units' => 100,
            'units_per_pallet' => 100,
            'full_pallets' => 1,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('stock-mobile-list', false)
            ->assertSee('stock-mobile-card', false)
            ->assertSeeText('Lote')
            ->assertSeeText('Pallets');
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

    private function makeItemWithStock(
        Client $client,
        string $sku,
        string $itemStatus,
        string $category,
        string $batchStatus,
    ): Item {
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => $sku,
            'description' => 'Desc '.$sku,
            'status' => $itemStatus,
            'stock_category' => $category,
            'active' => $itemStatus === Item::STATUS_ACTIVE,
            'units_per_pallet' => 100,
        ]);

        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-'.$sku,
            'quantity_units' => 100,
            'units_per_pallet' => 100,
            'full_pallets' => 1,
            'status' => $batchStatus,
            'stock_category' => $category,
        ]);

        return $item;
    }

    private function assertInternalRoleSeesMultipleClients(string $roleSlug): void
    {
        [$friesland, $edelvives] = $this->seedBaseData();

        $frItem = Item::factory()->create([
            'client_id' => $friesland->id,
            'sku' => 'SKU-FR-'.$roleSlug,
        ]);

        $edItem = Item::factory()->create([
            'client_id' => $edelvives->id,
            'sku' => 'SKU-ED-'.$roleSlug,
        ]);

        StockPallet::factory()->create([
            'client_id' => $friesland->id,
            'item_id' => $frItem->id,
            'quantity_units' => 100,
        ]);

        StockPallet::factory()->create([
            'client_id' => $edelvives->id,
            'item_id' => $edItem->id,
            'quantity_units' => 100,
        ]);

        $user = $this->makeUserWithRole($roleSlug);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('SKU-FR-'.$roleSlug)
            ->assertSee('SKU-ED-'.$roleSlug)
            ->assertSee('FRIESLAND')
            ->assertSee('EDELVIVES')
            ->assertSee('name="client_id"', false);
    }
}
