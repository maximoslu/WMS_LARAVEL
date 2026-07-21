<?php

namespace Tests\Feature;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CreateLocationRangeCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_create_location_range_dry_run_does_not_modify_catalog(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => '38', 'name' => 'NAVE 38']);

        $this->artisan('wms:locations:create-range', [
            '--warehouse' => '38',
            '--type' => 'Calle',
            '--from' => 0,
            '--to' => 20,
            '--dry-run' => true,
        ])
            ->expectsOutputToContain('DRY-RUN wms:locations:create-range')
            ->expectsOutputToContain('Creadas: 21')
            ->assertSuccessful();

        $this->assertSame(0, Location::query()->where('warehouse_id', $warehouse->id)->count());
    }

    public function test_create_location_range_apply_creates_missing_locations_without_duplicates(): void
    {
        $warehouse = Warehouse::factory()->create(['code' => '38', 'name' => 'NAVE 38']);
        Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => '5']);

        $this->artisan('wms:locations:create-range', [
            '--warehouse' => '38',
            '--type' => 'Calle',
            '--from' => 0,
            '--to' => 10,
            '--apply' => true,
        ])
            ->expectsOutputToContain('APPLY wms:locations:create-range')
            ->expectsOutputToContain('Creadas: 10')
            ->expectsOutputToContain('Ya existentes: 1')
            ->assertSuccessful();

        $this->assertSame(11, Location::query()->where('warehouse_id', $warehouse->id)->count());
        $this->assertSame(1, Location::query()->where('warehouse_id', $warehouse->id)->where('code', '5')->count());
    }

    public function test_create_location_range_apply_requires_confirmation_for_large_ranges(): void
    {
        Warehouse::factory()->create(['code' => '38', 'name' => 'NAVE 38']);

        $this->artisan('wms:locations:create-range', [
            '--warehouse' => '38',
            '--type' => 'Calle',
            '--from' => 0,
            '--to' => 1001,
            '--apply' => true,
        ])
            ->expectsOutputToContain('Para aplicar rangos de mas de 1000 ubicaciones')
            ->assertFailed();

        $this->assertSame(0, Location::query()->count());
    }
}
