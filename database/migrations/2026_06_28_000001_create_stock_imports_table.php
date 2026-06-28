<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_imports', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('client_id')->constrained()->cascadeOnDelete();
            $table->foreignId('uploaded_by')->constrained('users')->cascadeOnDelete();
            $table->string('original_filename');
            $table->string('stored_path');
            $table->string('status')->default('previewed');
            $table->unsignedInteger('total_rows')->default(0);
            $table->unsignedInteger('imported_rows')->default(0);
            $table->unsignedInteger('skipped_rows')->default(0);
            $table->unsignedInteger('available_rows')->default(0);
            $table->unsignedInteger('blocked_rows')->default(0);
            $table->json('detected_sheets_json')->nullable();
            $table->json('summary_json')->nullable();
            $table->json('warnings_json')->nullable();
            $table->json('errors_json')->nullable();
            $table->timestamp('imported_at')->nullable();
            $table->timestamps();

            $table->index(['client_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_imports');
    }
};
