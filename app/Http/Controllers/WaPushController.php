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

        WaPushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']]
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
