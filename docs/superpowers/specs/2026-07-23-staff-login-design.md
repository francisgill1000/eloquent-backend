# Staff (ShopUser) Login — Design Spec

## Background

The prior email+password login rollout (`2026-07-22-email-password-login-design.md`) deliberately
broke staff login: the shared `/shops/login` endpoint dropped its PIN branch entirely, and
`SetRbacContext` middleware — which resolves the acting `ShopUser` from
`personal_access_tokens.shop_user_id` — can never populate that field anymore, since no login path
sets it. Staff accounts (`ShopUser` rows) can still be created/edited/deleted by the shop owner via
the existing self-service `AccessControl.tsx` page (Users tab) and `ShopUserController`, using a
`login_pin` field, but there is currently no way for any staff member to actually authenticate.

This spec rebuilds staff login using email+password, consistent with the owner account, while
keeping staff account management exactly where it is today: fully self-service under the shop
owner, no master involvement.

## Goals

- A `ShopUser` can log in with email+password and receive a token scoped to their `shop_id` and
  RBAC roles (`shop_user_id` correctly tagged on the token again).
- The shop owner continues to manage staff accounts themselves (create/edit/deactivate), just with
  email+password fields replacing the PIN field.
- No change to how the owner (`Shop`) logs in.

## Non-goals

- Self-service password reset for staff (out of scope — same standing decision as the owner
  account; master/owner-driven only).
- Any change to RBAC roles/permissions themselves.
- Any change to the master-controlled owner account flow.

## Design

### Data model

Add nullable `email` (unique) and nullable `password` (hashed cast, hidden) columns to
`shop_users`, mirroring the `shops` migration:

```php
$table->string('email')->nullable()->unique();
$table->string('password')->nullable();
```

`ShopUser::$hidden` gains `password` (alongside existing `login_pin`). `ShopUser::$casts` gains
`'password' => 'hashed'`, matching `Shop`'s single-hashing-path pattern — no stray `Hash::make()`
calls anywhere.

**Email uniqueness is global across `shops` + `shop_users` combined.** There is no business code
left to disambiguate accounts, so one email must resolve to exactly one identity platform-wide.
Since a Postgres `unique()` constraint can't span two tables, this is enforced in application code
(request validation checks both tables) exactly like the owner-creation flow already does.

`login_pin` stays on `shop_users`, dormant — not dropped. This mirrors the precedent already set
for `shops.pin`/`shops.shop_code`, which were also left in place rather than removed.

### Login endpoint

Extend the existing shared `ShopController::login()` — no new route, no separate staff-login page.
Today it does:

```php
$shop = Shop::where('email', $email)->first();
if (!$shop || !$shop->password || !Hash::check($password, $shop->password)) { 401 }
```

New behavior: if no `Shop` matches, fall back to `ShopUser`:

```php
$shopUser = ShopUser::where('email', $email)->where('is_active', true)->first();
if (!$shopUser || !$shopUser->password || !Hash::check($password, $shopUser->password)) { 401 }
```

On a `ShopUser` match: mint the token on `$shopUser->shop` (Sanctum's authenticatable is still
`Shop` — unchanged architecture), but create it via a path that tags
`personal_access_tokens.shop_user_id = $shopUser->id` (the same mechanism `SetRbacContext` already
reads — this wiring already exists in the middleware, it just has nothing to populate it since the
PIN branch was removed). Permissions come from `Rbac::permissionsFor($shopUser)` (existing
spatie-teams role resolution, already used elsewhere for the RBAC system) rather than the owner's
`[Rbac::WILDCARD]`. Response shape (`shop`, `user`, `permissions`, `token`) is unchanged — this is
exactly the dual shape `Login.tsx` already has state for, left over from the old PIN system, so
**no frontend changes are needed to `Login.tsx`**.

Both branches share the same generic `401 Invalid credentials` on failure (no user-enumeration
signal, no distinction between "no such email" and "wrong password" and "email exists on the other
table").

### Staff management UI (`AccessControl.tsx`, `ShopUserController`)

Swap the `login_pin` field for `email` + `password` in:

- `ShopUserController::validateData()` — `email` required + unique-across-both-tables validation
  rule, `password` required on create / optional on edit (same "leave blank to keep" pattern
  already used for the PIN field today).
- `ShopUserResource` — still never exposes the credential itself (today it omits `login_pin`
  entirely from the response; same for `password`).
- `admin/src/lib/access.ts` — `createUser`/`updateUser` payload shape changes from
  `{name, login_pin, role_id, is_active}` to `{name, email, password?, role_id, is_active}`.
- `admin/src/pages/AccessControl.tsx` (`UserEditor`) — "Login PIN" field becomes "Email" +
  "Password" fields. When the owner sets/changes a staff password, mirror the master's existing UX
  pattern from `MasterShopDetail.tsx`/`MasterShopCreate.tsx`: reveal it once from local component
  state only, never persisted to any storage, never re-fetchable after the fact.
- `admin/src/types.ts` — `ShopUser` type gains `email: string | null` (password never included,
  same as it's never included in the API response today).

No backfill mechanism is needed for existing `ShopUser` rows with no email/password set: the owner
already has full self-service edit access to every row today (that's how PINs are set), so they
re-edit each existing staff member once, same motion as changing a PIN.

## Testing

- Backend: `ShopUserLoginTest` (new) covering staff login success, wrong password, inactive user,
  email collision with a `Shop` email, missing credentials. Existing `RbacLoginTest` gets a staff
  case added alongside its owner cases. `ShopUserController` validation tests updated from
  `login_pin` to `email`/`password` fixtures.
- Frontend: `AccessControl.test.tsx` / `UserEditor` tests updated from PIN fixtures to email
  fixtures; `access.ts` payload shape tests updated.

## Rollout

Same pattern as the owner-login feature: subagent-driven-development plan, staging first, then
prod (backend `git reset --hard` + migrate, paired with `admin/deploy.ps1` +
`admin/deploy-staging.ps1` for the `AccessControl.tsx` changes — per the lesson captured in
`admin-deploy-script.md`, these must ship together).
