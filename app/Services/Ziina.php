<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\BookingInvoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Thin wrapper over the Ziina payment API (https://docs.ziina.com).
 *
 * Single platform-level account: one Bearer token from config('services.ziina.api_key').
 */
class Ziina
{
    private function client(): PendingRequest
    {
        $key = config('services.ziina.api_key');

        if (empty($key)) {
            throw new \RuntimeException('Ziina is not configured (ZIINA_API_KEY missing).');
        }

        return Http::withToken($key)
            ->baseUrl(rtrim((string) config('services.ziina.base_url'), '/'))
            ->acceptJson()
            ->timeout(20);
    }

    /**
     * Create a payment intent for an invoice's full total (AED).
     *
     * @param array{success_url:string,cancel_url:string,failure_url:string} $urls
     * @return array Ziina response (includes `id`, `redirect_url`, `status`).
     */
    public function createIntent(BookingInvoice $invoice, array $urls): array
    {
        // Ziina amounts are in the minor unit (fils): 10.50 AED -> 1050.
        $amount = (int) round(((float) $invoice->total) * 100);

        $response = $this->client()->post('/payment_intent', [
            'amount'        => $amount,
            'currency_code' => 'AED',
            'test'          => (bool) config('services.ziina.test'),
            'message'       => "Payment for invoice {$invoice->invoice_number}",
            'operation_id'  => $invoice->ziina_operation_id,
            'success_url'   => $urls['success_url'],
            'cancel_url'    => $urls['cancel_url'],
            'failure_url'   => $urls['failure_url'],
        ]);

        $response->throw();

        return $response->json();
    }

    /**
     * Ensure an issued invoice exists for the booking, create a Ziina payment
     * intent for its total, and return the hosted-page link. Shared by the web
     * pay endpoint and the chat bot.
     *
     * Returns:
     *   ['ok' => true,  'url' => ..., 'intent_id' => ..., 'status' => ..., 'invoice' => BookingInvoice]
     *   ['ok' => false, 'reason' => 'cancelled'|'paid'|'invalid_status'|'below_minimum'|'error', 'message' => ...]
     */
    public function paymentLinkForBooking(Booking $booking): array
    {
        if ($booking->status === 'cancelled') {
            return ['ok' => false, 'reason' => 'cancelled', 'message' => 'Booking is cancelled.'];
        }

        // Pay-first: a freshly-made booking has no invoice yet — issue one from
        // the booking total so payment can confirm it.
        $invoice = $booking->invoice ?: BookingInvoice::create([
            'booking_id' => $booking->id,
            'subtotal'   => $booking->charges ?? 0,
            'total'      => $booking->charges ?? 0,
            'status'     => 'issued',
            'issued_at'  => now(),
        ]);

        if ($invoice->status === 'paid') {
            return ['ok' => false, 'reason' => 'paid', 'message' => 'Invoice is already paid.'];
        }
        if ($invoice->status !== 'issued') {
            return ['ok' => false, 'reason' => 'invalid_status', 'message' => "Invoice is {$invoice->status}."];
        }

        // Ziina rejects transfers under 2 AED.
        if ((float) $invoice->total < 2) {
            return ['ok' => false, 'reason' => 'below_minimum', 'message' => 'Amount is below the 2 AED minimum for online payment.'];
        }

        // Stable idempotency key — one per invoice, so retries never double-charge.
        if (empty($invoice->ziina_operation_id)) {
            $invoice->update(['ziina_operation_id' => (string) Str::uuid()]);
        }

        $base = rtrim((string) config('services.ziina.return_base'), '/');
        $return = "{$base}/booking/{$booking->id}";

        try {
            $intent = $this->createIntent($invoice, [
                'success_url' => "{$return}?pay=success",
                'cancel_url'  => "{$return}?pay=cancel",
                'failure_url' => "{$return}?pay=failed",
            ]);
        } catch (\Throwable $e) {
            Log::error('Ziina createIntent failed: ' . $e->getMessage(), ['invoice_id' => $invoice->id]);
            return ['ok' => false, 'reason' => 'error', 'message' => 'Could not start payment. Please try again.'];
        }

        $invoice->update(['ziina_intent_id' => $intent['id'] ?? null]);

        return [
            'ok'        => true,
            'url'       => $intent['redirect_url'] ?? null,
            'intent_id' => $intent['id'] ?? null,
            'status'    => $intent['status'] ?? null,
            'invoice'   => $invoice,
        ];
    }

    /** Retrieve a payment intent by id (used to verify status on return). */
    public function getIntent(string $id): array
    {
        $response = $this->client()->get("/payment_intent/{$id}");
        $response->throw();

        return $response->json();
    }

    /**
     * Register (overwrite) the account webhook URL.
     * Pass a secret to have Ziina sign requests with X-Hmac-Signature.
     */
    public function registerWebhook(string $url, ?string $secret = null): array
    {
        $payload = ['url' => $url];
        if (!empty($secret)) {
            $payload['secret'] = $secret;
        }

        $response = $this->client()->post('/webhook', $payload);
        $response->throw();

        return $response->json();
    }
}
