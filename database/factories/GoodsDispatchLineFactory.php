<?php

namespace Database\Factories;

use App\Models\GoodsDispatch;
use App\Models\GoodsDispatchLine;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsDispatchLine>
 */
class GoodsDispatchLineFactory extends Factory
{
    protected $model = GoodsDispatchLine::class;

    public function definition(): array
    {
        $item = Item::factory();

        return [
            'goods_dispatch_id' => GoodsDispatch::factory(),
            'item_id' => $item,
            'sku' => strtoupper(fake()->bothify('SKU-####')),
            'description' => fake()->sentence(4),
            'lot' => fake()->boolean() ? strtoupper(fake()->bothify('LOT-###')) : null,
            'units_per_pallet' => fake()->numberBetween(1, 1500),
            'pallets' => fake()->numberBetween(1, 12),
            'requested_units' => fake()->numberBetween(1, 15000),
            'notes' => null,
        ];
    }
}
