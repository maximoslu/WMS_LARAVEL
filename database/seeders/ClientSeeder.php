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
     * @return array<int, array{name: string, code: string, active: bool, show_storage_occupancy_to_client: bool, show_stock_total_to_client: bool, send_order_preparation_pdf_to_client: bool, allow_order_line_required_units: bool}>
     */
    public function defaults(): array
    {
        return [
            [
                'name' => 'FRIESLAND',
                'code' => 'FRIESLAND',
                'active' => true,
                'show_storage_occupancy_to_client' => false,
                'show_stock_total_to_client' => false,
                'send_order_preparation_pdf_to_client' => false,
                'allow_order_line_required_units' => false,
            ],
            [
                'name' => 'EDELVIVES',
                'code' => 'EDELVIVES',
                'active' => true,
                'show_storage_occupancy_to_client' => true,
                'show_stock_total_to_client' => true,
                'send_order_preparation_pdf_to_client' => true,
                'allow_order_line_required_units' => true,
            ],
        ];
    }
}
