<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Avatar\AvatarSessionToken;
use App\Services\Avatar\LiveAvatarClient;
use App\Services\Wa\PersonaResolver;
use Illuminate\Http\Request;

/**
 * Brokers a LiveAvatar FULL-mode "Video Assistant" session for the customer.
 * The API key stays server-side; the browser only receives session creds. The
 * shop + device identity is signed into the system prompt so the custom-LLM
 * bridge can rebuild authoritative context without trusting the client.
 */
class AvatarController extends Controller
{
    public function session(Request $request, Shop $shop, LiveAvatarClient $client, PersonaResolver $persona)
    {
        $deviceId = (string) $request->header('X-Device-Id');
        if ($deviceId === '') {
            return response()->json(['message' => 'X-Device-Id header required'], 422);
        }

        $token = AvatarSessionToken::issue($shop->id, $deviceId);

        // Real persona for safety (LiveAvatar needs non-empty context) plus the
        // signed marker the bridge parses to rebuild authoritative context.
        $systemPrompt = $persona->systemPrompt($shop)
            . "\n" . sprintf(AvatarSessionToken::MARKER, $token);

        $creds = $client->createSession([
            'avatar_id'     => $shop->avatar_id ?: config('services.liveavatar.default_avatar_id'),
            'voice_id'      => $shop->voice_id ?: config('services.liveavatar.default_voice_id'),
            'system_prompt' => $systemPrompt,
        ]);

        return response()->json($creds);
    }
}
