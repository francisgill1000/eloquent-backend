<?php

namespace Database\Factories;

use App\Models\Shop;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Shop>
 */
class ShopFactory extends Factory
{
    protected $model = Shop::class;

    public function definition(): array
    {
        return [
            'name' => fake()->company(),
            'shop_code' => (string) fake()->unique()->numberBetween(100000, 999999),
            'pin' => str_pad((string) fake()->unique()->numberBetween(0, 9999), 4, '0', STR_PAD_LEFT),
            'lat' => fake()->latitude(),
            'lon' => fake()->longitude(),
        ];
    }
}
