<?php

namespace App\Services;

use App\Models\BookingInvoice;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Http;

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
