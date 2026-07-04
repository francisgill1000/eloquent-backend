<?php
namespace App\Http\Controllers;

use App\Models\AssistantMessage;
use App\Models\Shop;
use App\Services\Assistant\AssistantToolRegistry;
use App\Services\Assistant\ConversationStore;
use App\Services\Wa\ClaudeClient;
use App\Services\Wa\Speech;
use App\Services\Wa\Transcriber;
use App\Support\Assistant\AssistantPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Owner voice/text assistant. One rolling conversation per shop, stored
 * server-side (ConversationStore). Scoped to the authenticated shop.
 */
class OwnerAssistantController extends Controller
{
    public function __construct(
        protected AssistantToolRegistry $registry,
        protected ClaudeClient $claude,
        protected Speech $speech,
        protected Transcriber $transcriber,
        protected ConversationStore $store,
    ) {}

    public function history(Request $request)
    {
        $messages = AssistantMessage::where('shop_id', $request->user()->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AssistantMessage $m) => $this->store->toApi($m))
            ->all();

        return response()->json(['messages' => $messages]);
    }

    public function clear(Request $request)
    {
        $this->store->clear($request->user());
        return response()->json(['ok' => true]);
    }

    public function text(Request $request)
    {
        $data = $request->validate(['text' => ['required', 'string', 'max:2000']]);
        return $this->respond($request->user(), $data['text'], null, null, false);
    }

    public function voice(Request $request)
    {
        $request->validate(['audio' => ['required', 'file', 'max:25600']]); // 25MB
        $file = $request->file('audio');
        $bytes = (string) file_get_contents($file->getRealPath());
        $mime = $file->getMimeType() ?: 'audio/webm';

        $transcript = null;
        try {
            $transcript = $this->transcriber->transcribe($bytes, $mime);
        } catch (\Throwable $e) {
            Log::warning('assistant transcription failed: ' . $e->getMessage());
        }

        if (! $transcript) {
            return response()->json([
                'transcript' => '',
                'reply_text' => "Sorry, I didn't catch that — please try again.",
                'reply_audio_url' => null,
            ], 201);
        }

        return $this->respond($request->user(), $transcript, [$bytes, $mime], $transcript, true);
    }

    /**
     * Run one turn. Persists the (question, answer) pair ONLY on success.
     *
     * @param array{0:string,1:string}|null $userAudio [bytes, mime] for a voice turn
     */
    protected function respond(Shop $shop, string $userText, ?array $userAudio, ?string $transcript, bool $speak): \Illuminate\Http\JsonResponse
    {
        $context = $this->store->contextFor($shop);
        $messages = array_merge($context, [['role' => 'user', 'content' => $userText]]);

        $replyText = '';
        try {
            $replyText = $this->claude->toolLoop(
                AssistantPrompt::for($shop),
                $messages,
                $this->registry->defs(),
                fn (string $tool, array $input) => $this->registry->execute($shop, $tool, $input),
            );
        } catch (\Throwable $e) {
            Log::error('assistant reply failed: ' . $e->getMessage());
        }

        // Failure → persist nothing, return a graceful fallback the client shows
        // transiently. Keeps stored history clean and strictly alternating.
        if ($replyText === '') {
            $payload = ['reply_text' => "Sorry, I couldn't work that out — please try again.", 'reply_audio_url' => null];
            if ($transcript !== null) {
                $payload['transcript'] = $transcript;
            }
            return response()->json($payload, 201);
        }

        // Success → persist the user turn (with its voice audio) then the reply.
        $this->store->append($shop, 'user', $userText, $userAudio[0] ?? null, $userAudio[1] ?? null);

        $replyAudioBytes = null;
        $replyMime = null;
        if ($speak && $this->speech->available()) {
            try {
                $replyAudioBytes = $this->speech->synthesize($replyText);
                $replyMime = 'audio/ogg';
            } catch (\Throwable $e) {
                Log::warning('assistant tts failed: ' . $e->getMessage());
            }
        }
        $assistantMsg = $this->store->append($shop, 'assistant', $replyText, $replyAudioBytes, $replyMime);

        $payload = [
            'reply_text' => $replyText,
            'reply_audio_url' => $this->store->signedUrl($assistantMsg),
        ];
        if ($transcript !== null) {
            $payload['transcript'] = $transcript;
        }
        return response()->json($payload, 201);
    }

    public function audio(AssistantMessage $message)
    {
        abort_unless($message->audio_path && Storage::disk('local')->exists($message->audio_path), 404);

        return Storage::disk('local')->response(
            $message->audio_path,
            null,
            ['Content-Type' => $message->audio_mime ?: 'application/octet-stream'],
        );
    }
}
