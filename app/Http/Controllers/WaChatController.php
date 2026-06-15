<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Services\WhatsAppCloud;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

class WaChatController extends Controller
{
    private function requireShop(Request $request): Shop
    {
        $user = $request->user();

        if (!$user || !($user instanceof Shop)) {
            throw new HttpException(403, 'Shop authentication required');
        }

        return $user;
    }

    private function requireOwnedContact(Request $request, WaContact $contact): Shop
    {
        $shop = $this->requireShop($request);
        $owned = $contact->isApp()
            ? (int) $contact->shop_id === (int) $shop->id
            : ($contact->waAccount && (int) $contact->waAccount->shop_id === (int) $shop->id);
        abort_unless($owned, 404);
        return $shop;
    }

    public function account(Request $request)
    {
        $shop = $this->requireShop($request);
        $account = WaAccount::where('shop_id', $shop->id)->first();

        if (!$account) {
            return response()->json(['connected' => false]);
        }

        return response()->json($this->accountPayload($account));
    }

    /** Connected-account response shape shared by account() and saveAccount(). */
    private function accountPayload(WaAccount $account): array
    {
        return [
            'connected' => true,
            'phone_number' => $account->phone_number,
            'phone_number_id' => $account->phone_number_id,
            'waba_id' => $account->waba_id,
            'status' => $account->status,
            // 'shared' = no own token, sends use the platform's default token
            'token_preview' => $account->token ? '••••' . substr($account->token, -4) : 'shared',
        ];
    }

    public function saveAccount(Request $request)
    {
        $shop = $this->requireShop($request);

        $data = $request->validate([
            'phone_number' => ['nullable', 'string', 'max:32'],
            'phone_number_id' => ['required', 'string', 'max:64'],
            'waba_id' => ['nullable', 'string', 'max:64'],
            'token' => ['nullable', 'string'],
        ]);

        $taken = WaAccount::where('phone_number_id', $data['phone_number_id'])
            ->where('shop_id', '!=', $shop->id)
            ->exists();
        if ($taken) {
            return response()->json([
                'message' => 'This WhatsApp number is already connected to another account.',
            ], 422);
        }

        $attributes = [
            'phone_number' => $data['phone_number'] ?? null,
            'phone_number_id' => $data['phone_number_id'],
            'waba_id' => $data['waba_id'] ?? null,
            'status' => 'active',
        ];
        if (!empty($data['token'])) {
            $attributes['token'] = $data['token']; // empty token on update keeps the existing one
        }

        $account = WaAccount::updateOrCreate(['shop_id' => $shop->id], $attributes);

        return response()->json([
            'message' => 'WhatsApp connected successfully',
            ...$this->accountPayload($account),
        ]);
    }

    public function contacts(Request $request)
    {
        $shop = $this->requireShop($request);
        $account = WaAccount::where('shop_id', $shop->id)->first();

        // One inbox: WhatsApp threads (via the account) + in-app Live Chat
        // threads (owned by shop_id directly). Live Chat works without a
        // connected WA number; `connected` keeps meaning "WA connected".
        $contacts = WaContact::query()
            ->where(function ($q) use ($shop, $account) {
                $q->where(fn ($w) => $w->where('channel', 'app')->where('shop_id', $shop->id));
                if ($account) {
                    $q->orWhere('wa_account_id', $account->id);
                }
            })
            ->orderByDesc('last_message_at')
            ->limit(500)
            ->get();

        return response()->json(['connected' => (bool) $account, 'data' => $contacts]);
    }

    public function messages(Request $request, WaContact $contact)
    {
        $this->requireOwnedContact($request, $contact);

        $query = $contact->messages()->orderBy('id');

        $sinceId = (int) $request->query('since_id', 0);
        if ($sinceId > 0) {
            $query->where('id', '>', $sinceId);
        } else {
            // initial load: last 200 messages
            $ids = $contact->messages()->orderByDesc('id')->limit(200)->pluck('id');
            $query->whereIn('id', $ids);
        }

        return response()->json(['data' => $query->get()]);
    }

    public function send(Request $request, WaContact $contact)
    {
        $this->requireOwnedContact($request, $contact);

        $data = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
        ]);

        // A human is replying: stamp sender_type 'staff' so recordMessage()
        // auto-pauses the AI (agent takeover) for this thread.
        // Live Chat: storing the row delivers it (the customer app polls).
        // No Graph call, no 24h window.
        if ($contact->isApp()) {
            $message = $contact->recordMessage('out', $data['text'], 'text', null, 'sent', [], 'staff');

            return response()->json(['data' => $message], 201);
        }

        $account = $contact->waAccount;

        try {
            $result = (new WhatsAppCloud())->sendText($account, $contact->wa_number, $data['text']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $waMessageId = $result['messages'][0]['id'] ?? null;
        $message = $contact->recordMessage('out', $data['text'], 'text', $waMessageId, 'sent', [], 'staff');

        return response()->json(['data' => $message], 201);
    }

    /**
     * Agent takeover toggle: flip the AI concierge on/off for one thread.
     * Staff-only and tenant-scoped. There is no auto-resume — the only way the
     * AI comes back after a human takes over is staff setting enabled = true.
     */
    public function toggleAi(Request $request, WaContact $contact)
    {
        $this->requireOwnedContact($request, $contact);

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
        ]);

        $contact->update(['ai_enabled' => $data['enabled']]);

        return response()->json(['data' => $contact->fresh()]);
    }

    public function markRead(Request $request, WaContact $contact)
    {
        $this->requireOwnedContact($request, $contact);

        $contact->update(['unread_count' => 0]);

        return response()->json(['data' => $contact->fresh()]);
    }

    /**
     * Lead triage: tag a conversation so staff know who to follow up. Staff-only
     * and tenant-scoped. An empty/absent status clears it back to "New" (null).
     */
    public function setLeadStatus(Request $request, WaContact $contact)
    {
        $this->requireOwnedContact($request, $contact);

        $data = $request->validate([
            'status' => ['nullable', 'in:hot,warm,cold,follow_up,not_interested'],
        ]);

        $contact->update(['lead_status' => $data['status'] ?? null]);

        return response()->json(['data' => $contact->fresh()]);
    }
}
