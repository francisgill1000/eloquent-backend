# Lead Assignment & the Agent Flow — Design

**Date:** 2026-07-24
**Product:** Business Hunt (`leads` module)
**Status:** Design approved, awaiting implementation plan

## Problem

Business Hunt has no concept of a lead's owner. Every lead is shop-wide: anyone
holding `leads.view` sees all of them, and nothing routes work to a person. A
shop with more than one salesperson cannot answer "who is working this lead",
cannot stop two agents calling the same business, and cannot hand out a batch of
200 search results.

`lead_activities.user_id` already records *who acted*, so there is an audit
trail — but no ownership.

## Goals

Three layers, built in order, one design:

1. **Ownership** — every lead has a named owner; assignment is visible and logged.
2. **Isolation** — a plain agent sees *only* the leads assigned to them. Not a
   default filter: a wall. Unassigned leads are invisible to agents.
3. **Distribution** — a manager hands out leads in bulk, and an optional
   per-shop round-robin auto-spreads newly saved leads across the team.

## Non-goals

- Multiple agents per lead (no pivot table). One owner, or none.
- Territories, quotas, commission calculation, or agent-level credit budgets.
- Reassignment approval workflows.
- Changing the fixed funnel (`Lead::STATUSES`) or the credit model.

---

## Architecture

### Data model

**Migration — `add_assignment_to_leads_table`**

| Column | Type | Notes |
|---|---|---|
| `assigned_to_id` | nullable FK → `shop_users` | `nullOnDelete` — deleting an agent returns their leads to the pool rather than orphaning them |
| `assigned_at` | nullable timestamp | when the current assignment was made |

Index: `(shop_id, assigned_to_id, status)` — the shape every scoped query uses.

**Migration — `add_lead_auto_assign_to_shops`**

| Column | Type | Notes |
|---|---|---|
| `lead_auto_assign` | boolean, default `false` | round-robin off unless a shop turns it on |
| `lead_assign_cursor` | nullable unsigned bigint | `shop_users.id` that last received a round-robin lead |

**Migration — `backfill_leads_view_all`** (see Backward compatibility).

### Permissions

Two new entries in the `hunt` group of `PermissionCatalog::grouped()`:

```php
'leads.view_all' => 'See every lead, not just their own',
'leads.assign'   => 'Assign leads to other users',
```

`leads.view_all` is a *widening* permission — its absence is what makes someone
an agent. This is deliberate: it keeps the existing four Hunt permissions
meaning exactly what they mean today.

### The single authority

```php
Rbac::seesAllLeads(?ShopUser $user): bool
```

True when the user is `null` (untagged legacy token — already owner-equivalent
in `Rbac`), holds the `Owner` role, or holds `leads.view_all`. Every consumer
asks this function; nobody re-derives it.

### Backward compatibility (mandatory)

Every existing lead is unassigned. Without a backfill, the deploy would empty
the Hunt screen for every non-owner user in every shop. The backfill migration
grants `leads.view_all` to **every existing role that already holds
`leads.view`**. Behaviour on prod is unchanged on day one; removing
`leads.view_all` from a role is the deliberate act that turns an employee into
an agent.

---

## Layer 1 — Ownership

One model method owns the write so the web controller and the voice tool cannot
drift (the same pattern as `Lead::applyWonDeal`):

```php
Lead::assignTo(?ShopUser $target, ?ShopUser $actor): void
```

Sets `assigned_to_id` and `assigned_at`, and writes a `LeadActivity` of new type
`LeadActivity::TYPE_ASSIGNED` with payload `{from_id, from_name, to_id, to_name}`
so reassignment appears in the timeline the detail page already renders. It does
not save the lead — the caller owns the transaction.

### Endpoints

Both gated `can.perm:leads.assign`, inside the existing
`auth:sanctum + rbac.context + module:leads` group:

- `PATCH /shop/leads/{lead}/assign` — body `{assigned_to_id: int|null}`
- `POST /shop/leads/assign` — body `{ids: int[], assigned_to_id: int|null}`

Both static-path routes (`/shop/leads/assign`, `/shop/leads/settings`) must be
declared **before** the `{lead}` routes, per the ordering note already in
`routes/api.php` — otherwise `{lead}` swallows them.

`assigned_to_id` is validated as an **active `ShopUser` belonging to the
authenticated shop**, resolved server-side. The shop id is never taken from the
request body. `null` unassigns, returning the lead to the pool.

Explicit assignment may target anyone active, including an `Owner` — only the
automatic rotation excludes owners.

### Read surface

`LeadController@index` gains `?assigned_to=me|unassigned|<id>`. The response
includes each lead's `assigned_to` as `{id, name, is_active}` so the UI can
render an owner chip and mark leads stranded on a deactivated user.

### UI

- `LeadDetail.tsx` — an "Assigned to" row: a picker for holders of
  `leads.assign`, plain text otherwise.
- `Leads.tsx` — an owner chip (initials) on each row; checkbox multi-select with
  an "Assign to…" action bar, shown only to holders of `leads.assign`.
- Filter chips for **My leads** / **Unassigned** alongside the existing status
  and pipeline filters.

---

## Layer 2 — Isolation

A global scope on the `Lead` model, `AssignedLeadScope`, appends
`where assigned_to_id = <me>` whenever `Rbac::seesAllLeads()` is false.

Chosen over explicit per-call-site filtering because there are ~12 query sites
today across `LeadController`, `HuntTools`, `HuntReadTools` and
`ReportsAggregator`, and the failure mode of a missed site is silent cross-agent
data exposure. The RBAC audit of 2026-07-24 found nine findings of exactly that
class (routes and tools that were never gated). A global scope cannot be
forgotten by future code.

Because it is global, an agent's funnel counts, due-follow-up badge, won-value
total, Hunt voice answers and reports all narrow to that agent with no
additional code.

### The one bypass

`LeadImporter` runs `withoutGlobalScope(AssignedLeadScope::class)`.

It dedupes on `(shop_id, external_ref)` via `firstOrNew`, and recovers from a
concurrent duplicate insert with `->firstOrFail()`. Under the scope, an agent
re-saving a business another agent already owns would: fail to find it → INSERT →
violate `unique(shop_id, external_ref)` → enter recovery → `firstOrFail()` finds
nothing (the row is not theirs) → **uncaught 500**. The importer must see the
whole shop.

Everywhere else stays scoped on purpose — including the assign endpoints, so a
manager can only hand out leads they can already see.

### Two rules that keep it from biting

1. **Self-assign on save.** When a user for whom `seesAllLeads()` is false saves
   search results, `LeadImporter` stamps the new leads to that user. You never
   lose a lead you just found — and it never silently vanishes at the moment of
   creation. This takes priority over round-robin.
2. **No acting user, no filter.** When `current_shop_user()` is null (scheduled
   jobs, console commands), the scope does not apply — consistent with how
   `Rbac` already treats untagged sessions.

### Deactivated and deleted agents

Deactivating a user does **not** reassign their leads; the leads keep the owner
and the manager sees the chip marked inactive so they can reassign deliberately.
Deleting a user nulls `assigned_to_id` via the FK, returning the leads to the
unassigned pool where the manager can see and redistribute them.

---

## Layer 3 — Distribution

### Bulk assign

Covered by `POST /shop/leads/assign` and the multi-select action bar in Layer 1.

### Round-robin

Off by default per shop. A `LeadAssigner` service is called by `LeadImporter`
after each lead is created, and acts only when **all** of:

- `shop.lead_auto_assign` is true, **and**
- the lead was newly created (`wasRecentlyCreated`), **and**
- the lead is still unassigned after the self-assign rule.

So a manager's bulk search distributes across the team, while an agent's own
find stays theirs.

**Pool:** active `ShopUser`s of the shop that do not hold the `Owner` role,
ordered by `id`. Users holding no `leads.view` at all are skipped — they would
otherwise be handed leads they cannot open. (Decision: Francis, 2026-07-24 —
"every shop user will get leads, keep admin/main/owner out of this", with the
no-Hunt-access exclusion added as the sane reading.)

**Fairness:** the pool is ordered by id; the cursor advances one position per
lead and wraps. Reading and writing `shops.lead_assign_cursor` happens under a
`lockForUpdate` on the shop row — the same race guard used for the Ziina
webhook. Importing 60 businesses spreads them evenly, and the next import
resumes where the last one stopped.

**Empty pool:** the lead stays unassigned and visible to the owner. No error.

### Control

The auto-assign switch lives on the **Leads page header**, visible to holders of
`leads.assign` — not in Settings. Hunt behaviour belongs to Hunt, and the RBAC
audit specifically flagged Settings as a privilege-escalation surface; adding a
new toggle there would widen it again. Persisted via a small
`PATCH /shop/leads/settings` gated `can.perm:leads.assign`.

---

## Voice assistant

`HuntTools` gains one tool, registered only when the caller holds
`leads.assign`:

- `assign_lead(lead: string, assignee: string)` — resolves the lead by name or
  id and the assignee by name within the shop, then calls `Lead::assignTo`.
  Ambiguous or unknown names return a disambiguation error rather than guessing;
  numbers and identities are resolved in code, never by the model (see the
  `LLM never validates numbers` rule).

`HuntReadTools` needs no change — the global scope already narrows "how many
leads do I have", "what's due today" and the funnel to the acting agent.

## Reports

`ReportsAggregator` gains a per-agent breakdown — leads held, worked, won count,
won value — computed only for users where `seesAllLeads()` is true. For an agent
the existing figures already mean "mine" thanks to the scope, so no separate
agent-facing view is needed.

---

## Testing

Feature tests, run on the droplet against a scratch database (never prod):

**Scoping**
- Agent A sees only their leads; agent B's leads and unassigned leads are absent
  from index, show (404), funnel counts, won value and due-follow-ups.
- Owner sees everything including unassigned.
- A role holding `leads.view_all` sees everything.
- Untagged token (null shop user) sees everything — legacy compatibility.
- Cross-shop: an agent never sees another shop's leads regardless of assignment.

**Importer**
- Agent re-saving a business owned by another agent updates the existing row and
  does not 500 (the regression this design exists to prevent).
- Agent saving new search results gets them auto-assigned to themselves.

**Assignment**
- `leads.assign` required for both endpoints; 403 without it.
- Cannot assign to a shop user of another shop, nor to an inactive user.
- Assigning writes a `LeadActivity` of type `assigned` with from/to.
- `null` unassigns.
- A manager without `leads.view_all` can only assign leads they own.

**Round-robin**
- Off by default: leads stay unassigned.
- On: 6 leads across 3 eligible users → 2 each; cursor persists across imports.
- Owner excluded; inactive users excluded; users without `leads.view` excluded.
- Empty pool leaves leads unassigned without error.
- Self-assign wins over rotation.

**Backfill**
- Every pre-existing role holding `leads.view` gains `leads.view_all`; visibility
  on existing shops is unchanged after migration.

## Rollout

Local → staging → prod, per the standing rule. Deploy is a no-op for existing
shops by construction (the backfill), so the feature switches on per shop when a
role has `leads.view_all` removed. Admin frontend ships with `admin/deploy.ps1`.
