# AI Summary voice wake word — design

**Date:** 2026-07-26
**Status:** Approved, ready for implementation plan

## Problem

On `/ai-summary` the owner must tap the mic button to hear the summary read
aloud. Hands are often busy (driving, working, mid-conversation). The owner
wants to say the business name out loud — "hey Shakaina", "Shakaina salon" —
and have the summary start speaking.

## Scope

Wake-word listening on the AI Summary page only, triggering the existing
play-summary action. Plus a Settings page to set the phrase per shop.

**Explicitly out of scope:** app-wide wake word, asking the assistant a
question by voice, wake word on any other page, server-side audio processing.

## Behaviour

- On `/ai-summary`, the browser listens continuously for the shop's wake phrase.
- A match plays the summary aloud — identical to tapping the mic button.
- Saying it again while speaking stops playback (same as tapping again).
- Recognition runs entirely in the browser's speech API. No audio is uploaded
  to our backend, and no assistant/Claude credits are spent.
- The whole feature is additive: with it off, unsupported, or blocked, the page
  behaves exactly as it does today.

## Storage and API

### Schema

Migration adds `shops.wake_phrase` — nullable string, default null.

When null, the **effective phrase falls back to the shop's own name**. Every
tenant therefore gets a working wake word with zero setup, and no shop's name
is ever baked into a default (see the multi-tenant rule: no hardcoded
identity).

### Routes

Follows the existing persona / simulation pattern in `routes/api.php`:

| Route | Middleware | Returns |
|---|---|---|
| `GET /shop/wake-word` | `auth:sanctum`, `rbac.context` | `{ phrase, effective_phrase, using_custom }` |
| `PUT /shop/wake-word` | `auth:sanctum`, `rbac.context`, `can.perm:settings.manage` | same shape |

The GET is auth-only on purpose: the AI Summary page needs the phrase, and
`summary.view` users do not necessarily hold `settings.manage`. The value is a
business name — not sensitive — but it is still resolved from the authed
shop, never from a request parameter.

`PUT` accepts `{ phrase: string|null }`. Empty string or null clears the
override and restores the shop-name fallback. Validation: max 60 characters,
trimmed; reject a phrase shorter than 3 characters after trimming (too short
to match reliably and would fire constantly).

A new `ShopWakeWordController` holds `show` and `update`. Both derive the shop
from the token.

## Settings page

New page `/settings/wake-word`, registered in `admin/src/lib/nav.ts`
`ALL_SETTINGS_OPTIONS`:

```
{ label: 'Voice wake word', sub: 'Say this to hear your summary out loud',
  to: '/settings/wake-word', icon: 'Mic', modules: BOTH, perm: 'settings.manage' }
```

Page contents:

- One text input, placeholder showing the effective phrase (the shop name)
  when nothing is saved.
- Helper text explaining that "hey" is optional and near-misses still work.
- A **Test it** button: starts listening for ~6 seconds and shows a live
  "Heard: …" line plus a match/no-match result, so the owner can confirm the
  phrase works with their accent before saving. Hidden when the browser has no
  speech recognition.
- Save button. Follows the `SimulationSettings.tsx` layout and error/notice
  conventions (`c-error-box`, mint notice block, `c-btn` / `c-btn-ghost`).

## Matching

New pure module `admin/src/lib/wakeWord.ts`, exporting:

```ts
export function normalise(text: string): string
export function matchesWakePhrase(heard: string, phrase: string): boolean
```

Algorithm:

1. Normalise both sides — lowercase, strip punctuation, collapse whitespace.
2. Strip a leading filler from the heard text: `hey`, `hi`, `hello`, `ok`,
   `okay`.
3. Slide a window over the heard words matching the phrase's word count
   (and word count ± 1, so "shakaina salon" matches a one-word phrase).
4. A window matches when its Levenshtein distance to the phrase is within a
   length-scaled tolerance: `min(floor(phrase.length / 5), 3)`. Short phrases
   (under 5 characters) therefore require an exact match — a 1-edit tolerance
   on a 3-letter phrase would fire on ordinary conversation.

This fires on *hey shakaina*, *shakaina salon*, *shakina*, *shakaina*, and
does not fire on unrelated words.

The module is pure and DOM-free, so it carries the bulk of the test coverage:
exact match, filler prefix, trailing word, single-character mishearing, casing
and punctuation, an unrelated phrase, an empty phrase, and a phrase longer
than the heard text.

## Listening

New hook `admin/src/hooks/useWakeWord.ts`:

```ts
useWakeWord({ phrase, enabled, onWake }): { supported, listening, blocked }
```

Wraps `window.SpeechRecognition ?? window.webkitSpeechRecognition` with
`continuous = true`, `interimResults = true`, `lang` from the browser.

Guards:

- **Self-trigger:** recognition is stopped while the summary is speaking and
  restarted when playback ends or is stopped. The TTS audio can never trigger
  another wake.
- **Auto-restart:** browsers end the session periodically. `onend` restarts it
  while `enabled` is true, with a short backoff, and gives up after repeated
  immediate failures rather than looping hot.
- **Unsupported browser** (Firefox, older iOS): `supported` is false, the
  on-page toggle does not render, nothing else changes.
- **Permission denied** (`onerror` with `not-allowed` / `service-not-allowed`):
  sets `blocked`, turns listening off, shows a quiet "Mic blocked" note, and
  does not retry.
- **Cleanup:** stops on unmount and when the tab is hidden
  (`visibilitychange`), so the mic is never open on a page the owner has left.
- A wake match is debounced — a second match within ~1.5s is ignored, since
  interim results repeat the same text.

## On-page control

The play card in `AiSummary.tsx` gains a small **Listen** toggle.

- Default **on**.
- Turning it off is remembered per device in
  `localStorage['wakeWord.off.<shopId>']`. A shared laptop can opt out without
  changing the shop-wide setting.
- Keyed by shop id so switching business does not inherit the other's choice.
- Only rendered when `supported` is true.
- Sub-label reflects state: *Listening for "Shakaina"* / *Not listening* /
  *Mic blocked*.

## Error handling summary

| Situation | Behaviour |
|---|---|
| No speech API | Toggle hidden, tap-to-play unchanged |
| Mic permission denied | Toggle off, "Mic blocked" note, no retry |
| Recognition errors repeatedly | Backs off, stops, leaves tap-to-play working |
| No summary loaded yet | Wake is ignored (nothing to speak) |
| Wake fires while speaking | Stops playback, same as a second tap |
| `GET /shop/wake-word` fails | Falls back to the shop name from context |

## Testing

- **`wakeWord.ts` unit tests** — the matcher cases listed above. This is where
  the correctness lives.
- **Backend feature tests** — `GET` returns the shop name when unset and the
  override when set; `PUT` saves, clears on empty, validates length; `PUT`
  without `settings.manage` is 403; a second shop's token never reads or writes
  the first shop's phrase.
- **Settings page test** — renders the effective phrase, saves, shows the
  error state.
- **AiSummary test** — the toggle renders only when supported, and a simulated
  wake call triggers playback. Speech recognition is mocked; no test opens a
  real mic.

## Known limitation

iOS Safari's speech recognition drops sessions frequently and often needs a
fresh user gesture. The auto-restart loop covers most of this, but wake-word
reliability on iPhone will be noticeably worse than on Android or desktop
Chrome. Every failure path degrades to today's tap-to-play behaviour, never to
a broken page.
