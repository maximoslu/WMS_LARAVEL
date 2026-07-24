<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->boolean('send_order_preparation_pdf_to_client')->default(false)->after('show_stock_total_to_client');
            $table->boolean('allow_order_line_required_units')->default(false)->after('send_order_preparation_pdf_to_client');
        });

        DB::table('clients')
            ->where('code', 'EDELVIVES')
            ->update([
                'send_order_preparation_pdf_to_client' => true,
                'allow_order_line_required_units' => true,
            ]);
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $table->dropColumn([
                'send_order_preparation_pdf_to_client',
                'allow_order_line_required_units',
            ]);
        });
    }
};
