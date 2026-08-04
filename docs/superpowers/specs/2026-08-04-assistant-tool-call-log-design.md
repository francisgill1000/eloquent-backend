# Assistant tool-call log — design

**Date:** 2026-08-04
**Status:** approved, ready for implementation

## Problem

On 2026-08-04 on production the owner assistant told Francis *"Done! Jhon has been added to your staff"* and *"Done! General checkup at 100 dirhams has been added"*. Neither row was written. Establishing that took sequence forensics — comparing `staff_id_seq` and `catalogs_id_seq` against each table's `max(id)` — because **nothing records which tools the assistant actually invoked**. Not `OwnerAssistantController`, not `AssistantToolRegistry`, not `laravel.log`.

The confirm-directive feature (shipped the same day) makes the failure *visible* to the owner: a skipped confirm turn now leaves an unconfirmed card on screen. It does not make the failure *diagnosable*. When Francis reports "it said it did X but didn't", there is still no evidence trail.

## What this builds

A server-side record of every owner-assistant tool call: which tool, with what arguments, what came back, and which conversation turn it belonged to.

Logging records calls that happened; the bug is a call that did not. It is still the proof — but only because each row is tied to a conversation. The query that would have answered this morning in a minute is: *assistant messages in conversation 16 around 01:10:40* beside *tool calls in conversation 16 around 01:10:40*. The assistant claimed success; there are no rows; proven.

## Scope

**Owner assistant only.** Every one of its tool calls passes through the single chokepoint `AssistantToolRegistry::execute()`, including calls made by `POST /shop/assistant/confirm`.

Out of scope: the WhatsApp auto-reply assistant (`App\Services\Wa\BookingTools`, a separate integration), the public booking assistant (extracts fields, never writes), any UI, and any alerting. The log is read with `psql` on the droplet during an investigation.

## Data model

New table `assistant_tool_calls`:

| column            | type      | notes                                                     |
|-------------------|-----------|-----------------------------------------------------------|
| `id`              | bigint    | primary key                                                |
| `shop_id`         | bigint    | tenant scope; indexed with `created_at`                    |
| `conversation_id` | bigint    | nullable — backfilled when a thread is created lazily      |
| `shop_user_id`    | bigint    | nullable — the acting staff user, null for an owner token  |
| `tool`            | string    | tool name                                                  |
| `input`           | json      | arguments as received, truncated                           |
| `result`          | json      | the tool's return value, truncated                         |
| `outcome`         | string    | derived; see below                                         |
| `user_confirmed`  | boolean   | true only for a call from the confirm endpoint             |
| `duration_ms`     | integer   | wall time of the tool call                                 |
| `created_at`      | timestamp |                                                            |

`outcome` is derived from the decoded result so investigations can filter without parsing JSON:

- `applied` — result has `done`
- `preview` — result has `preview`
- `error` — result has `error`
- `read` — anything else (a read tool returning data)

Indexes: `(shop_id, created_at)` and `(conversation_id)`.

`input` and `result` are truncated to 2000 characters each before storage. Some results are long lead or booking lists, and the log's job is to show what happened, not to mirror the payload.

## Components

**`App\Services\Assistant\AssistantCallLog`** — a new request-scoped singleton, the only thing that writes the table.

- `record(string $tool, array $input, array $result, bool $userConfirmed, int $durationMs): void`
- `forConversation(?int $id): void` — the conversation this request's calls belong to
- `backfillConversation(int $id): void` — sets `conversation_id` on the rows this request wrote that still have none

It lives in its own service rather than on `AssistantActions`, which exists to carry UI directives and should not grow a second responsibility.

**`AssistantToolRegistry::execute()`** — times the module dispatch and calls `record()`. The `unknown_tool` path logs too; a tool the model invented is exactly the kind of thing worth seeing.

**`OwnerAssistantController`** — sets the conversation on the log alongside the existing `AssistantActions::forConversation()` call, and calls `backfillConversation()` where it already backfills pending actions. Unlike a pending action, a turn may produce several tool calls, so the backfill covers every row from the request rather than one row by id.

**`assistant:prune-tool-calls --days=30`** — nightly, beside the existing `assistant:prune-pending-actions`.

## Error handling

Logging must never break a tool call or change its result. The whole record path is wrapped in `try/catch`; a failure is written to `laravel.log` as a warning and swallowed. A broken log is an inconvenience; a broken assistant is an outage.

The timing measurement and the log write happen around the dispatch, never inside the module, so a module that throws still returns its exception to the caller unchanged.

## Testing

- Each executed tool writes one row with the right `tool`, `input`, and `shop_id`.
- `outcome` derivation: `applied`, `preview`, `error`, and `read` each from a real tool call.
- A call through the confirm endpoint records `user_confirmed = true`; a model-driven one records `false`.
- `unknown_tool` is logged with `outcome = error`.
- A logger that throws does not break the tool call or alter its result.
- `input` and `result` longer than 2000 characters are truncated.
- On the first turn of a brand-new thread, every row from that request is backfilled with the new `conversation_id`.
- The prune deletes rows older than 30 days and keeps newer ones.

## Retention and PII

`input` holds customer names and phone numbers from tools like `create_booking` and `create_customer`. Rows are kept 30 days: long enough to investigate something reported a week or two later, short enough not to accumulate personal data without cause. This is consistent with the 7-day prune already running for `assistant_pending_actions`, which holds the same class of data for a shorter, narrower purpose.
