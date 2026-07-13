<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('client_dispatch_email_recipients', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->string('email');
            $table->string('name')->nullable();
            $table->timestamps();

            $table->unique(['client_id', 'email']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('client_dispatch_email_recipients');
    }
};
