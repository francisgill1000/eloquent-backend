# Assistant Conversation Threads Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give the owner assistant ChatGPT-style conversation threads — each mic tap opens a fresh thread, past threads are saved/reopenable in a slide-in drawer, and each thread is an isolated AI context.

**Architecture:** Add a `conversations` table and a `conversation_id` FK on `assistant_messages`. Re-scope `ConversationStore` and `OwnerAssistantController` from per-shop to per-conversation, with lazy thread creation on the first successful message. Frontend gains `/ask/:conversationId` routing, a conversation-id-aware `VoiceAssistant`, and a history drawer.

**Tech Stack:** Laravel 11 (PHP 8.4), Eloquent, Sanctum; React + TypeScript + Vite (admin SPA), React Router, Vitest/RTL. Backend tests run on the droplet (php8.4), never locally.

## Global Constraints

- **Multi-tenant safety:** Every conversation endpoint scopes by the authenticated shop (`$request->user()` IS the Shop). Accessing another shop's conversation returns `404`. Never read `shop_id` from the request; never hardcode any shop identity. Titles come only from user content.
- **Persist-on-success:** A turn (and, for a new thread, the thread itself) is persisted ONLY when Claude returns a non-empty reply. Failures persist nothing.
- **Lazy thread creation:** A `conversations` row is created only when the first message of a new thread succeeds.
- **Backfill:** Existing `assistant_messages` are grouped into one thread per shop titled exactly `"Previous chat"`.
- **Audio paths:** `assistant/{shop_id}/{conversation_id}/{uuid}.{ext}`.
- **Testing DB:** Backend tests use `RefreshDatabase` on a scratch DB on the droplet. NEVER run against prod. Frontend: Vitest.
- **Rollout:** local → staging (64.227.153.90) → prod. Admin frontend deploys via `admin/deploy.ps1`.

---

## File Structure

**Backend**
- Create: `database/migrations/2026_07_07_000001_create_conversations_and_link_assistant_messages.php` — conversations table, `conversation_id` column, backfill, index.
- Create: `app/Models/Conversation.php` — thread model + cascade-delete hook.
- Modify: `app/Models/AssistantMessage.php` — add `conversation_id` fillable + `conversation()` relation.
- Modify: `app/Services/Assistant/ConversationStore.php` — re-scope to a conversation; add create/list/messagesFor/rename/delete.
- Modify: `app/Http/Controllers/OwnerAssistantController.php` — conversation endpoints + conversation-aware text/voice.
- Modify: `routes/api.php:190-193` — replace history/clear routes with conversation routes.
- Modify/rewrite tests: `tests/Feature/ConversationStoreTest.php`, `tests/Feature/AssistantConversationApiTest.php`.

**Frontend (admin SPA)**
- Modify: `admin/src/lib/assistant.ts` — conversation types + functions; conversation-id on text/voice.
- Modify: `admin/src/App.tsx:91` — add `/ask/:conversationId` route.
- Modify: `admin/src/pages/VoiceAssistant.tsx` — conversation-id state, load-by-route, drawer.
- Modify: `admin/src/styles/desktop.css` — drawer styles.
- Rewrite test: `admin/src/pages/VoiceAssistant.test.tsx`.

---

## Task 1: Database — conversations table, link, backfill

**Files:**
- Create: `database/migrations/2026_07_07_000001_create_conversations_and_link_assistant_messages.php`
- Create: `app/Models/Conversation.php`
- Modify: `app/Models/AssistantMessage.php`
- Test: `tests/Feature/ConversationMigrationTest.php` (create)

**Interfaces:**
- Produces:
  - Table `conversations(id, shop_id, title, created_at, updated_at)`, index `['shop_id','updated_at']`.
  - Column `assistant_messages.conversation_id` (unsignedBigInteger, nullable), index `['conversation_id','id']`.
  - `App\Models\Conversation` with `$fillable = ['shop_id','title']`, `messages(): HasMany`, `shop(): BelongsTo`, and a `deleting` hook that deletes child messages (so audio cleanup runs).
  - `AssistantMessage::$fillable` includes `conversation_id`; `AssistantMessage::conversation(): BelongsTo`.

- [ ] **Step 1: Write the failing migration/backfill test**

Create `tests/Feature/ConversationMigrationTest.php`:

```php
<?php
namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\AssistantMessage;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ConversationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        return Shop::create(['name' => 'S'.$code, 'shop_code' => $code, 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_schema_links_messages_to_conversations(): void
    {
        // Column + relations exist and a message can carry a conversation_id.
        $shop = $this->shop('1001');
        $c = Conversation::create(['shop_id' => $shop->id, 'title' => 'Previous chat']);
        $m = AssistantMessage::create(['shop_id' => $shop->id, 'conversation_id' => $c->id, 'role' => 'user', 'content' => 'hi']);

        $this->assertSame($c->id, $m->fresh()->conversation->id);
        $this->assertSame(1, $c->messages()->count());
    }

    public function test_conversation_delete_cascades_messages_and_audio(): void
    {
        \Illuminate\Support\Facades\Storage::fake('local');
        $shop = $this->shop('1001');
        $c = Conversation::create(['shop_id' => $shop->id, 'title' => 'Previous chat']);
        $m = AssistantMessage::create([
            'shop_id' => $shop->id, 'conversation_id' => $c->id, 'role' => 'assistant', 'content' => 'hi',
            'audio_path' => "assistant/{$shop->id}/{$c->id}/x.ogg", 'audio_mime' => 'audio/ogg',
        ]);
        \Illuminate\Support\Facades\Storage::disk('local')->put($m->audio_path, 'BYTES');

        $c->delete();

        $this->assertDatabaseCount('assistant_messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        \Illuminate\Support\Facades\Storage::disk('local')->assertMissing("assistant/{$shop->id}/{$c->id}/x.ogg");
    }
}
```

> **Backfill note:** `RefreshDatabase` migrates a fresh (empty) DB, so there are no legacy rows to observe at migration time — the backfill loop cannot be meaningfully asserted in a `RefreshDatabase` test. It is instead verified on **staging** (Task 7, Step 3): after running the migration, confirm one "Previous chat" thread exists per shop that had messages. The schema link + cascade delete are the unit-testable pieces here.

- [ ] **Step 2: Run test to verify it fails**

Run (on droplet): `php artisan test --filter=ConversationMigrationTest`
Expected: FAIL — `Class "App\Models\Conversation" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_07_000001_create_conversations_and_link_assistant_messages.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            $table->string('title');
            $table->timestamps();
            $table->index(['shop_id', 'updated_at']);
        });

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->unsignedBigInteger('conversation_id')->nullable()->after('shop_id');
        });

        // Backfill: bundle each shop's existing messages into one "Previous chat".
        $shopIds = DB::table('assistant_messages')->distinct()->pluck('shop_id');
        foreach ($shopIds as $shopId) {
            $range = DB::table('assistant_messages')
                ->where('shop_id', $shopId)
                ->selectRaw('MIN(created_at) as mn, MAX(created_at) as mx')
                ->first();

            $conversationId = DB::table('conversations')->insertGetId([
                'shop_id' => $shopId,
                'title' => 'Previous chat',
                'created_at' => $range->mn ?? now(),
                'updated_at' => $range->mx ?? now(),
            ]);

            DB::table('assistant_messages')
                ->where('shop_id', $shopId)
                ->update(['conversation_id' => $conversationId]);
        }

        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        Schema::table('assistant_messages', function (Blueprint $table) {
            $table->dropIndex(['conversation_id', 'id']);
            $table->dropColumn('conversation_id');
        });
        Schema::dropIfExists('conversations');
    }
};
```

- [ ] **Step 4: Write the `Conversation` model**

Create `app/Models/Conversation.php`:

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Conversation extends Model
{
    protected $fillable = ['shop_id', 'title'];

    protected static function booted(): void
    {
        // Deleting a thread must delete its messages one-by-one so each
        // AssistantMessage's deleting hook removes its audio file.
        static::deleting(function (Conversation $c) {
            $c->messages()->get()->each->delete();
        });
    }

    public function messages(): HasMany
    {
        return $this->hasMany(AssistantMessage::class);
    }

    public function shop(): BelongsTo
    {
        return $this->belongsTo(Shop::class);
    }
}
```

- [ ] **Step 5: Add the relation + fillable to `AssistantMessage`**

Modify `app/Models/AssistantMessage.php`:

```php
    protected $fillable = ['shop_id', 'conversation_id', 'role', 'content', 'audio_path', 'audio_mime'];
```

And add, after the `shop()` method:

```php
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(Conversation::class);
    }
```

- [ ] **Step 6: Run the test to verify it passes**

Run (on droplet): `php artisan test --filter=ConversationMigrationTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_07_000001_create_conversations_and_link_assistant_messages.php app/Models/Conversation.php app/Models/AssistantMessage.php tests/Feature/ConversationMigrationTest.php
git commit -m "feat(assistant): conversations table + link assistant_messages (with backfill)"
```

---

## Task 2: ConversationStore — re-scope to a conversation

**Files:**
- Modify: `app/Services/Assistant/ConversationStore.php`
- Test: `tests/Feature/ConversationStoreTest.php` (rewrite)

**Interfaces:**
- Consumes: `Conversation`, `AssistantMessage`, `Shop` from Task 1.
- Produces (on `ConversationStore`):
  - `create(Shop $shop, string $firstUserText): Conversation`
  - `list(Shop $shop): array` — items `['id'=>int,'title'=>string,'updated_at'=>string]`, newest `updated_at` first (tiebreak `id` desc).
  - `contextFor(Conversation $c, int $limit = 20): array` — last N turns `[['role'=>,'content'=>], ...]` chronological.
  - `append(Conversation $c, string $role, string $content, ?string $audioBytes = null, ?string $audioMime = null): AssistantMessage` — writes with `conversation_id`, audio under `assistant/{shop_id}/{conversation_id}/{uuid}.{ext}`, touches the conversation's `updated_at`.
  - `messagesFor(Conversation $c): array` — chronological, each via `toApi`.
  - `rename(Conversation $c, string $title): void`
  - `delete(Conversation $c): void`
  - unchanged: `signedUrl(AssistantMessage): ?string`, `toApi(AssistantMessage): array`.

- [ ] **Step 1: Rewrite the failing test**

Replace the entire contents of `tests/Feature/ConversationStoreTest.php`:

```php
<?php
namespace Tests\Feature;

use App\Models\Conversation;
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

    private function conv(Shop $shop, string $title = 'T'): Conversation
    {
        return Conversation::create(['shop_id' => $shop->id, 'title' => $title]);
    }

    public function test_create_titles_from_first_message_truncated(): void
    {
        $store = app(ConversationStore::class);
        $shop = $this->shop();
        $long = str_repeat('a', 200);

        $c = $store->create($shop, "  How much   did I make? ");
        $this->assertSame('How much did I make?', $c->title);

        $c2 = $store->create($shop, $long);
        $this->assertSame(61, mb_strlen($c2->title)); // 60 chars + ellipsis
        $this->assertStringEndsWith('…', $c2->title);

        $c3 = $store->create($shop, '   ');
        $this->assertSame('New chat', $c3->title);
    }

    public function test_append_stores_row_audio_and_touches_conversation(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $c = $this->conv($shop);
        $store = app(ConversationStore::class);

        $before = $c->updated_at;
        $msg = $store->append($c, 'assistant', 'fifty dirhams', 'OGGBYTES', 'audio/ogg');

        $this->assertDatabaseHas('assistant_messages', [
            'id' => $msg->id, 'conversation_id' => $c->id, 'shop_id' => $shop->id, 'role' => 'assistant',
        ]);
        Storage::disk('local')->assertExists($msg->audio_path);
        $this->assertStringStartsWith("assistant/{$shop->id}/{$c->id}/", $msg->audio_path);
        $this->assertTrue($c->fresh()->updated_at->gte($before));
    }

    public function test_context_is_isolated_to_the_conversation(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $a = $this->conv($shop, 'A');
        $b = $this->conv($shop, 'B');

        $store->append($a, 'user', 'in-a');
        $store->append($b, 'user', 'in-b');

        $ctx = $store->contextFor($a);
        $this->assertCount(1, $ctx);
        $this->assertSame('in-a', $ctx[0]['content']);
        $this->assertSame(['role', 'content'], array_keys($ctx[0]));
    }

    public function test_context_caps_and_orders(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $c = $this->conv($shop);
        for ($i = 0; $i < 25; $i++) {
            $store->append($c, $i % 2 === 0 ? 'user' : 'assistant', "m{$i}");
        }
        $ctx = $store->contextFor($c, 20);
        $this->assertCount(20, $ctx);
        $this->assertSame('m5', $ctx[0]['content']);
        $this->assertSame('m24', $ctx[19]['content']);
    }

    public function test_list_returns_shop_threads_newest_first(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $old = $this->conv($shop, 'Old');
        $new = $this->conv($shop, 'New');
        // Make $new the most recently updated.
        $store->append($new, 'user', 'x');

        $list = $store->list($shop);
        $this->assertSame(['id', 'title', 'updated_at'], array_keys($list[0]));
        $this->assertSame($new->id, $list[0]['id']);
        $this->assertSame($old->id, $list[1]['id']);
    }

    public function test_messages_for_returns_chronological_api_shape(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $c = $this->conv($shop);
        $store->append($c, 'user', 'first');
        $store->append($c, 'assistant', 'second');

        $msgs = $store->messagesFor($c);
        $this->assertCount(2, $msgs);
        $this->assertSame('first', $msgs[0]['content']);
        $this->assertSame(['id', 'role', 'content', 'audio_url'], array_keys($msgs[0]));
    }

    public function test_rename_updates_title(): void
    {
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $c = $this->conv($shop, 'Old');
        $store->rename($c, 'New name');
        $this->assertSame('New name', $c->fresh()->title);
    }

    public function test_delete_removes_thread_messages_and_audio(): void
    {
        Storage::fake('local');
        $shop = $this->shop();
        $store = app(ConversationStore::class);
        $c = $this->conv($shop);
        $msg = $store->append($c, 'assistant', 'hi', 'BYTES', 'audio/ogg');
        $path = $msg->audio_path;

        $store->delete($c);

        $this->assertDatabaseCount('assistant_messages', 0);
        $this->assertDatabaseCount('conversations', 0);
        Storage::disk('local')->assertMissing($path);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (on droplet): `php artisan test --filter=ConversationStoreTest`
Expected: FAIL — `create()` / conversation-typed `append()` do not exist.

- [ ] **Step 3: Rewrite `ConversationStore`**

Replace the entire contents of `app/Services/Assistant/ConversationStore.php`:

```php
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

    /** Threads for the drawer, newest activity first. */
    public function list(Shop $shop): array
    {
        return Conversation::where('shop_id', $shop->id)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->get(['id', 'title', 'updated_at'])
            ->map(fn (Conversation $c) => [
                'id' => $c->id,
                'title' => $c->title,
                'updated_at' => $c->updated_at?->toIso8601String(),
            ])
            ->all();
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
```

- [ ] **Step 4: Run test to verify it passes**

Run (on droplet): `php artisan test --filter=ConversationStoreTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/ConversationStore.php tests/Feature/ConversationStoreTest.php
git commit -m "feat(assistant): re-scope ConversationStore to per-conversation threads"
```

---

## Task 3: Controller + routes — conversation endpoints & lazy create

**Files:**
- Modify: `app/Http/Controllers/OwnerAssistantController.php`
- Modify: `routes/api.php:190-193`
- Test: `tests/Feature/AssistantConversationApiTest.php` (rewrite)

**Interfaces:**
- Consumes: `ConversationStore` methods from Task 2.
- Produces routes (in the `auth:sanctum + rbac.context + subscription.active` group):
  - `GET  /shop/assistant/conversations` → `conversations` → `{ conversations: [{id,title,updated_at}] }`
  - `GET  /shop/assistant/conversations/{conversation}` → `messages` → `{ messages: [...] }`
  - `PATCH /shop/assistant/conversations/{conversation}` → `rename` → `{ ok: true, title }`
  - `DELETE /shop/assistant/conversations/{conversation}` → `destroy` → `{ ok: true }`
  - `POST /shop/assistant/text` → `text` (body: `text`, optional `conversation_id`)
  - `POST /shop/assistant/voice` → `voice` (multipart: `audio`, optional `conversation_id`)
  - Success payloads for text/voice include `conversation_id` (int) and `title` (string).

- [ ] **Step 1: Rewrite the failing API test**

Replace the entire contents of `tests/Feature/AssistantConversationApiTest.php`:

```php
<?php
namespace Tests\Feature;

use App\Models\AssistantMessage;
use App\Models\Conversation;
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
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);
        return $shop;
    }

    private function fakeClaude(string $reply = 'Fifty dirhams.'): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => $reply]]])]);
    }

    public function test_first_text_lazily_creates_a_thread_titled_from_message(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        $this->fakeClaude();

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])
            ->assertCreated()
            ->assertJsonPath('reply_text', 'Fifty dirhams.')
            ->assertJsonPath('title', 'how much today');
        $cid = $res->json('conversation_id');
        $this->assertIsInt($cid);

        $this->assertDatabaseHas('conversations', ['id' => $cid, 'shop_id' => $shop->id, 'title' => 'how much today']);
        $this->getJson("/api/shop/assistant/conversations/{$cid}")
            ->assertOk()
            ->assertJsonPath('messages.0.content', 'how much today')
            ->assertJsonPath('messages.1.content', 'Fifty dirhams.');
    }

    public function test_second_turn_appends_to_same_thread_with_isolated_context(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        $this->fakeClaude();

        $cid = $this->postJson('/api/shop/assistant/text', ['text' => 'first'])->json('conversation_id');
        // A separate thread whose messages must NOT leak into the first thread.
        $other = Conversation::create(['shop_id' => $shop->id, 'title' => 'Other']);
        AssistantMessage::create(['shop_id' => $shop->id, 'conversation_id' => $other->id, 'role' => 'user', 'content' => 'LEAK']);

        $this->postJson('/api/shop/assistant/text', ['text' => 'second', 'conversation_id' => $cid])
            ->assertCreated()
            ->assertJsonPath('conversation_id', $cid);

        $msgs = $this->getJson("/api/shop/assistant/conversations/{$cid}")->json('messages');
        $this->assertCount(4, $msgs); // first Q/A + second Q/A, no LEAK
        $this->assertNotContains('LEAK', array_column($msgs, 'content'));
    }

    public function test_claude_failure_creates_no_thread_and_no_messages(): void
    {
        Storage::fake('local');
        $this->authShop();
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'boom'], 500)]);

        $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])->assertCreated();
        $this->assertDatabaseCount('conversations', 0);
        $this->assertDatabaseCount('assistant_messages', 0);
    }

    public function test_conversation_list_is_scoped_and_newest_first(): void
    {
        Storage::fake('local');
        $shop = $this->authShop('1001');
        $this->fakeClaude();
        $this->postJson('/api/shop/assistant/text', ['text' => 'older'])->assertCreated();
        $this->postJson('/api/shop/assistant/text', ['text' => 'newer'])->assertCreated();

        $list = $this->getJson('/api/shop/assistant/conversations')->assertOk()->json('conversations');
        $this->assertCount(2, $list);
        $this->assertSame('newer', $list[0]['title']);

        $this->authShop('2002'); // different shop sees none
        $this->getJson('/api/shop/assistant/conversations')->assertOk()->assertJsonCount(0, 'conversations');
    }

    public function test_rename_and_delete_are_shop_scoped(): void
    {
        Storage::fake('local');
        $shop = $this->authShop('1001');
        $this->fakeClaude();
        $cid = $this->postJson('/api/shop/assistant/text', ['text' => 'hello'])->json('conversation_id');

        $this->patchJson("/api/shop/assistant/conversations/{$cid}", ['title' => 'Renamed'])
            ->assertOk()->assertJsonPath('title', 'Renamed');
        $this->assertDatabaseHas('conversations', ['id' => $cid, 'title' => 'Renamed']);

        // Another shop cannot touch it.
        $this->authShop('2002');
        $this->patchJson("/api/shop/assistant/conversations/{$cid}", ['title' => 'Hijack'])->assertNotFound();
        $this->deleteJson("/api/shop/assistant/conversations/{$cid}")->assertNotFound();
        $this->getJson("/api/shop/assistant/conversations/{$cid}")->assertNotFound();

        // Owner can delete it.
        Sanctum::actingAs($shop, ['*']);
        $this->deleteJson("/api/shop/assistant/conversations/{$cid}")->assertOk();
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_voice_turn_persists_both_audios_under_conversation_and_serves_them(): void
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
        $this->assertIsInt($res->json('conversation_id'));

        $this->assertDatabaseCount('assistant_messages', 2);
        foreach (AssistantMessage::all() as $m) {
            Storage::disk('local')->assertExists($m->audio_path);
        }
        $this->get($res->json('reply_audio_url'))->assertOk();
    }

    public function test_audio_endpoint_rejects_unsigned_request(): void
    {
        Storage::fake('local');
        $shop = $this->authShop();
        $c = Conversation::create(['shop_id' => $shop->id, 'title' => 'T']);
        $msg = AssistantMessage::create([
            'shop_id' => $shop->id, 'conversation_id' => $c->id, 'role' => 'assistant', 'content' => 'hi',
            'audio_path' => 'assistant/'.$shop->id.'/'.$c->id.'/x.ogg', 'audio_mime' => 'audio/ogg',
        ]);
        Storage::disk('local')->put($msg->audio_path, 'BYTES');

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
        $this->assertDatabaseCount('conversations', 0);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (on droplet): `php artisan test --filter=AssistantConversationApiTest`
Expected: FAIL — new routes/methods (`conversations`, `conversation_id` in payload) don't exist.

- [ ] **Step 3: Rewrite the controller**

Replace the entire contents of `app/Http/Controllers/OwnerAssistantController.php`:

```php
<?php
namespace App\Http\Controllers;

use App\Models\AssistantMessage;
use App\Models\Conversation;
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
```

- [ ] **Step 4: Replace the routes**

In `routes/api.php`, replace the four lines at 190-193 (the `history`, `clear`, `text`, `voice` routes) with:

```php
    Route::get('/shop/assistant/conversations',                 [\App\Http\Controllers\OwnerAssistantController::class, 'conversations']);
    Route::get('/shop/assistant/conversations/{conversation}',  [\App\Http\Controllers\OwnerAssistantController::class, 'messages']);
    Route::patch('/shop/assistant/conversations/{conversation}',[\App\Http\Controllers\OwnerAssistantController::class, 'rename']);
    Route::delete('/shop/assistant/conversations/{conversation}',[\App\Http\Controllers\OwnerAssistantController::class, 'destroy']);
    Route::post('/shop/assistant/text',                         [\App\Http\Controllers\OwnerAssistantController::class, 'text']);
    Route::post('/shop/assistant/voice',                        [\App\Http\Controllers\OwnerAssistantController::class, 'voice']);
```

(The signed `/shop/assistant/audio/{message}` route at lines 218-220 is unchanged.)

- [ ] **Step 5: Run tests to verify they pass**

Run (on droplet): `php artisan test --filter=AssistantConversationApiTest`
Expected: PASS (8 tests).

Then run the whole assistant-adjacent suite to confirm no regressions:
Run: `php artisan test --filter=Assistant`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/OwnerAssistantController.php routes/api.php tests/Feature/AssistantConversationApiTest.php
git commit -m "feat(assistant): conversation thread endpoints + lazy-create text/voice"
```

---

## Task 4: Frontend API client

**Files:**
- Modify: `admin/src/lib/assistant.ts`

**Interfaces:**
- Produces:
  - `type Conversation = { id: number; title: string; updated_at: string }`
  - `type AssistantReply = { conversation_id?: number; title?: string; transcript?: string; reply_text: string; reply_audio_url: string | null }`
  - `listConversations(): Promise<Conversation[]>`
  - `getConversation(id: number): Promise<AssistantMsg[]>`
  - `renameConversation(id: number, title: string): Promise<void>`
  - `deleteConversation(id: number): Promise<void>`
  - `postText(text: string, conversationId?: number): Promise<AssistantReply>`
  - `postVoice(audio: Blob, conversationId?: number): Promise<AssistantReply>`

- [ ] **Step 1: Rewrite the client**

Replace the entire contents of `admin/src/lib/assistant.ts`:

```ts
import api from './api';

export type AssistantMsg = { id: number; role: 'user' | 'assistant'; content: string; audio_url: string | null };
export type Conversation = { id: number; title: string; updated_at: string };
export type AssistantReply = {
  conversation_id?: number;
  title?: string;
  transcript?: string;
  reply_text: string;
  reply_audio_url: string | null;
};

export async function listConversations(): Promise<Conversation[]> {
  const { data } = await api.get('/shop/assistant/conversations');
  return data.conversations as Conversation[];
}

export async function getConversation(id: number): Promise<AssistantMsg[]> {
  const { data } = await api.get(`/shop/assistant/conversations/${id}`);
  return data.messages as AssistantMsg[];
}

export async function renameConversation(id: number, title: string): Promise<void> {
  await api.patch(`/shop/assistant/conversations/${id}`, { title });
}

export async function deleteConversation(id: number): Promise<void> {
  await api.delete(`/shop/assistant/conversations/${id}`);
}

export async function postText(text: string, conversationId?: number): Promise<AssistantReply> {
  const { data } = await api.post('/shop/assistant/text', { text, conversation_id: conversationId });
  return data;
}

export async function postVoice(audio: Blob, conversationId?: number): Promise<AssistantReply> {
  const form = new FormData();
  const ext = audio.type.split('/')[1]?.split(';')[0] || 'webm';
  form.append('audio', audio, `voice.${ext}`);
  if (conversationId != null) form.append('conversation_id', String(conversationId));
  // Override the shared api's JSON default so the FormData is sent as multipart.
  const { data } = await api.post('/shop/assistant/voice', form, {
    headers: { 'Content-Type': 'multipart/form-data' },
  });
  return data;
}
```

- [ ] **Step 2: Typecheck**

Run (in `admin/`): `npx tsc --noEmit`
Expected: no errors from `assistant.ts` (there will be errors in `VoiceAssistant.tsx`/tests until later tasks — that's fine; confirm none originate in `assistant.ts`).

- [ ] **Step 3: Commit**

```bash
git add admin/src/lib/assistant.ts
git commit -m "feat(assistant): conversation-aware API client"
```

---

## Task 5: Routing + VoiceAssistant conversation state

**Files:**
- Modify: `admin/src/App.tsx:91`
- Modify: `admin/src/pages/VoiceAssistant.tsx`
- Test: `admin/src/pages/VoiceAssistant.test.tsx` (rewrite)

**Interfaces:**
- Consumes: `listConversations`, `getConversation`, `renameConversation`, `deleteConversation`, `postText`, `postVoice` from Task 4.
- Behavior:
  - `/ask` (no param) → brand-new empty thread (no history fetched).
  - `/ask/:conversationId` → loads that thread's messages.
  - First successful send in a new thread adopts the returned `conversation_id` and updates the route (`navigate('/ask/'+id, {replace:true})`).
  - A history drawer lists threads, opens/renames/deletes them, and offers "New chat".

- [ ] **Step 1: Add the route**

In `admin/src/App.tsx`, immediately after line 91 (`<Route path="/ask" element={<VoiceAssistant />} />`), add:

```tsx
          <Route path="/ask/:conversationId" element={<VoiceAssistant />} />
```

- [ ] **Step 2: Rewrite the failing test**

Replace the entire contents of `admin/src/pages/VoiceAssistant.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeAll, beforeEach } from 'vitest';
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import {
  getConversation, listConversations, renameConversation, deleteConversation, postText,
} from '@/lib/assistant';
import VoiceAssistant from './VoiceAssistant';

const navigate = vi.fn();
let params: { conversationId?: string } = {};
vi.mock('react-router-dom', () => ({
  useNavigate: () => navigate,
  useParams: () => params,
}));
vi.mock('@/lib/assistant', () => ({
  getConversation: vi.fn().mockResolvedValue([]),
  listConversations: vi.fn().mockResolvedValue([]),
  renameConversation: vi.fn().mockResolvedValue(undefined),
  deleteConversation: vi.fn().mockResolvedValue(undefined),
  postText: vi.fn().mockResolvedValue({ conversation_id: 9, title: 'how much', reply_text: 'You made 50 dirhams.', reply_audio_url: null }),
  postVoice: vi.fn(),
}));
vi.mock('@/hooks/useRecorder', () => ({
  useRecorder: () => ({ recording: false, start: vi.fn(), stop: vi.fn(), supported: true }),
}));

const asMock = (fn: unknown) => fn as unknown as ReturnType<typeof vi.fn>;

beforeAll(() => {
  window.HTMLMediaElement.prototype.play = vi.fn().mockResolvedValue(undefined);
  window.HTMLMediaElement.prototype.pause = vi.fn();
});

describe('VoiceAssistant page', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    params = {};
    asMock(getConversation).mockResolvedValue([]);
    asMock(listConversations).mockResolvedValue([]);
    asMock(postText).mockResolvedValue({ conversation_id: 9, title: 'how much', reply_text: 'You made 50 dirhams.', reply_audio_url: null });
  });

  it('starts a new empty thread on /ask (no history fetched)', async () => {
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    expect(getConversation).not.toHaveBeenCalled();
  });

  it('loads an existing thread from the route param', async () => {
    params = { conversationId: '5' };
    asMock(getConversation).mockResolvedValueOnce([{ id: 1, role: 'assistant', content: 'welcome back', audio_url: null }]);
    render(<VoiceAssistant />);
    expect(await screen.findByText('welcome back')).toBeInTheDocument();
    expect(getConversation).toHaveBeenCalledWith(5);
  });

  it('adopts the returned conversation id after the first send', async () => {
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());
    expect(postText).toHaveBeenCalledWith('how much', undefined);
    expect(navigate).toHaveBeenCalledWith('/ask/9', { replace: true });
  });

  it('opens the history drawer and lists threads', async () => {
    asMock(listConversations).mockResolvedValue([{ id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' }]);
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.click(screen.getByRole('button', { name: /history/i }));
    expect(await screen.findByText('Booking help')).toBeInTheDocument();
  });

  it('navigates to a thread when picked from the drawer', async () => {
    asMock(listConversations).mockResolvedValue([{ id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' }]);
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.click(screen.getByRole('button', { name: /history/i }));
    fireEvent.click(await screen.findByText('Booking help'));
    expect(navigate).toHaveBeenCalledWith('/ask/3');
  });

  it('deletes a thread from the drawer', async () => {
    asMock(listConversations).mockResolvedValue([{ id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' }]);
    vi.spyOn(window, 'confirm').mockReturnValue(true);
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.click(screen.getByRole('button', { name: /history/i }));
    await screen.findByText('Booking help');
    fireEvent.click(screen.getByRole('button', { name: /delete thread/i }));
    await waitFor(() => expect(deleteConversation).toHaveBeenCalledWith(3));
  });

  it('renames a thread from the drawer', async () => {
    asMock(listConversations).mockResolvedValue([{ id: 3, title: 'Booking help', updated_at: '2026-07-07T10:00:00+00:00' }]);
    vi.spyOn(window, 'prompt').mockReturnValue('New name');
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.click(screen.getByRole('button', { name: /history/i }));
    await screen.findByText('Booking help');
    fireEvent.click(screen.getByRole('button', { name: /rename thread/i }));
    await waitFor(() => expect(renameConversation).toHaveBeenCalledWith(3, 'New name'));
  });
});
```

- [ ] **Step 3: Run test to verify it fails**

Run (in `admin/`): `npx vitest run src/pages/VoiceAssistant.test.tsx`
Expected: FAIL — component still imports `getHistory`/`clearHistory` and has no drawer/params logic.

- [ ] **Step 4: Rewrite `VoiceAssistant.tsx`**

Replace the entire contents of `admin/src/pages/VoiceAssistant.tsx`. Keep the existing `THINKING_WORDS`, `ThinkingBubble`, `fmtTime`, and `AudioBubble` helpers unchanged (copy them verbatim from the current file); only the exported `VoiceAssistant` component and the imports change. The full file:

```tsx
import { useEffect, useRef, useState } from 'react';
import { useNavigate, useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import {
  getConversation, listConversations, renameConversation, deleteConversation,
  postText, postVoice, type Conversation,
} from '@/lib/assistant';
import { useRecorder } from '@/hooks/useRecorder';

type Msg = { role: 'user' | 'assistant'; content: string; audioUrl?: string | null };

// Rotating status words shown while the assistant is working, so the wait
// feels alive instead of a dead row of dots. Business-flavoured on purpose.
const THINKING_WORDS = [
  'Thinking',
  'Crunching the numbers',
  'Checking your books',
  'Looking into it',
  'Consulting your data',
  'Working it out',
  'Almost there',
];

/** The "assistant is thinking" bubble: a phrase that rotates every ~1.5s plus
 *  a trio of bouncing dots. Replaces the old static ellipsis. */
function ThinkingBubble() {
  const [i, setI] = useState(0);
  useEffect(() => {
    const id = setInterval(() => setI((n) => (n + 1) % THINKING_WORDS.length), 1500);
    return () => clearInterval(id);
  }, []);
  return (
    <div className="va-bubble va-ai va-thinking">
      {/* key re-mounts the span each change so the fade-in animation replays */}
      <span key={i} className="va-thinking-word">{THINKING_WORDS[i]}</span>
      <span className="va-dots" aria-hidden="true"><i /><i /><i /></span>
    </div>
  );
}

function fmtTime(s: number): string {
  if (!isFinite(s) || s < 0) return '0:00';
  const m = Math.floor(s / 60);
  const sec = Math.floor(s % 60);
  return `${m}:${sec.toString().padStart(2, '0')}`;
}

/**
 * A WhatsApp-style voice-note player for one message: play/pause, a progress
 * track, and elapsed time. Auto-plays once on mount when autoPlay is set; the
 * button replays it any number of times afterwards.
 */
function AudioBubble({ src, autoPlay = false }: { src: string; autoPlay?: boolean }) {
  const ref = useRef<HTMLAudioElement>(null);
  const [playing, setPlaying] = useState(false);
  const [progress, setProgress] = useState(0);
  const [elapsed, setElapsed] = useState(0);
  const [duration, setDuration] = useState(0);

  useEffect(() => {
    if (autoPlay) ref.current?.play().catch(() => undefined);
  }, [autoPlay]);

  const toggle = () => {
    const a = ref.current;
    if (!a) return;
    if (a.paused) a.play().catch(() => undefined);
    else a.pause();
  };

  return (
    <div className="va-audio">
      <button className="va-audio-btn" onClick={toggle} aria-label={playing ? 'Pause' : 'Play'}>
        {playing ? (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1" /><rect x="14" y="5" width="4" height="14" rx="1" /></svg>
        ) : (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
        )}
      </button>
      <div className="va-audio-track"><div className="va-audio-fill" style={{ width: `${progress * 100}%` }} /></div>
      <span className="va-audio-time">{fmtTime(elapsed || duration)}</span>
      <audio
        ref={ref}
        src={src}
        preload="metadata"
        onPlay={() => setPlaying(true)}
        onPause={() => setPlaying(false)}
        onEnded={() => { setPlaying(false); setProgress(0); setElapsed(0); }}
        onLoadedMetadata={(e) => setDuration(e.currentTarget.duration)}
        onTimeUpdate={(e) => {
          const a = e.currentTarget;
          setElapsed(a.currentTime);
          setProgress(a.duration && isFinite(a.duration) ? a.currentTime / a.duration : 0);
        }}
      />
    </div>
  );
}

export default function VoiceAssistant() {
  const navigate = useNavigate();
  const { conversationId: routeId } = useParams<{ conversationId?: string }>();
  const cid = routeId ? Number(routeId) : null;

  const [conversationId, setConversationId] = useState<number | null>(cid);
  const [messages, setMessages] = useState<Msg[]>([]);
  const [loadingHistory, setLoadingHistory] = useState(cid != null);
  const [busy, setBusy] = useState(false);
  const [draft, setDraft] = useState('');
  const [error, setError] = useState('');
  const [drawerOpen, setDrawerOpen] = useState(false);
  const [threads, setThreads] = useState<Conversation[]>([]);
  const { recording, start, stop, supported } = useRecorder();
  const threadRef = useRef<HTMLDivElement>(null);
  // Messages loaded from the server should not auto-play their audio; only
  // notes added during this session do. Reset whenever we (re)load a thread.
  const restoredCount = useRef(0);

  // Load the thread named in the route, or start fresh when there is none.
  useEffect(() => {
    let alive = true;
    setConversationId(cid);
    if (cid == null) {
      setMessages([]);
      restoredCount.current = 0;
      setLoadingHistory(false);
      return;
    }
    setLoadingHistory(true);
    getConversation(cid)
      .then((history) => {
        if (!alive) return;
        const msgs: Msg[] = history.map((m) => ({ role: m.role, content: m.content, audioUrl: m.audio_url }));
        restoredCount.current = msgs.length;
        setMessages(msgs);
      })
      .catch(() => { if (alive) setError('Could not load this conversation.'); })
      .finally(() => { if (alive) setLoadingHistory(false); });
    return () => { alive = false; };
  }, [cid]);

  // Keep the latest message in view as the conversation grows.
  useEffect(() => {
    threadRef.current?.scrollTo?.({ top: threadRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, busy]);

  async function openDrawer() {
    setDrawerOpen(true);
    try { setThreads(await listConversations()); } catch { setError('Could not load your chats.'); }
  }

  async function removeThread(id: number) {
    if (!window.confirm('Delete this chat?')) return;
    try { await deleteConversation(id); } catch { setError('Could not delete the chat.'); return; }
    setThreads((t) => t.filter((c) => c.id !== id));
    if (id === conversationId) navigate('/ask'); // deleting the open thread → new chat
  }

  async function renameThread(id: number, current: string) {
    const next = window.prompt('Rename chat', current);
    if (next == null || !next.trim()) return;
    try { await renameConversation(id, next.trim()); } catch { setError('Could not rename the chat.'); return; }
    setThreads((t) => t.map((c) => (c.id === id ? { ...c, title: next.trim() } : c)));
  }

  // After the first successful send in a new thread, adopt its id + route.
  function adopt(id?: number) {
    if (id != null && conversationId == null) {
      setConversationId(id);
      navigate(`/ask/${id}`, { replace: true });
    }
  }

  async function send(text: string) {
    if (!text.trim() || busy) return;
    setBusy(true); setError('');
    setMessages((m) => [...m, { role: 'user', content: text }]);
    setDraft('');
    try {
      const res = await postText(text, conversationId ?? undefined);
      setMessages((m) => [...m, { role: 'assistant', content: res.reply_text, audioUrl: res.reply_audio_url }]);
      adopt(res.conversation_id);
    } catch { setError('Could not reach the assistant.'); }
    finally { setBusy(false); }
  }

  async function toggleMic() {
    if (recording) {
      setBusy(true);
      const blob = await stop();
      if (!blob) { setBusy(false); return; }
      const voiceUrl = URL.createObjectURL(blob); // play back the owner's own note
      try {
        const res = await postVoice(blob, conversationId ?? undefined);
        setMessages((m) => [
          ...m,
          { role: 'user', content: res.transcript ?? '', audioUrl: voiceUrl },
          { role: 'assistant', content: res.reply_text, audioUrl: res.reply_audio_url },
        ]);
        adopt(res.conversation_id);
      } catch {
        setMessages((m) => [...m, { role: 'user', content: '', audioUrl: voiceUrl }]);
        setError('Could not reach the assistant.');
      }
      finally { setBusy(false); }
    } else {
      setError('');
      try { await start(); } catch { setError('Microphone permission needed.'); }
    }
  }

  return (
    <div className="m-screen va-screen">
      <div className="va-head">
        <button className="c-icon-btn" aria-label="Back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={18} /></button>
        <div className="va-head-text">
          <span className="va-title">Ask about your business</span>
          <span className="va-sub">Ask a question — or tell me to change something</span>
        </div>
        <button className="c-icon-btn" aria-label="New chat" onClick={() => navigate('/ask')}><Icons.Plus size={18} /></button>
        <button className="c-icon-btn" aria-label="History" onClick={() => void openDrawer()}><Icons.Clock size={18} /></button>
      </div>

      <div className="va-thread" ref={threadRef}>
        {loadingHistory && <div className="va-bubble va-ai va-typing">…</div>}
        {!loadingHistory && messages.length === 0 && !busy && (
          <div className="va-empty">
            <div className="va-empty-mic"><Icons.Mic size={26} /></div>
            <p className="va-hint">Tap the mic and ask or tell me, e.g.<br />"How much did I make this month?" or "Cancel Sarah's 3 o'clock"</p>
          </div>
        )}
        {messages.map((m, i) => (
          <div key={i} className={`va-bubble ${m.role === 'user' ? 'va-user' : 'va-ai'}`}>
            {m.audioUrl && (
              <AudioBubble src={m.audioUrl} autoPlay={m.role === 'assistant' && i >= restoredCount.current} />
            )}
            {m.content && <div className="va-text">{m.content}</div>}
          </div>
        ))}
        {busy && <ThinkingBubble />}
        {error && <div className="c-error-box">{error}</div>}
      </div>

      <div className="va-controls">
        <input className="va-input" placeholder="Type a question…" value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') void send(draft); }} disabled={busy} />
        <button className="c-btn" aria-label="Send" disabled={busy || !draft.trim()} onClick={() => void send(draft)}>
          <Icons.Send size={16} />
        </button>
        {supported && (
          <button className={`va-mic ${recording ? 'recording' : ''}`} aria-label="Microphone" disabled={busy && !recording} onClick={() => void toggleMic()}>
            <Icons.Mic size={20} />
          </button>
        )}
      </div>

      {drawerOpen && (
        <div className="va-drawer-backdrop" onClick={() => setDrawerOpen(false)}>
          <div className="va-drawer" onClick={(e) => e.stopPropagation()}>
            <div className="va-drawer-head">
              <span className="va-drawer-title">Your chats</span>
              <button className="c-icon-btn" aria-label="Close" onClick={() => setDrawerOpen(false)}><Icons.ChevronLeft size={18} /></button>
            </div>
            <button className="va-drawer-new" onClick={() => { setDrawerOpen(false); navigate('/ask'); }}>
              <Icons.Plus size={16} /> New chat
            </button>
            <div className="va-drawer-list">
              {threads.length === 0 && <p className="va-drawer-empty">No past chats yet.</p>}
              {threads.map((c) => (
                <div key={c.id} className={`va-drawer-row ${c.id === conversationId ? 'active' : ''}`}>
                  <button className="va-drawer-open" onClick={() => { setDrawerOpen(false); navigate(`/ask/${c.id}`); }}>
                    <span className="va-drawer-row-title">{c.title}</span>
                    <span className="va-drawer-row-time">{new Date(c.updated_at).toLocaleDateString()}</span>
                  </button>
                  <button className="c-icon-btn" aria-label="Rename thread" onClick={() => void renameThread(c.id, c.title)}><Icons.Send size={14} /></button>
                  <button className="c-icon-btn" aria-label="Delete thread" onClick={() => void removeThread(c.id)}><Icons.Trash size={14} /></button>
                </div>
              ))}
            </div>
          </div>
        </div>
      )}
    </div>
  );
}
```

- [ ] **Step 5: Run the test to verify it passes**

Run (in `admin/`): `npx vitest run src/pages/VoiceAssistant.test.tsx`
Expected: PASS (7 tests).

- [ ] **Step 6: Typecheck the whole admin app**

Run (in `admin/`): `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 7: Commit**

```bash
git add admin/src/App.tsx admin/src/pages/VoiceAssistant.tsx admin/src/pages/VoiceAssistant.test.tsx
git commit -m "feat(assistant): per-thread VoiceAssistant with history drawer + routing"
```

---

## Task 6: Drawer styles

**Files:**
- Modify: `admin/src/styles/desktop.css`

**Interfaces:**
- Consumes: the `va-drawer*` classnames emitted by Task 5.

- [ ] **Step 1: Add drawer styles**

Append to `admin/src/styles/desktop.css` (match the existing glass/mint language already used by `.va-*` rules — search the file for an existing `.va-head` rule and mirror its tokens/colors):

```css
/* --- Assistant history drawer ------------------------------------------- */
.va-drawer-backdrop {
  position: fixed;
  inset: 0;
  background: rgba(0, 0, 0, 0.35);
  display: flex;
  justify-content: flex-end;
  z-index: 50;
}
.va-drawer {
  width: min(88vw, 340px);
  height: 100%;
  background: var(--c-surface, #fff);
  box-shadow: -8px 0 24px rgba(0, 0, 0, 0.18);
  display: flex;
  flex-direction: column;
  animation: va-drawer-in 160ms ease-out;
}
@keyframes va-drawer-in { from { transform: translateX(100%); } to { transform: translateX(0); } }
.va-drawer-head {
  display: flex;
  align-items: center;
  justify-content: space-between;
  padding: 14px 16px;
  border-bottom: 1px solid var(--c-border, #eee);
}
.va-drawer-title { font-weight: 600; }
.va-drawer-new {
  display: flex;
  align-items: center;
  gap: 8px;
  margin: 12px 16px;
  padding: 10px 12px;
  border: 1px solid var(--c-border, #e5e7eb);
  border-radius: 10px;
  background: transparent;
  cursor: pointer;
  font-weight: 500;
}
.va-drawer-list { overflow-y: auto; flex: 1; padding: 0 8px 16px; }
.va-drawer-empty { color: var(--c-muted, #888); padding: 16px; text-align: center; }
.va-drawer-row {
  display: flex;
  align-items: center;
  gap: 4px;
  padding: 4px 8px;
  border-radius: 10px;
}
.va-drawer-row.active { background: var(--c-mint-tint, rgba(16, 185, 129, 0.10)); }
.va-drawer-open {
  flex: 1;
  min-width: 0;
  display: flex;
  flex-direction: column;
  align-items: flex-start;
  gap: 2px;
  padding: 8px;
  background: transparent;
  border: 0;
  cursor: pointer;
  text-align: left;
}
.va-drawer-row-title {
  font-weight: 500;
  white-space: nowrap;
  overflow: hidden;
  text-overflow: ellipsis;
  max-width: 100%;
}
.va-drawer-row-time { font-size: 12px; color: var(--c-muted, #999); }
```

- [ ] **Step 2: Verify the build compiles**

Run (in `admin/`): `npx vite build`
Expected: build succeeds (no CSS/TS errors).

- [ ] **Step 3: Commit**

```bash
git add admin/src/styles/desktop.css
git commit -m "style(assistant): history drawer styling"
```

---

## Task 7: Staging verification & deploy

**Files:** none (deploy + manual verification).

- [ ] **Step 1: Full backend suite on the droplet**

Run (on droplet, scratch DB — never prod): `php artisan test`
Expected: all green, including the three assistant test files.

- [ ] **Step 2: Full frontend suite**

Run (in `admin/`): `npx vitest run` then `npx tsc --noEmit`
Expected: all pass, no type errors.

- [ ] **Step 3: Deploy to staging**

Deploy the backend + migration to staging (64.227.153.90, isolated DB) and the admin frontend via `admin/deploy.ps1` (staging target). Run the migration on staging and confirm the backfill created one "Previous chat" per shop that had messages.

- [ ] **Step 4: Manual smoke test on staging**

Verify, logged in as a shop with existing assistant history:
1. Home mic → `/ask` opens an empty new thread.
2. Ask a question → reply appears; the URL becomes `/ask/<id>`.
3. Open the history drawer → the new thread AND "Previous chat" are listed.
4. Reopen "Previous chat" → old messages load; context stays isolated (a follow-up doesn't reference the other thread).
5. Rename and delete a thread; delete the open thread → falls back to a new chat.
6. Tap the mic, record, and confirm voice reply + audio playback still work.

- [ ] **Step 5: Promote to prod**

Once staging is verified great, promote code + migration + admin frontend to prod (deploy per `admin/deploy.ps1`, run the migration on prod). Confirm the prod backfill and do a quick smoke test of steps 1–4 above.

- [ ] **Step 6: Commit / tag as needed**

No code change; ensure all prior commits are pushed.

---

## Self-Review Notes

- **Spec coverage:** new-thread-per-mic-tap (Task 5 `/ask` empty), title from first message (Task 2 `titleFrom` + Task 3 payload), slide-in drawer (Task 5/6), per-thread isolated context (Task 2 `contextFor`, tested Task 3), `conversations` table Option A (Task 1), lazy creation (Task 3 `respond`), "Previous chat" backfill (Task 1 migration + Task 3 note), rename/delete (Tasks 2/3/5), multi-tenant 404 scoping (Task 3), audio path with shop+conversation (Task 2), tests across store/api/migration/frontend (all tasks), staging→prod rollout (Task 7). No gaps found.
- **Removed endpoints:** old `GET/DELETE /shop/assistant/history` are replaced; no remaining caller (frontend rewritten in Tasks 4-5). Confirmed `assistant.ts` no longer exports `getHistory`/`clearHistory`.
- **Type consistency:** `postText(text, conversationId?)` / `postVoice(blob, conversationId?)` and the `AssistantReply.conversation_id`/`title` fields match between client (Task 4) and component (Task 5); `Conversation` shape `{id,title,updated_at}` matches store `list()` (Task 2) and API (Task 3).
```
