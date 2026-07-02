# Server-side Assistant Conversations Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Persist each shop's owner-assistant conversation (text + audio) on the server so it syncs across devices and survives reloads.

**Architecture:** A new `assistant_messages` table (one rolling conversation per shop, keyed by `shop_id`). A `ConversationStore` service handles loading capped context for Claude, persisting turns, storing audio on the private disk, and building signed audio URLs. `OwnerAssistantController` gains `history`, `clear`, and `audio` actions and persists turns on success. The React page fetches history from the server instead of `localStorage`.

**Tech Stack:** Laravel 11 (PHP 8.4), Sanctum auth, Laravel signed URLs, private `local` filesystem disk; React + Vite + TypeScript (admin), Vitest.

## Global Constraints

- The owner assistant is scoped to the authenticated `Shop` (`$request->user()`), never cross-shop.
- A turn is persisted **only on full success** (transcription + Claude both succeed). On any failure, persist nothing and return a graceful fallback.
- Store all turns; send only the **last 20 messages** to Claude.
- Typed questions get **text-only** replies (no TTS). Voice questions get spoken replies.
- Audio lives on the **private** disk (`storage/app/private`) under `assistant/{shop_id}/{uuid}.{ext}`, served only via **signed URLs** (24h expiry).
- PHP runner in this repo: `C:\Users\franc\.config\herd-lite\bin\php.exe` (Windows). On the droplet: `php8.4`.
- Follow existing patterns in `app/Services/Wa/*` and `WaChatController`.

---

### Task 1: `assistant_messages` table + `AssistantMessage` model

**Files:**
- Create: `database/migrations/2026_07_02_000001_create_assistant_messages_table.php`
- Create: `app/Models/AssistantMessage.php`
- Test: `tests/Feature/AssistantMessageModelTest.php`

**Interfaces:**
- Produces: table `assistant_messages(id, shop_id, role, content, audio_path, audio_mime, timestamps)`; model `App\Models\AssistantMessage` with `$fillable = ['shop_id','role','content','audio_path','audio_mime']`, `shop()` belongsTo, and a `deleting` hook that removes `audio_path` from the `local` disk.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AssistantMessageModelTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'FreshPress', 'shop_code' => '1001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_deleting_a_message_removes_its_audio_file(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        Storage::disk('local')->put('assistant/1/note.ogg', 'BYTES');

        $msg = AssistantMessage::create([
            'shop_id' => $shop->id,
            'role' => 'assistant',
            'content' => 'hi',
            'audio_path' => 'assistant/1/note.ogg',
            'audio_mime' => 'audio/ogg',
        ]);

        Storage::disk('local')->assertExists('assistant/1/note.ogg');
        $msg->delete();
        Storage::disk('local')->assertMissing('assistant/1/note.ogg');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `& "C:\Users\franc\.config\herd-lite\bin\php.exe" artisan test --filter AssistantMessageModelTest`
Expected: FAIL — class `App\Models\AssistantMessage` / table not found.

- [ ] **Step 3: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('role', 10);            // user | assistant
            $table->text('content');
            $table->string('audio_path')->nullable();
            $table->string('audio_mime', 40)->nullable();
            $table->timestamps();
            $table->index(['shop_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_messages');
    }
};
```

- [ ] **Step 4: Write the model**

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

class AssistantMessage extends Model
{
    protected $fillable = ['shop_id', 'role', 'content', 'audio_path', 'audio_mime'];

    protected static function booted(): void
    {
        // Keep disk and DB in step: a deleted row must not orphan its audio file.
        static::deleting(function (AssistantMessage $m) {
            if ($m->audio_path) {
                Storage::disk('local')->delete($m->audio_path);
            }
        });
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

Run: `& "C:\Users\franc\.config\herd-lite\bin\php.exe" artisan test --filter AssistantMessageModelTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_02_000001_create_assistant_messages_table.php app/Models/AssistantMessage.php tests/Feature/AssistantMessageModelTest.php
git commit -m "feat(assistant): assistant_messages table + model with audio cleanup"
```

---

### Task 2: `ConversationStore` service

**Files:**
- Create: `app/Services/Assistant/ConversationStore.php`
- Test: `tests/Feature/ConversationStoreTest.php`

**Interfaces:**
- Consumes: `App\Models\AssistantMessage`, `App\Models\Shop`.
- Produces: `App\Services\Assistant\ConversationStore` with:
  - `contextFor(Shop $shop, int $limit = 20): array` → last `$limit` turns as `[['role'=>..,'content'=>..], ...]` in chronological order (for Claude).
  - `append(Shop $shop, string $role, string $content, ?string $audioBytes = null, ?string $audioMime = null): AssistantMessage` → stores audio to `assistant/{shop_id}/{uuid}.{ext}` when bytes given, creates the row.
  - `clear(Shop $shop): void` → deletes all of the shop's rows (triggering audio cleanup).
  - `signedUrl(AssistantMessage $m): ?string` → 24h `temporarySignedRoute('assistant.audio', ...)` when `audio_path` set, else null.
  - `toApi(AssistantMessage $m): array` → `['id'=>int,'role'=>string,'content'=>string,'audio_url'=>?string]`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Shop;
use App\Services\Assistant\ConversationStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ConversationStoreTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code = '1001'): Shop
    {
        return Shop::create(['name' => 'S'.$code, 'shop_code' => $code, 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_append_stores_row_and_audio_file(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $store = app(ConversationStore::class);

        $msg = $store->append($shop, 'assistant', 'fifty dirhams', 'OGGBYTES', 'audio/ogg');

        $this->assertDatabaseHas('assistant_messages', ['id' => $msg->id, 'shop_id' => $shop->id, 'role' => 'assistant', 'content' => 'fifty dirhams']);
        $this->assertNotNull($msg->audio_path);
        Storage::disk('local')->assertExists($msg->audio_path);
        $this->assertStringEndsWith('.ogg', $msg->audio_path);
    }

    public function test_append_without_audio_leaves_path_null(): void
    {
        Storage::fake('local');
        $store = app(ConversationStore::class);
        $msg = $store->append($this->shop(), 'user', 'how much today');
        $this->assertNull($msg->audio_path);
    }

    public function test_context_for_caps_and_orders(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        for ($i = 0; $i < 25; $i++) {
            $store->append($shop, $i % 2 === 0 ? 'user' : 'assistant', "m{$i}");
        }
        $ctx = $store->contextFor($shop, 20);
        $this->assertCount(20, $ctx);
        $this->assertSame('m5', $ctx[0]['content']);   // oldest kept
        $this->assertSame('m24', $ctx[19]['content']);  // newest last
        $this->assertSame(['role', 'content'], array_keys($ctx[0]));
    }

    public function test_clear_deletes_rows_and_audio(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $msg = $store->append($shop, 'assistant', 'hi', 'BYTES', 'audio/ogg');
        $path = $msg->audio_path;

        $store->clear($shop);

        $this->assertDatabaseCount('assistant_messages', 0);
        Storage::disk('local')->assertMissing($path);
    }

    public function test_signed_url_null_without_audio(): void
    {
        $store = app(ConversationStore::class);
        $msg = $store->append($this->shop(), 'user', 'text only');
        $this->assertNull($store->signedUrl($msg));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `& "C:\Users\franc\.config\herd-lite\bin\php.exe" artisan test --filter ConversationStoreTest`
Expected: FAIL — class `ConversationStore` not found.

- [ ] **Step 3: Write the service**

```php
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
```

- [ ] **Step 4: Run test to verify it passes**

Run: `& "C:\Users\franc\.config\herd-lite\bin\php.exe" artisan test --filter ConversationStoreTest`
Expected: PASS (5 tests). Note: `signedUrl` needs the named route `assistant.audio`; if the test errors on a missing route, it means Task 3's route isn't registered yet — for this task, the `test_signed_url_null_without_audio` case returns before route generation, so it passes; the audio-bearing signed URL is exercised in Task 3.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/ConversationStore.php tests/Feature/ConversationStoreTest.php
git commit -m "feat(assistant): ConversationStore for per-shop history + audio storage"
```

---

### Task 3: Controller actions + routes (persist, history, clear, signed audio)

**Files:**
- Modify: `app/Http/Controllers/OwnerAssistantController.php`
- Modify: `routes/api.php:148-151`
- Modify: `tests/Feature/OwnerAssistantControllerTest.php`
- Create: `tests/Feature/AssistantConversationApiTest.php`

**Interfaces:**
- Consumes: `ConversationStore` (Task 2), existing `ClaudeClient`, `Speech`, `Transcriber`, `OwnerAssistantTools`, `AssistantPrompt`.
- Produces:
  - `GET /shop/assistant/history` → `{ messages: [{id,role,content,audio_url}] }` (chronological).
  - `POST /shop/assistant/text` → `{ reply_text, reply_audio_url: null }` and persists the pair on success.
  - `POST /shop/assistant/voice` → `{ transcript, reply_text, reply_audio_url }` and persists the pair (with audio) on success; persists nothing on transcription/Claude failure.
  - `DELETE /shop/assistant/history` → `{ ok: true }`, deletes rows + audio.
  - `GET /shop/assistant/audio/{message}` (name `assistant.audio`, `signed` middleware) → streams the audio file.

- [ ] **Step 1: Write the failing feature test**

```php
<?php
namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantConversationApiTest extends TestCase
{
    use RefreshDatabase;

    private function authShop(string $code = '1001'): Shop
    {
        $shop = Shop::create(['name' => 'FreshPress'.$code, 'shop_code' => $code, 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        Sanctum::actingAs($shop, ['*']);
        return $shop;
    }

    public function test_text_turn_is_persisted_and_history_returns_it(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Fifty dirhams.']]])]);

        $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])
            ->assertCreated()
            ->assertJsonPath('reply_text', 'Fifty dirhams.');

        $res = $this->getJson('/api/shop/assistant/history')->assertOk();
        $res->assertJsonPath('messages.0.role', 'user')
            ->assertJsonPath('messages.0.content', 'how much today')
            ->assertJsonPath('messages.1.role', 'assistant')
            ->assertJsonPath('messages.1.content', 'Fifty dirhams.')
            ->assertJsonPath('messages.0.audio_url', null);
    }

    public function test_voice_turn_persists_both_audios_and_serves_them(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'how much today']),
            'api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'Fifty dirhams.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGGBYTES', 200),
        ]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'VOICE', 'audio/webm');
        $res = $this->post('/api/shop/assistant/voice', ['audio' => $audio])
            ->assertCreated()
            ->assertJsonPath('transcript', 'how much today')
            ->assertJsonPath('reply_text', 'Fifty dirhams.');
        $this->assertNotNull($res->json('reply_audio_url'));

        // Two rows, both with stored audio files.
        $this->assertDatabaseCount('assistant_messages', 2);
        foreach (AssistantMessage::all() as $m) {
            Storage::disk('local')->assertExists($m->audio_path);
        }

        // The signed reply URL streams the bytes.
        $this->get($res->json('reply_audio_url'))->assertOk();
    }

    public function test_audio_endpoint_rejects_unsigned_request(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        $msg = AssistantMessage::create([
            'shop_id' => $shop->id, 'role' => 'assistant', 'content' => 'hi',
            'audio_path' => 'assistant/'.$shop->id.'/x.ogg', 'audio_mime' => 'audio/ogg',
        ]);
        Storage::disk('local')->put($msg->audio_path, 'BYTES');

        // No signature → 403 from the signed middleware.
        $this->get('/api/shop/assistant/audio/'.$msg->id)->assertForbidden();
    }

    public function test_transcription_failure_persists_nothing(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response('', 500)]);

        $audio = UploadedFile::fake()->createWithContent('voice.webm', 'VOICE', 'audio/webm');
        $this->post('/api/shop/assistant/voice', ['audio' => $audio])
            ->assertCreated()
            ->assertJsonPath('transcript', '');

        $this->assertDatabaseCount('assistant_messages', 0);
    }

    public function test_claude_failure_persists_nothing(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])->assertCreated();
        $this->assertDatabaseCount('assistant_messages', 0);
    }

    public function test_history_is_scoped_to_the_shop(): void
    {
        Storage::fake('local');
        $a = $this->authShop('1001');
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'A reply']]])]);
        $this->postJson('/api/shop/assistant/text', ['text' => 'a question'])->assertCreated();

        $b = $this->authShop('2002'); // switches acting shop
        $this->getJson('/api/shop/assistant/history')
            ->assertOk()
            ->assertJsonCount(0, 'messages');
    }

    public function test_clear_deletes_history_and_audio(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        $msg = AssistantMessage::create([
            'shop_id' => $shop->id, 'role' => 'assistant', 'content' => 'hi',
            'audio_path' => 'assistant/'.$shop->id.'/x.ogg', 'audio_mime' => 'audio/ogg',
        ]);
        Storage::disk('local')->put($msg->audio_path, 'BYTES');

        $this->deleteJson('/api/shop/assistant/history')->assertOk();

        $this->assertDatabaseCount('assistant_messages', 0);
        Storage::disk('local')->assertMissing('assistant/'.$shop->id.'/x.ogg');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `& "C:\Users\franc\.config\herd-lite\bin\php.exe" artisan test --filter AssistantConversationApiTest`
Expected: FAIL — routes/actions not defined (404s / method errors).

- [ ] **Step 3: Add the routes**

Replace `routes/api.php:148-151` (the assistant group) with:

```php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/shop/assistant/history',     [\App\Http\Controllers\OwnerAssistantController::class, 'history']);
    Route::delete('/shop/assistant/history',   [\App\Http\Controllers\OwnerAssistantController::class, 'clear']);
    Route::post('/shop/assistant/text',        [\App\Http\Controllers\OwnerAssistantController::class, 'text']);
    Route::post('/shop/assistant/voice',       [\App\Http\Controllers\OwnerAssistantController::class, 'voice']);
});

// Signed (not token-authed) so an <audio> element can load it directly; the
// signature both authorizes and prevents one shop forging another's URL.
Route::get('/shop/assistant/audio/{message}', [\App\Http\Controllers\OwnerAssistantController::class, 'audio'])
    ->name('assistant.audio')
    ->middleware('signed');
```

- [ ] **Step 4: Rewrite the controller**

Replace the whole body of `app/Http/Controllers/OwnerAssistantController.php` with:

```php
<?php
namespace App\Http\Controllers;

use App\Models\AssistantMessage;
use App\Models\Shop;
use App\Services\Assistant\ConversationStore;
use App\Services\Assistant\OwnerAssistantTools;
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
        protected OwnerAssistantTools $tools,
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
                OwnerAssistantTools::defs(),
                fn (string $tool, array $input) => $this->tools->execute($shop, $tool, $input),
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
```

- [ ] **Step 5: Update the old controller test to the new contract**

In `tests/Feature/OwnerAssistantControllerTest.php`, the endpoints no longer accept/echo `history` and no longer return a `history` key. Update:
- Remove the `history` request field from `postJson('/api/shop/assistant/text', ...)` and `post('/api/shop/assistant/voice', ...)` calls (drop `'history' => []` / `'history' => '[]'`).
- Remove any `assertJsonStructure(['reply_text','reply_audio_url','history'])` → use `['reply_text','reply_audio_url']`.
- Delete `test_empty_content_history_messages_are_not_sent_to_claude` (client no longer sends history; covered by server-owned context). 
- Keep: reply/audio, auth, transcribe-then-reply, claude-degrades-gracefully, transcription-failure-message. For the graceful/degrade tests add `Storage::fake('local');` at the top (they now touch the store only on success, but faking the disk keeps them hermetic).

Run to confirm the remaining old tests compile against the new contract:
Run: `& "C:\Users\franc\.config\herd-lite\bin\php.exe" artisan test --filter OwnerAssistantControllerTest`
Expected: PASS.

- [ ] **Step 6: Run the new feature test**

Run: `& "C:\Users\franc\.config\herd-lite\bin\php.exe" artisan test --filter AssistantConversationApiTest`
Expected: PASS (7 tests).

- [ ] **Step 7: Run the full assistant suite**

Run: `& "C:\Users\franc\.config\herd-lite\bin\php.exe" artisan test --filter Assistant`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/OwnerAssistantController.php routes/api.php tests/Feature/AssistantConversationApiTest.php tests/Feature/OwnerAssistantControllerTest.php
git commit -m "feat(assistant): server-side history, clear, and signed audio endpoints"
```

---

### Task 4: Frontend assistant lib

**Files:**
- Modify: `admin/src/lib/assistant.ts`
- Test: `admin/src/lib/assistant.test.ts` (create if absent)

**Interfaces:**
- Produces:
  - `type AssistantMsg = { id: number; role: 'user'|'assistant'; content: string; audio_url: string | null }`
  - `type AssistantReply = { transcript?: string; reply_text: string; reply_audio_url: string | null }`
  - `getHistory(): Promise<AssistantMsg[]>`
  - `clearHistory(): Promise<void>`
  - `postText(text: string): Promise<AssistantReply>`
  - `postVoice(audio: Blob): Promise<AssistantReply>`

- [ ] **Step 1: Write the failing test**

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { getHistory, clearHistory, postText } from './assistant';

vi.mock('./api', () => ({ default: { get: vi.fn(), post: vi.fn(), delete: vi.fn() } }));

describe('assistant lib', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getHistory returns the messages array', async () => {
    (api.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { messages: [{ id: 1, role: 'user', content: 'hi', audio_url: null }] } });
    const msgs = await getHistory();
    expect(api.get).toHaveBeenCalledWith('/shop/assistant/history');
    expect(msgs).toHaveLength(1);
    expect(msgs[0].content).toBe('hi');
  });

  it('clearHistory calls DELETE', async () => {
    (api.delete as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { ok: true } });
    await clearHistory();
    expect(api.delete).toHaveBeenCalledWith('/shop/assistant/history');
  });

  it('postText sends only the text (no history)', async () => {
    (api.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { reply_text: 'ok', reply_audio_url: null } });
    const r = await postText('how much');
    expect(api.post).toHaveBeenCalledWith('/shop/assistant/text', { text: 'how much' });
    expect(r.reply_text).toBe('ok');
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run (in `admin/`): `npx vitest run src/lib/assistant.test.ts`
Expected: FAIL — `getHistory`/`clearHistory` not exported; `postText` signature mismatch.

- [ ] **Step 3: Rewrite the lib**

```ts
import api from './api';

export type AssistantMsg = { id: number; role: 'user' | 'assistant'; content: string; audio_url: string | null };
export type AssistantReply = { transcript?: string; reply_text: string; reply_audio_url: string | null };

export async function getHistory(): Promise<AssistantMsg[]> {
  const { data } = await api.get('/shop/assistant/history');
  return data.messages as AssistantMsg[];
}

export async function clearHistory(): Promise<void> {
  await api.delete('/shop/assistant/history');
}

export async function postText(text: string): Promise<AssistantReply> {
  const { data } = await api.post('/shop/assistant/text', { text });
  return data;
}

export async function postVoice(audio: Blob): Promise<AssistantReply> {
  const form = new FormData();
  const ext = audio.type.split('/')[1]?.split(';')[0] || 'webm';
  form.append('audio', audio, `voice.${ext}`);
  // Override the shared JSON default so FormData is sent as multipart.
  const { data } = await api.post('/shop/assistant/voice', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run (in `admin/`): `npx vitest run src/lib/assistant.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src/lib/assistant.ts admin/src/lib/assistant.test.ts
git commit -m "feat(assistant): frontend lib fetches server history, drops client history"
```

---

### Task 5: Frontend page — fetch server history, drop localStorage

**Files:**
- Modify: `admin/src/pages/VoiceAssistant.tsx`
- Modify: `admin/src/pages/VoiceAssistant.test.tsx`

**Interfaces:**
- Consumes: `getHistory`, `clearHistory`, `postText`, `postVoice`, `AssistantMsg` (Task 4).

- [ ] **Step 1: Rewrite the page's data layer**

In `admin/src/pages/VoiceAssistant.tsx`:

1. Update imports:
```ts
import { getHistory, clearHistory, postText, postVoice } from '@/lib/assistant';
```
Remove the `storage` import and the `AssistantTurn` import. Remove `STORAGE_PREFIX`, `conversationKey`, and `loadSaved` (all localStorage code) and the `useMemo` storage-key line.

2. Replace the state init and add a load effect:
```ts
const [messages, setMessages] = useState<Msg[]>([]);
const [loadingHistory, setLoadingHistory] = useState(true);
// ...existing busy/draft/error/recorder/threadRef...
const restoredCount = useRef(0);

// Load this shop's conversation from the server on open. The server scopes by
// auth token, so there is no cross-shop leak and no local persistence.
useEffect(() => {
  let alive = true;
  getHistory()
    .then((history) => {
      if (!alive) return;
      const msgs: Msg[] = history.map((m) => ({ role: m.role, content: m.content, audioUrl: m.audio_url }));
      restoredCount.current = msgs.length;
      setMessages(msgs);
    })
    .catch(() => { if (alive) setError('Could not load your conversation.'); })
    .finally(() => { if (alive) setLoadingHistory(false); });
  return () => { alive = false; };
}, []);
```

3. Delete the localStorage persistence `useEffect` entirely.

4. Replace `clearConversation`:
```ts
async function clearConversation() {
  setError('');
  try { await clearHistory(); } catch { setError('Could not clear the conversation.'); return; }
  setMessages([]);
  restoredCount.current = 0;
}
```

5. Remove `historyToSend`. Update `send`:
```ts
async function send(text: string) {
  if (!text.trim() || busy) return;
  setBusy(true); setError('');
  setMessages((m) => [...m, { role: 'user', content: text }]);
  setDraft('');
  try {
    const res = await postText(text);
    setMessages((m) => [...m, { role: 'assistant', content: res.reply_text, audioUrl: res.reply_audio_url }]);
  } catch { setError('Could not reach the assistant.'); }
  finally { setBusy(false); }
}
```

6. Update `toggleMic` (drop history arg):
```ts
const res = await postVoice(blob);
```
(everything else in `toggleMic` stays; it still appends the user voice bubble with the local `voiceUrl` blob for instant playback and the assistant bubble with `res.reply_audio_url`.)

7. In the thread render, show a loading state before the empty state:
```tsx
{loadingHistory && <div className="va-bubble va-ai va-typing">…</div>}
{!loadingHistory && messages.length === 0 && !busy && (
  <div className="va-empty"> ...unchanged... </div>
)}
```

- [ ] **Step 2: Update the page tests**

Rewrite `admin/src/pages/VoiceAssistant.test.tsx` to mock the new lib (no localStorage):

```ts
vi.mock('@/lib/assistant', () => ({
  getHistory: vi.fn().mockResolvedValue([]),
  clearHistory: vi.fn().mockResolvedValue(undefined),
  postText: vi.fn().mockResolvedValue({ reply_text: 'You made 50 dirhams.', reply_audio_url: null }),
  postVoice: vi.fn(),
}));
```

Key test updates:
- Every test must `await` the initial history load. After `render`, use `await screen.findByPlaceholderText(/type/i)` or `await waitFor(...)` before asserting, since the empty state now appears post-fetch.
- "renders server history on open": `(getHistory as ...).mockResolvedValueOnce([{ id: 1, role: 'assistant', content: 'welcome back', audio_url: null }])`, render, `expect(await screen.findByText('welcome back')).toBeInTheDocument()`.
- "shows the assistant text reply": unchanged interaction; keep `postText` mock returning the reply.
- "clears the conversation" test: click clear, assert `clearHistory` was called and the reply text is gone:
```ts
import { clearHistory } from '@/lib/assistant';
// ...
fireEvent.click(screen.getByRole('button', { name: /clear conversation/i }));
await waitFor(() => expect(clearHistory).toHaveBeenCalled());
expect(screen.queryByText('You made 50 dirhams.')).not.toBeInTheDocument();
```
- Delete the two localStorage-specific tests ("restores from storage", "keeps each shop's conversation separate") — persistence is now server-side and covered by backend tests. Replace with the "renders server history on open" test above.
- Keep the audio-player tests (they exercise `postText`/`postVoice` returning `reply_audio_url`).

- [ ] **Step 3: Run the page tests**

Run (in `admin/`): `npx vitest run src/pages/VoiceAssistant.test.tsx`
Expected: PASS.

- [ ] **Step 4: Typecheck**

Run (in `admin/`): `npx tsc --noEmit`
Expected: exit 0 (no errors; confirms all `storage`/`AssistantTurn`/`historyToSend` references are gone).

- [ ] **Step 5: Commit**

```bash
git add admin/src/pages/VoiceAssistant.tsx admin/src/pages/VoiceAssistant.test.tsx
git commit -m "feat(assistant): page loads server history, drops localStorage"
```

---

### Task 6: Deploy + live verification

**Files:** none (deploy only).

- [ ] **Step 1: Full backend suite green**

Run: `& "C:\Users\franc\.config\herd-lite\bin\php.exe" artisan test`
Expected: PASS.

- [ ] **Step 2: Full frontend suite + build**

Run (in `admin/`): `npx vitest run` then `npm run build`
Expected: all tests pass; build exit 0.

- [ ] **Step 3: Push**

```bash
git push origin main
```

- [ ] **Step 4: Deploy backend (runs migration)**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && git checkout -- "*.gitignore" 2>/dev/null; git pull --ff-only && php8.4 artisan migrate --force && php8.4 artisan config:clear && php8.4 artisan config:cache && php8.4 artisan route:cache && chown -R www-data:www-data . && systemctl reload php8.4-fpm && echo DONE'
```
Expected: migration runs; `DONE`.

- [ ] **Step 5: Deploy admin frontend**

```bash
cd admin && scp -q dist/assets/* root@64.227.153.90:/var/www/admin/assets/ && scp -q dist/index.html root@64.227.153.90:/var/www/admin/index.html && ssh root@64.227.153.90 'chown -R www-data:www-data /var/www/admin && echo DONE'
```

- [ ] **Step 6: Live verify (mint temp token, exercise, revoke)**

```bash
TOK=$(ssh root@64.227.153.90 'cd /var/www/eloquent-backend && php8.4 artisan tinker --execute='"'"'$s=\App\Models\Shop::query()->first(); echo $s->createToken("__diag_temp",["*"])->plainTextToken;'"'"' 2>/dev/null')
# post a turn, then confirm history returns it
curl -s -X POST https://api.eloquentservice.com/api/shop/assistant/text -H "Authorization: Bearer $TOK" -H "Accept: application/json" -H "Content-Type: application/json" -d '{"text":"how much did I make today"}' -o /dev/null -w "text -> %{http_code}\n"
curl -s https://api.eloquentservice.com/api/shop/assistant/history -H "Authorization: Bearer $TOK" -H "Accept: application/json" | head -c 400; echo
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && php8.4 artisan tinker --execute='"'"'echo \Laravel\Sanctum\PersonalAccessToken::where("name","__diag_temp")->delete();'"'"' 2>/dev/null'
```
Expected: `text -> 201`; history JSON contains the question and its reply. Token revoked.

- [ ] **Step 7: Final commit (if any deploy notes) — none required.**

---

## Self-Review notes

- **Spec coverage:** table + model (T1); store with audio + capping + clear + signed URLs (T2); history/text/voice/clear/audio endpoints, scoping, failure-persists-nothing (T3); frontend lib (T4); page fetch + drop localStorage + clear (T5); migrate-on-deploy (T6). All spec sections mapped.
- **Deviation from spec (intentional):** spec left "persist failed replies?" open; plan decides **persist nothing on failure** (both transcription and Claude), keeping stored history strictly alternating and retry-clean. Reflected in T3 tests.
- **Type consistency:** `AssistantReply`/`AssistantMsg` names match across T4/T5; `store->append/contextFor/clear/signedUrl/toApi` signatures match across T2/T3; route name `assistant.audio` matches T2 `signedUrl` and T3 route.
- **Audio playback auth:** signed URLs (not Bearer) so `<audio src>` works; verified by T3 `test_audio_endpoint_rejects_unsigned_request` and the streamed-bytes assertion.
