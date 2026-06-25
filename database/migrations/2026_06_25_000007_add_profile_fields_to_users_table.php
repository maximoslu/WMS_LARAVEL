<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (! Schema::hasColumn('users', 'client_id')) {
                $table->foreignId('client_id')->nullable()->constrained()->nullOnDelete();
            }

            if (! Schema::hasColumn('users', 'avatar_path')) {
                $table->string('avatar_path')->nullable();
            }

            if (! Schema::hasColumn('users', 'active')) {
                $table->boolean('active')->default(true);
            }
        });

        DB::table('users')->update([
            'active' => true,
        ]);
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            if (Schema::hasColumn('users', 'client_id')) {
                $table->dropConstrainedForeignId('client_id');
            }

            $columnsToDrop = [];

            if (Schema::hasColumn('users', 'avatar_path')) {
                $columnsToDrop[] = 'avatar_path';
            }

            if (Schema::hasColumn('users', 'active')) {
                $columnsToDrop[] = 'active';
            }

            if ($columnsToDrop !== []) {
                $table->dropColumn($columnsToDrop);
            }
        });
    }
};
