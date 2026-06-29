<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('daily_operation_lines', function (Blueprint $table): void {
            $table->foreignId('parent_line_id')
                ->nullable()
                ->after('source_id')
                ->constrained('daily_operation_lines')
                ->cascadeOnDelete();

            $table->index(['day_id', 'parent_line_id'], 'daily_operation_lines_day_parent_index');
        });
    }

    public function down(): void
    {
        Schema::table('daily_operation_lines', function (Blueprint $table): void {
            $table->dropIndex('daily_operation_lines_day_parent_index');
            $table->dropConstrainedForeignId('parent_line_id');
        });
    }
};
