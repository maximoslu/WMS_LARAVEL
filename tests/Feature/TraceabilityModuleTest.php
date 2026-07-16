<?php

namespace Tests\Feature;

use App\Jobs\SendStockAlertEmailJob;
use App\Models\Client;
use App\Models\ClientStockAlertEmailRecipient;
use App\Models\InventoryMovement;
use App\Models\Item;
use App\Models\Location;
use App\Models\Role;
use App\Models\StockAlertEvent;
use App\Models\StockAlertRule;
use App\Models\StockPallet;
use App\Models\User;
use App\Models\UserActivitySession;
use App\Models\Warehouse;
use App\Services\Activity\UserActivityService;
use App\Services\Audit\AuditLogService;
use App\Services\BrevoMailService;
use App\Services\Inventory\InventoryMovementService;
use App\Services\Traceability\ClientInventoryAnalyticsService;
use App\Services\Traceability\LotTraceabilityService;
use App\Services\Traceability\StockAlertEvaluationService;
use App\Services\Traceability\StockForecastService;
use App\Services\Traceability\TraceabilityBackfillService;
use Database\Seeders\RoleSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use LogicException;
use Tests\TestCase;

class TraceabilityModuleTest extends TestCase
{
    use RefreshDatabase;

    public function test_traceability_permissions_match_the_operational_roles(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->user(Role::ADMINISTRACION);
        $warehouse = $this->user(Role::ALMACEN);
        $clientUser = $this->user(Role::CLIENTE, Client::factory()->create());

        foreach (['traceability.index', 'traceability.movements.index', 'traceability.lots.index', 'traceability.alerts.index'] as $route) {
            $this->actingAs($warehouse)->get(route($route))->assertOk();
            $this->actingAs($clientUser)->get(route($route))->assertForbidden();
        }

        foreach (['traceability.activity.index', 'traceability.audit.index', 'traceability.analytics.index', 'traceability.reports.index'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
            $this->actingAs($warehouse)->get(route($route))->assertForbidden();
            $this->actingAs($clientUser)->get(route($route))->assertForbidden();
        }
    }

    public function test_heartbeat_counts_only_visible_bounded_activity(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->user(Role::ALMACEN);
        $request = Request::create('/actividad/heartbeat', 'POST');
        $session = app('session')->driver();
        $session->setId(Str::random(40));
        $session->start();
        $request->setLaravelSession($session);
        $request->setUserResolver(fn () => $user);
        $activity = app(UserActivityService::class);

        $this->assertSame(0, $activity->heartbeat($request, $user, 'stock.index', true)['counted_seconds']);

        $this->travel(60)->seconds();
        $this->assertSame(
            ['counted_seconds' => 60, 'active_seconds' => 60],
            $activity->heartbeat($request, $user, 'stock.index', true),
        );

        $this->travel(60)->seconds();
        $this->assertSame(
            ['counted_seconds' => 0, 'active_seconds' => 60],
            $activity->heartbeat($request, $user, 'stock.index', false),
        );

        $this->assertDatabaseHas('user_section_metrics', [
            'user_id' => $user->id,
            'section' => 'stock',
            'active_seconds' => 60,
        ]);
    }

    public function test_login_and_logout_create_and_close_activity_session(): void
    {
        $this->seed(RoleSeeder::class);
        $user = $this->user(Role::ALMACEN);
        $user->update(['password' => Hash::make('trace-password')]);

        $this->post(route('login.store'), ['email' => $user->email, 'password' => 'trace-password'])
            ->assertRedirect(route('dashboard'));
        $session = UserActivitySession::query()->where('user_id', $user->id)->firstOrFail();
        $this->assertNull($session->ended_at);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'login']);

        $this->post(route('logout'))->assertRedirect(route('login'));
        $this->assertNotNull($session->fresh()->ended_at);
        $this->assertDatabaseHas('audit_logs', ['user_id' => $user->id, 'event' => 'logout']);
    }

    public function test_inventory_movement_is_idempotent_and_immutable(): void
    {
        Queue::fake();
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id]);
        $stock = StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => 100,
            'full_pallets' => 1,
            'warehouse_pallets' => 1,
        ]);
        $service = app(InventoryMovementService::class);
        $before = $service->snapshot(null);
        $after = $service->snapshot($stock);

        $first = $service->record($before, $after, InventoryMovement::OPENING_BALANCE, 'test:opening:'.$stock->id, (string) Str::uuid());
        $second = $service->record($before, $after, InventoryMovement::OPENING_BALANCE, 'test:opening:'.$stock->id, (string) Str::uuid());

        $this->assertTrue($first->is($second));
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->expectException(LogicException::class);
        $first->update(['units_delta' => 999]);
    }

    public function test_audit_log_removes_sensitive_values_and_is_immutable(): void
    {
        $client = Client::factory()->create();
        $log = app(AuditLogService::class)->record(
            event: 'sensitive_test',
            module: 'tests',
            description: 'Prueba de saneamiento.',
            auditable: $client,
            clientId: $client->id,
            newValues: ['name' => 'Visible', 'password' => 'never-store', 'nested' => ['api_token' => 'never-store', 'safe' => 'yes']],
        );

        $this->assertSame(['name' => 'Visible', 'nested' => ['safe' => 'yes']], $log->new_values);
        $this->expectException(LogicException::class);
        $log->delete();
    }

    public function test_alert_rule_rejects_an_item_from_another_client(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->user(Role::ADMINISTRACION);
        $client = Client::factory()->create();
        $foreignItem = Item::factory()->create(['client_id' => Client::factory()->create()->id]);

        $this->actingAs($admin)->post(route('traceability.alerts.rules.store'), [
            'client_id' => $client->id,
            'item_id' => $foreignItem->id,
            'minimum_units' => 10,
            'severity' => 'warning',
            'cooldown_minutes' => 60,
            'active' => 1,
        ])->assertStatus(422);

        $this->assertDatabaseCount('stock_alert_rules', 0);
    }

    public function test_alert_evaluation_dry_run_is_read_only_and_apply_does_not_repeat_email(): void
    {
        Queue::fake();
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id]);
        ClientStockAlertEmailRecipient::query()->create(['client_id' => $client->id, 'email' => 'stock@example.test', 'active' => true]);
        StockAlertRule::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'active' => true,
            'minimum_units' => 100,
            'severity' => 'warning',
            'cooldown_minutes' => 1440,
        ]);
        $service = app(StockAlertEvaluationService::class);

        $dryRun = $service->evaluate($client->id, $item->id, false);
        $this->assertSame(1, $dryRun['triggered']);
        $this->assertDatabaseCount('stock_alert_events', 0);

        $first = $service->evaluate($client->id, $item->id, true);
        $second = $service->evaluate($client->id, $item->id, true);
        $this->assertSame(1, $first['triggered']);
        $this->assertSame(1, $second['unchanged']);
        $this->assertDatabaseCount('stock_alert_events', 1);
        Queue::assertPushed(SendStockAlertEmailJob::class, 1);
    }

    public function test_resolved_stock_alert_is_not_emailed_by_a_stale_queued_job(): void
    {
        Http::fake();
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id]);
        $rule = StockAlertRule::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'active' => true,
            'minimum_units' => 100,
            'severity' => 'warning',
            'cooldown_minutes' => 60,
        ]);
        $event = StockAlertEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'stock_alert_rule_id' => $rule->id,
            'client_id' => $client->id,
            'item_id' => $item->id,
            'severity' => StockAlertEvent::STATUS_WARNING,
            'status' => StockAlertEvent::STATUS_RESOLVED,
            'reason' => 'Alerta ya resuelta.',
            'recipients' => ['stock@example.test'],
            'notification_status' => 'queued',
            'triggered_at' => now()->subMinute(),
            'resolved_at' => now(),
        ]);

        (new SendStockAlertEmailJob($event->id))->handle(
            app(BrevoMailService::class),
            app(AuditLogService::class),
        );

        Http::assertNothingSent();
        $this->assertSame('queued', $event->fresh()->notification_status);
    }

    public function test_stock_alert_email_marks_event_and_records_delivery_audit(): void
    {
        config([
            'services.brevo.key' => 'test-brevo-key',
            'services.brevo.base_url' => 'https://api.brevo.com/v3',
            'mail.from.address' => 'sistema@example.test',
            'mail.from.name' => 'MAXIMO WMS',
        ]);
        Http::fake(['https://api.brevo.com/*' => Http::response(['messageId' => 'stock-alert-1'], 201)]);
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id, 'sku' => 'ALERT-001']);
        $rule = StockAlertRule::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'active' => true,
            'minimum_units' => 100,
            'severity' => 'warning',
            'cooldown_minutes' => 60,
        ]);
        $event = StockAlertEvent::query()->create([
            'uuid' => (string) Str::uuid(),
            'stock_alert_rule_id' => $rule->id,
            'client_id' => $client->id,
            'item_id' => $item->id,
            'severity' => StockAlertEvent::STATUS_WARNING,
            'status' => StockAlertEvent::STATUS_WARNING,
            'reason' => 'Stock bajo.',
            'recipients' => ['stock@example.test'],
            'notification_status' => 'queued',
            'triggered_at' => now(),
        ]);

        (new SendStockAlertEmailJob($event->id))->handle(
            app(BrevoMailService::class),
            app(AuditLogService::class),
        );

        $this->assertSame('sent', $event->fresh()->notification_status);
        $this->assertNotNull($event->fresh()->notified_at);
        $this->assertDatabaseHas('audit_logs', [
            'event' => 'stock_alert_sent',
            'auditable_id' => $event->id,
            'client_id' => $client->id,
        ]);
        Http::assertSentCount(1);
    }

    public function test_stock_alert_recipients_are_normalized_separate_and_audited(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->user(Role::ADMINISTRACION);
        $client = Client::factory()->create();

        $this->actingAs($admin)->post(route('clients.stock-alert-emails.store', $client), [
            'email' => 'STOCK@EXAMPLE.TEST',
            'active' => 1,
        ])->assertRedirect(route('clients.edit', $client));

        $this->assertDatabaseHas('client_stock_alert_email_recipients', ['client_id' => $client->id, 'email' => 'stock@example.test']);
        $this->assertDatabaseCount('client_receipt_email_recipients', 0);
        $this->assertDatabaseCount('client_dispatch_email_recipients', 0);
        $this->assertDatabaseHas('audit_logs', ['client_id' => $client->id, 'event' => 'stock_alert_email_added']);
    }

    public function test_silenced_critical_alert_waits_and_reappears_after_silence(): void
    {
        Queue::fake();
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id]);
        StockAlertRule::query()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'active' => true,
            'minimum_units' => 100,
            'severity' => 'critical',
            'cooldown_minutes' => 1440,
        ]);
        $service = app(StockAlertEvaluationService::class);
        $service->evaluate($client->id, $item->id, true);
        $event = StockAlertEvent::query()->firstOrFail();
        $event->update(['status' => StockAlertEvent::STATUS_SILENCED, 'silenced_until' => now()->addHour()]);

        $this->assertSame(1, $service->evaluate($client->id, $item->id, true)['unchanged']);
        $this->assertDatabaseCount('stock_alert_events', 1);

        $event->update(['silenced_until' => now()->subMinute()]);
        $this->assertSame(1, $service->evaluate($client->id, $item->id, true)['triggered']);
        $this->assertDatabaseCount('stock_alert_events', 2);
        $this->assertDatabaseHas('stock_alert_events', ['id' => $event->id, 'status' => StockAlertEvent::STATUS_RESOLVED]);
    }

    public function test_disabling_rule_resolves_its_active_event(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->user(Role::ADMINISTRACION);
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id]);
        $rule = StockAlertRule::query()->create(['client_id' => $client->id, 'item_id' => $item->id, 'active' => true, 'minimum_units' => 100, 'severity' => 'warning', 'cooldown_minutes' => 60]);
        app(StockAlertEvaluationService::class)->evaluate($client->id, $item->id, true);

        $this->actingAs($admin)->put(route('traceability.alerts.rules.update', $rule), [
            'client_id' => $client->id,
            'item_id' => $item->id,
            'active' => 0,
            'minimum_units' => 100,
            'severity' => 'warning',
            'cooldown_minutes' => 60,
        ])->assertRedirect(route('traceability.alerts.index', ['client_id' => $client->id]));

        $this->assertFalse($rule->fresh()->active);
        $this->assertNotNull(StockAlertEvent::query()->firstOrFail()->resolved_at);
        $this->assertDatabaseHas('audit_logs', ['event' => 'stock_alert_rule_deactivated']);
    }

    public function test_forecast_is_deterministic_and_includes_pending_free_stock(): void
    {
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id]);
        StockPallet::factory()->create([
            'client_id' => $client->id,
            'item_id' => $item->id,
            'quantity_units' => 3000,
            'warehouse_pallets' => 3,
        ]);
        foreach (range(1, 30) as $daysAgo) {
            $this->movement($client, $item, -100, now()->subDays($daysAgo));
        }

        $forecast = app(StockForecastService::class)->forecast($item);

        $this->assertSame('Media movil ponderada 7/30/90 dias', $forecast['method']);
        $this->assertSame(3000, $forecast['available_units']);
        $this->assertGreaterThan(0, $forecast['weighted_daily_average']);
        $this->assertNotNull($forecast['coverage_days']);
        $this->assertContains($forecast['confidence'], ['medium', 'high', 'low']);
    }

    public function test_lot_traceability_never_mixes_clients_or_items(): void
    {
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $itemA = Item::factory()->create(['client_id' => $clientA->id]);
        $itemB = Item::factory()->create(['client_id' => $clientB->id]);
        StockPallet::factory()->create(['client_id' => $clientA->id, 'item_id' => $itemA->id, 'lot' => 'SHARED-LOT', 'quantity_units' => 100]);
        StockPallet::factory()->create(['client_id' => $clientB->id, 'item_id' => $itemB->id, 'lot' => 'SHARED-LOT', 'quantity_units' => 900]);

        $trace = app(LotTraceabilityService::class)->trace($clientA->id, 'SHARED-LOT', $itemA->id);

        $this->assertSame(100, $trace['current_units']);
        $this->assertCount(1, $trace['stock']);
        $this->assertTrue($trace['stock']->every(fn (StockPallet $stock): bool => $stock->client_id === $clientA->id && $stock->item_id === $itemA->id));
    }

    public function test_lot_traceability_location_filter_does_not_mix_other_batches(): void
    {
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id]);
        $warehouse = Warehouse::factory()->create(['client_id' => $client->id]);
        $locationA = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => 'A-01']);
        $locationB = Location::factory()->create(['warehouse_id' => $warehouse->id, 'code' => 'B-01']);
        StockPallet::factory()->create(['client_id' => $client->id, 'item_id' => $item->id, 'lot' => 'FILTERED-LOT', 'location_id' => $locationA->id, 'quantity_units' => 100]);
        StockPallet::factory()->create(['client_id' => $client->id, 'item_id' => $item->id, 'lot' => 'FILTERED-LOT', 'location_id' => $locationB->id, 'quantity_units' => 900]);

        $trace = app(LotTraceabilityService::class)->trace($client->id, 'FILTERED-LOT', $item->id, [
            'location_id' => $locationA->id,
            'status' => 'active',
        ]);

        $this->assertSame(100, $trace['current_units']);
        $this->assertCount(1, $trace['stock']);
        $this->assertSame($locationA->id, $trace['stock']->first()->location_id);
    }

    public function test_client_analytics_category_filter_is_applied_to_stock_and_movements(): void
    {
        $client = Client::factory()->create();
        $availableItem = Item::factory()->create(['client_id' => $client->id, 'stock_category' => StockPallet::CATEGORY_IN_USE]);
        $blockedItem = Item::factory()->create(['client_id' => $client->id, 'stock_category' => StockPallet::CATEGORY_BLOCKED]);
        StockPallet::factory()->create(['client_id' => $client->id, 'item_id' => $availableItem->id, 'stock_category' => StockPallet::CATEGORY_IN_USE, 'quantity_units' => 100]);
        StockPallet::factory()->create(['client_id' => $client->id, 'item_id' => $blockedItem->id, 'stock_category' => StockPallet::CATEGORY_BLOCKED, 'status' => StockPallet::STATUS_BLOCKED, 'quantity_units' => 250]);
        $this->movement($client, $availableItem, -10, now(), ['metadata' => ['stock_category_before' => StockPallet::CATEGORY_IN_USE, 'stock_category_after' => StockPallet::CATEGORY_IN_USE]]);
        $this->movement($client, $blockedItem, -20, now(), ['metadata' => ['stock_category_before' => StockPallet::CATEGORY_BLOCKED, 'stock_category_after' => StockPallet::CATEGORY_BLOCKED]]);

        $results = app(ClientInventoryAnalyticsService::class)->analyze(
            $client->id,
            now()->subDay(),
            now(),
            category: StockPallet::CATEGORY_BLOCKED,
        );

        $this->assertSame([$blockedItem->id], $results['rankings']->pluck('item_id')->all());
        $this->assertSame(250, $results['stock_summary']['blocked_units']);
        $this->assertSame(0, $results['stock_summary']['available_units']);
    }

    public function test_movement_export_is_scoped_limited_and_audited(): void
    {
        $this->seed(RoleSeeder::class);
        $admin = $this->user(Role::ADMINISTRACION);
        $clientA = Client::factory()->create();
        $clientB = Client::factory()->create();
        $itemA = Item::factory()->create(['client_id' => $clientA->id, 'sku' => 'ONLY-A']);
        $itemB = Item::factory()->create(['client_id' => $clientB->id, 'sku' => 'NEVER-B']);
        $this->movement($clientA, $itemA, -10, now());
        $this->movement($clientB, $itemB, -20, now());

        $response = $this->actingAs($admin)->get(route('traceability.reports.movements.csv', [
            'client_id' => $clientA->id,
            'date_from' => now()->toDateString(),
            'date_to' => now()->toDateString(),
        ]))->assertOk();
        $csv = $response->streamedContent();

        $this->assertStringContainsString('ONLY-A', $csv);
        $this->assertStringNotContainsString('NEVER-B', $csv);
        $this->assertDatabaseHas('audit_logs', ['client_id' => $clientA->id, 'event' => 'traceability_report_exported']);
    }

    public function test_traceability_backfill_dry_run_creates_no_records(): void
    {
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id]);
        StockPallet::factory()->create(['client_id' => $client->id, 'item_id' => $item->id, 'quantity_units' => 250]);

        $summary = app(TraceabilityBackfillService::class)->run($client->id, false);

        $this->assertSame(1, $summary['opening_balances']);
        $this->assertDatabaseCount('inventory_movements', 0);
        $this->assertDatabaseCount('audit_logs', 0);
    }

    public function test_traceability_backfill_apply_is_idempotent_and_never_changes_stock(): void
    {
        $client = Client::factory()->create();
        $item = Item::factory()->create(['client_id' => $client->id]);
        $stock = StockPallet::factory()->create(['client_id' => $client->id, 'item_id' => $item->id, 'quantity_units' => 250]);
        $service = app(TraceabilityBackfillService::class);

        $first = $service->run($client->id, true);
        $second = $service->run($client->id, true);

        $this->assertSame(1, $first['created_movements']);
        $this->assertSame(0, $second['created_movements']);
        $this->assertSame(250, (int) $stock->fresh()->quantity_units);
        $this->assertDatabaseCount('inventory_movements', 1);
        $this->assertDatabaseHas('inventory_movements', [
            'stock_pallet_id' => $stock->id,
            'movement_type' => InventoryMovement::OPENING_BALANCE,
            'source' => 'backfill',
            'reconstruction_confidence' => 'opening_balance',
        ]);
    }

    private function user(string $roleSlug, ?Client $client = null): User
    {
        $role = Role::query()->where('slug', $roleSlug)->firstOrFail();

        return User::factory()->create(['role_id' => $role->id, 'client_id' => $client?->id]);
    }

    /** @param array<string, mixed> $extra */
    private function movement(Client $client, Item $item, int $unitsDelta, $effectiveAt, array $extra = []): InventoryMovement
    {
        return InventoryMovement::query()->create([
            'uuid' => (string) Str::uuid(),
            'correlation_id' => (string) Str::uuid(),
            'idempotency_key' => 'test:forecast:'.Str::uuid(),
            'client_id' => $client->id,
            'client_name' => $client->name,
            'item_id' => $item->id,
            'sku' => $item->sku,
            'description' => $item->description,
            ...$extra,
            'movement_type' => InventoryMovement::DISPATCH,
            'source' => 'test',
            'units_delta' => $unitsDelta,
            'full_pallets_delta' => 0,
            'warehouse_pallets_delta' => 0,
            'effective_at' => $effectiveAt,
            'recorded_at' => $effectiveAt,
            'created_at' => $effectiveAt,
        ]);
    }
}
