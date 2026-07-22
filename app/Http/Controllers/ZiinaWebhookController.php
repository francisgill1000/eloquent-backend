<?php

namespace App\Http\Controllers;

use App\Models\BookingInvoice;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class ZiinaWebhookController extends Controller
{
    /**
     * Receive Ziina webhook events. Always returns 200 so Ziina does not
     * retry-storm us; failures are logged. The webhook is the source of
     * truth for marking an invoice paid.
     */
    public function handle(Request $request)
    {
        // A shared secret is required — without it we cannot tell a real
        // Ziina event from a forged "payment completed" POST, so refuse to
        // process rather than silently accepting unsigned requests.
        $secret = config('services.ziina.webhook_secret');
        if (empty($secret)) {
            Log::error('Ziina webhook received but ZIINA_WEBHOOK_SECRET is not configured — rejecting.');
            return response('Webhook not configured', 500);
        }

        $expected = hash_hmac('sha256', $request->getContent(), $secret);
        $signature = (string) $request->header('X-Hmac-Signature');
        if (!hash_equals($expected, $signature)) {
            Log::warning('Ziina webhook signature mismatch', ['ip' => $request->ip()]);
            return response('Forbidden', 403);
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

        // Business Hunt credit-pack purchase. markPaidOnce() is an atomic
        // DB-level claim — safe even if two webhook deliveries for the same
        // intent race each other, not just sequential Ziina retries.
        $purchase = \App\Models\CreditPurchase::where('ziina_intent_id', $intentId)->first();
        if ($purchase && $purchase->shop && $purchase->markPaidOnce()) {
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
