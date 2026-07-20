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
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StockRelocationTest extends TestCase
{
    use RefreshDatabase;

    public function test_internal_roles_can_view_stock_relocation_screen(): void
    {
        [$client, $item] = $this->stockFixture();

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $this->actingAs($this->makeUserWithRole($roleSlug))
                ->get(route('stock.relocations.create', [
                    'client_id' => $client->id,
                    'item_id' => $item->id,
                ]))
                ->assertOk()
                ->assertSee('Reubicar stock')
                ->assertSee('Esta accion no modifica cantidades ni descuenta stock');
        }
    }

    public function test_cliente_cannot_view_or_execute_stock_relocation(): void
    {
        [$client, $item, $stockPallet, $source, $destination] = $this->stockFixture();
        $cliente = $this->makeUserWithRole(Role::CLIENTE, $client);

        $this->actingAs($cliente)
            ->get(route('stock.relocations.create'))
            ->assertForbidden();

        $this->actingAs($cliente)
            ->post(route('stock.relocations.store'), [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
                'destination_location_id' => $destination->id,
            ])
            ->assertForbidden();

        $this->assertSame($source->id, $stockPallet->fresh()->location_id);
    }

    public function test_internal_user_can_relocate_stock_batch_without_changing_quantities(): void
    {
        [$client, $item, $stockPallet, $source, $destination] = $this->stockFixture([
            'lot' => 'LOT-REUBICAR',
            'quantity_units' => 2500,
            'units_per_pallet' => 1000,
            'full_pallets' => 2,
            'peaks_count' => 1,
            'warehouse_pallets' => 3,
            'peak_1' => 500,
            'stock_category' => StockPallet::CATEGORY_BLOCKED,
            'status' => StockPallet::STATUS_BLOCKED,
        ]);
        $before = $stockPallet->only([
            'client_id', 'item_id', 'lot', 'quantity_units', 'units_per_pallet', 'full_pallets',
            'peaks_count', 'warehouse_pallets', 'peak_1', 'stock_category', 'status', 'active',
        ]);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->post(route('stock.relocations.store'), [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
                'destination_location_id' => $destination->id,
            ])
            ->assertRedirect(route('stock.relocations.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
                'destination_location_id' => $destination->id,
            ]))
            ->assertSessionHas('status', 'Stock reubicado correctamente. Solo se ha cambiado la ubicacion fisica.');

        $fresh = $stockPallet->fresh();

        $this->assertSame($destination->id, $fresh->location_id);
        $this->assertSame($destination->code, $fresh->location_text);
        $this->assertNotSame($source->id, $fresh->location_id);
        $this->assertSame($before, $fresh->only(array_keys($before)));

        $movement = InventoryMovement::query()->latest('id')->firstOrFail();
        $this->assertSame(InventoryMovement::TRANSFER, $movement->movement_type);
        $this->assertSame($source->id, $movement->from_location_id);
        $this->assertSame($destination->id, $movement->to_location_id);
        $this->assertSame(0, $movement->units_delta);
        $this->assertSame(0, $movement->full_pallets_delta);
        $this->assertSame('0.00', (string) $movement->warehouse_pallets_delta);
    }

    public function test_same_location_is_rejected(): void
    {
        [$client, $item, $stockPallet, $source] = $this->stockFixture();

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->from(route('stock.relocations.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
            ]))
            ->post(route('stock.relocations.store'), [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
                'destination_location_id' => $source->id,
            ])
            ->assertRedirect(route('stock.relocations.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
            ]))
            ->assertSessionHasErrors('destination_location_id');
    }

    public function test_stock_from_another_client_is_rejected(): void
    {
        [$client, $item] = $this->stockFixture();
        [$otherClient, $otherItem, $otherStock, , $destination] = $this->stockFixture();

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->post(route('stock.relocations.store'), [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $otherStock->id,
                'destination_location_id' => $destination->id,
            ])
            ->assertSessionHasErrors('stock_pallet_id');

        $this->assertSame($otherClient->id, $otherStock->fresh()->client_id);
        $this->assertSame($otherItem->id, $otherStock->fresh()->item_id);
    }

    public function test_inactive_or_incompatible_destination_is_rejected(): void
    {
        [$client, $item, $stockPallet] = $this->stockFixture();
        $inactive = $this->locationForClient($client, 'INACT-01', false);
        $inactiveWarehouse = Warehouse::factory()->inactive()->create([
            'client_id' => $client->id,
        ]);
        $locationInInactiveWarehouse = Location::factory()->create([
            'warehouse_id' => $inactiveWarehouse->id,
            'code' => 'WH-OFF-01',
            'active' => true,
        ]);
        $otherClient = Client::factory()->create(['active' => true]);
        $foreign = $this->locationForClient($otherClient, 'FOREIGN-01');

        foreach ([$inactive, $locationInInactiveWarehouse, $foreign] as $destination) {
            $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
                ->post(route('stock.relocations.store'), [
                    'client_id' => $client->id,
                    'item_id' => $item->id,
                    'stock_pallet_id' => $stockPallet->id,
                    'destination_location_id' => $destination->id,
                ])
                ->assertSessionHasErrors('destination_location_id');
        }
    }

    public function test_multiple_batches_require_selecting_a_specific_stock_batch(): void
    {
        [$client, $item, $firstStock] = $this->stockFixture(['lot' => 'LOTE-A']);
        $secondLocation = $this->locationForClient($client, 'SRC-02');
        $destination = $this->locationForClient($client, 'DEST-02');
        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $secondLocation->id,
            'lot' => 'LOTE-B',
            'quantity_units' => 700,
            'units_per_pallet' => 100,
            'full_pallets' => 7,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('stock.relocations.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
            ]))
            ->assertOk()
            ->assertSee('Selecciona la partida concreta')
            ->assertSee('LOTE-A')
            ->assertSee('LOTE-B')
            ->assertSee('name="stock_pallet_id"', false);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->post(route('stock.relocations.store'), [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'destination_location_id' => $destination->id,
            ])
            ->assertSessionHasErrors('stock_pallet_id');

        $this->assertNotSame($destination->id, $firstStock->fresh()->location_id);
    }

    public function test_screen_shows_current_and_destination_locations(): void
    {
        [$client, $item, $stockPallet, $source, $destination] = $this->stockFixture([
            'lot' => 'LOTE-VISIBLE',
            'quantity_units' => 1250,
            'units_per_pallet' => 1000,
            'full_pallets' => 1,
            'peaks_count' => 1,
            'peak_1' => 250,
        ]);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('stock.relocations.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
                'destination_location_id' => $destination->id,
            ]))
            ->assertOk()
            ->assertSee('Resumen de reubicacion')
            ->assertSee('Partida concreta')
            ->assertSee('#'.$stockPallet->id)
            ->assertSee('Ubicacion actual')
            ->assertSee($source->displayLabel())
            ->assertSee('Ubicacion destino')
            ->assertSee($destination->displayLabel())
            ->assertSee('1 pallets')
            ->assertSee('1 picos')
            ->assertSee('250 uds pico')
            ->assertSee('1.250 uds')
            ->assertSee('LOTE-VISIBLE')
            ->assertSee('Reubicar');
    }

    public function test_each_batch_shows_current_location_and_destination_select_hides_duplicates(): void
    {
        [$client, $item, , $source] = $this->stockFixture(['lot' => 'LOTE-A']);
        $secondLocation = $this->locationForClient($client, 'SRC-02');
        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $secondLocation->id,
            'lot' => 'LOTE-B',
            'quantity_units' => 800,
            'units_per_pallet' => 100,
        ]);

        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
            'code' => '38',
            'name' => 'NAVE 38',
            'active' => true,
        ]);
        $canonicalDestination = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => '11',
            'active' => true,
        ]);
        $duplicateDestinationId = DB::table('locations')->insertGetId([
            'warehouse_id' => $warehouse->id,
            'code' => 'Calle 11',
            'name' => 'Duplicada historica',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $response = $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('stock.relocations.create', [
                'client_id' => $client->id,
                'item_id' => $item->id,
            ]))
            ->assertOk()
            ->assertSee('Ubicacion actual: '.$source->displayLabel())
            ->assertSee('Ubicacion actual: '.$secondLocation->displayLabel())
            ->assertSee('Partida #')
            ->assertSee('value="'.$canonicalDestination->id.'"', false)
            ->assertDontSee('value="'.$duplicateDestinationId.'"', false);

        $this->assertSame(1, substr_count($response->getContent(), 'NAVE 38 - Calle 11'));
    }

    public function test_duplicate_non_canonical_destination_is_rejected_on_submit(): void
    {
        [$client, $item, $stockPallet] = $this->stockFixture();
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
        $duplicateDestinationId = DB::table('locations')->insertGetId([
            'warehouse_id' => $warehouse->id,
            'code' => 'Calle 14',
            'name' => 'Duplicada historica',
            'active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->post(route('stock.relocations.store'), [
                'client_id' => $client->id,
                'item_id' => $item->id,
                'stock_pallet_id' => $stockPallet->id,
                'destination_location_id' => $duplicateDestinationId,
            ])
            ->assertSessionHasErrors('destination_location_id');
    }

    public function test_stock_navigation_exposes_relocation_for_internal_roles_only(): void
    {
        [$client] = $this->stockFixture();

        $this->actingAs($this->makeUserWithRole(Role::ALMACEN))
            ->get(route('stock.index', ['client_id' => $client->id]))
            ->assertOk()
            ->assertSee(route('stock.relocations.create', ['client_id' => $client->id]), false)
            ->assertSee('Reubicar');

        $this->actingAs($this->makeUserWithRole(Role::CLIENTE, $client))
            ->get(route('stock.index'))
            ->assertOk()
            ->assertDontSee(route('stock.relocations.create'), false)
            ->assertDontSee('Reubicar');
    }

    /** @param array<string, mixed> $stockOverrides */
    private function stockFixture(array $stockOverrides = []): array
    {
        $this->seed(RoleSeeder::class);

        $client = Client::factory()->create(['active' => true]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'SKU-REUBICAR-'.fake()->unique()->numerify('###'),
            'description' => 'Articulo para reubicar',
            'units_per_pallet' => 100,
        ]);
        $source = $this->locationForClient($client, 'SRC-'.fake()->unique()->numerify('##'));
        $destination = $this->locationForClient($client, 'DST-'.fake()->unique()->numerify('##'));
        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $source->id,
            'lot' => 'LOT-BASE',
            'quantity_units' => 1200,
            'units_per_pallet' => 100,
            'full_pallets' => 12,
            'peaks_count' => 0,
            'warehouse_pallets' => 12,
            'peak_1' => 0,
            'stock_category' => StockPallet::CATEGORY_IN_USE,
            'status' => StockPallet::STATUS_AVAILABLE,
            'active' => true,
            ...$stockOverrides,
        ]);

        return [$client, $item, $stockPallet, $source, $destination];
    }

    private function locationForClient(Client $client, string $code, bool $active = true): Location
    {
        $warehouse = Warehouse::factory()->create([
            'client_id' => $client->id,
            'code' => 'WH-'.$code,
            'active' => true,
        ]);

        return Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => $code,
            'active' => $active,
        ]);
    }

    private function makeUserWithRole(string $roleSlug, ?Client $client = null): User
    {
        $this->seed(RoleSeeder::class);

        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $client?->id,
        ]);
    }
}
