<?php

namespace App\Http\Controllers;

use App\Jobs\ProcessWaReply;
use App\Models\Shop;
use App\Models\User;
use App\Models\WaContact;
use Illuminate\Http\Request;

/**
 * Customer-facing in-app Live Chat with a shop. Same guest-first identity as
 * favourites/bookings: the rezzy-customer device id (X-Device-Id header) keys
 * the thread, no login required. Replies come from the shared AI pipeline
 * (ProcessWaReply) and land as stored rows this controller serves back.
 */
class ChatController extends Controller
{
    public function messages(Request $request, Shop $shop)
    {
        $contact = WaContact::where('shop_id', $shop->id)
            ->where('channel', 'app')
            ->where('device_id', $this->deviceId($request))
            ->first();

        if (!$contact) {
            return response()->json(['data' => []]);
        }

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

    public function send(Request $request, Shop $shop)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:4096'],
        ]);

        $deviceId = $this->deviceId($request);

        $contact = WaContact::firstOrCreate(
            ['shop_id' => $shop->id, 'device_id' => $deviceId],
            ['channel' => 'app']
        );

        // A logged-in customer's name/phone makes the owner inbox readable.
        $user = $request->user('sanctum');
        if ($user instanceof User) {
            $updates = [];
            if (!$contact->name && $user->name) {
                $updates['name'] = $user->name;
            }
            if (!$contact->wa_number && $user->phone) {
                $updates['wa_number'] = preg_replace('/\D+/', '', (string) $user->phone) ?: null;
            }
            if ($updates) {
                $contact->update($updates);
            }
        }

        $message = $contact->recordMessage('in', $data['text']);

        ProcessWaReply::dispatch($message->id);

        return response()->json(['data' => $message], 201);
    }

    private function deviceId(Request $request): string
    {
        $id = trim((string) $request->header('X-Device-Id'));
        abort_if($id === '' || strlen($id) > 64, 422, 'X-Device-Id header required');

        return $id;
    }
}
