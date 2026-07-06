<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SupplierManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_almacen_puede_ver_proveedores(): void
    {
        $this->seedBaseData();

        Supplier::factory()->create([
            'name' => 'PROVEEDOR ALMACEN',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee('Proveedores')
            ->assertSee('PROVEEDOR ALMACEN');
    }

    public function test_almacen_puede_crear_proveedor(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ALMACEN);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();

        $this->actingAs($user)
            ->post(route('suppliers.store'), [
                'client_id' => $client->id,
                'name' => ' Proveedor nuevo ',
                'tax_id' => ' b12345678 ',
                'email' => ' COMPRAS@PROVEEDOR.ES ',
                'phone' => ' 600123123 ',
                'contact_name' => ' Lucia Perez ',
                'notes' => ' Alta urgente para recepcion ',
                'active' => '1',
            ])
            ->assertRedirect(route('suppliers.index'));

        $this->assertDatabaseHas('suppliers', [
            'client_id' => $client->id,
            'name' => 'Proveedor nuevo',
            'tax_id' => 'B12345678',
            'email' => 'compras@proveedor.es',
            'phone' => '600123123',
            'contact_name' => 'Lucia Perez',
            'notes' => 'Alta urgente para recepcion',
            'active' => true,
        ]);
    }

    public function test_almacen_ve_boton_nuevo_proveedor_y_enlace_editar(): void
    {
        $this->seedBaseData();

        $supplier = Supplier::factory()->create([
            'name' => 'PROVEEDOR CTA',
        ]);

        $user = $this->makeUserWithRole(Role::ALMACEN);

        $this->actingAs($user)
            ->get(route('suppliers.index'))
            ->assertOk()
            ->assertSee('Nuevo proveedor')
            ->assertSee(route('suppliers.create'), false)
            ->assertSee(route('suppliers.edit', $supplier), false)
            ->assertDontSee(route('suppliers.toggle-active', $supplier), false);
    }

    public function test_administracion_sigue_pudiendo_gestionar_proveedores(): void
    {
        $this->seedBaseData();

        $supplier = Supplier::factory()->create();
        $user = $this->makeUserWithRole(Role::ADMINISTRACION);

        $this->actingAs($user)
            ->get(route('suppliers.create'))
            ->assertOk();

        $this->actingAs($user)
            ->get(route('suppliers.edit', $supplier))
            ->assertOk();

        $this->actingAs($user)
            ->patch(route('suppliers.toggle-active', $supplier))
            ->assertRedirect(route('suppliers.index'));
    }

    private function seedBaseData(): void
    {
        $this->seed([
            RoleSeeder::class,
            ClientSeeder::class,
        ]);
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }
}
