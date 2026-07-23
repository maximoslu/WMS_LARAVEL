<?php

namespace Database\Seeders;

use App\Models\Client;
use Illuminate\Database\Seeder;

class ClientSeeder extends Seeder
{
    public function run(): void
    {
        foreach ($this->defaults() as $client) {
            Client::query()->updateOrCreate(
                ['code' => $client['code']],
                $client
            );
        }
    }

    /**
     * @return array<int, array{name: string, code: string, active: bool, show_storage_occupancy_to_client: bool}>
     */
    public function defaults(): array
    {
        return [
            [
                'name' => 'FRIESLAND',
                'code' => 'FRIESLAND',
                'active' => true,
                'show_storage_occupancy_to_client' => false,
            ],
            [
                'name' => 'EDELVIVES',
                'code' => 'EDELVIVES',
                'active' => true,
                'show_storage_occupancy_to_client' => true,
            ],
        ];
    }
}
