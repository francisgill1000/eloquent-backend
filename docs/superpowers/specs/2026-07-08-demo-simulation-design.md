# Demo Simulation — Design

**Date:** 2026-07-08
**Status:** Approved for planning

## Problem

Francis records marketing videos of the Business Lens voice assistant by talking to it
live. His own voice recorded live feels unprofessional and inconsistent between takes,
and a live take uses his real phone number. He wants a repeatable way to record a clean,
professional booking conversation.

## Goal

A reusable, **per-shop, editable, dry-run** simulation that replays a scripted voice
booking conversation on the real Ask chat screen — as voice notes on both sides with
professional female voices (not Francis's) — and ends by opening a preview of the
resulting booking. It must be recordable identically every take.

## Non-goals / Guarantees

The simulation MUST NOT:

- Create a `Booking` row, `ShopCustomer`, invoice, or any other persisted record.
- Fire reminders, reviews, notifications, or WhatsApp messages.
- Use Francis's real phone number (script ships a fake number).
- Invoke the real AI assistant / booking endpoints. It is fully client-side playback
  plus text-to-speech only.

The ONLY thing persisted is the **script configuration** itself (per shop).

## User flow

1. Owner opens **Settings → Demo simulation** (`/settings/simulation`).
2. On that page they edit the script (messages, booking details, voices, pacing) and
   **Save**. A sensible default is generated from the shop's real services & staff the
   first time.
3. They press **Play**, which opens the real Ask chat screen in sim mode (`/ask?sim=1`).
4. The chat auto-plays the script turn-by-turn: owner voice-note → assistant "Thinking…"
   bubble → assistant voice-note reply → next turn. Same bubbles/animation/audio player
   as the live product.
5. After the final assistant reply, it navigates to a **booking preview** page that
   renders the booking-detail layout from the script data (nothing fetched or saved).
6. Francis screen-records steps 4–5.

A deep link `/ask?sim=1` also starts a take directly (bookmark for clean recording),
provided a saved script exists for the shop.

## Voices

OpenAI TTS (the app already uses it via `TtsController`). Both female, distinct:

- **Owner / human side:** `shimmer` (female, neutral).
- **AI assistant side:** `nova` (female, warm — the voice the product already uses).

Both are editable in the script config (whitelist: `nova`, `shimmer`, `coral`, `sage`,
`alloy`).

## Components

### Backend

1. **`TtsController::speak` — add optional `voice` param.**
   - Accept `voice` from the request; validate against the whitelist above; fall back to
     the configured default when absent/invalid.
   - Cache key already includes the voice, so each `(voice, text)` pair is billed once
     then served from cache — re-takes and replays are instant and free.

2. **`ShopSimulationController` — `GET` / `PUT /shop/simulation`.**
   - Stores the script as JSON on the shop (new nullable `simulation_script` json column,
     additive migration). Mirrors the persona storage pattern.
   - `GET` returns the saved script, or a default generated from the shop's real services
     & staff when none is saved (multi-tenant clean — no hardcoded shop identity).
   - `PUT` validates and saves the script JSON.
   - Scoped to the authenticated shop; no cross-shop access.

### Script shape (JSON)

```json
{
  "turns": [
    { "who": "owner",     "text": "Book Sarah in for a haircut tomorrow at 9:30." },
    { "who": "assistant", "text": "Done — Sarah's booked for a Hair Cut tomorrow at 9:30am with Aisha. Want me to send her a confirmation?" }
  ],
  "booking": {
    "customer_name": "Sarah",
    "customer_phone": "055 010 2030",
    "service": "Hair Cut",
    "price": "150.00",
    "date": "<tomorrow>",
    "start_time": "09:30",
    "end_time": "10:00",
    "staff_name": "Aisha"
  },
  "voices": { "owner": "shimmer", "assistant": "nova" },
  "thinking_ms": 1400
}
```

### Frontend

1. **Settings card** — add `{ label: 'Demo simulation', sub: 'Record a scripted voice
   booking — no real booking made', to: '/settings/simulation', icon: ..., modules:
   ['bookings'] }` to `Settings.tsx`.

2. **Simulation editor page** (`/settings/simulation`):
   - Loads the script via `GET /shop/simulation`.
   - Edit turns (add/remove/reorder, set who + text), booking fields, voice pickers,
     thinking-pause slider.
   - **Save** (`PUT /shop/simulation`) and **Play** (navigate `/ask?sim=1`).

3. **Ask screen sim mode** (`VoiceAssistant.tsx`, `?sim=1`):
   - On mount with `sim=1`, load the saved script and run the player instead of the
     normal interactive chat. Controls are hidden/disabled during playback.
   - Player loop per turn: render the bubble, request TTS audio for the turn's text in the
     turn's voice, play it, show the "Thinking…" bubble before assistant turns, pause
     `thinking_ms`, advance. Reuses existing `AudioBubble` / `ThinkingBubble`.
   - After the last turn, `navigate('/booking/preview', { state: { booking } })`.

4. **Booking preview** (`/booking/preview`):
   - Renders the existing booking-detail layout (`BookingAction`) from `location.state`
     instead of fetching by id. If `BookingAction` is reused, it takes a preview branch
     that skips the API load and any mutating actions; otherwise a thin preview wrapper
     reuses its presentational parts. No persistence, no status changes.

## Data flow

`Settings/Simulation editor` → `PUT /shop/simulation` (save) → `/ask?sim=1` reads
`GET /shop/simulation` → per turn `POST /tts {text, voice}` (cached) → play bubbles →
`/booking/preview` renders from in-memory booking. No booking-write endpoint is touched.

## Testing

- **Backend:** `TtsController` honours whitelisted `voice`, rejects/ignores invalid,
  caches per voice+text. `ShopSimulationController` GET default vs saved, PUT validation,
  per-shop scoping. Migration is additive/reversible.
- **Frontend:** editor loads/saves script; sim-mode player advances turns and requests
  TTS with the right voice; playback ends by navigating to `/booking/preview`; preview
  renders from state without an API call; normal (non-sim) Ask behaviour unchanged.
- **Guarantee test:** running the simulation issues no booking-create / customer-create
  requests (assert the mutating endpoints are never called).

## Open questions

None blocking. Voice choices and default script are editable at runtime.
