# RBAC Audit — Business Hunt module

**Date:** 2026-07-24
**Scope:** Role-based access control for a Business Hunt shop (`modules: ['leads']`), audited against the requirement *"RBAC should be based on the left-side menu — one grantable user per menu item."*
**Method:** static audit of the permission catalog, API routes, middleware, assistant tool modules, and the admin SPA nav/route guards.

**Status: ALL FINDINGS FIXED (2026-07-24), deployed to staging + production.** The audit below is preserved as written; each finding now carries a **FIXED** note describing the change. See §8 for the verification run and §9 for three issues discovered *while* fixing (all also fixed).

---

## 1. Summary

Business Hunt's own REST surface is the best-gated area in the app — all 11 lead routes carry a `can.perm` middleware and the view/search/manage/purchase split is correct. The problems are at the edges of the Hunt menu, not inside it:

- **Settings cannot be granted per-menu at all** — it is a single all-or-nothing toggle that bundles user and role management, which is a privilege-escalation path.
- **Three permissions in the catalog are enforced nowhere on the backend** (`chats.view`, `profile.view`, `assistant.manage`), and a fourth (`settings.manage`) is enforced only in the assistant tool, not on its REST route.
- **The SPA has no route-level permission guard**, so hiding a menu item does not deny access to its page.

Net: 4 of the 6 left-menu items for a Hunt shop are cleanly separable *and* enforced. Settings is not separable. Chats and Profile are separable in the menu but not enforced in the API.

**Post-fix:** all 6 are separable and enforced end to end — catalog, roles UI, API route, and SPA route + controls. Every Settings page is now its own grant, so "one user per left menu" is fully expressible.

---

## 2. Left menu → permission map (Hunt-only shop)

A shop with `modules: ['leads']` renders 6 sidebar items (`admin/src/layout/DesktopSidebar.tsx:20-34`); the bookings-only items (Bookings, Customers) are filtered out by `navVisible`.

Verdicts below are **before → after** the fixes.

| # | Left menu | Route | Permission | Isolatable? | Backend enforced? |
|---|---|---|---|---|---|
| 1 | AI Summary | `/ai-summary` | `summary.view` | Yes | Yes — `routes/api.php:130` |
| 2 | Home (Ask) | `/` | `assistant.use` | Yes | Yes — `routes/api.php:254-255` |
| 3 | Chats | `/conversations` | `chats.view` | Yes | ~~No~~ → **Yes** (F4 fixed) |
| 4 | Business Hunt | `/leads` | `leads.view`, `.search`, `.manage`, `.purchase` | Yes | Yes — `routes/api.php:266-277` |
| 5 | Settings | `/settings` | *(per-page groups)* | ~~No~~ → **Yes** (F1 fixed) | Yes |
| 5a | ↳ AI Assistant | `/assistant` | `assistant.manage` | **Yes** | ~~No~~ → **Yes** (F2 fixed) |
| 5b | ↳ Access Control | `/settings/access` | `users.*`, `roles.*` | **Yes** | Yes — `routes/api.php:311-321` |
| 5c | ↳ Business settings | `/settings` | `settings.manage` | **Yes** | ~~Partial~~ → **Yes** (F3 + §9.1 fixed) |
| 6 | Profile | `/profile` | `profile.view` | Yes | ~~No~~ → **Yes** (F3 fixed) |

Catalog source: `app/Support/PermissionCatalog.php:41-108`. Module filtering for the roles screen: `PermissionCatalog::forShop()` (line 120), exercised by `tests/Feature/HuntPermissionsTest.php:79`.

---

## 3. Findings

### F1 — HIGH — Settings is one all-or-nothing toggle that bundles user + role management

`PermissionCatalog.php:96-107` tags `assistant_config`, `access` and `settings` with `section: 'Settings'`. The roles editor collapses an entire section into a **single checkbox** that sets or clears every child permission:

```
admin/src/pages/AccessControl.tsx:442-456
  const perms = groupsInSection.flatMap(([, g]) => Object.keys(g.permissions));
  ... <input type="checkbox" checked={allOn} onChange={() => toggleGroup(perms, allOn)} />
      <span>Manage all {section} pages ...
```

For a Hunt shop that section contains exactly: `assistant.manage`, `users.view`, `users.manage`, `roles.view`, `roles.manage`, `settings.manage`.

**Impact.** There is no way to create a Hunt user who can reach AI Assistant config or business settings without also giving them `users.manage` + `roles.manage`. Such a user can create new users and edit any role — including granting themselves every permission in the catalog. This is a privilege-escalation path, and it is precisely where the "one user per left menu" model breaks.

**FIXED.** `AccessControl.tsx` now renders a section as a grouping band — header + convenience select-all — with **each page beneath it as its own nested permission group**. The aggregate "Manage all Settings pages" toggle is gone, so AI Assistant, Users & Roles and Business settings are three independent grants. New `.ac-matrix-section` styling in `access.css`. Covered by two tests in `AccessControl.test.tsx`, including one asserting that granting `assistant.manage` does not carry `users.manage`/`roles.manage`.

---

### F2 — HIGH — `assistant.manage` is enforced nowhere

The AI Assistant settings page is backed by `ShopPersonaController`, whose routes carry only `auth:sanctum`:

```
routes/api.php:227-229
  Route::get ('/shop/persona',          [ShopPersonaController::class, 'show']);
  Route::put ('/shop/persona',          [ShopPersonaController::class, 'update']);
  Route::get ('/shop/persona/generate', [ShopPersonaController::class, 'generate']);
```

The controller's only guard is "is this a Shop token" (`ShopPersonaController.php:69`). A grep for `assistant.manage` across `app/` and `routes/` returns **only** the catalog definition — no enforcement point exists.

**Impact.** A staff user granted nothing but `leads.view` can read and completely rewrite the assistant persona, which drives what the AI says to customers. `/shop/persona/generate` also costs a Claude call.

**FIXED.** The three persona routes moved into their own group with `['auth:sanctum','rbac.context','can.perm:assistant.manage']`. `/shop/simulation` (also a Settings page) moved into a `can.perm:settings.manage` group at the same time. Covered by `test_assistant_manage_gates_the_persona_routes`.

---

### F3 — MEDIUM — `profile.view` and `settings.manage` are not enforced on the REST path

`ShopController::update` gates **only** the working-hours sync:

```
app/Http/Controllers/ShopController.php:263-274
  // Editing working hours is its own grantable permission ...
  // Profile fields below are not gated (unchanged).
  if (is_array($request->validated()['working_hours'] ?? null)) {
      abort_unless(Rbac::userCan(current_shop_user(), 'working_hours.manage'), 403, ...);
  }
```

The route is `PUT /shops/{shop}` behind `['auth:sanctum','rbac.context','shop.self']` (`routes/api.php:47-50`) — tenant-scoped, but not permission-scoped.

- `profile.view` has **zero** enforcement points anywhere.
- `settings.manage` is enforced only inside the assistant tool module (`app/Services/Assistant/Modules/ProfileTools.php:16-17`), never on the HTTP route.

**Impact.** Any authenticated staff user of a Hunt shop can change the business name, logo, hero image, address and settings via the REST API, regardless of role. The in-code comment shows this was a known carry-over, not an oversight — but it means two menu items in the catalog are decorative.

**FIXED.** `ShopController::update` now partitions the payload by which left-menu page owns each field, and checks a permission per group: `working_hours` → `working_hours.manage` (unchanged), the booking-notification fields (new `SETTINGS_FIELDS` const) → `settings.manage`, every other shop field → `profile.view`. Covered by `test_profile_view_gates_business_profile_writes`, `test_settings_manage_does_not_unlock_profile_fields`, `test_settings_manage_gates_notification_settings_writes`, and a regression guard that `profile.view` is not a backdoor into the working-hours sync. The `settings.manage` branch is live — see §9.1, which fixed the validation bug that had made those fields unwritable.

---

### F4 — MEDIUM — `chats.view` is cosmetic; conversation transcripts are readable without it

```
routes/api.php:245-256
  Route::middleware(['auth:sanctum','rbac.context','subscription.active'])->group(function () {
      Route::get   ('/shop/assistant/conversations',                [OwnerAssistantController::class, 'conversations']);
      Route::get   ('/shop/assistant/conversations/{conversation}', [OwnerAssistantController::class, 'messages']);
      Route::patch ('/shop/assistant/conversations/{conversation}', [...'rename']);
      Route::delete('/shop/assistant/conversations/{conversation}', [...'destroy']);
      // ... "Reading past conversations (Chats menu) stays open to any authed shop user so
      //      the Home page never partially 403s; the Chats menu itself is hidden client-side
      //      for users without chats.view."
```

The comment documents this as deliberate — the goal was to stop the Home page partially 403-ing.

**Impact.** For a Hunt shop, Ask transcripts contain lead names and phone numbers, credit balances, and won-deal revenue figures. A user whose Chats menu is hidden can still `GET /shop/assistant/conversations` and read all of it — and `rename`/`destroy` are open too, so they can delete the shop's chat history.

**FIXED.** All four conversation routes now carry `can.perm:chats.view`. The stated motivation turned out to be moot: `listConversations` is called only by `Conversations.tsx` (the Chats page) — `VoiceAssistant.tsx` no longer reads it, only its test file still mocks it — so the Home screen cannot partially 403. Covered by `test_chats_view_gates_the_conversation_routes`.

---

### F5 — MEDIUM — the admin SPA has no route-level permission guard

Routes are wrapped in `RequireShop` → `RequireSubscription` → `ModuleGuard`, but never a permission guard:

```
admin/src/App.tsx:90-94
  <Route element={<ModuleGuard module="leads" />}>
    <Route path="/leads"         element={<Leads />} />
    <Route path="/leads/credits" element={<LeadCredits />} />
    <Route path="/leads/:id"     element={<LeadDetail />} />
  </Route>
```

Permission checks exist only in the nav layer (`admin/src/lib/nav.ts:20-22`, `DesktopSidebar.tsx:45`).

**Impact.** Typing `/leads` directly renders the page shell for a user without `leads.view`; only the data fetches 403. Same for `/settings/access`, `/assistant`, `/profile`. For the Hunt REST routes this is only cosmetic because the API is gated — but for the endpoints in F2/F3/F4 the page actually works. Also: no page-level `can()` checks exist inside `Leads.tsx`, `LeadDetail.tsx` or `LeadCredits.tsx`, so a `leads.view`-only user is shown Search and Buy Credits buttons that 403 on click.

**FIXED, two parts.**

*Route level* — new `admin/src/components/RequirePerm.tsx` gate, applied in `App.tsx` to `/leads*`, `/settings/access`, `/settings/simulation`, `/settings/notifications`, `/assistant`, `/working-hours`, `/services*`, `/categories*`, `/staff*`, `/customers*`, `/insights`, `/bookings`, `/reminders`, `/conversations`, `/ai-summary`, `/profile` and `/` (Ask). A denied user goes to `firstAccessiblePath` rather than Home (Home is itself gated); if they can see nothing at all it renders a "No access" dead end instead of looping. Five tests in `RequirePerm.test.tsx`.

*Control level* — `Leads.tsx` gates the search input/button on `leads.search`, the select-all + per-card checkboxes + save-to-pipeline bar on `leads.manage`, and the Buy/Top-up links on `leads.purchase`. `LeadDetail.tsx` derives `locked = busy || !can('leads.manage')` and applies it to every write control plus the drag-to-set funnel switch, with guards at each action entry point. `LeadCredits.tsx` ANDs the server's Ziina eligibility with `leads.purchase`. Two tests in `LeadDetail.test.tsx`.

---

### F6 — LOW — assistant tool definitions are not permission-filtered

`AssistantToolRegistry::defs()` filters modules by the kill-switch and the product module, but never by the acting user's permissions:

```
app/Services/Assistant/AssistantToolRegistry.php:47-79
  protected function activeModules(?Shop $shop): array { ... module gate only ... }
  public function defs(?Shop $shop = null): array { ...no permission filter... }
```

Enforcement happens later, at `AssistantModule::run()` (`AssistantModule.php:25-32`), which returns `['error' => 'no_permission']`.

**Impact.** Security is intact, UX is not: a `leads.view`-only user is still offered `search_businesses` and `save_leads`, so the model proposes them and the conversation dead-ends on a permission error.

**FIXED.** `AssistantToolModule` gained `visibleToolDefs(?ShopUser)`; `AssistantModule` implements it by filtering `toolDefs()` through the tool→permission map, and `AssistantToolRegistry::defs()` now takes an optional acting user (defaulting to `current_shop_user()`) and calls it. A null user stays all-allowed, matching `Rbac`. Covered by `test_tool_defs_are_filtered_by_the_acting_users_permissions`.

---

### F7 — LOW — `draft_outreach` and `personalize` require different permissions for the same capability

| Path | Permission |
|---|---|
| Assistant tool `draft_outreach` | `leads.view` — `HuntReadTools.php:44` |
| REST `POST /shop/leads/{lead}/personalize` | `leads.manage` — `routes/api.php:277` |

Both generate outreach copy for a lead and both cost a Claude call. The voice path is the weaker bar. Pick one — `leads.manage` is the more defensible, since drafting outreach is pipeline work.

**FIXED.** `draft_outreach` moved to `leads.manage` in `HuntReadTools::permissions()`, matching the REST route. Covered by `test_draft_outreach_requires_leads_manage_like_its_rest_twin`.

---

### F8 — LOW — role validation is not module-scoped

```
app/Http/Controllers/RoleController.php:82
  'permissions.*' => [Rule::in(PermissionCatalog::all())],
```

`all()` is the full catalog; `forShop()` (the module-filtered version) is used only by the read-only `/shop/permissions` endpoint (`RbacMeController.php:35`).

**Impact.** A Hunt shop can persist `bookings.*` permissions onto a role via direct API call. Harmless today because `module:bookings` blocks those routes anyway, but it lets meaningless grants accumulate in the DB and would become real if a shop later enables both modules.

**FIXED.** `RoleController::validateData` now takes the `Shop` and builds its `Rule::in` set from `PermissionCatalog::forShop($shop)`. Covered by `test_a_hunt_shop_cannot_grant_bookings_permissions_to_a_role`, plus a positive test that its own permissions still validate.

---

### F9 — NOTE — the Hunt menu sits behind the Lens subscription gate in the SPA

The backend deliberately exempts Hunt from `subscription.active` — Hunt is billed by credits, not by the Lens subscription:

```
routes/api.php:258-265
  // Gated by the `leads` module + the Hunt CREDIT balance — deliberately NOT by
  // `subscription.active`: Hunt is a separate billing meter ...
```

But the SPA wraps every authenticated route, `/leads` included, in `RequireSubscription` (`App.tsx:72`), which redirects to `/subscribe` when `sub.status` is neither `trialing` nor `active` (`admin/src/components/RequireSubscription.tsx`).

**Impact.** Latent, not live: the guard fails open when there is no subscription row at all (`if (sub && ...)`), so a pure Hunt shop with no sub row is fine. A Hunt shop with an *expired* sub row would be locked out of Hunt in the UI while the API would have served it. Not an RBAC bug, but it contradicts the billing design and would block per-user Hunt testing on such a shop.

**FIXED.** `RequireSubscription` now returns early for a shop that has `leads` but not `bookings`, mirroring the backend's deliberate exclusion of the Hunt routes from `subscription.active`.

---

## 4. What is working correctly

- **All 11 Business Hunt REST routes are permission-gated** (`routes/api.php:265-278`), with a sensible split: `leads.view` for reads, `leads.search` isolated because it spends a credit, `leads.purchase` isolated because it spends real money, `leads.manage` for pipeline writes.
- **Assistant Hunt tools cannot be shipped ungated.** `AssistantModule::handles()` derives the tool list from the permission map (`AssistantModule.php:20-23`), so a tool missing from `permissions()` is simply unroutable rather than silently unprotected. Maps: `HuntReadTools.php:38-45`, `HuntTools.php:40-45`.
- **Master routes are protected by an internal check** (`MasterController.php:20`) even though the routes carry only `auth:sanctum` — no cross-tenant leak from a Hunt staff token.
- **The roles screen never mixes products.** `PermissionCatalog::forShop()` filters by enabled module, so a Hunt shop's roles editor shows no Bookings/Customers/Services rows.
- **Owner and legacy tokens bypass cleanly.** `Rbac::userCan()` treats `null` (untagged pre-RBAC token) and the Owner role as all-allowed, and swallows `PermissionDoesNotExist` into a 403 instead of a 500 (`app/Support/Rbac.php`).
- **Staff login now resolves an RBAC identity.** `ShopController::login` (line 212-222) authenticates a `ShopUser` by email+password and writes `shop_user_id` onto the access token; `SetRbacContext` reads it back and sets the spatie team scope. Per-user permission testing is therefore actually possible now — it was not before the email+password migration.

---

## 5. Test coverage

`tests/Feature/HuntPermissionsTest.php` contains 5 tests:

- `test_leads_index_requires_leads_view`
- `test_leads_search_is_blocked_without_leads_search_permission`
- `test_owner_bypasses_lead_permissions`
- `test_permissions_endpoint_is_module_filtered_for_a_hunt_shop`
- `test_hunt_only_shop_without_subscription_can_reach_roles`

**Gaps (all now closed):**
- ~~No test for `leads.manage`~~ → `test_leads_manage_gates_pipeline_writes`.
- ~~No test for `leads.purchase`~~ → `test_leads_purchase_gates_buying_credit_packs`.
- ~~No test that a `leads.view`-only user is denied the ungated surfaces in F2/F3/F4~~ → one test per finding in `MenuPermissionIsolationTest`.
- ~~No "one user per menu" isolation test~~ → `test_one_user_per_left_menu_reaches_only_its_own_section`, a data-provider test that builds a user per menu item and asserts each reaches its own section and 403s on all the others.

New test files: `tests/Feature/MenuPermissionIsolationTest.php` (17 tests), `admin/src/components/RequirePerm.test.tsx` (5 tests). Extended: `HuntAssistantToolsTest.php` (+2), `AccessControl.test.tsx` (+2), `LeadDetail.test.tsx` (+2).

---

## 6. Fix order (all applied)

| Priority | Finding | Fix | Status |
|---|---|---|
| 1 | F1 | Per-group toggles within a section. | ✅ |
| 2 | F2 | `can.perm:assistant.manage` on the three `/shop/persona` routes. | ✅ |
| 3 | F3 | Field-partitioned checks in `ShopController::update`; `profile.view` owns the profile, `settings.manage` owns notification fields. | ✅ |
| 4 | F4 | `can.perm:chats.view` on all four conversation routes (Home never reads them). | ✅ |
| 5 | F5 | `RequirePerm` guard across the app + `can()` checks on every Hunt write control. | ✅ |
| 6 | F7, F8 | `draft_outreach` → `leads.manage`; role validation via `forShop()`. | ✅ |
| 7 | F6, F9 | `defs()` permission-filtered; `RequireSubscription` module-aware. | ✅ |

Test gaps in §5 backfilled, including the per-menu isolation test.

---

## 7. Note on the per-user verification

The original audit was read-only, so no users were created. The verification is now automated instead of manual: `test_one_user_per_left_menu_reaches_only_its_own_section` builds a real `ShopUser` per left-menu item, each with exactly one permission, issues a real Sanctum token carrying `shop_user_id`, and asserts over the API that each user reaches its own section and is refused on every other. That is the check the original request asked for, run on every test run rather than once by hand.

Two things worth knowing if you also want to click through it on staging:

- All 6 menus are now expressible as isolated users, Settings included.
- Check the **API**, not just the sidebar. Before these fixes the two disagreed; the tests now assert the API directly for that reason.

---

## 8. Verification

Backend — `php vendor/bin/phpunit`, in-memory SQLite per `phpunit.xml` (no real DB touched):

```
Tests: 567, Assertions: 1662, Failures: 7
```

All 7 failures are in `PublicBookingAssistantTest` and pre-date this work — they are `cURL error 60: SSL certificate problem` reaching `api.openai.com`, i.e. a missing local CA bundle, not a code fault. Verified against a clean stash: the same 7 fail before any of these changes.

Frontend — `npx vitest run` in `admin/`:

```
Test Files 4 failed | 44 passed (48)
Tests      11 failed | 202 passed (213)
```

All 11 failures pre-date this work (verified against a clean stash: identical list). They are in `DesktopSidebar.test.tsx` (3), `Chats.test.tsx` (3), `LeadDetail.test.tsx` outreach-button block (4) and `Settings.test.tsx` (1). The LeadDetail ones are a query collision — the funnel stage switch renders a button with `aria-label="Follow-up"`, which `getByRole('button', {name: /follow-up/i})` matches alongside the outreach button. Unrelated to RBAC; left alone.

`npx tsc --noEmit` is clean. Net: **+9 frontend tests, +20 backend tests, 0 regressions.**

---

## 9. Issues found while fixing

### 9.1 — Booking-notification settings never saved (FIXED)

`UpdateShopRequest::rules()` did not list `booking_reminders_enabled`, `booking_reminder_template`, `booking_reviews_enabled`, `review_request_template`, `google_review_url`, `waitlist_notify_enabled` or `waitlist_notify_template`. `ShopController::update` fills the model from `$request->validated()`, so those fields were stripped and the write silently no-opped — the Booking notifications page reported success and persisted nothing. (`Shop` has `$guarded = []`, so validation was the only thing blocking them.)

**FIXED.** Rules added for all seven: `sometimes|boolean` for the three toggles, `nullable|string|max:1000` for the three templates, `nullable|url|max:2048` for the Google review link (with a friendly message). `sometimes` matters — it keeps a Profile-page save, which PUTs only its own fields, from dropping notification settings out of `validated()` and wiping them.

This also makes the `settings.manage` branch of F3's field partition live rather than inert: those fields now both persist *and* require `settings.manage`.

Covered by three tests in `MenuPermissionIsolationTest`:
- `test_settings_manage_gates_notification_settings_writes` — `profile.view` is refused, `settings.manage` succeeds, and the values actually land in the DB.
- `test_a_profile_save_does_not_wipe_notification_settings` — a name-only save leaves the notification fields untouched.
- `test_an_invalid_google_review_url_is_rejected` — 422 on a malformed URL.

### 9.2 — Permissions were only ever written at login (fixed)

`ShopContext` stored permissions solely via `setAccess`, called only from `Login.tsx`. There was no `/auth/me` refresh on app boot, so any already-logged-in session carried no stored permissions at all. That was invisible while permissions only hid nav items — but adding route guards on top of it would have locked every existing session out of the entire app on the next deploy.

Fixed in two ways:

- `permissions` is now `string[] | null`, where `null` means *not yet known* and is treated as owner-equivalent. This mirrors the backend, where an untagged token is all-allowed (`Rbac::userCan`). Failing open here is safe — the API is the real gate.
- `ShopProvider` now calls `fetchMe()` on boot and rewrites the cached user + permissions, so the client gate matches the server's and a role edited mid-session takes effect on reload rather than at next login.

Covered by `RequirePerm.test.tsx`'s "fails open for a session whose permissions were never stored".

### 9.3 — A test helper created the Owner role without assigning it (fixed)

`ShopRegistrationTest::actingOwner` created the `Owner` role and a `ShopUser`, but never called `assignRole` — so the "owner" it returned held no role and no permissions. It passed only because the route under test had no permission check. Fixed by assigning the role. Same trap as noted previously for the shared `actingOwner` helper.
