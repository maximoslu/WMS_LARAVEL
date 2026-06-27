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
            'lot' => 'LOT-001',
            'location_text' => 'A1-01',
            'pallet_code' => 'PAL-STOCK-001',
            'quantity_units' => 700,
            'received_at' => '2026-06-26',
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertOk()
            ->assertSee('Vista operativa por partida y fecha de entrada')
            ->assertSee('SKU-STOCK-01')
            ->assertSee('LOT-001');
    }

    public function test_cliente_cannot_view_stock_index(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('stock.index'))
            ->assertForbidden();
    }

    public function test_stock_view_shows_received_at_and_batch_status(): void
    {
        [$client] = $this->seedBaseData();
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-BATCH-01',
            'description' => 'Artículo con partida',
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-B1',
            'location_text' => 'A1-02',
            'pallet_code' => 'PAL-BATCH-001',
            'quantity_units' => 500,
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
        ]);

        StockPallet::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'lot' => 'LOT-LOC',
            'location_id' => $location->id,
            'location_text' => 'ANTIGUA',
            'pallet_code' => 'PAL-LOC-001',
            'quantity_units' => 700,
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
            'pallet_code' => 'PAL-WITH-001',
            'quantity_units' => 400,
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
