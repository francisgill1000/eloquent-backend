<?php

namespace App\Http\Controllers;

use App\Models\WaAccount;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WaWebhookController extends Controller
{
    /**
     * Meta webhook verification handshake.
     * PHP converts "hub.mode" query keys to "hub_mode".
     */
    public function verify(Request $request)
    {
        $mode = $request->query('hub_mode');
        $token = $request->query('hub_verify_token');
        $challenge = $request->query('hub_challenge');

        if ($mode === 'subscribe' && $token && $token === config('services.whatsapp.verify_token')) {
            return response($challenge, 200)->header('Content-Type', 'text/plain');
        }

        return response('Forbidden', 403);
    }

    /**
     * Receive incoming messages. Routed to the owning shop by
     * entry[].changes[].value.metadata.phone_number_id.
     * Always returns 200 so Meta does not retry-storm us.
     */
    public function receive(Request $request)
    {
        // Verify Meta's webhook signature when an app secret is configured
        // (no-op when unset — parity with the retired Node service).
        $secret = config('services.whatsapp.app_secret');
        if ($secret) {
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
            $signature = (string) $request->header('X-Hub-Signature-256');
            if (!hash_equals($expected, $signature)) {
                return response('Forbidden', 403);
            }
        }

        $payload = $request->all();

        try {
            foreach (($payload['entry'] ?? []) as $entry) {
                foreach (($entry['changes'] ?? []) as $change) {
                    $this->handleChange($change['value'] ?? []);
                }
            }
        } catch (\Throwable $e) {
            Log::error('WA webhook processing failed: ' . $e->getMessage(), ['payload' => $payload]);
        }

        return response()->json(['status' => 'ok']);
    }

    private function handleChange(array $value): void
    {
        $phoneNumberId = $value['metadata']['phone_number_id'] ?? null;
        $messages = $value['messages'] ?? [];
        if (!$phoneNumberId || empty($messages)) {
            return; // status callbacks, etc.
        }

        $account = WaAccount::where('phone_number_id', $phoneNumberId)->first();
        if (!$account) {
            Log::warning("WA webhook for unknown phone_number_id {$phoneNumberId}");
            return;
        }

        // Map wa_id -> profile name from the contacts block
        $profileNames = [];
        foreach (($value['contacts'] ?? []) as $c) {
            if (!empty($c['wa_id'])) {
                $profileNames[$c['wa_id']] = $c['profile']['name'] ?? null;
            }
        }

        foreach ($messages as $msg) {
            $from = $msg['from'] ?? null;
            if (!$from) {
                continue;
            }

            $waMessageId = $msg['id'] ?? null;
            if ($waMessageId && WaMessage::where('wa_message_id', $waMessageId)->exists()) {
                continue; // Meta retried delivery — already stored
            }

            $type = $msg['type'] ?? 'text';
            $body = $type === 'text'
                ? ($msg['text']['body'] ?? '')
                : "[{$type} message]";

            // Media messages (voice notes, images, ...) carry a media id we
            // can download and keep, so chats can play them later.
            $media = [];
            $mediaObject = is_array($msg[$type] ?? null) ? $msg[$type] : null;
            if ($mediaObject && !empty($mediaObject['id'])) {
                $media['media_id'] = $mediaObject['id'];
                $media['media_mime'] = $mediaObject['mime_type'] ?? null;
                $media['media_path'] = $this->storeMedia($account, $mediaObject['id'], $media['media_mime']);
            }

            $contact = WaContact::firstOrCreate(
                ['wa_account_id' => $account->id, 'wa_number' => $from],
                ['name' => $profileNames[$from] ?? null]
            );

            $profileName = $profileNames[$from] ?? null;
            if ($profileName && $contact->name !== $profileName) {
                $contact->update(['name' => $profileName]);
            }

            $stored = $contact->recordMessage('in', $body, $type, $waMessageId, null, $media);

            // Auto-reply runs in the background — the webhook ACKs instantly.
            // Meta retries never reach here (wa_message_id dedupe above), so
            // a message is dispatched exactly once.
            \App\Jobs\ProcessWaReply::dispatch($stored->id);
        }
    }

    /** Download and persist a media object; returns the public-disk path or null. */
    private function storeMedia(WaAccount $account, string $mediaId, ?string $mime): ?string
    {
        try {
            $download = (new \App\Services\WhatsAppCloud())->downloadMedia($account, $mediaId);
            if (!$download) {
                return null;
            }

            $mime = $download['mime'] ?: $mime ?: '';
            $ext = match (true) {
                str_contains($mime, 'ogg') => 'ogg',
                str_contains($mime, 'mpeg') => 'mp3',
                str_contains($mime, 'mp4') => 'mp4',
                str_contains($mime, 'aac') => 'aac',
                str_contains($mime, 'amr') => 'amr',
                str_contains($mime, 'jpeg') => 'jpg',
                str_contains($mime, 'png') => 'png',
                str_contains($mime, 'webp') => 'webp',
                str_contains($mime, 'pdf') => 'pdf',
                default => 'bin',
            };

            $path = "wa-media/{$account->id}/{$mediaId}.{$ext}";
            \Illuminate\Support\Facades\Storage::disk('public')->put($path, $download['data']);

            return $path;
        } catch (\Throwable $e) {
            Log::warning("WA media download failed for {$mediaId}: " . $e->getMessage());
            return null;
        }
    }
}
