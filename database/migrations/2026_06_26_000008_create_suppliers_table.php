<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('suppliers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('tax_id')->nullable();
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('contact_name')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('active')->default(true);
            $table->timestamps();

            $table->index(['client_id', 'active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('suppliers');
    }
};
