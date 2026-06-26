<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_dispatches', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_dispatches', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('sent_at');
            }
        });

        Schema::table('merchandise_requests', function (Blueprint $table): void {
            if (! Schema::hasColumn('merchandise_requests', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('shipped_at');
            }
        });

        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_dispatch_lines', 'requested_pallets')) {
                $table->unsignedInteger('requested_pallets')->nullable()->after('requested_units');
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'loaded_pallets')) {
                $table->unsignedInteger('loaded_pallets')->nullable()->after('requested_pallets');
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'loading_notes')) {
                $table->text('loading_notes')->nullable()->after('loaded_pallets');
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'confirmed_by')) {
                $table->foreignId('confirmed_by')->nullable()->after('loading_notes')->constrained('users')->nullOnDelete();
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'confirmed_at')) {
                $table->timestamp('confirmed_at')->nullable()->after('confirmed_by');
            }
        });

        DB::table('goods_dispatch_lines')
            ->whereNull('requested_pallets')
            ->update([
                'requested_pallets' => DB::raw('pallets'),
            ]);
    }

    public function down(): void
    {
        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_dispatch_lines', 'confirmed_by')) {
                $table->dropConstrainedForeignId('confirmed_by');
            }

            foreach (['confirmed_at', 'loading_notes', 'loaded_pallets', 'requested_pallets'] as $column) {
                if (Schema::hasColumn('goods_dispatch_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('merchandise_requests', function (Blueprint $table): void {
            if (Schema::hasColumn('merchandise_requests', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });

        Schema::table('goods_dispatches', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_dispatches', 'completed_at')) {
                $table->dropColumn('completed_at');
            }
        });
    }
};
