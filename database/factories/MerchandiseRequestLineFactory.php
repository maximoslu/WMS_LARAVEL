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
        return [
            'merchandise_request_id' => MerchandiseRequest::factory(),
            'item_id' => Item::factory(),
            'stock_pallet_id' => null,
            'line_type' => 'pallet',
            'stock_peak_index' => null,
            'lot' => fake()->boolean(60) ? strtoupper(fake()->bothify('LOT-###')) : null,
            'units_per_pallet' => fake()->numberBetween(1, 1500),
            'units_per_peak' => null,
            'requested_pallets' => fake()->numberBetween(1, 12),
            'requested_peaks' => 0,
            'requested_units' => fake()->numberBetween(1, 12000),
            'prepared_pallets' => null,
            'prepared_peaks' => null,
            'prepared_units' => null,
            'notes' => null,
        ];
    }
}
