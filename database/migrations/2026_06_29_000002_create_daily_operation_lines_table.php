<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_operation_lines')) {
            return;
        }

        Schema::create('daily_operation_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('day_id')->constrained('daily_operation_days')->cascadeOnDelete();
            $table->string('section', 30);
            $table->string('counterparty_name', 255);
            $table->unsignedInteger('pallets');
            $table->text('observations')->nullable();
            $table->boolean('without_booking')->default(false);
            $table->unsignedInteger('sort_order')->default(0);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['day_id', 'section']);
            $table->index(['day_id', 'sort_order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_operation_lines');
    }
};
