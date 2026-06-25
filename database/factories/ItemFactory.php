<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Item;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Item>
 */
class ItemFactory extends Factory
{
    protected $model = Item::class;

    public function definition(): array
    {
        $lot = fake()->boolean(60) ? strtoupper(fake()->bothify('LOT-###')) : null;

        return [
            'client_id' => Client::factory(),
            'sku' => strtoupper(fake()->bothify('SKU-####')),
            'description' => fake()->sentence(4),
            'lot' => $lot,
            'lot_key' => $lot ?? '',
            'units_per_pallet' => fake()->numberBetween(1, 1500),
            'active' => true,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'active' => false,
        ]);
    }
}
