<?php

namespace Database\Factories;

use App\Models\Client;
use App\Models\Item;
use App\Models\Location;
use App\Models\StockPallet;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<StockPallet>
 */
class StockPalletFactory extends Factory
{
    protected $model = StockPallet::class;

    public function definition(): array
    {
        return [
            'client_id' => Client::factory(),
            'item_id' => Item::factory(),
            'goods_receipt_id' => null,
            'location_id' => null,
            'location_text' => fake()->optional()->bothify('PAS-## / HUE-##'),
            'pallet_code' => strtoupper(fake()->bothify('PAL-#####')),
            'quantity_units' => fake()->numberBetween(1, 1500),
            'received_at' => fake()->optional()->date(),
            'active' => true,
        ];
    }

    public function configure(): static
    {
        return $this->afterMaking(function (StockPallet $stockPallet): void {
            $item = $stockPallet->item;

            if ($item === null) {
                $client = $stockPallet->client ?? Client::factory()->create();
                $item = Item::factory()->create([
                    'client_id' => $client->id,
                ]);

                $stockPallet->setRelation('client', $client);
                $stockPallet->setRelation('item', $item);
                $stockPallet->item_id = $item->id;
            }

            $stockPallet->client_id = $item->client_id;

            if ($stockPallet->location_id !== null) {
                $location = $stockPallet->location ?? Location::query()->find($stockPallet->location_id);
                if ($location !== null) {
                    $stockPallet->setRelation('location', $location);
                    $stockPallet->location_text = $location->code;
                }
            }
        })->afterCreating(function (StockPallet $stockPallet): void {
            if ($stockPallet->client_id !== $stockPallet->item->client_id) {
                $stockPallet->forceFill([
                    'client_id' => $stockPallet->item->client_id,
                ])->save();
            }
        });
    }
}
