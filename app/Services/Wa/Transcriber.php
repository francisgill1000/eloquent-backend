<?php

namespace App\Services\Wa;

use Illuminate\Support\Facades\Http;

/**
 * Voice-note transcription via OpenAI Whisper. Multi-language (Arabic,
 * English, Hindi, Urdu, ...). Ported from whatsapp-autoreply/lib/transcribe.js.
 */
class Transcriber
{
    public function available(): bool
    {
        return (bool) config('services.openai.key');
    }

    public function transcribe(string $bytes, string $mime): ?string
    {
        if (!$this->available()) {
            return null;
        }

        $ext = match (true) {
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mpeg') => 'mp3',
            str_contains($mime, 'mp4'), str_contains($mime, 'm4a') => 'm4a',
            str_contains($mime, 'wav') => 'wav',
            default => 'ogg',
        };

        $response = Http::withToken((string) config('services.openai.key'))
            ->timeout(60)
            ->attach('file', $bytes, "voice.{$ext}")
            ->post('https://api.openai.com/v1/audio/transcriptions', ['model' => 'whisper-1']);

        if (!$response->successful()) {
            throw new \RuntimeException("transcription failed ({$response->status()}): " . mb_substr($response->body(), 0, 200));
        }

        $text = trim((string) $response->json('text'));

        return $text !== '' ? $text : null;
    }
}
