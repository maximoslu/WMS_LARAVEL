<?php

namespace Database\Factories;

use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestEvent;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchandiseRequestEvent>
 */
class MerchandiseRequestEventFactory extends Factory
{
    protected $model = MerchandiseRequestEvent::class;

    public function definition(): array
    {
        return [
            'merchandise_request_id' => MerchandiseRequest::factory(),
            'user_id' => User::factory(),
            'event_type' => 'created',
            'title' => 'Pedido creado',
            'description' => fake()->optional()->sentence(),
        ];
    }
}
