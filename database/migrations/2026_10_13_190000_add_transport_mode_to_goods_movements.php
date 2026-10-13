<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_receipts', function (Blueprint $table): void {
            $table->string('transport_mode', 30)->nullable();
            $table->json('transport_data')->nullable();
        });
        Schema::table('goods_dispatches', fn (Blueprint $table) => $table->string('transport_mode', 30)->nullable());
    }

    public function down(): void
    {
        Schema::table('goods_receipts', fn (Blueprint $table) => $table->dropColumn(['transport_mode', 'transport_data']));
        Schema::table('goods_dispatches', fn (Blueprint $table) => $table->dropColumn('transport_mode'));
    }
};
