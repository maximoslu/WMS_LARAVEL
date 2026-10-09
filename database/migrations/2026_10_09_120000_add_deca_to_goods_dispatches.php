<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('goods_dispatches', function (Blueprint $table): void {
            $table->json('transport_data')->nullable();
            $table->foreignId('deca_document_id')->nullable()->unique()->constrained('deca_documents')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('goods_dispatches', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('deca_document_id');
            $table->dropColumn('transport_data');
        });
    }
};
