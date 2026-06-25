<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
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
