<?php

namespace Database\Factories;

use App\Models\Shop;
use App\Services\SubscriptionService;
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

    /**
     * Give the shop an active 30-day trial subscription — the same access a
     * real shop gets at registration via SubscriptionService::startTrial().
     * Use this for tests hitting subscription-gated routes (the whole-app
     * paywall) that aren't themselves exercising subscription states.
     */
    public function trialing(): static
    {
        return $this->afterCreating(function (Shop $shop) {
            $shop->subscription()->create([
                'status' => 'trialing',
                'plan' => null,
                'trial_ends_at' => now()->addDays(SubscriptionService::TRIAL_DAYS),
                'access_until' => now()->addDays(SubscriptionService::TRIAL_DAYS),
            ]);
        });
    }
}
