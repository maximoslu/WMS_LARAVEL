<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Role;
use App\Models\StockImport;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class AuditManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_superadmin_and_administracion_can_access_audit_module(): void
    {
        $this->seed(RoleSeeder::class);

        foreach ([Role::SUPERADMIN, Role::ADMINISTRACION] as $roleSlug) {
            $user = $this->makeUserWithRole($roleSlug);

            $this->actingAs($user)
                ->get(route('audit.index'))
                ->assertOk()
                ->assertSee('Auditoría y trazabilidad');
        }
    }

    public function test_cliente_cannot_access_audit_module(): void
    {
        $this->seed(RoleSeeder::class);

        $user = $this->makeUserWithRole(Role::CLIENTE);

        $this->actingAs($user)
            ->get(route('audit.index'))
            ->assertForbidden();
    }

    public function test_administracion_can_preview_cleanup_but_cannot_execute_it(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->makeUserWithRole(Role::ADMINISTRACION);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $admin->id,
            'data' => json_encode(['title' => 'Notificacion antigua']),
            'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-01 10:00:00',
        ]);

        $this->actingAs($admin)
            ->post(route('audit.cleanup.preview'), [
                'cleanup_type' => 'notifications',
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-02',
            ])
            ->assertRedirect(route('audit.index'));

        $this->actingAs($admin)
            ->post(route('audit.cleanup.execute'), [
                'cleanup_type' => 'notifications',
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-02',
                'confirmation_text' => 'CONFIRMAR LIMPIEZA',
            ])
            ->assertForbidden();
    }

    public function test_superadmin_cleanup_requires_confirmation_and_does_not_delete_without_it(): void
    {
        $this->seed(RoleSeeder::class);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);

        DB::table('notifications')->insert([
            'id' => (string) Str::uuid(),
            'type' => 'test',
            'notifiable_type' => User::class,
            'notifiable_id' => $superadmin->id,
            'data' => json_encode(['title' => 'Notificacion antigua']),
            'created_at' => '2026-06-01 10:00:00',
            'updated_at' => '2026-06-01 10:00:00',
        ]);

        $this->actingAs($superadmin)
            ->post(route('audit.cleanup.execute'), [
                'cleanup_type' => 'notifications',
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-02',
                'confirmation_text' => 'MAL',
            ])
            ->assertSessionHasErrors('confirmation_text');

        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_superadmin_can_execute_safe_stock_import_cleanup(): void
    {
        $this->seed(RoleSeeder::class);
        $superadmin = $this->makeUserWithRole(Role::SUPERADMIN);
        $client = Client::factory()->create();

        DB::table('stock_imports')->insert([
            'client_id' => $client->id,
            'uploaded_by' => $superadmin->id,
            'original_filename' => 'old.xlsx',
            'stored_path' => 'stock-imports/old.xlsx',
            'status' => StockImport::STATUS_FAILED,
            'total_rows' => 1,
            'imported_rows' => 0,
            'skipped_rows' => 1,
            'available_rows' => 0,
            'blocked_rows' => 0,
            'detected_sheets_json' => json_encode([]),
            'summary_json' => json_encode([]),
            'warnings_json' => json_encode([]),
            'errors_json' => json_encode(['error']),
            'imported_at' => null,
            'created_at' => '2026-06-01 08:00:00',
            'updated_at' => '2026-06-01 08:00:00',
        ]);

        $this->actingAs($superadmin)
            ->post(route('audit.cleanup.execute'), [
                'cleanup_type' => 'stock_imports',
                'date_from' => '2026-06-01',
                'date_to' => '2026-06-02',
                'client_id' => $client->id,
                'confirmation_text' => 'CONFIRMAR LIMPIEZA',
            ])
            ->assertRedirect(route('audit.index'));

        $this->assertDatabaseCount('stock_imports', 0);
    }

    private function makeUserWithRole(string $roleSlug): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
        ]);
    }
}
