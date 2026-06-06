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

        // Per-account token (tenant brought their own Meta business) falls
        // back to our shared system-user token for numbers under our WABA.
        $token = $account->token ?: config('services.whatsapp.default_token');
        if (!$token) {
            throw new \RuntimeException('WhatsApp send failed: no access token configured');
        }

        $response = Http::withToken($token)
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

    /**
     * Download a media object (voice note, image, ...) from the Cloud API.
     * Returns ['data' => binary, 'mime' => string] or null when unavailable.
     */
    public function downloadMedia(WaAccount $account, string $mediaId): ?array
    {
        $token = $account->token ?: config('services.whatsapp.default_token');
        if (!$token) {
            return null;
        }

        $version = config('services.whatsapp.graph_version', 'v25.0');

        $meta = Http::withToken($token)->get("https://graph.facebook.com/{$version}/{$mediaId}");
        $url = $meta->json('url');
        if (!$meta->successful() || !$url) {
            return null;
        }

        $binary = Http::withToken($token)->timeout(30)->get($url);
        if (!$binary->successful()) {
            return null;
        }

        return [
            'data' => $binary->body(),
            'mime' => $meta->json('mime_type') ?: ($binary->header('Content-Type') ?: 'application/octet-stream'),
        ];
    }
}
