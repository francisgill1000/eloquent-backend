# In-app self-service booking page ("Book yourself")

Date: 2026-07-09
Status: Approved — ready for implementation plan

## Goal

A public, no-auth booking page **inside the admin app** that a shop shares with
its own customers (via the Booking QR code on the Profile page). Customers book
themselves either by **voice** (a conversational assistant that fills the form as
they speak) or by **tapping** the form fields — a hybrid where speech and taps
update the same booking state. Instantly creates a real, confirmed booking that
appears in the owner's Bookings.

Explicitly **do not** take design cues from the old external customer app
(`bookings.eloquentservice.com`); reference this admin app's own visual language.

## Decisions (from brainstorming)

- **Voice mode:** Hybrid — big animated mic + a live-filling form. Speak OR tap;
  both mutate the same form state. Voice is **field-extraction only** (see
  Restrictions), not a general agent.
- **Availability:** Freeform — no slot pre-checking/locking. The create endpoint
  still enforces working-hours; a closed-day/time attempt is surfaced inline.
- **Result:** Instantly confirmed booking (same as an owner-made booking).
- **Staff:** Auto-assigned by the existing `StaffAssigner`; customer does not pick.

## Entry point & routing

- New **public** route `admin` `/book/:shopId`, declared alongside the other
  public/full-screen routes (`/web`, `/login`, `/scan/:token`) — outside
  `RequireShop`, `AppShell`, `RequireSubscription`, `ModuleGuard`.
- `admin/src/pages/Profile.tsx`: the **Booking QR Code** now encodes
  `${window.location.origin}/book/${shop.id}`. The "Copy link", "Share", and the
  downloaded poster CTA all use this in-app URL instead of `CUSTOMER_WEB`.
  (`CUSTOMER_WEB` may be removed if it has no other use.)

## Data loading

- On mount: `GET /shops/:shopId` (already public via `apiResource('/shops')`).
  Returns `name`, `logo`, `hero_image`, `working_hours`, `catalogs`
  (the shop's priced services), and computed `slots` for a date.
- Refetch with `?date=` when the chosen date changes to refresh `slots`.
- Shop not found / no services → a friendly "This booking link isn't available
  right now" screen (no stack traces, no owner data).

## Screen layout (responsive, this app's look)

Single page component `admin/src/pages/PublicBooking.tsx`, styled with the app's
existing tokens/classes (mint glass `c-*`, `va-*`, `Icons`, `insights.css`-style
cards). New styles in a dedicated block (e.g. `booking-public` section of
`mobile.css`/`desktop.css` or a new `public-booking.css`).

- **Header:** shop logo + "Book with {shop name}".
- **Hero mic:** a large mic button that reacts to the caller's voice in real
  time. A Web Audio `AnalyserNode` on the recorder's `MediaStream` drives a
  CSS scale/glow (RMS amplitude → intensity). Visual states: `idle`,
  `listening` (amplitude-reactive rings/glow), `thinking` (pulse while the
  server call is in flight), `speaking` (while TTS reply plays).
- **Live form:**
  - Service — chips/select built from `catalogs` (title + price).
  - Date — date input (defaults to today; drives the `slots` refetch).
  - Time — slot chips from `slots` for the chosen date; free pick allowed.
  - Your name — text.
  - Your phone (WhatsApp) — tel.
- **Assistant reply line:** the spoken `reply_text` shown as text so the caller
  sees what was understood / what's still needed.
- **Confirm booking** button: enabled when service + date + time + name + phone
  are present; voice can also set `ready`.
- **Responsive:** mic + form side-by-side on `≥1024px`; stacked (mic on top,
  form below) on mobile/tablet.

## Voice backend (new, customer-scoped, public)

New `App\Http\Controllers\PublicBookingAssistantController`:

- Routes (public, keyed by `X-Device-Id`, throttled — each hits Claude/Whisper/TTS):
  - `POST /shops/{shop}/book-assistant/text`  — `{ text, state }`
  - `POST /shops/{shop}/book-assistant/voice` — `{ audio, state }` (multipart)
  - Suggested throttle: `throttle:20,1` (matches the other public AI endpoints).
- Flow:
  1. Voice: transcribe audio via `App\Services\Wa\Transcriber` (reuse). On
     transcription failure return a graceful retry payload (mirrors the owner
     controller).
  2. One `ClaudeClient` call with:
     - a **customer-booking system prompt** (helper `PublicBookingPrompt::for($shop)`)
       that states the shop name, lists the shop's services (titles + prices),
       today's date, and instructs: collect service, date, time, name, phone;
       ask only for what's missing; keep replies short and friendly; never
       discuss anything but booking this appointment.
     - the current partial booking `state` (what's already filled) + short history.
     - a single tool `set_booking(service?, date?, start_time?, customer_name?,
       customer_phone?, ready?)`. Extracted fields come back via the tool input.
  3. TTS the reply via `App\Services\Wa\Speech` (reuse) when available.
- Response JSON: `{ transcript?, reply_text, reply_audio_url, fields, ready }`.
  - `reply_audio_url`: a signed/public URL an `<audio>` can load (follow the TTS
    pattern used by `POST /tts` / owner assistant audio).
  - `fields`: only the keys the model set this turn (merged client-side).

### Restrictions (safety — it is public)

The assistant may **only**: read this shop's services and set the booking fields
above. It has **no** access to revenue, other bookings, cancellations, customer
lists, or any owner tool. This is a separate, minimal registry/prompt — the owner
`AssistantToolRegistry` is **not** reused here.

## Create + confirm

- Confirm → `POST /shops/:shopId/book` (existing public endpoint) with:
  `{ services: [{ title, price }], date, start_time, customer_name,
  customer_whatsapp, charges }`. `charges` = selected service price.
- Instantly confirmed; staff auto-assigned; visible in owner Bookings.
- Errors surfaced inline:
  - Working-hours (`400 "Shop is closed on this day"`) → "We're closed then —
    pick another time."
  - Validation/other → generic friendly message; form stays editable.
- Success → confirmation screen: ✓, booking summary (service, date/time, name,
  shop), and a **Book another** button. No auth, no owner booking-detail page.

## Testing

- Backend feature tests (run on droplet, php8.4 — never local, never prod DB):
  - `book-assistant/text` happy path extracts fields into `fields`.
  - Restricted tools: the assistant cannot reach owner tools (only `set_booking`).
  - Throttle applies to the assistant endpoints.
  - Public `POST /shops/{shop}/book` still creates a confirmed booking end-to-end.
- Frontend:
  - `Profile.test.tsx` — Booking QR now encodes the in-app `/book/:id` URL.
  - Basic render test for `PublicBooking` (loads shop, shows form, disabled
    Confirm until required fields set).

## Out of scope (YAGNI)

Real slot-availability locking, staff picker, deposits/payment, pending-approval
flow, multi-service cart, promo codes (endpoint supports it; not exposed here),
customer login/accounts.

## Reuse map

- Public reads: `GET /shops/{shop}` (name/logo/services/slots/hours).
- Public create: `POST /shops/{shop}/book` (`BookingController::bookSlot`).
- Speech-to-text: `App\Services\Wa\Transcriber`.
- Text-to-speech: `App\Services\Wa\Speech` + existing TTS URL pattern.
- Tool loop: `App\Services\Wa\ClaudeClient::toolLoop` (with a minimal one-tool set).
- Frontend recorder: `admin/src/hooks/useRecorder` (extend to expose the
  `MediaStream`/analyser for the reactive mic, or add a small analyser in-page).
