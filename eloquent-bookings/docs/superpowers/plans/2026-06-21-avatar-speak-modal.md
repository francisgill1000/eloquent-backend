# Avatar-Speaks Modal in Live Chat — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add a header button in the Live Chat screen that opens a modal where a HeyGen avatar speaks a static line, to verify the avatar renders and talks end-to-end.

**Architecture:** A self-contained `AvatarSpeakModal` component reuses the existing backend session-token broker (`createAvatarSession`) and the HeyGen `LiveAvatarSession` SDK in non-voice mode (`voiceChat: false`). On stream-ready it calls `session.repeat(text)` to speak a static message. `ShopChat` gains a header button and modal toggle state. A shared `tokenFromCreds` helper is lifted from `AvatarCall` into `lib/avatar.ts` so both call sites share one implementation.

**Tech Stack:** React 18, TypeScript, Vite, Vitest + Testing Library, `@heygen/liveavatar-web-sdk` ^0.0.18, react-router-dom v6.

## Global Constraints

- Package manager: npm. Test runner: `vitest` (`npm test` = `vitest run`).
- Path alias `@/` maps to `src/` (used throughout; e.g. `@/lib/api`).
- No microphone in this feature: sessions use `voiceChat: false` (no `getUserMedia`).
- The avatar must SPEAK literal text via `session.repeat(text)` — NOT `message()` (which routes to the AI brain).
- Static message (verbatim): `Hi! I'm your assistant. I can help you with prices, timings and availability. How can I help today?`
- The HeyGen SDK cannot run under jsdom — tests must never let `new LiveAvatarSession` construct. Mock `createAvatarSession` with a pending promise so the modal stays in the `connecting` phase.
- Follow existing code style: 2-space indent, single quotes, no semicolon-free style (semicolons used), functional components.

---

### Task 1: Share `tokenFromCreds` in `lib/avatar.ts`

Lift the token-extraction helper out of `AvatarCall.tsx` into the shared lib so the new modal and the existing call use one copy.

**Files:**
- Modify: `src/lib/avatar.ts`
- Modify: `src/pages/AvatarCall.tsx:13-18` (remove local helper, import shared)
- Test: `src/lib/avatar.test.ts` (create)

**Interfaces:**
- Consumes: `AvatarSession` type (already exported from `src/lib/avatar.ts`).
- Produces: `export function tokenFromCreds(creds: AvatarSession): string | undefined` — returns the first present of `session_token`, `token`, `access_token`, `livekit_client_token`, `session_id` when it is a string, else `undefined`.

- [ ] **Step 1: Write the failing test**

Create `src/lib/avatar.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { tokenFromCreds } from './avatar';

describe('tokenFromCreds', () => {
  it('prefers session_token', () => {
    expect(tokenFromCreds({ session_token: 'a', token: 'b' })).toBe('a');
  });

  it('falls back through the known field names', () => {
    expect(tokenFromCreds({ token: 'b' })).toBe('b');
    expect(tokenFromCreds({ access_token: 'c' })).toBe('c');
    expect(tokenFromCreds({ livekit_client_token: 'd' })).toBe('d');
    expect(tokenFromCreds({ session_id: 'e' })).toBe('e');
  });

  it('returns undefined when no string token is present', () => {
    expect(tokenFromCreds({})).toBeUndefined();
    expect(tokenFromCreds({ session_token: 123 as unknown as string })).toBeUndefined();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/lib/avatar.test.ts`
Expected: FAIL — `tokenFromCreds` is not exported from `./avatar`.

- [ ] **Step 3: Add the helper to `src/lib/avatar.ts`**

Append to `src/lib/avatar.ts` (after the existing `createAvatarSession`):

```ts
/**
 * Pull the SDK session-access token out of whatever the backend broker returns.
 * Field name is reconciled during the LiveAvatar end-to-end pass; we try the
 * common names so a contract tweak there doesn't require a frontend change.
 */
export function tokenFromCreds(creds: AvatarSession): string | undefined {
  const c = creds as Record<string, unknown>;
  const candidate =
    c.session_token ?? c.token ?? c.access_token ?? c.livekit_client_token ?? c.session_id;
  return typeof candidate === 'string' ? candidate : undefined;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `npx vitest run src/lib/avatar.test.ts`
Expected: PASS (3 tests).

- [ ] **Step 5: Refactor `AvatarCall.tsx` to use the shared helper**

In `src/pages/AvatarCall.tsx`:

1. Delete the local `tokenFromCreds` function (lines ~8-18, the JSDoc block plus the function).
2. Update the import on line 4 to also pull in the helper:

```ts
import { createAvatarSession, tokenFromCreds, type AvatarSession } from '../lib/avatar';
```

3. If `AvatarSession` is no longer otherwise referenced in the file after removing the helper, keep the import anyway only if still used; otherwise drop the unused `type AvatarSession` from the import to satisfy `tsc`. (Check: after deletion, `AvatarSession` is only used inside the deleted helper, so remove it → import becomes `import { createAvatarSession, tokenFromCreds } from '../lib/avatar';`.)

- [ ] **Step 6: Verify build + existing tests pass**

Run: `npx tsc -b` then `npm test`
Expected: tsc clean; all existing tests still pass.

- [ ] **Step 7: Commit**

```bash
git add src/lib/avatar.ts src/lib/avatar.test.ts src/pages/AvatarCall.tsx
git commit -m "refactor: share tokenFromCreds helper in lib/avatar"
```

---

### Task 2: `AvatarSpeakModal` component + `Video` icon + modal styles

Build the modal that connects the avatar and speaks the static line. No microphone.

**Files:**
- Create: `src/components/AvatarSpeakModal.tsx`
- Modify: `src/components/Icons.tsx` (add `Video` icon)
- Modify: `src/styles/customer.css` (add `.c-avatar-modal*` rules)

**Interfaces:**
- Consumes: `createAvatarSession(shopId)` and `tokenFromCreds(creds)` from `@/lib/avatar`; `LiveAvatarSession`, `SessionEvent` from `@heygen/liveavatar-web-sdk`; `Icons` from `@/components/Icons`.
- Produces:
  - `Icons.Video: ({ size }: { size?: number }) => JSX.Element`
  - `export const AVATAR_STATIC_MESSAGE: string` (the verbatim static line)
  - `export default function AvatarSpeakModal(props: { shopId: string | number; message?: string; onClose: () => void }): JSX.Element`

- [ ] **Step 1: Add the `Video` icon to `Icons.tsx`**

In `src/components/Icons.tsx`, add this entry inside the `Icons` object (place it near `Mic`):

```tsx
  Video: ({ size = 22 }: P) => (
    <svg width={size} height={size} viewBox="0 0 24 24" {...base}><rect x="3" y="6" width="13" height="12" rx="2" /><path d="M16 10l5-3v10l-5-3" /></svg>
  ),
```

- [ ] **Step 2: Create the modal component**

Create `src/components/AvatarSpeakModal.tsx`:

```tsx
import { useEffect, useRef, useState } from 'react';
import { LiveAvatarSession, SessionEvent } from '@heygen/liveavatar-web-sdk';
import { createAvatarSession, tokenFromCreds } from '@/lib/avatar';
import { Icons } from '@/components/Icons';

export const AVATAR_STATIC_MESSAGE =
  "Hi! I'm your assistant. I can help you with prices, timings and availability. How can I help today?";

type Phase = 'connecting' | 'live' | 'error';

type Props = {
  shopId: string | number;
  message?: string;
  onClose: () => void;
};

/**
 * Avatar that SPEAKS a line of text (no microphone). Verification phase uses a
 * static message; phase 2 passes the real chat reply via `message`. Uses the
 * SDK's repeat() — which speaks literal text — not message(), which would route
 * to the AI brain.
 */
export default function AvatarSpeakModal({ shopId, message, onClose }: Props) {
  const videoRef = useRef<HTMLVideoElement>(null);
  const sessionRef = useRef<LiveAvatarSession | null>(null);
  const [phase, setPhase] = useState<Phase>('connecting');
  const [error, setError] = useState('');

  useEffect(() => {
    let cancelled = false;
    const line = message ?? AVATAR_STATIC_MESSAGE;

    async function start() {
      try {
        const creds = await createAvatarSession(shopId);
        if (cancelled) return;

        const token = tokenFromCreds(creds);
        if (!token) throw new Error('No session token returned.');

        // No mic: voiceChat:false, so the SDK won't request getUserMedia.
        const session = new LiveAvatarSession(token, { voiceChat: false });
        sessionRef.current = session;

        // The video track only exists once the stream is ready — attach then,
        // not before, or the <video> stays black. Speak once we're live.
        session.on(SessionEvent.SESSION_STREAM_READY, () => {
          if (cancelled) return;
          if (videoRef.current) {
            session.attach(videoRef.current);
            void videoRef.current.play().catch(() => {});
          }
          setPhase('live');
          session.repeat(line);
        });

        await session.start();
      } catch (e) {
        if (!cancelled) {
          setError(e instanceof Error ? e.message : 'Could not start the assistant.');
          setPhase('error');
        }
      }
    }

    void start();
    return () => {
      cancelled = true;
      void sessionRef.current?.stop();
      sessionRef.current = null;
    };
  }, [shopId, message]);

  return (
    <div className="c-avatar-modal" role="dialog" aria-label="Video assistant">
      <video ref={videoRef} className="c-avatar-video" autoPlay playsInline />
      {phase === 'connecting' && <div className="c-avatar-status">Connecting…</div>}
      {phase === 'error' && <div className="c-avatar-status">{error || 'Something went wrong.'}</div>}
      <button className="c-avatar-close" aria-label="Close" onClick={onClose}>Close</button>
    </div>
  );
}
```

Note: the close button's visible text and accessible name are both `Close` — the Task 3 test queries the button by name `/close/i`. Also remove the now-unused `Icons` import if you did not use it elsewhere in the file (it is imported above but only this button would use it — since the button is now plain text, delete the `import { Icons }` line to keep `tsc` clean).

- [ ] **Step 3: Add modal styles**

In `src/styles/customer.css`, after the existing `.c-avatar-end` rule (around line 352), add:

```css
.c-avatar-modal{position:fixed;inset:0;z-index:50;background:#000;display:flex;align-items:center;justify-content:center}
.c-avatar-close{position:absolute;bottom:28px;left:50%;transform:translateX(-50%);height:48px;padding:0 28px;border-radius:999px;border:none;background:rgba(255,255,255,.14);color:#fff;font-weight:700;font-size:14px;cursor:pointer}
```

(`.c-avatar-video` and `.c-avatar-status` already exist and are reused.)

- [ ] **Step 4: Verify build**

Run: `npx tsc -b`
Expected: clean (no type errors). The component isn't rendered anywhere yet — that's Task 3.

- [ ] **Step 5: Commit**

```bash
git add src/components/AvatarSpeakModal.tsx src/components/Icons.tsx src/styles/customer.css
git commit -m "feat: add AvatarSpeakModal (speaks a static line, no mic)"
```

---

### Task 3: Wire the avatar button into `ShopChat`

Add a header button that opens the modal, with a render test that proves it opens and closes without constructing the SDK.

**Files:**
- Modify: `src/pages/ShopChat.tsx`
- Modify: `src/pages/ShopChat.test.tsx`

**Interfaces:**
- Consumes: `AvatarSpeakModal` (default export) from `@/components/AvatarSpeakModal`; `Icons.Video`.
- Produces: no new exports (page-internal state only).

- [ ] **Step 1: Write the failing test**

Add these two tests inside the `describe('ShopChat', ...)` block in `src/pages/ShopChat.test.tsx`. Add the avatar-lib import at the top of the file with the other imports:

```ts
import * as avatarLib from '@/lib/avatar';
```

Tests:

```ts
  it('opens the avatar modal from the header button', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([]);
    // Keep the session pending so the SDK never constructs in jsdom.
    vi.spyOn(avatarLib, 'createAvatarSession').mockReturnValue(new Promise(() => {}));

    setup();
    await screen.findByText(/say hi/i);
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /video assistant/i }));

    expect(await screen.findByText(/connecting/i)).toBeInTheDocument();
  });

  it('closes the avatar modal', async () => {
    vi.spyOn(chatLib, 'getChatMessages').mockResolvedValue([]);
    vi.spyOn(avatarLib, 'createAvatarSession').mockReturnValue(new Promise(() => {}));

    setup();
    await screen.findByText(/say hi/i);
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /video assistant/i }));
    await screen.findByText(/connecting/i);
    await user.click(screen.getByRole('button', { name: /close/i }));

    expect(screen.queryByText(/connecting/i)).toBeNull();
  });
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `npx vitest run src/pages/ShopChat.test.tsx`
Expected: FAIL — no button named "Video assistant" exists yet.

- [ ] **Step 3: Add the import + state + button + modal to `ShopChat.tsx`**

1. Add imports near the top of `src/pages/ShopChat.tsx`:

```ts
import AvatarSpeakModal from '@/components/AvatarSpeakModal';
```

(`Icons` is already imported.)

2. Add state alongside the other `useState` hooks (near line 45):

```ts
  const [avatarOpen, setAvatarOpen] = useState(false);
```

3. In the `.c-thread-head` block, add the button after the `.c-thread-head-text` div (after the closing `</div>` of head-text, before `.c-thread-head` closes — around line 202):

```tsx
        <button
          className="c-icon-btn"
          style={{ marginLeft: 'auto' }}
          aria-label="Video assistant"
          onClick={() => setAvatarOpen(true)}
        >
          <Icons.Video size={18} />
        </button>
```

4. Render the modal — add just before the final closing `</div>` of the `.m-screen` wrapper (end of the component's returned JSX, around line 267):

```tsx
      {avatarOpen && (
        <AvatarSpeakModal shopId={shopId} onClose={() => setAvatarOpen(false)} />
      )}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `npx vitest run src/pages/ShopChat.test.tsx`
Expected: PASS — including the two new tests and all pre-existing ShopChat tests.

- [ ] **Step 5: Full verification**

Run: `npx tsc -b` then `npm test`
Expected: tsc clean; entire suite passes.

- [ ] **Step 6: Commit**

```bash
git add src/pages/ShopChat.tsx src/pages/ShopChat.test.tsx
git commit -m "feat: open avatar-speaks modal from Live Chat header"
```

---

## Manual verification (Francis)

After Task 3, run `npm run dev`, open a shop → **Live Chat** → tap the video icon in the header. Expected: modal opens, shows "Connecting…", then the avatar video appears and speaks the static line. Tap **Close** to return to the chat. (Requires the backend `POST /avatar/shops/:id/session` to return a real HeyGen session token.)

## Phase 2 (later — not in this plan)

Pass the latest `direction: 'out'` reply `body` into `AvatarSpeakModal` as `message`, and call `repeat()` on each new reply. The modal already accepts `message`, so this is a wiring change in `ShopChat`, not a rewrite.

## Self-Review notes

- **Spec coverage:** placement (Task 3 modal in ShopChat), header button (Task 3), token reuse (Tasks 1–2 via `createAvatarSession`/`tokenFromCreds`), auto-speak on stream-ready (Task 2), `voiceChat:false` + `repeat()` (Task 2), shared `tokenFromCreds` (Task 1), static message constant (Task 2), error/connecting phases (Task 2), modal toggle test (Task 3). All covered.
- **Placeholder scan:** the only "placeholder" noted is the throwaway `Icons.Chevron size={0}` in the first draft of the close button — Step 2 explicitly replaces it with plain `Close` text. No TODOs remain.
- **Type consistency:** `tokenFromCreds(creds: AvatarSession): string | undefined`, `createAvatarSession(shopId: string | number)`, `AvatarSpeakModal({ shopId, message?, onClose })`, `Icons.Video({ size? })` — names/types consistent across tasks.
