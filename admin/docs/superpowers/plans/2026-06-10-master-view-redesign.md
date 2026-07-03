# Master View Redesign + Per-Shop Persona + Active Toggle — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Redesign the admin "All Businesses" master view into a clean eloquent-bookings-style card list + per-business detail screen, and add per-shop WhatsApp persona and an active/inactive visibility toggle end-to-end (backend → master UI → live bot, bot change strictly additive).

**Architecture:** Three repos in rollout order. (1) Laravel backend `Business Lens/backend` gains a nullable `shops.persona` column, a master-guarded `PATCH /master/shops/{shop}` endpoint, and returns `persona` from the master list + `shop-context`. (2) admin master UI gets a `MasterShopCard`, a new `MasterShopDetail` page at `/master/:id`, and an `updateMasterShop` lib call. (3) The live Node bot `whatsapp-autoreply` uses a shop's persona only when present, otherwise unchanged behavior.

**Tech Stack:** Laravel 12 / PHPUnit + RefreshDatabase; React 18 + TypeScript + Vite + Vitest + Testing Library; Node ESM (whatsapp-autoreply).

**Key reference:** Design spec at `admin/docs/superpowers/specs/2026-06-10-master-view-redesign-design.md`. Card visual reference: `eloquent-bookings/src/components/ShopCard.tsx` + its `.c-shop-card` CSS.

---

## File Structure

**Backend (`D:/Francis/projects/2026/Eloquent/Solutions/Business Lens/backend`)**
- Create: `database/migrations/2026_06_10_000001_add_persona_to_shops_table.php`
- Modify: `app/Http/Controllers/MasterController.php` — extract `presentShop()`, add `updateShop()`, add `persona` to payload
- Modify: `app/Http/Controllers/WaWebhookController.php` — add `persona` to `shopContext()` JSON
- Modify: `routes/api.php` — register `PATCH /master/shops/{shop}`
- Test: `tests/Feature/MasterTest.php` (extend), `tests/Feature/WaShopContextTest.php` (extend)

**Frontend (`D:/Francis/projects/2026/Eloquent/Solutions/Business Lens/admin`)**
- Create: `src/lib/format.ts` — shared `shortDate`
- Modify: `src/types.ts` — `MasterShop.persona`
- Modify: `src/lib/shops.ts` — `updateMasterShop()`
- Create: `src/components/MasterShopCard.tsx` + `src/components/MasterShopCard.test.tsx`
- Create: `src/pages/MasterShopDetail.tsx` + `src/pages/MasterShopDetail.test.tsx`
- Modify: `src/pages/MasterShops.tsx` — render `MasterShopCard`
- Modify: `src/App.tsx` — add `/master/:id` route
- Modify: `src/styles/customer.css` — card + detail styles

**Bot (`D:/Francis/projects/2026/Eloquent/Solutions/whatsapp-autoreply`) — LIVE, additive only**
- Modify: `lib/accounts.js` — carry `persona` through `resolveShopAccount`
- Modify: `server.js` — use `shopAccount.persona` when present

---

## PHASE 1 — BACKEND

### Task 1: Add `persona` column to shops

**Files:**
- Create: `database/migrations/2026_06_10_000001_add_persona_to_shops_table.php`

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
            // Per-shop WhatsApp assistant persona (system prompt). Null = use the
            // auto-generated category-based prompt (existing behavior).
            $table->text('persona')->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('persona');
        });
    }
};
```

- [ ] **Step 2: Run the migration against the test DB to confirm it applies**

Run: `cd D:/Francis/projects/2026/Eloquent/Solutions/Business Lens/backend && php artisan migrate --env=testing` (if the project uses an in-memory sqlite test DB via `RefreshDatabase`, this is implicitly exercised by Task 2's tests; running it here just confirms the file is valid)
Expected: migration runs without error (or "Nothing to migrate" if testing uses RefreshDatabase). No SQL errors.

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_06_10_000001_add_persona_to_shops_table.php
git commit -m "feat(backend): add nullable persona column to shops"
```

---

### Task 2: Master `updateShop` endpoint + persona in master list

**Files:**
- Modify: `app/Http/Controllers/MasterController.php`
- Modify: `routes/api.php:108-113` (inside the `auth:sanctum` master group)
- Test: `tests/Feature/MasterTest.php`

- [ ] **Step 1: Write failing tests** — append these methods inside the `MasterTest` class (before the closing brace at `MasterTest.php:50`):

```php
    public function test_master_list_includes_status_and_persona(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $shop = Shop::factory()->create(['persona' => 'You are Glow Salon.']);

        $row = collect(
            $this->getJson('/api/master/shops', $this->authed($master))->assertOk()->json('data')
        )->firstWhere('id', $shop->id);

        $this->assertSame('active', $row['status']); // shops are created active
        $this->assertSame('You are Glow Salon.', $row['persona']);
    }

    public function test_master_can_set_persona_and_toggle_status(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $shop = Shop::factory()->create();

        $res = $this->patchJson("/api/master/shops/{$shop->id}", [
            'status' => 'inactive',
            'persona' => 'You are the assistant for Glow Salon. Keep replies short.',
        ], $this->authed($master))->assertOk();

        $this->assertSame('inactive', $res->json('data.status'));
        $this->assertSame('You are the assistant for Glow Salon. Keep replies short.', $res->json('data.persona'));
        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'status' => 'inactive']);
    }

    public function test_master_clears_persona_when_blank(): void
    {
        $master = Shop::factory()->create(['is_master' => true]);
        $shop = Shop::factory()->create(['persona' => 'Old persona.']);

        $res = $this->patchJson("/api/master/shops/{$shop->id}", [
            'persona' => '   ',
        ], $this->authed($master))->assertOk();

        $this->assertNull($res->json('data.persona'));
        $this->assertDatabaseHas('shops', ['id' => $shop->id, 'persona' => null]);
    }

    public function test_regular_shop_cannot_update_shop(): void
    {
        $shop = Shop::factory()->create(['is_master' => false]);
        $target = Shop::factory()->create();
        $this->patchJson("/api/master/shops/{$target->id}", ['status' => 'inactive'], $this->authed($shop))
            ->assertForbidden();
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `cd D:/Francis/projects/2026/Eloquent/Solutions/Business Lens/backend && php artisan test --filter=MasterTest`
Expected: FAIL — the new tests fail (404/route-not-found for the PATCH, and persona key missing in the list).

- [ ] **Step 3: Refactor `MasterController::shops()` to use a shared presenter and add `updateShop()`**

In `app/Http/Controllers/MasterController.php`, replace the body of `shops()` (lines 35-57, the `$shops = Shop::query()...->map(...)` closure) so the map calls a shared method, and add the `presentShop()` + `updateShop()` methods. Concretely:

Replace the `->map(function (Shop $shop) use ($waShopIds) { return [ ... ]; });` block with:

```php
            ->map(fn (Shop $shop) => $this->presentShop($shop, $waShopIds));
```

Then add these two methods directly after `shops()` (after line 60):

```php
    /**
     * Master-only: update a business's visibility (status) and/or its WhatsApp
     * assistant persona. Blank persona clears back to the default category prompt.
     */
    public function updateShop(Request $request, Shop $shop)
    {
        $this->requireMaster($request);

        $data = $request->validate([
            'status' => ['sometimes', 'in:active,inactive'],
            'persona' => ['sometimes', 'nullable', 'string', 'max:20000'],
        ]);

        if (array_key_exists('persona', $data)) {
            $data['persona'] = trim((string) $data['persona']) !== '' ? $data['persona'] : null;
        }

        $shop->update($data);

        $shop->loadCount('bookings');
        $waShopIds = WaAccount::pluck('phone_number', 'shop_id');

        return response()->json(['data' => $this->presentShop($shop, $waShopIds)]);
    }

    /** Shape one business for the master views (list + update). */
    private function presentShop(Shop $shop, $waShopIds): array
    {
        return [
            'id' => $shop->id,
            'name' => $shop->name,
            'shop_code' => $shop->shop_code,
            'pin' => $shop->pin,
            'phone' => $shop->phone,
            'location' => $shop->location,
            'category' => ServiceCategories::name((int) $shop->category_id),
            'status' => $shop->status,
            'persona' => $shop->persona,
            'is_master' => (bool) $shop->is_master,
            'bookings_count' => $shop->bookings_count,
            'wa_connected' => $waShopIds->has($shop->id),
            'wa_number' => $waShopIds->get($shop->id),
            'last_login_at' => optional($shop->last_login_at)->toIso8601String(),
            'created_at' => optional($shop->created_at)->toIso8601String(),
        ];
    }
```

- [ ] **Step 4: Register the route** — in `routes/api.php`, inside the master `auth:sanctum` group, add directly under line 108 (`Route::get('/master/shops', ...)`):

```php
    Route::patch('/master/shops/{shop}', [\App\Http\Controllers\MasterController::class, 'updateShop']);
```

- [ ] **Step 5: Run tests to verify they pass**

Run: `cd D:/Francis/projects/2026/Eloquent/Solutions/Business Lens/backend && php artisan test --filter=MasterTest`
Expected: PASS — all MasterTest cases green (including the 3 pre-existing ones).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/MasterController.php routes/api.php tests/Feature/MasterTest.php
git commit -m "feat(backend): master can update shop status and persona; expose persona in list"
```

---

### Task 3: Return `persona` from `shop-context`

**Files:**
- Modify: `app/Http/Controllers/WaWebhookController.php:157-163`
- Test: `tests/Feature/WaShopContextTest.php`

- [ ] **Step 1: Write failing test** — append inside the `WaShopContextTest` class (before the closing brace at `WaShopContextTest.php:76`):

```php
    public function test_returns_shop_persona_when_set(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);
        $shop = Shop::factory()->create(['persona' => 'You are Glow Salon. Keep it short.']);
        $this->makeAccount($shop, 'pn_ctx_persona');

        $this->withHeader('X-Relay-Secret', 'relay-secret')
            ->getJson('/api/wa/shop-context?phone_number_id=pn_ctx_persona')
            ->assertOk()
            ->assertJson(['found' => true, 'persona' => 'You are Glow Salon. Keep it short.']);
    }

    public function test_persona_is_null_when_unset(): void
    {
        config(['services.whatsapp.relay_secret' => 'relay-secret']);
        $shop = Shop::factory()->create();
        $this->makeAccount($shop, 'pn_ctx_nopersona');

        $this->withHeader('X-Relay-Secret', 'relay-secret')
            ->getJson('/api/wa/shop-context?phone_number_id=pn_ctx_nopersona')
            ->assertOk()
            ->assertJson(['found' => true, 'persona' => null]);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd D:/Francis/projects/2026/Eloquent/Solutions/Business Lens/backend && php artisan test --filter=WaShopContextTest`
Expected: FAIL — `persona` key absent from the response.

- [ ] **Step 3: Add `persona` to the response** — in `WaWebhookController::shopContext()`, change the returned array (lines 157-163) to include persona:

```php
        return response()->json([
            'found' => true,
            'shop_name' => $shop?->name,
            'category' => \App\Support\ServiceCategories::name($shop?->category_id),
            'persona' => $shop?->persona,
            'phone_number_id' => $account->phone_number_id,
            'token' => $account->token ?: config('services.whatsapp.default_token'),
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd D:/Francis/projects/2026/Eloquent/Solutions/Business Lens/backend && php artisan test --filter=WaShopContextTest`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/WaWebhookController.php tests/Feature/WaShopContextTest.php
git commit -m "feat(backend): expose per-shop persona from wa/shop-context"
```

---

## PHASE 2 — FRONTEND (admin)

> All frontend commands run from `D:/Francis/projects/2026/Eloquent/Solutions/Business Lens/admin`.

### Task 4: Types + `updateMasterShop` lib call + shared `shortDate`

**Files:**
- Modify: `src/types.ts:144` (MasterShop)
- Modify: `src/lib/shops.ts`
- Create: `src/lib/format.ts`

- [ ] **Step 1: Add `persona` to `MasterShop`** — in `src/types.ts`, inside the `MasterShop` type, add after the `status?: string;` line (line 144):

```ts
  persona?: string | null;
```

- [ ] **Step 2: Add `updateMasterShop`** — in `src/lib/shops.ts`, directly after `getMasterShops()` (after line 73), add:

```ts
/** Master account only: update a business's visibility and/or WhatsApp persona. */
export async function updateMasterShop(
  id: number,
  payload: { status?: 'active' | 'inactive'; persona?: string | null },
): Promise<MasterShop> {
  const { data } = await api.patch(`/master/shops/${id}`, payload);
  return data?.data ?? data;
}
```

- [ ] **Step 3: Create the shared date helper** — `src/lib/format.ts`:

```ts
/** Short, locale-friendly day+month, with an em-dash fallback for empties. */
export function shortDate(iso?: string | null): string {
  if (!iso) return '—';
  const d = new Date(iso);
  if (Number.isNaN(d.getTime())) return '—';
  return d.toLocaleDateString([], { day: 'numeric', month: 'short' });
}
```

- [ ] **Step 4: Type-check**

Run: `npx tsc --noEmit`
Expected: PASS (no errors).

- [ ] **Step 5: Commit**

```bash
git add src/types.ts src/lib/shops.ts src/lib/format.ts
git commit -m "feat(admin): updateMasterShop lib call, persona type, shared shortDate"
```

---

### Task 5: `MasterShopCard` component + styles

**Files:**
- Create: `src/components/MasterShopCard.tsx`
- Create: `src/components/MasterShopCard.test.tsx`
- Modify: `src/styles/customer.css` (append card styles)

- [ ] **Step 1: Write the failing test** — `src/components/MasterShopCard.test.tsx`:

```tsx
import { describe, it, expect, vi } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type { MasterShop } from '@/types';
import { MasterShopCard } from './MasterShopCard';

const base: MasterShop = {
  id: 7, name: 'Shakaina Salon', shop_code: '339416', pin: '7201',
  phone: '+971500000000', category: 'Salon', status: 'active',
  bookings_count: 12, wa_connected: true, created_at: '2026-06-07T00:00:00Z',
};

describe('MasterShopCard', () => {
  it('shows name, category and connection state, and fires onOpen', async () => {
    const onOpen = vi.fn();
    render(<MasterShopCard shop={base} onOpen={onOpen} />);

    expect(screen.getByText('Shakaina Salon')).toBeInTheDocument();
    expect(screen.getByText(/Salon/)).toBeInTheDocument();
    expect(screen.getByText(/Connected/)).toBeInTheDocument();
    expect(screen.getByText(/12 bookings/)).toBeInTheDocument();

    await userEvent.setup().click(screen.getByRole('button'));
    expect(onOpen).toHaveBeenCalledWith(7);
  });

  it('marks inactive shops and not-set-up WhatsApp', () => {
    render(<MasterShopCard shop={{ ...base, status: 'inactive', wa_connected: false }} onOpen={() => {}} />);
    expect(screen.getByText(/Inactive/)).toBeInTheDocument();
    expect(screen.getByText(/Not set up/)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/components/MasterShopCard.test.tsx`
Expected: FAIL — module `./MasterShopCard` not found.

- [ ] **Step 3: Write the component** — `src/components/MasterShopCard.tsx`:

```tsx
import type { MasterShop } from '@/types';
import { Icons } from './Icons';
import { shortDate } from '@/lib/format';

function initial(name: string): string {
  const c = (name || '?').trim().charAt(0);
  return c ? c.toUpperCase() : '?';
}

export function MasterShopCard({ shop, onOpen }: {
  shop: MasterShop;
  onOpen: (id: number) => void;
}) {
  const inactive = shop.status !== 'active';
  return (
    <button type="button" className="c-msc" onClick={() => onOpen(shop.id)}>
      <span className="c-msc-thumb" aria-hidden>{initial(shop.name)}</span>
      <div className="c-msc-body">
        <div className="c-msc-top">
          <span className={`c-msc-wa${shop.wa_connected ? ' on' : ''}`}>
            <Icons.WhatsApp size={13} /> {shop.wa_connected ? 'Connected' : 'Not set up'}
          </span>
          <span className="c-msc-tags">
            {shop.is_master && <em className="c-msc-tag master">master</em>}
            {inactive && <em className="c-msc-tag off">Inactive</em>}
          </span>
        </div>
        <span className="c-msc-name">{shop.name}</span>
        <span className="c-msc-meta">
          {shop.category || 'No category'}{shop.phone ? ` · ${shop.phone}` : ''}
        </span>
        <span className="c-msc-foot">
          {shop.bookings_count ?? 0} bookings · Joined {shortDate(shop.created_at)}
        </span>
      </div>
    </button>
  );
}
```

- [ ] **Step 4: Append card styles** — add to the end of `src/styles/customer.css`:

```css
/* Master — business card (eloquent-bookings ShopCard layout, monogram thumb) */
.c-msc { display: flex; gap: 14px; width: 100%; box-sizing: border-box; text-align: left; cursor: pointer; margin: 0 16px 12px; padding: 14px; background: var(--surface-1); border: 1px solid var(--border-1); border-radius: var(--r-lg); }
.c-msc:not(:disabled):active { transform: scale(0.997); }
.c-msc-thumb { width: 72px; height: 72px; flex-shrink: 0; border-radius: var(--r-md); display: grid; place-items: center; background: var(--mint-soft); border: 1px solid var(--border-mint); color: var(--mint-300); font-size: 28px; font-weight: 800; }
.c-msc-body { flex: 1; min-width: 0; display: flex; flex-direction: column; }
.c-msc-top { display: flex; align-items: center; justify-content: space-between; gap: 8px; }
.c-msc-wa { display: inline-flex; align-items: center; gap: 4px; font-size: 10.5px; font-weight: 700; color: var(--text-4); }
.c-msc-wa.on { color: var(--mint-300); }
.c-msc-tags { display: inline-flex; align-items: center; gap: 6px; flex: 0 0 auto; }
.c-msc-tag { font-style: normal; font-size: 9.5px; font-weight: 800; text-transform: uppercase; letter-spacing: 0.08em; padding: 2px 6px; border-radius: var(--r-xs); }
.c-msc-tag.master { color: var(--mint-300); background: var(--mint-soft); border: 1px solid var(--border-mint); }
.c-msc-tag.off { color: var(--warn); background: var(--warn-soft); border: 1px solid rgba(244,184,96,0.25); }
.c-msc-name { font-size: 16px; font-weight: 700; color: var(--text-1); margin-top: 4px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.c-msc-meta { font-size: 11.5px; color: var(--text-3); font-weight: 600; margin-top: 3px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
.c-msc-foot { font-size: 11.5px; color: var(--text-3); margin-top: 8px; }
```

- [ ] **Step 5: Run test to verify it passes**

Run: `npx vitest run src/components/MasterShopCard.test.tsx`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add src/components/MasterShopCard.tsx src/components/MasterShopCard.test.tsx src/styles/customer.css
git commit -m "feat(admin): MasterShopCard in eloquent-bookings style with monogram + status badges"
```

---

### Task 6: `MasterShopDetail` page + route + styles

**Files:**
- Create: `src/pages/MasterShopDetail.tsx`
- Create: `src/pages/MasterShopDetail.test.tsx`
- Modify: `src/App.tsx`
- Modify: `src/styles/customer.css` (append detail styles)

- [ ] **Step 1: Write the failing test** — `src/pages/MasterShopDetail.test.tsx`:

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
  id: 7, name: 'Shakaina Salon', shop_code: '339416', pin: '7201',
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
    expect(screen.getByText('339416')).toBeInTheDocument();
    expect(screen.getByText('7201')).toBeInTheDocument();
    expect(screen.getByText(/12 bookings/)).toBeInTheDocument();
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
});
```

- [ ] **Step 2: Run test to verify it fails**

Run: `npx vitest run src/pages/MasterShopDetail.test.tsx`
Expected: FAIL — module `./MasterShopDetail` not found.

- [ ] **Step 3: Write the page** — `src/pages/MasterShopDetail.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { useLocation, useNavigate, useParams } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { useShop } from '@/context/ShopContext';
import { getMasterShops, updateMasterShop } from '@/lib/shops';
import { shortDate } from '@/lib/format';
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
  const [copied, setCopied] = useState(false);
  const [error, setError] = useState('');

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
      })
      .catch(() => { if (alive) setError('Could not load this business.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, [id, me, navigate, seeded]);

  const savePersona = async () => {
    if (!shop) return;
    setSavingPersona(true);
    setError('');
    try {
      const updated = await updateMasterShop(shop.id, { persona });
      setShop(updated);
      setPersona(updated.persona ?? '');
      setPersonaSaved(true);
      setTimeout(() => setPersonaSaved(false), 1500);
    } catch {
      setError('Could not save the persona.');
    } finally {
      setSavingPersona(false);
    }
  };

  const toggleStatus = async () => {
    if (!shop) return;
    const next = shop.status === 'active' ? 'inactive' : 'active';
    const prev = shop.status;
    setShop({ ...shop, status: next }); // optimistic
    setTogglingStatus(true);
    setError('');
    try {
      const updated = await updateMasterShop(shop.id, { status: next });
      setShop(updated);
    } catch {
      setShop({ ...shop, status: prev }); // revert
      setError('Could not change visibility.');
    } finally {
      setTogglingStatus(false);
    }
  };

  const copyCreds = async () => {
    if (!shop) return;
    try {
      await navigator.clipboard.writeText(`Business ID: ${shop.shop_code}\nPIN: ${shop.pin}`);
      setCopied(true);
      setTimeout(() => setCopied(false), 1200);
    } catch { /* values stay visible */ }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading…" /></div>;

  if (!shop) return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>
      <p style={{ textAlign: 'center', color: 'var(--text-3)' }}>Business not found.</p>
    </div></div>
  );

  const inactive = shop.status !== 'active';
  const waHref = shop.phone
    ? `https://wa.me/${shop.phone.replace(/\D/g, '')}?text=${encodeURIComponent(
        `Your Business Lens login\nBusiness ID: ${shop.shop_code}\nPIN: ${shop.pin}`)}`
    : null;

  return (
    <div className="m-screen"><div className="m-scroll">
      <button className="c-back" onClick={() => navigate(-1)}><Icons.ChevronLeft size={16} /> Back</button>

      {error && <div className="c-error-box" style={{ margin: '0 16px 12px' }}>{error}</div>}

      <div className="c-msd-hero">
        <span className="c-msc-thumb" aria-hidden>{initial(shop.name)}</span>
        <div style={{ minWidth: 0 }}>
          <h1 className="c-page-title" style={{ fontSize: 20 }}>{shop.name}</h1>
          <p className="c-msd-sub">
            {shop.category || 'No category'}{shop.location ? ` · ${shop.location}` : ''}
          </p>
          <span className={`c-msc-wa${shop.wa_connected ? ' on' : ''}`}>
            <Icons.WhatsApp size={13} /> {shop.wa_connected ? 'Connected' : 'Not set up'}
          </span>
        </div>
      </div>

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

      <div className="c-msd-section">
        <h3 className="c-msd-h">Visibility</h3>
        <button className={`c-btn-ghost c-msd-action${inactive ? ' off' : ''}`}
          disabled={togglingStatus} onClick={() => void toggleStatus()}>
          {inactive ? 'Show in customer app' : 'Hide from customer app'}
        </button>
        <p className="c-msd-help">
          {inactive
            ? 'Hidden from the customer app — customers can’t find or book this business.'
            : 'Visible in the customer app.'}
        </p>
      </div>

      <div className="c-msd-section">
        <h3 className="c-msd-h">Persona</h3>
        <label className="c-field-label" htmlFor="msd-persona">WhatsApp assistant persona</label>
        <div className="c-input-row c-input-area" style={{ marginBottom: 10 }}>
          <textarea id="msd-persona" rows={6} placeholder="Leave empty to use the default (based on the business category)…"
            value={persona} onChange={(e) => { setPersona(e.target.value); setPersonaSaved(false); }} />
        </div>
        <button className="c-btn c-btn-block" disabled={savingPersona} onClick={() => void savePersona()}>
          {savingPersona ? 'Saving…' : personaSaved ? 'Saved ✓' : 'Save persona'}
        </button>
        <p className="c-msd-help">Controls how the bot replies on this business’s own WhatsApp number.</p>
      </div>

      <div className="c-msd-section">
        <h3 className="c-msd-h">Activity</h3>
        <div className="c-master-meta">
          <span>{shop.bookings_count ?? 0} bookings</span>
          <span>Joined {shortDate(shop.created_at)}</span>
          <span>Last login {shortDate(shop.last_login_at)}</span>
        </div>
      </div>
    </div></div>
  );
}
```

- [ ] **Step 4: Add the route** — in `src/App.tsx`, add directly under line 44 (`<Route path="/master" element={<MasterShops />} />`):

```tsx
          <Route path="/master/:id" element={<MasterShopDetail />} />
```

And add the import after line 19 (`import MasterShops from '@/pages/MasterShops';`):

```tsx
import MasterShopDetail from '@/pages/MasterShopDetail';
```

- [ ] **Step 5: Append detail styles** — add to the end of `src/styles/customer.css`:

```css
/* Master — business detail */
.c-msd-hero { display: flex; gap: 14px; align-items: center; margin: 4px 16px 16px; }
.c-msd-hero .c-msc-thumb { width: 64px; height: 64px; font-size: 26px; }
.c-msd-sub { margin: 2px 0 6px; color: var(--text-3); font-size: 13px; }
.c-msd-section { margin: 0 16px 18px; }
.c-msd-h { margin: 0 0 10px; font-size: 12px; font-weight: 700; text-transform: uppercase; letter-spacing: 0.1em; color: var(--text-3); }
.c-msd-action { display: inline-flex; align-items: center; justify-content: center; gap: 8px; width: 100%; margin-top: 10px; text-decoration: none; }
.c-msd-action.off { color: var(--mint-300); border-color: var(--border-mint); background: var(--mint-soft); }
.c-msd-help { margin: 8px 2px 0; font-size: 11.5px; color: var(--text-4); }
.c-msd-section .c-master-meta { margin-top: 0; }
```

- [ ] **Step 6: Run tests to verify they pass**

Run: `npx vitest run src/pages/MasterShopDetail.test.tsx`
Expected: PASS — all three cases green.

- [ ] **Step 7: Commit**

```bash
git add src/pages/MasterShopDetail.tsx src/pages/MasterShopDetail.test.tsx src/App.tsx src/styles/customer.css
git commit -m "feat(admin): master business detail screen (creds, visibility toggle, persona)"
```

---

### Task 7: Refactor `MasterShops.tsx` list to use `MasterShopCard`

**Files:**
- Modify: `src/pages/MasterShops.tsx`

- [ ] **Step 1: Swap the inline card for `MasterShopCard`** — in `src/pages/MasterShops.tsx`:

  1. Add imports near the existing component imports:

```tsx
import { MasterShopCard } from '@/components/MasterShopCard';
import { shortDate } from '@/lib/format';
```

  2. Delete the local `shortDate` function at the top of the file (now imported from `@/lib/format`). Keep using `shortDate` for the created-shop card if present.

  3. Replace the entire `filtered.map((s) => ( ... ))` block (the inline `<div key={s.id} className="c-master-card"> ... </div>`) with:

```tsx
        filtered.map((s) => (
          <MasterShopCard
            key={s.id}
            shop={s}
            onOpen={(id) => navigate(`/master/${id}`, { state: { shop: s } })}
          />
        ))
```

> Note: leave the created-shop success card (the `createdShop && (...)` block) and the "Add business" form exactly as they are — they use `.c-master-card` and other existing classes and are unrelated to the per-business list card.

- [ ] **Step 2: Type-check + run the existing MasterShops tests (if any) and the new card test**

Run: `npx tsc --noEmit && npx vitest run src/components/MasterShopCard.test.tsx src/pages/MasterShopDetail.test.tsx`
Expected: PASS, no type errors. (If `shortDate` is now unused in MasterShops after the swap, remove the `shortDate` import there to satisfy lint/tsc.)

- [ ] **Step 3: Manual smoke (optional but recommended)**

Run: `npm run dev` and open `/master`. Expected: businesses render as horizontal monogram cards; tapping one opens `/master/:id` with credentials, a visibility toggle, and a persona editor.

- [ ] **Step 4: Commit**

```bash
git add src/pages/MasterShops.tsx
git commit -m "feat(admin): render master list with MasterShopCard, open detail on tap"
```

---

## PHASE 3 — LIVE BOT (whatsapp-autoreply) — additive only

> Commands run from `D:/Francis/projects/2026/Eloquent/Solutions/whatsapp-autoreply`.
> **Safety:** with no persona set on any shop (today's state), `shop-context` returns `persona: null`, the guard is false, and behavior is identical to now.

### Task 8: Carry `persona` through `resolveShopAccount`

**Files:**
- Modify: `lib/accounts.js:25-30`

- [ ] **Step 1: Add `persona` to the returned object** — in `lib/accounts.js`, update the return inside `resolveShopAccount` (lines 25-30) to:

```js
    return {
      shopName: json.shop_name,
      category: json.category || null,
      persona: json.persona || null,
      phoneNumberId: json.phone_number_id,
      token: json.token,
    };
```

Also update the JSDoc `@returns` on line 13 to include persona:

```js
 * @returns {Promise<{shopName: string, category: string|null, persona: string|null, phoneNumberId: string, token: string} | null>}
```

- [ ] **Step 2: Smoke-check the mapping in isolation** (no live calls; verifies the field is plumbed):

Run:
```bash
node --input-type=module -e "import('./lib/accounts.js').then(m => { console.log(typeof m.resolveShopAccount === 'function' ? 'ok: resolveShopAccount exported' : 'MISSING'); })"
```
Expected: prints `ok: resolveShopAccount exported` (module loads, no syntax error).

- [ ] **Step 3: Commit**

```bash
git add lib/accounts.js
git commit -m "feat(bot): carry per-shop persona through shop-context lookup"
```

---

### Task 9: Use the shop persona when present (additive)

**Files:**
- Modify: `server.js:365-368`

- [ ] **Step 1: Guard the tenant-number prompt selection** — in `server.js`, replace the `else` branch of the persona selection (lines 365-368):

Current:
```js
  } else {
    activePrompt = buildProviderPrompt(shopAccount.shopName, shopAccount.category);
    offerTools = false;
  }
```

Replace with:
```js
  } else {
    // Tenant number: use the shop's custom persona if the master set one,
    // otherwise fall back to the category-based default (unchanged behavior).
    activePrompt = shopAccount.persona && shopAccount.persona.trim()
      ? shopAccount.persona
      : buildProviderPrompt(shopAccount.shopName, shopAccount.category);
    offerTools = false;
  }
```

- [ ] **Step 2: Verify the server file still parses**

Run:
```bash
node --check server.js
```
Expected: no output, exit code 0 (syntax OK).

- [ ] **Step 3: Manual end-to-end verification (after backend + bot are deployed)**

1. With no persona set on a test shop, message its WhatsApp number → bot replies as before (category-based).
2. In admin `/master/:id` for that shop, set a persona (e.g. "You are Mario, reply only in playful one-liners."), save.
3. Message the shop's number again → reply now reflects the custom persona.
4. Clear the persona (blank + save) → reply reverts to the default.

Expected: behavior matches each step; live traffic for shops without a persona is unchanged throughout.

- [ ] **Step 4: Commit**

```bash
git add server.js
git commit -m "feat(bot): tenant replies use per-shop persona when set (additive)"
```

---

## Rollout

1. Deploy **backend** (migration + endpoint) via git pull on the droplet (`/var/www/eloquent-backend`, runs `php artisan migrate`).
2. Deploy **admin** (static SPA) per the static-SPA deploy process.
3. Deploy **whatsapp-autoreply** — safe at any time; backward compatible until a persona is set.

## Self-review notes (verified against spec)

- Spec A (list redesign) → Tasks 5, 7. Monogram thumb, WA + Inactive badges, whole-card navigation. ✓
- Spec B (detail screen) → Task 6. Creds + copy + "Send login to owner", visibility toggle, persona editor, activity. Data from router state with `getMasterShops()` fallback. ✓
- Spec C (backend) → Tasks 1-3. `persona` column, `PATCH /master/shops/{id}`, persona in master list + shop-context. `status` already returned; customer `/shops` already filters `active`. ✓
- Spec D (bot, additive) → Tasks 8-9. Persona used only when present; null → unchanged `buildProviderPrompt`. ✓
- Type consistency: `updateMasterShop(id, { status?, persona? })` used identically in lib (Task 4), detail page (Task 6), and tests. `MasterShop.persona?: string \| null` (Task 4) matches backend payload (Task 2). `shortDate` single source in `src/lib/format.ts` (Task 4) consumed by Tasks 5, 6, 7. ✓
