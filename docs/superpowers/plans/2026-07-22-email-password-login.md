# Email + Password Login (Business Owner Account) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Replace the business-code + PIN owner login with email + password, master-controlled provisioning (no self-serve signup), consistent with the existing master-only PIN-reset policy.

**Architecture:** Add `email`/`password` columns to `shops`. `ShopController::login` looks up `shops.email` and verifies the hashed password instead of matching `shop_code` + `pin`. The Sanctum token is still minted on `Shop` exactly as today, so every existing `$request->user()` assumption elsewhere in the codebase is unaffected. Public self-registration (`Register.tsx`) is removed; the only path to create a shop becomes the already-existing master-only "Add Business" flow (`MasterShopCreate.tsx`), whose backend endpoint (`POST /shops`) gets gated behind `auth:sanctum` + a master check for the first time.

**Tech Stack:** Laravel 12 (PHP), Sanctum, Pest/PHPUnit feature tests; React + TypeScript admin SPA, Vitest + Testing Library.

## Global Constraints

- Staff sub-user (`ShopUser`) login is explicitly OUT OF SCOPE and deferred to a separate task. As an accepted consequence, staff login breaks after this ships — do not try to preserve it.
- No self-service password reset and no self-service registration — master/support sets every shop's email + password by hand, consistent with the already-shipped master-only PIN-reset policy.
- The Sanctum token must still always be minted on `Shop` (`$shop->createToken(...)`) — never change this, since every other controller in the codebase assumes `$request->user()` is a `Shop`.
- `shops.shop_code` and `shops.pin` columns are NOT dropped in this task — they stay, just unused by login.
- Never persist a plaintext password client-side (no `localStorage`/`sessionStorage`).

---

### Task 1: Add `email` + `password` columns to `shops`

**Files:**
- Create: `database/migrations/2026_07_22_000001_add_email_password_to_shops_table.php`
- Test: `tests/Feature/RbacLoginTest.php` (exercises the columns indirectly; no dedicated migration test needed — Laravel migration tests aren't a project convention here)

**Interfaces:**
- Produces: `shops.email` (string, nullable, unique), `shops.password` (string, nullable) — consumed by Task 2 (model cast) and Task 3 (login lookup).

- [ ] **Step 1: Write the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('email')->nullable()->unique()->after('pin');
            $table->string('password')->nullable()->after('email');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['email', 'password']);
        });
    }
};
```

- [ ] **Step 2: Run the migration on the local/test DB**

Run: `php artisan migrate`
Expected: `2026_07_22_000001_add_email_password_to_shops_table ... DONE`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_07_22_000001_add_email_password_to_shops_table.php
git commit -m "feat(auth): add email+password columns to shops"
```

---

### Task 2: Wire `email`/`password` into the `Shop` model

**Files:**
- Modify: `app/Models/Shop.php:27` (the `$hidden` array), `app/Models/Shop.php:31-36` (the `$casts` array)

**Interfaces:**
- Consumes: `shops.email`, `shops.password` columns from Task 1.
- Produces: `Shop::$password` auto-hashes on assignment (via the `'hashed'` cast) — consumed by Task 4 (`ShopController::store`) and Task 5 (`MasterController::updateShop`). `email` is NOT hidden, so it appears in JSON responses by default — consumed by Task 3 (login response) and all frontend tasks.

- [ ] **Step 1: Hide the password hash and add the auto-hashing cast**

In `app/Models/Shop.php`, change:

```php
    protected $hidden = ['pin', 'device_id'];
```

to:

```php
    protected $hidden = ['pin', 'device_id', 'password'];
```

And change:

```php
    protected $casts = [
        'last_login_at' => 'datetime',
        'modules' => 'array',
        'simulation_script' => 'array',
        'hunt_self_serve' => 'boolean',
    ];
```

to:

```php
    protected $casts = [
        'last_login_at' => 'datetime',
        'modules' => 'array',
        'simulation_script' => 'array',
        'hunt_self_serve' => 'boolean',
        'password' => 'hashed',
    ];
```

- [ ] **Step 2: Verify with tinker that assignment auto-hashes**

Run: `php artisan tinker --execute="$s = \App\Models\Shop::factory()->create(['email' => 'tinker-check@example.com', 'password' => 'plaintext123']); echo str_starts_with($s->password, '$2y$') ? 'HASHED' : 'NOT HASHED';"`
Expected: `HASHED`

- [ ] **Step 3: Commit**

```bash
git add app/Models/Shop.php
git commit -m "feat(auth): hash Shop passwords automatically and hide them from JSON"
```

---

### Task 3: Replace business-code+PIN login with email+password

**Files:**
- Modify: `app/Http/Controllers/ShopController.php:179-222` (the `login` method and its imports)
- Modify: `app/Models/ShopLoginActivity.php:12-14` (add a `METHOD_PASSWORD` constant)
- Modify: `tests/Feature/RbacLoginTest.php` (full rewrite — the PIN-based scenarios it covers no longer exist)

**Interfaces:**
- Consumes: `Shop::$password` (Task 2's `'hashed'` cast), `ShopLoginActivity::METHOD_PASSWORD`.
- Produces: `POST /api/shops/login` now takes `{ email, password }` and returns `{ shop, user, permissions, token }` exactly as before (shape unchanged) — consumed by Task 6 (`shopLogin()` in the frontend).

- [ ] **Step 1: Add the `METHOD_PASSWORD` constant**

In `app/Models/ShopLoginActivity.php`, change:

```php
    const METHOD_PIN = 'pin';
    const METHOD_QR = 'qr';
    const METHOD_AUTO = 'auto';
```

to:

```php
    const METHOD_PIN = 'pin';
    const METHOD_PASSWORD = 'password';
    const METHOD_QR = 'qr';
    const METHOD_AUTO = 'auto';
```

- [ ] **Step 2: Write the failing tests (replace the whole file)**

Replace the full contents of `tests/Feature/RbacLoginTest.php` with:

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RbacLoginTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_with_correct_email_and_password_succeeds(): void
    {
        $shop = Shop::factory()->create(['email' => 'owner@example.com', 'password' => 'correct-horse']);

        $res = $this->postJson('/api/shops/login', [
            'email' => 'owner@example.com',
            'password' => 'correct-horse',
        ]);

        $res->assertStatus(201)
            ->assertJsonPath('permissions.0', '*')
            ->assertJsonPath('shop.email', 'owner@example.com');

        $this->assertNotEmpty($res->json('token'));
    }

    public function test_wrong_password_is_rejected(): void
    {
        $shop = Shop::factory()->create(['email' => 'owner2@example.com', 'password' => 'correct-horse']);

        $this->postJson('/api/shops/login', [
            'email' => 'owner2@example.com',
            'password' => 'wrong-password',
        ])->assertStatus(401);
    }

    public function test_unknown_email_is_rejected(): void
    {
        $this->postJson('/api/shops/login', [
            'email' => 'nobody@example.com',
            'password' => 'anything',
        ])->assertStatus(401);
    }

    public function test_shop_with_no_password_set_cannot_log_in(): void
    {
        // Not-yet-backfilled shop: email set by master, password not set yet.
        $shop = Shop::factory()->create(['email' => 'pending@example.com', 'password' => null]);

        $this->postJson('/api/shops/login', [
            'email' => 'pending@example.com',
            'password' => 'anything',
        ])->assertStatus(401);
    }
}
```

- [ ] **Step 3: Run the tests to verify they fail**

Run: `php artisan test --filter=RbacLoginTest`
Expected: FAIL — `login()` still expects `shop_code`/`pin` so all four assertions fail (404/401 mismatches).

- [ ] **Step 4: Rewrite `ShopController::login`**

In `app/Http/Controllers/ShopController.php`, add the `Hash` import at the top (after the existing `use Illuminate\Support\Facades\Log;` line):

```php
use Illuminate\Support\Facades\Hash;
```

Replace the entire `login` method (currently lines 179-222):

```php
    public function login(Request $request)
    {
        $email = $request->input('email');
        $password = $request->input('password');

        $shop = Shop::where('email', $email)->first();

        if (!$shop || !$shop->password || !Hash::check((string) $password, $shop->password)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        setPermissionsTeamId($shop->id);

        $token = $shop->createToken('auth_token')->plainTextToken;
        $permissions = [\App\Support\Rbac::WILDCARD];
        $user = ['id' => null, 'name' => $shop->name, 'is_active' => true];

        $shop->recordLogin($request, ShopLoginActivity::METHOD_PASSWORD);

        return response()->json([
            'shop' => $shop,
            'user' => $user,
            'permissions' => $permissions,
            'token' => $token
        ], 201);
    }
```

Note this drops the `->makeVisible('pin')` call entirely — `pin` is irrelevant now and stays hidden; `email` is not in `$hidden` so it appears in the response automatically.

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=RbacLoginTest`
Expected: `PASS  Tests\Feature\RbacLoginTest` (4 passed)

- [ ] **Step 6: Stop exposing the now-meaningless `pin` from auto-login too**

`ShopController::login_log` (the device-based auto-login used by returning sessions) is unaffected functionally by this change — it matches on `device_id`, not email/PIN — but its response still calls `->makeVisible('pin')`, which is now pointless exposure of dormant data. In `app/Http/Controllers/ShopController.php`, inside `login_log`, change:

```php
            return response()->json([
                'authenticated' => true,
                'token' => $token,
                'shop' => $shop->makeVisible('pin'), // owner's own creds
            ]);
```

to:

```php
            return response()->json([
                'authenticated' => true,
                'token' => $token,
                'shop' => $shop,
            ]);
```

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/ShopController.php app/Models/ShopLoginActivity.php tests/Feature/RbacLoginTest.php
git commit -m "feat(auth): replace business-code+PIN login with email+password"
```

---

### Task 4: Gate shop creation behind master auth and require email+password

**Files:**
- Modify: `routes/api.php:44` (split the `store` route out of the public `apiResource`)
- Modify: `app/Http/Requests/StoreShopRequest.php` (require `email`/`password`, restrict `authorize()` to master)
- Modify: `app/Http/Controllers/ShopController.php:112-138` (the `store` method — drop the `makeVisible('pin')` reveal)
- Modify: `tests/Feature/ShopRegistrationTest.php` (registration now requires a master-authenticated caller)

**Interfaces:**
- Consumes: `Shop::$is_master` (existing column), `MasterController::requireMaster`-style check (reimplemented inline here since `StoreShopRequest` doesn't have access to `MasterController`).
- Produces: `POST /api/shops` now requires `Authorization: Bearer <master token>` and `{ name, phone?, email, password, category_id, ... }` — consumed by Task 9 (`MasterShopCreate.tsx`).

- [ ] **Step 1: Write the failing test — registration requires master auth**

In `tests/Feature/ShopRegistrationTest.php`, replace the `test_registers_with_name_and_phone_and_returns_credentials` test:

```php
    public function test_registration_requires_master_auth(): void
    {
        $this->postJson('/api/shops', [
            'name' => 'Shakaina Salon',
            'phone' => '0554501483',
            'email' => 'shakaina@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 1,
            'is_verified' => true,
        ])->assertStatus(401);
    }

    public function test_master_registers_a_shop_with_email_and_password(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $token = $master->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Shakaina Salon',
            'phone' => '0554501483',
            'email' => 'shakaina@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 1,
            'is_verified' => true,
        ]);

        $response->assertCreated();
        $this->assertNotEmpty($response->json('token'));
        $this->assertSame('shakaina@example.com', $response->json('shop.email'));
        $this->assertArrayNotHasKey('pin', $response->json('shop'));

        $shop = Shop::where('name', 'Shakaina Salon')->first();
        $this->assertNotNull($shop);
        $this->assertSame('0554501483', $shop->phone);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('at-least-8-chars', $shop->password));
        $this->assertNotNull($shop->category_confirmed_at); // locked at registration
    }

    public function test_non_master_shop_cannot_register_a_shop(): void
    {
        $notMaster = Shop::factory()->create(['is_master' => false]);
        $token = $notMaster->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Shakaina Salon',
            'phone' => '0554501483',
            'email' => 'shakaina2@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 1,
            'is_verified' => true,
        ])->assertStatus(403);
    }
```

The other tests in this file also `postJson('/api/shops', ...)` without auth and without `email`/`password`. Replace each of the following three tests in full:

```php
    public function test_registers_with_custom_other_category(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $token = $master->createToken('t')->plainTextToken;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Falcon Tours',
            'phone' => '0554500000',
            'email' => 'falcon@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 0, // "Other"
            'custom_category' => 'Desert Safari Tours',
            'is_verified' => true,
        ]);

        $response->assertCreated();

        $shop = Shop::where('name', 'Falcon Tours')->first();
        $this->assertNotNull($shop);
        $this->assertSame(0, (int) $shop->category_id);
        $this->assertSame('Desert Safari Tours', $shop->custom_category);
        $this->assertSame('Desert Safari Tours', $shop->categoryLabel());
    }

    public function test_other_category_requires_custom_name(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $token = $master->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Nameless Other Shop',
            'phone' => '0550000002',
            'email' => 'nameless@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 0, // "Other" but no custom_category
            'is_verified' => true,
        ])->assertStatus(422)->assertJsonValidationErrors('custom_category');
    }

    public function test_rejects_unknown_category(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $token = $master->createToken('t')->plainTextToken;

        $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/shops', [
            'name' => 'Bad Cat Shop',
            'phone' => '0550000001',
            'email' => 'badcat@example.com',
            'password' => 'at-least-8-chars',
            'category_id' => 99,
            'is_verified' => true,
        ])->assertStatus(422);
    }
```

`test_old_shop_confirms_category_once_then_locked` and `test_phone_can_be_updated_via_shop_update` don't post to `/api/shops` (they hit `/api/shop/category` and `PUT /api/shops/{id}` respectively) — leave both unchanged.

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=ShopRegistrationTest`
Expected: FAIL — route is still public and `StoreShopRequest` doesn't require `email`/`password` yet, so the "requires master auth" test gets 201 instead of 401, and category/master tests hit validation-shape mismatches.

- [ ] **Step 3: Split the route**

In `routes/api.php`, change:

```php
Route::apiResource('/shops', ShopController::class)->only(['index', 'show', 'store']);
```

to:

```php
Route::apiResource('/shops', ShopController::class)->only(['index', 'show']);
// Shop creation is master-only — no public self-registration (see StoreShopRequest::authorize()).
Route::middleware('auth:sanctum')->post('/shops', [ShopController::class, 'store']);
```

- [ ] **Step 4: Update `StoreShopRequest`**

Replace the full contents of `app/Http/Requests/StoreShopRequest.php`:

```php
<?php

namespace App\Http\Requests;

use App\Models\Shop;
use Illuminate\Foundation\Http\FormRequest;

class StoreShopRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user instanceof Shop && $user->is_master;
    }

    public function rules(): array
    {
        return [
            'name'        => 'required|string|max:255|unique:shops,name',
            'email'       => 'required|email|max:255|unique:shops,email',
            'password'    => 'required|string|min:8',
            'phone'       => 'nullable|string|max:32',
            'logo'        => 'nullable',
            'hero_image'  => 'nullable',
            'lat'         => 'nullable|between:-90,90',
            'lon'         => 'nullable|between:-180,180',
            'location'    => 'nullable|string|max:255',
            'is_verified' => 'boolean',
            // OTHER_ID (0) means the owner chose "Other" and typed a custom_category.
            'category_id' => 'required|integer|in:' . implode(',', array_merge(
                [\App\Support\ServiceCategories::OTHER_ID],
                \App\Support\ServiceCategories::ids(),
            )),
            'custom_category' => 'nullable|string|max:255|required_if:category_id,' . \App\Support\ServiceCategories::OTHER_ID,
            'status'      => 'required|string|in:active,inactive',

        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'is_verified' => $this->boolean('is_verified'),
            'status' => $this->status ?? 'inactive',
        ]);
    }
}
```

A `403` (not the framework default `401`) is what Laravel returns when `authorize()` returns `false` on an otherwise-authenticated request — the earlier `test_registration_requires_master_auth` test expects `401` because there's no bearer token at all (unauthenticated hits the `auth:sanctum` middleware first); `test_non_master_shop_cannot_register_a_shop` expects `403` because that request IS authenticated but fails `authorize()`.

- [ ] **Step 5: Drop the `makeVisible('pin')` reveal in `store()`**

In `app/Http/Controllers/ShopController.php`, change:

```php
        return response()->json([
            'shop' => $shop->makeVisible('pin'), // shown once on the credentials screen
            'token' => $token
        ], 201);
```

(inside `store()`) to:

```php
        return response()->json([
            'shop' => $shop,
            'token' => $token
        ], 201);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `php artisan test --filter=ShopRegistrationTest`
Expected: `PASS  Tests\Feature\ShopRegistrationTest`

- [ ] **Step 7: Commit**

```bash
git add routes/api.php app/Http/Requests/StoreShopRequest.php app/Http/Controllers/ShopController.php tests/Feature/ShopRegistrationTest.php
git commit -m "feat(auth): require master auth + email/password for shop creation"
```

---

### Task 5: Let master view/set a shop's email and password

**Files:**
- Modify: `app/Http/Controllers/MasterController.php:76-102` (`presentShop`) and `:51-73` (`updateShop`)
- Modify: `tests/Feature/MasterTest.php` (the pin-based assertion)

**Interfaces:**
- Consumes: `Shop::$email`, `Shop::$password` (Task 2).
- Produces: `PATCH /api/master/shops/{shop}` now accepts optional `email`/`password`; `presentShop()` includes `email` instead of `pin` — consumed by Task 6 (`updateMasterShop()` type) and Task 10 (`MasterShopDetail.tsx`).

- [ ] **Step 1: Write the failing test**

In `tests/Feature/MasterTest.php`, rename the test and its assertion. Change:

```php
    public function test_master_sees_all_shops_with_codes_and_pins(): void
```

to:

```php
    public function test_master_sees_all_shops_with_codes_and_emails(): void
```

And change:

```php
        $this->assertSame($shopA->pin, $rowA['pin']);
```

to:

```php
        $this->assertSame($shopA->email, $rowA['email']);
```

Then add a new test to the same file (after `test_master_sees_all_shops_with_codes_and_pins`):

```php
    public function test_master_can_set_a_shops_email_and_password(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $shop = Shop::factory()->create();

        $response = $this->patchJson("/api/master/shops/{$shop->id}", [
            'email' => 'new-owner@example.com',
            'password' => 'brand-new-pass',
        ], $this->authed($master))->assertOk();

        $this->assertSame('new-owner@example.com', $response->json('data.email'));
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('brand-new-pass', $shop->fresh()->password));
    }
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `php artisan test --filter=MasterTest`
Expected: FAIL — `presentShop()` still returns `pin`, not `email`; `updateShop()` rejects `email`/`password` (not in its validation rules, silently dropped, so the password never actually changes).

- [ ] **Step 3: Update `presentShop()`**

In `app/Http/Controllers/MasterController.php`, change:

```php
            'shop_code' => $shop->shop_code,
            'pin' => $shop->pin,
```

to:

```php
            'shop_code' => $shop->shop_code,
            'email' => $shop->email,
```

- [ ] **Step 4: Update `updateShop()` validation and assignment**

In `app/Http/Controllers/MasterController.php`, change:

```php
        $data = $request->validate([
            'status' => ['sometimes', 'in:active,inactive'],
            'persona' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', 'in:bookings,leads'],
            'hunt_self_serve' => ['sometimes', 'boolean'],
        ]);
```

to:

```php
        $data = $request->validate([
            'status' => ['sometimes', 'in:active,inactive'],
            'persona' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', 'in:bookings,leads'],
            'hunt_self_serve' => ['sometimes', 'boolean'],
            'email' => ['sometimes', 'email', 'max:255', 'unique:shops,email,' . $shop->id],
            'password' => ['sometimes', 'string', 'min:8'],
        ]);
```

(The rest of `updateShop()` — `if (array_key_exists('persona', ...)) {...}` and `$shop->update($data);` — stays unchanged; `$shop->update($data)` already mass-assigns whatever keys `$data` contains, and the `'hashed'` cast from Task 2 takes care of hashing `password` automatically.)

- [ ] **Step 5: Run the tests to verify they pass**

Run: `php artisan test --filter=MasterTest`
Expected: `PASS  Tests\Feature\MasterTest`

- [ ] **Step 6: Run the full backend suite to catch any other `pin` references**

Run: `php artisan test`
Expected: all green. If anything outside this plan's scope references `shop.pin`/`shop_code`+`pin` login and fails, note it — do not silently patch unrelated tests without understanding why they broke.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/MasterController.php tests/Feature/MasterTest.php
git commit -m "feat(auth): let master view/set a shop's email and password"
```

---

### Task 6: Update the frontend API layer and types

**Files:**
- Modify: `admin/src/lib/shops.ts:4-15` (`shopLogin`), `:80-91` (`updateMasterShop`)
- Modify: `admin/src/types.ts:172-197` (`MasterShop`)

**Interfaces:**
- Consumes: Task 3's `POST /shops/login` shape, Task 5's `PATCH /master/shops/{id}` shape.
- Produces: `shopLogin(email, password)`, `updateMasterShop(id, { ...email?, password? })`, `MasterShop.email` — consumed by Tasks 7, 9, 10.

- [ ] **Step 1: Update `MasterShop` in `admin/src/types.ts`**

Change:

```ts
  shop_code?: string;
  pin?: string;
```

to:

```ts
  shop_code?: string;
  email?: string;
```

- [ ] **Step 2: Update `shopLogin` in `admin/src/lib/shops.ts`**

Change:

```ts
export async function shopLogin(
  shopCode: string,
  pin: string,
): Promise<{ token: string; shop: Shop; user: AuthUser | null; permissions: string[] }> {
  const { data } = await api.post('shops/login', { shop_code: shopCode, pin });
  return {
    token: data.token,
    shop: data.shop,
    user: data.user ?? null,
    permissions: Array.isArray(data.permissions) ? data.permissions : [],
  };
}
```

to:

```ts
export async function shopLogin(
  email: string,
  password: string,
): Promise<{ token: string; shop: Shop; user: AuthUser | null; permissions: string[] }> {
  const { data } = await api.post('shops/login', { email, password });
  return {
    token: data.token,
    shop: data.shop,
    user: data.user ?? null,
    permissions: Array.isArray(data.permissions) ? data.permissions : [],
  };
}
```

- [ ] **Step 3: Update `registerShop`'s doc comment**

Change:

```ts
export async function registerShop(form: Record<string, unknown>): Promise<{ token?: string; shop?: Shop }> {
```

to:

```ts
/** Master account only: create a new business (POST /shops requires master auth). */
export async function registerShop(form: Record<string, unknown>): Promise<{ token?: string; shop?: Shop }> {
```

(the body is unchanged — it already just forwards `form` as-is, so `email`/`password` pass through with no signature change needed)

- [ ] **Step 4: Widen `updateMasterShop`'s payload type**

Change:

```ts
export async function updateMasterShop(
  id: number,
  payload: {
    status?: 'active' | 'inactive';
    persona?: string | null;
    modules?: Array<'bookings' | 'leads'>;
    hunt_self_serve?: boolean;
  },
): Promise<MasterShop> {
```

to:

```ts
export async function updateMasterShop(
  id: number,
  payload: {
    status?: 'active' | 'inactive';
    persona?: string | null;
    modules?: Array<'bookings' | 'leads'>;
    hunt_self_serve?: boolean;
    email?: string;
    password?: string;
  },
): Promise<MasterShop> {
```

- [ ] **Step 5: Type-check**

Run: `cd admin && npx tsc --noEmit`
Expected: errors only in files not yet touched by this plan (`Login.tsx`, `MasterShopCreate.tsx`, `MasterShopDetail.tsx`, `MasterShopCard.test.tsx`, `MasterShops.test.tsx`, `MasterShopDetail.test.tsx`, `Register.tsx` — all fixed in later tasks). If you see an error anywhere else, stop and investigate before continuing.

- [ ] **Step 6: Commit**

```bash
git add admin/src/lib/shops.ts admin/src/types.ts
git commit -m "feat(auth): update frontend API layer for email+password"
```

---

### Task 7: Rewrite the Login page

**Files:**
- Modify: `admin/src/pages/Login.tsx` (full rewrite)
- Modify: `admin/src/pages/Login.test.tsx` (full rewrite)

**Interfaces:**
- Consumes: `shopLogin(email, password)` from Task 6.

- [ ] **Step 1: Write the failing tests (replace the whole file)**

Replace the full contents of `admin/src/pages/Login.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import * as shops from '@/lib/shops';
import Login from './Login';

const nav = vi.fn();
vi.mock('react-router-dom', async (orig) => ({
  ...(await orig() as object),
  useNavigate: () => nav,
}));

function setup() {
  return render(<MemoryRouter><ShopProvider><Login /></ShopProvider></MemoryRouter>);
}

describe('Login', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); nav.mockReset(); });

  it('logs in with email and password', async () => {
    vi.spyOn(shops, 'shopLogin').mockResolvedValue({ token: 't', shop: { id: 1, name: 'Acme' }, user: null, permissions: ['*'] });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/email/i), 'owner@example.com');
    await user.type(screen.getByLabelText(/password/i), 'secret123');
    await user.click(screen.getByRole('button', { name: /log in/i }));
    expect(shops.shopLogin).toHaveBeenCalledWith('owner@example.com', 'secret123');
    expect(localStorage.getItem('shop_token')).toBe('t');
  });

  it('shows an error on failed login', async () => {
    vi.spyOn(shops, 'shopLogin').mockRejectedValue(new Error('bad'));
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/email/i), 'x@example.com');
    await user.type(screen.getByLabelText(/password/i), 'wrong');
    await user.click(screen.getByRole('button', { name: /log in/i }));
    expect(await screen.findByText(/failed|invalid|incorrect/i)).toBeInTheDocument();
  });

  it('remembers only the email, never the password', async () => {
    vi.spyOn(shops, 'shopLogin').mockResolvedValue({ token: 't', shop: { id: 1, name: 'Acme' }, user: null, permissions: ['*'] });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/email/i), 'owner@example.com');
    await user.type(screen.getByLabelText(/password/i), 'secret123');
    await user.click(screen.getByLabelText(/remember/i));
    await user.click(screen.getByRole('button', { name: /log in/i }));
    expect(localStorage.getItem('remember_shop_email')).toBe('owner@example.com');
    expect(Object.keys(localStorage).some((k) => localStorage.getItem(k) === 'secret123')).toBe(false);
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd admin && npx vitest run src/pages/Login.test.tsx`
Expected: FAIL — `Login.tsx` still renders "Business ID"/"PIN" fields, not "Email"/"Password".

- [ ] **Step 3: Rewrite `Login.tsx`**

Replace the full contents of `admin/src/pages/Login.tsx`:

```tsx
import { useState, useEffect } from 'react';
import { useNavigate } from 'react-router-dom';
import { shopLogin } from '@/lib/shops';
import { useShop } from '@/context/ShopContext';
import { storage } from '@/lib/storage';

export default function Login() {
  const navigate = useNavigate();
  const { loginShop, setAccess } = useShop();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [showPassword, setShowPassword] = useState(false);
  const [rememberMe, setRememberMe] = useState(false);
  const [submitting, setSubmitting] = useState(false);
  const [error, setError] = useState('');

  useEffect(() => {
    if (storage.get('remember_shop_login') === 'true') {
      setRememberMe(true);
      setEmail(storage.get('remember_shop_email') ?? '');
    }
  }, []);

  const handleLogin = async () => {
    if (submitting) return;
    if (!email.trim()) { setError('Please enter your email.'); return; }
    if (!password.trim()) { setError('Please enter your password.'); return; }
    setSubmitting(true);
    setError('');
    try {
      const { token, shop, user, permissions } = await shopLogin(email.trim(), password);
      if (token && shop) {
        if (rememberMe) {
          storage.set('remember_shop_login', 'true');
          storage.set('remember_shop_email', email.trim());
        } else {
          storage.remove('remember_shop_login');
          storage.remove('remember_shop_email');
        }
        loginShop(shop, token);
        setAccess(user, permissions);
        navigate('/');
      } else {
        setError('Invalid response from server.');
      }
    } catch (e: unknown) {
      const data = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(data?.message || 'Login failed. Check your credentials.');
    } finally {
      setSubmitting(false);
    }
  };

  return (
    <div className="m-screen c-auth-screen"><div className="m-scroll c-auth-scroll">
      <div className="c-auth">
        <div className="c-auth-brand">
          <div className="c-auth-orb"><img src="/favicon.svg" alt="" /></div>
          <div className="c-auth-wordmark">Business Lens</div>
        </div>

        <div className="c-auth-card">
        <h1 className="c-auth-title">Welcome back</h1>
        <p className="c-auth-sub">Enter your email and password to access your dashboard.</p>

        {error && <div className="c-error-box">{error}</div>}

        <label className="c-field-label" htmlFor="email">Email</label>
        <div className="c-input-row">
          <input
            id="email"
            type="email"
            placeholder="you@business.com"
            autoCapitalize="none"
            value={email}
            onChange={(e) => { setEmail(e.target.value); setError(''); }}
          />
        </div>

        <label className="c-field-label" htmlFor="password">Password</label>
        <div className="c-input-row">
          <input
            id="password"
            type={showPassword ? 'text' : 'password'}
            placeholder="Enter your password"
            value={password}
            onChange={(e) => { setPassword(e.target.value); setError(''); }}
            onKeyDown={(e) => { if (e.key === 'Enter') void handleLogin(); }}
          />
          <button
            type="button"
            onClick={() => setShowPassword((v) => !v)}
            style={{ background: 'none', border: 'none', color: 'var(--text-3)', fontSize: 11, fontWeight: 700, textTransform: 'uppercase', cursor: 'pointer' }}
          >
            {showPassword ? 'Hide' : 'Show'}
          </button>
        </div>

        <label style={{ display: 'flex', alignItems: 'center', gap: 8, marginBottom: 16, color: 'var(--text-2)', fontSize: 14 }}>
          <input type="checkbox" checked={rememberMe} onChange={(e) => setRememberMe(e.target.checked)} />
          Remember my email
        </label>

        <button className="c-btn c-btn-block" disabled={submitting} onClick={() => void handleLogin()}>
          {submitting ? 'Logging in…' : 'Log In'}
        </button>
        </div>
      </div>
    </div></div>
  );
}
```

Note this drops the `post_reset_login_prefill` block from the old file — it was dead code (nothing writes that storage key since the Forgot-PIN page was removed).

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd admin && npx vitest run src/pages/Login.test.tsx`
Expected: `3 passed`

- [ ] **Step 5: Commit**

```bash
git add admin/src/pages/Login.tsx admin/src/pages/Login.test.tsx
git commit -m "feat(auth): rewrite Login page for email+password"
```

---

### Task 8: Remove public self-registration

**Files:**
- Delete: `admin/src/pages/Register.tsx`, `admin/src/pages/Register.test.tsx`
- Modify: `admin/src/App.tsx:12` (import), `:65` (route)

**Interfaces:**
- None — this is a pure removal with no other task depending on it.

- [ ] **Step 1: Delete the files**

```bash
git rm admin/src/pages/Register.tsx admin/src/pages/Register.test.tsx
```

- [ ] **Step 2: Remove the import and route from `App.tsx`**

In `admin/src/App.tsx`, delete this line:

```tsx
import Register from '@/pages/Register';
```

And delete this line:

```tsx
        <Route path="/register" element={<Register />} />
```

- [ ] **Step 3: Type-check and run the admin test suite**

Run: `cd admin && npx tsc --noEmit && npx vitest run`
Expected: `tsc` passes for this file; `vitest` will still show failures in `MasterShopCreate.test.tsx`, `MasterShopDetail.test.tsx`, `MasterShopCard.test.tsx`, `MasterShops.test.tsx` — those are fixed in Tasks 9-11.

- [ ] **Step 4: Commit**

```bash
git add admin/src/App.tsx
git commit -m "feat(auth): remove public self-registration"
```

---

### Task 9: Update `MasterShopCreate.tsx` for email+password

**Files:**
- Modify: `admin/src/pages/MasterShopCreate.tsx` (full rewrite)
- Modify: `admin/src/pages/MasterShopCreate.test.tsx` (full rewrite)

**Interfaces:**
- Consumes: `registerShop()` from Task 6 (now master-only server-side, per Task 4).

- [ ] **Step 1: Write the failing tests (replace the whole file)**

Replace the full contents of `admin/src/pages/MasterShopCreate.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as shops from '@/lib/shops';
import MasterShopCreate from './MasterShopCreate';

const nav = vi.fn();
vi.mock('react-router-dom', async (orig) => ({
  ...(await orig() as object),
  useNavigate: () => nav,
}));

const CATEGORIES = [
  { id: 1, name: 'Barber' },
  { id: 9, name: 'Salon' },
];

function setup() {
  storage.setJSON('shop_data', { id: 1, name: 'Business Lens HQ', is_master: true });
  storage.set('shop_token', 'tok');
  vi.spyOn(shops, 'getServiceCategories').mockResolvedValue(CATEGORIES);
  return render(<MemoryRouter><ShopProvider><MasterShopCreate /></ShopProvider></MemoryRouter>);
}

describe('MasterShopCreate', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); nav.mockReset(); });

  it('submits name, email, password and category, then shows the new credentials', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({
      shop: { id: 1, name: 'Acme', email: 'acme@example.com' },
      token: 'fresh-token',
    });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business name/i), 'Acme Salon');
    await user.type(screen.getByLabelText(/phone/i), '0501234567');
    await user.type(screen.getByLabelText(/^email$/i), 'acme@example.com');
    await user.type(screen.getByLabelText(/password/i), 'a-strong-pass');
    await user.selectOptions(await screen.findByLabelText(/service category/i), '9');
    await user.click(screen.getByRole('button', { name: /create business/i }));
    expect(spy).toHaveBeenCalledWith(expect.objectContaining({
      name: 'Acme Salon',
      phone: '0501234567',
      email: 'acme@example.com',
      password: 'a-strong-pass',
      category_id: 9,
    }));
    expect(await screen.findByText(/created ✓/i)).toBeInTheDocument();
    expect(screen.getByText('acme@example.com')).toBeInTheDocument();
    expect(screen.getByText('a-strong-pass')).toBeInTheDocument();
  });

  it('blocks submit without a business name', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.click(screen.getByRole('button', { name: /create business/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/business name is required/i)).toBeInTheDocument();
  });

  it('blocks submit without an email', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business name/i), 'Acme Salon');
    await user.click(screen.getByRole('button', { name: /create business/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/email is required/i)).toBeInTheDocument();
  });

  it('blocks submit with a short password', async () => {
    const spy = vi.spyOn(shops, 'registerShop').mockResolvedValue({});
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/business name/i), 'Acme Salon');
    await user.type(screen.getByLabelText(/^email$/i), 'acme@example.com');
    await user.type(screen.getByLabelText(/password/i), 'short');
    await user.click(screen.getByRole('button', { name: /create business/i }));
    expect(spy).not.toHaveBeenCalled();
    expect(screen.getByText(/at least 8 characters/i)).toBeInTheDocument();
  });

  it('redirects non-master shops away', () => {
    storage.setJSON('shop_data', { id: 2, name: 'Some Shop', is_master: false });
    storage.set('shop_token', 'tok');
    render(<MemoryRouter><ShopProvider><MasterShopCreate /></ShopProvider></MemoryRouter>);
    expect(nav).toHaveBeenCalledWith('/');
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd admin && npx vitest run src/pages/MasterShopCreate.test.tsx`
Expected: FAIL — the component still has phone/category fields only, no email/password, no master guard.

- [ ] **Step 3: Rewrite `MasterShopCreate.tsx`**

Replace the full contents of `admin/src/pages/MasterShopCreate.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import { getServiceCategories, registerShop } from '@/lib/shops';
import { useShop } from '@/context/ShopContext';
import type { ServiceCategory } from '@/types';

type Created = { name: string; email: string; password: string };

export default function MasterShopCreate() {
  const navigate = useNavigate();
  const { shop: me } = useShop();
  const [categories, setCategories] = useState<ServiceCategory[]>([]);
  const [name, setName] = useState('');
  const [phone, setPhone] = useState('');
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [categoryId, setCategoryId] = useState('');
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [created, setCreated] = useState<Created | null>(null);
  const [copied, setCopied] = useState(false);

  useEffect(() => {
    if (me && !me.is_master) navigate('/');
  }, [me, navigate]);

  useEffect(() => {
    getServiceCategories().then(setCategories).catch(() => { /* non-fatal: empty list */ });
  }, []);

  const resetForm = () => { setName(''); setPhone(''); setEmail(''); setPassword(''); setCategoryId(''); };

  const handleSave = async () => {
    if (!name.trim()) { setError('Business name is required.'); return; }
    if (!email.trim()) { setError('Email is required.'); return; }
    if (password.trim().length < 8) { setError('Password must be at least 8 characters.'); return; }
    if (!categoryId) { setError('Please choose a category.'); return; }
    setSaving(true);
    setError('');
    try {
      const res = await registerShop({
        name: name.trim(),
        phone: phone.trim() || undefined,
        email: email.trim(),
        password,
        category_id: Number(categoryId),
        is_verified: true,
      });
      setCreated({
        name: res.shop?.name ?? name.trim(),
        email: email.trim(),
        password,
      });
      resetForm();
    } catch (e: unknown) {
      const d = (e as { response?: { data?: { message?: string } } })?.response?.data;
      setError(d?.message || 'Could not create the business.');
    } finally {
      setSaving(false);
    }
  };

  const copyCreds = async () => {
    if (!created) return;
    try {
      await navigator.clipboard.writeText(`Email: ${created.email}\nPassword: ${created.password}`);
      setCopied(true);
      setTimeout(() => setCopied(false), 1200);
    } catch { /* values stay visible */ }
  };

  return (
    <div className="m-screen c-svc-edit"><div className="m-scroll">
      <div className="svc-edit-wrap">
      <button className="c-back" onClick={() => navigate('/master')}><Icons.ChevronLeft size={16} /> Back</button>
      <h1 className="c-auth-title" style={{ textAlign: 'left', margin: '0 16px 16px' }}>Add Business</h1>

      {error && <div className="c-error-box">{error}</div>}

      {created ? (
        <div className="svc-form">
          <div className="c-master-top" style={{ marginBottom: 10 }}>
            <span className="c-master-name">{created.name} <em>· created ✓</em></span>
          </div>
          <p className="c-msd-sub" style={{ marginTop: 0 }}>Send these login details to the owner.</p>
          <div className="c-master-creds">
            <span><b>Email</b> {created.email}</span>
            <span><b>Password</b> {created.password}</span>
            <button className="c-icon-btn" aria-label="Copy new credentials" onClick={() => void copyCreds()}>
              {copied ? <Icons.Check size={14} /> : <Icons.Copy size={14} />}
            </button>
          </div>
          <button className="c-btn c-btn-block" style={{ marginTop: 16 }} onClick={() => navigate('/master')}>
            Back to Businesses
          </button>
          <button className="c-btn-ghost c-btn-block" style={{ marginTop: 8 }} onClick={() => setCreated(null)}>
            Add another
          </button>
        </div>
      ) : (
        <div className="svc-form">
          <label className="c-field-label" htmlFor="mb-name">Business Name</label>
          <div className="c-input-row">
            <input id="mb-name" type="text" placeholder="e.g. Glow Salon" value={name}
              onChange={(e) => { setName(e.target.value); setError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="mb-phone">Phone Number</label>
          <div className="c-input-row">
            <input id="mb-phone" type="tel" placeholder="+9715xxxxxxxx" value={phone}
              onChange={(e) => { setPhone(e.target.value); setError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="mb-email">Email</label>
          <div className="c-input-row">
            <input id="mb-email" type="email" placeholder="owner@business.com" value={email}
              onChange={(e) => { setEmail(e.target.value); setError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="mb-password">Password</label>
          <div className="c-input-row">
            <input id="mb-password" type="text" placeholder="At least 8 characters" value={password}
              onChange={(e) => { setPassword(e.target.value); setError(''); }} />
          </div>

          <label className="c-field-label" htmlFor="mb-category">Service Category</label>
          <div className="c-input-row">
            <select id="mb-category" value={categoryId}
              onChange={(e) => { setCategoryId(e.target.value); setError(''); }}
              style={{ width: '100%', background: 'none', border: 'none', color: 'var(--text-1)', font: 'inherit' }}>
              <option value="" disabled>Choose category…</option>
              {categories.map((c) => <option key={c.id} value={c.id}>{c.name}</option>)}
            </select>
          </div>

          <button className="c-btn c-btn-block" disabled={saving} onClick={() => void handleSave()}>
            {saving ? 'Creating…' : 'Create Business'}
          </button>
        </div>
      )}
      </div>
    </div></div>
  );
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd admin && npx vitest run src/pages/MasterShopCreate.test.tsx`
Expected: `5 passed`

- [ ] **Step 5: Commit**

```bash
git add admin/src/pages/MasterShopCreate.tsx admin/src/pages/MasterShopCreate.test.tsx
git commit -m "feat(auth): collect email+password on the Add Business form"
```

---

### Task 10: Update `MasterShopDetail.tsx` — view email, set password

**Files:**
- Modify: `admin/src/pages/MasterShopDetail.tsx:144-203` (state + `copyCreds`/`waHref` + the Credentials section)
- Modify: `admin/src/pages/MasterShopDetail.test.tsx` (fixture + new assertions)

**Interfaces:**
- Consumes: `updateMasterShop()` from Task 6.

- [ ] **Step 1: Write the failing tests (replace the whole file)**

Replace the full contents of `admin/src/pages/MasterShopDetail.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ShopProvider } from '@/context/ShopContext';
import { storage } from '@/lib/storage';
import * as lib from '@/lib/shops';
import type { MasterShop } from '@/types';
import MasterShopDetail from './MasterShopDetail';

const shop: MasterShop = {
  id: 7, name: 'Shakaina Salon', shop_code: '339416', email: 'owner@shakaina.com',
  phone: '+971500000000', category: 'Salon', location: 'Dubai', status: 'active',
  persona: '', bookings_count: 12, wa_connected: true, created_at: '2026-06-07T00:00:00Z',
};

function setup(state: { shop: MasterShop } = { shop }) {
  storage.setJSON('shop_data', { id: 1, name: 'Business Lens HQ', is_master: true });
  storage.set('shop_token', 'tok');
  return render(
    <MemoryRouter initialEntries={[{ pathname: '/master/7', state }]}>
      <ShopProvider>
        <Routes>
          <Route path="/master/:id" element={<MasterShopDetail />} />
        </Routes>
      </ShopProvider>
    </MemoryRouter>,
  );
}

describe('MasterShopDetail', () => {
  beforeEach(() => { localStorage.clear(); vi.restoreAllMocks(); });

  it('shows credentials and activity from router state', () => {
    setup();
    expect(screen.getByText('Shakaina Salon')).toBeInTheDocument();
    expect(screen.getByDisplayValue('owner@shakaina.com')).toBeInTheDocument();
    expect(screen.getByText(/12 bookings/)).toBeInTheDocument();
  });

  it('saves the email via updateMasterShop', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop, email: 'new@shakaina.com' });
    setup();
    const user = userEvent.setup();
    const emailInput = screen.getByLabelText(/^email$/i);
    await user.clear(emailInput);
    await user.type(emailInput, 'new@shakaina.com');
    await user.click(screen.getByRole('button', { name: /save email/i }));
    expect(update).toHaveBeenCalledWith(7, { email: 'new@shakaina.com' });
  });

  it('sets a new password and shows it once for copying', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/new password/i), 'brand-new-pass');
    await user.click(screen.getByRole('button', { name: /set password/i }));
    expect(update).toHaveBeenCalledWith(7, { password: 'brand-new-pass' });
    expect(await screen.findByText('brand-new-pass')).toBeInTheDocument();
  });

  it('saves a persona via updateMasterShop', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop, persona: 'You are Shakaina Salon.' });
    setup();
    const user = userEvent.setup();
    await user.type(screen.getByLabelText(/persona/i), 'You are Shakaina Salon.');
    await user.click(screen.getByRole('button', { name: /save persona/i }));
    expect(update).toHaveBeenCalledWith(7, { persona: 'You are Shakaina Salon.' });
  });

  it('toggles visibility via updateMasterShop', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop, status: 'inactive' });
    setup();
    await userEvent.setup().click(screen.getByRole('button', { name: /hide from customer app/i }));
    expect(update).toHaveBeenCalledWith(7, { status: 'inactive' });
  });

  it('toggling Business Hunt sends the updated modules', async () => {
    const update = vi.spyOn(lib, 'updateMasterShop').mockResolvedValue({ ...shop, modules: ['bookings', 'leads'] });
    setup();
    await userEvent.setup().click(screen.getByRole('button', { name: /enable business hunt/i }));
    expect(update).toHaveBeenCalledWith(7, { modules: ['bookings', 'leads'] });
  });
});
```

- [ ] **Step 2: Run the tests to verify they fail**

Run: `cd admin && npx vitest run src/pages/MasterShopDetail.test.tsx`
Expected: FAIL — the component still renders `shop.shop_code`/`shop.pin` as plain text, no editable email field, no password-set control.

- [ ] **Step 3: Update `MasterShopDetail.tsx`**

Replace lines 1-35 (imports and the top of the component, through the `error` state) with:

```tsx
import { useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getMasterShops, updateMasterShop, grantShopCredits } from '@/lib/shops';
import { shortDate } from '@/lib/format';
import { shopHasModule, type Module } from '@/lib/modules';
import type { MasterShop } from '@/types';

function initial(name: string): string {
  const c = (name || '?').trim().charAt(0);
  return c ? c.toUpperCase() : '?';
}

export default function MasterShopDetail() {
  const { id } = useParams<{ id: string }>();
  const navigate = useNavigate();
  const location = useLocation();
  const { shop: me } = useShop();

  const seeded = (location.state as { shop?: MasterShop } | null)?.shop;
  const [shop, setShop] = useState<MasterShop | null>(seeded ?? null);
  const [loading, setLoading] = useState(!seeded);
  const [persona, setPersona] = useState(seeded?.persona ?? '');
  const [savingPersona, setSavingPersona] = useState(false);
  const [personaSaved, setPersonaSaved] = useState(false);
  const [togglingStatus, setTogglingStatus] = useState(false);
  const [togglingModule, setTogglingModule] = useState<Module | null>(null);
  const [creditAmount, setCreditAmount] = useState('');
  const [grantingCredits, setGrantingCredits] = useState(false);
  const [creditMsg, setCreditMsg] = useState('');
  const [togglingSelfServe, setTogglingSelfServe] = useState(false);
  const [email, setEmail] = useState(seeded?.email ?? '');
  const [savingEmail, setSavingEmail] = useState(false);
  const [emailSaved, setEmailSaved] = useState(false);
  const [newPassword, setNewPassword] = useState('');
  const [settingPassword, setSettingPassword] = useState(false);
  const [passwordMsg, setPasswordMsg] = useState('');
  const [justSetPassword, setJustSetPassword] = useState('');
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState('');
```

Change the `useEffect` that fetches the shop (currently `setPersona(found?.persona ?? '')`) to also seed `email`:

```tsx
  useEffect(() => {
    if (me && !me.is_master) { navigate('/'); return; }
    if (seeded) return; // already have it from the list
    let alive = true;
    getMasterShops()
      .then((list) => {
        if (!alive) return;
        const found = list.find((s) => String(s.id) === String(id)) ?? null;
        setShop(found);
        setPersona(found?.persona ?? '');
        setEmail(found?.email ?? '');
      })
      .catch(() => { if (alive) setError('Could not load this business.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [id, me, navigate, seeded]);
```

Add two new handlers right after `toggleSelfServe` (before `copyCreds`):

```tsx
  const saveEmail = async () => {
    if (!shop || !email.trim()) return;
    setSavingEmail(true);
    setError('');
    try {
      const updated = await updateMasterShop(shop.id, { email: email.trim() });
      setShop(updated);
      setEmail(updated.email ?? '');
      setEmailSaved(true);
      setTimeout(() => setEmailSaved(false), 1500);
    } catch {
      setError('Could not save the email.');
    } finally {
      setSavingEmail(false);
    }
  };

  const setShopPassword = async () => {
    if (!shop) return;
    const pwd = newPassword.trim();
    if (pwd.length < 8) { setPasswordMsg('Password must be at least 8 characters.'); return; }
    setSettingPassword(true);
    setPasswordMsg('');
    try {
      await updateMasterShop(shop.id, { password: pwd });
      setJustSetPassword(pwd);
      setNewPassword('');
      setPasswordMsg('Password set ✓ — copy it below before leaving this page.');
    } catch {
      setPasswordMsg('Could not set the password.');
    } finally {
      setSettingPassword(false);
    }
  };
```

Replace `copyCreds` (which read `shop.pin`) with a version that copies whichever password was just set:

```tsx
  const copyPassword = async () => {
    if (!justSetPassword) return;
    try {
      await navigator.clipboard.writeText(justSetPassword);
      setCopied(true);
      setTimeout(() => setCopied(false), 1200);
    } catch { /* value stays visible */ }
  };
```

Delete the `waHref` line entirely (it built a WhatsApp link embedding `shop.pin`, which no longer exists):

```tsx
  const waHref = shop.phone
    ? `https://wa.me/${shop.phone.replace(/\D/g, '')}?text=${encodeURIComponent(
        `Your Business Lens login\nBusiness ID: ${shop.shop_code}\nPIN: ${shop.pin}`)}`
    : null;
```

Replace the "Credentials" section:

```tsx
      <div className="c-msd-section">
        <h3 className="c-msd-h">Credentials</h3>
        <div className="c-master-creds">
          <span><b>ID</b> {shop.shop_code || '—'}</span>
          <span><b>PIN</b> {shop.pin || '—'}</span>
          <button className="c-icon-btn" aria-label="Copy credentials" onClick={() => void copyCreds()}>
            {copied ? <Icons.Check size={14} /> : <Icons.Copy size={14} />}
          </button>
        </div>
        {waHref && (
          <a className="c-btn-ghost c-msd-action" href={waHref} target="_blank" rel="noreferrer">
            <Icons.WhatsApp size={15} /> Send login to owner
          </a>
        )}
        <p className="c-msd-help">Send these login details to the owner.</p>
      </div>
```

with:

```tsx
      <div className="c-msd-section">
        <h3 className="c-msd-h">Credentials</h3>
        <label className="c-field-label" htmlFor="msd-email">Email</label>
        <div className="c-input-row" style={{ marginBottom: 10 }}>
          <input id="msd-email" type="email" value={email}
            onChange={(e) => { setEmail(e.target.value); setEmailSaved(false); }} />
        </div>
        <button className="c-btn-ghost c-msd-action" disabled={savingEmail || !email.trim()} onClick={() => void saveEmail()}>
          {savingEmail ? 'Saving…' : emailSaved ? 'Saved ✓' : 'Save email'}
        </button>

        <label className="c-field-label" htmlFor="msd-password" style={{ marginTop: 14, display: 'block' }}>New password</label>
        <div className="c-input-row" style={{ marginBottom: 10 }}>
          <input id="msd-password" type="text" placeholder="At least 8 characters" value={newPassword}
            onChange={(e) => { setNewPassword(e.target.value); setPasswordMsg(''); }} />
        </div>
        <button className="c-btn-ghost c-msd-action" disabled={settingPassword || newPassword.trim().length < 8}
          onClick={() => void setShopPassword()}>
          {settingPassword ? 'Setting…' : 'Set password'}
        </button>
        {passwordMsg && <p className="c-msd-help">{passwordMsg}</p>}

        {justSetPassword && (
          <div className="c-master-creds" style={{ marginTop: 10 }}>
            <span><b>Password</b> {justSetPassword}</span>
            <button className="c-icon-btn" aria-label="Copy password" onClick={() => void copyPassword()}>
              {copied ? <Icons.Check size={14} /> : <Icons.Copy size={14} />}
            </button>
          </div>
        )}
        <p className="c-msd-help">Send these login details to the owner.</p>
      </div>
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd admin && npx vitest run src/pages/MasterShopDetail.test.tsx`
Expected: `6 passed`

- [ ] **Step 5: Commit**

```bash
git add admin/src/pages/MasterShopDetail.tsx admin/src/pages/MasterShopDetail.test.tsx
git commit -m "feat(auth): let master view/edit a shop's email and set its password"
```

---

### Task 11: Fix remaining `pin` fixtures in unrelated tests

**Files:**
- Modify: `admin/src/components/MasterShopCard.test.tsx:8`
- Modify: `admin/src/pages/MasterShops.test.tsx:26,32`

**Interfaces:**
- None — these are type-fixture-only fixes (`MasterShopCard.tsx` and `MasterShops.tsx` themselves never read `.pin`).

- [ ] **Step 1: Fix `MasterShopCard.test.tsx`**

Change:

```ts
const base: MasterShop = {
  id: 7, name: 'Shakaina Salon', shop_code: '339416', pin: '7201',
  phone: '+971500000000', category: 'Salon', status: 'active',
  bookings_count: 12, wa_connected: true, created_at: '2026-06-07T00:00:00Z',
};
```

to:

```ts
const base: MasterShop = {
  id: 7, name: 'Shakaina Salon', shop_code: '339416', email: 'owner@shakaina.com',
  phone: '+971500000000', category: 'Salon', status: 'active',
  bookings_count: 12, wa_connected: true, created_at: '2026-06-07T00:00:00Z',
};
```

- [ ] **Step 2: Fix `MasterShops.test.tsx`**

Change both fixture object literals — replace `pin: '2511'` → `email: 'owner1@example.com'` and `pin: '9001'` → `email: 'owner2@example.com'` (match each to its own row so both stay unique), i.e.:

```ts
        id: 30, name: 'Shakaina Salon', shop_code: '730762', pin: '2511',
```
→
```ts
        id: 30, name: 'Shakaina Salon', shop_code: '730762', email: 'owner1@example.com',
```

```ts
        id: 12, name: 'Quick Fix AC', shop_code: '101010', pin: '9001',
```
→
```ts
        id: 12, name: 'Quick Fix AC', shop_code: '101010', email: 'owner2@example.com',
```

- [ ] **Step 3: Run the full admin test suite and type-check**

Run: `cd admin && npx tsc --noEmit && npx vitest run`
Expected: all green, zero TypeScript errors.

- [ ] **Step 4: Commit**

```bash
git add admin/src/components/MasterShopCard.test.tsx admin/src/pages/MasterShops.test.tsx
git commit -m "test(auth): update MasterShop fixtures from pin to email"
```

---

### Task 12: Update Profile.tsx's read-only credentials display

**Files:**
- Modify: `admin/src/pages/Profile.tsx:53-54` (the `shopCode`/`pin` locals), `:235-255` (the Credentials block), `:266` (the QR hint text)

**Interfaces:**
- Consumes: `shop.email` (already declared on the `Shop` type, now backed by a real column as of Task 1 and returned by login as of Task 3).

- [ ] **Step 1: Update the locals**

Change:

```tsx
  const shopCode = (shop?.shop_code as string) || '';
  const pin = (shop?.pin as string) || '';
```

to:

```tsx
  const loginEmail = shop?.email || '';
```

- [ ] **Step 2: Update the Credentials block**

Change:

```tsx
        {/* Credentials */}
        {(shopCode || pin) && (
          <>
            <div className="c-section-title">Credentials</div>
            <div className="c-cred-grid">
              <div className="c-cred">
                <div className="c-cred-label">Business Code</div>
                <div className="c-cred-value-row">
                  <span className="c-cred-value">{shopCode || '—'}</span>
                  <span className="c-cred-icon"><Icons.Tag size={16} /></span>
                </div>
              </div>
              <div className="c-cred">
                <div className="c-cred-label">Access PIN</div>
                <div className="c-cred-value-row">
                  <span className="c-cred-value">{pin || '—'}</span>
                  <span className="c-cred-icon"><Icons.Key size={16} /></span>
                </div>
              </div>
            </div>
          </>
        )}
```

to:

```tsx
        {/* Credentials */}
        {loginEmail && (
          <>
            <div className="c-section-title">Credentials</div>
            <div className="c-cred-grid">
              <div className="c-cred">
                <div className="c-cred-label">Login Email</div>
                <div className="c-cred-value-row">
                  <span className="c-cred-value">{loginEmail}</span>
                  <span className="c-cred-icon"><Icons.Tag size={16} /></span>
                </div>
              </div>
            </div>
          </>
        )}
```

Note: this does NOT touch the pre-existing `form.email` input further up the page (the general profile-edit form) or its `handleSave` payload — that field is a separate, pre-existing no-op (there is no `email` rule in `UpdateShopRequest`, so it's silently dropped server-side today) and stays out of scope here. Wiring it up would let an owner self-service change their own login email while already authenticated, which conflicts with the master-controlled-credentials decision this plan implements — leave it exactly as-is.

- [ ] **Step 3: Update the QR hint text**

Change:

```tsx
              <p className="c-qr-hint">Scan to open Business Lens on your phone, then sign in with your business code &amp; PIN.</p>
```

to:

```tsx
              <p className="c-qr-hint">Scan to open Business Lens on your phone, then sign in with your email &amp; password.</p>
```

- [ ] **Step 4: Type-check**

Run: `cd admin && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Manually verify in the browser**

Run the admin dev server, log in as a test shop with an email set, open Profile, and confirm the Credentials card shows "Login Email" with the correct value and no leftover PIN/Business-Code UI.

- [ ] **Step 6: Commit**

```bash
git add admin/src/pages/Profile.tsx
git commit -m "feat(auth): show login email instead of business code+PIN on Profile"
```

---

### Task 13: Full-suite verification and deployment note

**Files:** none (verification only)

- [ ] **Step 1: Run the full backend test suite**

Run: `php artisan test`
Expected: all green. (Per project convention — see the "Run tests on droplet" note — if local PHP is broken in this environment, run this on the droplet instead: `ssh` in, `cd` to the app, `php8.4 artisan test`.)

- [ ] **Step 2: Run the full admin test + type-check suite**

Run: `cd admin && npx tsc --noEmit && npx vitest run`
Expected: all green.

- [ ] **Step 3: Manual smoke test**

Start the backend and admin dev servers. As a master-flagged shop, log in, go to `/master/new`, create a test business with an email+password, confirm the credentials screen shows them, log out, log back in as that new business using the email+password. Then go to `/master/<id>` for an existing shop, set a new password, and confirm you can log in as that shop with it.

- [ ] **Step 4: Deployment note — set the master account's own credentials before deploying**

This is not a code change, but is required before this ships to staging/prod: the master shop's own account currently has no `email`/`password` and, like every other shop, can no longer log in via `shop_code`+`pin` once this deploys. Before deploying, run on the target environment (via `php artisan tinker` or a one-off DB update) something equivalent to:

```php
$master = \App\Models\Shop::where('is_master', true)->first();
$master->email = 'francisgill1000@gmail.com'; // pick the real master login email
$master->password = 'choose-a-strong-password'; // auto-hashes via the 'hashed' cast
$master->save();
```

Do this on staging first, verify master can still log in there, then repeat on prod immediately before/during the deploy window — otherwise master locks itself out of its own dashboard the moment this ships.
