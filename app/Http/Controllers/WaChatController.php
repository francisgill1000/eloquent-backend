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
        $account = $contact->waAccount;
        abort_unless($account && (int) $account->shop_id === (int) $shop->id, 404);
        return $shop;
    }

    public function account(Request $request)
    {
        $shop = $this->requireShop($request);
        $account = WaAccount::where('shop_id', $shop->id)->first();

        if (!$account) {
            return response()->json(['connected' => false]);
        }

        return response()->json([
            'connected' => true,
            'phone_number' => $account->phone_number,
            'phone_number_id' => $account->phone_number_id,
            'waba_id' => $account->waba_id,
            'status' => $account->status,
            'token_preview' => '••••' . substr($account->token, -4),
        ]);
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

        $account = WaAccount::where('shop_id', $shop->id)->first();

        if (!$account && empty($data['token'])) {
            return response()->json(['message' => 'Access token is required.'], 422);
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
            'connected' => true,
            'phone_number' => $account->phone_number,
            'phone_number_id' => $account->phone_number_id,
            'waba_id' => $account->waba_id,
            'status' => $account->status,
            'token_preview' => '••••' . substr($account->token, -4),
        ]);
    }

    public function contacts(Request $request)
    {
        $shop = $this->requireShop($request);
        $account = WaAccount::where('shop_id', $shop->id)->first();

        if (!$account) {
            return response()->json(['connected' => false, 'data' => []]);
        }

        $contacts = $account->contacts()
            ->orderByDesc('last_message_at')
            ->limit(500)
            ->get();

        return response()->json(['connected' => true, 'data' => $contacts]);
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

        $account = $contact->waAccount;

        try {
            $result = (new WhatsAppCloud())->sendText($account, $contact->wa_number, $data['text']);
        } catch (\RuntimeException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $waMessageId = $result['messages'][0]['id'] ?? null;
        $message = $contact->recordMessage('out', $data['text'], 'text', $waMessageId, 'sent');

        return response()->json(['data' => $message], 201);
    }

    public function markRead(Request $request, WaContact $contact)
    {
        $this->requireOwnedContact($request, $contact);

        $contact->update(['unread_count' => 0]);

        return response()->json(['data' => $contact->fresh()]);
    }
}
