<?php

namespace Database\Factories;

use App\Models\BookingInvoice;
use Illuminate\Database\Eloquent\Factories\Factory;

class BookingInvoiceFactory extends Factory
{
    protected $model = BookingInvoice::class;

    public function definition(): array
    {
        return [
            'booking_id'    => 1,
            'subtotal'      => 100,
            'total'         => 100,
            'status'        => 'issued',
            'issued_at'     => now(),
        ];
    }

    public function paid(): static
    {
        return $this->state(fn () => ['status' => 'paid', 'paid_at' => now()]);
    }
}
