<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Avatar\AvatarSessionToken;
use App\Services\Avatar\LiveAvatarClient;
use Illuminate\Http\Request;

/**
 * Brokers a LiveAvatar FULL-mode "Video Assistant" session for the customer.
 * The API key stays server-side; the browser receives only a session_token.
 * The shop + device identity is signed into a token passed as a dynamic variable
 * so the custom-LLM bridge can rebuild authoritative context without trusting
 * the client. The avatar's actual prompt/knowledge comes from our Rezzy brain
 * inside the bridge, so a single default Context is enough for all shops.
 */
class AvatarController extends Controller
{
    public function session(Request $request, Shop $shop, LiveAvatarClient $client)
    {
        $deviceId = (string) $request->header('X-Device-Id');
        if ($deviceId === '') {
            return response()->json(['message' => 'X-Device-Id header required'], 422);
        }

        $creds = $client->createSession([
            'avatar_id'     => $shop->avatar_id ?: config('services.liveavatar.default_avatar_id'),
            'voice_id'      => $shop->voice_id ?: config('services.liveavatar.default_voice_id'),
            'context_id'    => config('services.liveavatar.default_context_id'),
            'session_token' => AvatarSessionToken::issue($shop->id, $deviceId),
        ]);

        return response()->json($creds);
    }
}
