<?php

namespace App\Services\Wa;

use Illuminate\Support\Facades\Http;

/**
 * Text-to-speech via OpenAI. Output is OGG/Opus so WhatsApp renders it as a
 * proper voice note (mic icon + waveform). Ported from whatsapp-autoreply/lib/tts.js.
 */
class Speech
{
    private const INSTRUCTIONS = 'Speak as a warm, friendly native speaker of the language of the text, with a natural local accent — for Urdu and Hindi sound like a native speaker from Pakistan/India, for Arabic like a Gulf Arabic speaker. Conversational WhatsApp voice-note tone, not a news reader.';

    public function available(): bool
    {
        return (bool) config('services.openai.key');
    }

    /** @return string ogg/opus audio bytes */
    public function synthesize(string $text): string
    {
        $response = Http::withToken((string) config('services.openai.key'))
            ->timeout(60)
            ->post('https://api.openai.com/v1/audio/speech', [
                'model' => config('services.openai.tts_model', 'gpt-4o-mini-tts'),
                'voice' => config('services.openai.tts_voice', 'nova'),
                'input' => $text,
                'instructions' => self::INSTRUCTIONS,
                'response_format' => 'opus',
            ]);

        if (!$response->successful()) {
            throw new \RuntimeException("tts failed ({$response->status()}): " . mb_substr($response->body(), 0, 200));
        }

        return $response->body();
    }
}
