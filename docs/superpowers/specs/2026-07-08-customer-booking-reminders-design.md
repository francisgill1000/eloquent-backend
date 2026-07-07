# Customer Booking Reminders (WhatsApp) — Design

**Tier 1, Feature 1** of the Bookings roadmap. Built 2026-07-08 (autonomous overnight build).

## Problem
No-shows are the #1 revenue killer for UAE salons/clinics. The app already sends a
staff-facing reminder (manual `mark-reminder-sent`), but nothing reminds the *customer*.
This feature auto-sends a WhatsApp reminder to the customer ahead of their appointment so
they confirm or cancel — riding entirely on the existing WhatsApp Cloud stack.

## Scope (minimal vertical slice)
- A scheduled command `bookings:send-reminders` that runs hourly.
- For each shop with the `bookings` module + a configured `WaAccount` + reminders enabled,
  find `booked` bookings whose appointment start is within the reminder lead window and whose
  customer reminder has not yet been sent, and send one WhatsApp text via `WhatsAppCloud`.
- Tenant-scoped: iterate per shop; use that shop's own `WaAccount` and template. Never leak a
  number/message across shops.

## Decisions (defaults, documented)
- **Reminder cadence:** a single 24-hour-ahead reminder in v1 (the biggest, cheapest win).
  1-hour reminders are deferred — the schema (`reminder_customer_sent_at`) is generic so a
  second window can be added later without migration churn. Window is "appointment starts
  between now and now+24h" gated by a not-yet-sent flag, so a booking is reminded once as it
  enters the 24h window regardless of exact run time (hourly cron tolerates drift).
- **New column** `bookings.reminder_customer_sent_at` (nullable timestamp). Kept separate from
  the existing `reminder_sent_at` (staff-facing manual reminder) to avoid collision.
- **Per-shop settings** on `shops`:
  - `booking_reminders_enabled` (boolean, default `true`) — sending only ever happens when the
    shop also has a `WaAccount`, so default-on is safe (no account ⇒ no send).
  - `booking_reminder_template` (text, nullable) — falls back to a tenant-neutral default.
- **Template placeholders:** `{name}` (customer), `{shop}` (this shop's own name — tenant-safe),
  `{date}`, `{time}`, `{service}`. Default:
  `"Hi {name}, this is a reminder of your appointment at {shop} on {date} at {time}. Reply here to confirm or reschedule."`
- **Idempotency:** sending sets `reminder_customer_sent_at = now()`; the query excludes rows
  where it is already set, so re-runs never double-send.
- **Failure isolation:** a send failure for one booking is caught + logged and does not abort the
  batch or mark that booking as reminded (so it retries next run).
- **No payment/charge code** — pure messaging. Compliant with build rules.

## Not in scope (future)
- 1-hour reminder window, customer reply→auto-confirm parsing, WhatsApp template-message
  (HSM) approval for >24h-session sends. v1 relies on the existing free-form send path used by
  autoreply; real cross-session delivery may need an approved template later.

## Tests (TDD)
1. Sends a reminder for a booked appointment inside the 24h window; sets the flag; text
   contains customer name + shop name.
2. Does not send for a booking outside the window / already-reminded / cancelled.
3. Does not send when the shop lacks a WaAccount or has reminders disabled.
4. Tenant isolation: shop A's reminder uses shop A's account + name, never shop B's.
5. A send failure does not mark the booking reminded (retried next run).
