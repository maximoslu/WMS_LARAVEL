<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('items', function (Blueprint $table): void {
            if (! Schema::hasColumn('items', 'stock_category')) {
                $table->string('stock_category', 20)->default('in_use')->after('status');
            }
        });

        Schema::table('stock_pallets', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_pallets', 'stock_category')) {
                $table->string('stock_category', 20)->default('in_use')->after('status');
            }

            if (! Schema::hasColumn('stock_pallets', 'warehouse_pallets')) {
                $table->decimal('warehouse_pallets', 10, 2)->nullable()->after('peaks_count');
            }
        });

        DB::table('items')
            ->whereNull('stock_category')
            ->orWhere('stock_category', '')
            ->update(['stock_category' => 'in_use']);

        DB::table('stock_pallets')
            ->whereNull('stock_category')
            ->orWhere('stock_category', '')
            ->update(['stock_category' => 'in_use']);

        DB::table('stock_pallets')
            ->whereNull('warehouse_pallets')
            ->update([
                'warehouse_pallets' => DB::raw('COALESCE(full_pallets, 0) + COALESCE(peaks_count, 0)'),
            ]);
    }

    public function down(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_pallets', 'warehouse_pallets')) {
                $table->dropColumn('warehouse_pallets');
            }

            if (Schema::hasColumn('stock_pallets', 'stock_category')) {
                $table->dropColumn('stock_category');
            }
        });

        Schema::table('items', function (Blueprint $table): void {
            if (Schema::hasColumn('items', 'stock_category')) {
                $table->dropColumn('stock_category');
            }
        });
    }
};
