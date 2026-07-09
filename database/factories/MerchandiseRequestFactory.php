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
        $client = Client::factory();

        return [
            'client_id' => $client,
            'requested_by' => User::factory(),
            'status' => MerchandiseRequest::STATUS_PENDING,
            'delivery_reference' => null,
            'delivery_address' => null,
            'camion_propio' => false,
            'requested_date' => now()->toDateString(),
            'notes' => null,
            'prepared_by' => null,
            'prepared_at' => null,
            'shipped_by' => null,
            'shipped_at' => null,
            'cancelled_at' => null,
        ];
    }
}
