<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('daily_operation_days')) {
            return;
        }

        Schema::create('daily_operation_days', function (Blueprint $table): void {
            $table->id();
            $table->date('operation_date')->unique();
            $table->unsignedInteger('opening_pallets')->nullable();
            $table->unsignedInteger('stored_pallets_today')->nullable();
            $table->unsignedInteger('moved_pallets_today')->nullable();
            $table->unsignedInteger('expected_pallets_tomorrow')->nullable();
            $table->text('notes')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index('operation_date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('daily_operation_days');
    }
};
