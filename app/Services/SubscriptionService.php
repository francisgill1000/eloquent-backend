<?php

namespace App\Services;

use App\Models\Pricing;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;

class SubscriptionService
{
    private const DAYS = ['monthly' => 30, 'annual' => 365];

    public const TRIAL_DAYS = 30;

    public function days(string $plan): int
    {
        return self::DAYS[$plan] ?? 0;
    }

    public function price(string $plan): int
    {
        return Pricing::fils($plan);
    }

    public function startTrial(Shop $shop): Subscription
    {
        return Subscription::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'status' => 'trialing',
                'plan' => null,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
                'access_until' => now()->addDays(self::TRIAL_DAYS),
            ],
        );
    }

    /**
     * Idempotent extension for a paid payment. The caller (webhook) guards
     * against processing the same payment row twice.
     */
    public function applyPaidPayment(SubscriptionPayment $payment): void
    {
        $sub = Subscription::firstOrCreate(
            ['shop_id' => $payment->shop_id],
            ['status' => 'expired', 'access_until' => now()],
        );
        $sub->extend($payment->plan, $payment->period_days);
    }
}
