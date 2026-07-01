# Avatar-Speaks Modal in Live Chat — Design

**Date:** 2026-06-21
**Project:** eloquent-bookings
**Status:** Approved (phase 1 — verification)

## Goal

Add a HeyGen avatar to the in-app **Live Chat** screen. A button in the chat
header opens a modal containing the avatar video. The avatar connects and then
**speaks a static hardcoded line**. This phase exists to verify, on a real shop,
that the avatar renders and talks end-to-end (token broker → SDK → video +
audio). Once verified, phase 2 swaps the static line for the actual chat reply
text.

This is intentionally separate from the existing two-way voice `AvatarCall`
(`/shop/:id/avatar`): no microphone, no voice chat — the avatar only *speaks*
text we hand it.

## Decisions (locked)

| Decision | Choice |
|----------|--------|
| Placement | Modal over the Live Chat screen (`ShopChat.tsx`) |
| Button location | Icon button in the chat header (`.c-thread-head`) |
| Token source | Reuse existing backend broker `POST /avatar/shops/:id/session` via `createAvatarSession()` |
| Speak trigger | Automatic on stream-ready |
| SDK mechanism | `LiveAvatarSession(token, { voiceChat: false })` + `session.repeat(text)` |
| Static message | `"Hi! I'm your assistant. I can help you with prices, timings and availability. How can I help today?"` |

`repeat(text)` makes the avatar speak the literal text. (`message(text)` would
route to the AI brain for a generated reply — not what we want here.)
`voiceChat: false` means no `getUserMedia` / mic prompt.

## Components

### 1. `src/lib/avatar.ts` (edit)
Lift the `tokenFromCreds(creds)` helper currently inlined in `AvatarCall.tsx`
into this shared lib so both the existing voice call and the new modal use one
implementation. Export it. Update `AvatarCall.tsx` to import it (removes the
local copy — no behavior change).

```ts
export function tokenFromCreds(creds: AvatarSession): string | undefined
```

### 2. `src/components/AvatarSpeakModal.tsx` (new)
Self-contained modal. Props:

```ts
type Props = {
  shopId: string | number;
  message?: string;   // defaults to STATIC_MESSAGE; phase 2 passes reply text
  onClose: () => void;
};
```

Lifecycle (mirrors `AvatarCall`'s proven pattern):
1. On mount: `createAvatarSession(shopId)` → `tokenFromCreds` → guard if missing.
2. `new LiveAvatarSession(token, { voiceChat: false })`.
3. `session.on(SESSION_STREAM_READY)` → `attach(videoEl)`, `play()`, set phase
   `live`, then `session.repeat(message ?? STATIC_MESSAGE)`.
4. `onClose` / unmount: `session.stop()`, null the ref.
5. `cancelled` ref guard so closing mid-connect tears down cleanly and never
   calls `setState` after unmount.

Phases: `connecting | live | error`. No `mic-denied` phase (no mic).

UI: full-screen overlay (`.c-avatar-modal`) with the `<video>` (reuse
`.c-avatar-video`), a connecting spinner/status, an error message + Close on
failure, and a Close (X) button. Chat underneath is untouched.

### 3. `src/pages/ShopChat.tsx` (edit)
- Add `const [avatarOpen, setAvatarOpen] = useState(false)`.
- Add an icon button in `.c-thread-head` (right-aligned) that sets
  `avatarOpen = true`. `Icons` has no video glyph, so add a small `Video` icon
  to `src/components/Icons.tsx` (same style as the others) and use it here.
- Render `{avatarOpen && <AvatarSpeakModal shopId={shopId} onClose={() => setAvatarOpen(false)} />}`.

### 4. `src/styles/customer.css` (edit)
Add `.c-avatar-modal` overlay styles (fixed, full-viewport, dark backdrop,
centered video, close button). Reuse existing `.c-avatar-video` /
`.c-avatar-status` rules where possible.

## Data flow

```
[Live Chat header button] --tap--> avatarOpen=true
  --> <AvatarSpeakModal shopId message=STATIC>
       --> createAvatarSession(shopId)  (POST /avatar/shops/:id/session)
       --> new LiveAvatarSession(token, {voiceChat:false}).start()
       --> SESSION_STREAM_READY --> attach video + repeat(STATIC)
  --close--> session.stop() ; avatarOpen=false
```

## Error & edge handling
- Token/session failure → `error` phase, message + Close button. Chat intact.
- Close mid-connect → `cancelled` guard prevents post-unmount state writes;
  `session.stop()` called.
- `SESSION_DISCONNECTED` after `live` → close the modal (return to chat).

## Testing
The LiveAvatar SDK can't run under jsdom, so we do **not** test the live
session. A light render test (consistent with `ShopChat.test.tsx`) verifies the
header button toggles the modal open/closed. The SDK calls are exercised
manually during Francis's verification pass.

## Phase 2 (later — not in this change)
When a new `direction: 'out'` reply lands in `ShopChat`, pass its `body` to the
modal as `message` and call `repeat()` on each new reply. The modal already
accepts `message`, so this is a small wiring change in `ShopChat`, not a
rewrite.

## Out of scope
- Two-way voice (already exists as `AvatarCall`).
- Real AI-driven avatar replies (that is phase 2).
- Backend changes — the broker endpoint is assumed working.
