<?php

namespace Tests\Feature;

use App\Models\BackupExport;
use App\Models\Client;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockPallet;
use App\Models\User;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class BackupModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RoleSeeder::class);
    }

    public function test_superadmin_can_view_backups_module(): void
    {
        $this->actingAs($this->user(Role::SUPERADMIN))
            ->get(route('backups.index'))
            ->assertOk()
            ->assertSee('Backups')
            ->assertSee('Crear copia manual')
            ->assertSee('Sistema completo')
            ->assertSee('Stock por cliente')
            ->assertSee('Los backups no incluyen .env ni secretos.');
    }

    public function test_administracion_cannot_view_backups_module(): void
    {
        $this->actingAs($this->user(Role::ADMINISTRACION))
            ->get(route('backups.index'))
            ->assertForbidden();
    }

    public function test_almacen_cannot_view_backups_module(): void
    {
        $this->actingAs($this->user(Role::ALMACEN))
            ->get(route('backups.index'))
            ->assertForbidden();
    }

    public function test_cliente_cannot_view_backups_module(): void
    {
        $this->actingAs($this->user(Role::CLIENTE, Client::factory()->create()))
            ->get(route('backups.index'))
            ->assertForbidden();
    }

    public function test_superadmin_can_request_manual_stock_backup(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES']);
        $this->stock($client, 'SKU-BACKUP', 300);

        $this->actingAs($this->user(Role::SUPERADMIN))
            ->post(route('backups.store'), ['type' => BackupExport::TYPE_STOCK])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('status');

        $backup = BackupExport::query()->latest()->firstOrFail();

        $this->assertSame(BackupExport::STATUS_COMPLETED, $backup->status);
        $this->assertSame(BackupExport::TYPE_STOCK, $backup->type);
        $this->assertStringStartsWith('backups/', (string) $backup->path);
        $this->assertStringNotContainsString('public', (string) $backup->path);
        $this->assertNotNull($backup->size_bytes);
        $this->assertNotNull($backup->checksum);
        Storage::disk('local')->assertExists((string) $backup->path);
    }

    public function test_non_superadmin_cannot_request_manual_backup(): void
    {
        $this->actingAs($this->user(Role::ADMINISTRACION))
            ->post(route('backups.store'), ['type' => BackupExport::TYPE_STOCK])
            ->assertForbidden();

        $this->assertDatabaseCount('backup_exports', 0);
    }

    public function test_stock_client_backup_requires_client(): void
    {
        $this->actingAs($this->user(Role::SUPERADMIN))
            ->post(route('backups.store'), ['type' => BackupExport::TYPE_STOCK_CLIENT])
            ->assertSessionHasErrors('client_id');
    }

    public function test_stock_client_backup_contains_only_selected_client(): void
    {
        $edelvives = Client::factory()->create(['code' => 'EDELVIVES', 'name' => 'EDELVIVES']);
        $friesland = Client::factory()->create(['code' => 'FRIESLAND', 'name' => 'FRIESLAND']);
        $this->stock($edelvives, 'SKU-EDEL', 150);
        $this->stock($friesland, 'SKU-FRIES', 250);

        $this->actingAs($this->user(Role::SUPERADMIN))
            ->post(route('backups.store'), [
                'type' => BackupExport::TYPE_STOCK_CLIENT,
                'client_id' => $edelvives->id,
            ])
            ->assertRedirect(route('backups.index'));

        $backup = BackupExport::query()->where('type', BackupExport::TYPE_STOCK_CLIENT)->firstOrFail();
        $content = gzdecode(file_get_contents(Storage::disk('local')->path((string) $backup->path)));

        $this->assertStringContainsString('SKU-EDEL', $content);
        $this->assertStringNotContainsString('SKU-FRIES', $content);
    }

    public function test_backup_file_does_not_include_env_literal(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES']);
        $this->stock($client, 'SKU-SAFE', 100);

        $this->actingAs($this->user(Role::SUPERADMIN))
            ->post(route('backups.store'), ['type' => BackupExport::TYPE_STOCK])
            ->assertRedirect(route('backups.index'));

        $backup = BackupExport::query()->latest()->firstOrFail();
        $content = gzdecode(file_get_contents(Storage::disk('local')->path((string) $backup->path)));

        $this->assertStringNotContainsString('.env', $content);
        $this->assertFalse(str_contains((string) $backup->path, 'public/'));
    }

    public function test_superadmin_can_download_completed_backup(): void
    {
        $backup = BackupExport::query()->create([
            'type' => BackupExport::TYPE_STOCK,
            'scope' => 'stock',
            'status' => BackupExport::STATUS_COMPLETED,
            'disk' => 'local',
            'path' => 'backups/manual/test.csv.gz',
            'filename' => 'test.csv.gz',
            'mime_type' => 'application/gzip',
        ]);
        Storage::disk('local')->put('backups/manual/test.csv.gz', gzencode('ok', 9));

        $this->actingAs($this->user(Role::SUPERADMIN))
            ->get(route('backups.download', $backup))
            ->assertOk()
            ->assertDownload('test.csv.gz');
    }

    public function test_non_superadmin_cannot_download_backup(): void
    {
        $backup = BackupExport::query()->create([
            'type' => BackupExport::TYPE_STOCK,
            'status' => BackupExport::STATUS_COMPLETED,
            'disk' => 'local',
            'path' => 'backups/manual/test.csv.gz',
            'filename' => 'test.csv.gz',
        ]);
        Storage::disk('local')->put('backups/manual/test.csv.gz', gzencode('ok', 9));

        $this->actingAs($this->user(Role::ALMACEN))
            ->get(route('backups.download', $backup))
            ->assertForbidden();
    }

    public function test_completed_backup_with_path_traversal_is_not_downloadable(): void
    {
        $backup = BackupExport::query()->create([
            'type' => BackupExport::TYPE_STOCK,
            'status' => BackupExport::STATUS_COMPLETED,
            'disk' => 'local',
            'path' => 'backups/../.env',
            'filename' => '.env',
        ]);

        $this->actingAs($this->user(Role::SUPERADMIN))
            ->get(route('backups.download', $backup))
            ->assertNotFound();
    }

    public function test_missing_backup_download_returns_404(): void
    {
        $this->actingAs($this->user(Role::SUPERADMIN))
            ->get('/backups/999999/download')
            ->assertNotFound();
    }

    public function test_snapshot_command_generates_one_file_per_active_client(): void
    {
        $edelvives = Client::factory()->create(['code' => 'EDELVIVES', 'active' => true]);
        $friesland = Client::factory()->create(['code' => 'FRIESLAND', 'active' => true]);
        Client::factory()->inactive()->create(['code' => 'INACTIVO']);
        $this->stock($edelvives, 'SKU-EDEL', 100);
        $this->stock($friesland, 'SKU-FRIES', 100);

        $this->artisan('wms:backups:stock-snapshots --date=2026-07-21')
            ->assertExitCode(0);

        $this->assertDatabaseCount('backup_exports', 2);
        $this->assertDatabaseHas('backup_exports', [
            'type' => BackupExport::TYPE_STOCK_SNAPSHOT_DAILY,
            'client_id' => $edelvives->id,
            'status' => BackupExport::STATUS_COMPLETED,
        ]);
        $this->assertDatabaseHas('backup_exports', [
            'type' => BackupExport::TYPE_STOCK_SNAPSHOT_DAILY,
            'client_id' => $friesland->id,
            'status' => BackupExport::STATUS_COMPLETED,
        ]);
    }

    public function test_snapshot_does_not_duplicate_same_day_without_force(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES']);
        $this->stock($client, 'SKU-EDEL', 100);

        $this->artisan('wms:backups:stock-snapshots --date=2026-07-21')->assertExitCode(0);
        $this->artisan('wms:backups:stock-snapshots --date=2026-07-21')->assertExitCode(0);

        $this->assertDatabaseCount('backup_exports', 1);
    }

    public function test_snapshot_force_regenerates_existing_file(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES']);
        $this->stock($client, 'SKU-EDEL', 100);

        $this->artisan('wms:backups:stock-snapshots --date=2026-07-21')->assertExitCode(0);
        $first = BackupExport::query()->firstOrFail();
        Storage::disk('local')->assertExists((string) $first->path);

        $this->artisan('wms:backups:stock-snapshots --date=2026-07-21 --force')->assertExitCode(0);

        $this->assertDatabaseCount('backup_exports', 1);
        Storage::disk('local')->assertExists((string) BackupExport::query()->firstOrFail()->path);
    }

    public function test_snapshot_contains_minimum_fields_and_expected_quantities(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES']);
        $this->stock($client, 'SKU-QTY', 725);

        $this->artisan('wms:backups:stock-snapshots --client=EDELVIVES --date=2026-07-21')
            ->assertExitCode(0);

        $backup = BackupExport::query()->firstOrFail();
        $content = gzdecode(file_get_contents(Storage::disk('local')->path((string) $backup->path)));

        $this->assertStringContainsString('snapshot_date;client_id;client_code;client_name;item_id;sku;description;lot;stock_status;stock_category;quantity', $content);
        $this->assertStringContainsString('SKU-QTY', $content);
        $this->assertStringContainsString('725', $content);
    }

    public function test_stock_snapshot_by_client_does_not_mix_clients(): void
    {
        $edelvives = Client::factory()->create(['code' => 'EDELVIVES']);
        $friesland = Client::factory()->create(['code' => 'FRIESLAND']);
        $this->stock($edelvives, 'SKU-EDEL', 100);
        $this->stock($friesland, 'SKU-FRIES', 100);

        $this->artisan('wms:backups:stock-snapshots --client=EDELVIVES --date=2026-07-21')
            ->assertExitCode(0);

        $backup = BackupExport::query()->firstOrFail();
        $content = gzdecode(file_get_contents(Storage::disk('local')->path((string) $backup->path)));

        $this->assertStringContainsString('SKU-EDEL', $content);
        $this->assertStringNotContainsString('SKU-FRIES', $content);
    }

    public function test_prune_dry_run_does_not_delete_old_snapshot(): void
    {
        $backup = $this->oldSnapshot();
        Storage::disk('local')->put((string) $backup->path, 'old');

        $this->artisan('wms:backups:prune --days=365 --type=stock_snapshot_daily --dry-run')
            ->assertExitCode(0);

        $this->assertDatabaseHas('backup_exports', ['id' => $backup->id]);
        Storage::disk('local')->assertExists((string) $backup->path);
    }

    public function test_prune_apply_deletes_only_old_snapshot_in_test(): void
    {
        $old = $this->oldSnapshot();
        $recent = BackupExport::query()->create([
            'type' => BackupExport::TYPE_STOCK_SNAPSHOT_DAILY,
            'status' => BackupExport::STATUS_COMPLETED,
            'disk' => 'local',
            'path' => 'backups/stock-snapshots/EDELVIVES/2026/07/recent.csv.gz',
            'filename' => 'recent.csv.gz',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        Storage::disk('local')->put((string) $old->path, 'old');
        Storage::disk('local')->put((string) $recent->path, 'recent');

        $this->artisan('wms:backups:prune --days=365 --type=stock_snapshot_daily --apply')
            ->assertExitCode(0);

        $this->assertDatabaseMissing('backup_exports', ['id' => $old->id]);
        $this->assertDatabaseHas('backup_exports', ['id' => $recent->id]);
        Storage::disk('local')->assertMissing((string) $old->path);
        Storage::disk('local')->assertExists((string) $recent->path);
    }

    public function test_cli_create_stock_backup_works(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES']);
        $this->stock($client, 'SKU-CLI', 100);

        $this->artisan('wms:backups:create --type=stock')
            ->assertExitCode(0);

        $this->assertDatabaseHas('backup_exports', [
            'type' => BackupExport::TYPE_STOCK,
            'status' => BackupExport::STATUS_COMPLETED,
        ]);
    }

    public function test_cli_create_stock_client_backup_works(): void
    {
        $client = Client::factory()->create(['code' => 'EDELVIVES']);
        $this->stock($client, 'SKU-CLI-CLIENT', 100);

        $this->artisan('wms:backups:create --type=stock-client --client=EDELVIVES')
            ->assertExitCode(0);

        $this->assertDatabaseHas('backup_exports', [
            'type' => BackupExport::TYPE_STOCK_CLIENT,
            'client_id' => $client->id,
            'status' => BackupExport::STATUS_COMPLETED,
        ]);
    }

    public function test_cli_invalid_type_fails(): void
    {
        $this->artisan('wms:backups:create --type=bad')
            ->assertExitCode(1);
    }

    public function test_cli_missing_client_fails(): void
    {
        $this->artisan('wms:backups:create --type=stock-client --client=NOPE')
            ->assertExitCode(1);
    }

    public function test_failed_generation_records_failed_status(): void
    {
        config(['wms.backups.disk' => 'missing-disk']);

        $this->actingAs($this->user(Role::SUPERADMIN))
            ->post(route('backups.store'), ['type' => BackupExport::TYPE_STOCK])
            ->assertRedirect(route('backups.index'))
            ->assertSessionHas('warning');

        $this->assertDatabaseHas('backup_exports', [
            'type' => BackupExport::TYPE_STOCK,
            'status' => BackupExport::STATUS_FAILED,
        ]);
    }

    private function user(string $roleSlug, ?Client $client = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create([
            'role_id' => $role->id,
            'client_id' => $roleSlug === Role::CLIENTE ? $client?->id : null,
        ]);
    }

    private function stock(Client $client, string $sku, int $quantity): StockPallet
    {
        $item = Item::factory()->create([
            'client_id' => $client->id,
            'sku' => $sku,
            'description' => 'Articulo '.$sku,
            'units_per_pallet' => 100,
        ]);

        return StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => $quantity,
            'units_per_pallet' => 100,
        ]);
    }

    private function oldSnapshot(): BackupExport
    {
        $backup = BackupExport::query()->create([
            'type' => BackupExport::TYPE_STOCK_SNAPSHOT_DAILY,
            'status' => BackupExport::STATUS_COMPLETED,
            'disk' => 'local',
            'path' => 'backups/stock-snapshots/EDELVIVES/2025/01/old.csv.gz',
            'filename' => 'old.csv.gz',
        ]);

        $backup->forceFill([
            'created_at' => now()->subYears(3),
            'updated_at' => now()->subYears(3),
        ])->saveQuietly();

        return $backup;
    }
}
