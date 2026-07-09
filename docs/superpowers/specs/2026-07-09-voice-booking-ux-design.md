# Voice booking screen — UX redesign

**Date:** 2026-07-09
**Scope:** `/book/:shopId` (public self-service voice booking). Presentation & controls only. No change to booking logic, phone validation, or the backend.

## Problem

The single-mic screen works functionally but is opaque (owner feedback):

1. Can't tell when it's **listening** vs **thinking** vs **speaking** — all four states share the same mint glow and differ only subtly.
2. The only status text is tiny, grey, and pinned to the bottom footer — easy to miss.
3. **No way to stop it.** In hands-free mode (Android Chrome) the orb is inert after Start — tapping it does nothing, so there's no way to interrupt speech or end the session.
4. Animation is not prominent.

## Design

### 1. Color-coded, distinct states
Drive the orb's gradient + glow + rings from a per-state CSS variable so each state reads at a glance:

| State | Hue | Animation | Status label |
|-------|-----|-----------|--------------|
| idle / start | soft mint | slow breathe | `Tap to start — then just talk` |
| listening | emerald | continuous equalizer bars + expanding halos (synthetic, always moving; also scales with `--lvl` in tap mode) | `● Listening` |
| thinking | amber | shimmer + 3 bouncing dots | `Thinking…` |
| speaking | indigo/violet | strong radiating ripples | `Speaking… · tap to stop` |

Synthetic (always-moving) animation for listening is deliberately chosen over a live mic meter: it's reliable (no second `getUserMedia` competing with Web Speech) and communicates "I'm listening" even in silence.

### 2. Prominent status + live captions (center stage, not a footer)
- A **large, color-matched status pill** below the orb (`● Listening`).
- **Captions:** two fade-in lines — `You: "<what it heard>"` and `Assistant: "<reply>"`.
  - `heard`: the Web Speech utterance (hands-free) or the Whisper `transcript` (tap mode).
  - `reply`: `reply_text` / the booked / correction message.
- New component state: `heard: string`, `reply: string`.

### 3. Stop / interrupt (the missing control)
- **Tap the orb while speaking** → `stopSpeaking()` stops the audio source; the pending `speakReply` promise resolves and the existing flow resumes (hands-free auto-listens again). Works in both modes.
- **`End` button** (small, top-right, shown only during a live session: `started || recording || speaking`) → tears down recognition/audio and returns to the Start screen (reuses `reset()` + `stopListening()`).

### 4. Structure
- Extract a presentational **`VoiceOrb`** component (`admin/src/components/VoiceOrb.tsx`): props `{ state, level, ariaLabel, disabled, onTap }`; renders the button, rings, equalizer bars, and mic icon. Keeps orchestration in `PublicBooking.tsx` (currently 363 lines).
- **Preserve existing aria-labels** the tests depend on: idle=`Speak to book`, recording=`Stop`, hands-free listening=`Listening`. The speaking-interrupt label is `Tap to interrupt` (no "stop" word, to avoid role-name collisions in tests).

## Testing
- **TDD (vitest, tap mode — jsdom has no Web Speech):**
  1. After a turn, the captions show what was heard and the assistant's reply.
  2. The `End` button returns the screen to the Start state.
- Existing booking tests must stay green (labels preserved).
- CSS/animation is verified visually (build + screenshot on staging), not unit-tested.

## Out of scope
Booking flow, phone validation, backend, and the tap-mode voice-activity auto-stop — all unchanged.
