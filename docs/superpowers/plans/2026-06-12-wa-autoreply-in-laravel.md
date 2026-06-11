# WhatsApp Auto-Reply in Laravel Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Port the `whatsapp-autoreply` Node service into this Laravel backend (queued-job auto-replies with Claude, voice, onboarding tool, web push) so the Node process can be retired by repointing Meta's webhook — with zero edits to any Node app.

**Architecture:** The existing `POST /api/wa/webhook` keeps storing inbound messages and ACKing instantly; it gains HMAC signature verification and dispatches a `ProcessWaReply` job per new inbound message. The job (database queue, `queue:work` worker) ports the Node pipeline: skip-reactions → bare-greeting canned reply → Whisper transcription → persona resolution → Claude reply (tool-enabled for sales leads) → TTS voice-out → Cloud API send → record outbound. All of Node's HTTP relay lookups become direct method calls.

**Tech Stack:** Laravel 12, PHPUnit 11, database queue, `Http` facade (no AI SDKs), `minishlink/web-push` (only new composer dep). Spec: `docs/superpowers/specs/2026-06-12-wa-autoreply-in-laravel-design.md`.

**Reference for ports:** the Node source lives at `D:\Francis\projects\2026\Eloquent\Solutions\whatsapp-autoreply\` — READ ONLY, never modify it.

**Conventions in this repo:** PHPUnit class-based tests in `tests/Feature` + `tests/Unit` with `RefreshDatabase`; models use `protected $guarded = []`; run tests with `php artisan test --filter=Name`.

---

### Task 1: Config plumbing (services.php + .env.example)

**Files:**
- Modify: `config/services.php`
- Modify: `.env.example` (append)

- [ ] **Step 1: Add config keys**

In `config/services.php`, replace the existing `'whatsapp' => [...]` block and add `anthropic`, `openai`, `webpush` blocks after it:

```php
    'whatsapp' => [
        'verify_token' => env('WHATSAPP_VERIFY_TOKEN'),
        'graph_version' => env('GRAPH_API_VERSION', 'v25.0'),
        'relay_secret' => env('WA_RELAY_SECRET'),
        // Shared system-user token for all numbers under our own WABA.
        // Per-account tokens (wa_accounts.token) override this when set.
        'default_token' => env('WHATSAPP_DEFAULT_TOKEN'),
        // Meta app secret — verifies X-Hub-Signature-256 on webhooks (no-op when unset).
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        // phone_number_id of our own Rezzy sales line (runs the sales persona + onboarding).
        'sales_phone_number_id' => env('WHATSAPP_SALES_PHONE_NUMBER_ID'),
    ],

    'anthropic' => [
        'key' => env('ANTHROPIC_API_KEY'),
        'model' => env('CLAUDE_MODEL', 'claude-haiku-4-5'),
    ],

    'openai' => [
        'key' => env('OPENAI_API_KEY'), // Whisper + TTS; absent → voice features off
        'tts_model' => env('TTS_MODEL', 'gpt-4o-mini-tts'),
        'tts_voice' => env('TTS_VOICE', 'nova'),
    ],

    'webpush' => [
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
        'subject' => env('VAPID_SUBJECT', 'mailto:admin@eloquentservice.com'),
    ],
```

- [ ] **Step 2: Append the new vars to `.env.example`** (no secrets, empty values):

```
WHATSAPP_APP_SECRET=
WHATSAPP_SALES_PHONE_NUMBER_ID=
ANTHROPIC_API_KEY=
CLAUDE_MODEL=claude-haiku-4-5
OPENAI_API_KEY=
VAPID_PUBLIC_KEY=
VAPID_PRIVATE_KEY=
VAPID_SUBJECT=mailto:admin@eloquentservice.com
```

- [ ] **Step 3: Sanity check + commit**

Run: `php artisan config:clear && php artisan test --filter=WaShopContextTest`
Expected: existing tests still PASS.

```bash
git add config/services.php .env.example
git commit -m "feat(wa): config keys for in-app auto-reply (anthropic, openai, webpush, app secret)"
```

---

### Task 2: Prompts support class

**Files:**
- Create: `app/Support/Wa/Prompts.php`
- Test: `tests/Unit/WaPromptsTest.php`

The Rezzy sales prompt is ported **verbatim** from `whatsapp-autoreply/server.js` (`REZZY_SYSTEM_PROMPT`, lines 39–91); the provider prompt from `lib/personas.js` (`buildProviderPrompt`). Use a PHP heredoc so the text needs no escaping.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Support\Wa\Prompts;
use PHPUnit\Framework\TestCase;

class WaPromptsTest extends TestCase
{
    public function test_sales_prompt_contains_key_rules(): void
    {
        $this->assertStringContainsString('KEEP IT SHORT', Prompts::REZZY_SALES);
        $this->assertStringContainsString('create_business_account', Prompts::REZZY_SALES);
        $this->assertStringContainsString('50 AED', Prompts::REZZY_SALES);
        $this->assertStringContainsString('https://pay.ziina.com/eloquentservice/dRhj0YS4V?source=app', Prompts::REZZY_SALES);
    }

    public function test_provider_prompt_includes_shop_and_category(): void
    {
        $prompt = Prompts::provider('Glow Salon', 'Salon');

        $this->assertStringContainsString('Glow Salon, a salon business', $prompt);
        $this->assertStringContainsString('Never mention Rezzy', $prompt);
    }

    public function test_provider_prompt_without_category(): void
    {
        $prompt = Prompts::provider('Glow Salon', null);

        $this->assertStringContainsString('assistant for Glow Salon.', $prompt);
        $this->assertStringNotContainsString('business business', $prompt);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WaPromptsTest`
Expected: FAIL — class `App\Support\Wa\Prompts` not found.

- [ ] **Step 3: Implement**

Create `app/Support/Wa/Prompts.php`. **Copy the full prompt text verbatim from `whatsapp-autoreply/server.js` lines 39–91** into the `REZZY_SALES` heredoc (the skeleton below shows start/end; the executor MUST paste the complete ~50-line prompt, not this abbreviation):

```php
<?php

namespace App\Support\Wa;

/**
 * System prompts for the WhatsApp auto-reply bot. The sales prompt is the
 * canonical Rezzy lead assistant (ported verbatim from the retired Node
 * service); the provider prompt speaks as a tenant shop's assistant.
 */
class Prompts
{
    public const REZZY_SALES = <<<'PROMPT'
You are Rezzy's friendly assistant on WhatsApp. You chat with small business owners — salons, dentists, clinics, tutors, home services, physical therapists — who saw an ad and want to know about Rezzy.

[... FULL VERBATIM TEXT of REZZY_SYSTEM_PROMPT from server.js lines 39-91 ...]

Stay focused on Rezzy and helping them. If they go off-topic, gently and kindly bring it back.
PROMPT;

    /**
     * Assistant prompt for a service provider's customers, in the voice of
     * the shop's locked category (salon, barber, plumbing, ...).
     * Ported from whatsapp-autoreply/lib/personas.js buildProviderPrompt().
     */
    public static function provider(string $shopName, ?string $category): string
    {
        $business = $category ? "{$shopName}, a " . mb_strtolower($category) . ' business' : $shopName;

        return "You are the warm, professional WhatsApp assistant for {$business}. Customers message this number to ask about services, prices, timings, and to book appointments.\n\n"
            . "#1 RULE — KEEP IT SHORT. This is WhatsApp: every reply must be 1–3 short sentences, under 40 words. One thing at a time.\n\n"
            . "- Greet customers warmly and help them with what they need.\n"
            . "- To book: ask which service they'd like and their preferred day and time, then confirm it will be locked in and they'll get a confirmation shortly.\n"
            . "- If you don't know a detail (exact price, availability), say the team will confirm it right away — never guess.\n"
            . "- Reply in the customer's language.\n"
            . "- You are simply {$shopName}'s assistant. Never mention Rezzy, software, AI, or sales — and never pitch anything.";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WaPromptsTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Wa/Prompts.php tests/Unit/WaPromptsTest.php
git commit -m "feat(wa): port sales + provider system prompts from Node service"
```

---

### Task 3: Greetings detector

**Files:**
- Create: `app/Support/Wa/Greetings.php`
- Test: `tests/Unit/WaGreetingsTest.php`

Ports `whatsapp-autoreply/lib/greetings.js` exactly: same greeting set, emoji/punctuation strip, repeated-letter collapse.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Unit;

use App\Support\Wa\Greetings;
use PHPUnit\Framework\TestCase;

class WaGreetingsTest extends TestCase
{
    public function test_detects_bare_greetings(): void
    {
        foreach (['hi', 'Hello', 'HEY', 'good morning', 'salam', 'Asalamualaikum', 'gm', 'h'] as $text) {
            $this->assertTrue(Greetings::isBare($text), "expected '{$text}' to be a bare greeting");
        }
    }

    public function test_collapses_repeated_letters(): void
    {
        $this->assertTrue(Greetings::isBare('hiii'));
        $this->assertTrue(Greetings::isBare('hellooooo'));
        $this->assertTrue(Greetings::isBare('heyyy'));
    }

    public function test_strips_emoji_and_punctuation(): void
    {
        $this->assertTrue(Greetings::isBare('Hi! 😊'));
        $this->assertTrue(Greetings::isBare('hello...'));
    }

    public function test_rejects_real_messages(): void
    {
        foreach (['hi, how much is it?', 'hello I need a booking', 'what is rezzy', '', null] as $text) {
            $this->assertFalse(Greetings::isBare($text), 'expected "' . ($text ?? 'null') . '" NOT to be bare');
        }
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WaGreetingsTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support\Wa;

/**
 * Detect bare greetings ("hi", "hello", "good morning", "salam", ...) —
 * messages with no actual question. These get an instant canned welcome
 * instead of a Claude call. Ported from whatsapp-autoreply/lib/greetings.js.
 */
class Greetings
{
    private const GREETINGS = [
        // collapsed forms (repeated letters are squashed before matching)
        'h', 'hi', 'hy', 'hey', 'helo', 'hello', 'hiya', 'hola', 'hallo', 'yo',
        'hi there', 'hello there', 'hey there', 'greetings', 'start', 'namaste',
        'salam', 'salam alaikum', 'asalam', 'asalam alaikum', 'asalamualaikum',
        'good morning', 'good afternoon', 'good evening', 'good day', 'gm', 'ge',
    ];

    public static function isBare(?string $text): bool
    {
        if (!is_string($text) || $text === '') {
            return false;
        }

        // Keep only letters/numbers/spaces (drops emojis, punctuation), lowercase.
        $s = mb_strtolower($text);
        $s = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $s);
        $s = trim(preg_replace('/\s+/', ' ', $s));
        if ($s === '') {
            return false;
        }

        // Squash runs of the same letter: "hiii"→"hi", "helloooo"→"helo".
        $collapsed = preg_replace('/(\p{L})\1+/u', '$1', $s);

        return in_array($s, self::GREETINGS, true) || in_array($collapsed, self::GREETINGS, true);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WaGreetingsTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Support/Wa/Greetings.php tests/Unit/WaGreetingsTest.php
git commit -m "feat(wa): bare-greeting detector (canned welcome, no Claude call)"
```

---

### Task 4: ConversationHistory (DB-backed Claude turns)

**Files:**
- Create: `app/Support/Wa/ConversationHistory.php`
- Test: `tests/Feature/WaConversationHistoryTest.php` (needs DB → Feature)

Replaces Node's in-memory history. Maps the contact's last `wa_messages` to Claude turns: `in`→user, `out`→assistant; strips `🎤 `/`🔊 ` prefixes; skips `[type message]` placeholders; merges consecutive same-role turns (Claude requires alternation); drops leading assistant turns (Claude requires the first turn to be user).

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Support\Wa\ConversationHistory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaConversationHistoryTest extends TestCase
{
    use RefreshDatabase;

    private function makeContact(): WaContact
    {
        $shop = Shop::factory()->create();
        $account = WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000001',
            'phone_number_id' => 'pn_hist',
            'waba_id' => 'waba_hist',
        ]);

        return WaContact::create(['wa_account_id' => $account->id, 'wa_number' => '971555000111']);
    }

    public function test_maps_directions_to_roles_in_order(): void
    {
        $contact = $this->makeContact();
        $contact->recordMessage('in', 'hello there, prices?');
        $contact->recordMessage('out', 'Haircut is 50 AED 😊');
        $contact->recordMessage('in', 'book me tomorrow');

        $this->assertSame([
            ['role' => 'user', 'content' => 'hello there, prices?'],
            ['role' => 'assistant', 'content' => 'Haircut is 50 AED 😊'],
            ['role' => 'user', 'content' => 'book me tomorrow'],
        ], ConversationHistory::for($contact));
    }

    public function test_strips_voice_prefixes_and_skips_placeholders(): void
    {
        $contact = $this->makeContact();
        $contact->recordMessage('in', '🎤 how much is a haircut', 'audio');
        $contact->recordMessage('out', '🔊 It is 50 AED', 'audio');
        $contact->recordMessage('in', '[image message]', 'image');
        $contact->recordMessage('in', 'ok thanks');

        $this->assertSame([
            ['role' => 'user', 'content' => 'how much is a haircut'],
            ['role' => 'assistant', 'content' => 'It is 50 AED'],
            ['role' => 'user', 'content' => 'ok thanks'],
        ], ConversationHistory::for($contact));
    }

    public function test_merges_consecutive_same_role_turns(): void
    {
        $contact = $this->makeContact();
        $contact->recordMessage('in', 'hi');
        $contact->recordMessage('in', 'anyone there?');

        $this->assertSame([
            ['role' => 'user', 'content' => "hi\nanyone there?"],
        ], ConversationHistory::for($contact));
    }

    public function test_drops_leading_assistant_turns_and_limits(): void
    {
        $contact = $this->makeContact();
        $contact->recordMessage('out', 'Welcome!'); // would lead with assistant
        for ($i = 1; $i <= 12; $i++) {
            $contact->recordMessage('in', "question {$i}");
            $contact->recordMessage('out', "answer {$i}");
        }

        $history = ConversationHistory::for($contact);

        $this->assertLessThanOrEqual(10, count($history));
        $this->assertSame('user', $history[0]['role']);
        $this->assertSame('answer 12', end($history)['content']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WaConversationHistoryTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Support\Wa;

use App\Models\WaContact;

/**
 * Build the Claude conversation history for a contact from stored
 * wa_messages. Replaces the Node service's in-memory store — survives
 * restarts and is shared with the bizrezzy chat threads.
 */
class ConversationHistory
{
    public const LIMIT = 10; // turns kept per thread (Node parity)

    /** @return array<int, array{role: string, content: string}> */
    public static function for(WaContact $contact, int $limit = self::LIMIT): array
    {
        // Fetch extra rows: placeholders get skipped and turns get merged.
        $messages = $contact->messages()
            ->orderByDesc('id')
            ->limit($limit * 3)
            ->get(['id', 'direction', 'body'])
            ->reverse()
            ->values();

        $turns = [];
        foreach ($messages as $message) {
            $body = trim(preg_replace('/^(🎤|🔊)\s*/u', '', (string) $message->body));
            if ($body === '' || preg_match('/^\[\w+( \w+)? message\]$/i', $body)) {
                continue; // media placeholder like "[image message]"
            }

            $role = $message->direction === 'in' ? 'user' : 'assistant';
            if ($turns && $turns[count($turns) - 1]['role'] === $role) {
                // Claude requires alternating roles — merge consecutive turns.
                $turns[count($turns) - 1]['content'] .= "\n" . $body;
            } else {
                $turns[] = ['role' => $role, 'content' => $body];
            }
        }

        $turns = array_slice($turns, -$limit);

        // Claude requires the first turn to be from the user.
        while ($turns && $turns[0]['role'] !== 'user') {
            array_shift($turns);
        }

        return $turns;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WaConversationHistoryTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Support/Wa/ConversationHistory.php tests/Feature/WaConversationHistoryTest.php
git commit -m "feat(wa): DB-backed conversation history for Claude turns"
```

---

### Task 5: ClaudeClient service

**Files:**
- Create: `app/Services/Wa/ClaudeClient.php`
- Test: `tests/Feature/WaClaudeClientTest.php`

Ports `lib/claude.js`. Plain `Http` POST to the Anthropic Messages API — no SDK. System prompt sent as a block with `cache_control: ephemeral`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\Wa\ClaudeClient;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaClaudeClientTest extends TestCase
{
    public function test_reply_returns_joined_text_and_caches_system_prompt(): void
    {
        config(['services.anthropic.key' => 'sk-test', 'services.anthropic.model' => 'claude-haiku-4-5']);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Hello '],
                    ['type' => 'text', 'text' => 'there!'],
                ],
            ]),
        ]);

        $reply = (new ClaudeClient())->reply('You are a bot.', [['role' => 'user', 'content' => 'hi']]);

        $this->assertSame('Hello there!', $reply);
        Http::assertSent(function ($request) {
            return $request->hasHeader('x-api-key', 'sk-test')
                && $request->hasHeader('anthropic-version', '2023-06-01')
                && $request['model'] === 'claude-haiku-4-5'
                && $request['max_tokens'] === 1024
                && $request['system'][0]['cache_control'] === ['type' => 'ephemeral']
                && !array_key_exists('tools', $request->data());
        });
    }

    public function test_agent_reply_extracts_tool_use(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'text', 'text' => 'Creating your account…'],
                    ['type' => 'tool_use', 'name' => 'create_business_account',
                     'id' => 'tu_1', 'input' => ['business_name' => 'Glow Salon', 'category' => 'Salon']],
                ],
            ]),
        ]);

        $tools = [['name' => 'create_business_account', 'input_schema' => ['type' => 'object']]];
        $result = (new ClaudeClient())->agentReply('You are a bot.', [['role' => 'user', 'content' => 'yes']], $tools);

        $this->assertSame('Creating your account…', $result['text']);
        $this->assertSame('create_business_account', $result['toolUse']['name']);
        $this->assertSame('Glow Salon', $result['toolUse']['input']['business_name']);
        Http::assertSent(fn ($request) => $request['tools'] === $tools);
    }

    public function test_throws_on_api_error(): void
    {
        config(['services.anthropic.key' => 'sk-test']);
        Http::fake(['api.anthropic.com/*' => Http::response(['error' => 'overloaded'], 529)]);

        $this->expectException(\RuntimeException::class);

        (new ClaudeClient())->reply('sys', [['role' => 'user', 'content' => 'hi']]);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WaClaudeClientTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Wa;

use Illuminate\Support\Facades\Http;

/**
 * Thin Anthropic Messages API client (no SDK). The system prompt is cached
 * with cache_control: ephemeral to cut cost/latency across turns.
 * Ported from whatsapp-autoreply/lib/claude.js.
 */
class ClaudeClient
{
    private const URL = 'https://api.anthropic.com/v1/messages';

    /** @param array<int, array{role: string, content: string}> $history */
    public function reply(string $system, array $history): string
    {
        return $this->text($this->request($system, $history));
    }

    /**
     * Reply with tools enabled (e.g. in-chat account creation).
     *
     * @return array{text: string, toolUse: array{name: string, input: array}|null}
     */
    public function agentReply(string $system, array $history, array $tools): array
    {
        $res = $this->request($system, $history, $tools);

        $toolBlock = collect($res['content'] ?? [])->firstWhere('type', 'tool_use');

        return [
            'text' => $this->text($res),
            'toolUse' => $toolBlock
                ? ['name' => $toolBlock['name'], 'input' => (array) ($toolBlock['input'] ?? [])]
                : null,
        ];
    }

    private function request(string $system, array $history, array $tools = []): array
    {
        $payload = [
            'model' => config('services.anthropic.model', 'claude-haiku-4-5'),
            'max_tokens' => 1024,
            'system' => [[
                'type' => 'text',
                'text' => $system,
                'cache_control' => ['type' => 'ephemeral'],
            ]],
            'messages' => $history,
        ];
        if ($tools) {
            $payload['tools'] = $tools;
        }

        $response = Http::withHeaders([
                'x-api-key' => (string) config('services.anthropic.key'),
                'anthropic-version' => '2023-06-01',
            ])
            ->timeout(60)
            ->post(self::URL, $payload);

        if (!$response->successful()) {
            throw new \RuntimeException(
                "Claude request failed ({$response->status()}): " . mb_substr($response->body(), 0, 200)
            );
        }

        return $response->json() ?? [];
    }

    private function text(array $res): string
    {
        return trim(collect($res['content'] ?? [])
            ->where('type', 'text')
            ->pluck('text')
            ->implode(''));
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WaClaudeClientTest`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Services/Wa/ClaudeClient.php tests/Feature/WaClaudeClientTest.php
git commit -m "feat(wa): Claude client with ephemeral prompt caching and tool use"
```

---

### Task 6: Transcriber (Whisper) + Speech (TTS) services

**Files:**
- Create: `app/Services/Wa/Transcriber.php`
- Create: `app/Services/Wa/Speech.php`
- Test: `tests/Feature/WaVoiceServicesTest.php`

Ports `lib/transcribe.js` and `lib/tts.js`. Both are OpenAI HTTP calls; both report `available()` from `OPENAI_API_KEY`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Services\Wa\Speech;
use App\Services\Wa\Transcriber;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaVoiceServicesTest extends TestCase
{
    public function test_unavailable_without_api_key(): void
    {
        config(['services.openai.key' => null]);

        $this->assertFalse((new Transcriber())->available());
        $this->assertFalse((new Speech())->available());
    }

    public function test_transcribe_posts_multipart_and_returns_text(): void
    {
        config(['services.openai.key' => 'sk-oai']);
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => ' how much is a haircut '])]);

        $text = (new Transcriber())->transcribe('FAKEAUDIO', 'audio/ogg; codecs=opus');

        $this->assertSame('how much is a haircut', $text);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer sk-oai'));
    }

    public function test_transcribe_returns_null_for_empty_text(): void
    {
        config(['services.openai.key' => 'sk-oai']);
        Http::fake(['api.openai.com/v1/audio/transcriptions' => Http::response(['text' => '  '])]);

        $this->assertNull((new Transcriber())->transcribe('FAKEAUDIO', 'audio/ogg'));
    }

    public function test_synthesize_returns_audio_bytes(): void
    {
        config(['services.openai.key' => 'sk-oai', 'services.openai.tts_model' => 'gpt-4o-mini-tts', 'services.openai.tts_voice' => 'nova']);
        Http::fake(['api.openai.com/v1/audio/speech' => Http::response('OGGBYTES')]);

        $audio = (new Speech())->synthesize('Your booking is confirmed');

        $this->assertSame('OGGBYTES', $audio);
        Http::assertSent(fn ($request) => $request['response_format'] === 'opus' && $request['voice'] === 'nova');
    }

    public function test_voice_services_throw_on_api_error(): void
    {
        config(['services.openai.key' => 'sk-oai']);
        Http::fake(['api.openai.com/*' => Http::response('boom', 500)]);

        $this->expectException(\RuntimeException::class);
        (new Speech())->synthesize('hello');
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WaVoiceServicesTest`
Expected: FAIL — classes not found.

- [ ] **Step 3: Implement `Transcriber`**

```php
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
```

- [ ] **Step 4: Implement `Speech`**

```php
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
```

- [ ] **Step 5: Run test to verify it passes**

Run: `php artisan test --filter=WaVoiceServicesTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Wa/Transcriber.php app/Services/Wa/Speech.php tests/Feature/WaVoiceServicesTest.php
git commit -m "feat(wa): Whisper transcription + OpenAI TTS services"
```

---

### Task 7: WhatsAppCloud — uploadMedia + sendVoice

**Files:**
- Modify: `app/Services/WhatsAppCloud.php`
- Test: `tests/Feature/WaCloudMediaTest.php`

Ports `uploadMedia`/`sendVoice` from `lib/whatsapp.js`. Refactor: extract a shared `postMessage()` used by `sendText` and `sendVoice` (DRY). Token resolution stays `$account->token ?: default_token`.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WaAccount;
use App\Services\WhatsAppCloud;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class WaCloudMediaTest extends TestCase
{
    use RefreshDatabase;

    private function makeAccount(): WaAccount
    {
        $shop = Shop::factory()->create();

        return WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000002',
            'phone_number_id' => 'pn_media',
            'waba_id' => 'waba_media',
            'token' => 'shop-token',
        ]);
    }

    public function test_upload_media_returns_media_id(): void
    {
        config(['services.whatsapp.graph_version' => 'v25.0']);
        Http::fake(['graph.facebook.com/v25.0/pn_media/media' => Http::response(['id' => 'media_123'])]);

        $id = (new WhatsAppCloud())->uploadMedia($this->makeAccount(), 'OGGBYTES', 'audio/ogg');

        $this->assertSame('media_123', $id);
        Http::assertSent(fn ($request) => $request->hasHeader('Authorization', 'Bearer shop-token'));
    }

    public function test_upload_media_throws_without_id(): void
    {
        config(['services.whatsapp.graph_version' => 'v25.0']);
        Http::fake(['graph.facebook.com/*' => Http::response(['error' => ['message' => 'bad']], 400)]);

        $this->expectException(\RuntimeException::class);
        (new WhatsAppCloud())->uploadMedia($this->makeAccount(), 'OGGBYTES', 'audio/ogg');
    }

    public function test_send_voice_posts_audio_message(): void
    {
        config(['services.whatsapp.graph_version' => 'v25.0']);
        Http::fake(['graph.facebook.com/v25.0/pn_media/messages' => Http::response(['messages' => [['id' => 'wamid.OUT1']]])]);

        $result = (new WhatsAppCloud())->sendVoice($this->makeAccount(), '+971 55 500 0111', 'media_123');

        $this->assertSame('wamid.OUT1', $result['messages'][0]['id']);
        Http::assertSent(function ($request) {
            return $request['type'] === 'audio'
                && $request['audio'] === ['id' => 'media_123']
                && $request['to'] === '971555000111'; // digits only
        });
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WaCloudMediaTest`
Expected: FAIL — `uploadMedia` / `sendVoice` undefined.

- [ ] **Step 3: Implement**

In `app/Services/WhatsAppCloud.php`, replace `sendText` and add the new methods (keep `downloadMedia` unchanged):

```php
    /**
     * Send a plain text message via the WhatsApp Cloud API.
     *
     * @throws \RuntimeException when the Graph API rejects the request
     */
    public function sendText(WaAccount $account, string $to, string $text): array
    {
        return $this->postMessage($account, [
            'to' => preg_replace('/\D+/', '', $to),
            'type' => 'text',
            'text' => ['body' => $text],
        ]);
    }

    /**
     * Send a previously-uploaded audio object. OGG/Opus uploads render as a
     * proper voice note in WhatsApp.
     */
    public function sendVoice(WaAccount $account, string $to, string $mediaId): array
    {
        return $this->postMessage($account, [
            'to' => preg_replace('/\D+/', '', $to),
            'type' => 'audio',
            'audio' => ['id' => $mediaId],
        ]);
    }

    /**
     * Upload media bytes to the Cloud API; returns the media id.
     *
     * @throws \RuntimeException when the upload fails
     */
    public function uploadMedia(WaAccount $account, string $bytes, string $mime, string $filename = 'voice.ogg'): string
    {
        $version = config('services.whatsapp.graph_version', 'v25.0');
        $token = $this->token($account);

        $response = Http::withToken($token)
            ->timeout(30)
            ->attach('file', $bytes, $filename, ['Content-Type' => $mime])
            ->post("https://graph.facebook.com/{$version}/{$account->phone_number_id}/media", [
                'messaging_product' => 'whatsapp',
            ]);

        $id = $response->json('id');
        if (!$response->successful() || !$id) {
            $error = $response->json('error.message') ?: "HTTP {$response->status()}";
            throw new \RuntimeException("WhatsApp media upload failed: {$error}");
        }

        return $id;
    }

    /** Shared POST to the Cloud API messages endpoint. */
    private function postMessage(WaAccount $account, array $payload): array
    {
        $version = config('services.whatsapp.graph_version', 'v25.0');
        $token = $this->token($account);

        $response = Http::withToken($token)
            ->acceptJson()
            ->post(
                "https://graph.facebook.com/{$version}/{$account->phone_number_id}/messages",
                ['messaging_product' => 'whatsapp', ...$payload]
            );

        if (!$response->successful()) {
            $error = $response->json('error.message') ?: "HTTP {$response->status()}";
            throw new \RuntimeException("WhatsApp send failed: {$error}");
        }

        return $response->json() ?? [];
    }

    /**
     * Per-account token (tenant brought their own Meta business) falls back
     * to our shared system-user token for numbers under our WABA.
     */
    private function token(WaAccount $account): string
    {
        $token = $account->token ?: config('services.whatsapp.default_token');
        if (!$token) {
            throw new \RuntimeException('WhatsApp send failed: no access token configured');
        }

        return $token;
    }
```

Also update `downloadMedia` to reuse `$this->token()`? **No** — `downloadMedia` returns null (not throws) when no token; leave its token lookup as-is.

- [ ] **Step 4: Run new + existing tests**

Run: `php artisan test --filter="WaCloudMediaTest|WaChatTest"`
Expected: PASS — including existing `WaChatTest` which exercises `sendText`.

- [ ] **Step 5: Commit**

```bash
git add app/Services/WhatsAppCloud.php tests/Feature/WaCloudMediaTest.php
git commit -m "feat(wa): voice-note send + media upload on WhatsAppCloud"
```

---

### Task 8: Web push — migration, model, service, endpoints

**Files:**
- Create: `database/migrations/2026_06_12_000001_create_wa_push_subscriptions_table.php`
- Create: `app/Models/WaPushSubscription.php`
- Create: `app/Services/Wa/WebPush.php`
- Create: `app/Http/Controllers/WaPushController.php`
- Modify: `routes/api.php` (inside the existing `auth:sanctum` group that holds the master routes)
- Test: `tests/Feature/WaPushTest.php`

- [ ] **Step 1: Install the package**

Run: `composer require minishlink/web-push`
Expected: installs (pulls `web-token/*` deps). If it complains about missing `ext-gmp`, it still works via brick/math fallback — proceed.

- [ ] **Step 2: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\WaPushSubscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaPushTest extends TestCase
{
    use RefreshDatabase;

    private function actingShop(): Shop
    {
        $shop = Shop::factory()->create();
        $this->actingAs($shop, 'sanctum');

        return $shop;
    }

    public function test_vapid_key_returns_503_when_unconfigured(): void
    {
        config(['services.webpush.public_key' => null, 'services.webpush.private_key' => null]);
        $this->actingShop();

        $this->getJson('/api/wa/push/vapid-key')->assertStatus(503);
    }

    public function test_vapid_key_returns_public_key(): void
    {
        config(['services.webpush.public_key' => 'pubkey123', 'services.webpush.private_key' => 'privkey123']);
        $this->actingShop();

        $this->getJson('/api/wa/push/vapid-key')->assertOk()->assertJson(['key' => 'pubkey123']);
    }

    public function test_subscribe_stores_subscription_once(): void
    {
        $this->actingShop();
        $sub = [
            'endpoint' => 'https://fcm.googleapis.com/fcm/send/abc123',
            'keys' => ['p256dh' => 'p256value', 'auth' => 'authvalue'],
        ];

        $this->postJson('/api/wa/push/subscribe', $sub)->assertOk();
        $this->postJson('/api/wa/push/subscribe', $sub)->assertOk(); // idempotent

        $this->assertSame(1, WaPushSubscription::count());
        $this->assertSame('p256value', WaPushSubscription::first()->p256dh);
    }

    public function test_unsubscribe_removes_subscription(): void
    {
        $this->actingShop();
        WaPushSubscription::create(['endpoint' => 'https://e/1', 'p256dh' => 'k', 'auth' => 'a']);

        $this->postJson('/api/wa/push/unsubscribe', ['endpoint' => 'https://e/1'])->assertOk();

        $this->assertSame(0, WaPushSubscription::count());
    }

    public function test_endpoints_require_auth(): void
    {
        $this->getJson('/api/wa/push/vapid-key')->assertStatus(401);
        $this->postJson('/api/wa/push/subscribe', [])->assertStatus(401);
    }
}
```

- [ ] **Step 3: Run test to verify it fails**

Run: `php artisan test --filter=WaPushTest`
Expected: FAIL — 404s (routes missing).

- [ ] **Step 4: Migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('wa_push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('endpoint', 500)->unique();
            $table->string('p256dh', 255);
            $table->string('auth', 255);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('wa_push_subscriptions');
    }
};
```

- [ ] **Step 5: Model**

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class WaPushSubscription extends Model
{
    protected $guarded = [];
}
```

- [ ] **Step 6: WebPush service**

```php
<?php

namespace App\Services\Wa;

use App\Models\WaPushSubscription;
use Illuminate\Support\Facades\Log;
use Minishlink\WebPush\Subscription;
use Minishlink\WebPush\WebPush as PushClient;

/**
 * Browser push notifications for new WhatsApp messages (single-tenant: all
 * subscriptions are Francis's). Fire-and-forget; dead endpoints are pruned.
 * Ported from the push logic in whatsapp-autoreply/server.js.
 */
class WebPush
{
    public function enabled(): bool
    {
        return (bool) (config('services.webpush.public_key') && config('services.webpush.private_key'));
    }

    public function notify(string $title, string $body, ?string $tag = null): void
    {
        if (!$this->enabled()) {
            return;
        }

        $subscriptions = WaPushSubscription::all();
        if ($subscriptions->isEmpty()) {
            return;
        }

        try {
            $client = new PushClient(['VAPID' => [
                'subject' => config('services.webpush.subject'),
                'publicKey' => config('services.webpush.public_key'),
                'privateKey' => config('services.webpush.private_key'),
            ]]);

            $payload = json_encode(['title' => $title, 'body' => $body, 'tag' => $tag]);
            foreach ($subscriptions as $sub) {
                $client->queueNotification(Subscription::create([
                    'endpoint' => $sub->endpoint,
                    'publicKey' => $sub->p256dh,
                    'authToken' => $sub->auth,
                ]), $payload);
            }

            foreach ($client->flush() as $report) {
                $status = $report->getResponse()?->getStatusCode();
                if (!$report->isSuccess() && in_array($status, [404, 410], true)) {
                    WaPushSubscription::where('endpoint', $report->getEndpoint())->delete();
                }
            }
        } catch (\Throwable $e) {
            // Push must never break the reply pipeline.
            Log::warning('WA web push failed: ' . $e->getMessage());
        }
    }
}
```

- [ ] **Step 7: Controller**

```php
<?php

namespace App\Http\Controllers;

use App\Models\WaPushSubscription;
use App\Services\Wa\WebPush;
use Illuminate\Http\Request;

class WaPushController extends Controller
{
    public function vapidKey(WebPush $push)
    {
        if (!$push->enabled()) {
            return response()->json(['error' => 'push not configured'], 503);
        }

        return response()->json(['key' => config('services.webpush.public_key')]);
    }

    public function subscribe(Request $request)
    {
        $data = $request->validate([
            'endpoint' => ['required', 'string', 'max:500', 'url'],
            'keys.p256dh' => ['required', 'string', 'max:255'],
            'keys.auth' => ['required', 'string', 'max:255'],
        ]);

        WaPushSubscription::updateOrCreate(
            ['endpoint' => $data['endpoint']],
            ['p256dh' => $data['keys']['p256dh'], 'auth' => $data['keys']['auth']]
        );

        return response()->json(['ok' => true]);
    }

    public function unsubscribe(Request $request)
    {
        $data = $request->validate(['endpoint' => ['required', 'string', 'max:500']]);

        WaPushSubscription::where('endpoint', $data['endpoint'])->delete();

        return response()->json(['ok' => true]);
    }
}
```

- [ ] **Step 8: Routes**

In `routes/api.php`, inside the existing `auth:sanctum` group containing the `/master/...` routes, add:

```php
    Route::get('/wa/push/vapid-key', [\App\Http\Controllers\WaPushController::class, 'vapidKey']);
    Route::post('/wa/push/subscribe', [\App\Http\Controllers\WaPushController::class, 'subscribe']);
    Route::post('/wa/push/unsubscribe', [\App\Http\Controllers\WaPushController::class, 'unsubscribe']);
```

- [ ] **Step 9: Run test to verify it passes**

Run: `php artisan test --filter=WaPushTest`
Expected: PASS (5 tests).

- [ ] **Step 10: Commit**

```bash
git add composer.json composer.lock database/migrations/2026_06_12_000001_create_wa_push_subscriptions_table.php app/Models/WaPushSubscription.php app/Services/Wa/WebPush.php app/Http/Controllers/WaPushController.php routes/api.php tests/Feature/WaPushTest.php
git commit -m "feat(wa): web-push subscriptions + notify service (bizrezzy subscribes later)"
```

---

### Task 9: PersonaResolver

**Files:**
- Create: `app/Services/Wa/PersonaResolver.php`
- Test: `tests/Feature/WaPersonaResolverTest.php`

Folds Node's `lib/personas.js` + `lib/salesPrompt.js` + `lib/router.js` and the controller lookups into one resolver. Branches:
1. **Tenant number** → `shops.persona` if set, else category provider prompt; never tools.
2. **Sales number + active non-default BotPrompt** → override body; no tools.
3. **Sales number + sender is a ShopCustomer of the shop owning the number** (last-9-digit match) → provider prompt; no tools.
4. **Sales number + lead** → `Prompts::REZZY_SALES`; tools ON.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Models\BotPrompt;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Models\WaAccount;
use App\Services\Wa\PersonaResolver;
use App\Support\Wa\Prompts;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaPersonaResolverTest extends TestCase
{
    use RefreshDatabase;

    private function account(string $phoneNumberId, ?Shop $shop = null): WaAccount
    {
        return WaAccount::create([
            'shop_id' => $shop?->id,
            'phone_number' => '+971500000003',
            'phone_number_id' => $phoneNumberId,
            'waba_id' => 'waba_p',
        ]);
    }

    public function test_tenant_uses_custom_persona_when_set(): void
    {
        $shop = Shop::factory()->create(['persona' => 'You are Bella, the salon receptionist.']);
        $result = (new PersonaResolver())->resolve($this->account('pn_tenant', $shop), '971555000111');

        $this->assertSame('You are Bella, the salon receptionist.', $result['prompt']);
        $this->assertFalse($result['offerTools']);
    }

    public function test_tenant_falls_back_to_category_prompt(): void
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9, 'persona' => null]);
        $result = (new PersonaResolver())->resolve($this->account('pn_tenant2', $shop), '971555000111');

        $this->assertStringContainsString('Glow Salon, a salon business', $result['prompt']);
        $this->assertFalse($result['offerTools']);
    }

    public function test_sales_lead_gets_sales_prompt_with_tools(): void
    {
        config(['services.whatsapp.sales_phone_number_id' => 'pn_sales']);
        $result = (new PersonaResolver())->resolve($this->account('pn_sales'), '971555000111');

        $this->assertSame(Prompts::REZZY_SALES, $result['prompt']);
        $this->assertTrue($result['offerTools']);
    }

    public function test_sales_override_wins_for_everyone_and_disables_tools(): void
    {
        config(['services.whatsapp.sales_phone_number_id' => 'pn_sales']);
        BotPrompt::create(['name' => 'Salon Test', 'body' => 'You are a test salon bot.', 'is_active' => true, 'is_default' => false]);

        $result = (new PersonaResolver())->resolve($this->account('pn_sales'), '971555000111');

        $this->assertSame('You are a test salon bot.', $result['prompt']);
        $this->assertFalse($result['offerTools']);
    }

    public function test_default_bot_prompt_is_not_an_override(): void
    {
        config(['services.whatsapp.sales_phone_number_id' => 'pn_sales']);
        BotPrompt::create(['name' => 'Sales Bot', 'body' => 'default body', 'is_active' => true, 'is_default' => true]);

        $result = (new PersonaResolver())->resolve($this->account('pn_sales'), '971555000111');

        $this->assertSame(Prompts::REZZY_SALES, $result['prompt']);
        $this->assertTrue($result['offerTools']);
    }

    public function test_sales_known_customer_gets_provider_prompt(): void
    {
        config(['services.whatsapp.sales_phone_number_id' => 'pn_sales']);
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9]);
        ShopCustomer::create([
            'shop_id' => $shop->id,
            'name' => 'Aisha',
            'whatsapp' => '+971555000111',
            'whatsapp_normalized' => '971555000111',
        ]);

        $result = (new PersonaResolver())->resolve($this->account('pn_sales', $shop), '971555000111');

        $this->assertStringContainsString('Glow Salon, a salon business', $result['prompt']);
        $this->assertFalse($result['offerTools']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WaPersonaResolverTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Services\Wa;

use App\Models\BotPrompt;
use App\Models\Shop;
use App\Models\ShopCustomer;
use App\Models\WaAccount;
use App\Support\ServiceCategories;
use App\Support\Wa\Prompts;

/**
 * Pick the system prompt (and whether the onboarding tool is offered) for an
 * inbound message. Folds the Node service's persona / sales-override / shop
 * routing into direct lookups. Ported from whatsapp-autoreply lib/personas.js,
 * lib/salesPrompt.js and lib/router.js.
 */
class PersonaResolver
{
    /** @return array{prompt: string, offerTools: bool} */
    public function resolve(WaAccount $account, string $from): array
    {
        if (!$this->isSalesNumber($account)) {
            // Tenant number: custom persona if the master set one, else the
            // category-based default. Never the onboarding tool.
            $shop = $account->shop;
            $prompt = ($shop?->persona && trim($shop->persona) !== '')
                ? $shop->persona
                : Prompts::provider($shop?->name ?? 'this business', ServiceCategories::name($shop?->category_id));

            return ['prompt' => $prompt, 'offerTools' => false];
        }

        // Sales number: an active master-panel override wins for everyone —
        // a live persona test, no onboarding.
        if ($override = $this->salesOverride()) {
            return ['prompt' => $override->body, 'offerTools' => false];
        }

        // Known customer of the shop owning this number → that shop's assistant.
        if ($shop = $this->customerShop($account, $from)) {
            return [
                'prompt' => Prompts::provider($shop->name, ServiceCategories::name($shop->category_id)),
                'offerTools' => false,
            ];
        }

        // Lead → the default Rezzy sales assistant (the only path that may onboard).
        return ['prompt' => Prompts::REZZY_SALES, 'offerTools' => true];
    }

    public function isSalesNumber(WaAccount $account): bool
    {
        $salesId = (string) config('services.whatsapp.sales_phone_number_id');

        return $salesId !== '' && $account->phone_number_id === $salesId;
    }

    /** The active non-default master-panel prompt, or null for normal behaviour. */
    public function salesOverride(): ?BotPrompt
    {
        $active = BotPrompt::where('is_active', true)->first();

        return ($active && !$active->is_default && trim((string) $active->body) !== '') ? $active : null;
    }

    private function customerShop(WaAccount $account, string $from): ?Shop
    {
        if (!$account->shop_id) {
            return null;
        }

        $normalized = ShopCustomer::normalize($from);
        if ($normalized === '') {
            return null;
        }

        $tail = strlen($normalized) > 9 ? substr($normalized, -9) : $normalized;
        $isCustomer = ShopCustomer::where('shop_id', $account->shop_id)
            ->where('whatsapp_normalized', 'LIKE', '%' . $tail)
            ->exists();

        return $isCustomer ? $account->shop : null;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WaPersonaResolverTest`
Expected: PASS (6 tests). If `ShopCustomer::create` fails on required columns, check `database/migrations/*shop_customers*` and add the minimum required fields to the test factory call.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Wa/PersonaResolver.php tests/Feature/WaPersonaResolverTest.php
git commit -m "feat(wa): persona resolver (override / customer / lead / tenant branches)"
```

---

### Task 10: OnboardBusiness action

**Files:**
- Create: `app/Actions/Wa/OnboardBusiness.php`
- Test: `tests/Feature/WaOnboardBusinessTest.php`

Ports `lib/onboard.js`. Creates the shop **directly** via `Shop::create` (the model's `booted()` hook generates `shop_code`/`pin` and forces `status=active`) instead of HTTP. Returns the deterministic credentials message — the model never types IDs/PINs.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Actions\Wa\OnboardBusiness;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class WaOnboardBusinessTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_shop_and_returns_credentials_message(): void
    {
        $message = (new OnboardBusiness())->run(
            ['business_name' => 'Glow Salon', 'category' => 'Salon'],
            '971555000111'
        );

        $shop = Shop::where('name', 'Glow Salon')->firstOrFail();
        $this->assertSame('+971555000111', $shop->phone);
        $this->assertSame(9, (int) $shop->category_id);
        $this->assertNotNull($shop->category_confirmed_at);
        $this->assertStringContainsString("Business ID: {$shop->shop_code}", $message);
        $this->assertStringContainsString("PIN: {$shop->pin}", $message);
        $this->assertStringContainsString('https://bizrezzy.eloquentservice.com', $message);
    }

    public function test_resends_credentials_for_existing_phone(): void
    {
        $existing = Shop::factory()->create(['name' => 'Old Salon', 'phone' => '+971555000111']);

        $message = (new OnboardBusiness())->run(
            ['business_name' => 'Different Name', 'category' => 'Salon'],
            '971555000111'
        );

        $this->assertSame(1, Shop::count()); // no duplicate created
        $this->assertStringContainsString('already created', $message);
        $this->assertStringContainsString("Business ID: {$existing->shop_code}", $message);
    }

    public function test_rejects_duplicate_business_name(): void
    {
        Shop::factory()->create(['name' => 'Glow Salon', 'phone' => '+971555999999']);

        $message = (new OnboardBusiness())->run(
            ['business_name' => 'Glow Salon', 'category' => 'Salon'],
            '971555000111'
        );

        $this->assertSame(1, Shop::count());
        $this->assertStringContainsString('already exists on Rezzy', $message);
    }

    public function test_asks_again_on_missing_or_bad_input(): void
    {
        $bad = [
            ['business_name' => '', 'category' => 'Salon'],
            ['business_name' => 'Glow', 'category' => 'Bakery'],
            [],
        ];
        foreach ($bad as $input) {
            $message = (new OnboardBusiness())->run($input, '971555000111');
            $this->assertStringContainsString('exact business name', $message);
        }
        $this->assertSame(0, Shop::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WaOnboardBusinessTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement**

```php
<?php

namespace App\Actions\Wa;

use App\Models\Shop;
use App\Support\ServiceCategories;

/**
 * In-chat onboarding: the Rezzy sales bot creates a provider account for a
 * lead right inside the WhatsApp conversation. Their WhatsApp number becomes
 * the account phone. Deterministic on purpose: the model never types IDs or
 * PINs itself. Ported from whatsapp-autoreply/lib/onboard.js.
 */
class OnboardBusiness
{
    private const APP_URL = 'https://bizrezzy.eloquentservice.com';

    public const TOOL = [
        'name' => 'create_business_account',
        'description' => 'Create the Rezzy account for this business owner. Use ONLY after the owner has explicitly confirmed their exact business name and category AND clearly agreed to sign up. The system sends them their login details automatically afterwards.',
        'input_schema' => [
            'type' => 'object',
            'properties' => [
                'business_name' => [
                    'type' => 'string',
                    'description' => "The owner's exact business name, as they confirmed it",
                ],
                'category' => [
                    'type' => 'string',
                    'enum' => ['Barber', 'Plumbing', 'AC Repair', 'Electrician', 'Car Wash', 'Painting', 'Cleaning', 'Pest Control', 'Salon'],
                    'description' => 'The business category, confirmed with the owner',
                ],
            ],
            'required' => ['business_name', 'category'],
        ],
    ];

    /** Create (or recover) the account and return the exact message to send. */
    public function run(array $input, string $whatsappNumber): string
    {
        $name = trim((string) ($input['business_name'] ?? ''));
        $categoryId = $this->categoryId((string) ($input['category'] ?? ''));

        if ($name === '' || !$categoryId) {
            return "Almost there — I just need your exact business name and category to set you up. What's the business called?";
        }

        // One account per WhatsApp number: resend credentials instead of duplicating.
        if ($existing = $this->shopByPhone($whatsappNumber)) {
            return $this->credentialsMessage(
                "Good news — your Rezzy account for {$existing->name} is already created! 😊 Here are your login details again:",
                $existing->shop_code,
                $existing->pin
            );
        }

        if (Shop::where('name', $name)->exists()) {
            return "Hmm, a business named \"{$name}\" already exists on Rezzy. Is your business name maybe written slightly differently? Tell me the exact name and I'll set it up. 😊";
        }

        $shop = Shop::create([
            'name' => $name,
            'phone' => '+' . preg_replace('/\D+/', '', $whatsappNumber),
            'category_id' => $categoryId,
            'is_verified' => true,
            'category_confirmed_at' => now(),
        ]);

        return $this->credentialsMessage(
            "Great news — your Rezzy account for {$shop->name} is created! 😊 Here are your login details:",
            $shop->shop_code,
            $shop->pin
        );
    }

    private function categoryId(string $category): ?int
    {
        foreach (ServiceCategories::LIST as $c) {
            if ($c['name'] === $category) {
                return $c['id'];
            }
        }

        return null;
    }

    /** Find an existing account by phone (last-9-digit match) to avoid duplicates. */
    private function shopByPhone(string $number): ?Shop
    {
        $digits = preg_replace('/\D+/', '', $number);
        if (strlen($digits) < 7) {
            return null;
        }
        $tail = substr($digits, -9);

        return Shop::whereNotNull('phone')
            ->get(['id', 'name', 'phone', 'shop_code', 'pin'])
            ->first(function ($s) use ($tail) {
                $p = preg_replace('/\D+/', '', (string) $s->phone);

                return $p !== '' && substr($p, -9) === $tail;
            });
    }

    private function credentialsMessage(string $intro, string $shopCode, string $pin): string
    {
        return "{$intro}\n\n"
            . "Business ID: {$shopCode}\n"
            . "PIN: {$pin}\n\n"
            . 'Log in here: ' . self::APP_URL . "\n\n"
            . "Save these — you'll use them every time you log in.\n\n"
            . "The last step is connecting your WhatsApp booking line so it can start answering your customers — I'm on it, and I'll message you right here the moment it's live. 😊";
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=WaOnboardBusinessTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Commit**

```bash
git add app/Actions/Wa/OnboardBusiness.php tests/Feature/WaOnboardBusinessTest.php
git commit -m "feat(wa): in-chat business onboarding action with tool definition"
```

---

### Task 11: ProcessWaReply job

**Files:**
- Create: `app/Jobs/ProcessWaReply.php`
- Test: `tests/Feature/ProcessWaReplyTest.php`

The orchestrator — ports `server.js`'s webhook POST pipeline. Tests run the job synchronously with `dispatch_sync` and fake all HTTP.

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaReply;
use App\Models\Shop;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Models\WaMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProcessWaReplyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config([
            'services.anthropic.key' => 'sk-test',
            'services.anthropic.model' => 'claude-haiku-4-5',
            'services.whatsapp.graph_version' => 'v25.0',
            'services.whatsapp.default_token' => 'system-token',
            'services.whatsapp.sales_phone_number_id' => 'pn_sales',
            'services.openai.key' => null,      // voice off by default
            'services.webpush.public_key' => null, // push off in tests
        ]);
    }

    private function tenantContact(?string $persona = null): WaContact
    {
        $shop = Shop::factory()->create(['name' => 'Glow Salon', 'category_id' => 9, 'persona' => $persona]);
        $account = WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000005',
            'phone_number_id' => 'pn_tenant',
            'waba_id' => 'waba_j',
        ]);

        return WaContact::create(['wa_account_id' => $account->id, 'wa_number' => '971555000111', 'name' => 'Aisha']);
    }

    private function salesContact(): WaContact
    {
        $account = WaAccount::create([
            'shop_id' => null,
            'phone_number' => '+971500000006',
            'phone_number_id' => 'pn_sales',
            'waba_id' => 'waba_j',
        ]);

        return WaContact::create(['wa_account_id' => $account->id, 'wa_number' => '971555000222', 'name' => 'Omar']);
    }

    private function fakeClaudeAndGraph(string $replyText = 'Sure! We are open 9 to 5 😊'): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => $replyText]],
            ]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.REPLY1']]]),
        ]);
    }

    public function test_replies_to_tenant_text_with_claude_and_records_outbound(): void
    {
        $this->fakeClaudeAndGraph();
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', 'what are your timings?', 'text', 'wamid.IN1');

        dispatch_sync(new ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertNotNull($out);
        $this->assertSame('Sure! We are open 9 to 5 😊', $out->body);
        $this->assertSame('wamid.REPLY1', $out->wa_message_id);
        // Claude got the tenant provider prompt (no custom persona set)
        Http::assertSent(function ($request) {
            if (!str_contains($request->url(), 'anthropic')) {
                return false;
            }
            return str_contains($request['system'][0]['text'], 'Glow Salon, a salon business')
                && !array_key_exists('tools', $request->data());
        });
    }

    public function test_bare_greeting_gets_canned_welcome_without_claude(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.W1']]])]);
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', 'hiii', 'text', 'wamid.IN2');

        dispatch_sync(new ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertStringContainsString('Welcome to Glow Salon', $out->body);
        Http::assertNotSent(fn ($request) => str_contains($request->url(), 'anthropic'));
    }

    public function test_reactions_and_emoji_only_texts_get_no_reply(): void
    {
        Http::fake();
        $contact = $this->tenantContact();
        $r1 = $contact->recordMessage('in', '[reaction]', 'reaction', 'wamid.IN3');
        $r2 = $contact->recordMessage('in', '👍🙏', 'text', 'wamid.IN4');

        dispatch_sync(new ProcessWaReply($r1->id));
        dispatch_sync(new ProcessWaReply($r2->id));

        $this->assertSame(0, $contact->messages()->where('direction', 'out')->count());
        Http::assertNothingSent();
    }

    public function test_non_text_media_gets_polite_fallback(): void
    {
        Http::fake(['graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.F1']]])]);
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', '[image message]', 'image', 'wamid.IN5');

        dispatch_sync(new ProcessWaReply($inbound->id));

        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertStringContainsString("couldn't open that", $out->body);
    }

    public function test_sales_lead_gets_tools_and_onboarding_creates_shop(): void
    {
        Http::fake([
            'api.anthropic.com/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'tool_use', 'id' => 'tu_1', 'name' => 'create_business_account',
                     'input' => ['business_name' => 'Omar Barbershop', 'category' => 'Barber']],
                ],
            ]),
            'graph.facebook.com/*' => Http::response(['messages' => [['id' => 'wamid.OB1']]]),
        ]);
        $contact = $this->salesContact();
        $inbound = $contact->recordMessage('in', 'yes create my account', 'text', 'wamid.IN6');

        dispatch_sync(new ProcessWaReply($inbound->id));

        $shop = Shop::where('name', 'Omar Barbershop')->firstOrFail();
        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertStringContainsString("Business ID: {$shop->shop_code}", $out->body);
        Http::assertSent(function ($request) {
            return str_contains($request->url(), 'anthropic')
                && ($request['tools'][0]['name'] ?? null) === 'create_business_account';
        });
    }

    public function test_does_not_reply_twice_when_thread_already_answered(): void
    {
        Http::fake();
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', 'hello, prices?', 'text', 'wamid.IN7');
        $contact->recordMessage('out', 'Already answered manually', 'text', 'wamid.MANUAL');

        dispatch_sync(new ProcessWaReply($inbound->id));

        Http::assertNothingSent();
        $this->assertSame(1, $contact->messages()->where('direction', 'out')->count());
    }

    public function test_claude_failure_is_quiet_no_outbound(): void
    {
        Http::fake(['api.anthropic.com/*' => Http::response('overloaded', 529)]);
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', 'hello, prices?', 'text', 'wamid.IN8');

        dispatch_sync(new ProcessWaReply($inbound->id)); // must not throw

        $this->assertSame(0, $contact->messages()->where('direction', 'out')->count());
    }

    public function test_voice_note_is_transcribed_and_answered_with_voice(): void
    {
        config(['services.openai.key' => 'sk-oai']);
        Http::fake([
            'api.openai.com/v1/audio/transcriptions' => Http::response(['text' => 'how much is a haircut']),
            'api.anthropic.com/v1/messages' => Http::response(['content' => [['type' => 'text', 'text' => 'A haircut is 50 AED 😊']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGGBYTES'),
            'graph.facebook.com/v25.0/pn_tenant/media' => Http::response(['id' => 'media_voice_1']),
            'graph.facebook.com/v25.0/pn_tenant/messages' => Http::response(['messages' => [['id' => 'wamid.V1']]]),
            'graph.facebook.com/v25.0/media_in_1' => Http::response(['url' => 'https://lookaside.test/audio', 'mime_type' => 'audio/ogg']),
            'lookaside.test/audio' => Http::response('INAUDIO'),
        ]);
        $contact = $this->tenantContact();
        $inbound = $contact->recordMessage('in', '[audio message]', 'audio', 'wamid.IN9', null, ['media_id' => 'media_in_1', 'media_mime' => 'audio/ogg']);

        dispatch_sync(new ProcessWaReply($inbound->id));

        $this->assertSame('🎤 how much is a haircut', $inbound->fresh()->body);
        $out = $contact->messages()->where('direction', 'out')->first();
        $this->assertSame('🔊 A haircut is 50 AED 😊', $out->body);
        $this->assertSame('audio', $out->type);
        $this->assertSame('media_voice_1', $out->media_id);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ProcessWaReplyTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement the job**

```php
<?php

namespace App\Jobs;

use App\Actions\Wa\OnboardBusiness;
use App\Models\WaAccount;
use App\Models\WaContact;
use App\Models\WaMessage;
use App\Services\Wa\ClaudeClient;
use App\Services\Wa\PersonaResolver;
use App\Services\Wa\Speech;
use App\Services\Wa\Transcriber;
use App\Services\Wa\WebPush;
use App\Services\WhatsAppCloud;
use App\Support\Wa\ConversationHistory;
use App\Support\Wa\Greetings;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

/**
 * Generate and send the auto-reply for one stored inbound WhatsApp message.
 * Ports the pipeline of the retired whatsapp-autoreply Node service:
 * skip reactions → canned greeting → transcribe voice → resolve persona →
 * Claude (tool-enabled for sales leads) → voice-out → send → record.
 *
 * Fail-quiet: any error logs and stops. The inbound is already stored, so
 * the shop can always answer manually in bizrezzy. Never retried ($tries=1)
 * so a half-failure can never double-send.
 */
class ProcessWaReply implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 1;
    public int $timeout = 120;

    public function __construct(public int $waMessageId)
    {
    }

    public function handle(
        WhatsAppCloud $wa,
        ClaudeClient $claude,
        PersonaResolver $personas,
        Transcriber $transcriber,
        Speech $speech,
        WebPush $push,
        OnboardBusiness $onboard,
    ): void {
        $message = WaMessage::with('waContact.waAccount.shop')->find($this->waMessageId);
        if (!$message || $message->direction !== 'in') {
            return;
        }

        $contact = $message->waContact;
        $account = $contact?->waAccount;
        if (!$contact || !$account) {
            return;
        }

        // Idempotency: if anything was sent on this thread after the inbound
        // arrived (manual reply, earlier job run), never auto-reply again.
        $alreadyAnswered = $contact->messages()
            ->where('direction', 'out')
            ->where('id', '>', $message->id)
            ->exists();
        if ($alreadyAnswered) {
            return;
        }

        $from = $contact->wa_number;
        $name = $contact->name ?: ('+' . $from);

        // Emoji-like signals (reactions 👍, stickers) — store-only, never reply.
        if (in_array($message->type, ['reaction', 'sticker'], true)) {
            $push->notify($name, "[{$message->type}]", $from);
            return;
        }

        // Emoji-only / symbol-only texts ("👍", "❤️🙏") — store-only, never reply.
        if ($message->type === 'text' && !preg_match('/[\p{L}\p{N}]/u', (string) $message->body)) {
            $push->notify($name, (string) $message->body, $from);
            return;
        }

        $salesNumber = $personas->isSalesNumber($account);
        // When an override is active we're in a live persona test — the canned
        // greeting must NOT fire; every message goes through the override prompt.
        $overrideActive = $salesNumber && $personas->salesOverride() !== null;

        // Bare greetings: instant canned welcome — no Claude call, no API cost.
        if ($message->type === 'text' && !$overrideActive && Greetings::isBare($message->body)) {
            $welcome = $salesNumber
                ? "Hi! 😊 Welcome to Rezzy — we help businesses get more bookings on WhatsApp, 24/7. What kind of business do you run?"
                : 'Hi! 😊 Welcome to ' . ($account->shop?->name ?? 'our shop') . '. How can I help you today?';
            $push->notify($name, (string) $message->body, $from);
            $this->sendText($wa, $account, $contact, $welcome);
            return;
        }

        // Voice notes: transcribe, then answer them like normal text.
        $isVoice = false;
        if (in_array($message->type, ['audio', 'voice'], true) && $transcriber->available()) {
            $transcript = $this->transcribe($wa, $transcriber, $account, $message);
            if ($transcript) {
                $isVoice = true;
                $message->update(['body' => '🎤 ' . $transcript]);
                $contact->update(['last_message_preview' => mb_substr($message->body, 0, 500)]);
            }
        }

        // Remaining non-text (images, files, unheard voice) — polite fallback.
        if ($message->type !== 'text' && !$isVoice) {
            $push->notify($name, "Sent a {$message->type} message", $from);
            $this->sendText(
                $wa, $account, $contact,
                "Hi! 😊 I couldn't open that — could you please type your message? I'll help you right away!"
            );
            return;
        }

        $push->notify($name, (string) $message->body, $from);

        ['prompt' => $prompt, 'offerTools' => $offerTools] = $personas->resolve($account, $from);
        $history = ConversationHistory::for($contact);
        if (!$history) {
            return;
        }

        try {
            $onboarded = false;
            if ($offerTools) {
                $agent = $claude->agentReply($prompt, $history, [OnboardBusiness::TOOL]);
                $reply = $agent['text'];
                if (($agent['toolUse']['name'] ?? null) === 'create_business_account') {
                    // Deterministic credentials message — the model never types IDs/PINs.
                    $reply = $onboard->run($agent['toolUse']['input'], $from);
                    $onboarded = true;
                }
                $reply = $reply !== '' ? $reply : 'One moment…';
            } else {
                $reply = $claude->reply($prompt, $history);
            }

            // Voice in → voice out. Credential messages always go as TEXT so
            // they can be copied. Any TTS hiccup falls back to plain text.
            if ($isVoice && !$onboarded && $speech->available()) {
                try {
                    $audio = $speech->synthesize($reply);
                    $mediaId = $wa->uploadMedia($account, $audio, 'audio/ogg');
                    $sent = $wa->sendVoice($account, $from, $mediaId);
                    $contact->recordMessage('out', '🔊 ' . $reply, 'audio', $sent['messages'][0]['id'] ?? null, 'sent', ['media_id' => $mediaId]);
                    return;
                } catch (\Throwable $e) {
                    Log::warning("WA voice reply failed for {$from}: " . $e->getMessage());
                }
            }

            $this->sendText($wa, $account, $contact, $reply);
        } catch (\Throwable $e) {
            Log::error("WA auto-reply failed for {$from}: " . $e->getMessage());
        }
    }

    private function sendText(WhatsAppCloud $wa, WaAccount $account, WaContact $contact, string $text): void
    {
        try {
            $sent = $wa->sendText($account, $contact->wa_number, $text);
            $contact->recordMessage('out', $text, 'text', $sent['messages'][0]['id'] ?? null, 'sent');
        } catch (\Throwable $e) {
            Log::error("WA send failed for {$contact->wa_number}: " . $e->getMessage());
        }
    }

    /** Audio bytes come from the already-downloaded media file, else Graph. */
    private function transcribe(WhatsAppCloud $wa, Transcriber $transcriber, WaAccount $account, WaMessage $message): ?string
    {
        try {
            $bytes = null;
            $mime = $message->media_mime ?: 'audio/ogg';
            if ($message->media_path && Storage::disk('public')->exists($message->media_path)) {
                $bytes = Storage::disk('public')->get($message->media_path);
            } elseif ($message->media_id) {
                $download = $wa->downloadMedia($account, $message->media_id);
                if ($download) {
                    $bytes = $download['data'];
                    $mime = $download['mime'];
                }
            }

            return $bytes ? $transcriber->transcribe($bytes, $mime) : null;
        } catch (\Throwable $e) {
            Log::warning('WA voice transcription failed: ' . $e->getMessage());
            return null;
        }
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ProcessWaReplyTest`
Expected: PASS (8 tests). Common gotchas if not: the voice test needs the webhook-stored media to be absent from `Storage` so the job takes the `downloadMedia` path — the Http fakes above cover both Graph calls.

- [ ] **Step 5: Commit**

```bash
git add app/Jobs/ProcessWaReply.php tests/Feature/ProcessWaReplyTest.php
git commit -m "feat(wa): ProcessWaReply job — full auto-reply pipeline in Laravel"
```

---

### Task 12: Webhook — signature verification + job dispatch

**Files:**
- Modify: `app/Http/Controllers/WaWebhookController.php` (`receive()` + `handleChange()`)
- Test: `tests/Feature/WaWebhookDispatchTest.php`

- [ ] **Step 1: Write the failing test**

```php
<?php

namespace Tests\Feature;

use App\Jobs\ProcessWaReply;
use App\Models\Shop;
use App\Models\WaAccount;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class WaWebhookDispatchTest extends TestCase
{
    use RefreshDatabase;

    private function payload(string $phoneNumberId = 'pn_hook', string $msgId = 'wamid.HOOK1'): array
    {
        return [
            'entry' => [[
                'changes' => [[
                    'value' => [
                        'metadata' => ['phone_number_id' => $phoneNumberId],
                        'contacts' => [['wa_id' => '971555000111', 'profile' => ['name' => 'Aisha']]],
                        'messages' => [[
                            'id' => $msgId,
                            'from' => '971555000111',
                            'type' => 'text',
                            'text' => ['body' => 'hello, prices?'],
                        ]],
                    ],
                ]],
            ]],
        ];
    }

    private function makeAccount(): WaAccount
    {
        $shop = Shop::factory()->create();

        return WaAccount::create([
            'shop_id' => $shop->id,
            'phone_number' => '+971500000007',
            'phone_number_id' => 'pn_hook',
            'waba_id' => 'waba_hook',
        ]);
    }

    public function test_inbound_message_dispatches_reply_job_once(): void
    {
        Queue::fake();
        $this->makeAccount();

        $this->postJson('/api/wa/webhook', $this->payload())->assertOk();
        // Meta retry of the same message: stored-once, dispatched-once.
        $this->postJson('/api/wa/webhook', $this->payload())->assertOk();

        Queue::assertPushed(ProcessWaReply::class, 1);
    }

    public function test_status_callbacks_dispatch_nothing(): void
    {
        Queue::fake();
        $this->makeAccount();

        $this->postJson('/api/wa/webhook', [
            'entry' => [['changes' => [['value' => [
                'metadata' => ['phone_number_id' => 'pn_hook'],
                'statuses' => [['id' => 'wamid.X', 'status' => 'delivered']],
            ]]]]],
        ])->assertOk();

        Queue::assertNothingPushed();
    }

    public function test_rejects_bad_signature_when_app_secret_set(): void
    {
        config(['services.whatsapp.app_secret' => 'meta-secret']);
        Queue::fake();
        $this->makeAccount();

        $body = json_encode($this->payload());

        // Wrong signature → 403, nothing stored or dispatched.
        $this->call('POST', '/api/wa/webhook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256=deadbeef',
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertStatus(403);
        Queue::assertNothingPushed();

        // Correct signature → accepted.
        $signature = 'sha256=' . hash_hmac('sha256', $body, 'meta-secret');
        $this->call('POST', '/api/wa/webhook', [], [], [], [
            'HTTP_X_HUB_SIGNATURE_256' => $signature,
            'CONTENT_TYPE' => 'application/json',
        ], $body)->assertOk();
        Queue::assertPushed(ProcessWaReply::class, 1);
    }

    public function test_no_signature_check_when_app_secret_unset(): void
    {
        config(['services.whatsapp.app_secret' => null]);
        Queue::fake();
        $this->makeAccount();

        $this->postJson('/api/wa/webhook', $this->payload())->assertOk();

        Queue::assertPushed(ProcessWaReply::class, 1);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=WaWebhookDispatchTest`
Expected: FAIL — no job dispatched, no 403.

- [ ] **Step 3: Implement**

In `WaWebhookController::receive()`, add signature verification at the top (before `$request->all()`):

```php
    public function receive(Request $request)
    {
        // Verify Meta's webhook signature when an app secret is configured
        // (no-op when unset — parity with the retired Node service).
        $secret = config('services.whatsapp.app_secret');
        if ($secret) {
            $expected = 'sha256=' . hash_hmac('sha256', $request->getContent(), $secret);
            $signature = (string) $request->header('X-Hub-Signature-256');
            if (!hash_equals($expected, $signature)) {
                return response('Forbidden', 403);
            }
        }

        $payload = $request->all();
        // ... rest unchanged
```

In `handleChange()`, capture the stored message and dispatch the job. Replace the final line of the messages loop:

```php
            // before:
            $contact->recordMessage('in', $body, $type, $waMessageId, null, $media);

            // after:
            $stored = $contact->recordMessage('in', $body, $type, $waMessageId, null, $media);

            // Auto-reply runs in the background — the webhook ACKs instantly.
            // Meta retries never reach here (wa_message_id dedupe above), so
            // a message is dispatched exactly once.
            \App\Jobs\ProcessWaReply::dispatch($stored->id);
```

- [ ] **Step 4: Run the new test + the full WA suite**

Run: `php artisan test --filter="WaWebhookDispatchTest|WaChatTest|WaShopContextTest|BotPromptTest"`
Expected: ALL PASS (existing webhook-storage behavior unchanged).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/WaWebhookController.php tests/Feature/WaWebhookDispatchTest.php
git commit -m "feat(wa): webhook verifies Meta signature and dispatches ProcessWaReply"
```

---

### Task 13: Full test suite + deployment docs

**Files:**
- Modify: `D:/Francis/projects/2026/Eloquent/standards/deployment.md` (additional working directory — allowed)

- [ ] **Step 1: Run the whole suite**

Run: `php artisan test`
Expected: ALL PASS. Fix any regression before proceeding.

- [ ] **Step 2: Add the queue-worker section to the deployment standard**

Append to `D:/Francis/projects/2026/Eloquent/standards/deployment.md`:

```markdown
## Queue worker (apps with background jobs)

Apps that dispatch queued jobs (e.g. Rezzy backend's WhatsApp auto-replies)
need a supervisor-managed worker on the droplet:

`/etc/supervisor/conf.d/<app-name>-worker.conf`:

```ini
[program:<app-name>-worker]
command=php /var/www/<app-name>/artisan queue:work --queue=default --sleep=1 --tries=1 --max-time=3600
directory=/var/www/<app-name>
autostart=true
autorestart=true
user=www-data
redirect_stderr=true
stdout_logfile=/var/www/<app-name>/storage/logs/worker.log
stopwaitsecs=130
```

Then: `sudo supervisorctl reread && sudo supervisorctl update && sudo supervisorctl start <app-name>-worker:*`

**On every redeploy** add `php artisan queue:restart` after `php artisan migrate --force`
so workers pick up the new code (supervisor restarts them automatically).
```

- [ ] **Step 3: Commit (backend repo)** — the standards file lives outside this repo; if it's under its own git repo, commit there too, otherwise just save it.

```bash
git add -A && git status   # confirm only intended files
git commit -m "test(wa): full suite green for in-app auto-replies" --allow-empty
```

---

### Task 14: Cutover (manual — run with Francis, no code changes)

**No files modified. The Node app is never edited.**

- [ ] **Step 1: Production env** — add to the droplet's backend `.env`: `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `WHATSAPP_APP_SECRET` (from the Meta app), `WHATSAPP_SALES_PHONE_NUMBER_ID` (same value the Node `.env` has as `WHATSAPP_PHONE_NUMBER_ID`), `VAPID_PUBLIC_KEY`/`VAPID_PRIVATE_KEY` (copy from Node `.env` so existing push keys stay valid), `CLAUDE_MODEL=claude-haiku-4-5`. Then `php artisan config:clear`.
- [ ] **Step 2: Deploy + migrate** — redeploy per the deploy skill; `php artisan migrate --force`.
- [ ] **Step 3: Sales account row** — on the droplet, `php artisan tinker`:
  ```php
  \App\Models\WaAccount::firstOrCreate(
      ['phone_number_id' => env('WHATSAPP_SALES_PHONE_NUMBER_ID')],
      ['shop_id' => null, 'phone_number' => '<sales number>', 'waba_id' => '<waba id>']
  );
  ```
- [ ] **Step 4: Start the worker** — install the supervisor program from Task 13; confirm `sudo supervisorctl status` shows RUNNING.
- [ ] **Step 5: Repoint Meta webhook** — in the Meta developer console, change the webhook callback URL to `https://api.eloquentservice.com/api/wa/webhook`. The GET handshake uses the same `WHATSAPP_VERIFY_TOKEN` already in the backend env. **This is a Meta console setting — no Node code is touched.**
- [ ] **Step 6: Live verification** — send "hi" to the sales number (expect the canned welcome), then a real question (expect a Claude reply); send a message to a tenant shop number; send a voice note (expect transcription + voice reply); confirm both sides appear in bizrezzy chat threads.
- [ ] **Step 7: Stop the Node process** — stop/disable the `whatsapp-autoreply` process on its host (pm2/systemd stop). Its code stays on disk untouched as the rollback path (repoint the webhook back to it if anything goes wrong).

---

### Task 15 (DEFERRED — only after the cutover has been stable): Remove Node-only relay surface

**Do NOT execute during initial implementation.** Run only when Francis confirms the Laravel pipeline has been stable in production.

**Files:**
- Modify: `routes/api.php` — remove the routes for `/wa/relay-out`, `/wa/relay-transcript`, `/wa/persona`, `/wa/shop-context`, `/wa/sales-prompt`, `/wa/shop-by-phone`
- Modify: `app/Http/Controllers/WaWebhookController.php` — remove `relayOut()`, `relayTranscript()`, `persona()`, `shopContext()`, `salesPrompt()`, `shopByPhone()`
- Delete: `tests/Feature/WaShopContextTest.php` and any other tests that only exercise the removed endpoints (check `BotPromptTest` — keep its master-panel CRUD tests, remove only relay-endpoint assertions)

- [ ] **Step 1:** Remove routes + methods; run `php artisan test`; fix/remove orphaned tests.
- [ ] **Step 2:** Commit: `git commit -m "chore(wa): remove Node-relay endpoints superseded by in-app pipeline"`

---

## Self-review notes

- **Spec coverage:** queued job (T11–12), Claude (T5), voice (T6, T11), onboarding (T10), history (T4), web push (T8), persona/override (T9), signature verification (T12), worker + deploy (T13–14), relay removal (T15 deferred), prompts (T2), greetings (T3), media send (T7), config (T1). No UI built; no Node file touched anywhere.
- **Type consistency:** `PersonaResolver::resolve()` returns `{prompt, offerTools}` — consumed with the same keys in T11. `ClaudeClient::agentReply()` returns `{text, toolUse:{name, input}}` — matched in T11. `OnboardBusiness::TOOL` / `run(array, string): string` — matched in T11. `recordMessage(direction, body, type, waMessageId, status, media)` — existing signature, used consistently.
- **Risk callout:** `WaPersonaResolverTest` creates a `ShopCustomer` directly — if the table requires more columns, the executor should consult the `shop_customers` migration and add minimal fields (test-only adjustment).
