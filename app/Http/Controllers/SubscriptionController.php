<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use App\Services\Ziina;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionController extends Controller
{
    public function __construct(private SubscriptionService $subs)
    {
    }

    /** Current subscription state + prices — drives the FE gate, banner, and /subscribe. */
    public function show(Request $request)
    {
        /** @var Shop $shop */
        $shop = $request->user();
        $sub = $shop->subscription()->first() ?? $this->subs->startTrial($shop);

        return response()->json([
            'status' => $sub->status,
            'plan' => $sub->plan,
            'access_until' => $sub->access_until,
            'trial_ends_at' => $sub->trial_ends_at,
            'days_left' => $sub->daysLeft(),
            'prices' => [
                'monthly' => $this->subs->price('monthly'),
                'annual' => $this->subs->price('annual'),
            ],
        ]);
    }

    /** Start a Ziina one-off payment for a 30- or 365-day pass. */
    public function checkout(Request $request, Ziina $ziina)
    {
        $data = $request->validate(['plan' => 'required|in:monthly,annual']);
        /** @var Shop $shop */
        $shop = $request->user();
        $plan = $data['plan'];
        $amount = $this->subs->price($plan);
        $days = $this->subs->days($plan);

        $payment = SubscriptionPayment::create([
            'shop_id' => $shop->id,
            'plan' => $plan,
            'amount_fils' => $amount,
            'ziina_operation_id' => (string) Str::uuid(),
            'status' => 'pending',
            'period_days' => $days,
        ]);

        $base = rtrim((string) config('services.ziina.admin_return_base'), '/');
        $return = "{$base}/subscribe";

        $intent = $ziina->createSubscriptionIntent($shop, $plan, $amount, [
            'success_url' => "{$return}?pay=success",
            'cancel_url'  => "{$return}?pay=cancel",
            'failure_url' => "{$return}?pay=failed",
        ]);

        $payment->update(['ziina_intent_id' => $intent['id'] ?? null]);

        return response()->json([
            'redirect_url' => $intent['redirect_url'] ?? null,
            'intent_id' => $intent['id'] ?? null,
        ]);
    }
}
