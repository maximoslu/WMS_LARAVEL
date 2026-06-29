<?php

namespace Tests\Feature;

use App\Models\DailyOperationDay;
use App\Models\DailyOperationLine;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyOperationsTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_administracion_and_almacen_can_access_daily_operations(): void
    {
        $this->seed(RoleSeeder::class);

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION, Role::ALMACEN] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('daily-operations.index'))
                ->assertOk()
                ->assertSee('Operaciones diarias');
        }
    }

    public function test_cliente_cannot_access_daily_operations(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('daily-operations.index'))
            ->assertForbidden();
    }

    public function test_can_create_day_summary_and_operation_line(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->post(route('daily-operations.day.upsert'), [
                'operation_date' => '2026-06-29',
                'opening_pallets' => 100,
                'stored_pallets_today' => 40,
                'moved_pallets_today' => 25,
                'expected_pallets_tomorrow' => 18,
                'notes' => 'Cierre operativo del dia.',
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-06-29']));

        $day = DailyOperationDay::query()->whereDate('operation_date', '2026-06-29')->firstOrFail();

        $this->actingAs($user)
            ->post(route('daily-operations.lines.store'), [
                'operation_date' => '2026-06-29',
                'section' => DailyOperationLine::SECTION_DESCARGA,
                'counterparty_name' => 'Transporte Norte',
                'pallets' => 12,
                'observations' => 'Recepcion de proveedor.',
            ])
            ->assertRedirect(route('daily-operations.index', ['date' => '2026-06-29']));

        $this->assertDatabaseHas('daily_operation_days', [
            'id' => $day->id,
            'opening_pallets' => 100,
            'stored_pallets_today' => 40,
            'moved_pallets_today' => 25,
            'expected_pallets_tomorrow' => 18,
        ]);

        $this->assertDatabaseHas('daily_operation_lines', [
            'day_id' => $day->id,
            'section' => DailyOperationLine::SECTION_DESCARGA,
            'counterparty_name' => 'Transporte Norte',
            'pallets' => 12,
        ]);
    }

    public function test_daily_operations_can_filter_by_selected_date_and_show_totals(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);

        $day = DailyOperationDay::query()->create([
            'operation_date' => '2026-06-30',
            'opening_pallets' => 50,
            'stored_pallets_today' => 20,
            'moved_pallets_today' => 10,
            'expected_pallets_tomorrow' => 12,
            'created_by' => $user->id,
            'updated_by' => $user->id,
        ]);

        $day->lines()->create([
            'section' => DailyOperationLine::SECTION_CARGA,
            'counterparty_name' => 'Cliente Sur',
            'pallets' => 7,
            'observations' => 'Carga de expedicion.',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('daily-operations.index', ['date' => '2026-06-30']))
            ->assertOk()
            ->assertSee('Cliente Sur')
            ->assertSee('7')
            ->assertSee('Pallets iniciales')
            ->assertSee('50');
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }
}
