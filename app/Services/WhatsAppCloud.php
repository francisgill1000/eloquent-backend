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
        return $this->postMessage($account, [
            'to' => preg_replace('/\D+/', '', $to),
            'type' => 'text',
            'text' => ['body' => $text],
        ]);
    }

    /**
     * Send a previously-uploaded audio object. OGG/Opus uploads render as a
     * proper voice note in WhatsApp.
     */
    public function sendVoice(WaAccount $account, string $to, string $mediaId): array
    {
        return $this->postMessage($account, [
            'to' => preg_replace('/\D+/', '', $to),
            'type' => 'audio',
            'audio' => ['id' => $mediaId],
        ]);
    }

    /**
     * Upload media bytes to the Cloud API; returns the media id.
     *
     * @throws \RuntimeException when the upload fails
     */
    public function uploadMedia(WaAccount $account, string $bytes, string $mime, string $filename = 'voice.ogg'): string
    {
        $version = config('services.whatsapp.graph_version', 'v25.0');
        $token = $this->token($account);

        $response = Http::withToken($token)
            ->timeout(30)
            ->attach('file', $bytes, $filename, ['Content-Type' => $mime])
            ->post("https://graph.facebook.com/{$version}/{$account->phone_number_id}/media", [
                'messaging_product' => 'whatsapp',
            ]);

        $id = $response->json('id');
        if (!$response->successful() || !$id) {
            $error = $response->json('error.message') ?: "HTTP {$response->status()}";
            throw new \RuntimeException("WhatsApp media upload failed: {$error}");
        }

        return $id;
    }

    /** Shared POST to the Cloud API messages endpoint. */
    private function postMessage(WaAccount $account, array $payload): array
    {
        $version = config('services.whatsapp.graph_version', 'v25.0');
        $token = $this->token($account);

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(
                "https://graph.facebook.com/{$version}/{$account->phone_number_id}/messages",
                ['messaging_product' => 'whatsapp', ...$payload]
            );

        if (!$response->successful()) {
            $error = $response->json('error.message') ?: "HTTP {$response->status()}";
            throw new \RuntimeException("WhatsApp send failed: {$error}");
        }

        return $response->json() ?? [];
    }

    /**
     * Per-account token (tenant brought their own Meta business) falls back
     * to our shared system-user token for numbers under our WABA.
     */
    private function token(WaAccount $account): string
    {
        $token = $account->token ?: config('services.whatsapp.default_token');
        if (!$token) {
            throw new \RuntimeException('WhatsApp send failed: no access token configured');
        }

        return $token;
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
