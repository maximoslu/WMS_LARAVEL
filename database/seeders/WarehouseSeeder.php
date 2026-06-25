<?php

namespace Database\Seeders;

use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class WarehouseSeeder extends Seeder
{
    public function run(): void
    {
        Warehouse::query()->updateOrCreate(
            [
                'client_id' => null,
                'code' => 'MAX-01',
            ],
            [
                'name' => 'MAXIMO PRINCIPAL',
                'active' => true,
            ]
        );
    }
}
