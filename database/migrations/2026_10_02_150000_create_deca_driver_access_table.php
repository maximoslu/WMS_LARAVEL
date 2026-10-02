<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('deca_driver_access', function (Blueprint $table): void {
            $table->unsignedTinyInteger('id')->primary();
            $table->string('password_hash');
            $table->uuid('version');
            $table->foreignId('owner_id')->constrained('users');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('deca_driver_access');
    }
};
