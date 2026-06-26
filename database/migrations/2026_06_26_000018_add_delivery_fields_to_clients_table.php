<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            if (! Schema::hasColumn('clients', 'delivery_address')) {
                $table->text('delivery_address')->nullable()->after('code');
            }

            if (! Schema::hasColumn('clients', 'delivery_postal_code')) {
                $table->string('delivery_postal_code', 20)->nullable()->after('delivery_address');
            }

            if (! Schema::hasColumn('clients', 'delivery_city')) {
                $table->string('delivery_city', 120)->nullable()->after('delivery_postal_code');
            }

            if (! Schema::hasColumn('clients', 'delivery_province')) {
                $table->string('delivery_province', 120)->nullable()->after('delivery_city');
            }

            if (! Schema::hasColumn('clients', 'delivery_country')) {
                $table->string('delivery_country', 120)->nullable()->after('delivery_province');
            }
        });
    }

    public function down(): void
    {
        Schema::table('clients', function (Blueprint $table): void {
            $columns = [
                'delivery_country',
                'delivery_province',
                'delivery_city',
                'delivery_postal_code',
                'delivery_address',
            ];

            foreach ($columns as $column) {
                if (Schema::hasColumn('clients', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
