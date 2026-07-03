<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertSee('64')
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
            ->assertSee('Mi inventario')
            ->assertSee('Consulta tus existencias, lotes, pallets y picos disponibles.')
            ->assertSee('Usa el buscador para localizar por SKU, descripcion o lote.')
            ->assertSee('SKU-FR-ONLY')
            ->assertDontSee('SKU-ED-HIDE')
            ->assertDontSee('name="client_id"', false);
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

    public function test_superadmin_sees_edit_batch_action_and_can_open_edit_screen(): void
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

        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee(route('stock.batches.edit', $stockPallet));

        $this->actingAs($user)
            ->get(route('stock.batches.edit', $stockPallet))
            ->assertOk()
            ->assertSee('Editar partida de stock')
            ->assertSee('SKU-EDIT-STOCK');
    }

    public function test_administracion_does_not_see_edit_batch_action_and_cannot_access_edit_route(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-NO-EDIT-ADMIN',
        ]);

        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
        ]);

        $user = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSee(route('stock.batches.edit', $stockPallet));

        $this->actingAs($user)
            ->get(route('stock.batches.edit', $stockPallet))
            ->assertForbidden();
    }

    public function test_almacen_does_not_see_edit_batch_action_and_cannot_access_edit_route(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-NO-EDIT-ALM',
        ]);

        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSee(route('stock.batches.edit', $stockPallet));

        $this->actingAs($user)
            ->get(route('stock.batches.edit', $stockPallet))
            ->assertForbidden();
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

    public function test_superadmin_can_update_stock_batch_and_keep_operational_stock_without_pallet_breakdown(): void
    {
        [$client] = $this->seedBaseData();

        $warehouse = Warehouse::factory()->create();
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
        ]);

        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->put(route('stock.batches.update', $stockPallet), [
                'lot' => 'LOT-NEW',
                'quantity_units' => 1100,
                'units_per_pallet' => 0,
                'location_id' => $location->id,
                'location_text' => '',
                'received_at' => '2026-06-28',
                'status' => StockPallet::STATUS_AVAILABLE,
                'blocked_reason' => '',
            ])
            ->assertRedirect(route('stock.index', ['client_id' => $client->id]));

        $stockPallet->refresh();

        $this->assertSame('LOT-NEW', $stockPallet->lot);
        $this->assertSame(1100, $stockPallet->quantity_units);
        $this->assertSame(0, $stockPallet->units_per_pallet);
        $this->assertSame(0, $stockPallet->full_pallets);
        $this->assertSame(0, $stockPallet->peaks_count);
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

    public function test_stock_table_does_not_show_cliente_column_and_summary_shows_operational_kpis(): void
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
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSeeText('Pallets completos')
            ->assertSeeText('Picos totales')
            ->assertSeeText('Unidades logisticas')
            ->assertDontSee('<th>Cliente</th>', false);
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
