<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backup_exports', function (Blueprint $table): void {
            $table->id();
            $table->string('type', 60)->index();
            $table->string('scope', 120)->nullable()->index();
            $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 30)->default('pending')->index();
            $table->string('disk', 60)->default('local');
            $table->string('path')->nullable();
            $table->string('filename')->nullable();
            $table->string('mime_type', 120)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->string('checksum', 128)->nullable();
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'created_at']);
            $table->index(['client_id', 'type', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backup_exports');
    }
};
