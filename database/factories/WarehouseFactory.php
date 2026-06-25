<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Warehouse>
 */
class WarehouseFactory extends Factory
{
    protected $model = Warehouse::class;

    public function definition(): array
    {
        return [
            'client_id' => fake()->boolean(30) ? Client::factory() : null,
            'code' => strtoupper(fake()->bothify('WH-##')),
            'name' => 'Almacen '.fake()->city(),
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
