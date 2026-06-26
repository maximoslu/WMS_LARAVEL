<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\GoodsDispatch;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GoodsDispatch>
 */
class GoodsDispatchFactory extends Factory
{
    protected $model = GoodsDispatch::class;

    public function definition(): array
    {
        return [
            'dispatch_number' => null,
            'client_id' => Client::factory(),
            'merchandise_request_id' => null,
            'type' => GoodsDispatch::TYPE_MANUAL,
            'status' => GoodsDispatch::STATUS_DRAFT,
            'created_by' => User::factory(),
            'sent_at' => null,
            'notes' => null,
        ];
    }
}
