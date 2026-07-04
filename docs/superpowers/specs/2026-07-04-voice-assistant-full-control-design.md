# Voice Assistant — Full App Control by Voice

**Date:** 2026-07-04
**Status:** Design approved, pending spec review

## Goal

Turn the "Ask" mic page into a full, hands-free control surface so a
non-technical owner can run the entire app by voice — not just read reports,
but create, edit, and delete across every part of the business.

Today the assistant (`OwnerAssistantTools`) exposes 5 read tools and 4 narrow
write tools (cancel booking, set status, set one day's hours, change one
price). This expands it to cover every data action in the app, with a strict
confirm-everything safety gate and per-user RBAC enforcement.

## Requirements (from brainstorming)

1. **Scope:** every data action — bookings, services, categories, staff,
   working hours, prices, customers, users & roles, business profile.
   (Navigation / page-opening is explicitly out of scope.)
2. **Safety:** confirm *everything* by voice. Every change AND delete is read
   back and requires a spoken/typed "yes". No on-screen tap required, fully
   hands-free — but nothing is ever written on the first tool call.
3. **Permissions:** respect the logged-in user's RBAC role. Voice can never do
   more than that user can do by tapping through the app.
4. **Roles:** full per-permission role editing by voice (create/update roles
   with an explicit permission list, read back in plain-language labels).
5. **Testing:** TDD. Tests are written first; the user runs the PHP suite on
   the branch (local/CI), not on this dev box.

## Non-goals

- Voice navigation / opening pages ("show me bookings" as a launcher).
- Any change to the `ClaudeClient::toolLoop` mechanism or the mic UI's
  audio/recording pipeline.
- New RBAC permissions — we reuse the existing `PermissionCatalog`.

## Architecture (Approach B — domain tool modules + registry)

The single `OwnerAssistantTools` class is decomposed into focused domain
modules behind a registry. Each module owns one part of the app, is small
enough to hold in context, and is tested in isolation.

```
app/Services/Assistant/
  Contracts/AssistantToolModule.php   interface: defs(): array; handles(string $tool): bool; run(ToolCall $call): array
  Support/ToolCall.php                value object: Shop $shop, ?ShopUser $actingUser, string $tool, array $input, bool $confirmed
  Support/MutatingTool.php            base for write tools: RBAC check + confirm gate + resolution response shapes
  AssistantToolRegistry.php           aggregates every module's defs(); routes execute() to the owning module
  Modules/ReportTools.php             existing read tools, moved verbatim
  Modules/BookingTools.php
  Modules/ServiceTools.php
  Modules/CategoryTools.php
  Modules/StaffTools.php
  Modules/HoursTools.php
  Modules/CustomerTools.php
  Modules/AccessTools.php             users & roles
  Modules/ProfileTools.php            business settings
```

`OwnerAssistantController::respond()` changes one dependency: it calls
`AssistantToolRegistry::defs()` and `AssistantToolRegistry::execute($shop, $tool, $input)`
instead of `OwnerAssistantTools` directly. The tool-loop in `ClaudeClient` is
untouched. `OwnerAssistantTools` is retired once `ReportTools` + the registry
replace it (or kept as a thin alias if any test references it — decided in the
plan).

### Registry responsibilities

- `defs()` — concatenate every module's `defs()` into the Anthropic tool list.
- `execute($shop, $tool, $input)` — build a `ToolCall`, find the module whose
  `handles($tool)` is true, call `run($call)`, JSON-encode the result. Unknown
  tool → `{"error": "unknown_tool"}`.
- Reads `current_shop_user()` once and puts it on the `ToolCall` so every
  module sees the same acting user.

## Safety mechanism 1 — the confirm-everything gate

Every mutating tool runs through `MutatingTool`. Mutating tool schemas include
a boolean `confirmed` (default `false`). The write code path is physically
unreachable unless `confirmed === true`.

Flow:

1. Model calls a write tool with `confirmed: false` (or omitted).
2. The tool **resolves the real target** (see resolution) and returns a
   **preview** — a human-readable summary + the concrete before→after changes —
   and **writes nothing**:
   `{"preview": true, "action": "Cancel booking BK00042 — Sarah Ali, today 3:00pm", "changes": {"status": "booked → cancelled"}}`
3. The assistant reads the preview back and asks "Shall I do that?"
4. On the user's "yes", the model re-calls the same tool with `confirmed: true`.
5. The tool performs the write and returns `{"done": true, ...}`.

This applies uniformly to creates, updates, and deletes. A misheard command can
never mutate data without a read-back turn, because the write branch is gated on
`confirmed === true` in shared base code, not on model discipline.

## Safety mechanism 2 — RBAC per tool

Each mutating tool declares the permission it requires. Before running, the base
checks `Rbac::userCan($call->actingUser, $perm)`. Denied → returns
`{"error": "no_permission"}` and the assistant says "That's above your access
level" instead of acting. Owner/untagged sessions pass everything (existing
backward-compat behaviour in `Rbac`).

Permission mapping (reusing `App\Support\PermissionCatalog`):

| Domain           | Read perm             | Write perm                                                   |
|------------------|-----------------------|--------------------------------------------------------------|
| Reports          | `reports.view`        | —                                                            |
| Bookings         | `bookings.view`       | `bookings.create` / `bookings.update` / `bookings.delete`    |
| Services         | `services.view`       | `services.manage`                                           |
| Categories       | `services.view`       | `services.manage`                                           |
| Staff            | `staff.view`          | `staff.manage`                                              |
| Working hours    | `working_hours.view`  | `working_hours.manage`                                     |
| Customers        | `customers.view`      | `customers.manage`                                         |
| Users & roles    | `users.view` / `roles.view` | `users.manage` / `roles.manage`                       |
| Business profile | —                     | `settings.manage`                                          |

Cancel = a status change → `bookings.update`. Physical record removal
(`delete_booking`) → `bookings.delete`.

## Tool catalog

### ReportTools (read, `reports.view`) — existing, moved verbatim
`get_revenue`, `get_top_services`, `get_staff_performance`, `get_busy_times`,
`get_bookings`.

### BookingTools
- `find_booking` (view) — locate by reference or customer + date/time.
- `create_booking` (create) — customer, service, staff, date, time.
- `reschedule_booking` (update) — change date/time.
- `update_booking_status` (update) — booked/completed/cancelled/queued.
- `cancel_booking` (update) — set status cancelled.
- `delete_booking` (delete) — remove the record.

### ServiceTools (`services.view` / `services.manage`)
- `list_services`
- `create_service` — title, price, duration, category, description.
- `update_service` — price / title / duration / category / active.
- `delete_service`

### CategoryTools (`services.view` / `services.manage`)
- `list_categories`, `create_category`, `rename_category`, `delete_category`

### StaffTools (`staff.view` / `staff.manage`)
- `list_staff`, `create_staff`, `update_staff`, `delete_staff`

### HoursTools (`working_hours.view` / `working_hours.manage`)
- `list_hours`
- `set_hours` — one weekday open/close.
- `close_day` — mark a weekday closed.

### CustomerTools (`customers.view` / `customers.manage`)
- `find_customer`, `create_customer`, `update_customer`, `delete_customer`

### AccessTools (`users.view` / `users.manage` / `roles.view` / `roles.manage`)
- `list_users`, `create_user` (name, PIN, role), `update_user`
  (role / active / reset PIN), `delete_user`
- `list_roles`, `list_permissions` (roles.view — enumerates valid permission
  names + human labels so the model reads them naturally),
  `create_role` (name, permissions[]), `update_role`, `delete_role`

### ProfileTools (`settings.manage`)
- `get_business_profile`, `update_business_profile` (name, phone, address, etc.)

## Fuzzy-name resolution

Voice input is loose ("cancel Sarah's 3 o'clock", "put the men's haircut up to
60", "give Ali Fridays off"). Each write tool resolves loose input to a real
record before previewing:

- **Services / staff / categories / customers** — case-insensitive partial
  match by name/title.
- **Bookings** — by reference, or customer name + date/time.
- **Days** — "Friday" → weekday index; **times** — "3pm", "half past 2" →
  `HH:MM`; **dates** — "today", "this Friday", "the 20th" → real date.

Three outcomes, handled uniformly in `MutatingTool` so every tool behaves the
same:

- **One match** → proceed to the preview gate.
- **Multiple matches** → `{"ambiguous": true, "matches": [...]}`; assistant asks
  which one; nothing written.
- **No match** → `{"error": "not_found"}`; assistant says it couldn't find it.

## System prompt (`AssistantPrompt`)

Extend the existing prompt to teach:
- **Collect-then-confirm:** if a create/update is missing a required field, ask
  for it (one at a time), then read the whole thing back and wait for "yes"
  before calling with `confirmed: true`.
- **Plain language:** days by name, permissions by their labels, money and times
  spoken naturally.
- **Relay tool outcomes:** `no_permission` → "that's above your access level";
  `ambiguous` → ask which; `not_found` → say so; never invent success.
- Preserve the existing short, spoken-friendly reply style.

## Frontend

No structural change — the mic page already supports the multi-turn text+voice
exchange the confirm flow needs. Copy tweaks only:
- Header subtitle → "Ask about your business — or tell me to change something".
- Empty-state example gains an action prompt, e.g. "Cancel Sarah's 3 o'clock".

FAB, routing, and audio playback are unchanged.

## Rollout & safety

- **Kill-switch:** config flag `assistant.mutations_enabled` (default `true`).
  When off, the registry omits all mutating tool defs and the assistant is
  read-only — no redeploy needed to disable.
- Deploy the branch; user runs the PHP suite (local/CI).
- Verify against a **throwaway test shop** (never real customer data) —
  exercise each action group once before trusting it in production.
- Every tool remains scoped to the authenticated shop (unchanged) — no
  cross-tenant risk.

## Testing (TDD)

Written before implementation, one focused feature-test file per module:

- **RBAC:** a user lacking the perm gets `no_permission` and no data changes.
- **Confirm gate (the key invariant):** `confirmed:false` returns a preview and
  writes nothing; `confirmed:true` performs the write. One test per mutating
  tool.
- **Resolution:** one-match proceeds; multi-match → `ambiguous`; no-match →
  `not_found`.
- **Registry:** aggregates all module defs; routes each tool to its owner;
  unknown tool → error.
- **Kill-switch:** with `assistant.mutations_enabled=false`, mutating tools are
  absent from `defs()`.
- **Regression:** existing `OwnerAssistant*Test` suites stay green after read
  tools move into `ReportTools`.

## Open questions

None outstanding — all scope, safety, permission, roles, and testing decisions
resolved during brainstorming.
