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
| UI | Single page `/settings/access` with inline segmented sections (Users · Roles · Permissions); modals/drawers, no sub-routes |

## 3. The pivotal architecture change

**Before:** `Shop` is the authenticatable. Login = `shop_code + PIN` → Sanctum token on the Shop. Controllers call `$request->user()` and receive a `Shop`.

**After:** A new **`ShopUser`** is the authenticatable. Login = `shop_code + PIN`, where PIN resolves to a specific `ShopUser` in that shop. Token is issued on the `ShopUser`. The current shop is `$request->user()->shop`.

- Login screen and request shape are **identical** to today (`shop_code` + `pin`); only backend resolution changes.
- Existing shops are migrated: each gets an auto-created **Owner** `ShopUser` seeded from `shop.pin`, assigned the non-deletable **Owner** role.

## 4. Backend

### 4.1 Package & config
- Install `spatie/laravel-permission`; publish migrations/config.
- Enable **teams** (`config/permission.php` → `'teams' => true`).
- Permission **team resolver**: a middleware on the authenticated API group calls `setPermissionsTeamId($request->user()->shop_id)` so all role queries/assignments are shop-scoped automatically.
- Guard: `shop_users` (Sanctum, model `ShopUser`).

### 4.2 Data model
- **`shop_users`**: `id, shop_id (fk, index), name, login_pin (hashed), is_active (bool, default true), timestamps`. Traits: `Authenticatable`, `HasApiTokens`, `HasRoles`.
  - PIN uniqueness enforced **per shop** (unique composite `shop_id + login_pin` at the app/validation layer; PIN stored hashed, so uniqueness is checked by rejecting a PIN that already resolves within the shop).
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
- `Gate::before()` returns `true` when the current `ShopUser` holds the **Owner** role → bypasses all permission checks.
- The Owner role is created per shop, cannot be deleted or renamed, and is not editable in the permission matrix (implicitly all-permissions).

### 4.5 Endpoints (new)
All under the authenticated group; all implicitly shop-scoped by the team middleware.
- `GET  /auth/me` → current user + `permissions: string[]` (flattened effective permissions; `["*"]` sentinel for Owner) + shop.
- `GET  /permissions` → read-only catalog grouped by module.
- `GET/POST/PUT/DELETE /roles` → role CRUD (name + permission list). Owner role protected from edit/delete.
- `GET/POST/PUT/DELETE /users` → `ShopUser` CRUD (name, PIN, role, is_active). Cannot delete self; cannot delete last Owner.
- Role/permission-management endpoints guarded by `roles.manage` / `users.manage`.

### 4.6 Login change
- `POST /shops/login` (existing path kept): validate `shop_code + pin` → find the shop, then find the `ShopUser` in that shop whose `login_pin` matches → issue token on that user → return `{ token, user, shop, permissions }`.
- Backward-compatible response: still includes `shop`; adds `user` + `permissions`.

### 4.7 Enforcement on existing resources
- Add `$request->shop()` resolver (macro or `FormRequest`/middleware helper returning `$request->user()->shop`).
- **Mechanical sweep:** replace `$request->user()` (previously a Shop) with `$request->shop()` in existing controllers.
- Apply spatie `permission:<name>` middleware to existing resource routes per the catalog (e.g. `bookings.*`, `services.*`, `staff.*`, etc.).

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

- Highest-risk item is the controller sweep (§4.7) — kept mechanical via the `$request->shop()` resolver and covered by feature tests before/after.
- Data migration for existing shops (Owner user + role) is idempotent and reversible.
- Permission enforcement is added per-route; a missed route simply stays owner-only-safe because non-owners get no permissions until roles are assigned.

## 8. Out of scope (YAGNI)

- Password/email accounts, password reset, 2FA (PIN only, matching current UX).
- User-creatable permission strings (catalog is fixed).
- Cross-business/global admin.
- Granular per-record (row-level) permissions beyond shop scoping.
