# Ask Paywall / Subscription Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Gate the whole Booking Manager + Ask app behind a paid subscription — 30-day trial, then AED 149/mo (30-day pass) or AED 1,000/yr (365-day pass) via renewable Ziina one-off payments — with master-editable pricing and per-shop status.

**Architecture:** A `subscriptions` row per shop holds one authoritative `access_until` timestamp; access = `now < access_until`. New shops start a 30-day trial. Paying via the existing Ziina one-off flow logs a `subscription_payments` row and, on the Ziina webhook, extends `access_until`. A backend middleware returns 402 for lapsed shops (master exempt); the admin SPA's `RequireSubscription` gate routes lapsed shops to `/subscribe`. Prices live in an editable `pricing` table.

**Tech Stack:** Laravel 12 / PHP 8.4 / PostgreSQL (backend, `/var/www/eloquent-backend`), React + Vite + TypeScript admin SPA (`admin/`), Ziina payments, Vitest + Testing Library (frontend tests), PHPUnit (backend tests).

## Global Constraints

- **Money is stored in fils (integer minor unit).** AED 149 = `14900`, AED 1,000 = `100000`. Ziina amounts are in fils.
- **Plan periods:** `monthly` = 30 days, `annual` = 365 days. Trial = 30 days.
- **The authenticated user is always the `Shop` model** (Sanctum token is issued on Shop; ShopUser is a token tag for RBAC). Middleware derives the shop from `$request->user()`.
- **Master is always exempt** from gating and billing: `$shop->is_master === true` bypasses every subscription check. The master shop is id 31, code `700110`.
- **Access rule is single-source:** `hasAccess = access_until !== null && now < access_until`. `status` is a derived label kept in sync on writes.
- **Frontend gate is the primary lock; backend middleware is defense-in-depth** on the authenticated shop route groups. Public customer-facing endpoints (shop browse, slot booking) are intentionally NOT gated.
- **No outbound messaging in v1** — reminders are in-app only (trial/expiry banner + `/subscribe`).
- **New admin screens reuse the Dashboard glass-card style** (mint glass cards, mint chips; no flat panels/tabs/modal overlays).
- **No local PHP/Composer in this environment.** Backend PHPUnit tests are written as part of each task and committed, but are executed where dev deps exist (CI or a PHP box); on the droplet they can't run (`php artisan test` unavailable — no dev deps). Backend verification on the droplet = `php -l` on changed files + `php artisan tinker` smoke tests. Frontend tests DO run locally via `npx vitest run`.
- **Deploy:** backend = `git pull` + composer + migrate + cache on the droplet; admin = `admin/deploy.ps1`. Test the pay loop against Ziina **test** mode (`ZIINA_TEST=true`) before going live.

---

## File Structure

**Backend (create):**
- `database/migrations/2026_07_05_000001_create_subscriptions_table.php`
- `database/migrations/2026_07_05_000002_create_subscription_payments_table.php`
- `database/migrations/2026_07_05_000003_create_pricing_table.php`
- `app/Models/Subscription.php`, `app/Models/SubscriptionPayment.php`, `app/Models/Pricing.php`
- `app/Services/SubscriptionService.php`
- `app/Http/Middleware/EnsureSubscribed.php`
- `app/Http/Controllers/SubscriptionController.php`
- `tests/Unit/SubscriptionTest.php`, `tests/Feature/SubscriptionFlowTest.php`, `tests/Feature/MasterPricingTest.php`

**Backend (modify):**
- `app/Services/Ziina.php` — extract shared `postIntent`, add `createSubscriptionIntent`
- `app/Http/Controllers/ZiinaWebhookController.php` — match subscription payments
- `app/Http/Controllers/ShopController.php` — start trial in `store()`
- `app/Http/Controllers/MasterController.php` — pricing + per-shop status + grant
- `app/Models/Shop.php` — `subscription()` relation
- `bootstrap/app.php` — register `subscription.active` middleware alias
- `routes/api.php` — apply gating; add subscription + master-pricing routes
- `config/services.php` — add `ziina.subscription_return_base` (admin URL) if not derivable

**Frontend (create):**
- `admin/src/lib/subscription.ts` — API client + types
- `admin/src/context/SubscriptionContext.tsx` — status provider
- `admin/src/components/RequireSubscription.tsx` — route gate
- `admin/src/components/TrialBanner.tsx` — in-app reminder banner
- `admin/src/pages/Subscribe.tsx` + `admin/src/pages/Subscribe.test.tsx`
- `admin/src/pages/MasterPricing.tsx` (or a section in MasterShops) + test

**Frontend (modify):**
- `admin/src/App.tsx` — `/subscribe` route + wrap app in `RequireSubscription`
- `admin/src/layout/AppShell.tsx` — render `TrialBanner`
- `admin/src/lib/api.ts` (axios instance) — 402 interceptor → `/subscribe`
- `admin/src/pages/MasterShops.tsx` / `MasterShopDetail.tsx` — pricing entry + per-shop status

---

## Task 1: Subscription tables + Pricing seed

**Files:**
- Create: `database/migrations/2026_07_05_000001_create_subscriptions_table.php`
- Create: `database/migrations/2026_07_05_000002_create_subscription_payments_table.php`
- Create: `database/migrations/2026_07_05_000003_create_pricing_table.php`

**Interfaces:**
- Produces: tables `subscriptions`, `subscription_payments`, `pricing`; `pricing` seeded with `monthly=14900`, `annual=100000`.

- [ ] **Step 1: Write the subscriptions migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->unique()->constrained()->cascadeOnDelete();
            $table->string('status', 16)->default('trialing'); // trialing|active|expired
            $table->string('plan', 16)->nullable();             // monthly|annual|null
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamp('access_until')->nullable();       // authoritative expiry
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('subscriptions'); }
};
```

- [ ] **Step 2: Write the subscription_payments migration**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('shop_id')->constrained()->cascadeOnDelete();
            $table->string('plan', 16);                 // monthly|annual
            $table->integer('amount_fils');
            $table->string('ziina_intent_id')->nullable()->index();
            $table->uuid('ziina_operation_id');
            $table->string('status', 16)->default('pending'); // pending|paid|failed
            $table->integer('period_days');             // 30 or 365
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('subscription_payments'); }
};
```

- [ ] **Step 3: Write the pricing migration (with inline seed)**

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('pricing', function (Blueprint $table) {
            $table->id();
            $table->string('plan', 16)->unique();       // monthly|annual
            $table->integer('price_fils');
            $table->timestamps();
        });
        DB::table('pricing')->insert([
            ['plan' => 'monthly', 'price_fils' => 14900,  'created_at' => now(), 'updated_at' => now()],
            ['plan' => 'annual',  'price_fils' => 100000, 'created_at' => now(), 'updated_at' => now()],
        ]);
    }
    public function down(): void { Schema::dropIfExists('pricing'); }
};
```

- [ ] **Step 4: Lint the migrations**

Run (on the droplet during deploy, or any PHP box): `php -l database/migrations/2026_07_05_000001_create_subscriptions_table.php` (repeat for 000002, 000003)
Expected: `No syntax errors detected` for each.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_05_00000*
git commit -m "feat(subscriptions): migrations for subscriptions, payments, pricing (+seed)"
```

---

## Task 2: Models + Shop relation

**Files:**
- Create: `app/Models/Subscription.php`, `app/Models/SubscriptionPayment.php`, `app/Models/Pricing.php`
- Modify: `app/Models/Shop.php`
- Test: `tests/Unit/SubscriptionTest.php`

**Interfaces:**
- Produces:
  - `Subscription::hasAccess(): bool`, `isTrialing(): bool`, `daysLeft(): int`, `extend(string $plan, int $days): void`
  - `SubscriptionPayment` model (fillable incl. `shop_id, plan, amount_fils, ziina_intent_id, ziina_operation_id, status, period_days, paid_at`)
  - `Pricing::fils(string $plan): int`
  - `Shop::subscription(): HasOne`

- [ ] **Step 1: Write the failing unit test**

```php
<?php
namespace Tests\Unit;
use App\Models\Shop;
use App\Models\Subscription;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionTest extends TestCase {
    use RefreshDatabase;

    private function shop(): Shop {
        return Shop::create(['name' => 'T', 'shop_code' => '900001', 'status' => 'active']);
    }

    public function test_has_access_is_true_before_expiry_and_false_after(): void {
        $sub = new Subscription(['status' => 'active', 'access_until' => now()->addDay()]);
        $this->assertTrue($sub->hasAccess());
        $sub->access_until = now()->subDay();
        $this->assertFalse($sub->hasAccess());
        $sub->access_until = null;
        $this->assertFalse($sub->hasAccess());
    }

    public function test_extend_stacks_from_future_expiry_and_activates(): void {
        $shop = $this->shop();
        $sub = Subscription::create([
            'shop_id' => $shop->id, 'status' => 'trialing',
            'trial_ends_at' => now()->addDays(10), 'access_until' => now()->addDays(10),
        ]);
        $sub->extend('monthly', 30);
        $this->assertSame('active', $sub->status);
        $this->assertSame('monthly', $sub->plan);
        // extended from the existing future access_until (10d) + 30d ≈ 40d out
        $this->assertEqualsWithDelta(40, now()->diffInDays($sub->access_until, false), 1);
    }

    public function test_extend_from_now_when_already_expired(): void {
        $shop = $this->shop();
        $sub = Subscription::create([
            'shop_id' => $shop->id, 'status' => 'expired', 'access_until' => now()->subDays(5),
        ]);
        $sub->extend('annual', 365);
        $this->assertEqualsWithDelta(365, now()->diffInDays($sub->access_until, false), 1);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `php artisan test --filter=SubscriptionTest`
Expected: FAIL — `Class "App\Models\Subscription" not found`.

- [ ] **Step 3: Write `Subscription` model**

```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Subscription extends Model {
    protected $fillable = ['shop_id', 'status', 'plan', 'trial_ends_at', 'access_until'];
    protected $casts = ['trial_ends_at' => 'datetime', 'access_until' => 'datetime'];

    public function shop(): BelongsTo { return $this->belongsTo(Shop::class); }

    public function hasAccess(): bool {
        return $this->access_until !== null && now()->lt($this->access_until);
    }
    public function isTrialing(): bool { return $this->status === 'trialing'; }
    public function daysLeft(): int {
        if ($this->access_until === null) return 0;
        return max(0, (int) ceil(now()->diffInDays($this->access_until, false)));
    }
    public function extend(string $plan, int $days): void {
        $base = ($this->access_until && $this->access_until->isFuture()) ? $this->access_until : now();
        $this->access_until = $base->copy()->addDays($days);
        $this->status = 'active';
        $this->plan = $plan;
        $this->save();
    }
}
```

- [ ] **Step 4: Write `SubscriptionPayment` and `Pricing` models**

`app/Models/SubscriptionPayment.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SubscriptionPayment extends Model {
    protected $fillable = [
        'shop_id', 'plan', 'amount_fils', 'ziina_intent_id',
        'ziina_operation_id', 'status', 'period_days', 'paid_at',
    ];
    protected $casts = ['paid_at' => 'datetime'];
    public function shop(): BelongsTo { return $this->belongsTo(Shop::class); }
}
```

`app/Models/Pricing.php`:
```php
<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;

class Pricing extends Model {
    protected $table = 'pricing';
    protected $fillable = ['plan', 'price_fils'];
    public static function fils(string $plan): int {
        return (int) (static::where('plan', $plan)->value('price_fils') ?? 0);
    }
}
```

- [ ] **Step 5: Add the `subscription()` relation to `Shop`**

In `app/Models/Shop.php`, add:
```php
use Illuminate\Database\Eloquent\Relations\HasOne;
// ...
public function subscription(): HasOne { return $this->hasOne(Subscription::class); }
```

- [ ] **Step 6: Run the test to verify it passes**

Run: `php artisan test --filter=SubscriptionTest`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Models/ tests/Unit/SubscriptionTest.php
git commit -m "feat(subscriptions): Subscription/SubscriptionPayment/Pricing models + Shop relation"
```

---

## Task 3: SubscriptionService

**Files:**
- Create: `app/Services/SubscriptionService.php`
- Test: add to `tests/Unit/SubscriptionTest.php`

**Interfaces:**
- Consumes: `Subscription`, `Pricing`, `SubscriptionPayment` (Task 2)
- Produces:
  - `SubscriptionService::startTrial(Shop $shop): Subscription`
  - `SubscriptionService::days(string $plan): int` (monthly→30, annual→365)
  - `SubscriptionService::price(string $plan): int` (fils, from Pricing)
  - `SubscriptionService::applyPaidPayment(SubscriptionPayment $payment): void`

- [ ] **Step 1: Write the failing test**

```php
public function test_start_trial_grants_30_days(): void {
    $shop = $this->shop();
    $sub = app(\App\Services\SubscriptionService::class)->startTrial($shop);
    $this->assertSame('trialing', $sub->status);
    $this->assertEqualsWithDelta(30, now()->diffInDays($sub->access_until, false), 1);
}

public function test_apply_paid_payment_extends_access(): void {
    $shop = $this->shop();
    $svc = app(\App\Services\SubscriptionService::class);
    $svc->startTrial($shop);
    $payment = \App\Models\SubscriptionPayment::create([
        'shop_id' => $shop->id, 'plan' => 'monthly', 'amount_fils' => 14900,
        'ziina_operation_id' => \Illuminate\Support\Str::uuid(), 'status' => 'paid', 'period_days' => 30,
    ]);
    $svc->applyPaidPayment($payment);
    $sub = $shop->subscription()->first();
    $this->assertSame('active', $sub->status);
    $this->assertSame('monthly', $sub->plan);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=SubscriptionTest`
Expected: FAIL — `Class "App\Services\SubscriptionService" not found`.

- [ ] **Step 3: Implement the service**

```php
<?php
namespace App\Services;
use App\Models\Pricing;
use App\Models\Shop;
use App\Models\Subscription;
use App\Models\SubscriptionPayment;

class SubscriptionService {
    private const DAYS = ['monthly' => 30, 'annual' => 365];
    public const TRIAL_DAYS = 30;

    public function days(string $plan): int { return self::DAYS[$plan] ?? 0; }
    public function price(string $plan): int { return Pricing::fils($plan); }

    public function startTrial(Shop $shop): Subscription {
        return Subscription::updateOrCreate(
            ['shop_id' => $shop->id],
            [
                'status' => 'trialing',
                'plan' => null,
                'trial_ends_at' => now()->addDays(self::TRIAL_DAYS),
                'access_until' => now()->addDays(self::TRIAL_DAYS),
            ],
        );
    }

    /** Idempotent extension for a paid payment. Caller guards duplicate webhook delivery. */
    public function applyPaidPayment(SubscriptionPayment $payment): void {
        $sub = Subscription::firstOrCreate(
            ['shop_id' => $payment->shop_id],
            ['status' => 'expired', 'access_until' => now()],
        );
        $sub->extend($payment->plan, $payment->period_days);
    }
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=SubscriptionTest`
Expected: PASS (5 tests total).

- [ ] **Step 5: Commit**

```bash
git add app/Services/SubscriptionService.php tests/Unit/SubscriptionTest.php
git commit -m "feat(subscriptions): SubscriptionService (startTrial, price, applyPaidPayment)"
```

---

## Task 4: Start trial on shop creation

**Files:**
- Modify: `app/Http/Controllers/ShopController.php` (`store()`)
- Test: `tests/Feature/SubscriptionFlowTest.php`

**Interfaces:**
- Consumes: `SubscriptionService::startTrial` (Task 3)

- [ ] **Step 1: Write the failing feature test**

```php
<?php
namespace Tests\Feature;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubscriptionFlowTest extends TestCase {
    use RefreshDatabase;

    public function test_creating_a_shop_starts_a_30_day_trial(): void {
        $res = $this->postJson('/api/shops', [
            'name' => 'Glow Salon', 'phone' => '+971500000000', 'category_id' => 1, 'is_verified' => true,
        ]);
        $res->assertSuccessful();
        $shop = Shop::where('name', 'Glow Salon')->firstOrFail();
        $sub = $shop->subscription()->firstOrFail();
        $this->assertSame('trialing', $sub->status);
        $this->assertTrue($sub->hasAccess());
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=SubscriptionFlowTest::test_creating_a_shop_starts_a_30_day_trial`
Expected: FAIL — no subscription row created.

- [ ] **Step 3: Hook trial creation into `store()`**

In `app/Http/Controllers/ShopController.php`, after the shop is created and before returning the response, add:
```php
app(\App\Services\SubscriptionService::class)->startTrial($shop);
```
(where `$shop` is the newly created model — inject/resolve `SubscriptionService` or call via `app()`.)

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=SubscriptionFlowTest::test_creating_a_shop_starts_a_30_day_trial`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ShopController.php tests/Feature/SubscriptionFlowTest.php
git commit -m "feat(subscriptions): start a 30-day trial when a shop is created"
```

---

## Task 5: Generalize Ziina intent creation

**Files:**
- Modify: `app/Services/Ziina.php`
- Test: `tests/Feature/SubscriptionFlowTest.php` (add)

**Interfaces:**
- Produces: `Ziina::createSubscriptionIntent(Shop $shop, string $plan, int $amountFils, array $urls): array` returning Ziina JSON (`id`, `redirect_url`, `status`).
- Refactor: private `postIntent(int $amountFils, string $operationId, string $message, array $urls): array` shared by `createIntent` and `createSubscriptionIntent`.

- [ ] **Step 1: Write the failing test (HTTP faked)**

```php
public function test_create_subscription_intent_posts_amount_in_fils(): void {
    \Illuminate\Support\Facades\Http::fake([
        '*/payment_intent' => \Illuminate\Support\Facades\Http::response(
            ['id' => 'pi_1', 'redirect_url' => 'https://pay.ziina/x', 'status' => 'pending'], 200),
    ]);
    config(['services.ziina.api_key' => 'test', 'services.ziina.base_url' => 'https://api.ziina/api']);
    $shop = Shop::create(['name' => 'T', 'shop_code' => '900002', 'status' => 'active']);
    $out = app(\App\Services\Ziina::class)->createSubscriptionIntent($shop, 'monthly', 14900, [
        'success_url' => 'https://a/s', 'cancel_url' => 'https://a/c', 'failure_url' => 'https://a/f',
    ]);
    $this->assertSame('pi_1', $out['id']);
    \Illuminate\Support\Facades\Http::assertSent(fn ($r) => $r['amount'] === 14900 && $r['currency_code'] === 'AED');
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=test_create_subscription_intent_posts_amount_in_fils`
Expected: FAIL — method not defined.

- [ ] **Step 3: Refactor `Ziina` and add the method**

Add a shared private and the new public method (keep the existing `createIntent(BookingInvoice …)` working by delegating to `postIntent`):
```php
private function postIntent(int $amountFils, string $operationId, string $message, array $urls): array {
    $response = $this->client()->post('/payment_intent', [
        'amount'        => $amountFils,
        'currency_code' => 'AED',
        'test'          => (bool) config('services.ziina.test'),
        'message'       => $message,
        'operation_id'  => $operationId,
        'success_url'   => $urls['success_url'],
        'cancel_url'    => $urls['cancel_url'],
        'failure_url'   => $urls['failure_url'],
    ]);
    $response->throw();
    return $response->json();
}

public function createSubscriptionIntent(\App\Models\Shop $shop, string $plan, int $amountFils, array $urls): array {
    return $this->postIntent($amountFils, (string) \Illuminate\Support\Str::uuid(),
        "Booking Manager {$plan} subscription — {$shop->name}", $urls);
}
```
Then change the existing `createIntent(BookingInvoice $invoice, array $urls)` body to:
```php
$amount = (int) round(((float) $invoice->total) * 100);
return $this->postIntent($amount, $invoice->ziina_operation_id,
    "Payment for invoice {$invoice->invoice_number}", $urls);
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=test_create_subscription_intent_posts_amount_in_fils`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Ziina.php tests/Feature/SubscriptionFlowTest.php
git commit -m "refactor(ziina): shared postIntent + createSubscriptionIntent for subscriptions"
```

---

## Task 6: EnsureSubscribed middleware

**Files:**
- Create: `app/Http/Middleware/EnsureSubscribed.php`
- Modify: `bootstrap/app.php` (register alias)
- Test: `tests/Feature/SubscriptionFlowTest.php` (add)

**Interfaces:**
- Produces: middleware alias `subscription.active`. Returns `402 {error:'subscription_required', status, access_until}` when the shop lacks access; passes when master or access valid.

- [ ] **Step 1: Write the failing test against a gated probe route**

```php
public function test_gate_blocks_expired_and_allows_active_and_exempts_master(): void {
    \Illuminate\Support\Facades\Route::middleware(['auth:sanctum', 'subscription.active'])
        ->get('/api/_probe', fn () => response()->json(['ok' => true]));

    $expired = Shop::create(['name' => 'E', 'shop_code' => '900003', 'status' => 'active']);
    $expired->subscription()->create(['status' => 'expired', 'access_until' => now()->subDay()]);
    $this->actingAs($expired)->getJson('/api/_probe')->assertStatus(402)
        ->assertJson(['error' => 'subscription_required']);

    $active = Shop::create(['name' => 'A', 'shop_code' => '900004', 'status' => 'active']);
    $active->subscription()->create(['status' => 'active', 'access_until' => now()->addDay()]);
    $this->actingAs($active)->getJson('/api/_probe')->assertOk();

    $master = Shop::create(['name' => 'M', 'shop_code' => '900005', 'status' => 'active', 'is_master' => true]);
    $this->actingAs($master)->getJson('/api/_probe')->assertOk();
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=test_gate_blocks_expired_and_allows_active_and_exempts_master`
Expected: FAIL — middleware alias `subscription.active` not registered.

- [ ] **Step 3: Implement the middleware**

```php
<?php
namespace App\Http\Middleware;
use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;

class EnsureSubscribed {
    public function handle(Request $request, Closure $next) {
        $shop = $request->user();
        if ($shop instanceof Shop && $shop->is_master) {
            return $next($request);
        }
        $sub = $shop?->subscription()->first();
        if ($sub && $sub->hasAccess()) {
            return $next($request);
        }
        return response()->json([
            'error' => 'subscription_required',
            'status' => $sub?->status,
            'access_until' => $sub?->access_until,
        ], 402);
    }
}
```

- [ ] **Step 4: Register the alias in `bootstrap/app.php`**

In the `->withMiddleware(function (Middleware $middleware) { ... })` block, alongside the existing `rbac.context` / `can.perm` aliases, add:
```php
$middleware->alias(['subscription.active' => \App\Http\Middleware\EnsureSubscribed::class]);
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=test_gate_blocks_expired_and_allows_active_and_exempts_master`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Middleware/EnsureSubscribed.php bootstrap/app.php tests/Feature/SubscriptionFlowTest.php
git commit -m "feat(subscriptions): EnsureSubscribed gate middleware (402, master exempt)"
```

---

## Task 7: Subscription status + checkout endpoints

**Files:**
- Create: `app/Http/Controllers/SubscriptionController.php`
- Modify: `routes/api.php`
- Test: `tests/Feature/SubscriptionFlowTest.php` (add)

**Interfaces:**
- Consumes: `SubscriptionService` (Task 3), `Ziina::createSubscriptionIntent` (Task 5)
- Produces:
  - `GET /api/shop/subscription` → `{status, plan, access_until, trial_ends_at, days_left, prices:{monthly,annual}}`
  - `POST /api/shop/subscription/checkout {plan}` → `{redirect_url, intent_id}`; creates a `pending` `subscription_payments` row.

- [ ] **Step 1: Write the failing test**

```php
public function test_checkout_creates_pending_payment_and_returns_redirect(): void {
    \Illuminate\Support\Facades\Http::fake([
        '*/payment_intent' => \Illuminate\Support\Facades\Http::response(
            ['id' => 'pi_9', 'redirect_url' => 'https://pay.ziina/9', 'status' => 'pending'], 200),
    ]);
    config(['services.ziina.api_key' => 'test', 'services.ziina.base_url' => 'https://api.ziina/api']);
    $shop = Shop::create(['name' => 'C', 'shop_code' => '900006', 'status' => 'active']);
    app(\App\Services\SubscriptionService::class)->startTrial($shop);

    $res = $this->actingAs($shop)->postJson('/api/shop/subscription/checkout', ['plan' => 'monthly']);
    $res->assertOk()->assertJson(['redirect_url' => 'https://pay.ziina/9', 'intent_id' => 'pi_9']);
    $this->assertDatabaseHas('subscription_payments', [
        'shop_id' => $shop->id, 'plan' => 'monthly', 'amount_fils' => 14900,
        'ziina_intent_id' => 'pi_9', 'status' => 'pending', 'period_days' => 30,
    ]);
}

public function test_status_returns_days_left_and_prices(): void {
    $shop = Shop::create(['name' => 'S', 'shop_code' => '900007', 'status' => 'active']);
    app(\App\Services\SubscriptionService::class)->startTrial($shop);
    $this->actingAs($shop)->getJson('/api/shop/subscription')
        ->assertOk()->assertJsonPath('status', 'trialing')
        ->assertJsonPath('prices.monthly', 14900)->assertJsonPath('prices.annual', 100000);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=SubscriptionFlowTest`
Expected: FAIL — route/controller missing.

- [ ] **Step 3: Implement the controller**

```php
<?php
namespace App\Http\Controllers;
use App\Models\Shop;
use App\Models\SubscriptionPayment;
use App\Services\SubscriptionService;
use App\Services\Ziina;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class SubscriptionController extends Controller {
    public function __construct(private SubscriptionService $subs) {}

    public function show(Request $request) {
        /** @var Shop $shop */
        $shop = $request->user();
        $sub = $shop->subscription()->first() ?? $this->subs->startTrial($shop);
        return response()->json([
            'status' => $sub->status,
            'plan' => $sub->plan,
            'access_until' => $sub->access_until,
            'trial_ends_at' => $sub->trial_ends_at,
            'days_left' => $sub->daysLeft(),
            'prices' => [
                'monthly' => $this->subs->price('monthly'),
                'annual' => $this->subs->price('annual'),
            ],
        ]);
    }

    public function checkout(Request $request, Ziina $ziina) {
        $data = $request->validate(['plan' => 'required|in:monthly,annual']);
        /** @var Shop $shop */
        $shop = $request->user();
        $plan = $data['plan'];
        $amount = $this->subs->price($plan);
        $days = $this->subs->days($plan);

        $payment = SubscriptionPayment::create([
            'shop_id' => $shop->id, 'plan' => $plan, 'amount_fils' => $amount,
            'ziina_operation_id' => (string) Str::uuid(), 'status' => 'pending', 'period_days' => $days,
        ]);

        $base = rtrim((string) config('services.ziina.return_base'), '/');
        $return = "{$base}/subscribe";
        $intent = $ziina->createSubscriptionIntent($shop, $plan, $amount, [
            'success_url' => "{$return}?pay=success",
            'cancel_url'  => "{$return}?pay=cancel",
            'failure_url' => "{$return}?pay=failed",
        ]);
        $payment->update(['ziina_intent_id' => $intent['id'] ?? null]);

        return response()->json([
            'redirect_url' => $intent['redirect_url'] ?? null,
            'intent_id' => $intent['id'] ?? null,
        ]);
    }
}
```

> Note: `services.ziina.return_base` currently points at the customer app. If the admin app is on a different host (`admin.eloquentservice.com`), add a `services.ziina.admin_return_base` env/config and use it here instead. Confirm during Task 16.

- [ ] **Step 4: Register the routes (ungated — needed to escape the paywall)**

In `routes/api.php`, add inside a plain `auth:sanctum` group (NOT behind `subscription.active`):
```php
Route::middleware('auth:sanctum')->group(function () {
    Route::get('/shop/subscription', [\App\Http\Controllers\SubscriptionController::class, 'show']);
    Route::post('/shop/subscription/checkout', [\App\Http\Controllers\SubscriptionController::class, 'checkout']);
});
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=SubscriptionFlowTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/SubscriptionController.php routes/api.php tests/Feature/SubscriptionFlowTest.php
git commit -m "feat(subscriptions): status + Ziina checkout endpoints"
```

---

## Task 8: Apply the gate to authenticated shop routes

**Files:**
- Modify: `routes/api.php`
- Test: `tests/Feature/SubscriptionFlowTest.php` (add)

**Interfaces:**
- Consumes: `subscription.active` (Task 6)
- Applies gating to the three `['auth:sanctum','rbac.context']` groups (assistant, catalogs, RBAC). Master routes, subscription routes, and the Ziina webhook stay ungated.

- [ ] **Step 1: Write the failing test**

```php
public function test_assistant_endpoint_is_gated_by_subscription(): void {
    $expired = Shop::create(['name' => 'X', 'shop_code' => '900008', 'status' => 'active']);
    $expired->subscription()->create(['status' => 'expired', 'access_until' => now()->subDay()]);
    $this->actingAs($expired)->getJson('/api/shop/assistant/history')->assertStatus(402);

    $active = Shop::create(['name' => 'Y', 'shop_code' => '900009', 'status' => 'active']);
    $active->subscription()->create(['status' => 'active', 'access_until' => now()->addDay()]);
    // 200 (or a non-402 domain response) once subscribed:
    $this->actingAs($active)->getJson('/api/shop/assistant/history')->assertStatus(200);
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=test_assistant_endpoint_is_gated_by_subscription`
Expected: FAIL — expired shop currently returns 200 (ungated).

- [ ] **Step 3: Add `subscription.active` to the three rbac groups**

In `routes/api.php`, change the group middleware arrays at the assistant group (currently `['auth:sanctum', 'rbac.context']`), the catalog group, and the RBAC group to:
```php
Route::middleware(['auth:sanctum', 'rbac.context', 'subscription.active'])->group(function () {
```
(Apply to all three `['auth:sanctum','rbac.context']` groups. Do **not** touch the `auth:sanctum`-only group that holds the master + wa-push + subscription routes.)

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=SubscriptionFlowTest`
Expected: PASS (whole file green).

- [ ] **Step 5: Commit**

```bash
git add routes/api.php tests/Feature/SubscriptionFlowTest.php
git commit -m "feat(subscriptions): gate assistant/catalog/RBAC route groups behind subscription"
```

---

## Task 9: Webhook grants access on paid subscription payment

**Files:**
- Modify: `app/Http/Controllers/ZiinaWebhookController.php`
- Test: `tests/Feature/SubscriptionFlowTest.php` (add)

**Interfaces:**
- Consumes: `SubscriptionService::applyPaidPayment` (Task 3)
- On `payment_intent` `status === 'completed'`, if a `subscription_payments` row matches `ziina_intent_id` and isn't already `paid`: mark it `paid`, set `paid_at`, and extend the shop's access. Idempotent.

- [ ] **Step 1: Write the failing test**

```php
public function test_webhook_marks_payment_paid_and_extends_access(): void {
    $shop = Shop::create(['name' => 'W', 'shop_code' => '900010', 'status' => 'active']);
    $shop->subscription()->create(['status' => 'expired', 'access_until' => now()->subDay()]);
    $payment = \App\Models\SubscriptionPayment::create([
        'shop_id' => $shop->id, 'plan' => 'annual', 'amount_fils' => 100000,
        'ziina_operation_id' => \Illuminate\Support\Str::uuid(), 'ziina_intent_id' => 'pi_paid',
        'status' => 'pending', 'period_days' => 365,
    ]);

    // no webhook secret configured in tests → HMAC check skipped
    $this->postJson('/api/ziina/webhook', [
        'event_type' => 'payment_intent.status.updated',
        'data' => ['id' => 'pi_paid', 'status' => 'completed'],
    ])->assertOk();

    $this->assertSame('paid', $payment->fresh()->status);
    $sub = $shop->subscription()->first();
    $this->assertSame('active', $sub->status);
    $this->assertTrue($sub->hasAccess());
}
```

> Match the exact webhook envelope shape the controller already parses (event key + `data.id` / `data.status`); adjust the payload keys in this test to whatever `handle()`/`handlePaymentIntent()` currently reads.

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=test_webhook_marks_payment_paid_and_extends_access`
Expected: FAIL — subscription still expired.

- [ ] **Step 3: Extend `handlePaymentIntent`**

In `app/Http/Controllers/ZiinaWebhookController.php`, inside `handlePaymentIntent($data)`, after the existing `BookingInvoice` handling (still only when `status === 'completed'`), add:
```php
$subPayment = \App\Models\SubscriptionPayment::where('ziina_intent_id', $data['id'] ?? null)->first();
if ($subPayment && $subPayment->status !== 'paid') {
    $subPayment->update(['status' => 'paid', 'paid_at' => now()]);
    app(\App\Services\SubscriptionService::class)->applyPaidPayment($subPayment);
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `php artisan test --filter=test_webhook_marks_payment_paid_and_extends_access`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/ZiinaWebhookController.php tests/Feature/SubscriptionFlowTest.php
git commit -m "feat(subscriptions): Ziina webhook grants/extends access on paid subscription"
```

---

## Task 10: Master pricing + per-shop status + grant

**Files:**
- Modify: `app/Http/Controllers/MasterController.php`, `routes/api.php`
- Test: `tests/Feature/MasterPricingTest.php`

**Interfaces:**
- Consumes: `requireMaster` (existing), `Pricing`, `Subscription`, `SubscriptionService`
- Produces:
  - `GET /api/master/pricing` → `{monthly, annual}` (fils)
  - `PATCH /api/master/pricing {monthly_fils, annual_fils}` → updated prices
  - `PATCH /api/master/shops/{shop}/subscription {grant_days}` → extends a shop's `access_until` by N days, status `active`
  - `presentShop()` gains `subscription_status`, `plan`, `access_until`, `days_left`

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MasterPricingTest extends TestCase {
    use RefreshDatabase;

    private function master(): Shop {
        return Shop::create(['name' => 'M', 'shop_code' => '700110', 'status' => 'active', 'is_master' => true]);
    }

    public function test_master_can_read_and_update_pricing(): void {
        $m = $this->master();
        $this->actingAs($m)->getJson('/api/master/pricing')
            ->assertOk()->assertJson(['monthly' => 14900, 'annual' => 100000]);
        $this->actingAs($m)->patchJson('/api/master/pricing', ['monthly_fils' => 19900, 'annual_fils' => 120000])
            ->assertOk();
        $this->assertDatabaseHas('pricing', ['plan' => 'monthly', 'price_fils' => 19900]);
    }

    public function test_master_can_grant_days_to_a_shop(): void {
        $m = $this->master();
        $shop = Shop::create(['name' => 'G', 'shop_code' => '900011', 'status' => 'active']);
        $shop->subscription()->create(['status' => 'expired', 'access_until' => now()->subDay()]);
        $this->actingAs($m)->patchJson("/api/master/shops/{$shop->id}/subscription", ['grant_days' => 30])
            ->assertOk();
        $this->assertTrue($shop->subscription()->first()->hasAccess());
    }

    public function test_non_master_cannot_touch_pricing(): void {
        $shop = Shop::create(['name' => 'N', 'shop_code' => '900012', 'status' => 'active']);
        $this->actingAs($shop)->getJson('/api/master/pricing')->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run to verify it fails**

Run: `php artisan test --filter=MasterPricingTest`
Expected: FAIL — routes/methods missing.

- [ ] **Step 3: Add controller methods**

In `app/Http/Controllers/MasterController.php`:
```php
public function pricing(Request $request) {
    $this->requireMaster($request);
    return response()->json([
        'monthly' => \App\Models\Pricing::fils('monthly'),
        'annual' => \App\Models\Pricing::fils('annual'),
    ]);
}

public function updatePricing(Request $request) {
    $this->requireMaster($request);
    $data = $request->validate([
        'monthly_fils' => ['required', 'integer', 'min:200'],
        'annual_fils' => ['required', 'integer', 'min:200'],
    ]);
    \App\Models\Pricing::where('plan', 'monthly')->update(['price_fils' => $data['monthly_fils']]);
    \App\Models\Pricing::where('plan', 'annual')->update(['price_fils' => $data['annual_fils']]);
    return response()->json(['monthly' => $data['monthly_fils'], 'annual' => $data['annual_fils']]);
}

public function grantSubscription(Request $request, Shop $shop) {
    $this->requireMaster($request);
    $data = $request->validate(['grant_days' => ['required', 'integer', 'min:1', 'max:3650']]);
    $sub = $shop->subscription()->firstOrCreate([], ['status' => 'expired', 'access_until' => now()]);
    $base = ($sub->access_until && $sub->access_until->isFuture()) ? $sub->access_until : now();
    $sub->update(['access_until' => $base->copy()->addDays($data['grant_days']), 'status' => 'active']);
    return response()->json(['ok' => true, 'access_until' => $sub->access_until, 'days_left' => $sub->daysLeft()]);
}
```
And in `presentShop()`, add to the returned array:
```php
'subscription_status' => optional($shop->subscription)->status,
'plan' => optional($shop->subscription)->plan,
'access_until' => optional(optional($shop->subscription)->access_until)->toIso8601String(),
'days_left' => optional($shop->subscription)->daysLeft() ?? 0,
```
(Eager-load `subscription` in `shops()`: `Shop::query()->with('subscription')->...`.)

- [ ] **Step 4: Register routes (ungated / master-guarded)**

In the `auth:sanctum` group that already holds `/master/shops`, add:
```php
Route::get('/master/pricing', [\App\Http\Controllers\MasterController::class, 'pricing']);
Route::patch('/master/pricing', [\App\Http\Controllers\MasterController::class, 'updatePricing']);
Route::patch('/master/shops/{shop}/subscription', [\App\Http\Controllers\MasterController::class, 'grantSubscription']);
```

- [ ] **Step 5: Run to verify it passes**

Run: `php artisan test --filter=MasterPricingTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MasterController.php routes/api.php tests/Feature/MasterPricingTest.php
git commit -m "feat(master): editable pricing, per-shop subscription status, manual grant"
```

---

## Task 11: Frontend subscription client + context

**Files:**
- Create: `admin/src/lib/subscription.ts`, `admin/src/context/SubscriptionContext.tsx`
- Modify: `admin/src/App.tsx` (wrap provider)

**Interfaces:**
- Produces:
  - `getSubscription(): Promise<SubStatus>`, `startCheckout(plan): Promise<{redirect_url:string}>`, `SubStatus` type
  - `useSubscription()` → `{ status, refresh }` from context

- [ ] **Step 1: Write the API client**

```ts
// admin/src/lib/subscription.ts
import { api } from './api';

export type SubStatus = {
  status: 'trialing' | 'active' | 'expired';
  plan: 'monthly' | 'annual' | null;
  access_until: string | null;
  trial_ends_at: string | null;
  days_left: number;
  prices: { monthly: number; annual: number };
};

export async function getSubscription(): Promise<SubStatus> {
  const { data } = await api.get('/shop/subscription');
  return data;
}

export async function startCheckout(plan: 'monthly' | 'annual'): Promise<{ redirect_url: string; intent_id: string }> {
  const { data } = await api.post('/shop/subscription/checkout', { plan });
  return data;
}
```

- [ ] **Step 2: Write the context provider**

```tsx
// admin/src/context/SubscriptionContext.tsx
import { createContext, useContext, useEffect, useState, useCallback, ReactNode } from 'react';
import { getSubscription, type SubStatus } from '@/lib/subscription';
import { useShop } from './ShopContext';

type Ctx = { sub: SubStatus | null; loading: boolean; refresh: () => Promise<void> };
const SubscriptionContext = createContext<Ctx>({ sub: null, loading: true, refresh: async () => {} });

export function SubscriptionProvider({ children }: { children: ReactNode }) {
  const { shop } = useShop();
  const [sub, setSub] = useState<SubStatus | null>(null);
  const [loading, setLoading] = useState(true);

  const refresh = useCallback(async () => {
    if (!shop || shop.is_master) { setSub(null); setLoading(false); return; }
    try { setSub(await getSubscription()); } catch { /* leave as-is */ } finally { setLoading(false); }
  }, [shop]);

  useEffect(() => { void refresh(); }, [refresh]);
  return <SubscriptionContext.Provider value={{ sub, loading, refresh }}>{children}</SubscriptionContext.Provider>;
}

export const useSubscription = () => useContext(SubscriptionContext);
```

- [ ] **Step 3: Wrap the app in the provider**

In `admin/src/App.tsx`, wrap the authenticated tree (inside `RequireShop`/`ShopProvider`) with `<SubscriptionProvider>`.

- [ ] **Step 4: Typecheck**

Run: `cd admin && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add admin/src/lib/subscription.ts admin/src/context/SubscriptionContext.tsx admin/src/App.tsx
git commit -m "feat(admin): subscription API client + context provider"
```

---

## Task 12: RequireSubscription gate + 402 interceptor

**Files:**
- Create: `admin/src/components/RequireSubscription.tsx`
- Modify: `admin/src/App.tsx` (nest gate, add `/subscribe` route placeholder), `admin/src/lib/api.ts` (402 interceptor)

**Interfaces:**
- Consumes: `useSubscription` (Task 11)
- Produces: a route element that renders `<Outlet/>` when master or `hasAccess`, else `<Navigate to="/subscribe"/>`.

- [ ] **Step 1: Write the gate**

```tsx
// admin/src/components/RequireSubscription.tsx
import { Navigate, Outlet } from 'react-router-dom';
import { useShop } from '@/context/ShopContext';
import { useSubscription } from '@/context/SubscriptionContext';
import { Spinner } from './Spinner';

export default function RequireSubscription() {
  const { shop } = useShop();
  const { sub, loading } = useSubscription();
  if (shop?.is_master) return <Outlet />;
  if (loading) return <Spinner label="Loading…" />;
  const hasAccess = sub?.status === 'trialing' || sub?.status === 'active';
  return hasAccess ? <Outlet /> : <Navigate to="/subscribe" replace />;
}
```

- [ ] **Step 2: Add the 402 interceptor**

In `admin/src/lib/api.ts`, on the axios response interceptor, redirect to `/subscribe` on a 402:
```ts
api.interceptors.response.use(
  (r) => r,
  (error) => {
    if (error?.response?.status === 402 && !location.pathname.startsWith('/subscribe')) {
      location.assign('/subscribe');
    }
    return Promise.reject(error);
  },
);
```
(Merge with the existing interceptor if one exists — don't clobber current 401 handling.)

- [ ] **Step 3: Nest the gate in the router**

In `admin/src/App.tsx`, put the app's authenticated screens under `RequireSubscription` (inside `RequireShop` → `AppShell`), and add a `/subscribe` route that is reachable WITHOUT the gate (so lapsed users can pay). E.g. keep `/subscribe` as a sibling under `AppShell` but outside `RequireSubscription`.

- [ ] **Step 4: Typecheck**

Run: `cd admin && npx tsc --noEmit`
Expected: no errors (the `/subscribe` page is added in Task 13; use a temporary `<div/>` placeholder element if needed to compile).

- [ ] **Step 5: Commit**

```bash
git add admin/src/components/RequireSubscription.tsx admin/src/lib/api.ts admin/src/App.tsx
git commit -m "feat(admin): RequireSubscription gate + 402 redirect to /subscribe"
```

---

## Task 13: `/subscribe` paywall screen

**Files:**
- Create: `admin/src/pages/Subscribe.tsx`, `admin/src/pages/Subscribe.test.tsx`
- Modify: `admin/src/App.tsx` (wire the real page into the `/subscribe` route)

**Interfaces:**
- Consumes: `useSubscription`, `startCheckout` (Task 11)

- [ ] **Step 1: Write the failing test**

```tsx
// admin/src/pages/Subscribe.test.tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import * as subLib from '@/lib/subscription';
import Subscribe from './Subscribe';

vi.mock('@/context/SubscriptionContext', () => ({
  useSubscription: () => ({
    sub: { status: 'expired', plan: null, access_until: null, trial_ends_at: null, days_left: 0,
      prices: { monthly: 14900, annual: 100000 } },
    loading: false, refresh: vi.fn(),
  }),
}));

describe('Subscribe', () => {
  beforeEach(() => { vi.restoreAllMocks(); });

  it('shows both plans and starts checkout for the chosen plan', async () => {
    const spy = vi.spyOn(subLib, 'startCheckout').mockResolvedValue({ redirect_url: 'https://pay/x', intent_id: 'pi' });
    render(<MemoryRouter><Subscribe /></MemoryRouter>);
    expect(screen.getByText(/149/)).toBeInTheDocument();
    expect(screen.getByText(/1,?000/)).toBeInTheDocument();
    const user = (await import('@testing-library/user-event')).default.setup();
    await user.click(screen.getByRole('button', { name: /monthly/i }));
    expect(spy).toHaveBeenCalledWith('monthly');
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd admin && npx vitest run src/pages/Subscribe.test.tsx`
Expected: FAIL — `Subscribe` not found.

- [ ] **Step 3: Implement the screen (glass-card style)**

```tsx
// admin/src/pages/Subscribe.tsx
import { useEffect, useState } from 'react';
import { useSubscription } from '@/context/SubscriptionContext';
import { startCheckout } from '@/lib/subscription';

const aed = (fils: number) => `AED ${(fils / 100).toLocaleString('en-AE')}`;

export default function Subscribe() {
  const { sub, refresh } = useSubscription();
  const [busy, setBusy] = useState<'monthly' | 'annual' | null>(null);
  const [error, setError] = useState('');

  useEffect(() => {
    const p = new URLSearchParams(location.search).get('pay');
    if (p === 'success') void refresh();
  }, [refresh]);

  const choose = async (plan: 'monthly' | 'annual') => {
    setBusy(plan); setError('');
    try { const { redirect_url } = await startCheckout(plan); location.href = redirect_url; }
    catch { setError('Could not start payment. Please try again.'); setBusy(null); }
  };

  const monthly = sub?.prices.monthly ?? 14900;
  const annual = sub?.prices.annual ?? 100000;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head" style={{ paddingTop: 18 }}>
        <h1 className="c-page-title">Subscribe</h1>
        <p className="c-page-sub">Unlock Booking Manager + Ask for your business.</p>
      </div>
      {error && <div className="c-error-box">{error}</div>}
      <div className="svc-grid">
        <div className="c-svc-card">
          <div className="c-svc-body">
            <div className="c-row-title">Monthly</div>
            <div className="c-svc-price-inline">{aed(monthly)}<span> / month</span></div>
          </div>
          <div className="c-svc-actions">
            <button className="c-btn c-btn-block" disabled={busy !== null} onClick={() => void choose('monthly')}>
              {busy === 'monthly' ? 'Starting…' : 'Choose Monthly'}
            </button>
          </div>
        </div>
        <div className="c-svc-card">
          <div className="c-svc-body">
            <div className="c-row-title">Annual <em>· best value</em></div>
            <div className="c-svc-price-inline">{aed(annual)}<span> / year</span></div>
          </div>
          <div className="c-svc-actions">
            <button className="c-btn c-btn-block" disabled={busy !== null} onClick={() => void choose('annual')}>
              {busy === 'annual' ? 'Starting…' : 'Choose Annual'}
            </button>
          </div>
        </div>
      </div>
    </div></div>
  );
}
```

- [ ] **Step 4: Wire the route and run the test**

Point the `/subscribe` route in `App.tsx` at `<Subscribe/>`. Then:
Run: `cd admin && npx vitest run src/pages/Subscribe.test.tsx`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src/pages/Subscribe.tsx admin/src/pages/Subscribe.test.tsx admin/src/App.tsx
git commit -m "feat(admin): /subscribe paywall screen (monthly/annual Ziina checkout)"
```

---

## Task 14: Trial/expiry banner

**Files:**
- Create: `admin/src/components/TrialBanner.tsx`
- Modify: `admin/src/layout/AppShell.tsx`
- Test: `admin/src/components/TrialBanner.test.tsx`

**Interfaces:**
- Consumes: `useSubscription` (Task 11)

- [ ] **Step 1: Write the failing test**

```tsx
// admin/src/components/TrialBanner.test.tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import TrialBanner from './TrialBanner';

vi.mock('@/context/SubscriptionContext', () => ({
  useSubscription: () => ({ sub: { status: 'trialing', days_left: 5 }, loading: false, refresh: vi.fn() }),
}));

describe('TrialBanner', () => {
  it('shows days left during trial', () => {
    render(<MemoryRouter><TrialBanner /></MemoryRouter>);
    expect(screen.getByText(/5 days left/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd admin && npx vitest run src/components/TrialBanner.test.tsx`
Expected: FAIL — component not found.

- [ ] **Step 3: Implement the banner**

```tsx
// admin/src/components/TrialBanner.tsx
import { Link } from 'react-router-dom';
import { useSubscription } from '@/context/SubscriptionContext';

export default function TrialBanner() {
  const { sub } = useSubscription();
  if (!sub || sub.status !== 'trialing') return null;
  const urgent = sub.days_left <= 3;
  return (
    <div className="c-trial-banner" style={urgent ? { borderColor: 'var(--warn)' } : undefined}>
      <span>{sub.days_left} days left in your free trial</span>
      <Link to="/subscribe" className="c-btn" style={{ padding: '6px 12px', fontSize: 13 }}>Subscribe</Link>
    </div>
  );
}
```
Add a small `.c-trial-banner` rule to `admin/src/styles/customer.css` (mint glass strip, flex space-between, matches existing card styling).

- [ ] **Step 4: Render it in `AppShell`**

In `admin/src/layout/AppShell.tsx`, render `<TrialBanner />` near the top of the shell (above the routed content), so it shows on every authenticated screen.

- [ ] **Step 5: Run to verify it passes**

Run: `cd admin && npx vitest run src/components/TrialBanner.test.tsx`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add admin/src/components/TrialBanner.tsx admin/src/components/TrialBanner.test.tsx admin/src/layout/AppShell.tsx admin/src/styles/customer.css
git commit -m "feat(admin): in-app trial/expiry banner (escalates near expiry)"
```

---

## Task 15: Master pricing UI + per-shop status

**Files:**
- Create: `admin/src/lib/masterPricing.ts`
- Modify: `admin/src/pages/MasterShops.tsx` (pricing card + status on cards), `admin/src/components/MasterShopCard.tsx` (status chip), `admin/src/types.ts` (extend `MasterShop`)
- Test: `admin/src/pages/MasterShops.test.tsx` (extend)

**Interfaces:**
- Consumes: master endpoints (Task 10)
- Produces: `getMasterPricing()`, `updateMasterPricing({monthly_fils, annual_fils})`, `grantShopDays(shopId, days)`

- [ ] **Step 1: Write the API client**

```ts
// admin/src/lib/masterPricing.ts
import { api } from './api';
export async function getMasterPricing(): Promise<{ monthly: number; annual: number }> {
  const { data } = await api.get('/master/pricing'); return data;
}
export async function updateMasterPricing(p: { monthly_fils: number; annual_fils: number }) {
  const { data } = await api.patch('/master/pricing', p); return data;
}
export async function grantShopDays(shopId: number, grant_days: number) {
  const { data } = await api.patch(`/master/shops/${shopId}/subscription`, { grant_days }); return data;
}
```

- [ ] **Step 2: Extend the `MasterShop` type + card status chip**

In `admin/src/types.ts`, add to `MasterShop`: `subscription_status?: string; plan?: string | null; access_until?: string | null; days_left?: number;`
In `MasterShopCard.tsx`, render a small status chip (reuse `.c-msc-tag` styling) showing `trialing (Nd)` / `active` / `expired`.

- [ ] **Step 3: Add a pricing card to `MasterShops.tsx`**

Add a glass card near the top with two number inputs (monthly/annual in AED, converted to fils on save) that calls `getMasterPricing` on mount and `updateMasterPricing` on save.

- [ ] **Step 4: Extend the master test**

```tsx
it('shows subscription status on business cards', async () => {
  vi.spyOn(shopsLib, 'getMasterShops').mockResolvedValue([
    { id: 30, name: 'Shakaina Salon', shop_code: '730762', status: 'active',
      subscription_status: 'trialing', days_left: 12, wa_connected: false } as any,
  ]);
  setup();
  expect(await screen.findByText('Shakaina Salon')).toBeInTheDocument();
  expect(screen.getByText(/trialing/i)).toBeInTheDocument();
});
```

- [ ] **Step 5: Run tests + typecheck**

Run: `cd admin && npx vitest run src/pages/MasterShops.test.tsx && npx tsc --noEmit`
Expected: PASS + no type errors.

- [ ] **Step 6: Commit**

```bash
git add admin/src/lib/masterPricing.ts admin/src/pages/MasterShops.tsx admin/src/components/MasterShopCard.tsx admin/src/types.ts admin/src/pages/MasterShops.test.tsx
git commit -m "feat(master): pricing editor UI + per-shop subscription status chips"
```

---

## Task 16: Deploy + end-to-end verification (Ziina test mode)

**Files:** none (deploy + smoke test)

- [ ] **Step 1: Confirm the admin return URL config**

Decide the `/subscribe` return base (admin host). If `services.ziina.return_base` points at the customer app, add `ZIINA_ADMIN_RETURN_BASE=https://admin.eloquentservice.com` to the backend `.env` and use it in `SubscriptionController::checkout` (Task 7 note). Verify `ZIINA_TEST=true` for the smoke test.

- [ ] **Step 2: Deploy the backend**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && git pull && \
  php8.4 /usr/local/bin/composer install --no-dev --optimize-autoloader --no-interaction && \
  php artisan migrate --force && \
  php artisan config:clear && php artisan route:clear && \
  php artisan config:cache && php artisan route:cache && \
  chown -R www-data:www-data .'
```

- [ ] **Step 3: Verify migrations + pricing seed on the droplet**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && php artisan tinker --execute="echo App\Models\Pricing::fils(\"monthly\").\"/\".App\Models\Pricing::fils(\"annual\");"'
```
Expected: `14900/100000`.

- [ ] **Step 4: Deploy the admin SPA**

```bash
pwsh admin/deploy.ps1
```
Expected: `HTTP/1.1 200 OK`.

- [ ] **Step 5: Smoke-test the full loop (Ziina test mode)**

- Log into a non-master shop → confirm the trial banner shows days left and the app is usable.
- Manually expire it: `ssh … php artisan tinker --execute="App\Models\Subscription::where('shop_id', <id>)->update(['access_until' => now()->subDay(), 'status' => 'expired']);"`
- Reload → app redirects to `/subscribe`.
- Click Monthly → Ziina test page → pay with a test card → return to `/subscribe?pay=success` → app unlocks.
- Confirm: `subscription_payments` row is `paid`, subscription `active`, `access_until` ≈ now+30d.
- Log into master (700110) → confirm NO gate, pricing editor works, shop status chips show.

- [ ] **Step 6: Flip to live**

Once verified, set `ZIINA_TEST=false` in the backend `.env`, `php artisan config:cache`, and re-verify one real low-risk payment (or leave in test until launch per Francis).

---

## Self-Review

**Spec coverage:** §5 tables → Task 1; models/access rule → Task 2; SubscriptionService → Task 3; trial init (§6.1) → Task 4; Ziina generalization (§6.3) → Task 5; gate middleware (§6.4) → Tasks 6, 8; subscription endpoints (§6.5) → Task 7; webhook (§6.6) → Task 9; master pricing/status/grant (§6.8) → Task 10; reminders (§6.7, in-app) → Task 14; frontend gate/context/screen/banner (§7) → Tasks 11–14; master UI (§7.4) → Task 15; rollout (§11) → Task 16. All spec sections covered.

**Placeholder scan:** No TBD/TODO; every code step contains real code. The one open config decision (admin return base) is called out explicitly in Tasks 7 & 16 with a concrete resolution, not left vague.

**Type consistency:** `hasAccess()/extend()/daysLeft()` (Task 2) used consistently in Tasks 3/6/10; `SubStatus` shape (Task 11) matches `SubscriptionController::show` JSON (Task 7); `startCheckout`/`getSubscription` names consistent across Tasks 11–15; `subscription.active` alias consistent across Tasks 6/8. Prices in fils throughout.
