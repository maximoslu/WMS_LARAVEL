<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientPreparationEmailRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientPreparationEmailRecipient>
 */
class ClientPreparationEmailRecipientFactory extends Factory
{
    protected $model = ClientPreparationEmailRecipient::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'email' => fake()->unique()->companyEmail(),
            'name' => fake()->optional()->name(),
        ];
    }
}
