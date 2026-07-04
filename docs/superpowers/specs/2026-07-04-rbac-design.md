# RBAC (Roles, Permissions & Users) — Design Spec

- **Date:** 2026-07-04
- **App:** Booking Manager (Laravel 12 API + React/TS admin SPA)
- **Status:** Approved for planning

## 1. Goal

Add full role-based access control to the Booking Manager: per-business **users** who log in individually, fully custom **roles** (CRUD) drawing from a predefined **permission catalog**, enforced on **both backend (source of truth) and frontend (UI gating)**. All management happens on **one admin page** (no separate routes per entity).

## 2. Decisions (locked)

| Decision | Choice |
|---|---|
| Roles | Fully custom, per-business CRUD |
| Permissions | Predefined global catalog (seeded in code) |
| Scope | Per-business (tenant-scoped via spatie teams, `team_id = shop_id`) |
| Engine | `spatie/laravel-permission` (teams mode) |
| Auth depth | Real per-user login; Owner remains superadmin |
| Enforcement | Backend (403 via middleware/policies) **and** frontend (hide/disable) |
| Login UX | **Unchanged** — `shop_code + PIN`, where the PIN now uniquely identifies a `ShopUser` within the shop |
| Auth plumbing | **Token stays issued on the `Shop`** (so `$request->user()` remains a `Shop` — existing controllers untouched). The token is tagged with the acting `shop_user_id`; RBAC resolves the current `ShopUser` from that tag. **No controller sweep needed** (Decision 2 dropped — this is safer). |
| PIN storage | Plaintext, matching the existing `shops.pin` convention; hidden from serialization. Uniqueness enforced by a DB unique index `(shop_id, login_pin)`. |
| UI | Single page `/settings/access` with inline segmented sections (Users · Roles · Permissions); modals/drawers, no sub-routes |

## 3. The architecture (low-risk, additive)

**Before:** `Shop` is the Sanctum authenticatable. Login = `shop_code + PIN` → token on the Shop (`$shop->createToken(...)`). Controllers call `$request->user()` and receive a `Shop`. PINs are plaintext.

**After:** The **`Shop` stays the authenticatable** — the token is still created on the Shop, so `$request->user()` remains a `Shop` and **every existing controller keeps working unchanged**. We add a **`ShopUser`** as the RBAC *subject*: on login the PIN resolves to a specific `ShopUser`, and the issued token is tagged with that `shop_user_id`. A middleware reads the tag, loads the current `ShopUser`, and exposes it (`current_shop_user()`), against which all permission checks run.

- Login screen and request shape are **identical** to today (`shop_code` + `pin`); only backend resolution changes.
- Existing shops are migrated: each gets an auto-created **Owner** `ShopUser` seeded from `shop.pin`, assigned the non-deletable **Owner** role.
- The acting user is carried on the `personal_access_tokens` row via a new nullable `shop_user_id` column (set at token creation).

## 4. Backend

### 4.1 Package & config
- Install `spatie/laravel-permission`; publish migrations/config.
- Enable **teams** (`config/permission.php` → `'teams' => true`).
- Permission **team resolver**: a middleware on the authenticated API group calls `setPermissionsTeamId($request->user()->shop_id)` so all role queries/assignments are shop-scoped automatically.
- Guard: `shop_users` (Sanctum, model `ShopUser`).

### 4.2 Data model
- **`shop_users`**: `id, shop_id (fk, index), name, login_pin (plaintext, hidden), is_active (bool, default true), timestamps`, with a unique index on `(shop_id, login_pin)`. Traits: `HasRoles` (spatie). It is **not** the authenticatable — the Shop is — so it does not need `Authenticatable`/`HasApiTokens`.
  - PIN uniqueness enforced **per shop** by the DB unique index plus request validation; matches the existing plaintext `shops.pin` convention.
- **`personal_access_tokens`**: add nullable `shop_user_id` column (set when the login endpoint creates the token, so each session knows which user is acting).
- **spatie tables** (`roles`, `permissions`, `model_has_roles`, `model_has_permissions`, `role_has_permissions`) with `team_id` column (= `shop_id`).
- Roles are **per-shop** (`team_id` set). Permissions are **global** (`team_id = null`), seeded from the catalog.

### 4.3 Permission catalog (seeded, grouped by module)
`reports.view`,
`bookings.view` `bookings.create` `bookings.update` `bookings.delete`,
`services.view` `services.manage`,
`staff.view` `staff.manage`,
`customers.view` `customers.manage`,
`working_hours.view` `working_hours.manage`,
`assistant.use` `assistant.manage`,
`users.view` `users.manage`,
`roles.view` `roles.manage`,
`settings.manage`.

(Catalog lives in one PHP source of truth — e.g. `App\Support\PermissionCatalog` — consumed by the seeder and exposed via the read-only permissions endpoint so backend and frontend never drift.)

### 4.4 Owner / superadmin
- The current `ShopUser` is resolved from the token's `shop_user_id`. The `permission:` middleware treats a user holding the **Owner** role as all-allowed (bypass).
- If a token has **no** `shop_user_id` (e.g. legacy tokens issued before this feature, or non-admin flows), the middleware treats the session as **Owner-equivalent** for backward compatibility, so existing integrations keep working. New logins always tag the token.
- The Owner role is created per shop, cannot be deleted or renamed, and is not editable in the permission matrix (implicitly all-permissions).

### 4.5 Endpoints (new)
All under the authenticated group; all implicitly shop-scoped by the team middleware.
- `GET  /auth/me` → current user + `permissions: string[]` (flattened effective permissions; `["*"]` sentinel for Owner) + shop.
- `GET  /permissions` → read-only catalog grouped by module.
- `GET/POST/PUT/DELETE /roles` → role CRUD (name + permission list). Owner role protected from edit/delete.
- `GET/POST/PUT/DELETE /users` → `ShopUser` CRUD (name, PIN, role, is_active). Cannot delete self; cannot delete last Owner.
- Role/permission-management endpoints guarded by `roles.manage` / `users.manage`.

### 4.6 Login change
- `POST /shops/login` (existing path kept): validate `shop_code + pin` → find the shop, then find the active `ShopUser` in that shop whose `login_pin` matches → create the token **on the shop** tagged with `shop_user_id` → return `{ token, user, shop, permissions }`.
- Backward-compatible response: still includes `shop` (with `pin` visible, as today); adds `user` + `permissions`.
- If a shop has no matching `ShopUser` yet (unmigrated), fall back to the legacy check (`$shop->pin === $pin`) and issue an untagged token → treated as Owner-equivalent. Keeps every current shop logging in during rollout.

### 4.7 Enforcement on existing resources
- **No controller sweep** — the token stays on the Shop, so `$request->user()` is unchanged.
- Apply the spatie-based `permission:<name>` middleware to existing resource routes per the catalog (e.g. `bookings.*`, `services.*`, `staff.*`, etc.). The middleware resolves `current_shop_user()` from the token and returns 403 when the permission is missing (Owner/untagged bypass).

## 5. Frontend (single page)

- **Route:** `/settings/access` → `AccessControl.tsx`. Added to the Settings menu and desktop sidebar (Shield icon), mint/card styling.
- **Inline segmented switch** (no sub-routes): **Users · Roles · Permissions**.
  - **Users:** list; add/edit via modal (name, PIN, role select, active toggle); activate/deactivate; delete (guards: not self, not last owner).
  - **Roles:** list; create/edit via drawer with a **permission matrix** (checkbox grid grouped by module, "select all in group"); delete (Owner locked).
  - **Permissions:** read-only catalog grouped by module (reference).
- **`lib/access.ts`:** typed API calls for me/permissions/roles/users.
- **`ShopContext`** extended: `currentUser`, `permissions: string[]`, `can(perm): boolean` (Owner `["*"]` → always true). Populated at login and via `/auth/me` on refresh.
- **Gating primitives:** `useCan()` hook + `<Can permission="…">` wrapper to hide/disable actions across the app (e.g. Add/Delete buttons, sidebar items). The Access page itself is gated behind `users.view || roles.view`.

## 6. Testing (TDD)

Feature tests (Pest/PHPUnit):
1. Login: correct PIN resolves to the correct `ShopUser`; wrong PIN → 401.
2. Owner bypass: owner hits a `bookings.delete`-guarded route without explicit perm → 200.
3. Enforcement: non-owner lacking `bookings.delete` → 403; after granting → 200.
4. Tenant isolation: shop A's user cannot see or mutate shop B's roles/users (team scoping).
5. Role CRUD: create role with perms, edit perms, Owner role delete/edit blocked.
6. User CRUD: create user with role + PIN, cannot delete self, cannot delete last owner.
7. `/auth/me` returns correct flattened permissions.

Frontend: `can()` logic unit test (owner sentinel, granted, denied); Access page renders three sections and gates by permission.

## 7. Rollout / risk

- **Low blast radius:** the token stays on the Shop, so existing controllers/integrations are untouched; RBAC is purely additive.
- Untagged/legacy tokens are treated as Owner-equivalent, so sessions issued before the deploy keep full access until re-login.
- Data migration for existing shops (Owner user + role) is idempotent and reversible.
- Permission enforcement is added per-route; a non-owner gets no permissions until a role is assigned, so the default is safe-restrictive for staff and unchanged for owners.
- Auth touches a production app — implement + test on a branch; **do not auto-deploy**; owner reviews before shipping.

## 8. Out of scope (YAGNI)

- Password/email accounts, password reset, 2FA (PIN only, matching current UX).
- User-creatable permission strings (catalog is fixed).
- Cross-business/global admin.
- Granular per-record (row-level) permissions beyond shop scoping.
