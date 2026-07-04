<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ShopUser>
 */
class ShopUserFactory extends Factory
{
    protected $model = ShopUser::class;

    public function definition(): array
    {
        return [
            'shop_id' => Shop::factory(),
            'name' => $this->faker->name(),
            'login_pin' => (string) $this->faker->unique()->numberBetween(1000, 9999),
            'is_active' => true,
        ];
    }
}
