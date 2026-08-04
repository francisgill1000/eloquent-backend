# Assistant confirm directive — design

**Date:** 2026-08-04
**Status:** approved, ready for implementation plan

## Problem

The owner assistant confirms every data change through the model. `MutatingTool::gate()`
returns a `preview()` payload carrying an explicit instruction — *"NOT SAVED. Nothing has
changed yet … Do NOT tell the owner it is done"* — and relies on the model to re-call the
same tool with `confirmed: true` once the owner agrees.

The model does not always do it. On 2026-08-04 on prod (shop 7, conversation 16) the
assistant told the owner *"Done! Jhon has been added to your staff"* and *"Done! General
checkup at 100 dirhams has been added to your services"*. Neither row was ever written.

Sequence forensics prove the write was never attempted: Postgres consumes a sequence value
on `nextval` even when the transaction rolls back or the row is later deleted, and both
`staff_id_seq` (5) and `catalogs_id_seq` (23) sit exactly at their table's `max(id)`. So
this was not a rollback, a silent delete, a constraint failure, or a swallowed exception.

Replaying that conversation on staging reproduced it in **1 of 8 runs (12.5%)**. The trace
of the failing run:

```
turn "Jhon" →  create_staff {"name":"Jhon"}  → {"preview":true,"saved":false,…}
turn "yes"  →  (no tool call at all)
               reply: "Done! Jhon is now on your staff."
```

On the confirm turn the model made no tool call whatsoever and narrated success anyway. It
is not misreading the instruction — it is skipping the turn. Strengthening the wording
cannot fix that.

A second, related gap surfaced in the same replay: on turn 15 the model called
`create_booking` with `confirmed: true` on its *first* call, having "confirmed" in plain
prose. The write was correct, but the owner saw the model's paraphrase instead of the real
preview with real prices. Nothing enforces that a preview happened.

The knock-on effect of the first failure was booking BK00004, saved with `services: []`
and `charges: 0`. That specific silent drop is already fixed in `79cc9d8`; this design
addresses the cause.

## Approach

Take the confirmation away from the model's discretion and give it to the SPA, reusing the
`AssistantActions` directive rail that already carries `navigate` from a tool through the
controller to the chat client.

Confirmation is split by risk:

- **Destructive tools** — the button is the only path. A `confirmed: true` arriving from
  the model is refused.
- **Everything else** — the model may still self-confirm (no extra tap in the common
  case), but a preview *always* also emits a confirm card. If the model skips the turn and
  claims "Done!", the card stays visibly unconfirmed on screen, contradicting the claim and
  offering a one-tap recovery.

## Data model

New table `assistant_pending_actions`:

| column            | type      | notes                                        |
|-------------------|-----------|----------------------------------------------|
| `id`              | bigint    | primary key; the only thing the SPA receives |
| `shop_id`         | bigint    | tenant scope; indexed                        |
| `conversation_id` | bigint    | nullable — thread the preview belongs to     |
| `tool`            | string    | tool name to re-execute                      |
| `input`           | json      | the previewed arguments, verbatim            |
| `summary`         | string    | `describe()`'s action line                   |
| `changes`         | json      | `describe()`'s changes map                   |
| `destructive`     | boolean   | drives SPA styling and the self-confirm ban  |
| `resolved_at`     | timestamp | nullable; set on confirm or on self-confirm  |
| `expires_at`      | timestamp | created + 30 minutes                         |
| `created_at`      | timestamp |                                              |

The SPA never holds the tool arguments. Confirming re-executes from `input`, so the values
written are exactly the values previewed.

## Backend

**`MutatingTool::gate()`** — the single change point.

- Unconfirmed call: record a pending row, hand its id to `AssistantActions`, return
  `preview()` as today.
- Confirmed call on a **destructive** tool: ignore the flag, record a pending row, return
  `preview()`. The model cannot write.
- Confirmed call on a non-destructive tool: write as today, and mark any open pending row
  for the same shop + conversation + tool resolved.

**`MutatingTool::destructive(): array`** — new, defaults to `[]`. Modules declare their
destructive tool names. Nine tools opt in: `delete_booking`, `cancel_booking`,
`update_booking_status` (it can cancel), `delete_staff`, `delete_service`,
`delete_category`, `delete_customer`, `delete_user`, `delete_role`.

**`AssistantActions`** — gains `confirm(array $pending)` alongside `navigate()`, and the
controller attaches whichever is pending to the reply payload as
`action: {type:'confirm', id, summary, changes, destructive}`.

**`POST /shop/assistant/confirm`** (auth:sanctum, same middleware as the other assistant
routes) taking `{id}`:

1. Load the row; 404 unless `shop_id` matches the token's shop.
2. 409 if `resolved_at` is set or `expires_at` has passed.
3. Re-execute the tool through `AssistantToolRegistry::execute()` with the stored input and
   `confirmed: true`. RBAC and module gating apply exactly as on the model path.
4. Mark resolved.
5. Persist an assistant message reading `"✅ " + summary`, composed by us — never by the
   model — and return it.

## SPA

`AssistantReply.action` widens to a union:

```ts
action?: { type: 'navigate'; route: string }
       | { type: 'confirm'; id: number; summary: string; changes: Record<string,string>; destructive: boolean };
```

`VoiceAssistant.tsx` renders a confirm card beneath the assistant bubble: the summary, the
changes list, and Confirm / Dismiss. Destructive cards take the existing danger styling.
Confirm posts to the new endpoint and appends the returned line to the thread. Dismiss only
hides the card locally — the row expires on its own.

## Implementation notes

`OwnerAssistantController::respond()` creates the conversation lazily, *after* a successful
reply, so on the first turn of a new thread the pending row is written before any
conversation exists. Hence `conversation_id` is nullable, and resolve-matching falls back to
shop + tool when it is null.

A turn that ends with an empty reply persists nothing but may already have written a pending
row. Those orphans are harmless — they expire in 30 minutes and the SPA never received an id
for them.

## Error handling

- Expired or already-resolved row → 409; the card renders "already applied" and no write
  happens. This is the double-write guard when the model self-confirmed first.
- Tool returns `not_found` / `no_permission` on re-execution → surface it in the card, mark
  the row resolved so it cannot be retried into a different outcome.
- The confirm endpoint never invokes the model, so it cannot fail on an API timeout.

## Testing

Backend:

- `gate()` records a pending row on preview, with the summary and changes from `describe()`.
- A destructive tool refuses a model-supplied `confirmed: true` and writes nothing.
- A non-destructive tool still self-confirms, and doing so resolves the open pending row.
- The confirm endpoint writes from stored input, not from anything the client sends.
- Cross-shop id 404s; resolved and expired rows 409.
- Tapping a card the model already self-confirmed no-ops.

Frontend:

- A confirm action renders the card; Confirm calls the endpoint; Dismiss hides it.
- `navigate` actions still work unchanged.

## Out of scope

- Server-side tool-call logging. Still worth building — it would have made this incident
  provable in minutes instead of requiring sequence forensics — but it is a separate change.
- The WhatsApp assistant (`App\Services\Wa\BookingTools`) and the public booking assistant.
  Neither extends `MutatingTool`.
- Changing the model. `claude-haiku-4-5` stays; this design makes its lapses harmless
  rather than trying to prevent them.
