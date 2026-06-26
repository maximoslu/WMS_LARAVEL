<?php

namespace Database\Factories;

use App\Models\Item;
use App\Models\MerchandiseRequest;
use App\Models\MerchandiseRequestLine;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<MerchandiseRequestLine>
 */
class MerchandiseRequestLineFactory extends Factory
{
    protected $model = MerchandiseRequestLine::class;

    public function definition(): array
    {
        $unitsPerPallet = fake()->randomElement([500, 700, 1000]);
        $requestedPallets = fake()->numberBetween(1, 20);

        return [
            'merchandise_request_id' => MerchandiseRequest::factory(),
            'item_id' => Item::factory(),
            'lot' => fake()->optional()->bothify('LOT-###'),
            'requested_pallets' => $requestedPallets,
            'units_per_pallet' => $unitsPerPallet,
            'requested_units' => $requestedPallets * $unitsPerPallet,
            'prepared_pallets' => null,
            'prepared_units' => null,
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
