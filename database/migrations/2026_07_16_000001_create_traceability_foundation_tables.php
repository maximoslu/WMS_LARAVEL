<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('user_role', 50)->nullable();
            $table->string('event', 100)->index();
            $table->string('module', 80)->index();
            $table->string('source', 40)->default('web');
            $table->string('severity', 20)->default('info')->index();
            $table->string('auditable_type')->nullable();
            $table->unsignedBigInteger('auditable_id')->nullable();
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->text('description');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();
            $table->string('route')->nullable();
            $table->string('method', 10)->nullable();
            $table->uuid('correlation_id')->index();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('occurred_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['client_id', 'occurred_at'], 'audit_client_occurred_idx');
            $table->index(['auditable_type', 'auditable_id'], 'audit_auditable_idx');
            $table->index(['subject_type', 'subject_id'], 'audit_subject_idx');
        });

        Schema::create('user_activity_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->string('user_name');
            $table->string('user_role', 50)->nullable();
            $table->string('session_hash', 64)->index();
            $table->dateTime('started_at')->index();
            $table->dateTime('last_seen_at')->index();
            $table->dateTime('ended_at')->nullable();
            $table->unsignedInteger('active_seconds')->default(0);
            $table->string('closure_reason', 40)->nullable();
            $table->string('ip_address', 64)->nullable();
            $table->string('user_agent', 255)->nullable();
            $table->timestamps();

            $table->index(['user_id', 'started_at'], 'activity_user_started_idx');
        });

        Schema::create('user_section_metrics', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('client_id')->nullable()->index();
            $table->date('metric_date')->index();
            $table->string('section', 100);
            $table->unsignedInteger('visits')->default(0);
            $table->unsignedInteger('active_seconds')->default(0);
            $table->dateTime('last_seen_at')->nullable();
            $table->timestamps();

            $table->unique(['user_id', 'metric_date', 'section'], 'activity_metric_unique');
            $table->index(['client_id', 'metric_date'], 'activity_client_date_idx');
        });

        Schema::create('inventory_movements', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->uuid('correlation_id')->index();
            $table->string('idempotency_key', 191)->unique();
            $table->unsignedBigInteger('client_id')->index();
            $table->string('client_name')->nullable();
            $table->unsignedBigInteger('item_id')->nullable()->index();
            $table->string('sku', 100)->nullable()->index();
            $table->string('description')->nullable();
            $table->string('lot', 100)->nullable()->index();
            $table->unsignedBigInteger('stock_pallet_id')->nullable()->index();
            $table->string('movement_type', 50)->index();
            $table->string('source', 40)->default('live')->index();
            $table->string('source_type')->nullable();
            $table->unsignedBigInteger('source_id')->nullable();
            $table->string('source_line_type')->nullable();
            $table->unsignedBigInteger('source_line_id')->nullable();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('user_name')->nullable();
            $table->string('user_role', 50)->nullable();
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->unsignedBigInteger('location_id')->nullable()->index();
            $table->unsignedBigInteger('from_warehouse_id')->nullable();
            $table->unsignedBigInteger('from_location_id')->nullable();
            $table->unsignedBigInteger('to_warehouse_id')->nullable();
            $table->unsignedBigInteger('to_location_id')->nullable();
            $table->bigInteger('units_before')->nullable();
            $table->bigInteger('units_delta');
            $table->bigInteger('units_after')->nullable();
            $table->integer('full_pallets_before')->nullable();
            $table->integer('full_pallets_delta')->default(0);
            $table->integer('full_pallets_after')->nullable();
            $table->decimal('warehouse_pallets_before', 12, 2)->nullable();
            $table->decimal('warehouse_pallets_delta', 12, 2)->default(0);
            $table->decimal('warehouse_pallets_after', 12, 2)->nullable();
            $table->json('peaks_before')->nullable();
            $table->json('peaks_delta')->nullable();
            $table->json('peaks_after')->nullable();
            $table->json('metadata')->nullable();
            $table->string('reconstruction_confidence', 20)->default('exact');
            $table->unsignedBigInteger('reversal_of_id')->nullable()->index();
            $table->dateTime('effective_at')->index();
            $table->dateTime('recorded_at')->index();
            $table->timestamp('created_at')->nullable();

            $table->index(['client_id', 'effective_at'], 'movement_client_effective_idx');
            $table->index(['client_id', 'item_id', 'lot'], 'movement_client_item_lot_idx');
            $table->index(['source_type', 'source_id'], 'movement_source_idx');
            $table->index(['source_line_type', 'source_line_id'], 'movement_source_line_idx');
        });

        Schema::create('stock_alert_rules', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('item_id')->constrained()->restrictOnDelete();
            $table->boolean('active')->default(true)->index();
            $table->unsignedBigInteger('minimum_units')->nullable();
            $table->unsignedInteger('minimum_pallets')->nullable();
            $table->unsignedInteger('minimum_coverage_days')->nullable();
            $table->unsignedInteger('exhaustion_horizon_days')->nullable();
            $table->unsignedBigInteger('safety_stock_units')->default(0);
            $table->unsignedInteger('lead_time_days')->default(0);
            $table->boolean('include_blocked_stock')->default(false);
            $table->boolean('include_obsolete_stock')->default(false);
            $table->string('severity', 20)->default('warning');
            $table->unsignedInteger('cooldown_minutes')->default(1440);
            $table->dateTime('last_evaluated_at')->nullable();
            $table->dateTime('last_alerted_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['client_id', 'item_id'], 'stock_alert_rule_client_item_unique');
            $table->index(['client_id', 'active'], 'stock_alert_rule_client_active_idx');
        });

        Schema::create('stock_alert_events', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('stock_alert_rule_id')->constrained()->restrictOnDelete();
            $table->unsignedBigInteger('client_id')->index();
            $table->unsignedBigInteger('item_id')->index();
            $table->string('severity', 20)->index();
            $table->string('status', 20)->default('warning')->index();
            $table->text('reason');
            $table->bigInteger('observed_units')->nullable();
            $table->bigInteger('threshold_units')->nullable();
            $table->decimal('observed_pallets', 12, 2)->nullable();
            $table->decimal('threshold_pallets', 12, 2)->nullable();
            $table->decimal('coverage_days', 12, 2)->nullable();
            $table->date('estimated_exhaustion_date')->nullable();
            $table->json('criteria')->nullable();
            $table->json('recipients')->nullable();
            $table->string('notification_status', 20)->default('pending');
            $table->text('notification_error')->nullable();
            $table->dateTime('triggered_at')->index();
            $table->dateTime('notified_at')->nullable();
            $table->unsignedBigInteger('acknowledged_by')->nullable();
            $table->dateTime('acknowledged_at')->nullable();
            $table->dateTime('silenced_until')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->dateTime('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status'], 'stock_alert_event_client_status_idx');
            $table->index(['stock_alert_rule_id', 'triggered_at'], 'stock_alert_event_rule_date_idx');
        });

        Schema::create('client_stock_alert_email_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->unique(['client_id', 'email'], 'stock_alert_email_client_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_stock_alert_email_recipients');
        Schema::dropIfExists('stock_alert_events');
        Schema::dropIfExists('stock_alert_rules');
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('user_section_metrics');
        Schema::dropIfExists('user_activity_sessions');
        Schema::dropIfExists('audit_logs');
    }
};
