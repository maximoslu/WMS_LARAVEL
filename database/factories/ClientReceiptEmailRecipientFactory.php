<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\ClientReceiptEmailRecipient;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ClientReceiptEmailRecipient>
 */
class ClientReceiptEmailRecipientFactory extends Factory
{
    protected $model = ClientReceiptEmailRecipient::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'email' => fake()->unique()->companyEmail(),
            'name' => fake()->optional()->name(),
        ];
    }
}
