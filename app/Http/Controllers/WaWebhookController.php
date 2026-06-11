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

    /**
     * Tell the auto-reply bot who it's talking to: 'customer' if the sender
     * is a known customer (has booked) of the shop owning this WhatsApp
     * number, otherwise 'lead'. Drives the bot's persona on its own number.
     */
    public function persona(Request $request)
    {
        $secret = config('services.whatsapp.relay_secret');
        abort_unless(
            $secret && hash_equals($secret, (string) $request->header('X-Relay-Secret')),
            403
        );

        $data = $request->validate([
            'phone_number_id' => ['required', 'string'],
            'number' => ['required', 'string'],
        ]);

        $account = \App\Models\WaAccount::where('phone_number_id', $data['phone_number_id'])->first();
        $normalized = \App\Models\ShopCustomer::normalize($data['number']);

        if (!$account || $normalized === '') {
            return response()->json(['persona' => 'lead']);
        }

        $tail = strlen($normalized) > 9 ? substr($normalized, -9) : $normalized;
        $isCustomer = \App\Models\ShopCustomer::where('shop_id', $account->shop_id)
            ->where('whatsapp_normalized', 'LIKE', '%' . $tail)
            ->exists();

        if (!$isCustomer) {
            return response()->json(['persona' => 'lead']);
        }

        $shop = $account->shop;

        return response()->json([
            'persona' => 'customer',
            'shop_name' => $shop?->name,
            'category' => \App\Support\ServiceCategories::name($shop?->category_id),
        ]);
    }

    /**
     * Find an existing provider account by phone (last-9-digit match), so the
     * onboarding bot resends credentials instead of creating duplicates.
     */
    public function shopByPhone(Request $request)
    {
        $secret = config('services.whatsapp.relay_secret');
        abort_unless(
            $secret && hash_equals($secret, (string) $request->header('X-Relay-Secret')),
            403
        );

        $digits = preg_replace('/\D+/', '', (string) $request->query('number'));
        if (strlen($digits) < 7) {
            return response()->json(['exists' => false]);
        }
        $tail = substr($digits, -9);

        $shop = \App\Models\Shop::whereNotNull('phone')
            ->get(['id', 'name', 'phone', 'shop_code', 'pin'])
            ->first(function ($s) use ($tail) {
                $p = preg_replace('/\D+/', '', (string) $s->phone);
                return $p !== '' && substr($p, -9) === $tail;
            });

        if (!$shop) {
            return response()->json(['exists' => false]);
        }

        return response()->json([
            'exists' => true,
            'name' => $shop->name,
            'shop_code' => $shop->shop_code,
            'pin' => $shop->pin,
        ]);
    }

    /**
     * Hand the auto-reply bot a shop's send context by phone_number_id:
     * shop name, category, and the decrypted access token (falling back to
     * the shared system token). Lets the bot reply as any tenant shop.
     * Secured by the shared relay secret.
     */
    public function shopContext(Request $request)
    {
        $secret = config('services.whatsapp.relay_secret');
        abort_unless(
            $secret && hash_equals($secret, (string) $request->header('X-Relay-Secret')),
            403
        );

        $data = $request->validate([
            'phone_number_id' => ['required', 'string'],
        ]);

        $account = WaAccount::where('phone_number_id', $data['phone_number_id'])->first();
        if (!$account) {
            return response()->json(['found' => false]);
        }

        $shop = $account->shop;

        return response()->json([
            'found' => true,
            'shop_name' => $shop?->name,
            'category' => \App\Support\ServiceCategories::name($shop?->category_id),
            'persona' => $shop?->persona,
            'phone_number_id' => $account->phone_number_id,
            'token' => $account->token ?: config('services.whatsapp.default_token'),
        ]);
    }

    /**
     * Hand the auto-reply bot the active sales-number prompt. Returns
     * override=false when the default ("Sales Bot") is active — the bot then
     * uses its own local sales prompt and normal persona logic. Returns
     * override=true + the body when a custom prompt is active, so the bot
     * replies with that persona for everyone on the sales number.
     * Secured by the shared relay secret.
     */
    public function salesPrompt(Request $request)
    {
        $secret = config('services.whatsapp.relay_secret');
        abort_unless(
            $secret && hash_equals($secret, (string) $request->header('X-Relay-Secret')),
            403
        );

        $active = \App\Models\BotPrompt::where('is_active', true)->first();

        if (!$active || $active->is_default) {
            return response()->json(['override' => false]);
        }

        return response()->json([
            'override' => true,
            'name' => $active->name,
            'body' => $active->body,
        ]);
    }

    /**
     * Attach a voice-note transcript (from the bot's Whisper pass) to an
     * already-stored inbound message, so chats show what was said.
     */
    public function relayTranscript(Request $request)
    {
        $secret = config('services.whatsapp.relay_secret');
        abort_unless(
            $secret && hash_equals($secret, (string) $request->header('X-Relay-Secret')),
            403
        );

        $data = $request->validate([
            'wa_message_id' => ['required', 'string'],
            'transcript' => ['required', 'string', 'max:10000'],
        ]);

        $message = WaMessage::where('wa_message_id', $data['wa_message_id'])->first();
        if (!$message) {
            return response()->json(['status' => 'ignored']);
        }

        $message->update(['body' => '🎤 ' . $data['transcript']]);

        $contact = $message->waContact;
        if ($contact && $contact->messages()->max('id') === $message->id) {
            $contact->update(['last_message_preview' => mb_substr($message->body, 0, 500)]);
        }

        return response()->json(['status' => 'ok']);
    }

    /**
     * Record an outgoing message sent by the standalone auto-reply bot, so
     * bizrezzy chat threads show both sides. Secured by a shared secret.
     */
    public function relayOut(Request $request)
    {
        $secret = config('services.whatsapp.relay_secret');
        abort_unless(
            $secret && hash_equals($secret, (string) $request->header('X-Relay-Secret')),
            403
        );

        $data = $request->validate([
            'phone_number_id' => ['required', 'string'],
            'to' => ['required', 'string'],
            'text' => ['required', 'string'],
            'wa_message_id' => ['nullable', 'string'],
            'type' => ['nullable', 'string', 'max:20'],
            'media_id' => ['nullable', 'string'],
        ]);

        $account = WaAccount::where('phone_number_id', $data['phone_number_id'])->first();
        if (!$account) {
            return response()->json(['status' => 'ignored']);
        }

        $waMessageId = $data['wa_message_id'] ?? null;
        if ($waMessageId && WaMessage::where('wa_message_id', $waMessageId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        // Bot voice replies carry the uploaded audio's media id — download and
        // keep it so the chat thread can play the bot's voice note too.
        $media = [];
        if (!empty($data['media_id'])) {
            $media['media_id'] = $data['media_id'];
            $media['media_path'] = $this->storeMedia($account, $data['media_id'], null);
        }

        $contact = WaContact::firstOrCreate([
            'wa_account_id' => $account->id,
            'wa_number' => preg_replace('/\D+/', '', $data['to']),
        ]);
        $contact->recordMessage('out', $data['text'], $data['type'] ?? 'text', $waMessageId, 'sent', $media);

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
