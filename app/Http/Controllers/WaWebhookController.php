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

        return response()->json(['persona' => $isCustomer ? 'customer' : 'lead']);
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
        ]);

        $account = WaAccount::where('phone_number_id', $data['phone_number_id'])->first();
        if (!$account) {
            return response()->json(['status' => 'ignored']);
        }

        $waMessageId = $data['wa_message_id'] ?? null;
        if ($waMessageId && WaMessage::where('wa_message_id', $waMessageId)->exists()) {
            return response()->json(['status' => 'duplicate']);
        }

        $contact = WaContact::firstOrCreate([
            'wa_account_id' => $account->id,
            'wa_number' => preg_replace('/\D+/', '', $data['to']),
        ]);
        $contact->recordMessage('out', $data['text'], 'text', $waMessageId, 'sent');

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

            $contact = WaContact::firstOrCreate(
                ['wa_account_id' => $account->id, 'wa_number' => $from],
                ['name' => $profileNames[$from] ?? null]
            );

            $profileName = $profileNames[$from] ?? null;
            if ($profileName && $contact->name !== $profileName) {
                $contact->update(['name' => $profileName]);
            }

            $contact->recordMessage('in', $body, $type, $waMessageId);
        }
    }
}
