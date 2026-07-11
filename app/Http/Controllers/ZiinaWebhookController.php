<?php

namespace App\Http\Controllers;

use App\Models\BookingInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZiinaWebhookController extends Controller
{
    /** IPs Ziina sends webhooks from (docs.ziina.com). */
    private const TRUSTED_IPS = [
        '3.29.184.186',
        '3.29.190.95',
        '20.233.47.127',
        '13.202.161.181',
    ];

    /**
     * Receive Ziina webhook events. Always returns 200 so Ziina does not
     * retry-storm us; failures are logged. The webhook is the source of
     * truth for marking an invoice paid.
     */
    public function handle(Request $request)
    {
        // Verify the HMAC signature when a shared secret is configured.
        $secret = config('services.ziina.webhook_secret');
        if (!empty($secret)) {
            $expected = hash_hmac('sha256', $request->getContent(), $secret);
            $signature = (string) $request->header('X-Hmac-Signature');
            if (!hash_equals($expected, $signature)) {
                Log::warning('Ziina webhook signature mismatch', ['ip' => $request->ip()]);
                return response('Forbidden', 403);
            }
        }

        $payload = $request->all();
        $event = $payload['event'] ?? null;
        $data = $payload['data'] ?? [];

        try {
            if ($event === 'payment_intent.status.updated') {
                $this->handlePaymentIntent($data);
            }
        } catch (\Throwable $e) {
            Log::error('Ziina webhook processing failed: ' . $e->getMessage(), ['payload' => $payload]);
        }

        return response()->json(['status' => 'ok']);
    }

    private function handlePaymentIntent(array $data): void
    {
        $intentId = $data['id'] ?? null;
        $status = $data['status'] ?? null;

        if (!$intentId || $status !== 'completed') {
            return; // only completed payments flip an invoice to paid
        }

        // A completed intent belongs to either a booking invoice or a
        // subscription payment. Try both (they never collide on intent id).
        $invoice = BookingInvoice::where('ziina_intent_id', $intentId)->first();
        if ($invoice) {
            $invoice->markPaid(); // idempotent
        }

        $subPayment = \App\Models\SubscriptionPayment::where('ziina_intent_id', $intentId)->first();
        if ($subPayment && $subPayment->status !== 'paid') {
            $subPayment->update(['status' => 'paid', 'paid_at' => now()]);
            app(\App\Services\SubscriptionService::class)->applyPaidPayment($subPayment);
        }

        // Business Hunt credit-pack purchase. The status guard makes this
        // idempotent — Ziina retries webhooks, but credits are granted once.
        $purchase = \App\Models\CreditPurchase::where('ziina_intent_id', $intentId)->first();
        if ($purchase && $purchase->status !== 'paid' && $purchase->shop) {
            $purchase->update(['status' => 'paid', 'paid_at' => now()]);
            app(\App\Services\Credits\HuntCreditService::class)->grant(
                $purchase->shop,
                (int) $purchase->credits,
                'purchase',
                [
                    'pack_id' => $purchase->pack_id,
                    'purchase_id' => $purchase->id,
                    'via' => 'ziina',
                    'simulated' => false,
                ],
            );
        }

        if (!$invoice && !$subPayment && !$purchase) {
            Log::warning("Ziina webhook for unknown intent {$intentId}");
        }
    }
}
