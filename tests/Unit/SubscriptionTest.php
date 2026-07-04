<?php

namespace Tests\Unit;

use App\Models\Shop;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code = '900001'): Shop
    {
        return Shop::create(['name' => 'T', 'shop_code' => $code, 'status' => 'active']);
    }

    public function test_has_access_is_true_before_expiry_and_false_after(): void
    {
        $sub = new Subscription(['status' => 'active', 'access_until' => now()->addDay()]);
        $this->assertTrue($sub->hasAccess());
        $sub->access_until = now()->subDay();
        $this->assertFalse($sub->hasAccess());
        $sub->access_until = null;
        $this->assertFalse($sub->hasAccess());
    }

    public function test_extend_stacks_from_future_expiry_and_activates(): void
    {
        $shop = $this->shop('900001');
        $sub = Subscription::create([
            'shop_id' => $shop->id, 'status' => 'trialing',
            'trial_ends_at' => now()->addDays(10), 'access_until' => now()->addDays(10),
        ]);
        $sub->extend('monthly', 30);
        $this->assertSame('active', $sub->status);
        $this->assertSame('monthly', $sub->plan);
        // extended from the existing future access_until (10d) + 30d ≈ 40d out
        $this->assertEqualsWithDelta(40, now()->diffInDays($sub->access_until, false), 1);
    }

    public function test_extend_from_now_when_already_expired(): void
    {
        $shop = $this->shop('900002');
        $sub = Subscription::create([
            'shop_id' => $shop->id, 'status' => 'expired', 'access_until' => now()->subDays(5),
        ]);
        $sub->extend('annual', 365);
        $this->assertEqualsWithDelta(365, now()->diffInDays($sub->access_until, false), 1);
    }

    public function test_start_trial_grants_30_days(): void
    {
        $shop = $this->shop('900003');
        $sub = app(\App\Services\SubscriptionService::class)->startTrial($shop);
        $this->assertSame('trialing', $sub->status);
        $this->assertEqualsWithDelta(30, now()->diffInDays($sub->access_until, false), 1);
    }

    public function test_apply_paid_payment_extends_access(): void
    {
        $shop = $this->shop('900004');
        $svc = app(\App\Services\SubscriptionService::class);
        $svc->startTrial($shop);
        $payment = \App\Models\SubscriptionPayment::create([
            'shop_id' => $shop->id, 'plan' => 'monthly', 'amount_fils' => 14900,
            'ziina_operation_id' => \Illuminate\Support\Str::uuid(), 'status' => 'paid', 'period_days' => 30,
        ]);
        $svc->applyPaidPayment($payment);
        $sub = $shop->subscription()->first();
        $this->assertSame('active', $sub->status);
        $this->assertSame('monthly', $sub->plan);
    }
}
