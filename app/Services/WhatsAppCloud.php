<?php

namespace App\Services;

use App\Models\WaAccount;
use Illuminate\Support\Facades\Http;

class WhatsAppCloud
{
    /**
     * Send a plain text message via the WhatsApp Cloud API.
     *
     * @throws \RuntimeException when the Graph API rejects the request
     */
    public function sendText(WaAccount $account, string $to, string $text): array
    {
        $version = config('services.whatsapp.graph_version', 'v25.0');

        $response = Http::withToken($account->token)
            ->acceptJson()
            ->post("https://graph.facebook.com/{$version}/{$account->phone_number_id}/messages", [
                'messaging_product' => 'whatsapp',
                'to' => preg_replace('/\D+/', '', $to),
                'type' => 'text',
                'text' => ['body' => $text],
            ]);

        if (!$response->successful()) {
            $error = $response->json('error.message') ?: "HTTP {$response->status()}";
            throw new \RuntimeException("WhatsApp send failed: {$error}");
        }

        return $response->json() ?? [];
    }
}
