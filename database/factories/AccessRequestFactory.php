<?php

namespace Database\Factories;

use App\Models\AccessRequest;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<AccessRequest>
 */
class AccessRequestFactory extends Factory
{
    protected $model = AccessRequest::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'company' => fake()->company(),
            'email' => fake()->unique()->safeEmail(),
            'notes' => fake()->sentence(),
            'status' => AccessRequest::STATUS_PENDING,
            'approved_by' => null,
            'approved_at' => null,
            'rejected_by' => null,
            'rejected_at' => null,
            'rejection_reason' => null,
            'user_id' => null,
            'client_id' => null,
        ];
    }
}
