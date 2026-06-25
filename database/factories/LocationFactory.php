<?php

namespace Database\Factories;

use App\Models\Location;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Location>
 */
class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'warehouse_id' => Warehouse::factory(),
            'code' => strtoupper(fake()->bothify('A#-##')),
            'name' => fake()->optional()->words(2, true),
            'zone' => fake()->optional()->randomElement(['PICKING', 'BULK', 'RESERVA']),
            'aisle' => fake()->optional()->bothify('A#'),
            'rack' => fake()->optional()->numerify('##'),
            'level' => fake()->optional()->randomElement(['01', '02', '03']),
            'position' => fake()->optional()->randomElement(['01', '02', '03']),
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
