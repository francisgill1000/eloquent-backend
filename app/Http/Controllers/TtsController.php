<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Text-to-speech for the in-app assistant. Proxies ElevenLabs so the API key
 * never reaches the browser, and returns an MP3 stream. Identical text is
 * cached for a day so repeated greetings/replies aren't re-billed.
 */
class TtsController extends Controller
{
    public function speak(Request $request)
    {
        $text = trim((string) $request->input('text'));
        if ($text === '') {
            return response()->json(['message' => 'text required'], 422);
        }
        // Guard cost/abuse — replies are short; cap the spoken length.
        $text = mb_substr($text, 0, 800);

        $key = config('services.elevenlabs.api_key');
        if (empty($key)) {
            return response()->json(['message' => 'TTS not configured'], 503);
        }

        $voice = (string) config('services.elevenlabs.voice_id');
        $model = (string) config('services.elevenlabs.model_id');

        $cacheKey = 'tts:' . md5($voice . '|' . $model . '|' . $text);
        $audio = Cache::get($cacheKey);

        if ($audio === null) {
            $resp = Http::withHeaders([
                'xi-api-key' => $key,
                'Accept'     => 'audio/mpeg',
            ])->timeout(30)->post("https://api.elevenlabs.io/v1/text-to-speech/{$voice}", [
                'text'           => $text,
                'model_id'       => $model,
                'voice_settings' => ['stability' => 0.5, 'similarity_boost' => 0.8],
            ]);

            if (! $resp->successful()) {
                return response()->json(['message' => 'TTS failed'], 502);
            }

            $audio = $resp->body();
            Cache::put($cacheKey, $audio, now()->addDay());
        }

        return response($audio, 200)
            ->header('Content-Type', 'audio/mpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
