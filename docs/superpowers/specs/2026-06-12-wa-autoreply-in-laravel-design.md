# WhatsApp Auto-Reply in Laravel (retire the Node service)

**Date:** 2026-06-12
**Status:** Approved design

## Goal

Make this Laravel backend handle WhatsApp auto-replies end-to-end so the
standalone Node service (`whatsapp-autoreply`) is no longer needed. Full
feature parity: Claude text replies, voice notes (Whisper in / TTS out),
in-chat onboarding tool, conversation history, web-push notifications.

**Hard constraint: no changes to any Node app.** Neither `whatsapp-autoreply`
nor the bizrezzy frontend is edited. The Node service is retired
operationally: Meta's webhook URL is repointed to this backend, then the Node
process is stopped. Its codebase stays untouched (and works as a rollback —
point the webhook back and it resumes).

## Decisions made

| Decision | Choice |
|---|---|
| Scope | Full parity: voice, onboarding tool, history, web push |
| Execution model | Queued job + `queue:work` worker (database queue, already configured) |
| Laravel UI | None — no dashboard, no Blade pages |
| Web push opt-in | Backend keeps subscribe/send endpoints; subscribe button lives in bizrezzy (separate task, out of scope) |
| Runtime prompt/model hot-swap (Node `/wa/config`) | Dropped. Prompts come from `bot_prompts` (sales override) + `shops.persona` (per-shop), both already master-panel controlled. Base sales prompt is a code constant. Model fixed via env (`claude-haiku-4-5`). |
| History | DB-backed from `wa_messages` (upgrade over Node's in-memory store) |
| Node codebase | Never edited. Retired by webhook repoint + process stop. |

## Architecture

**New flow:**

```
Meta → POST /api/wa/webhook (Laravel)
  → verify X-Hub-Signature-256 (new)
  → store inbound via existing handleChange()
  → ACK 200 instantly
  → dispatch ProcessWaReply job (only for newly created inbound messages)

queue:work worker → ProcessWaReply:
  → skip reactions/stickers/emoji-only
  → bare greeting → canned welcome (no Claude call)
  → voice note → download media → Whisper transcript
  → other non-text → polite text fallback
  → resolve persona (sales override / provider / lead / tenant shop)
  → Claude reply (tool-enabled for sales leads → onboarding)
  → voice in → TTS voice note out (text fallback on TTS error)
  → send via Cloud API, record outbound wa_message, fire web push
```

All the HTTP relay hops Node used (`/wa/persona`, `/wa/shop-context`,
`/wa/sales-prompt`, `/wa/relay-out`, `/wa/relay-transcript`,
`/wa/shop-by-phone`) collapse into direct method calls inside the job.

## Components

All new code under `app/` mirrors the Node libs it ports.

### Services — `app/Services/Wa/`

- **`ClaudeClient`** (ports `lib/claude.js`): `reply(string $system, array $history): string`
  and `agentReply(string $system, array $history, array $tools): array{text: string, toolUse: ?array}`.
  Plain `Http` POST to `https://api.anthropic.com/v1/messages`, `max_tokens: 1024`,
  system block with `cache_control: {type: ephemeral}`. No SDK dependency.
- **`Transcriber`** (ports `lib/transcribe.js`): Whisper `whisper-1` via multipart
  `Http` POST to `api.openai.com/v1/audio/transcriptions`. Returns transcript or null.
  `available()` checks `OPENAI_API_KEY`.
- **`Speech`** (ports `lib/tts.js`): OpenAI TTS `gpt-4o-mini-tts`, voice `nova`,
  `response_format: opus`, same multi-language accent instructions. Returns OGG/Opus bytes.
- **`WebPush`** (ports push logic in `server.js`): wraps `minishlink/web-push`
  (composer dep). Sends `{title, body, tag}` payloads to all stored subscriptions;
  prunes endpoints returning 404/410. Disabled (no-op) when VAPID keys unset.
- **`PersonaResolver`** (ports `lib/personas.js` + `lib/salesPrompt.js` +
  `lib/accounts.js` + the controller lookups): given the receiving `WaAccount` and
  sender number, returns `{prompt, offerTools, account}`:
  - Sales number + active non-default `BotPrompt` → override body, no tools.
  - Sales number + sender is a `ShopCustomer` of a shop (last-9-digit match) →
    provider prompt for that shop, no tools.
  - Sales number + lead → base Rezzy sales prompt, tools on.
  - Tenant number → `shops.persona` if set, else category provider prompt; no tools.
- **`WhatsAppCloud`** (extend existing service): add `sendVoice(WaAccount, to, mediaId)`,
  `uploadMedia(WaAccount, bytes, mime, filename): string` (returns media id), reuse
  existing `sendText`/`downloadMedia`. Token resolution unchanged
  (`$account->token ?: default_token`).

### Support — `app/Support/Wa/`

- **`Prompts`**: the Rezzy sales system prompt (verbatim from `server.js`
  `REZZY_SYSTEM_PROMPT`) and `providerPrompt(shopName, ?category)` (verbatim from
  `lib/personas.js buildProviderPrompt`).
- **`Greetings`**: `isBareGreeting(?string): bool` — same greeting set, emoji/punct
  strip, repeated-letter collapse as `lib/greetings.js`.
- **`ConversationHistory`**: `for(WaContact $contact, int $limit = 10): array` —
  last N `wa_messages` mapped to Claude turns (`in`→user, `out`→assistant),
  stripping `🎤 `/`🔊 ` prefixes, skipping `[type message]` placeholders, and
  merging consecutive same-role turns (Claude requires alternating roles).
  Replaces Node's in-memory history; survives restarts.

### Actions — `app/Actions/Wa/`

- **`OnboardBusiness`** (ports `lib/onboard.js`): handler for the
  `create_business_account` tool. Validates name + category (same 9 categories /
  IDs as `App\Support\ServiceCategories`). Dedupe: existing shop by last-9-digit
  phone match → resend credentials message. Otherwise create the shop via the
  same internal path `POST /api/shops` uses (direct call, not HTTP), with
  `is_verified: true`, phone = sender's WhatsApp number. Returns the exact
  deterministic credentials message (Business ID, PIN, login URL) — the model
  never types credentials. Tool definition (name/description/schema) ported
  verbatim.

### Job — `app/Jobs/ProcessWaReply.php`

Constructor: `(int $waMessageId)`. Queue: `default` (database driver). The
orchestrator, porting `server.js`'s webhook POST handler step by step:

1. Load message + contact + account; bail if an outbound reply for this
   inbound already exists (idempotency on retries).
2. Reactions / stickers / emoji-only text → store-only, no reply (already
   stored by the webhook), fire web push, done.
3. Bare greeting (and no active sales override) → canned welcome
   (sales: Rezzy welcome; tenant: shop welcome), send, record outbound. No Claude.
4. Voice note (`audio`/`voice` + media) and Whisper available → transcribe
   from the already-downloaded `media_path` (fall back to `fetchMedia`);
   update the inbound message body to `🎤 {transcript}` (replaces relay-transcript).
5. Remaining non-text → polite "couldn't open that" text fallback.
6. `PersonaResolver` → prompt + tools; `ConversationHistory` → turns.
7. `ClaudeClient` reply; if tool use `create_business_account` →
   `OnboardBusiness` returns the deterministic message (always sent as text).
8. Voice in + not onboarded + TTS available → `Speech` → `uploadMedia` →
   `sendVoice`; record outbound as `🔊 {reply}` with media id/path. Any TTS
   error falls back to plain text.
9. Otherwise `sendText`. Record outbound via `WaContact::recordMessage('out', ...)`.
10. Fire `WebPush` for the inbound (title = contact name/number, body = text).

Job settings: `$tries = 1` (a failed reply must not double-send on retry; errors
are logged, the chat stays answerable manually in bizrezzy — same fail-quiet
behavior as Node), `$timeout = 120`.

### Webhook changes — `WaWebhookController`

- **`receive()`**: add `X-Hub-Signature-256` HMAC verification against the raw
  body using `WHATSAPP_APP_SECRET` (no-op when unset — same as Node). After
  `handleChange` stores a message, dispatch `ProcessWaReply` for each *newly
  created* inbound row. The existing `wa_message_id` dedupe means Meta retries
  never create a row, so never dispatch a second job.
- **Sales number storage**: the sales line gets a `wa_accounts` row with
  `shop_id = null` so `handleChange` stores its traffic uniformly (today those
  webhooks log "unknown phone_number_id"). `PersonaResolver` identifies it via
  `WHATSAPP_SALES_PHONE_NUMBER_ID`.
- **Push endpoints** (new, for bizrezzy later):
  - `GET /api/wa/push/vapid-key` → `{key}` (503 when push unconfigured)
  - `POST /api/wa/push/subscribe` / `POST /api/wa/push/unsubscribe`
  - Auth: `auth:sanctum` (master). Subscriptions stored in a new
    `wa_push_subscriptions` table (endpoint unique, p256dh, auth keys).
- **Removed after cutover** (Node-only relay surface): `/wa/relay-out`,
  `/wa/relay-transcript`, `/wa/persona`, `/wa/shop-context`, `/wa/sales-prompt`,
  `/wa/shop-by-phone` routes + their controller methods. (Their *logic* lives on
  in `PersonaResolver` / `OnboardBusiness`.) Removal is a separate final commit
  so the Node service keeps working until the webhook is repointed.

## Migrations

- `wa_push_subscriptions`: id, endpoint (unique), p256dh, auth, timestamps.

## Config / env

Additions to `config/services.php` (`whatsapp` + new `anthropic`, `openai` keys)
backed by env:

| Var | Purpose |
|---|---|
| `ANTHROPIC_API_KEY` | Claude API |
| `CLAUDE_MODEL` | default `claude-haiku-4-5` |
| `OPENAI_API_KEY` | Whisper + TTS (absent → voice features off, text-only fallback) |
| `WHATSAPP_APP_SECRET` | webhook signature verification |
| `WHATSAPP_SALES_PHONE_NUMBER_ID` | identifies the sales line |
| `VAPID_PUBLIC_KEY` / `VAPID_PRIVATE_KEY` / `VAPID_SUBJECT` | web push (absent → push off) |

Existing and unchanged: `WHATSAPP_VERIFY_TOKEN`, `GRAPH_API_VERSION`,
`WA_RELAY_SECRET` (kept while relay routes exist), `WHATSAPP_DEFAULT_TOKEN`
(also covers the sales number's sends).

Composer: add `minishlink/web-push`. No Anthropic/OpenAI SDKs — plain `Http`.

## Deploy

- Add a supervisor program on the droplet:
  `php /var/www/<app>/artisan queue:work --queue=default --sleep=1 --tries=1 --max-time=3600`
  and document it in `D:/Francis/projects/2026/Eloquent/standards/deployment.md`
  (restart worker on every redeploy: `php artisan queue:restart`).
- Cutover order:
  1. Deploy backend with new env vars; run migrations; insert sales `wa_accounts` row.
  2. Start the worker; verify with a test job.
  3. Repoint Meta webhook URL to `https://api.eloquentservice.com/api/wa/webhook`
     (verify handshake passes with existing `WHATSAPP_VERIFY_TOKEN`).
  4. Send a live test message; confirm auto-reply + bizrezzy thread shows both sides.
  5. Stop the Node process (no code changes). Rollback = repoint webhook back.
  6. After a stable period: remove the relay-only routes/methods (final commit).

## Error handling

- Fail-quiet philosophy preserved from Node: any failure in the reply pipeline
  logs and stops — never crashes the webhook, never retries a send. The inbound
  is already stored, so the shop can answer manually in bizrezzy.
- Unknown `phone_number_id` → store nothing (current behavior), no job.
- Tenant with no resolvable token → no reply (Node parity).
- TTS/transcription failures degrade to text (Node parity).

## Testing

- **Unit:** `Greetings::isBareGreeting` (greeting set, "hiii" collapse, emoji-only),
  `ConversationHistory` (role mapping, prefix stripping, placeholder skipping,
  alternation), `PersonaResolver` (override / provider / lead / tenant-persona /
  tenant-default branches), `OnboardBusiness` (create, dedupe-resend, 422 name
  clash, bad input).
- **Feature (Http::fake):** webhook POST → message stored + job dispatched once
  (and not on Meta retry); job run → Claude called with cached system block,
  Cloud API send hit, outbound `wa_message` recorded; voice path with faked
  Whisper/TTS; signature rejection with bad `X-Hub-Signature-256`.

## Out of scope

- Any edit to `whatsapp-autoreply` or bizrezzy code.
- bizrezzy push-subscribe button (separate task in that repo).
- Runtime prompt/model hot-swap (dropped by decision).
