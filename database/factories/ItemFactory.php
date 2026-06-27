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
        return [
            'client_id' => Client::factory(),
            'sku' => strtoupper(fake()->bothify('SKU-####')),
            'description' => fake()->sentence(4),
            'lot' => null,
            'lot_key' => '',
            'units_per_pallet' => fake()->numberBetween(1, 1500),
            'active' => true,
            'status' => Item::STATUS_ACTIVE,
            'default_location_id' => null,
        ];
    }

    public function inactive(): static
    {
        return $this->state(fn () => [
            'active' => false,
            'status' => Item::STATUS_BLOCKED,
        ]);
    }

    public function blocked(): static
    {
        return $this->state(fn () => [
            'active' => false,
            'status' => Item::STATUS_BLOCKED,
        ]);
    }

    public function obsolete(): static
    {
        return $this->state(fn () => [
            'active' => false,
            'status' => Item::STATUS_OBSOLETE,
        ]);
    }
}
