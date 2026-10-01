<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use App\Support\WmsNavigation;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DecaModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_must_log_in(): void
    {
        $this->get('/deca')->assertRedirect(route('login'));
    }

    public function test_operational_roles_can_access_the_initial_section(): void
    {
        $this->seed(RoleSeeder::class);

        foreach ([Role::ALMACEN, Role::ADMINISTRACION, Role::SUPERADMIN] as $slug) {
            $user = User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id]);

            $this->actingAs($user)->get('/deca')
                ->assertOk()
                ->assertSee('Transportes MOJE')
                ->assertSee('Crear DECA manual')
                ->assertSee('Mis documentos')
                ->assertSee('Sección en preparación.')
                ->assertSee('disabled aria-describedby="deca-create-status"', false);

            $this->assertContains('deca', array_column(WmsNavigation::sectionsForUser($user), 'key'));
        }
    }

    public function test_clients_cannot_access_or_see_the_deca_section(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create(['role_id' => Role::where('slug', Role::CLIENTE)->firstOrFail()->id]);

        $this->actingAs($user)->get('/deca')->assertForbidden();
        $this->assertNotContains('deca', array_column(WmsNavigation::sectionsForUser($user), 'key'));
    }
}
