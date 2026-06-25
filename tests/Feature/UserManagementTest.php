<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\User;
use Database\Seeders\ClientSeeder;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_can_open_users_index(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::SUPERADMIN);

        $this->actingAs($user)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Usuarios y roles');
    }

    public function test_superadmin_can_edit_role_and_client_of_another_user(): void
    {
        $this->seedBaseData();

        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $managedUser = $this->makeUserWithRole(Role::CLIENTE);
        $adminRole = Role::query()->where('slug', Role::ADMINISTRACION)->firstOrFail();
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();

        $this->actingAs($superadmin)
            ->put(route('users.update', $managedUser), [
                'name' => 'Usuario Gestionado',
                'email' => 'gestionado@example.com',
                'role_id' => $adminRole->id,
                'client_id' => $client->id,
                'active' => '1',
            ])
            ->assertRedirect(route('users.index'));

        $this->assertDatabaseHas('users', [
            'id' => $managedUser->id,
            'name' => 'Usuario Gestionado',
            'email' => 'gestionado@example.com',
            'role_id' => $adminRole->id,
            'client_id' => $client->id,
            'active' => true,
        ]);
    }

    public function test_almacen_and_cliente_cannot_open_users_index(): void
    {
        $this->seedBaseData();

        foreach ([Role::ALMACEN, Role::CLIENTE] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('users.index'))
                ->assertForbidden();
        }
    }

    public function test_user_without_admin_role_cannot_edit_other_user(): void
    {
        $this->seedBaseData();

        $user = $this->makeUserWithRole(Role::ALMACEN);
        $otherUser = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('users.edit', $otherUser))
            ->assertForbidden();
    }

    public function test_users_index_shows_role_and_client(): void
    {
        $this->seedBaseData();

        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $client = Client::query()->where('code', 'FRIESLAND')->firstOrFail();
        $role = Role::query()->where('slug', Role::ALMACEN)->firstOrFail();

        User::factory()->create([
            'name' => 'Usuario Operativo',
            'email' => 'operativo@example.com',
            'role_id' => $role->id,
            'client_id' => $client->id,
        ]);

        $this->actingAs($superadmin)
            ->get(route('users.index'))
            ->assertOk()
            ->assertSee('Usuario Operativo')
            ->assertSee('Almacen')
            ->assertSee('FRIESLAND');
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
