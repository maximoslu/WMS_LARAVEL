<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_dispatches', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_dispatches', 'stock_applied_at')) {
                $table->timestamp('stock_applied_at')->nullable()->after('delivery_note_sent_at');
            }

            if (! Schema::hasColumn('goods_dispatches', 'stock_applied_by')) {
                $table->foreignId('stock_applied_by')
                    ->nullable()
                    ->after('stock_applied_at')
                    ->constrained('users')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('goods_dispatches', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_dispatches', 'stock_applied_by')) {
                $table->dropConstrainedForeignId('stock_applied_by');
            }

            if (Schema::hasColumn('goods_dispatches', 'stock_applied_at')) {
                $table->dropColumn('stock_applied_at');
            }
        });
    }
};
