<?php
namespace App\Http\Controllers;

use App\Models\Shop;
use App\Services\Assistant\OwnerAssistantTools;
use App\Services\Wa\ClaudeClient;
use App\Services\Wa\Speech;
use App\Services\Wa\Transcriber;
use App\Support\Assistant\AssistantPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * Owner voice/text assistant. Synchronous: one request = one turn. Scoped to
 * the authenticated shop ($request->user()). History is client-held and echoed
 * back each turn (stateless server).
 */
class OwnerAssistantController extends Controller
{
    public function __construct(
        protected OwnerAssistantTools $tools,
        protected ClaudeClient $claude,
        protected Speech $speech,
        protected Transcriber $transcriber,
    ) {}

    public function text(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'history' => ['sometimes'],
        ]);
        $history = $this->parseHistory($data['history'] ?? []);
        // Typed question → text reply (no spoken audio).
        return $this->respond($request->user(), $data['text'], $history, null, false);
    }

    public function voice(Request $request)
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:25600'], // 25MB
            'history' => ['sometimes'],
        ]);
        $file = $request->file('audio');
        $transcript = null;
        try {
            $bytes = (string) file_get_contents($file->getRealPath());
            $transcript = $this->transcriber->transcribe($bytes, $file->getMimeType() ?: 'audio/webm');
        } catch (\Throwable $e) {
            Log::warning('assistant transcription failed: ' . $e->getMessage());
        }

        if (!$transcript) {
            return response()->json([
                'transcript' => '',
                'reply_text' => "Sorry, I didn't catch that — please try again.",
                'reply_audio_url' => null,
                'history' => $this->parseHistory($request->input('history', [])),
            ], 201);
        }

        $history = $this->parseHistory($request->input('history', []));
        // Spoken question → spoken reply.
        return $this->respond($request->user(), $transcript, $history, $transcript, true);
    }

    /** @param array<int, array{role:string, content:string}> $history */
    protected function respond(Shop $shop, string $userText, array $history, ?string $transcript = null, bool $speak = true): \Illuminate\Http\JsonResponse
    {
        $messages = array_merge($history, [['role' => 'user', 'content' => $userText]]);

        $replyText = '';
        try {
            $replyText = $this->claude->toolLoop(
                AssistantPrompt::for($shop),
                $messages,
                OwnerAssistantTools::defs(),
                fn (string $tool, array $input) => $this->tools->execute($shop, $tool, $input),
            );
        } catch (\Throwable $e) {
            Log::error('assistant reply failed: ' . $e->getMessage());
        }
        $replyText = $replyText !== '' ? $replyText : "Sorry, I couldn't work that out — please try again.";

        // Return the spoken reply inline as a base64 data URI. This plays
        // directly in the browser with no storage symlink, no CORS, and no
        // file accumulation — identical behaviour on local dev and prod.
        $audioUrl = null;
        if ($speak && $this->speech->available()) {
            try {
                $bytes = $this->speech->synthesize($replyText);
                $audioUrl = 'data:audio/ogg;base64,' . base64_encode($bytes);
            } catch (\Throwable $e) {
                Log::warning('assistant tts failed: ' . $e->getMessage());
            }
        }

        $newHistory = array_merge($messages, [['role' => 'assistant', 'content' => $replyText]]);

        $payload = [
            'reply_text' => $replyText,
            'reply_audio_url' => $audioUrl,
            'history' => $newHistory,
        ];
        if ($transcript !== null) {
            $payload['transcript'] = $transcript;
        }
        return response()->json($payload, 201);
    }

    /** Accepts a JSON string or an array; returns a clean role/content list. */
    protected function parseHistory(mixed $raw): array
    {
        $arr = is_string($raw) ? (json_decode($raw, true) ?: []) : (is_array($raw) ? $raw : []);
        return collect($arr)
            ->filter(fn ($m) => isset($m['role'], $m['content']) && in_array($m['role'], ['user', 'assistant'], true))
            ->map(fn ($m) => ['role' => $m['role'], 'content' => (string) $m['content']])
            ->values()->all();
    }
}
