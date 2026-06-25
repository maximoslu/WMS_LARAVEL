<?php

namespace Database\Seeders;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Database\Seeder;

class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $warehouse = Warehouse::query()->firstOrCreate(
            [
                'client_id' => null,
                'code' => 'MAX-01',
            ],
            [
                'name' => 'MAXIMO PRINCIPAL',
                'active' => true,
            ]
        );

        foreach ([
            ['code' => 'A1-01', 'zone' => 'BULK', 'aisle' => 'A1', 'rack' => '01', 'level' => '01', 'position' => '01'],
            ['code' => 'A1-02', 'zone' => 'BULK', 'aisle' => 'A1', 'rack' => '02', 'level' => '01', 'position' => '01'],
            ['code' => 'B1-01', 'zone' => 'BULK', 'aisle' => 'B1', 'rack' => '01', 'level' => '01', 'position' => '01'],
            ['code' => 'PICKING-01', 'zone' => 'PICKING', 'aisle' => 'PK', 'rack' => '01', 'level' => '01', 'position' => '01'],
        ] as $location) {
            Location::query()->updateOrCreate(
                [
                    'warehouse_id' => $warehouse->id,
                    'code' => $location['code'],
                ],
                [
                    'name' => $location['code'],
                    'zone' => $location['zone'],
                    'aisle' => $location['aisle'],
                    'rack' => $location['rack'],
                    'level' => $location['level'],
                    'position' => $location['position'],
                    'active' => true,
                ]
            );
        }
    }
}
