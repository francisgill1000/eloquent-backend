# Server-side assistant conversations

**Date:** 2026-07-02
**Status:** Approved for planning

## Problem

The owner voice/text assistant is stateless on the server. Conversation
history lives only in the browser's `localStorage`, keyed per shop. This means:

- History does not sync across devices (log in on another phone → empty).
- History is not reviewable or recoverable.
- All conversation state depends on one device's storage.

The owner has asked for the conversation to be stored server-side, including
audio, so it follows the shop across devices.

## Goals

- Persist each shop's assistant conversation on the server (source of truth).
- Persist audio for voice turns: the owner's voice note **and** the spoken reply.
- Conversation syncs across devices for the same shop, isolated from other shops.
- The Clear button permanently deletes the shop's conversation (rows + audio).

## Non-goals (YAGNI)

- Multiple threads/sessions per shop — one rolling conversation only.
- Migrating existing `localStorage` conversations — start fresh; old local data
  is ignored.
- Auto-trimming/retention of old audio — keep everything until the owner clears.
- Spoken audio for typed questions — typed → text reply only (unchanged).

## Data model

One new table. The shop *is* the conversation, so no separate conversation table.

### `assistant_messages`

| column | type | notes |
|---|---|---|
| `id` | bigint pk | |
| `shop_id` | bigint, indexed | conversation key; scoped per shop |
| `role` | string(10) | `user` \| `assistant` |
| `content` | text | transcript (user) / reply text (assistant); may be empty for a voice turn whose transcription failed — but such turns are not persisted (see below) |
| `audio_path` | string, nullable | path on the private disk, `assistant/{shop_id}/{uuid}.{ext}` |
| `audio_mime` | string(40), nullable | e.g. `audio/webm`, `audio/ogg` |
| `created_at`, `updated_at` | timestamps | ordering |

Index: `(shop_id, id)` for ordered retrieval.

Model: `App\Models\AssistantMessage` with `shop()` belongsTo. A `deleting`
model event (or explicit deletion in the clear path) removes the audio file.

## Backend

The server becomes the source of truth. The client no longer sends `history`;
the server loads it from the DB. All routes are shop-scoped via the Sanctum
auth token (`$request->user()` is the `Shop`).

### Endpoints

- `GET /shop/assistant/history`
  Returns the shop's messages in ascending order:
  `[{ id, role, content, audio_url|null }]`. `audio_url` is a **temporary
  signed URL** (24h) to the audio endpoint, generated only when `audio_path`
  is set.

- `POST /shop/assistant/text` (changed)
  Validates `text`. Loads prior history from DB (last ~20 turns for the Claude
  call). Runs the tool loop. Persists the user turn (text, no audio) and the
  assistant turn (reply text, no audio). Returns
  `{ reply_text, reply_audio_url: null, message: {id, role, content, audio_url} }`
  for the new turns. No longer accepts/echoes `history`.

- `POST /shop/assistant/voice` (changed)
  Validates `audio`. Transcribes. If transcription fails, returns the graceful
  "didn't catch that" message and persists **nothing** (no empty-content turn).
  On success: stores the uploaded voice file to disk, persists the user turn
  (transcript + audio), runs the tool loop with DB history, synthesizes the
  spoken reply, stores the reply audio, persists the assistant turn. Returns the
  two saved turns with signed `audio_url`s and `transcript`.

- `DELETE /shop/assistant/history`
  Deletes the shop's `assistant_messages` rows and their audio files. Returns
  204/200.

- `GET /shop/assistant/audio/{message}`
  Streams the audio file from the private disk. Protected by the `signed`
  middleware (Laravel signed URLs) — no auth header needed so an `<audio>`
  element can load it, and no shop can forge another shop's URL. Returns 404 if
  the message has no audio; 403 on invalid/expired signature (framework default).

### History cap for Claude

Store all turns; pass only the last ~20 messages to the model to bound token
cost. Mirrors the existing WA `WaConversationHistory` capping. The
empty-content filter in `parseHistory` is retained as defense-in-depth even
though the client no longer sends history.

### Audio storage

- Disk: `local` (private, `storage/app/private`). Not the public disk.
- Path: `assistant/{shop_id}/{uuid}.{ext}` where ext derives from the mime.
- The owner's voice note is stored as uploaded (webm/ogg/mp4). The spoken reply
  is stored as the OGG bytes returned by TTS.

## Frontend (`admin/src/pages/VoiceAssistant.tsx`)

- Drop `localStorage` for the conversation entirely (removes the cross-shop
  leak class — server scopes by token).
- On open: `GET /shop/assistant/history`, show a loading state, render returned
  turns. Assistant audio turns use the signed `audio_url` in an `<audio>`.
- Sending text/voice: append the returned saved turn(s). Do not send `history`.
- Clear button: `DELETE /shop/assistant/history`, then empty local state.
- Live voice playback: the owner's own just-recorded note can still use a local
  blob URL for instant playback; on reload it comes from the server signed URL.

New `admin/src/lib/assistant.ts` functions: `getHistory()`, `clearHistory()`,
plus updated `postText`/`postVoice` return types (include `message`/turns).

## Error handling

- Claude failure → graceful "couldn't work that out" reply, still persisted as
  the assistant turn (so the conversation stays coherent) OR not persisted —
  **decision: do not persist failed/fallback replies**, so a retry is clean and
  history isn't polluted with error text. The user turn that triggered it *is*
  persisted (it was a real question). (Revisit if this feels odd in testing.)
- Transcription failure → nothing persisted; graceful message returned.
- Audio write failure → persist the turn without audio (text still saved); log.
- Storage/disk unavailable on read → history returns text turns with
  `audio_url: null`.

## Testing

Backend feature tests:
- text turn persists user + assistant rows; history returns them in order.
- voice turn persists both rows with audio; audio files exist on the fake disk.
- `GET /history` returns signed audio URLs; the audio endpoint streams bytes for
  a valid signature and rejects an invalid/other-shop signature.
- `DELETE /history` removes rows and audio files.
- transcription failure persists nothing.
- history is scoped: shop B never sees shop A's messages.
- Claude call receives at most the capped number of turns.

Frontend tests (`VoiceAssistant.test.tsx`):
- on mount, fetches and renders server history (mock `getHistory`).
- sending appends the returned turn.
- Clear calls `clearHistory` and empties the view.
- no `localStorage` dependence remains.

## Rollout

- Migration adds the table; deploy backend (`/var/www/eloquent-backend`) with
  `php artisan migrate --force`.
- Build + deploy admin frontend (`/var/www/admin`).
- Existing local conversations are simply no longer read; no migration.
