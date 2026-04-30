<?php

namespace Database\Factories;

use App\Models\Booking;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Booking>
 */
class BookingFactory extends Factory
{
    protected $model = Booking::class;

    public function definition(): array
    {
        return [
            'shop_id' => 1,
            'staff_id' => null,
            'date' => now()->toDateString(),
            'start_time' => '10:00:00',
            'end_time' => '10:30:00',
            'status' => 'booked',
            'device_id' => fake()->uuid(),
            'charges' => 0,
            'services' => [],
        ];
    }

    public function queued(): static
    {
        return $this->state(fn () => ['staff_id' => null, 'status' => 'queued']);
    }
}
