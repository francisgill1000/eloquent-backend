# Customer Booking Chat UI Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reskin the public customer booking page (`/book/:shopId`) to the admin Ask chat layout — message bubbles with voice-note players and a text+mic bar — while keeping the voice booking flow identical, and add a "+" New booking action that starts a separate thread.

**Architecture:** Extract the admin chat components (`AudioBubble`, `ThinkingBubble`, `renderContent`) into a shared `admin/src/components/chat.tsx` used by both pages. Rewrite `PublicBooking.tsx`'s render to the chat layout, keeping all existing flow logic (tap-to-talk, 1.5s auto-send, inline TTS auto-play, auto-book, success card). "Separate thread" is client-only: a rotating booking-session id sent as `X-Device-Id`, with a one-line interceptor change so an explicit header wins.

**Tech Stack:** React 18 + TypeScript, Vite, Vitest + Testing Library, existing `va-*` CSS in `admin/src/styles/mobile.css`.

## Global Constraints

- No backend changes. `PublicBookingAssistantController`, `TtsController`, `ConversationStore`, prompts, endpoints, and migrations are untouched.
- Do not regress the booking flow: tap-to-talk + `AUTO_STOP_MS = 1500` auto-send; inline `reply_audio` auto-play with `/tts` fallback; auto-book when `ready` and all 5 fields (`service, date, start_time, customer_name, customer_phone`) known; phone guarded by `canonicalUaeMobile` before booking; "You're booked!" success card is the terminal state.
- Customer bubbles must render `BK…` references as **plain text, not links** (customers have no auth to `/booking/:id`).
- Frontend deploy to staging only via `admin/deploy-staging.ps1`; prod promotion is a separate, later, human-approved step.
- Run frontend tests locally (`npx vitest run` in `admin/`). Do not run PHP tests for this work (no backend change).

---

### Task 1: Extract shared chat components

Move `AudioBubble`, `ThinkingBubble`, and `renderContent` out of `VoiceAssistant.tsx` into a shared module so both pages render identical chat UI from one source. Add a `linkifyRefs` option to `renderContent`.

**Files:**
- Create: `admin/src/components/chat.tsx`
- Create: `admin/src/components/chat.test.tsx`
- Modify: `admin/src/pages/VoiceAssistant.tsx` (remove local copies, import from `@/components/chat`)

**Interfaces:**
- Produces:
  - `renderContent(text: string, opts?: { linkifyRefs?: boolean }): ReactNode` — splits on `BK\d{4,}`; when `linkifyRefs !== false` (default) wraps refs in `<Link to={/booking/:id}>`, otherwise renders them as plain text.
  - `ThinkingBubble` — `() => JSX.Element`.
  - `AudioBubble` — `({ src, autoPlay?, onEnded? }: { src: string; autoPlay?: boolean; onEnded?: () => void }) => JSX.Element`.

- [ ] **Step 1: Write the failing test**

Create `admin/src/components/chat.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { renderContent } from './chat';

describe('renderContent', () => {
  it('links BK references by default', () => {
    render(<MemoryRouter>{renderContent('See BK00042 soon')}</MemoryRouter>);
    const link = screen.getByRole('link', { name: 'BK00042' });
    expect(link).toHaveAttribute('href', '/booking/42');
  });

  it('renders BK references as plain text when linkifyRefs is false', () => {
    render(<MemoryRouter>{renderContent('See BK00042 soon', { linkifyRefs: false })}</MemoryRouter>);
    expect(screen.queryByRole('link')).toBeNull();
    expect(screen.getByText(/BK00042/)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npx vitest run src/components/chat.test.tsx`
Expected: FAIL — cannot resolve `./chat`.

- [ ] **Step 3: Create the shared module**

Create `admin/src/components/chat.tsx` (moved verbatim from `VoiceAssistant.tsx`, with the `linkifyRefs` option added to `renderContent`):

```tsx
import { useEffect, useRef, useState, type ReactNode } from 'react';
import { Link } from 'react-router-dom';

// Turn any booking reference (BK00042) in a message into a link to that
// booking's detail page — the reference's digits are the booking id. Pass
// { linkifyRefs: false } on public/customer surfaces where there's no auth to
// open the booking page; the reference then renders as plain text.
export function renderContent(text: string, opts?: { linkifyRefs?: boolean }): ReactNode {
  const linkify = opts?.linkifyRefs !== false;
  return text.split(/\b(BK\d{4,})\b/g).map((part, i) =>
    /^BK\d{4,}$/.test(part)
      ? (linkify
          ? <Link key={i} className="va-ref" to={`/booking/${parseInt(part.slice(2), 10)}`}>{part}</Link>
          : <span key={i}>{part}</span>)
      : part,
  );
}

// Rotating status words shown while the assistant is working.
const THINKING_WORDS = [
  'Thinking',
  'Crunching the numbers',
  'Checking your books',
  'Looking into it',
  'Consulting your data',
  'Working it out',
  'Almost there',
];

export function ThinkingBubble() {
  const [i, setI] = useState(0);
  useEffect(() => {
    const id = setInterval(() => setI((n) => (n + 1) % THINKING_WORDS.length), 1500);
    return () => clearInterval(id);
  }, []);
  return (
    <div className="va-bubble va-ai va-thinking">
      <span key={i} className="va-thinking-word">{THINKING_WORDS[i]}</span>
      <span className="va-dots" aria-hidden="true"><i /><i /><i /></span>
    </div>
  );
}

function fmtTime(s: number): string {
  if (!isFinite(s) || s < 0) return '0:00';
  const m = Math.floor(s / 60);
  const sec = Math.floor(s % 60);
  return `${m}:${sec.toString().padStart(2, '0')}`;
}

/**
 * A WhatsApp-style voice-note player for one message: play/pause, a progress
 * track, and elapsed time. Auto-plays once on mount when autoPlay is set.
 */
export function AudioBubble({ src, autoPlay = false, onEnded }: { src: string; autoPlay?: boolean; onEnded?: () => void }) {
  const ref = useRef<HTMLAudioElement>(null);
  const [playing, setPlaying] = useState(false);
  const [progress, setProgress] = useState(0);
  const [elapsed, setElapsed] = useState(0);
  const [duration, setDuration] = useState(0);

  useEffect(() => {
    if (autoPlay) ref.current?.play().catch(() => undefined);
  }, [autoPlay]);

  const toggle = () => {
    const a = ref.current;
    if (!a) return;
    if (a.paused) a.play().catch(() => undefined);
    else a.pause();
  };

  return (
    <div className="va-audio">
      <button className="va-audio-btn" onClick={toggle} aria-label={playing ? 'Pause' : 'Play'}>
        {playing ? (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><rect x="6" y="5" width="4" height="14" rx="1" /><rect x="14" y="5" width="4" height="14" rx="1" /></svg>
        ) : (
          <svg width="16" height="16" viewBox="0 0 24 24" fill="currentColor"><path d="M8 5v14l11-7z" /></svg>
        )}
      </button>
      <div className="va-audio-track"><div className="va-audio-fill" style={{ width: `${progress * 100}%` }} /></div>
      <span className="va-audio-time">{fmtTime(elapsed || duration)}</span>
      <audio
        ref={ref}
        src={src}
        preload="metadata"
        onPlay={() => setPlaying(true)}
        onPause={() => setPlaying(false)}
        onEnded={() => { setPlaying(false); setProgress(0); setElapsed(0); onEnded?.(); }}
        onLoadedMetadata={(e) => setDuration(e.currentTarget.duration)}
        onTimeUpdate={(e) => {
          const a = e.currentTarget;
          setElapsed(a.currentTime);
          setProgress(a.duration && isFinite(a.duration) ? a.currentTime / a.duration : 0);
        }}
      />
    </div>
  );
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd admin && npx vitest run src/components/chat.test.tsx`
Expected: PASS (2 tests).

- [ ] **Step 5: Update VoiceAssistant to import the shared module**

In `admin/src/pages/VoiceAssistant.tsx`:
- Delete the local `renderContent` function (lines ~14-20), the `THINKING_WORDS` const + `ThinkingBubble` (lines ~28-55), and `fmtTime` + `AudioBubble` (lines ~57-114).
- Remove the now-unused `Link` import from `react-router-dom` (keep `useNavigate, useParams, useSearchParams, Navigate`).
- Add near the other imports:

```tsx
import { AudioBubble, ThinkingBubble, renderContent } from '@/components/chat';
```

- Leave all call sites unchanged: `renderContent(m.content)` still links refs (default), `<ThinkingBubble />` and `<AudioBubble .../>` unchanged.

- [ ] **Step 6: Run the full admin suite to verify no regression**

Run: `cd admin && npx tsc -b && npx vitest run src/pages/VoiceAssistant.test.tsx src/components/chat.test.tsx`
Expected: typecheck clean; VoiceAssistant tests and chat tests PASS. (Pre-existing unrelated failures in `Chats.test.tsx`/`Settings.test.tsx` are out of scope.)

- [ ] **Step 7: Commit**

```bash
git add admin/src/components/chat.tsx admin/src/components/chat.test.tsx admin/src/pages/VoiceAssistant.tsx
git commit -m "refactor(chat): extract AudioBubble/ThinkingBubble/renderContent into shared component"
```

---

### Task 2: Booking-session id + interceptor override

A separate customer thread per booking = a distinct `X-Device-Id`. Add a persisted, rotatable booking-session id, and make the axios interceptor respect an explicitly-set header.

**Files:**
- Create: `admin/src/lib/bookingSession.ts`
- Create: `admin/src/lib/bookingSession.test.ts`
- Modify: `admin/src/lib/api.ts:15`

**Interfaces:**
- Produces:
  - `getBookingSessionId(): string` — returns the current booking-session UUID, creating + persisting one (localStorage key `booking_session_id`) on first use.
  - `newBookingSession(): string` — generates a fresh UUID, persists it, returns it.

- [ ] **Step 1: Write the failing test**

Create `admin/src/lib/bookingSession.test.ts`:

```ts
import { describe, it, expect, beforeEach } from 'vitest';
import { getBookingSessionId, newBookingSession } from './bookingSession';

describe('bookingSession', () => {
  beforeEach(() => localStorage.clear());

  it('creates and persists a stable id', () => {
    const a = getBookingSessionId();
    expect(a).toMatch(/[0-9a-f-]{36}/);
    expect(getBookingSessionId()).toBe(a);           // stable across calls
    expect(localStorage.getItem('booking_session_id')).toBe(a);
  });

  it('rotates to a new id on newBookingSession', () => {
    const a = getBookingSessionId();
    const b = newBookingSession();
    expect(b).not.toBe(a);
    expect(getBookingSessionId()).toBe(b);           // now the current id
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npx vitest run src/lib/bookingSession.test.ts`
Expected: FAIL — cannot resolve `./bookingSession`.

- [ ] **Step 3: Implement the helper**

Create `admin/src/lib/bookingSession.ts`:

```ts
import { storage } from './storage';

const KEY = 'booking_session_id';

function uuidv4(): string {
  return 'xxxxxxxx-xxxx-4xxx-yxxx-xxxxxxxxxxxx'.replace(/[xy]/g, (c) => {
    const r = (Math.random() * 16) | 0;
    const v = c === 'x' ? r : (r & 0x3) | 0x8;
    return v.toString(16);
  });
}

// The customer booking thread is keyed server-side by the X-Device-Id we send.
// Using a dedicated, rotatable id (not the app-wide device id) lets "New
// booking" start a fresh conversation while a reload keeps the current one.
export function getBookingSessionId(): string {
  let id = storage.get(KEY);
  if (!id) { id = uuidv4(); storage.set(KEY, id); }
  return id;
}

export function newBookingSession(): string {
  const id = uuidv4();
  storage.set(KEY, id);
  return id;
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd admin && npx vitest run src/lib/bookingSession.test.ts`
Expected: PASS (2 tests).

- [ ] **Step 5: Make the interceptor respect an explicit header**

In `admin/src/lib/api.ts`, change line 15 from:

```ts
  config.headers['X-Device-Id'] = getDeviceId();
```

to:

```ts
  // Respect an explicitly-set device id (the public booking flow sends its own
  // booking-session id); otherwise default to the app-wide device id.
  if (!config.headers['X-Device-Id']) config.headers['X-Device-Id'] = getDeviceId();
```

- [ ] **Step 6: Verify the interceptor test still passes**

Run: `cd admin && npx vitest run src/lib/api.test.ts`
Expected: PASS (the existing `attaches X-Device-Id` test still holds — no explicit header set there).

- [ ] **Step 7: Commit**

```bash
git add admin/src/lib/bookingSession.ts admin/src/lib/bookingSession.test.ts admin/src/lib/api.ts
git commit -m "feat(booking): rotatable booking-session id + interceptor respects explicit X-Device-Id"
```

---

### Task 3: Send the booking-session id on booking calls

Route the three public booking API calls through the booking-session id so each booking maps to its own server thread.

**Files:**
- Modify: `admin/src/lib/publicBooking.ts` (`bookAssistantText`, `bookAssistantVoice`, `recordBooking`)
- Modify: `admin/src/lib/publicBooking.test.ts` (add a header assertion)

**Interfaces:**
- Consumes: `getBookingSessionId()` from Task 2.
- Produces: unchanged public signatures; each of the three calls now sends `X-Device-Id: getBookingSessionId()`.

- [ ] **Step 1: Write the failing test**

Add to `admin/src/lib/publicBooking.test.ts` (follow the file's existing mocking style for `@/lib/api`; if it spies on `api.post`, assert the third arg's headers). Example assertion to include in a test that calls `bookAssistantText`:

```ts
it('sends the booking-session id as X-Device-Id', async () => {
  const post = vi.spyOn(api, 'post').mockResolvedValue({ data: { reply_text: 'ok', fields: {}, ready: false } });
  await bookAssistantText(7, 'hi', {}, []);
  const cfg = post.mock.calls[0][2] as { headers?: Record<string, string> };
  expect(cfg?.headers?.['X-Device-Id']).toBeTruthy();
});
```

(If `publicBooking.test.ts` does not yet import `api`/`vi`, add the imports it needs, matching the existing test setup.)

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npx vitest run src/lib/publicBooking.test.ts`
Expected: FAIL — no `X-Device-Id` header present on the call config.

- [ ] **Step 3: Implement the header on all three calls**

In `admin/src/lib/publicBooking.ts`:
- Add import: `import { getBookingSessionId } from './bookingSession';`
- `bookAssistantText`: change the post to pass a config with the header:

```ts
export async function bookAssistantText(shopId: number, text: string, state: BookingFields, history: Turn[] = []): Promise<AssistantReply> {
  const { data } = await api.post(`/shops/${shopId}/book-assistant/text`, { text, state, history },
    { headers: { 'X-Device-Id': getBookingSessionId() } });
  return normalize(data);
}
```

- `bookAssistantVoice`: merge the header into its existing headers:

```ts
  const { data } = await api.post(`/shops/${shopId}/book-assistant/voice`, fd, {
    headers: { 'Content-Type': 'multipart/form-data', 'X-Device-Id': getBookingSessionId() },
  });
```

- `recordBooking`: add the config:

```ts
export async function recordBooking(shopId: number, bookingId: number): Promise<{ ok: boolean; reference?: string }> {
  const { data } = await api.post(`/shops/${shopId}/book-assistant/booked`, { booking_id: bookingId },
    { headers: { 'X-Device-Id': getBookingSessionId() } });
  return { ok: !!data?.ok, reference: typeof data?.reference === 'string' ? data.reference : undefined };
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd admin && npx vitest run src/lib/publicBooking.test.ts`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src/lib/publicBooking.ts admin/src/lib/publicBooking.test.ts
git commit -m "feat(booking): send booking-session id as X-Device-Id on public booking calls"
```

---

### Task 4: Rewrite PublicBooking as a chat UI

Replace the single-orb render with the admin chat layout (header + "+" new booking, bubble thread, text+mic bar). Keep every flow behaviour. Push a user bubble + assistant bubble per turn; auto-play the reply via the existing Web Audio path (proven, unlocked on tap); each assistant bubble carries a replay voice-note built from `reply_audio`.

**Files:**
- Modify (rewrite): `admin/src/pages/PublicBooking.tsx`
- Modify (rewrite): `admin/src/pages/PublicBooking.test.tsx`

**Interfaces:**
- Consumes: `AudioBubble, ThinkingBubble, renderContent` (Task 1); `newBookingSession` (Task 2); `bookAssistantText, bookAssistantVoice` sending the session header (Task 3).
- Produces: the `/book/:shopId` page (default export). No exported API.

- [ ] **Step 1: Rewrite the test for the chat DOM**

Replace `admin/src/pages/PublicBooking.test.tsx` with:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import React from 'react';
import { render, screen, waitFor, act } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as pub from '@/lib/publicBooking';
import * as bookingsLib from '@/lib/bookings';
import * as session from '@/lib/bookingSession';
import { speak } from '@/lib/simulation';
import PublicBooking from './PublicBooking';

vi.mock('@/lib/simulation', () => ({ speak: vi.fn() }));

const rec = vi.hoisted(() => ({ onSilence: null as null | (() => void) }));
vi.mock('@/hooks/useRecorder', () => ({
  useRecorder: (opts?: { onSilence?: () => void }) => {
    rec.onSilence = opts?.onSilence ?? null;
    const [recording, setRecording] = React.useState(false);
    return {
      recording, supported: true, level: 0,
      start: async () => { setRecording(true); },
      stop: async () => { setRecording(false); return new Blob(['x'], { type: 'audio/webm' }); },
    };
  },
}));

function renderPage() {
  return render(
    <MemoryRouter initialEntries={['/book/7']}>
      <Routes><Route path="/book/:shopId" element={<PublicBooking />} /></Routes>
    </MemoryRouter>,
  );
}

const SHOP = { id: 7, name: 'FreshPress', catalogs: [{ id: 1, title: 'Classic Haircut', price: 30 }] };

describe('PublicBooking (chat)', () => {
  beforeEach(() => {
    vi.restoreAllMocks();
    vi.mocked(speak).mockResolvedValue('blob:fake');
    vi.spyOn(HTMLMediaElement.prototype, 'play').mockResolvedValue(undefined);
    // jsdom lacks object-URL support; audio bubbles build URLs from reply_audio.
    if (!URL.createObjectURL) Object.defineProperty(URL, 'createObjectURL', { value: () => 'blob:aud', writable: true });
    else vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:aud');
    vi.spyOn(URL, 'revokeObjectURL').mockImplementation(() => undefined);
  });

  it('shows a friendly error when the shop link is invalid', async () => {
    vi.spyOn(pub, 'getPublicShop').mockRejectedValue(new Error('404'));
    renderPage();
    await screen.findByText(/booking link isn't available/i);
  });

  it('auto-sends the turn when the customer goes quiet, then books when ready', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    vi.spyOn(pub, 'bookAssistantVoice').mockResolvedValue({
      transcript: 'friday 3pm 0501234567', reply_text: 'All set!', ready: true,
      fields: { service: 'Classic Haircut', date: '2026-07-12', start_time: '15:00', customer_name: 'Sara', customer_phone: '0501234567' },
    });
    vi.spyOn(pub, 'recordBooking').mockResolvedValue({ ok: true, reference: 'BK00009' });
    const create = vi.spyOn(bookingsLib, 'createBooking').mockResolvedValue({ id: 9, booking_reference: 'BK00009' } as never);

    renderPage();
    const user = userEvent.setup();
    await user.click(await screen.findByRole('button', { name: /microphone/i }));   // start recording
    await act(async () => { rec.onSilence?.(); });                                   // 1.5s silence fires

    await waitFor(() => expect(create).toHaveBeenCalledWith(7, expect.objectContaining({
      services: [{ title: 'Classic Haircut', price: 30 }], charges: 30,
      date: '2026-07-12', start_time: '15:00', customer_name: 'Sara', customer_whatsapp: '0501234567',
    })));
    await screen.findByText(/you're booked/i);
  });

  it('sends a typed message and shows both bubbles', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    const text = vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({
      reply_text: 'What day works?', ready: false, fields: { service: 'Classic Haircut' },
    });

    renderPage();
    const user = userEvent.setup();
    const input = await screen.findByPlaceholderText(/type a message/i);
    await user.type(input, 'I want a haircut');
    await user.click(screen.getByRole('button', { name: /send/i }));

    await waitFor(() => expect(text).toHaveBeenCalledWith(7, 'I want a haircut', expect.anything(), expect.anything()));
    await screen.findByText('I want a haircut');   // user bubble
    await screen.findByText('What day works?');    // assistant bubble
  });

  it('New booking clears the thread and rotates the session', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({ reply_text: 'What day works?', ready: false, fields: {} });
    const rotate = vi.spyOn(session, 'newBookingSession');

    renderPage();
    const user = userEvent.setup();
    const input = await screen.findByPlaceholderText(/type a message/i);
    await user.type(input, 'hello');
    await user.click(screen.getByRole('button', { name: /send/i }));
    await screen.findByText('hello');

    await user.click(screen.getByRole('button', { name: /new booking/i }));

    expect(rotate).toHaveBeenCalled();
    expect(screen.queryByText('hello')).toBeNull();          // thread cleared
  });

  it('renders a booking reference as plain text (no link) for customers', async () => {
    vi.spyOn(pub, 'getPublicShop').mockResolvedValue(SHOP);
    vi.spyOn(pub, 'bookAssistantText').mockResolvedValue({
      reply_text: 'Your reference is BK00009.', ready: false, fields: {},
    });
    renderPage();
    const user = userEvent.setup();
    const input = await screen.findByPlaceholderText(/type a message/i);
    await user.type(input, 'ref?');
    await user.click(screen.getByRole('button', { name: /send/i }));

    await screen.findByText(/BK00009/);
    expect(screen.queryByRole('link', { name: 'BK00009' })).toBeNull();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/PublicBooking.test.tsx`
Expected: FAIL — no `microphone`/`send`/`new booking` controls yet (current page renders the orb).

- [ ] **Step 3: Rewrite the component**

Replace `admin/src/pages/PublicBooking.tsx` with:

```tsx
import { useEffect, useRef, useState } from 'react';
import { useParams } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { AudioBubble, ThinkingBubble, renderContent } from '@/components/chat';
import { getPublicShop, bookAssistantText, bookAssistantVoice, recordBooking, type AssistantReply, type BookingFields, type PublicShop, type Turn } from '@/lib/publicBooking';
import { createBooking } from '@/lib/bookings';
import { useRecorder } from '@/hooks/useRecorder';
import { newBookingSession } from '@/lib/bookingSession';
import { speak } from '@/lib/simulation';
import '@/styles/public-booking.css';

type Created = { service: string; date: string; start_time: string; customer_name: string; reference: string };
type Msg = { role: 'user' | 'assistant'; content: string; audioUrl?: string | null };

function todayIso(): string {
  const d = new Date();
  const y = d.getFullYear();
  const mm = String(d.getMonth() + 1).padStart(2, '0');
  const dd = String(d.getDate()).padStart(2, '0');
  return `${y}-${mm}-${dd}`;
}

// Normalise a spoken/typed number to a canonical UAE mobile (05XXXXXXXX), or
// null if it isn't a valid UAE mobile. Lenient on input, strict on result.
function canonicalUaeMobile(raw?: string): string | null {
  let d = (raw || '').replace(/\D+/g, '');
  if (d.startsWith('971')) d = d.slice(3);
  if (d.length === 9 && d.startsWith('5')) d = '0' + d;
  return /^05\d{8}$/.test(d) ? d : null;
}

// Base64 MP3 -> object URL for a voice-note bubble.
function base64ToBlobUrl(b64: string): string {
  const bin = atob(b64);
  const bytes = new Uint8Array(bin.length);
  for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
  return URL.createObjectURL(new Blob([bytes], { type: 'audio/mpeg' }));
}

// Tap to record; taps again to send. Safety net: after speaking, this much
// quiet auto-sends (measured on the real audio level, so a mid-sentence pause
// won't trip it).
const AUTO_STOP_MS = 1500;

/**
 * Public, voice-or-text self-service booking, styled as the admin Ask chat.
 * The booking flow is unchanged; only the presentation is the conversational
 * thread. A "+" starts a fresh, separate thread.
 */
export default function PublicBooking() {
  const { shopId } = useParams<{ shopId: string }>();
  const id = Number(shopId);

  const [shop, setShop] = useState<PublicShop | null>(null);
  const [loadError, setLoadError] = useState(false);
  const [created, setCreated] = useState<Created | null>(null);
  const [busy, setBusy] = useState(false);
  const [speaking, setSpeaking] = useState(false);
  const [micDenied, setMicDenied] = useState(false);
  const [messages, setMessages] = useState<Msg[]>([]);
  const [draft, setDraft] = useState('');

  const fieldsRef = useRef<BookingFields>({ date: todayIso() });
  const historyRef = useRef<Turn[]>([]);
  const bookedRef = useRef(false);
  const playCtxRef = useRef<AudioContext | null>(null);
  const playSrcRef = useRef<AudioBufferSourceNode | null>(null);
  const finishingRef = useRef(false);
  const threadRef = useRef<HTMLDivElement>(null);
  const audioUrlsRef = useRef<string[]>([]);   // object URLs to revoke

  const { recording, start, stop, supported } = useRecorder({
    meter: true,
    onSilence: () => { void finishTurn(); },
    silenceMs: AUTO_STOP_MS,
  });

  useEffect(() => {
    let alive = true;
    getPublicShop(id)
      .then((s) => { if (alive) setShop(s); })
      .catch(() => { if (alive) setLoadError(true); });
    return () => { alive = false; };
  }, [id]);

  useEffect(() => () => {
    playCtxRef.current?.close().catch(() => undefined);
    audioUrlsRef.current.forEach((u) => URL.revokeObjectURL(u));
  }, []);

  // Keep the newest message in view.
  useEffect(() => {
    threadRef.current?.scrollTo?.({ top: threadRef.current.scrollHeight, behavior: 'smooth' });
  }, [messages, busy]);

  const priceFor = (title?: string): number => {
    const c = (shop?.catalogs ?? []).find((x) => x.title.toLowerCase() === (title ?? '').toLowerCase());
    return c ? Number(c.price) || 0 : 0;
  };

  /* ---- audio playback (auto-play the reply; also gates the mic) ---------- */

  function primeAudio() {
    try {
      if (!playCtxRef.current) {
        const Ctx: typeof AudioContext = window.AudioContext
          || (window as unknown as { webkitAudioContext: typeof AudioContext }).webkitAudioContext;
        playCtxRef.current = new Ctx();
      }
      void playCtxRef.current.resume();
    } catch { /* Web Audio unavailable */ }
  }

  function playBytes(bytes: ArrayBuffer): Promise<void> {
    return new Promise((resolve) => {
      const ctx = playCtxRef.current;
      if (!ctx) { resolve(); return; }
      ctx.decodeAudioData(bytes.slice(0))
        .then(async (buffer) => {
          try { playSrcRef.current?.stop(); } catch { /* nothing playing */ }
          const src = ctx.createBufferSource();
          src.buffer = buffer;
          src.connect(ctx.destination);
          src.onended = () => { setSpeaking(false); resolve(); };
          playSrcRef.current = src;
          setSpeaking(true);
          if (ctx.state === 'suspended') await ctx.resume();
          src.start(0);
        })
        .catch(() => { setSpeaking(false); resolve(); });
    });
  }

  // /tts round trip for client-composed lines (confirmation, error prompts).
  function speakReply(text: string): Promise<void> {
    if (!text || !playCtxRef.current) return Promise.resolve();
    return speak(text, 'nova')
      .then(async (url) => {
        const bytes = await (await fetch(url)).arrayBuffer();
        URL.revokeObjectURL(url);
        return playBytes(bytes);
      })
      .catch(() => { setSpeaking(false); });
  }

  // Play the assistant's own reply from inline audio; fall back to /tts.
  function speakServerReply(r: AssistantReply): Promise<void> {
    const b64 = r.reply_audio;
    if (b64 && playCtxRef.current) {
      try {
        const bin = atob(b64);
        const bytes = new Uint8Array(bin.length);
        for (let i = 0; i < bin.length; i++) bytes[i] = bin.charCodeAt(i);
        return playBytes(bytes.buffer);
      } catch { /* fall back */ }
    }
    return speakReply(r.reply_text);
  }

  function stopSpeaking() {
    try { playSrcRef.current?.stop(); } catch { /* nothing playing */ }
  }

  /* ---- bubbles ----------------------------------------------------------- */

  function pushUser(text: string) {
    if (text) setMessages((m) => [...m, { role: 'user', content: text }]);
  }
  function pushAssistant(text: string, audioB64?: string) {
    let audioUrl: string | null = null;
    if (audioB64) { audioUrl = base64ToBlobUrl(audioB64); audioUrlsRef.current.push(audioUrl); }
    setMessages((m) => [...m, { role: 'assistant', content: text, audioUrl }]);
  }

  /* ---- booking ----------------------------------------------------------- */

  async function book(f: BookingFields): Promise<string | null> {
    if (!shop) return null;
    try {
      const b = await createBooking(shop.id, {
        services: [{ title: f.service!, price: priceFor(f.service) }],
        charges: priceFor(f.service),
        date: f.date!,
        start_time: f.start_time!,
        customer_name: f.customer_name!,
        customer_whatsapp: f.customer_phone!,
      });
      const bookingId = (b as { id?: number }).id;
      const reference = (b as { booking_reference?: string }).booking_reference || (bookingId ? `#${bookingId}` : '');
      bookedRef.current = true;
      setCreated({ service: f.service!, date: f.date!, start_time: f.start_time!, customer_name: f.customer_name!, reference });
      if (bookingId) { try { await recordBooking(shop.id, bookingId); } catch { /* best-effort */ } }
      return reference;
    } catch (e: unknown) {
      const msg = (e as { response?: { data?: { message?: string } } })?.response?.data?.message;
      const t = msg && /closed/i.test(msg)
        ? "Sorry, we're closed then — please tell me another time."
        : "Sorry, I couldn't book that — please try again.";
      pushAssistant(t);
      await speakReply(t);
      return null;
    }
  }

  async function applyReply(userText: string, r: AssistantReply) {
    const merged = { ...fieldsRef.current, ...r.fields };
    fieldsRef.current = merged;
    if (userText) historyRef.current.push({ role: 'user', content: userText });
    if (r.reply_text) historyRef.current.push({ role: 'assistant', content: r.reply_text });
    historyRef.current = historyRef.current.slice(-12);

    const complete = !!(merged.service && merged.date && merged.start_time && merged.customer_name && merged.customer_phone);
    if (r.ready && complete) {
      const phone = canonicalUaeMobile(merged.customer_phone);
      if (!phone) {
        fieldsRef.current = { ...merged, customer_phone: undefined };
        const t = "That phone number doesn't look right. Please say your mobile number again slowly — it should start with zero-five and have ten digits, like oh five oh, one two three, four five six seven.";
        pushAssistant(t);
        await speakReply(t);
        return;
      }
      merged.customer_phone = phone;
      fieldsRef.current = merged;
      const reference = await book(merged);
      if (reference) {
        await speakReply(`Perfect, you're booked! Your reference is ${reference}. Please keep it for when you arrive.`);
      }
    } else {
      pushAssistant(r.reply_text, r.reply_audio);
      await speakServerReply(r);
    }
  }

  /* ---- turns ------------------------------------------------------------- */

  async function finishTurn() {
    if (finishingRef.current || !recording || !shop) return;
    finishingRef.current = true;
    setBusy(true);
    const blob = await stop();
    finishingRef.current = false;
    if (!blob) { setBusy(false); return; }
    try {
      const r = await bookAssistantVoice(shop.id, blob, fieldsRef.current, historyRef.current);
      pushUser(r.transcript || '');
      await applyReply(r.transcript || '', r);
    } catch {
      await speakReply("Sorry, I didn't catch that — please try again.");
    } finally {
      setBusy(false);
    }
  }

  async function sendText(text: string) {
    const t = text.trim();
    if (!t || busy || !shop || bookedRef.current) return;
    setDraft('');
    primeAudio();
    pushUser(t);
    setBusy(true);
    try {
      const r = await bookAssistantText(shop.id, t, fieldsRef.current, historyRef.current);
      await applyReply(t, r);
    } catch {
      await speakReply("Sorry, I didn't catch that — please try again.");
    } finally {
      setBusy(false);
    }
  }

  async function onMicTap() {
    if (!shop || bookedRef.current) return;
    if (busy && !recording) return;       // thinking / speaking — wait
    if (speaking) { stopSpeaking(); return; }
    if (recording) { void finishTurn(); return; }
    primeAudio();
    setMicDenied(false);
    try { await start(); } catch { setMicDenied(true); }
  }

  // "+" New booking (and "Book another"): fresh thread + fresh session.
  function newBooking() {
    stopSpeaking();
    if (recording) void stop();
    newBookingSession();
    audioUrlsRef.current.forEach((u) => URL.revokeObjectURL(u));
    audioUrlsRef.current = [];
    fieldsRef.current = { date: todayIso() };
    historyRef.current = [];
    bookedRef.current = false;
    setMessages([]);
    setCreated(null);
    setBusy(false);
    setDraft('');
  }

  /* ---- render ------------------------------------------------------------ */

  if (loadError) {
    return <div className="pb-screen pb-solo"><div className="pb-empty"><Icons.Store size={28} /><p>This booking link isn't available right now.</p></div></div>;
  }

  if (created) {
    return (
      <div className="pb-screen pb-solo">
        <div className="pb-done">
          <div className="pb-done-tick"><Icons.Check size={30} /></div>
          <h2>You're booked!</h2>
          {created.reference && <div className="pb-done-ref">Reference <b>{created.reference}</b></div>}
          <p className="pb-done-sub">{created.service} · {created.date} at {created.start_time}</p>
          <p className="pb-done-sub">See you soon, {created.customer_name}{shop ? ` — ${shop.name}` : ''}.</p>
          <button className="c-btn c-btn-block" onClick={newBooking}>Book another</button>
        </div>
      </div>
    );
  }

  return (
    <div className="m-screen va-screen">
      <div className="va-head">
        <div className="va-head-text">
          <span className="va-title">{shop?.name ?? 'Book'}</span>
          <span className="va-sub">Tell me what you'd like to book</span>
        </div>
        <button className="c-icon-btn" aria-label="New booking" onClick={newBooking}><Icons.Plus size={18} /></button>
      </div>

      <div className="va-thread" ref={threadRef}>
        {messages.length === 0 && !busy && (
          <div className="va-empty">
            <div className="va-empty-mic"><Icons.Mic size={26} /></div>
            <p className="va-hint">Tap the mic and tell me what you'd like to book{shop ? ` at ${shop.name}` : ''} — I'll listen and reply. You can type instead if you prefer.</p>
          </div>
        )}
        {messages.map((m, i) => (
          <div key={i} className={`va-bubble ${m.role === 'user' ? 'va-user' : 'va-ai'}`}>
            {m.audioUrl && <AudioBubble src={m.audioUrl} autoPlay={false} />}
            {m.content && <div className="va-text">{renderContent(m.content, { linkifyRefs: false })}</div>}
          </div>
        ))}
        {busy && <ThinkingBubble />}
        {micDenied && <div className="c-error-box">Allow the microphone in your browser, then tap the mic.</div>}
      </div>

      <div className="va-controls">
        <input className="va-input" placeholder="Type a message…" value={draft}
          onChange={(e) => setDraft(e.target.value)}
          onKeyDown={(e) => { if (e.key === 'Enter') void sendText(draft); }} disabled={busy} />
        <button className="c-btn" aria-label="Send" disabled={busy || !draft.trim()} onClick={() => void sendText(draft)}>
          <Icons.Send size={16} />
        </button>
        {supported && (
          <button className={`va-mic ${recording ? 'recording' : ''}`} aria-label="Microphone"
            disabled={busy && !recording} onClick={() => void onMicTap()}>
            <Icons.Mic size={20} />
          </button>
        )}
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Run the test to verify it passes**

Run: `cd admin && npx vitest run src/pages/PublicBooking.test.tsx`
Expected: PASS (5 tests).

- [ ] **Step 5: Typecheck and run the full suite**

Run: `cd admin && npx tsc -b && npx vitest run`
Expected: typecheck clean; all suites pass except the pre-existing unrelated `Chats.test.tsx` / `Settings.test.tsx` failures (confirm they are the same 4 failures present before this work — they must not increase).

- [ ] **Step 6: Commit**

```bash
git add admin/src/pages/PublicBooking.tsx admin/src/pages/PublicBooking.test.tsx
git commit -m "feat(booking): customer page as conversational chat UI (bubbles, text+mic, New booking)"
```

---

### Task 5: Deploy to staging and verify

**Files:** none (deploy only).

- [ ] **Step 1: Deploy the frontend to staging**

Run: `admin/deploy-staging.ps1` (PowerShell).
Expected: build succeeds; ends with `HTTP/1.1 200 OK` and `Done - https://staging-admin.eloquentservice.com`.

- [ ] **Step 2: Manual verification on device (Francis)**

On a phone at `https://staging-admin.eloquentservice.com/book/8`:
- Chat layout shows (header with shop name + "+"; bottom text+mic bar).
- Tap mic → speak → after ~1.5s it sends; user bubble (transcript) + assistant bubble (reply text + playable voice note) appear; reply auto-plays.
- Type a message + Send → works the same.
- Complete a booking → "You're booked!" card; "Book another" starts fresh.
- "+" mid-conversation clears the thread; the next message appears as a new thread in the shop's Chats.

- [ ] **Step 3: Update memory**

Update `self-service-booking` memory: note the chat-UI redesign shipped to staging (bubbles + text/mic + "+" new-booking via rotating booking-session id; shared `components/chat.tsx`).

---

## Self-Review

**Spec coverage:**
- Chat layout (header/thread/bottom bar) → Task 4. ✓
- Voice + text input → Task 4 (`onMicTap`/`sendText`). ✓
- Bubbles = text + playable voice note → Task 4 (`AudioBubble` from Task 1). ✓
- Plain-text refs for customers → Task 1 (`linkifyRefs`) + Task 4 call site + test. ✓
- "+" New booking = separate thread → Tasks 2 + 3 + 4. ✓
- Keep flow (auto-send 1.5s, inline TTS, auto-book, phone guard, success card) → Task 4. ✓
- Shared-component cleanup → Task 1. ✓
- Reply playback open-point → resolved to Web Audio auto-play + replay bubble (Task 4). ✓
- No backend changes → confirmed; backend tests not touched. ✓
- Testing per spec → Tasks 1–4 tests. ✓
- Deployment via `admin/deploy-staging.ps1` → Task 5. ✓

**Placeholder scan:** none — every step has concrete code/commands.

**Type consistency:** `renderContent(text, { linkifyRefs })`, `AudioBubble({ src, autoPlay, onEnded })`, `ThinkingBubble()`, `getBookingSessionId()/newBookingSession()`, `Msg`, `AssistantReply.reply_audio` used consistently across tasks.
