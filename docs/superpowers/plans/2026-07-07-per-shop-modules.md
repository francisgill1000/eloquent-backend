# Per-Shop Modules Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let the master account choose which modules (`bookings` / `leads`) each shop sees, gating the nav, client routes, and Leads API accordingly — without changing pricing.

**Architecture:** A `modules` JSON array on `shops` (default `['bookings']`). Backend enforces it with a `module:{name}` route middleware on the Leads endpoints and a `Shop::hasModule()` helper. The React SPA reads `shop.modules` from the existing shop context and gates the desktop rail, mobile tabs, the Settings link-list, and the `/leads` routes through one shared `lib/modules.ts` helper. The master sets it via checkboxes on the shop-detail screen.

**Tech Stack:** Laravel 11 (PHP 8.4), Sanctum auth, React + react-router-dom, Vitest.

## Global Constraints

- **Never run backend tests against prod.** Run PHP tests on the droplet (php8.4) against a **scratch DB**, never local (local PHP is broken) and never the prod connection. Dump/verify connection first.
- **Module values are exactly `'bookings'` and `'leads'`** — a string union, validated server-side (`in:bookings,leads`).
- **Master is always exempt** from module gating (sees everything), matching the existing `EnsureSubscribed` pattern.
- **Default modules = `['bookings']`** for every existing and new shop.
- **No pricing/billing changes** — this feature only toggles visibility.
- **Deploy flow:** local → staging → prod. Never destructive on prod.

---

### Task 1: `modules` column, model cast, default, and `hasModule()`

**Files:**
- Create: `database/migrations/2026_07_07_000000_add_modules_to_shops.php`
- Modify: `app/Models/Shop.php` (casts ~line 31-33; `creating` hook ~line 37-41; add `hasModule` method)
- Test: `tests/Unit/ShopModulesTest.php`

**Interfaces:**
- Produces: `Shop::hasModule(string $module): bool`; `shops.modules` JSON column cast to `array`; new/existing shops default to `['bookings']`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Unit/ShopModulesTest.php
namespace Tests\Unit;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_new_shop_defaults_to_bookings_module(): void
    {
        $shop = Shop::create(['name' => 'Test']);
        $this->assertSame(['bookings'], $shop->fresh()->modules);
    }

    public function test_has_module_reflects_the_array(): void
    {
        $shop = Shop::create(['name' => 'Test', 'modules' => ['bookings', 'leads']]);
        $this->assertTrue($shop->hasModule('leads'));
        $this->assertTrue($shop->hasModule('bookings'));

        $bookingsOnly = Shop::create(['name' => 'B', 'modules' => ['bookings']]);
        $this->assertFalse($bookingsOnly->hasModule('leads'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (on droplet, scratch DB): `php artisan test --filter=ShopModulesTest`
Expected: FAIL — `modules` column/method missing.

- [ ] **Step 3: Write the migration**

```php
<?php
// database/migrations/2026_07_07_000000_add_modules_to_shops.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->json('modules')->nullable()->after('is_master');
        });
        // Backfill every existing shop to the bookings module.
        DB::table('shops')->update(['modules' => json_encode(['bookings'])]);
    }

    public function down(): void
    {
        Schema::table('shops', fn (Blueprint $table) => $table->dropColumn('modules'));
    }
};
```

> If `is_master` is not a column on `shops`, drop the `->after('is_master')`.

- [ ] **Step 4: Add cast, default, and `hasModule` to the model**

In `app/Models/Shop.php`, add to `$casts`:

```php
    protected $casts = [
        'last_login_at' => 'datetime',
        'modules' => 'array',
    ];
```

In the `creating` closure (inside `booted()`), add the default:

```php
        static::creating(function ($shop) {
            $shop->status = self::ACTIVE;
            $shop->shop_code = self::resolveShopCode($shop->shop_code ?? null);
            $shop->pin = self::resolvePin($shop->pin ?? null, $shop->shop_code);
            $shop->modules = $shop->modules ?: ['bookings'];
        });
```

Add the helper method (near the other instance methods):

```php
    /** True when this shop has the given product module enabled. */
    public function hasModule(string $module): bool
    {
        return in_array($module, $this->modules ?? [], true);
    }
```

- [ ] **Step 5: Run test to verify it passes**

Run (droplet, scratch DB): `php artisan test --filter=ShopModulesTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_07_000000_add_modules_to_shops.php app/Models/Shop.php tests/Unit/ShopModulesTest.php
git commit -m "feat(shops): add modules column with bookings default + hasModule()"
```

---

### Task 2: `module:{name}` route middleware on the Leads API

**Files:**
- Create: `app/Http/Middleware/EnsureShopModule.php`
- Modify: `bootstrap/app.php:18-22` (alias map)
- Modify: `routes/api.php:172` (Leads group middleware)
- Test: `tests/Feature/ModuleMiddlewareTest.php`

**Interfaces:**
- Consumes: `Shop::hasModule()` from Task 1.
- Produces: route alias `module` → `EnsureShopModule`; a shop without the `leads` module gets HTTP 403 `{"error":"module_not_enabled","module":"leads"}` on `/api/shop/leads*`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/ModuleMiddlewareTest.php
namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ModuleMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    public function test_shop_without_leads_module_is_forbidden_from_leads_index(): void
    {
        $shop = Shop::create(['name' => 'BookingsOnly', 'modules' => ['bookings']]);
        Sanctum::actingAs($shop, ['*']);

        $this->getJson('/api/shop/leads')
            ->assertStatus(403)
            ->assertJson(['error' => 'module_not_enabled', 'module' => 'leads']);
    }

    public function test_shop_with_leads_module_passes_the_gate(): void
    {
        $shop = Shop::create(['name' => 'HasLeads', 'modules' => ['bookings', 'leads']]);
        Sanctum::actingAs($shop, ['*']);

        // 200 (or non-403); the gate lets it through to the controller.
        $this->getJson('/api/shop/leads')->assertStatus(200);
    }
}
```

> If `/api/shop/leads` requires an active subscription and returns 402 in the second test, give the shop a valid subscription in that test (mirror an existing Leads feature test's setup) — the assertion that matters is "not 403".

- [ ] **Step 2: Run test to verify it fails**

Run (droplet, scratch DB): `php artisan test --filter=ModuleMiddlewareTest`
Expected: FAIL — no `module` alias / both requests currently pass.

- [ ] **Step 3: Write the middleware**

```php
<?php
// app/Http/Middleware/EnsureShopModule.php
namespace App\Http\Middleware;

use App\Models\Shop;
use Closure;
use Illuminate\Http\Request;

class EnsureShopModule
{
    /**
     * Block shops that don't have the given product module enabled. The master
     * account is always exempt (mirrors EnsureSubscribed). Returns 403 with a
     * machine-readable code the admin SPA can react to.
     */
    public function handle(Request $request, Closure $next, string $module)
    {
        $shop = $request->user();

        if ($shop instanceof Shop && ($shop->is_master || $shop->hasModule($module))) {
            return $next($request);
        }

        return response()->json([
            'error' => 'module_not_enabled',
            'module' => $module,
        ], 403);
    }
}
```

- [ ] **Step 4: Register the alias**

In `bootstrap/app.php`, add to the `$middleware->alias([...])` map:

```php
        $middleware->alias([
            'rbac.context' => \App\Http\Middleware\SetRbacContext::class,
            'can.perm' => \App\Http\Middleware\EnsurePermission::class,
            'subscription.active' => \App\Http\Middleware\EnsureSubscribed::class,
            'module' => \App\Http\Middleware\EnsureShopModule::class,
        ]);
```

- [ ] **Step 5: Apply it to the Leads route group**

In `routes/api.php:172`, add `'module:leads'` to that group's middleware:

```php
Route::middleware(['auth:sanctum', 'rbac.context', 'subscription.active', 'module:leads'])->group(function () {
    Route::get   ('/shop/leads/search',           [\App\Http\Controllers\LeadController::class, 'search']);
    // ...rest of the /shop/leads/* and /shop/lead-messages/* routes unchanged...
});
```

- [ ] **Step 6: Run test to verify it passes**

Run (droplet, scratch DB): `php artisan test --filter=ModuleMiddlewareTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Commit**

```bash
git add app/Http/Middleware/EnsureShopModule.php bootstrap/app.php routes/api.php tests/Feature/ModuleMiddlewareTest.php
git commit -m "feat(api): gate /shop/leads behind the leads module (module middleware)"
```

---

### Task 3: Master can read + set a shop's modules

**Files:**
- Modify: `app/Http/Controllers/MasterController.php:53-56` (validation in `updateShop`), `:73-93` (`presentShop`)
- Test: `tests/Feature/MasterUpdateModulesTest.php`

**Interfaces:**
- Consumes: `Shop::hasModule()` / `modules` cast from Task 1.
- Produces: `PATCH /api/master/shops/{shop}` accepts `modules: string[]`; `presentShop` returns `modules`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// tests/Feature/MasterUpdateModulesTest.php
namespace Tests\Feature;

use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class MasterUpdateModulesTest extends TestCase
{
    use RefreshDatabase;

    public function test_master_can_set_shop_modules(): void
    {
        $master = Shop::create(['name' => 'Master', 'is_master' => true]);
        $target = Shop::create(['name' => 'Target', 'modules' => ['bookings']]);
        Sanctum::actingAs($master, ['*']);

        $res = $this->patchJson("/api/master/shops/{$target->id}", [
            'modules' => ['bookings', 'leads'],
        ]);

        $res->assertOk()->assertJsonPath('data.modules', ['bookings', 'leads']);
        $this->assertSame(['bookings', 'leads'], $target->fresh()->modules);
    }

    public function test_invalid_module_is_rejected(): void
    {
        $master = Shop::create(['name' => 'Master', 'is_master' => true]);
        $target = Shop::create(['name' => 'Target', 'modules' => ['bookings']]);
        Sanctum::actingAs($master, ['*']);

        $this->patchJson("/api/master/shops/{$target->id}", [
            'modules' => ['bookings', 'nonsense'],
        ])->assertStatus(422);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run (droplet, scratch DB): `php artisan test --filter=MasterUpdateModulesTest`
Expected: FAIL — `modules` not validated/persisted, not in payload.

- [ ] **Step 3: Add validation in `updateShop`**

In `MasterController::updateShop`, extend the `$request->validate([...])` array:

```php
        $data = $request->validate([
            'status' => ['sometimes', 'in:active,inactive'],
            'persona' => ['sometimes', 'nullable', 'string', 'max:20000'],
            'modules' => ['sometimes', 'array'],
            'modules.*' => ['string', 'in:bookings,leads'],
        ]);
```

- [ ] **Step 4: Return `modules` from `presentShop`**

Add one line to the `presentShop` return array (near `is_master`):

```php
            'is_master' => (bool) $shop->is_master,
            'modules' => $shop->modules ?? ['bookings'],
```

- [ ] **Step 5: Run test to verify it passes**

Run (droplet, scratch DB): `php artisan test --filter=MasterUpdateModulesTest`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MasterController.php tests/Feature/MasterUpdateModulesTest.php
git commit -m "feat(master): read + set per-shop modules via updateShop"
```

---

### Task 4: Frontend shared module helper + types

**Files:**
- Create: `admin/src/lib/modules.ts`
- Modify: `admin/src/types.ts` (Shop type ~line 65; MasterShop type ~line 176)
- Modify: `admin/src/lib/shops.ts` (payload type of `updateMasterShop`)
- Test: `admin/src/lib/modules.test.ts`

**Interfaces:**
- Produces: `type Module = 'bookings' | 'leads'`; `shopHasModule(shop, module): boolean`; `navVisible(modules: Module[], shop): boolean`. Shop/MasterShop gain `modules?: Module[]`.

- [ ] **Step 1: Write the failing test**

```ts
// admin/src/lib/modules.test.ts
import { describe, it, expect } from 'vitest';
import { shopHasModule, navVisible } from './modules';
import type { Shop } from '@/types';

const shop = (modules: string[], is_master = false) =>
  ({ modules, is_master } as unknown as Shop);

describe('shopHasModule', () => {
  it('is true when the module is present', () => {
    expect(shopHasModule(shop(['bookings', 'leads']), 'leads')).toBe(true);
  });
  it('is false when the module is absent', () => {
    expect(shopHasModule(shop(['bookings']), 'leads')).toBe(false);
  });
  it('defaults a shop with no modules to bookings only', () => {
    expect(shopHasModule({} as Shop, 'bookings')).toBe(true);
    expect(shopHasModule({} as Shop, 'leads')).toBe(false);
  });
  it('master sees everything', () => {
    expect(shopHasModule(shop([], true), 'leads')).toBe(true);
  });
  it('null shop has nothing', () => {
    expect(shopHasModule(null, 'bookings')).toBe(false);
  });
});

describe('navVisible', () => {
  it('shows an item if any of its modules match', () => {
    expect(navVisible(['bookings', 'leads'], shop(['leads']))).toBe(true);
    expect(navVisible(['bookings'], shop(['leads']))).toBe(false);
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npm run test -- modules`
Expected: FAIL — `./modules` does not exist.

- [ ] **Step 3: Write `lib/modules.ts`**

```ts
// admin/src/lib/modules.ts
import type { Shop } from '@/types';

/** The product modules a shop can have enabled. */
export type Module = 'bookings' | 'leads';

/** True if the shop has the module. Master sees everything; a shop with no
 *  modules set falls back to bookings-only (matches the backend default). */
export function shopHasModule(shop: Shop | null, module: Module): boolean {
  if (!shop) return false;
  if (shop.is_master) return true;
  const mods = (shop.modules ?? ['bookings']) as Module[];
  return mods.includes(module);
}

/** True if a nav item (tagged with the modules it belongs to) should show. */
export function navVisible(modules: Module[], shop: Shop | null): boolean {
  return modules.some((m) => shopHasModule(shop, m));
}
```

- [ ] **Step 4: Add `modules` to the types**

In `admin/src/types.ts`, add to the Shop type (near `is_open?` ~line 65):

```ts
  is_open?: boolean;
  modules?: Array<'bookings' | 'leads'>;
```

And to the MasterShop type (near its `is_master?` ~line 176):

```ts
  is_master?: boolean;
  modules?: Array<'bookings' | 'leads'>;
```

In `admin/src/lib/shops.ts`, widen the `updateMasterShop` payload type so `{ modules?: Array<'bookings' | 'leads'> }` is accepted alongside `status`/`persona` (add `modules?` to that inline/param type).

- [ ] **Step 5: Run test to verify it passes**

Run: `cd admin && npm run test -- modules`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add admin/src/lib/modules.ts admin/src/lib/modules.test.ts admin/src/types.ts admin/src/lib/shops.ts
git commit -m "feat(admin): shared shopHasModule/navVisible helper + modules on Shop types"
```

---

### Task 5: Gate the nav — desktop rail, mobile tabs, Settings list

**Files:**
- Modify: `admin/src/layout/DesktopSidebar.tsx:14-39`
- Modify: `admin/src/layout/MobileLayout.tsx:6-17, 39-41`
- Modify: `admin/src/pages/Settings.tsx:7-25`
- Test: `admin/src/layout/DesktopSidebar.test.tsx`

**Interfaces:**
- Consumes: `Module`, `navVisible` from Task 4.

- [ ] **Step 1: Write the failing test**

```tsx
// admin/src/layout/DesktopSidebar.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { DesktopSidebar } from './DesktopSidebar';
import { ShopContext } from '@/context/ShopContext';
import type { Shop } from '@/types';

function renderWith(modules: string[]) {
  const shop = { name: 'S', modules } as unknown as Shop;
  const ctx = { shop, logoutShop: () => {} } as never;
  render(
    <MemoryRouter>
      <ShopContext.Provider value={ctx}><DesktopSidebar /></ShopContext.Provider>
    </MemoryRouter>,
  );
}

describe('DesktopSidebar module gating', () => {
  it('bookings-only shop hides Business Hunt, shows Bookings', () => {
    renderWith(['bookings']);
    expect(screen.queryByText('Business Hunt')).toBeNull();
    expect(screen.getByText('Bookings')).toBeTruthy();
  });
  it('leads-only shop shows Business Hunt, hides Bookings', () => {
    renderWith(['leads']);
    expect(screen.getByText('Business Hunt')).toBeTruthy();
    expect(screen.queryByText('Bookings')).toBeNull();
  });
  it('always shows Home, Overview, Settings, Profile', () => {
    renderWith(['leads']);
    ['Home', 'Overview', 'Settings', 'Profile'].forEach((l) =>
      expect(screen.getByText(l)).toBeTruthy());
  });
});
```

> This test imports `ShopContext`. If it isn't exported from `@/context/ShopContext`, add `export` to the `const ShopContext = createContext(...)` there (harmless, enables testing).

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npm run test -- DesktopSidebar`
Expected: FAIL — Business Hunt still shows for a bookings-only shop.

- [ ] **Step 3: Tag + filter the desktop rail**

In `admin/src/layout/DesktopSidebar.tsx`, import the helper and tag every item:

```tsx
import { navVisible, type Module } from '@/lib/modules';

type NavItem = { label: string; to: string; icon: keyof typeof Icons; end?: boolean; modules: Module[] };

const BOTH: Module[] = ['bookings', 'leads'];

const BASE_NAV: NavItem[] = [
  { label: 'Home', to: '/', icon: 'Mic', end: true, modules: BOTH },
  { label: 'Overview', to: '/overview', icon: 'Chart', modules: BOTH },
  { label: 'Bookings', to: '/bookings', icon: 'Calendar', modules: ['bookings'] },
  { label: 'Chats', to: '/chats', icon: 'Chat', modules: BOTH },
  { label: 'Business Hunt', to: '/leads', icon: 'Search', modules: ['leads'] },
  { label: 'Services', to: '/services', icon: 'Grid', modules: ['bookings'] },
  { label: 'Staff', to: '/staff', icon: 'Users', modules: ['bookings'] },
  { label: 'Working Hours', to: '/working-hours', icon: 'Clock', modules: ['bookings'] },
  { label: 'Settings', to: '/settings', icon: 'Sliders', modules: BOTH },
  { label: 'Profile', to: '/profile', icon: 'Store', modules: BOTH },
];
```

Then extend the existing filter (currently `.filter((n) => WHATSAPP_ENABLED || n.to !== '/chats')`):

```tsx
  const nav = BASE_NAV
    .filter((n) => WHATSAPP_ENABLED || n.to !== '/chats')
    .filter((n) => navVisible(n.modules, shop));
```

(The `shop?.is_master ? [...] : nav` line below is unchanged — master still gets its single item.)

- [ ] **Step 4: Tag + filter the mobile tabs**

In `admin/src/layout/MobileLayout.tsx`:

```tsx
import { navVisible, type Module } from '@/lib/modules';

type Tab = { id: string; label: string; href: string; icon: keyof typeof Icons; modules: Module[] };

const BOTH: Module[] = ['bookings', 'leads'];

const ALL_TABS: Tab[] = [
  { id: 'home', label: 'Home', href: '/', icon: 'Mic', modules: BOTH },
  { id: 'overview', label: 'Overview', href: '/overview', icon: 'Chart', modules: BOTH },
  { id: 'bookings', label: 'Bookings', href: '/bookings', icon: 'Calendar', modules: ['bookings'] },
  { id: 'chats', label: 'Chats', href: '/chats', icon: 'Chat', modules: BOTH },
  { id: 'settings', label: 'Settings', href: '/settings', icon: 'Sliders', modules: BOTH },
  { id: 'profile', label: 'Profile', href: '/profile', icon: 'Store', modules: BOTH },
];
```

Update the tabs assignment:

```tsx
  const tabs = shop?.is_master
    ? MASTER_TABS
    : ALL_TABS.filter((t) => (WHATSAPP_ENABLED || t.id !== 'chats') && navVisible(t.modules, shop));
```

- [ ] **Step 5: Tag + filter the Settings link-list**

In `admin/src/pages/Settings.tsx`:

```tsx
import { shopHasModule, type Module } from '@/lib/modules';

type Option = { label: string; sub: string; to: string; icon: keyof typeof Icons; modules: Module[] };

const BOTH: Module[] = ['bookings', 'leads'];

const ALL_OPTIONS: Option[] = [
  { label: 'Business Hunt', sub: 'Find UAE businesses & win them', to: '/leads', icon: 'Search', modules: ['leads'] },
  { label: 'Lead messages', sub: 'WhatsApp opening & follow-up templates', to: '/leads/messages', icon: 'WhatsApp', modules: ['leads'] },
  { label: 'Working Hours', sub: 'Set your open & close times', to: '/working-hours', icon: 'Clock', modules: ['bookings'] },
  { label: 'Services', sub: 'Add or edit what you offer', to: '/services', icon: 'Grid', modules: ['bookings'] },
  { label: 'Staff', sub: 'Add & manage your team', to: '/staff', icon: 'Users', modules: ['bookings'] },
  { label: 'WhatsApp', sub: 'Chat connection settings', to: '/chats/setup', icon: 'WhatsApp', modules: BOTH },
  { label: 'AI Assistant', sub: 'What your auto-reply assistant says', to: '/assistant', icon: 'Chat', modules: BOTH },
  { label: 'Access Control', sub: 'Users, roles & permissions', to: '/settings/access', icon: 'Key', modules: BOTH },
];
const OPTIONS: Option[] = ALL_OPTIONS.filter((o) => WHATSAPP_ENABLED || o.to !== '/chats/setup');
```

Then apply the module filter where `visible` is computed inside the component (add `shopHasModule` alongside the existing `can(...)` filter):

```tsx
  const visible = OPTIONS.filter(
    (o) =>
      o.modules.some((m) => shopHasModule(shop, m)) &&
      (o.to !== '/settings/access' || can('users.view') || can('roles.view')),
  );
```

- [ ] **Step 6: Run test to verify it passes**

Run: `cd admin && npm run test -- DesktopSidebar`
Expected: PASS (3 tests).

- [ ] **Step 7: Commit**

```bash
git add admin/src/layout/DesktopSidebar.tsx admin/src/layout/MobileLayout.tsx admin/src/pages/Settings.tsx admin/src/layout/DesktopSidebar.test.tsx
git commit -m "feat(admin): gate nav (rail, tabs, settings list) by shop modules"
```

---

### Task 6: Client route guard for `/leads`

**Files:**
- Create: `admin/src/components/ModuleGuard.tsx`
- Modify: `admin/src/App.tsx:69-71` (wrap the three leads routes)
- Test: `admin/src/components/ModuleGuard.test.tsx`

**Interfaces:**
- Consumes: `shopHasModule`, `Module` from Task 4.
- Produces: `<ModuleGuard module="leads" />` — an `<Outlet>` gate that `<Navigate to="/" replace>` when the module is absent.

- [ ] **Step 1: Write the failing test**

```tsx
// admin/src/components/ModuleGuard.test.tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import { ModuleGuard } from './ModuleGuard';
import { ShopContext } from '@/context/ShopContext';
import type { Shop } from '@/types';

function renderAt(modules: string[]) {
  const ctx = { shop: { modules } as unknown as Shop } as never;
  render(
    <ShopContext.Provider value={ctx}>
      <MemoryRouter initialEntries={['/leads']}>
        <Routes>
          <Route element={<ModuleGuard module="leads" />}>
            <Route path="/leads" element={<div>LEADS PAGE</div>} />
          </Route>
          <Route path="/" element={<div>HOME PAGE</div>} />
        </Routes>
      </MemoryRouter>
    </ShopContext.Provider>,
  );
}

describe('ModuleGuard', () => {
  it('renders the route when the module is enabled', () => {
    renderAt(['bookings', 'leads']);
    expect(screen.getByText('LEADS PAGE')).toBeTruthy();
  });
  it('redirects home when the module is absent', () => {
    renderAt(['bookings']);
    expect(screen.getByText('HOME PAGE')).toBeTruthy();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npm run test -- ModuleGuard`
Expected: FAIL — `./ModuleGuard` does not exist.

- [ ] **Step 3: Write `ModuleGuard.tsx`**

```tsx
// admin/src/components/ModuleGuard.tsx
import { Navigate, Outlet } from 'react-router-dom';
import { useShop } from '@/context/ShopContext';
import { shopHasModule, type Module } from '@/lib/modules';

/** Route gate: renders children only if the shop has `module`, else redirects
 *  to Home. Master passes (shopHasModule returns true for masters). */
export function ModuleGuard({ module }: { module: Module }) {
  const { shop } = useShop();
  if (!shopHasModule(shop, module)) return <Navigate to="/" replace />;
  return <Outlet />;
}
```

- [ ] **Step 4: Wrap the leads routes in `App.tsx`**

Add the import and wrap the three `/leads` routes (currently lines 69-71):

```tsx
import { ModuleGuard } from '@/components/ModuleGuard';
```

```tsx
          <Route element={<ModuleGuard module="leads" />}>
            <Route path="/leads" element={<Leads />} />
            <Route path="/leads/messages" element={<LeadMessages />} />
            <Route path="/leads/:id" element={<LeadDetail />} />
          </Route>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd admin && npm run test -- ModuleGuard`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add admin/src/components/ModuleGuard.tsx admin/src/App.tsx admin/src/components/ModuleGuard.test.tsx
git commit -m "feat(admin): redirect /leads home when the leads module is off"
```

---

### Task 7: Master shop-detail — Modules toggles

**Files:**
- Modify: `admin/src/pages/MasterShopDetail.tsx` (add a "Modules" section + handler)
- Test: `admin/src/pages/MasterShopDetail.test.tsx` (extend)

**Interfaces:**
- Consumes: `updateMasterShop(id, { modules })` (Task 4 payload), `MasterShop.modules` (Task 4 type), `Module` from Task 4.

- [ ] **Step 1: Write the failing test**

Add to `admin/src/pages/MasterShopDetail.test.tsx` a case that renders the detail for a shop with `modules: ['bookings']`, clicks the "Business Hunt" module toggle, and asserts `updateMasterShop` was called with `{ modules: ['bookings', 'leads'] }`. Mirror the existing mock of `@/lib/shops` in that test file. Skeleton:

```tsx
it('toggling Business Hunt sends the updated modules', async () => {
  // arrange: mock updateMasterShop to resolve with the merged shop,
  // render MasterShopDetail seeded with a shop whose modules = ['bookings'].
  // act: click the "Business Hunt" toggle.
  // assert:
  expect(updateMasterShop).toHaveBeenCalledWith(SHOP_ID, { modules: ['bookings', 'leads'] });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd admin && npm run test -- MasterShopDetail`
Expected: FAIL — no Modules toggle rendered.

- [ ] **Step 3: Add the toggle handler**

In `MasterShopDetail.tsx`, add state + a handler that flips one module and PATCHes (mirrors `toggleStatus`):

```tsx
import { shopHasModule, type Module } from '@/lib/modules';
```

```tsx
  const [togglingModule, setTogglingModule] = useState<Module | null>(null);

  const toggleModule = async (module: Module) => {
    if (!shop) return;
    const current = (shop.modules ?? ['bookings']) as Module[];
    const next = current.includes(module)
      ? current.filter((m) => m !== module)
      : [...current, module];
    const prev = shop.modules;
    setShop({ ...shop, modules: next }); // optimistic
    setTogglingModule(module);
    setError('');
    try {
      const updated = await updateMasterShop(shop.id, { modules: next });
      setShop(updated);
    } catch {
      setShop({ ...shop, modules: prev }); // revert
      setError('Could not change modules.');
    } finally {
      setTogglingModule(null);
    }
  };
```

- [ ] **Step 4: Add the Modules section to the render**

Insert a new section (after the "Visibility" section, before "Persona"):

```tsx
      <div className="c-msd-section">
        <h3 className="c-msd-h">Modules</h3>
        {([
          ['bookings', 'Bookings', 'Calendar, services, staff & working hours'],
          ['leads', 'Business Hunt', 'Find & work B2B leads'],
        ] as [Module, string, string][]).map(([key, label, sub]) => {
          const on = shopHasModule(shop, key);
          return (
            <button key={key}
              className={`c-btn-ghost c-msd-action${on ? '' : ' off'}`}
              disabled={togglingModule === key}
              onClick={() => void toggleModule(key)}>
              {on ? `Disable ${label}` : `Enable ${label}`}
              <span className="c-msd-help" style={{ display: 'block' }}>{sub}</span>
            </button>
          );
        })}
        <p className="c-msd-help">Controls which menus this business sees in the app.</p>
      </div>
```

- [ ] **Step 5: Run test to verify it passes**

Run: `cd admin && npm run test -- MasterShopDetail`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add admin/src/pages/MasterShopDetail.tsx admin/src/pages/MasterShopDetail.test.tsx
git commit -m "feat(master): toggle per-shop modules from the shop detail screen"
```

---

### Task 8: Full test sweep + deploy to staging

**Files:** none (verification + deploy)

- [ ] **Step 1: Run the full frontend suite**

Run: `cd admin && npm run test`
Expected: all suites PASS.

- [ ] **Step 2: Run the backend suite on the droplet (scratch DB)**

Run (droplet, php8.4, scratch DB — verify connection first): `php artisan test`
Expected: PASS, including the three new test files.

- [ ] **Step 3: Deploy to staging + manual verify**

Deploy admin to staging (`admin/deploy.ps1` targeting staging). On staging:
- A `['bookings']` shop no longer shows **Business Hunt** (rail, mobile Settings list) and `/leads` redirects Home.
- From master, enable **Business Hunt** on one staging shop → it reappears and `/leads` loads.
- Confirm `/api/shop/leads` returns 403 for a bookings-only shop and 200 for one with leads.

- [ ] **Step 4: Promote to prod**

Once staging is verified great, promote code + migration + frontend to prod per the standing deploy flow. Run the migration on prod (backfills existing shops to `['bookings']`).

---

## Self-Review Notes

- **Spec coverage:** data model (T1), API gate (T2), master control (T3+T7), shared helper (T4), four nav surfaces — rail/tabs/Settings (T5) + route guard (T6), backfill (T1 migration), pure-`['leads']` case hides booking menus (T5 tagging), testing + rollout (T8). All spec sections mapped.
- **Type consistency:** `Module = 'bookings' | 'leads'`, `shopHasModule`, `navVisible`, `hasModule`, `updateMasterShop({ modules })` used identically across tasks.
- **Landing route:** unchanged by design — Home/Overview are `BOTH`, so every shop always has a landing page.
