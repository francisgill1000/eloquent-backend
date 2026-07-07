# Owner Assistant — Conversation Threads

**Date:** 2026-07-07
**Status:** Approved design, pending implementation plan
**Scope:** Admin/owner voice+text assistant (the `/ask` screen). Not the customer app assistant.

## Problem

Today the owner assistant stores every message in one flat, per-shop list
(`assistant_messages`, keyed only by `shop_id`). Tapping the mic on the Home page
always reopens that single endless thread, so:

- Different tasks (booking help vs. a business question) pile into one history.
- The history grows without bound and every request re-sends a rolling window of it.
- There is no way to keep separate conversations or come back to a past one.

We want ChatGPT-style **conversation threads**: each mic tap starts a fresh thread,
past threads are saved and reopenable, and each thread is an isolated context.

## Decisions (from brainstorming)

1. **New thread trigger:** Tapping the mic on the Home page always opens a **brand-new,
   empty thread**. Past threads are saved in a list and can be reopened.
2. **Title:** Taken from the user's **first message** in the thread (truncated). The
   owner can **rename** and **delete** threads. No extra AI call.
3. **Browsing:** A **slide-in drawer** on the chat screen (history icon) lists past
   threads, newest first, each showing title + relative date. Tap to open.
4. **Isolation:** The AI's context is **per-thread** — it only sees the current
   thread's messages. This is the core fix for unbounded-history bloat.
5. **Data model:** A dedicated **`conversations` table** (Option A) plus a
   `conversation_id` FK on `assistant_messages`.
6. **Lazy creation:** A thread row is created **only when the first message is
   successfully sent**, so opening the mic and leaving does not litter empty threads.
7. **Backfill:** Existing `assistant_messages` rows are bundled into one thread per
   shop titled **"Previous chat"** so nothing is lost.

## Data model

### New table: `conversations`

| Column       | Type                     | Notes                                   |
|--------------|--------------------------|-----------------------------------------|
| `id`         | bigint PK                |                                         |
| `shop_id`    | unsignedBigInteger, indexed | Scoping key (matches existing pattern; no FK constraint, consistent with `assistant_messages`). |
| `title`      | string                   | Derived from first user message; renamable. |
| `created_at` | timestamp                |                                         |
| `updated_at` | timestamp                | Bumped on each new message → drives "newest first" ordering. |

Index: `['shop_id', 'updated_at']` for the thread-list query (newest first per shop).

### Changed table: `assistant_messages`

Add:

- `conversation_id` — unsignedBigInteger, **nullable during migration**, then backfilled
  and indexed. Add index `['conversation_id', 'id']` (chronological messages within a
  thread) and drop reliance on the old `['shop_id', 'id']` index for context queries.

Migration steps (single migration, ordered):

1. Create `conversations`.
2. Add nullable `conversation_id` to `assistant_messages`.
3. **Backfill:** for each distinct `shop_id` present in `assistant_messages`, create one
   `conversations` row titled `"Previous chat"` (with `created_at`/`updated_at` set from
   that shop's message range), then set `conversation_id` on all of that shop's messages.
4. Add the `['conversation_id', 'id']` index.

`conversation_id` stays nullable at the DB level to keep the migration reversible and
simple; application code always writes it.

### Models

- **New `Conversation` model** (`app/Models/Conversation.php`):
  - `$fillable = ['shop_id', 'title']`
  - `messages()` hasMany `AssistantMessage`
  - `shop()` belongsTo `Shop`
  - `deleting` hook: delete child messages via `->get()->each->delete()` so each
    message's existing audio-file cleanup hook runs (no orphaned audio).
- **`AssistantMessage`**: add `conversation_id` to `$fillable`; add
  `conversation()` belongsTo `Conversation`.

## Backend

### `ConversationStore` (`app/Services/Assistant/ConversationStore.php`)

Currently per-shop. Re-scope its message operations to a **conversation**:

- `contextFor(Conversation $c, int $limit = 20)` — last N turns for that thread
  (replaces the per-shop query). This is what makes context isolated + bounded.
- `append(Conversation $c, string $role, ...)` — writes the message with
  `conversation_id = $c->id`; audio path becomes `assistant/{shop_id}/{conversation_id}/{uuid}.ext`
  (keeps files grouped and avoids collisions). Touch `$c->updated_at` on append.
- New helpers:
  - `create(Shop $shop, string $firstUserText): Conversation` — lazily create a thread,
    title = truncated first message (e.g. first ~60 chars, whitespace-collapsed).
  - `list(Shop $shop): array` — threads for the drawer, newest `updated_at` first:
    `id`, `title`, `updated_at`, message count (optional).
  - `messagesFor(Conversation $c): array` — the thread's messages shaped via `toApi`.
  - `rename(Conversation $c, string $title)`.
  - `delete(Conversation $c)` — removes the thread + its messages + audio.
- Keep `signedUrl` / `toApi` / `ext` as-is.
- The old shop-wide `clear()` is superseded by per-thread `delete()`. Keep or drop the
  "clear all" behavior per the API section below.

### `OwnerAssistantController` + routes

The auth user **is** the Shop (existing pattern: `$request->user()` is the shop).
All thread access must verify `conversation->shop_id === $request->user()->id`
(404/403 otherwise) — the multi-tenant no-leak rule.

New/updated routes under the existing `auth:sanctum + rbac.context + subscription.active`
group (`routes/api.php`, near the current `/shop/assistant/*` block):

| Method + path                                   | Purpose                                  |
|-------------------------------------------------|------------------------------------------|
| `GET /shop/assistant/conversations`             | List threads for the drawer.             |
| `POST /shop/assistant/conversations`            | (Optional) explicitly create a thread. See lazy-create note. |
| `GET /shop/assistant/conversations/{conversation}` | Messages for one thread (replaces flat `history`). |
| `PATCH /shop/assistant/conversations/{conversation}` | Rename.                              |
| `DELETE /shop/assistant/conversations/{conversation}` | Delete one thread.                   |
| `POST /shop/assistant/text`                     | Send text — now takes `conversation_id`. |
| `POST /shop/assistant/voice`                    | Send voice — now takes `conversation_id`.|

**Lazy-create flow:** `text`/`voice` accept an **optional** `conversation_id`. If absent
(a brand-new thread that hasn't been persisted yet), the controller creates the
conversation from the first user message *inside* `respond()` — but only on the same
"persist on success" path that already exists (create the thread + append the two turns
only when Claude returns a non-empty reply). The response returns the new
`conversation_id` so the client adopts it for subsequent turns. This means the explicit
`POST /conversations` route is optional — the recommended flow creates lazily on first
message. We will **not** add the explicit create route unless the plan finds it needed.

`respond()` changes:

- Signature gains the target `Conversation` (or null for a not-yet-created thread).
- Build context from `contextFor($conversation)` instead of the shop.
- On success with a null conversation: `create()` it from `$userText`, then append.
- Return `conversation_id` (+ `title` for a freshly created thread so the drawer/header
  can show it) alongside the existing `reply_text` / `reply_audio_url` / `transcript`.

`history` and `clear` (shop-wide) are replaced by the per-conversation endpoints. Decide
in the plan whether to keep a "delete all threads" affordance; not required by the UX.

## Frontend (admin app)

### API client (`admin/src/lib/assistant.ts`)

- New types: `Conversation = { id: number; title: string; updated_at: string }`.
- New functions: `listConversations()`, `getConversation(id)`, `renameConversation(id, title)`,
  `deleteConversation(id)`.
- `postText`/`postVoice` gain a `conversationId?: number` argument (omitted for a new
  thread) and return the (possibly new) `conversation_id` + `title`.
- `AssistantReply` gains `conversation_id: number` and optional `title`.

### `Home.tsx` mic

Currently `navigate('/ask')`. Change so a mic tap **always opens a new thread**:
navigate to `/ask` with **no conversation id** (e.g. `/ask` = new thread;
`/ask/:conversationId` = open existing). The floating `VoiceAssistantFab` behaves the
same as today (opens a new thread).

### `VoiceAssistant.tsx` (`/ask` and `/ask/:conversationId`)

- **State:** track `conversationId: number | null`. New thread → `null` until the first
  successful send returns an id, then adopt it (and update the route so refresh/back works).
- **On mount:**
  - Route has a `conversationId` → load that thread via `getConversation(id)`.
  - No id → start empty (show the existing empty-state prompt). Do **not** call history.
- **send/toggleMic:** pass the current `conversationId` (or omit for a new thread); on the
  response, set `conversationId` from `res.conversation_id` if we didn't have one.
- **History drawer:** replace the current single "Clear conversation" trash button in the
  header with a **history icon** that opens a slide-in drawer:
  - Drawer lists `listConversations()` results (title + relative time), newest first.
  - Tap a thread → navigate to `/ask/:id` (loads it).
  - A **"New chat"** action in the drawer/header → navigate to `/ask` (new empty thread).
  - Per-row **rename** and **delete** (delete confirms; if you delete the open thread,
    fall back to a new empty thread).
- Keep the existing `restoredCount` auto-play logic (loaded messages don't auto-play;
  new replies do) — it now applies per opened thread.

### Styling

Add drawer styles to `admin/src/styles/desktop.css` (and mobile) following the existing
glass-card / mint design language. The drawer should overlay from the side, dismiss on
backdrop tap, and be usable one-handed on mobile.

## Multi-tenant safety

Every conversation endpoint must scope by the authenticated shop and reject access to
another shop's conversation (`404`). No shop identity is ever hardcoded; titles come only
from user content. Audio paths include `shop_id` and `conversation_id`.

## Testing

Backend (`tests/Feature`, run on the droplet php8.4 — never local, never against prod DB):

- `ConversationStoreTest` — update: context/append/list/create/rename/delete scoped to a
  conversation; `updated_at` bumps; audio path shape; delete removes messages + audio.
- `AssistantConversationApiTest` — update/expand:
  - list returns only this shop's threads, newest first;
  - lazy create: first `text`/`voice` with no id creates a thread titled from the message
    and returns its id; failure (empty reply) creates **nothing**;
  - subsequent turns with the id append to the same thread and context is isolated
    (messages from another thread never appear in `contextFor`);
  - rename/delete work and are shop-scoped (cross-shop access → 404);
  - a shop cannot read/rename/delete another shop's conversation.
- **Migration/backfill test:** seed legacy `assistant_messages` (no `conversation_id`),
  run migration, assert one "Previous chat" thread per shop with all messages attached.

Frontend (`admin/src/pages/VoiceAssistant.test.tsx`, vitest):

- New thread on mount when no route id (no history call; empty state shown).
- Opening `/ask/:id` loads that thread's messages.
- Sending in a new thread adopts the returned `conversation_id`.
- Drawer lists threads, opens one, renames, deletes (including deleting the open thread).

## Rollout

Local → **staging** first (test on 64.227.153.90, isolated DB), verify the migration
backfill and the full thread flow, then promote code + migration + admin frontend to prod
once solid. Deploy the admin frontend with `admin/deploy.ps1`.

## Out of scope (YAGNI)

- AI-generated / summarized titles (chose first-message title; no extra cost).
- Auto-new-thread by inactivity timer.
- Search across threads, pinning, folders, sharing.
- Any change to the customer-app assistant or the WhatsApp chat system.
