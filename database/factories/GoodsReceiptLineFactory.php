<?php

namespace Database\Factories;

use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptLine;
use App\Models\Item;
use App\Models\Location;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsReceiptLine>
 */
class GoodsReceiptLineFactory extends Factory
{
    protected $model = GoodsReceiptLine::class;

    public function definition(): array
    {
        $quantity = fake()->numberBetween(100, 3000);
        $unitsPerPallet = fake()->randomElement([500, 700, 1000]);

        return [
            'goods_receipt_id' => GoodsReceipt::factory(),
            'item_id' => Item::factory(),
            'sku' => strtoupper(fake()->bothify('SKU-####')),
            'description' => fake()->sentence(4),
            'lot' => fake()->optional()->bothify('LOT-###'),
            'quantity_units' => $quantity,
            'units_per_pallet' => $unitsPerPallet,
            'pallet_count' => intdiv($quantity, $unitsPerPallet),
            'pico_units' => $quantity % $unitsPerPallet,
            'location_id' => Location::factory(),
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
