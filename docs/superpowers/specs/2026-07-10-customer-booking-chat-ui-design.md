# Customer booking page — conversational chat UI

**Date:** 2026-07-10
**Status:** Approved design, pending implementation plan
**Scope:** `admin/src/pages/PublicBooking.tsx` (`/book/:shopId`) and small shared-component + client plumbing changes. **No backend changes.**

## Goal

Reskin the public customer booking page to match the admin Ask/home page's conversational chat layout, to reduce customer confusion by reusing a familiar pattern. **The booking flow and functionality do not change** — only the presentation, plus one additive feature (a "+ New booking" action).

## Motivation

The current page is a single full-screen mic orb with no visible transcript. Francis finds it confusing and inconsistent with the admin chat UI. A chat layout also surfaces the transcript, so a misheard phone number / date is *visible* and the customer can correct it by voice — mitigating the known "no visible correction" limitation.

## What stays exactly the same (do not regress)

- **Tap-to-talk + 1.5s auto-send** (`useRecorder({ meter, onSilence, silenceMs: AUTO_STOP_MS=1500 })`); tap also sends immediately.
- **Inline TTS** reply audio (`reply_audio` base64 from `/book-assistant/voice|text`); `/tts` fallback for client-composed lines.
- **Auto-book** when all 5 fields (`service, date, start_time, customer_name, customer_phone`) are known and the model reports `ready`.
- **Phone validation** via `canonicalUaeMobile` before booking (BK00037 guard).
- **Success card** ("You're booked!", reference, "Book another") remains the terminal state.
- Server assistant, endpoints, prompts, `ConversationStore`, throttling — untouched.

## New behaviour

1. **Chat layout** replaces the orb:
   - **Header:** shop name + a top-right **"+" New booking** button (mirrors admin's new-chat "+").
   - **Thread:** message bubbles. Assistant (left): reply text + a playable voice-note. Customer (right): their transcript (voice) or typed text.
   - **Bottom bar:** text input + send button + mic button (mirrors admin `va-controls`).
2. **Voice + text input:** customer can type instead of speak. Typing calls the existing `bookAssistantText`; voice calls `bookAssistantVoice`. Both feed the same reply-handling.
3. **"+" New booking → separate thread:** rotates a persisted booking-session id; the next message opens a fresh customer conversation in the shop's Chats, and the on-screen thread clears. Complements the post-booking "Book another".

## Architecture

### Shared chat components (focused cleanup)
Extract the chat pieces currently defined *inside* `VoiceAssistant.tsx` into a shared module so both pages render identically from one source of truth:
- `AudioBubble` — WhatsApp-style voice-note player (play/pause, progress, elapsed).
- `ThinkingBubble` — rotating status words + bouncing dots.
- `renderContent(text, { linkifyRefs })` — linkifies `BK…` references. **`linkifyRefs` defaults to true for admin; PublicBooking passes `false`** (customers have no auth to open `/booking/:id`, so the reference renders as plain text).

Proposed location: `admin/src/components/chat/` (e.g. `AudioBubble.tsx`, `ThinkingBubble.tsx`, `renderContent.tsx`) or a single `admin/src/components/chat.tsx`. `VoiceAssistant.tsx` imports these instead of its local copies. No behaviour change for the admin page.

Styles: reuse the existing `va-*` classes (already in the app-wide stylesheet). Verify they are present on the public `/book` route bundle; if the orb-only `public-booking.css` is the only sheet loaded there, ensure the `va-*` rules are available (they ship in the shared CSS bundle for the single-page admin app, so this should already be the case — confirm during implementation).

### PublicBooking state (chat)
- `messages: Msg[]` where `Msg = { role: 'user' | 'assistant'; content: string; audioUrl?: string | null; autoPlay?: boolean }` — same shape as admin.
- Keep `fieldsRef`, `historyRef`, `bookedRef` and the flow refs.
- On each reply: push a user bubble (transcript / typed text) and an assistant bubble (reply text + audio). Assistant audio comes from `reply_audio` (make a blob/data URL for the `<audio>` element).

### Reply playback (open point — resolve in plan)
Two candidate paths:
- **A (unify, preferred):** play the reply through the chat `<audio>` element (like admin), deriving the `speaking` state from its `play`/`ended` events to keep gating the mic. Requires mobile audio-unlock — prime a reused `<audio>` with a silent WAV inside the mic tap (a prior version did this successfully).
- **B (keep proven):** keep the current Web Audio auto-play (already unlocked via `primeAudio` on tap, already drives `speaking`), and render the bubble's voice-note player with `autoPlay=false` purely for manual replay.

Default to A; fall back to B if unlock proves fragile on device. Either way the mic must stay gated while a reply is playing.

### "+" New booking — client-only threading
Server keys the customer thread by `device_id` via `Conversation::firstOrCreate(['shop_id','source'=>'customer','device_id'=>$deviceId])`. A new `device_id` value ⇒ a new thread. Implementation:
- New helper `admin/src/lib/bookingSession.ts`: a `getBookingSessionId()` persisted in `localStorage` (key `booking_session_id`, own UUID), and `newBookingSession()` that rotates it. Persisted so an accidental page reload continues the same booking; rotated only on "+".
- Booking API calls (`bookAssistantVoice`, `bookAssistantText`, `recordBooking`) send `X-Device-Id: <bookingSessionId>` for their requests.
- `api.ts` interceptor: change `config.headers['X-Device-Id'] = getDeviceId()` to `config.headers['X-Device-Id'] ??= getDeviceId()` so an explicitly-set header wins. (Safe: only these calls set it explicitly.)
- "+" handler: `newBookingSession()`, clear `messages`, reset `fieldsRef`/`historyRef`/`bookedRef`, leave the success card if shown.

## Data flow (per turn)

1. Customer speaks (auto-send after 1.5s / tap) **or** types + send.
2. Voice → `bookAssistantVoice(shopId, blob, fields, history)`; text → `bookAssistantText(shopId, text, fields, history)` — both with `X-Device-Id = bookingSessionId`.
3. Response `{ transcript?, reply_text, reply_audio?, fields, ready }`.
4. Push user bubble (`transcript` or typed text) + assistant bubble (`reply_text` + audio); auto-play reply (gating mic).
5. Merge fields; if `ready` && all 5 present → validate phone → `createBooking` → success card. Else await next turn.

## Testing (`PublicBooking.test.tsx`)

- Voice auto-send (via mocked `onSilence`) still books when the model reports ready (existing test, adapted to chat DOM).
- Typed message sends via `bookAssistantText` and renders a right-side user bubble + left-side assistant bubble.
- Assistant bubble shows reply text; voice-note player present.
- "+" New booking clears the thread and rotates the session id (assert the next request carries a different `X-Device-Id`, and the thread is empty).
- End/reset paths still return to a fresh state.
- Booking reference renders as plain text (no link) in a customer bubble.

Backend unchanged ⇒ `PublicBookingAssistantTest` / `TtsControllerTest` untouched.

## Out of scope

- Audio **streaming** (deferred — see `deferred-voice-streaming` memory).
- Any change to the assistant model, prompts, endpoints, or booking creation.
- A live-filling visible form for tap-correction (previously declined).

## Deployment

Frontend-only (plus the tiny `api.ts` change) ⇒ `admin/deploy-staging.ps1` to staging; promote to prod with the rest of the booking work once Francis approves.
