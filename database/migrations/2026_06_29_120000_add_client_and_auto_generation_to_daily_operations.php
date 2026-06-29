<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_operation_days', function (Blueprint $table): void {
            $table->foreignId('client_id')->nullable()->after('operation_date')->constrained()->nullOnDelete();
        });

        Schema::table('daily_operation_days', function (Blueprint $table): void {
            $table->dropUnique('daily_operation_days_operation_date_unique');
            $table->unique(['operation_date', 'client_id'], 'daily_operation_days_date_client_unique');
        });

        Schema::table('daily_operation_lines', function (Blueprint $table): void {
            $table->boolean('is_auto_generated')->default(false)->after('without_booking');
            $table->string('source_type', 40)->nullable()->after('is_auto_generated');
            $table->unsignedBigInteger('source_id')->nullable()->after('source_type');

            $table->index(['day_id', 'is_auto_generated'], 'daily_operation_lines_day_auto_index');
            $table->index(['source_type', 'source_id'], 'daily_operation_lines_source_index');
        });
    }

    public function down(): void
    {
        Schema::table('daily_operation_lines', function (Blueprint $table): void {
            $table->dropIndex('daily_operation_lines_day_auto_index');
            $table->dropIndex('daily_operation_lines_source_index');
            $table->dropColumn(['is_auto_generated', 'source_type', 'source_id']);
        });

        Schema::table('daily_operation_days', function (Blueprint $table): void {
            $table->dropUnique('daily_operation_days_date_client_unique');
            $table->unique('operation_date');
            $table->dropConstrainedForeignId('client_id');
        });
    }
};
