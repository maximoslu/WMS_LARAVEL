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
        $this->get(route('deca.quick'))->assertRedirect(route('login'));
    }

    public function test_operational_roles_can_access_the_initial_section(): void
    {
        $this->seed(RoleSeeder::class);

        foreach ([Role::ALMACEN, Role::ADMINISTRACION, Role::SUPERADMIN] as $slug) {
            $user = User::factory()->create(['role_id' => Role::where('slug', $slug)->firstOrFail()->id]);

            $this->actingAs($user)->get('/deca')
                ->assertOk()
                ->assertSee('Transportes Monge')
                ->assertSee('Crear DECA manual')
                ->assertSee('Mis documentos')
                ->assertSee(route('deca.quick'), false)
                ->assertSee(route('deca.create'), false);

            $this->get(route('deca.quick'))
                ->assertOk()
                ->assertSee('Edelvives → Supply Chain')->assertSee('INSOCA → Pastas Romero')
                ->assertSee(route('deca.create'), false)
                ->assertSee(route('deca.index'), false);

            $sections = WmsNavigation::sectionsForUser($user);
            $decaSection = collect($sections)->firstWhere('key', 'deca');
            $this->assertContains('deca-quick', array_column($decaSection['children'], 'key'));
            $this->assertContains('deca', array_column(WmsNavigation::sectionsForUser($user), 'key'));
        }
    }

    public function test_clients_cannot_access_or_see_the_deca_section(): void
    {
        $this->seed(RoleSeeder::class);
        $user = User::factory()->create(['role_id' => Role::where('slug', Role::CLIENTE)->firstOrFail()->id]);

        $this->actingAs($user)->get('/deca')->assertForbidden();
        $this->get(route('deca.quick'))->assertForbidden();
        $this->assertNotContains('deca', array_column(WmsNavigation::sectionsForUser($user), 'key'));
    }
}
