# Product-aware voice assistant + Business Hunt tools

**Date:** 2026-07-11
**Status:** Approved design, pending implementation plan

## Problem

The owner voice/text assistant is hardcoded to the bookings (Lens) product:

- `AssistantToolRegistry` aggregates only bookings-domain tool modules and gates
  them **only** by the global `assistant.mutations_enabled` kill-switch — never by
  the shop's `modules` column.
- `AssistantPrompt::for($shop)` emits a single hardcoded prompt that claims the
  assistant manages bookings, services, staff, hours, etc.

A `leads`-only shop (Business Hunt) therefore reaches the assistant with a prompt
that falsely claims booking control, a full set of booking tools operating on
empty data, and **zero** Hunt capability. The `Shop::hasModule()` method exists
(modules: `bookings`, `leads`) but nothing in the assistant path consults it.

## Goal

Make the assistant product-aware:

1. Gate every assistant tool module by the shop's enabled modules.
2. Add a real `HuntTools` module so a `leads` shop's assistant can do Hunt work.
3. Compose the system prompt from per-module sections so the text matches the
   shop's actual capabilities.

Master (`is_master`) shops keep every module, as they do everywhere else.

## Non-goals

- No changes to the Hunt web UI, billing, Ziina flow, or credit economics.
- No new RBAC permissions for leads (leads is module-gated, not permission-gated,
  matching the existing `/leads` routes under `ModuleGuard`).

## Architecture

### 1. Module gating in the registry

Add a module tag to the tool-module contract:

- `AssistantToolModule` interface gains `moduleKey(): ?string`.
- `AssistantModule` base implements it returning `null` (universal — always on).
- Modules override it:
  - **`'bookings'`**: `OwnerAssistantTools` (legacy revenue/bookings analytics
    read tools), `BookingTools`, `ServiceTools`, `CategoryTools`, `StaffTools`,
    `HoursTools`, `CustomerTools`.
  - **`null` (universal)**: `ProfileTools` (business profile), `AccessTools`
    (users/roles). These apply to any shop regardless of product.
  - **`'leads'`**: new `HuntTools`.

Registry filtering becomes shop-aware:

```php
protected function activeModules(?Shop $shop): array
{
    $mutationsOn = (bool) config('assistant.mutations_enabled', true);

    return array_values(array_filter($this->modules(), function (AssistantToolModule $m) use ($mutationsOn, $shop) {
        if (! $mutationsOn && $m instanceof MutatingTool) {
            return false;                       // global kill-switch (unchanged)
        }
        $key = $m->moduleKey();
        if ($key === null || $shop === null || $shop->is_master) {
            return true;                        // universal, unscoped, or master
        }
        return $shop->hasModule($key);          // product gate
    }));
}
```

- `defs(?Shop $shop = null)` gains the optional shop. `null` keeps the current
  "all modules" behaviour so the existing `AssistantToolRegistryTest` (which calls
  `defs()` with no arg) stays green.
- `execute(Shop $shop, ...)` already receives the shop; it now filters through
  `activeModules($shop)`, so a tool from a module the shop lacks returns
  `unknown_tool` instead of running.
- `OwnerAssistantController::respond()` passes `$shop` into `defs($shop)`.

### 2. New `HuntTools` module

`App\Services\Assistant\Modules\HuntTools`, `moduleKey() => 'leads'`.
All tools map to permission `null` (no RBAC — module-gated only).

Dependencies (constructor-injected): `HuntCreditService`, `LeadSearchService`,
`SearchInterpreter`, `LeadImporter` (see §4), `AssistantActions` (for navigate).

**Read tools (no confirm):**

| Tool | Behaviour |
|------|-----------|
| `hunt_credits` | Returns `{credits: N}` from `HuntCreditService::balance`. |
| `list_leads` | Funnel counts per status (`new,sent,replied,demo,won,pass`) + optional `status` filter returning up to ~8 lead names. Mirrors `LeadController::index`. |
| `find_lead` | Resolve one lead by fuzzy name (`LIKE`) within the shop. Multiple → `ambiguous` (names list); none → `not_found`. Returns name, status, phone/whatsapp, category, last_contacted. |
| `open_lead` | Resolve by name, then `AssistantActions::navigate("/leads/{id}")`. Same pattern as `open_booking`. |

**Mutating tools (confirm gate via `MutatingTool::gate`):**

| Tool | Behaviour |
|------|-----------|
| `search_businesses` | Inputs `category` (required), `area` (optional). **Preview** warns "This runs a live search that uses 1 credit — you have N (a repeat of a recent search is free)." **Write** runs `SearchInterpreter::interpret` then `LeadSearchService::search`; catches `InsufficientCredits` and returns `{error: 'insufficient_credits', credits}` for the model to relay; on success returns `{count, from_cache, credits_left, sample: [up to 5 names]}`. |
| `save_leads` | Inputs `category` + `area` of a just-run search. **Resolve** re-runs `LeadSearchService::search` — a repeat is a free cache hit, so this spends **no** credit — to recover the same result set; empty → `not_found`. **Preview** "Save all N {category} businesses in {area} to your leads." **Write** persists via `LeadImporter`, returns `{saved, created}`. |
| `update_lead_status` | Resolve lead by fuzzy name (→ `ambiguous`/`not_found`). **Preview** "Move {name} from {old} to {new}." **Write** sets status, bumps `last_contacted_at`, and logs a `status_change` activity — inline in `HuntTools`, mirroring `LeadController::updateStatus` (a status change is not an import, so it does not use `LeadImporter`). Rejects status values outside `Lead::STATUSES`. |

`save_leads` re-running the search to recover results (instead of threading the
prior result set through conversation state) is deliberate: the query cache makes
the repeat free and instant, and it keeps the tool stateless.

### 3. Product-aware prompt

`AssistantPrompt::for($shop)` composes from sections:

- **Shared header** — identity (`"{shop->name}"`), today's date, currency
  ("dirhams"), the language rule, the voice-note brevity rules, and the universal
  **MAKING CHANGES** confirm-gate block (it governs any mutating tool, booking or
  lead).
- **Bookings section** (only if `hasModule('bookings')` or `is_master`) — services
  and staff lists, the booking-specific rules, and the `open_booking` rule.
- **Hunt section** (only if `hasModule('leads')` or `is_master`) — explains it can
  search for businesses (costs a credit), save results to the pipeline, report the
  funnel/pipeline, find a lead's status, move a lead through the funnel, check the
  credit balance, and open a lead's page (`open_lead`).
- **Shared closing** — end every reply with a short question.

A `bookings+leads` shop gets both middle sections; a `leads`-only shop gets header
+ Hunt + closing, with no booking claims. The services/staff DB reads move inside
the bookings section so a leads-only shop never runs them.

Refactor note: extract the section bodies into private methods
(`sharedHeader`, `bookingsSection`, `huntSection`, `sharedClosing`) so the compose
step reads as a list of conditional appends.

### 4. `LeadImporter` service (shared persistence)

Extract the dedupe-on-`(shop_id, external_ref)` persistence loop currently inline
in `LeadController::store()` into `App\Services\Leads\LeadImporter`:

```php
class LeadImporter {
    /** @return array{saved: array<Lead>, created: int} */
    public function import(Shop $shop, array $rows): array
}
```

`LeadController::store()` is refactored to call it (behaviour-preserving), and
`HuntTools::save_leads` calls the same method — one dedupe path, no divergence.
Guarded by the existing lead controller/store feature tests.

## Testing

- **`AssistantPromptTest`** (extend): leads-only shop prompt contains Hunt
  language and omits booking-management claims; a bookings-only shop is unchanged
  (existing assertion holds); a `bookings+leads` shop contains both sections.
- **`AssistantToolRegistryTest`** (extend): a `leads`-only shop's `defs($shop)`
  excludes booking tools and includes `search_businesses`/`list_leads`; a
  `bookings`-only shop excludes Hunt tools; a master shop gets both; universal
  tools (profile/access) appear for every shop.
- **`HuntToolsTest`** (new unit): `search_businesses` preview vs `done` gate;
  credit spend and `from_cache`/`credits_left` shape via a mocked
  `LeadSearchService`; `insufficient_credits` relayed not thrown; `save_leads`
  persists via `LeadImporter` and reports `created`; `update_lead_status` moves the
  funnel and writes an activity row; `find_lead`/`open_lead` resolve, `ambiguous`,
  and `not_found`.
- **`LeadImporter`**: covered transitively by the refactored `store()` tests; add a
  direct test if the extraction exposes new branches.

Tests run on the droplet (php8.4) against a scratch DB — never local, never prod.

## Files

**New:** `app/Services/Assistant/Modules/HuntTools.php`,
`app/Services/Leads/LeadImporter.php`, `tests/Unit/HuntToolsTest.php`.

**Modified:** `app/Services/Assistant/Contracts/AssistantToolModule.php`,
`app/Services/Assistant/Support/AssistantModule.php`,
the seven bookings-domain modules + `ProfileTools`/`AccessTools` (add `moduleKey`),
`app/Services/Assistant/AssistantToolRegistry.php`,
`app/Http/Controllers/OwnerAssistantController.php`,
`app/Support/Assistant/AssistantPrompt.php`,
`app/Http/Controllers/LeadController.php` (use `LeadImporter`),
plus the three test files.
