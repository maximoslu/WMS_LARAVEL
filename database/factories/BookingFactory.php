<?php

namespace Database\Factories;

use App\Models\Booking;
use App\Models\Client;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $client = Client::factory();

        return [
            'client_id' => $client,
            'requested_by' => User::factory(),
            'assigned_to' => null,
            'approved_by' => null,
            'cancelled_by' => null,
            'warehouse_id' => null,
            'booking_code' => null,
            'type' => fake()->randomElement(Booking::types()),
            'status' => Booking::STATUS_REQUESTED,
            'scheduled_date' => now()->addDays(fake()->numberBetween(0, 10))->toDateString(),
            'scheduled_time_from' => '09:00:00',
            'scheduled_time_to' => '10:00:00',
            'contact_name' => fake()->name(),
            'contact_phone' => fake()->phoneNumber(),
            'carrier_name' => fake()->company(),
            'vehicle_plate' => strtoupper(fake()->bothify('####???')),
            'driver_name' => fake()->name(),
            'pallets_expected' => fake()->numberBetween(1, 33),
            'notes' => fake()->sentence(),
            'internal_notes' => null,
            'origin_destination' => fake()->city(),
            'document_reference' => fake()->optional()->bothify('DOC-###'),
            'loading_dock' => fake()->optional()->randomElement(['Muelle 1', 'Muelle 2', 'Muelle 3']),
            'google_calendar_event_id' => null,
            'google_calendar_synced_at' => null,
            'approved_at' => null,
            'cancelled_at' => null,
        ];
    }
}
