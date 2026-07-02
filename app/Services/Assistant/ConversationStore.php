<?php
namespace App\Services\Assistant;

use App\Models\AssistantMessage;
use App\Models\Shop;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/** Persistence for the owner assistant's per-shop rolling conversation. */
class ConversationStore
{
    /** Last $limit turns, chronological, shaped for the Claude API. */
    public function contextFor(Shop $shop, int $limit = 20): array
    {
        return AssistantMessage::where('shop_id', $shop->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['role', 'content'])
            ->reverse()
            ->map(fn (AssistantMessage $m) => ['role' => $m->role, 'content' => $m->content])
            ->values()
            ->all();
    }

    public function append(Shop $shop, string $role, string $content, ?string $audioBytes = null, ?string $audioMime = null): AssistantMessage
    {
        $path = null;
        if ($audioBytes !== null && $audioBytes !== '') {
            $path = "assistant/{$shop->id}/" . Str::uuid() . '.' . $this->ext($audioMime ?? '');
            Storage::disk('local')->put($path, $audioBytes);
        }

        return AssistantMessage::create([
            'shop_id' => $shop->id,
            'role' => $role,
            'content' => $content,
            'audio_path' => $path,
            'audio_mime' => $path ? $audioMime : null,
        ]);
    }

    public function clear(Shop $shop): void
    {
        // get()->each->delete() so the model's deleting hook removes audio files.
        AssistantMessage::where('shop_id', $shop->id)->get()->each->delete();
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
