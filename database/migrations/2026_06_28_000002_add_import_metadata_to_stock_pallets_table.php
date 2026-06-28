<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_pallets', 'peak_9')) {
                $table->unsignedInteger('peak_9')
                    ->default(0)
                    ->after('peak_8');
            }

            if (! Schema::hasColumn('stock_pallets', 'peak_10')) {
                $table->unsignedInteger('peak_10')
                    ->default(0)
                    ->after('peak_9');
            }

            if (! Schema::hasColumn('stock_pallets', 'stock_import_id')) {
                $table->foreignId('stock_import_id')
                    ->nullable()
                    ->after('goods_receipt_id')
                    ->constrained('stock_imports')
                    ->nullOnDelete();
            }

            if (! Schema::hasColumn('stock_pallets', 'source_sheet')) {
                $table->string('source_sheet')
                    ->nullable()
                    ->after('blocked_reason');
            }

            if (! Schema::hasColumn('stock_pallets', 'imported_at')) {
                $table->timestamp('imported_at')
                    ->nullable()
                    ->after('received_at');
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_pallets', 'stock_import_id')) {
                $table->dropConstrainedForeignId('stock_import_id');
            }

            foreach (['peak_10', 'peak_9', 'source_sheet', 'imported_at'] as $column) {
                if (Schema::hasColumn('stock_pallets', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
