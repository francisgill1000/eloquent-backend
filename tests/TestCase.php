<?php

namespace Tests;

use App\Models\Shop;
use App\Services\SubscriptionService;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // CurrentShopUser is a static holder populated by SetRbacContext during a
        // request; it survives between tests in the same process. Reset it so no
        // test inherits a prior HTTP test's acting user (keeps the suite
        // order-independent — a direct tool/service call sees a clean context).
        \App\Support\CurrentShopUser::set(null);
    }

    /**
     * Give a shop the active 30-day trial that every real shop receives at
     * registration, so it clears the whole-app subscription paywall
     * (subscription.active). Use in tests that aren't exercising subscription
     * states but hit gated routes with a plain Shop::create() shop.
     */
    protected function startTrial(Shop $shop): Shop
    {
        app(SubscriptionService::class)->startTrial($shop);

        return $shop;
    }
}
