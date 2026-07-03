<?php

use App\Support\WmsLineType;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('merchandise_request_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('merchandise_request_lines', 'stock_pallet_id')) {
                $table->foreignId('stock_pallet_id')
                    ->nullable()
                    ->after('item_id')
                    ->constrained('stock_pallets')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('merchandise_request_lines', 'line_type')) {
                $table->string('line_type', 20)->default(WmsLineType::PALLET)->after('stock_pallet_id');
            }

            if (! Schema::hasColumn('merchandise_request_lines', 'stock_peak_index')) {
                $table->unsignedTinyInteger('stock_peak_index')->nullable()->after('line_type');
            }

            if (! Schema::hasColumn('merchandise_request_lines', 'units_per_peak')) {
                $table->unsignedInteger('units_per_peak')->nullable()->after('units_per_pallet');
            }

            if (! Schema::hasColumn('merchandise_request_lines', 'requested_peaks')) {
                $table->unsignedInteger('requested_peaks')->default(0)->after('requested_pallets');
            }

            if (! Schema::hasColumn('merchandise_request_lines', 'prepared_peaks')) {
                $table->unsignedInteger('prepared_peaks')->nullable()->after('prepared_pallets');
            }
        });

        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (! Schema::hasColumn('goods_dispatch_lines', 'stock_pallet_id')) {
                $table->foreignId('stock_pallet_id')
                    ->nullable()
                    ->after('item_id')
                    ->constrained('stock_pallets')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'line_type')) {
                $table->string('line_type', 20)->default(WmsLineType::PALLET)->after('stock_pallet_id');
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'stock_peak_index')) {
                $table->unsignedTinyInteger('stock_peak_index')->nullable()->after('line_type');
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'units_per_peak')) {
                $table->unsignedInteger('units_per_peak')->nullable()->after('units_per_pallet');
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'requested_peaks')) {
                $table->unsignedInteger('requested_peaks')->default(0)->after('requested_pallets');
            }

            if (! Schema::hasColumn('goods_dispatch_lines', 'loaded_peaks')) {
                $table->unsignedInteger('loaded_peaks')->nullable()->after('loaded_pallets');
            }
        });

        DB::table('merchandise_request_lines')
            ->whereNull('line_type')
            ->update([
                'line_type' => WmsLineType::PALLET,
            ]);

        DB::table('goods_dispatch_lines')
            ->whereNull('line_type')
            ->update([
                'line_type' => WmsLineType::PALLET,
            ]);
    }

    public function down(): void
    {
        Schema::table('goods_dispatch_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('goods_dispatch_lines', 'stock_pallet_id')) {
                $table->dropConstrainedForeignId('stock_pallet_id');
            }

            foreach (['line_type', 'stock_peak_index', 'units_per_peak', 'requested_peaks', 'loaded_peaks'] as $column) {
                if (Schema::hasColumn('goods_dispatch_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        Schema::table('merchandise_request_lines', function (Blueprint $table): void {
            if (Schema::hasColumn('merchandise_request_lines', 'stock_pallet_id')) {
                $table->dropConstrainedForeignId('stock_pallet_id');
            }

            foreach (['line_type', 'stock_peak_index', 'units_per_peak', 'requested_peaks', 'prepared_peaks'] as $column) {
                if (Schema::hasColumn('merchandise_request_lines', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
