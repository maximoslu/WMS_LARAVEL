<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockPallet;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ClearStockLocationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_clear_stock_locations_dry_run_does_not_modify_stock(): void
    {
        [$client, $stockPallet] = $this->stockWithLocation();

        $this->artisan('wms:stock:clear-locations', [
            '--client' => $client->code,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN wms:stock:clear-locations')
            ->expectsOutputToContain('Partidas afectadas: 1')
            ->expectsOutputToContain('No se han modificado datos.')
            ->assertSuccessful();

        $stockPallet->refresh();

        $this->assertNotNull($stockPallet->location_id);
        $this->assertSame('18', $stockPallet->location_text);
        $this->assertSame(7500, $stockPallet->quantity_units);
        $this->assertSame(7500, $stockPallet->units_per_pallet);
        $this->assertSame(1, $stockPallet->full_pallets);
    }

    public function test_clear_stock_locations_apply_only_clears_location_fields(): void
    {
        [$client, $stockPallet] = $this->stockWithLocation();

        $this->artisan('wms:stock:clear-locations', [
            '--client' => $client->code,
            '--warehouse' => '38',
            '--apply' => true,
        ])
            ->expectsOutputToContain('APPLY wms:stock:clear-locations')
            ->expectsOutputToContain('Partidas afectadas: 1')
            ->expectsOutputToContain('Ubicaciones de stock limpiadas correctamente.')
            ->assertSuccessful();

        $stockPallet->refresh();

        $this->assertNull($stockPallet->location_id);
        $this->assertNull($stockPallet->location_text);
        $this->assertSame(7500, $stockPallet->quantity_units);
        $this->assertSame(7500, $stockPallet->units_per_pallet);
        $this->assertSame(1, $stockPallet->full_pallets);
        $this->assertSame(0, $stockPallet->peaks_count);
    }

    public function test_clear_stock_locations_rejects_apply_and_dry_run_together(): void
    {
        $this->artisan('wms:stock:clear-locations', [
            '--dry-run' => true,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Usa --dry-run o --apply, no ambos.')
            ->assertFailed();
    }

    /** @return array{Client, StockPallet} */
    private function stockWithLocation(): array
    {
        $client = Client::factory()->create([
            'code' => 'EDELVIVES',
            'name' => 'EDELVIVES',
        ]);
        $warehouse = Warehouse::factory()->create([
            'client_id' => null,
            'code' => '38',
            'name' => 'NAVE 38',
            'active' => true,
        ]);
        $location = Location::factory()->create([
            'warehouse_id' => $warehouse->id,
            'code' => '18',
            'active' => true,
        ]);
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => 'ED-LOC',
            'units_per_pallet' => 7500,
        ]);
        $stockPallet = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'location_id' => $location->id,
            'location_text' => '18',
            'quantity_units' => 7500,
            'units_per_pallet' => 7500,
            'full_pallets' => 1,
            'peaks_count' => 0,
            'peak_1' => 0,
        ]);

        return [$client, $stockPallet];
    }
}
