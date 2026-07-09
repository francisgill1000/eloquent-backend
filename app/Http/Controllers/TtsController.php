<?php

namespace App\Http\Controllers;

use App\Services\Tts\TtsSynthesizer;
use Illuminate\Http\Request;

/**
 * Text-to-speech for the in-app assistant. Reuses the OpenAI TTS the chat
 * already uses (the configured default voice, or a whitelisted "voice" from
 * the request: nova, shimmer, coral, sage, alloy), returning MP3 for broad
 * browser support. The API key stays server-side; identical text is cached
 * for a day so repeated greetings/replies aren't re-billed.
 */
class TtsController extends Controller
{
    public function __construct(private TtsSynthesizer $tts) {}

    public function speak(Request $request)
    {
        $text = trim((string) $request->input('text'));
        if ($text === '') {
            return response()->json(['message' => 'text required'], 422);
        }
        if (empty(config('services.openai.key'))) {
            return response()->json(['message' => 'TTS not configured'], 503);
        }

        $audio = $this->tts->mp3($text, (string) $request->input('voice', ''));
        if ($audio === null) {
            return response()->json(['message' => 'TTS failed'], 502);
        }

        return response($audio, 200)
            ->header('Content-Type', 'audio/mpeg')
            ->header('Cache-Control', 'public, max-age=86400');
    }
}
