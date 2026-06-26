<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_dispatches', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_dispatches', 'delivery_note_sent_at')) {
                $table->timestamp('delivery_note_sent_at')->nullable()->after('completed_at');
            }
        });

        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_dispatch_lines', 'is_extra_line')) {
                $table->boolean('is_extra_line')->default(false)->after('confirmed_at');
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'source_request_line_id')) {
                $table->foreignId('source_request_line_id')
                    ->nullable()
                    ->after('is_extra_line')
                    ->constrained('merchandise_request_lines')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_dispatch_lines', 'source_request_line_id')) {
                $table->dropConstrainedForeignId('source_request_line_id');
            }

            if (Schema::hasColumn('goods_dispatch_lines', 'is_extra_line')) {
                $table->dropColumn('is_extra_line');
            }
        });

        Schema::table('goods_dispatches', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_dispatches', 'delivery_note_sent_at')) {
                $table->dropColumn('delivery_note_sent_at');
            }
        });
    }
};
