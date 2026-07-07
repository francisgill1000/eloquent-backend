<?php
namespace App\Services\Assistant;

use App\Models\AssistantMessage;
use App\Models\Conversation;
use App\Models\Shop;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** Persistence for the owner assistant, scoped to a single conversation thread. */
class ConversationStore
{
    /** Lazily create a thread, titling it from the first user message. */
    public function create(Shop $shop, string $firstUserText): Conversation
    {
        return Conversation::create([
            'shop_id' => $shop->id,
            'title' => $this->titleFrom($firstUserText),
        ]);
    }

    /**
     * One page of the shop's threads, newest activity first, optionally filtered
     * by a title search. Fetches one row beyond the page to report whether more
     * exist — cheaper than a separate count query.
     *
     * @return array{conversations: list<array{id:int,title:string,updated_at:?string}>, has_more: bool}
     */
    public function list(Shop $shop, int $page = 1, int $perPage = 20, ?string $q = null): array
    {
        $page = max(1, $page);
        $query = Conversation::where('shop_id', $shop->id);

        $q = $q !== null ? trim($q) : '';
        if ($q !== '') {
            // Escape LIKE wildcards so a literal % or _ in the search can't widen it.
            $escaped = str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q);
            $query->where('title', 'like', '%' . $escaped . '%');
        }

        $rows = $query->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->skip(($page - 1) * $perPage)
            ->take($perPage + 1) // one extra row → tells us another page exists
            ->get(['id', 'title', 'updated_at']);

        $hasMore = $rows->count() > $perPage;

        $conversations = $rows->take($perPage)
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ])
            ->values()
            ->all();

        return ['conversations' => $conversations, 'has_more' => $hasMore];
    }

    /** Last $limit turns of ONE thread, chronological, shaped for the Claude API. */
    public function contextFor(Conversation $c, int $limit = 20): array
    {
        return AssistantMessage::where('conversation_id', $c->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (AssistantMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    public function append(Conversation $c, string $role, string $content, ?string $audioBytes = null, ?string $audioMime = null): AssistantMessage
    {
        $path = null;
        if ($audioBytes !== null && $audioBytes !== '') {
            $path = "assistant/{$c->shop_id}/{$c->id}/" . Str::uuid() . '.' . $this->ext($audioMime ?? '');
            Storage::disk('local')->put($path, $audioBytes);
        }

        $msg = AssistantMessage::create([
            'shop_id' => $c->shop_id,
            'conversation_id' => $c->id,
            'role' => $role,
            'content' => $content,
            'audio_path' => $path,
            'audio_mime' => $path ? $audioMime : null,
        ]);

        $c->touch(); // bump updated_at so this thread sorts to the top of the list

        return $msg;
    }

    /** One thread's messages, chronological, shaped for the frontend. */
    public function messagesFor(Conversation $c): array
    {
        return AssistantMessage::where('conversation_id', $c->id)
            ->orderBy('id')
            ->get()
            ->map(fn (AssistantMessage $m) => $this->toApi($m))
            ->all();
    }

    public function rename(Conversation $c, string $title): void
    {
        $c->update(['title' => $this->titleFrom($title)]);
    }

    public function delete(Conversation $c): void
    {
        $c->delete(); // model hook cascades to messages + their audio files
    }

    public function signedUrl(AssistantMessage $m): ?string
    {
        if (! $m->audio_path) {
            return null;
        }
        return URL::temporarySignedRoute('assistant.audio', now()->addDay(), ['message' => $m->id]);
    }

    public function toApi(AssistantMessage $m): array
    {
        return [
            'id' => $m->id,
            'role' => $m->role,
            'content' => $m->content,
            'audio_url' => $this->signedUrl($m),
        ];
    }

    /** Collapse whitespace, cap at 60 chars (+ ellipsis); fall back to "New chat". */
    private function titleFrom(string $text): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', $text) ?? '');
        if ($clean === '') {
            return 'New chat';
        }
        return mb_strlen($clean) > 60 ? mb_substr($clean, 0, 60) . '…' : $clean;
    }

    private function ext(string $mime): string
    {
        return match (true) {
            str_contains($mime, 'webm') => 'webm',
            str_contains($mime, 'ogg') => 'ogg',
            str_contains($mime, 'mp4') => 'mp4',
            str_contains($mime, 'mpeg'), str_contains($mime, 'mp3') => 'mp3',
            str_contains($mime, 'wav') => 'wav',
            default => 'bin',
        };
    }
}
