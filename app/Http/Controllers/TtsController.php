<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Text-to-speech for the in-app assistant. Reuses the OpenAI TTS the chat
 * already uses (the configured default voice, or a whitelisted "voice" from
 * the request: nova, shimmer, coral, sage, alloy), returning MP3 for broad
 * browser support. The API key stays server-side; identical text is cached
 * for a day so repeated greetings/replies aren't re-billed.
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

        $key = config('services.openai.key');
        if (empty($key)) {
            return response()->json(['message' => 'TTS not configured'], 503);
        }

        $model = (string) config('services.openai.tts_model', 'gpt-4o-mini-tts');
        $default = (string) config('services.openai.tts_voice', 'nova');

        // Optional caller-chosen voice, whitelisted to the voices the demo uses.
        $allowed = ['nova', 'shimmer', 'coral', 'sage', 'alloy'];
        $requested = (string) $request->input('voice', '');
        $voice = in_array($requested, $allowed, true) ? $requested : $default;

        $cacheKey = 'tts:' . md5($model . '|' . $voice . '|' . $text);
        $audio = Cache::get($cacheKey);

        if ($audio === null) {
            $resp = Http::withToken($key)
                ->timeout(60)
                ->post('https://api.openai.com/v1/audio/speech', [
                    'model'           => $model,
                    'voice'           => $voice,
                    'input'           => $text,
                    'response_format' => 'mp3',
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
