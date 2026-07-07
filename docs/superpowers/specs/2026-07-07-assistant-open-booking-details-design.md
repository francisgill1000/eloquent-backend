# Owner Assistant — "Open booking details" after creation

**Date:** 2026-07-07
**Status:** Approved design, pending implementation plan
**Scope:** Admin/owner voice+text assistant (`/ask`). Booking creation only.

## Problem

When the owner creates a booking by voice, the assistant reads back a reference
(e.g. BK00016) but there is no way to jump to that booking. The owner wants the
assistant to **offer** to open the booking and, on agreement, **redirect** to the
booking detail page.

The owner assistant currently returns only `reply_text` + `reply_audio_url`
(+ `conversation_id`). Its tool loop (`ClaudeClient::toolLoop`) returns only the
final text, so there is no channel to carry a navigation directive to the UI.

## Decision (from brainstorming)

- **Interaction:** the assistant asks ("Want me to open the details?"); if the
  owner agrees on the next turn, it **auto-navigates**. The *model* decides to
  navigate (by calling a tool), so "yes" works in any language.
- **Target:** a full redirect to the existing booking detail route `/booking/:id`
  (leaving the chat; back button returns). No panel/overlay.
- **Scope:** only after a booking is **created**. The tool is generic but we do
  not wire reschedule/find offers yet (YAGNI).

## Architecture

A new **navigation-action channel** from a tool call out to the chat UI:

```
model calls open_booking(reference)
  → BookingTools.open() resolves the booking (shop-scoped)
  → records navigate('/booking/{id}') on a request-scoped AssistantActions collector
  → returns a small ok tool_result
after toolLoop, OwnerAssistantController.respond() reads the collector
  → adds action:{type:'navigate', route:'/booking/{id}'} to the JSON response
frontend VoiceAssistant, after rendering the reply, navigates to action.route
```

### Backend

**`App\Services\Assistant\Support\AssistantActions`** (new)
- Request-scoped collector. Interface:
  - `navigate(string $route): void` — stores the pending navigation target.
  - `pending(): ?array` — returns `['type' => 'navigate', 'route' => $route]` or null.
- Bound as a `singleton` so `BookingTools` and `OwnerAssistantController` share one
  instance within a request.

**`BookingTools`** (`app/Services/Assistant/Modules/BookingTools.php`)
- Inject `AssistantActions`.
- New tool **`open_booking`** (NON-mutating — no confirm gate):
  - Permission: `bookings.view` (add to the `permissions()` map).
  - Input: `reference` (string, required).
  - Resolves via the existing `resolveBooking($call)`. If not found →
    `$this->notFound('booking')`.
  - On success: `$actions->navigate("/booking/{$booking->id}")` and return
    `['opening' => true, 'reference' => $booking->booking_reference]`.
  - Add its schema to `toolDefs()`.

**`OwnerAssistantController::respond()`**
- Inject `AssistantActions` (constructor).
- After a successful reply (the `done`/persist path), read `$this->actions->pending()`;
  if non-null, add `'action' => $pending` to the JSON payload. Unchanged otherwise.
- The failure/fallback path is untouched (no navigation on failure).

**`AssistantPrompt`** — add one rule:
- After a booking is created (a tool result with `done=true` for `create_booking`),
  offer to open its details and end with that question. If the owner agrees, call
  `open_booking` with the booking's reference. Do not navigate unless they agree.

### Frontend (admin app)

**`admin/src/lib/assistant.ts`**
- `AssistantReply` gains optional `action?: { type: 'navigate'; route: string }`.

**`admin/src/pages/VoiceAssistant.tsx`**
- In `send()` and `toggleMic()`, after appending the assistant reply, if
  `res.action?.type === 'navigate'`, call `navigate(res.action.route)`.
- Render order: show the reply text first, then navigate, so the owner sees the
  confirmation before the redirect.

## Data flow / isolation

- `open_booking` is shop-scoped through `resolveBooking` (already filters by
  `shop_id`), so no cross-tenant navigation. The booking detail SPA route is also
  permission-gated independently.
- `AssistantActions` holds at most one pending navigation per request; a request
  that does not call `open_booking` yields `pending() === null` and no `action`
  key, so existing responses are unchanged.

## Error handling

- Unknown/failed reference → `not_found`; the model tells the owner it couldn't
  find the booking and does NOT navigate (collector stays empty).
- If the owner declines, the model simply does not call `open_booking`.

## Testing

Backend (`tests/`, run on the droplet php8.4 / in-memory sqlite):
- **`AssistantActions`** unit: `navigate()` then `pending()` returns the directive;
  fresh instance returns null.
- **`BookingTools` open**: `open_booking` with a valid reference records the
  navigate target and returns `opening=true`; unknown reference → `not_found` and
  no navigation recorded; scoped to the shop (another shop's reference → not_found).
- **API/tool-loop** (mirrors `OwnerAssistantConfirmGateTest` with `Http::sequence`):
  a turn where the model calls `open_booking` yields a response whose JSON has
  `action.route === '/booking/{id}'` alongside `reply_text`.

Frontend (`admin/src/pages/VoiceAssistant.test.tsx`, vitest):
- When `postText` resolves with `action:{type:'navigate',route:'/booking/7'}`, the
  reply renders and `navigate` is called with `/booking/7`.
- When there is no `action`, `navigate` is not called for the reply.

## Rollout

Local → **staging** (64.227.153.90) first; verify the create → "yes" → redirect
flow in the browser. Backend-only + small frontend change; deploy admin via
`admin/deploy-staging.ps1`. Promote to prod only on explicit approval.

## Out of scope (YAGNI)

- Offering to open after reschedule/cancel/find (the tool supports it; not wired).
- A tappable in-bubble button (chose ask-then-auto-open).
- Opening in a panel/overlay (chose full redirect).
