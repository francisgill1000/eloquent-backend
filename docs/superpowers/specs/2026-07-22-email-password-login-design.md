# Email + Password Login (Business Owner Account)

## Problem

The admin app logs businesses in with a `shop_code` (business ID) + numeric `pin`. This is being replaced with standard email + password login for the top-level business/owner account.

## Scope

**In scope:** the top-level `Shop` (business owner) login only.

**Explicitly out of scope, deferred to a separate task:** staff sub-user accounts (`ShopUser`, `shop_users.login_pin`). These are currently created self-service by the shop owner via the existing Users/Roles page (`ShopUserController`) and are untouched by this change. As an accepted, deliberate consequence, **staff login breaks** once this ships (the login endpoint will only recognize email+password, not business-code+PIN) until the follow-up task migrates them too.

Also out of scope: the unused `AuthController` (phone+password against the generic `users` table — dead code, nothing calls it), QR login (`ShopQrLoginController`, operates on an already-authenticated session, unaffected), and device-based auto-login (`ShopController::login_log`, keyed on `device_id`, unaffected — see Note below).

## Approach

Add `email` (unique, nullable during backfill) and `password` (hashed) columns to the `shops` table. Replace the business-code+PIN branch of `ShopController::login` with an email+password check against `shops`. The Sanctum token is still minted on `Shop` exactly as today (`$shop->createToken(...)`), so every downstream `$request->user()` assumption in the codebase is unaffected — this is a credential-and-lookup swap, not a tenant-model change.

The existing sub-user PIN branch (`shop->shopUsers()->where('login_pin', $pin)...`) is removed from `login()` as part of this change, per the deferred-staff decision above.

## Data model

- `shops.email` — string, unique, nullable (nullable so existing rows can be migrated without a data backfill script; master fills it in per-shop as part of onboarding them onto the new login).
- `shops.password` — string (hashed via `Hash::make`), nullable for the same reason.
- `shops.pin` — left in place, no longer read by login. Not dropped in this task (still exists dormant; cleanup deferred until the staff migration task confirms nothing else depends on it).
- `shops.shop_code` — left in place as an internal identifier (used elsewhere beyond login); just stops being part of the login request.

## Login endpoint (`ShopController::login`)

Request body changes from `{ shop_code, pin }` to `{ email, password }`.

```
$shop = Shop::where('email', $request->input('email'))->first();

if (!$shop || !$shop->password || !Hash::check($request->input('password'), $shop->password)) {
    return response()->json(['message' => 'Invalid credentials'], 401);
}

$token = $shop->createToken('auth_token')->plainTextToken;
$permissions = [\App\Support\Rbac::WILDCARD];
$user = ['id' => null, 'name' => $shop->name, 'is_active' => true];

$shop->recordLogin($request, ShopLoginActivity::METHOD_PASSWORD); // new method constant
```

The sub-user PIN lookup branch is deleted. `setPermissionsTeamId($shop->id)` still runs as today.

`ShopLoginActivity::METHOD_PIN` stays for historical log rows; add a new `METHOD_PASSWORD` constant for new logins (or reuse an existing generic constant if one exists — verify during implementation).

## Registration removed

`Register.tsx`, its route in `App.tsx`, and the public path through `ShopController::store` are removed. New businesses are onboarded exclusively through the master dashboard. `MasterController` currently has no shop-creation endpoint — one needs to be added (protected by existing master auth), where master sets the new shop's `name`, `email`, and initial `password` directly.

## Existing accounts (backfill)

Existing shops have `email`/`password` as `null` and cannot log in via the new endpoint until master sets both. This is deliberate — consistent with the existing PIN-reset policy (master/support controlled, no self-service). Master dashboard's shop-detail view (`MasterController.php` shop-detail response, which currently exposes `'pin' => $shop->pin` for support to relay) is updated to instead let master view/set `email` and set a new `password`, and the `pin` field is dropped from that response (it no longer serves a purpose once email+password is set — leaving it would be a stale, pointless exposure).

## Password reset

No self-service "forgot password" flow. Same policy as the current (already-shipped) PIN-reset removal: master/support resets a shop's password directly via the master dashboard. No password-reset-link emails are sent in this task.

## Frontend (`Login.tsx`)

- Replace the "Business ID" (`shop_code`) input and PIN input with `email` (type email) and `password` (type password, with existing show/hide toggle pattern) inputs.
- `admin/src/lib/shops.ts`'s `shopLogin()` changes its signature to `shopLogin(email, password)` and posts `{ email, password }` to `POST /shops/login`.
- "Remember me": currently stores the raw PIN in localStorage alongside the shop code — this is fixed as part of this change. Only the email is remembered; the password is never persisted client-side.
- Dead code cleanup: the leftover `post_reset_login_prefill` storage-key handling (lines 17-28, unreachable since the forgot-PIN flow was removed) is deleted while touching this file.

## Note: auto-login (`login_log`)

`ShopController::login_log` matches by `device_id` header, independent of PIN or email — functionally unaffected. It currently returns `$shop->makeVisible('pin')`; since `pin` no longer serves any purpose in the response consumer, this call is simplified to not expose `pin` (small incidental cleanup, not a behavior change to the auto-login mechanism itself).

## Testing

- Backend: update/replace existing login feature tests that post `{ shop_code, pin }` to instead post `{ email, password }`; add cases for wrong password, unknown email, and null-password (not-yet-backfilled) accounts all returning 401.
- Remove or quarantine tests that exercised the sub-user PIN login branch of this endpoint (staff login is deferred — those tests will need to move to whatever new staff-login mechanism the follow-up task builds, or be deleted if that branch of the endpoint no longer exists).
- Frontend: update `Login.tsx` tests (if present) for the new fields; verify remember-me only ever persists email.
- Manual: confirm master dashboard can set a shop's email+password and that shop can then log in; confirm a shop with no password set gets a clean 401, not an error.
