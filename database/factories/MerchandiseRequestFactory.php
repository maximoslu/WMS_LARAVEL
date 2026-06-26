<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\MerchandiseRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchandiseRequest>
 */
class MerchandiseRequestFactory extends Factory
{
    protected $model = MerchandiseRequest::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'requested_by' => User::factory(),
            'status' => MerchandiseRequest::STATUS_CREATED,
            'delivery_reference' => strtoupper(fake()->bothify('REQ-#####')),
            'delivery_address' => fake()->address(),
            'requested_date' => fake()->date(),
            'notes' => fake()->optional()->sentence(),
            'prepared_by' => null,
            'prepared_at' => null,
            'shipped_by' => null,
            'shipped_at' => null,
            'cancelled_at' => null,
        ];
    }
}
