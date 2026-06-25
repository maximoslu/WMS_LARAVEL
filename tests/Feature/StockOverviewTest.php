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
            'units_per_pallet' => 700,
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_text' => 'A1-01',
            'pallet_code' => 'PAL-STOCK-001',
            'quantity_units' => 700,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Vista operativa por articulo')
            ->assertSee('SKU-STOCK-01');
    }

    public function test_cliente_cannot_view_stock_index(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertForbidden();
    }

    public function test_stock_view_shows_peak_quantities(): void
    {
        [$client] = $this->seedBaseData();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-PICO-01',
            'description' => 'Articulo con pico',
            'units_per_pallet' => 700,
        ]);

        foreach ([700, 300] as $index => $quantity) {
            StockPallet::query()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'location_text' => 'A1-0'.($index + 1),
                'pallet_code' => 'PAL-PICO-00'.($index + 1),
                'quantity_units' => $quantity,
                'active' => true,
            ]);
        }

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('SKU-PICO-01')
            ->assertSee('300')
            ->assertSee('Picos');
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
            'location_id' => $location->id,
            'location_text' => 'ANTIGUA',
            'pallet_code' => 'PAL-LOC-001',
            'quantity_units' => 700,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('A1-REAL')
            ->assertDontSee('ANTIGUA');
    }

    public function test_stock_view_shows_peak_overflow_and_detail_for_more_than_five_peaks(): void
    {
        [$client] = $this->seedBaseData();

        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-PICOS-07',
            'description' => 'Articulo con muchos picos',
            'units_per_pallet' => 700,
        ]);

        foreach ([100, 110, 120, 130, 140, 150, 160] as $index => $quantity) {
            StockPallet::query()->create([
                'client_id' => $client->id,
                'item_id' => $item->id,
                'location_text' => 'PX-0'.($index + 1),
                'pallet_code' => 'PAL-PICOS-0'.($index + 1),
                'quantity_units' => $quantity,
                'active' => true,
            ]);
        }

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('+2 picos')
            ->assertSee('Ver picos')
            ->assertSee('100 uds')
            ->assertSee('160 uds');
    }

    public function test_stock_view_can_filter_references_without_stock(): void
    {
        [$client] = $this->seedBaseData();

        Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-CON-STOCK',
            'description' => 'Con stock',
            'units_per_pallet' => 400,
        ]);

        $withoutStock = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-SIN-STOCK',
            'description' => 'Sin stock',
            'units_per_pallet' => 500,
        ]);

        $itemWithStock = Item::query()->where('sku', 'SKU-CON-STOCK')->firstOrFail();

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $itemWithStock->id,
            'location_text' => 'A2-01',
            'pallet_code' => 'PAL-WITH-001',
            'quantity_units' => 400,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index', ['stock_state' => 'without_stock']))
            ->assertOk()
            ->assertSee($withoutStock->sku)
            ->assertDontSee('SKU-CON-STOCK');
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
}
