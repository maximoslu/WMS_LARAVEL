<?php

namespace Database\Seeders;

use App\Models\Client;
use App\Models\Item;
use App\Models\StockPallet;
use Illuminate\Database\Seeder;

class StockPalletSeeder extends Seeder
{
    public function run(): void
    {
        $friesland = Client::query()->firstOrCreate(
            ['code' => 'FRIESLAND'],
            ['name' => 'FRIESLAND', 'active' => true]
        );

        $edelvives = Client::query()->firstOrCreate(
            ['code' => 'EDELVIVES'],
            ['name' => 'EDELVIVES', 'active' => true]
        );

        $frMilk = $this->upsertItem($friesland, [
            'sku' => 'FR-LECHE-700',
            'description' => 'Palet leche entera 700',
            'lot' => null,
            'units_per_pallet' => 700,
            'active' => true,
        ]);

        $frWater = $this->upsertItem($friesland, [
            'sku' => 'FR-AGUA-840',
            'description' => 'Agua embotellada 840',
            'lot' => null,
            'units_per_pallet' => 840,
            'active' => true,
        ]);

        $edBooks = $this->upsertItem($edelvives, [
            'sku' => 'ED-BOOK-420',
            'description' => 'Libro escolar 420',
            'lot' => 'LOT-A1',
            'units_per_pallet' => 420,
            'active' => true,
        ]);

        $edKit = $this->upsertItem($edelvives, [
            'sku' => 'ED-KIT-500',
            'description' => 'Kit educativo 500',
            'lot' => null,
            'units_per_pallet' => 500,
            'active' => true,
        ]);

        foreach ([
            [
                'item' => $frMilk,
                'client' => $friesland,
                'pallets' => [
                    ['pallet_code' => 'FR-LECHE-001', 'location_text' => 'A1-01', 'quantity_units' => 700, 'received_at' => '2026-06-10'],
                    ['pallet_code' => 'FR-LECHE-002', 'location_text' => 'A1-02', 'quantity_units' => 700, 'received_at' => '2026-06-10'],
                    ['pallet_code' => 'FR-LECHE-003', 'location_text' => 'A1-03', 'quantity_units' => 300, 'received_at' => '2026-06-12'],
                ],
            ],
            [
                'item' => $edBooks,
                'client' => $edelvives,
                'pallets' => [
                    ['pallet_code' => 'ED-BOOK-001', 'location_text' => 'B2-01', 'quantity_units' => 420, 'received_at' => '2026-06-11'],
                    ['pallet_code' => 'ED-BOOK-002', 'location_text' => 'B2-02', 'quantity_units' => 180, 'received_at' => '2026-06-13'],
                ],
            ],
            [
                'item' => $edKit,
                'client' => $edelvives,
                'pallets' => [
                    ['pallet_code' => 'ED-KIT-001', 'location_text' => 'C3-01', 'quantity_units' => 540, 'received_at' => '2026-06-14'],
                ],
            ],
        ] as $definition) {
            foreach ($definition['pallets'] as $pallet) {
                StockPallet::query()->updateOrCreate(
                    ['pallet_code' => $pallet['pallet_code']],
                    [
                        'client_id' => $definition['client']->id,
                        'item_id' => $definition['item']->id,
                        'location_text' => $pallet['location_text'],
                        'quantity_units' => $pallet['quantity_units'],
                        'received_at' => $pallet['received_at'],
                        'active' => true,
                    ]
                );
            }
        }

        // Garantiza un ejemplo sin stock real para filtros "sin stock".
        $frWater->refresh();
    }

    /**
     * @param  array{sku: string, description: string, lot: string|null, units_per_pallet: int, active: bool}  $attributes
     */
    private function upsertItem(Client $client, array $attributes): Item
    {
        $lotKey = $attributes['lot'] ?? '';

        return Item::query()->updateOrCreate(
            [
                'client_id' => $client->id,
                'sku' => $attributes['sku'],
                'lot_key' => $lotKey,
            ],
            [
                'description' => $attributes['description'],
                'lot' => $attributes['lot'],
                'units_per_pallet' => $attributes['units_per_pallet'],
                'active' => $attributes['active'],
            ]
        );
    }
}
