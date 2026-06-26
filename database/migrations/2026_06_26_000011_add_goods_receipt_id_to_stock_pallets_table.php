<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            $table->foreignId('goods_receipt_id')
                ->nullable()
                ->after('item_id')
                ->constrained('goods_receipts')
                ->nullOnDelete();

            $table->index(['goods_receipt_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::table('stock_pallets', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('goods_receipt_id');
        });
    }
};
