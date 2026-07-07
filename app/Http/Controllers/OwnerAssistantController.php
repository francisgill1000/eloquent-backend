<?php
namespace App\Http\Controllers;

use App\Models\AssistantMessage;
use App\Models\Conversation;
use App\Models\Shop;
use App\Services\Assistant\AssistantToolRegistry;
use App\Services\Assistant\ConversationStore;
use App\Services\Assistant\Support\AssistantActions;
use App\Services\Wa\ClaudeClient;
use App\Services\Wa\Speech;
use App\Services\Wa\Transcriber;
use App\Support\Assistant\AssistantPrompt;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Owner voice/text assistant. Conversations are organised into threads
 * (ChatGPT-style), each an isolated context, all scoped to the authed shop.
 */
class OwnerAssistantController extends Controller
{
    public function __construct(
        protected AssistantToolRegistry $registry,
        protected ClaudeClient $claude,
        protected Speech $speech,
        protected Transcriber $transcriber,
        protected ConversationStore $store,
        protected AssistantActions $actions,
    ) {}

    public function conversations(Request $request)
    {
        return response()->json(['conversations' => $this->store->list($request->user())]);
    }

    public function messages(Request $request, Conversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);
        return response()->json(['messages' => $this->store->messagesFor($conversation)]);
    }

    public function rename(Request $request, Conversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);
        $data = $request->validate(['title' => ['required', 'string', 'max:120']]);
        $this->store->rename($conversation, $data['title']);
        return response()->json(['ok' => true, 'title' => $conversation->fresh()->title]);
    }

    public function destroy(Request $request, Conversation $conversation)
    {
        $this->authorizeConversation($request, $conversation);
        $this->store->delete($conversation);
        return response()->json(['ok' => true]);
    }

    public function text(Request $request)
    {
        $data = $request->validate([
            'text' => ['required', 'string', 'max:2000'],
            'conversation_id' => ['nullable', 'integer'],
        ]);
        $conversation = $this->resolveConversation($request, $data['conversation_id'] ?? null);
        return $this->respond($request->user(), $conversation, $data['text'], null, null, false);
    }

    public function voice(Request $request)
    {
        $request->validate([
            'audio' => ['required', 'file', 'max:25600'], // 25MB
            'conversation_id' => ['nullable', 'integer'],
        ]);
        $conversation = $this->resolveConversation($request, $request->input('conversation_id'));

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

        return $this->respond($request->user(), $conversation, $transcript, [$bytes, $mime], $transcript, true);
    }

    /**
     * Run one turn. Persists the thread (if new) + the (question, answer) pair
     * ONLY on a successful, non-empty Claude reply.
     *
     * @param array{0:string,1:string}|null $userAudio [bytes, mime] for a voice turn
     */
    protected function respond(Shop $shop, ?Conversation $conversation, string $userText, ?array $userAudio, ?string $transcript, bool $speak): \Illuminate\Http\JsonResponse
    {
        $context = $conversation ? $this->store->contextFor($conversation) : [];
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

        // Failure → persist nothing (no thread, no turns); return a transient fallback.
        if ($replyText === '') {
            $payload = ['reply_text' => "Sorry, I couldn't work that out — please try again.", 'reply_audio_url' => null];
            if ($transcript !== null) {
                $payload['transcript'] = $transcript;
            }
            return response()->json($payload, 201);
        }

        // Success → lazily create the thread on its first message, then persist the pair.
        $conversation ??= $this->store->create($shop, $userText);
        $this->store->append($conversation, 'user', $userText, $userAudio[0] ?? null, $userAudio[1] ?? null);

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
        $assistantMsg = $this->store->append($conversation, 'assistant', $replyText, $replyAudioBytes, $replyMime);

        $payload = [
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'reply_text' => $replyText,
            'reply_audio_url' => $this->store->signedUrl($assistantMsg),
        ];
        if ($action = $this->actions->pending()) {
            $payload['action'] = $action;
        }
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

    /** Resolve an optional conversation id to a shop-owned thread, or null for a new one. */
    private function resolveConversation(Request $request, $conversationId): ?Conversation
    {
        if (! $conversationId) {
            return null;
        }
        $conversation = Conversation::find($conversationId);
        if (! $conversation) {
            abort(404);
        }
        $this->authorizeConversation($request, $conversation);
        return $conversation;
    }

    /** A shop may only touch its own threads. */
    private function authorizeConversation(Request $request, Conversation $conversation): void
    {
        abort_unless($conversation->shop_id === $request->user()->id, 404);
    }
}
