<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Supplier;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Supplier>
 */
class SupplierFactory extends Factory
{
    protected $model = Supplier::class;

    public function definition(): array
    {
        return [
            'client_id' => fake()->boolean(60) ? Client::factory() : null,
            'name' => mb_strtoupper(fake()->company()),
            'tax_id' => strtoupper(fake()->bothify('B########')),
            'email' => fake()->companyEmail(),
            'phone' => fake()->phoneNumber(),
            'contact_name' => fake()->name(),
            'notes' => fake()->optional()->sentence(),
            'active' => true,
        ];
    }
}
