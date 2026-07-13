<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientDispatchEmailRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientDispatchEmailRecipient>
 */
class ClientDispatchEmailRecipientFactory extends Factory
{
    protected $model = ClientDispatchEmailRecipient::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'email' => fake()->unique()->companyEmail(),
            'name' => fake()->optional()->name(),
        ];
    }
}
