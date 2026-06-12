<?php

namespace App\Http\Controllers;

use App\Models\WaPushSubscription;
use App\Services\Wa\WebPush;
use Illuminate\Http\Request;

class WaPushController extends Controller
{
    public function vapidKey(WebPush $push)
    {
        if (!$push->enabled()) {
            return response()->json(['error' => 'push not configured'], 503);
        }

        return response()->json(['key' => config('services.webpush.public_key')]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', 'url'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        $shop = $request->user();
        if (!$shop || !($shop instanceof \App\Models\Shop)) {
            return response()->json(['message' => 'Shop authentication required'], 403);
        }

        // The subscription belongs to this shop: only its own threads notify it.
        WaPushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            ['shop_id' => $shop->id, 'p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']]
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        WaPushSubscription::where('endpoint', $data['endpoint'])->delete();

        return response()->json(['ok' => true]);
    }
}
