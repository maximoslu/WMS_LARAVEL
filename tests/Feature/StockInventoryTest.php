<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\Warehouse;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Tests\TestCase;

class StockInventoryTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_roles_can_open_inventory_and_select_client(): void
    {
        $client = Client::factory()->create(['name' => 'Cliente inventario']);

        foreach ([Role::ALMACEN, Role::ADMINISTRACION, Role::SUPERADMIN] as $role) {
            $this->actingAs($this->user($role))
                ->get(route('stock.inventory.index', ['client_id' => $client->id]))
                ->assertOk()
                ->assertSee('INVENTARIO')
                ->assertSee('Cliente inventario')
                ->assertSee('Ver inventario')
                ->assertSee('Descargar inventario');
        }
    }

    public function test_filter_options_are_scoped_to_selected_client_and_warehouse(): void
    {
        $first = Client::factory()->create(['name' => 'Cliente Uno']);
        $second = Client::factory()->create(['name' => 'Cliente Dos']);
        $firstWarehouse = Warehouse::factory()->create(['client_id' => $first->id, 'name' => 'Nave Uno', 'code' => '1']);
        $secondWarehouse = Warehouse::factory()->create(['client_id' => $second->id, 'name' => 'Nave Dos', 'code' => '2']);
        $firstLocation = Location::factory()->create(['warehouse_id' => $firstWarehouse->id, 'code' => '1']);
        Location::factory()->create(['warehouse_id' => $firstWarehouse->id, 'code' => '2']);
        Location::factory()->create(['warehouse_id' => $secondWarehouse->id, 'code' => '99']);
        $this->stock($first, $firstLocation, 'UNO-ACTIVO', StockPallet::CATEGORY_IN_USE);
        $this->stock($second, null, 'DOS-VARIOS', StockPallet::CATEGORY_MISC);

        $response = $this->actingAs($this->user(Role::ALMACEN))
            ->get(route('stock.inventory.index', [
                'client_id' => $first->id,
                'warehouse_id' => $firstWarehouse->id,
            ]));

        $response->assertOk()
            ->assertSee('Nave Uno')
            ->assertDontSee('Nave Dos')
            ->assertSee('ACTIVO')
            ->assertDontSee('VARIOS')
            ->assertSee('Nave Uno - Calle 1')
            ->assertDontSee('Nave Dos - Ubicacion 99');
    }

    public function test_item_state_category_batch_status_and_location_filters_use_real_fields(): void
    {
        $client = Client::factory()->create();
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id, 'code' => '38', 'name' => 'NAVE 38']);
        $location = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '7']);
        $this->stock($client, $location, 'ACTIVO-OK', StockPallet::CATEGORY_IN_USE, Item::STATUS_ACTIVE, StockPallet::STATUS_AVAILABLE);
        $this->stock($client, $location, 'INACTIVO-BLOQ', StockPallet::CATEGORY_BLOCKED, Item::STATUS_BLOCKED, StockPallet::STATUS_BLOCKED);
        $this->stock($client, null, 'INACTIVO-OBS', StockPallet::CATEGORY_OBSOLETE, Item::STATUS_OBSOLETE, StockPallet::STATUS_OBSOLETE);
        $user = $this->user(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.inventory.index', ['client_id' => $client->id, 'item_state' => 'active']))
            ->assertOk()->assertSee('ACTIVO-OK')->assertDontSee('INACTIVO-BLOQ')->assertDontSee('INACTIVO-OBS');

        $this->actingAs($user)
            ->get(route('stock.inventory.index', ['client_id' => $client->id, 'item_state' => 'inactive']))
            ->assertOk()->assertDontSee('ACTIVO-OK')->assertSee('INACTIVO-BLOQ')->assertSee('INACTIVO-OBS');

        $this->actingAs($user)
            ->get(route('stock.inventory.index', ['client_id' => $client->id, 'stock_category' => StockPallet::CATEGORY_BLOCKED]))
            ->assertOk()->assertSee('INACTIVO-BLOQ')->assertDontSee('ACTIVO-OK')->assertDontSee('INACTIVO-OBS');

        $this->actingAs($user)
            ->get(route('stock.inventory.index', ['client_id' => $client->id, 'batch_status' => StockPallet::STATUS_OBSOLETE]))
            ->assertOk()->assertSee('INACTIVO-OBS')->assertDontSee('ACTIVO-OK')->assertDontSee('INACTIVO-BLOQ');

        $this->actingAs($user)
            ->get(route('stock.inventory.index', ['client_id' => $client->id, 'location_state' => 'with_location']))
            ->assertOk()->assertSee('ACTIVO-OK')->assertSee('INACTIVO-BLOQ')->assertDontSee('INACTIVO-OBS');

        $this->actingAs($user)
            ->get(route('stock.inventory.index', ['client_id' => $client->id, 'location_state' => 'without_location']))
            ->assertOk()->assertDontSee('ACTIVO-OK')->assertDontSee('INACTIVO-BLOQ')->assertSee('INACTIVO-OBS');
    }

    public function test_include_zero_adds_only_zero_stock_item_master_rows(): void
    {
        $client = Client::factory()->create();
        $withStock = $this->stock($client, null, 'CON-STOCK');
        $zero = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'A-CERO',
            'description' => 'Referencia sin existencias',
        ]);
        $user = $this->user(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.inventory.index', ['client_id' => $client->id]))
            ->assertOk()->assertSee($withStock->item->sku)->assertDontSee($zero->sku);

        $this->actingAs($user)
            ->get(route('stock.inventory.index', ['client_id' => $client->id, 'stock_state' => 'include_zero']))
            ->assertOk()->assertSee($withStock->item->sku)->assertSee($zero->sku);
    }

    public function test_inventory_is_tenant_safe_for_internal_selection_and_client_tampering(): void
    {
        $own = Client::factory()->create(['name' => 'Propio']);
        $other = Client::factory()->create(['name' => 'Ajeno']);
        $this->stock($own, null, 'SKU-PROPIO');
        $this->stock($other, null, 'SKU-AJENO');

        $this->actingAs($this->user(Role::ALMACEN))
            ->get(route('stock.inventory.index', ['client_id' => $own->id]))
            ->assertOk()->assertSee('SKU-PROPIO')->assertDontSee('SKU-AJENO');

        $clientUser = $this->user(Role::CLIENTE, $own);
        $this->actingAs($clientUser)
            ->get(route('stock.inventory.index', ['client_id' => $other->id]))
            ->assertOk()->assertSee('SKU-PROPIO')->assertDontSee('SKU-AJENO')->assertDontSee('Ajeno');

        $export = $this->actingAs($clientUser)
            ->get(route('stock.inventory.export', ['client_id' => $other->id]));
        $export->assertOk();
        $flat = collect($this->xlsxRows($export->baseResponse->getFile()->getPathname()))->flatten()->implode('|');
        $this->assertStringContainsString('SKU-PROPIO', $flat);
        $this->assertStringNotContainsString('SKU-AJENO', $flat);
    }

    public function test_client_location_visibility_setting_is_respected_in_preview_filters_and_export(): void
    {
        $client = Client::factory()->create(['show_storage_occupancy_to_client' => false]);
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id, 'name' => 'Nave privada', 'code' => '38']);
        $location = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '17']);
        $this->stock($client, $location, 'SKU-PRIVADO');
        $user = $this->user(Role::CLIENTE, $client);

        $preview = $this->actingAs($user)->get(route('stock.inventory.index', [
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
        ]));
        $preview->assertOk()
            ->assertSee('SKU-PRIVADO')
            ->assertDontSee('Nave privada')
            ->assertDontSee('Ubicación concreta')
            ->assertDontSee('Asignación de ubicación')
            ->assertSee('No visible');

        $export = $this->actingAs($user)->get(route('stock.inventory.export', [
            'warehouse_id' => $warehouse->id,
            'location_id' => $location->id,
        ]));
        $export->assertOk();
        $rows = $this->xlsxRows($export->baseResponse->getFile()->getPathname());
        $flat = collect($rows)->flatten()->implode('|');
        $this->assertStringContainsString('SKU-PRIVADO', $flat);
        $this->assertStringContainsString('No visible', $flat);
        $this->assertStringNotContainsString('Nave privada', $flat);
        $this->assertSame('Sin almacén', $rows[1][1]);
        $this->assertSame('No visible', $rows[1][2]);
    }

    public function test_export_contains_exact_filtered_lines_columns_and_natural_location_order(): void
    {
        $client = Client::factory()->create(['name' => 'Cliente Natural', 'code' => 'NAT']);
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id, 'name' => 'Nave Natural', 'code' => '1']);

        foreach (['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', 'A1', 'A2', 'A10'] as $code) {
            $location = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => $code]);
            $this->stock($client, $location, 'SKU-'.str_pad($code, 2, '0', STR_PAD_LEFT));
        }

        $otherWarehouse = Warehouse::factory()->create(['client_id' => $client->id, 'name' => 'Otra nave', 'code' => '2']);
        $otherLocation = Location::factory()->create(['warehouse_id' => $otherWarehouse->id, 'code' => '1']);
        $this->stock($client, $otherLocation, 'NO-DEBE-SALIR');
        $user = $this->user(Role::ALMACEN);
        $query = ['client_id' => $client->id, 'warehouse_id' => $warehouse->id];

        $preview = $this->actingAs($user)->get(route('stock.inventory.index', $query));
        $preview->assertOk()->assertDontSee('NO-DEBE-SALIR');

        $export = $this->actingAs($user)->get(route('stock.inventory.export', $query));
        $export->assertOk()->assertDownload('inventario_nat_'.now()->format('Y-m-d').'.xlsx');
        $rows = $this->xlsxRows($export->baseResponse->getFile()->getPathname());

        $this->assertSame([
            'CLIENTE', 'ALMACÉN', 'UBICACIÓN', 'REFERENCIA / SKU', 'DESCRIPCIÓN', 'LOTE',
            'UNIDADES POR PALLET', 'PALLETS TEÓRICOS', 'PICOS/UDS TEÓRICAS', 'TOTAL TEÓRICO',
            'PALLETS CONTADOS', 'PICOS/UDS CONTADAS', 'OBSERVACIONES',
        ], $rows[0]);
        $this->assertSame(
            ['1', '2', '3', '4', '5', '6', '7', '8', '9', '10', '11', 'A1', 'A2', 'A10'],
            collect(array_slice($rows, 1))->pluck(2)->all(),
        );
        $this->assertSame(14, $preview->viewData('paginator')->total());
        $this->assertCount(15, $rows);
        $this->assertStringNotContainsString('NO-DEBE-SALIR', collect($rows)->flatten()->implode('|'));
        $this->assertSame(2, $rows[1][7]);
        $this->assertSame('1 / 50', $rows[1][8]);
        $this->assertSame(250, $rows[1][9]);
        $this->assertSame(['', '', ''], array_slice($rows[1], 10, 3));
    }

    public function test_preview_and_download_do_not_modify_stock_or_create_movements(): void
    {
        $client = Client::factory()->create();
        $stock = $this->stock($client, null, 'INMUTABLE');
        $before = $stock->fresh()->getAttributes();
        $movementCount = InventoryMovement::query()->count();
        $user = $this->user(Role::ALMACEN);
        $query = ['client_id' => $client->id];

        $this->actingAs($user)->get(route('stock.inventory.index', $query))->assertOk();
        $this->actingAs($user)->get(route('stock.inventory.export', $query))->assertOk();

        $this->assertSame($before, $stock->fresh()->getAttributes());
        $this->assertSame($movementCount, InventoryMovement::query()->count());
        $this->assertSame(2, $stock->fresh()->full_pallets);
        $this->assertSame(1, $stock->fresh()->peaks_count);
        $this->assertSame(250, $stock->fresh()->quantity_units);
    }

    private function user(string $roleSlug, ?Client $client = null): User
    {
        $this->seed(RoleSeeder::class);
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $roleSlug === Role::CLIENTE ? $client?->id : null,
        ]);
    }

    private function stock(
        Client $client,
        ?Location $location,
        string $sku,
        string $category = StockPallet::CATEGORY_IN_USE,
        string $itemStatus = Item::STATUS_ACTIVE,
        string $batchStatus = StockPallet::STATUS_AVAILABLE,
    ): StockPallet {
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => $sku,
            'description' => 'Descripción '.$sku,
            'units_per_pallet' => 100,
            'active' => $itemStatus === Item::STATUS_ACTIVE,
            'status' => $itemStatus,
            'stock_category' => $category,
        ]);

        return StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location?->id,
            'location_text' => $location?->code,
            'lot' => 'LOTE-'.$sku,
            'quantity_units' => 250,
            'units_per_pallet' => 100,
            'peak_1' => 50,
            'status' => $batchStatus,
            'stock_category' => $category,
            'active' => true,
        ]);
    }

    /** @return list<list<mixed>> */
    private function xlsxRows(string $path): array
    {
        $reader = new XlsxReader;
        $reader->open($path);
        $rows = [];

        foreach ($reader->getSheetIterator() as $sheet) {
            $this->assertSame('INVENTARIO', $sheet->getName());

            foreach ($sheet->getRowIterator() as $row) {
                $rows[] = $row->toArray();
            }
        }

        $reader->close();

        return $rows;
    }
}
