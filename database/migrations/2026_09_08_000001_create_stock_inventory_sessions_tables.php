<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_inventory_sessions', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->string('status', 30)->default('in_progress')->index();
            $table->boolean('open_slot')->nullable()->default(true);
            $table->json('scope_filters');
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('started_at')->index();
            $table->foreignId('completed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('completed_at')->nullable()->index();
            $table->json('final_summary')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'open_slot'], 'stock_inventory_one_open_per_client');
            $table->index(['client_id', 'status'], 'stock_inventory_client_status_idx');
        });

        Schema::create('stock_inventory_locations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('stock_inventory_session_id')
                ->constrained('stock_inventory_sessions')
                ->cascadeOnDelete();
            $table->foreignId('client_id')->constrained()->restrictOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('warehouse_id')->nullable()->index();
            $table->string('scope_key', 191);
            $table->string('warehouse_code')->nullable();
            $table->string('warehouse_name')->nullable();
            $table->string('location_code')->nullable();
            $table->string('location_label');
            $table->string('check_state', 30)->default('pending')->index();
            $table->foreignId('checked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->dateTime('checked_at')->nullable()->index();
            $table->unsignedBigInteger('checked_movement_id')->default(0);
            $table->json('checked_snapshot')->nullable();
            $table->text('notes')->nullable();
            $table->string('finalized_status', 30)->nullable();
            $table->timestamps();

            $table->unique(
                ['stock_inventory_session_id', 'scope_key'],
                'stock_inventory_location_scope_unique'
            );
            $table->index(
                ['client_id', 'location_id'],
                'stock_inventory_location_client_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_inventory_locations');
        Schema::dropIfExists('stock_inventory_sessions');
    }
};
