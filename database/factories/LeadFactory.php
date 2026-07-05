<?php

namespace Database\Factories;

use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Lead>
 */
class LeadFactory extends Factory
{
    protected $model = Lead::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'name' => fake()->company(),
            'phone' => '05' . fake()->numberBetween(0, 9) . fake()->numerify('#######'),
            'website' => fake()->optional()->url(),
            'address' => fake()->streetAddress(),
            'category' => fake()->randomElement(['beauty_salon', 'hair_care', 'spa']),
            'lat' => fake()->latitude(24, 26),
            'lng' => fake()->longitude(54, 56),
            'source' => 'google_places',
            'external_ref' => 'place_' . fake()->unique()->bothify('????####'),
            'status' => 'new',
        ];
    }
}
