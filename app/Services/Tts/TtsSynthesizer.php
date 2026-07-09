<?php

namespace App\Services\Tts;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

/**
 * Shared OpenAI text-to-speech: returns cached MP3 bytes for a line of text,
 * or null when TTS is unavailable or fails.
 *
 * Used both by the standalone /tts endpoint and inline by the booking
 * assistant, so a spoken reply can come back in the SAME response instead of
 * costing the client a second round trip. The cache key is identical to the one
 * the /tts endpoint has always used, so audio voiced by either path is shared.
 */
class TtsSynthesizer
{
    /** Voices the demo/booking flows are allowed to request. */
    public const VOICES = ['nova', 'shimmer', 'coral', 'sage', 'alloy'];

    /** MP3 bytes for the (voice, text) pair, cached for a day; null on failure. */
    public function mp3(string $text, ?string $voice = null): ?string
    {
        // Match the /tts endpoint: cap length (cost guard) before caching so the
        // key is identical and long replies don't blow up billing.
        $text = mb_substr(trim($text), 0, 800);
        if ($text === '') {
            return null;
        }

        $key = config('services.openai.key');
        if (empty($key)) {
            return null;
        }

        $model = (string) config('services.openai.tts_model', 'gpt-4o-mini-tts');
        $default = (string) config('services.openai.tts_voice', 'nova');
        $voice = in_array((string) $voice, self::VOICES, true) ? (string) $voice : $default;

        $cacheKey = 'tts:' . md5($model . '|' . $voice . '|' . $text);
        $cached = Cache::get($cacheKey);
        if ($cached !== null) {
            return $cached;
        }

        $resp = Http::withToken($key)
            ->timeout(60)
            ->post('https://api.openai.com/v1/audio/speech', [
                'model'           => $model,
                'voice'           => $voice,
                'input'           => $text,
                'response_format' => 'mp3',
            ]);

        if (! $resp->successful() || $resp->body() === '') {
            return null;
        }

        $audio = $resp->body();
        Cache::put($cacheKey, $audio, now()->addDay());
        return $audio;
    }
}
