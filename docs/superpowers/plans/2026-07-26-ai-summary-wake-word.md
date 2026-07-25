# AI Summary Voice Wake Word — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let an owner say their business name on `/ai-summary` to hear the AI summary read aloud, with the wake phrase configurable in Settings.

**Architecture:** A nullable `shops.wake_phrase` column (falling back to the shop's own name) exposed through a small `ShopWakeWordController`. In the SPA, a pure fuzzy matcher (`lib/wakeWord.ts`) is driven by a browser `SpeechRecognition` hook (`hooks/useWakeWord.ts`); the AI Summary page pairs that hook with its existing text-to-speech playback, and a new Settings page edits the phrase.

**Tech Stack:** Laravel 12 + Sanctum + spatie/permission (backend, PostgreSQL); React 18 + TypeScript + Vite + Vitest + React Testing Library (`admin/`); browser Web Speech API (`SpeechRecognition` / `webkitSpeechRecognition`).

**Spec:** `docs/superpowers/specs/2026-07-26-ai-summary-wake-word-design.md`

## Global Constraints

- **Multi-tenant — no hardcoded identity.** The wake-phrase default is always derived from `$shop->name`. Never bake a specific business name into a default, fixture, or placeholder. Shop is always derived from the auth token, never from a request parameter.
- **⛔ NEVER run `php artisan test` in `/var/www/eloquent-backend` (production) or `/var/www/eloquent-backend-staging`.** Doing so has wiped the production database before. Backend tests run ONLY in the isolated `/root/testrun` copy — see "Running backend tests" below.
- **Backend tests never run locally.** Local PHP is broken (8.0 / untrusted binary). Frontend tests and `npx tsc --noEmit` DO run locally in `admin/`.
- **New routes 404 until the route cache is dropped.** After touching `routes/api.php`, run `php artisan optimize:clear` in the test copy before running tests.
- Work directly on `main`. No feature branches.
- Every failure path degrades to today's tap-to-play behaviour. The feature is additive: with speech recognition unsupported, disabled, or blocked, `/ai-summary` must behave exactly as it does now.
- Copy is British/neutral English and never names a specific tenant.

### Running backend tests

Build the isolated harness once per session:

```bash
ssh root@64.227.153.90 'rm -rf /root/testrun && mkdir -p /root/testrun && cd /var/www/eloquent-backend-staging && tar -cf - --exclude=./.git --exclude=./storage/logs --exclude=./storage/framework/cache --exclude=./storage/framework/sessions --exclude=./storage/framework/views . | (cd /root/testrun && tar -xf -) && cd /root/testrun && composer dump-autoload -o --no-interaction && php artisan config:clear'
```

Then for each run, sync the changed source and run the suite:

```bash
tar -cf - app tests database routes | ssh root@64.227.153.90 'cd /root/testrun && tar -xf - && php artisan optimize:clear && php artisan test --filter=ShopWakeWordTest'
```

`rm -rf /root/testrun` when finished — the droplet disk runs ~96% full.

---

## File Structure

**Backend**
- Create `database/migrations/2026_07_26_000001_add_wake_phrase_to_shops_table.php` — adds the nullable column.
- Create `app/Http/Controllers/ShopWakeWordController.php` — `show` + `update`, and the shop-name fallback. Single responsibility: the wake phrase.
- Modify `routes/api.php` — one auth-only GET, one `settings.manage` PUT.
- Create `tests/Feature/ShopWakeWordTest.php` — endpoint behaviour, permission gating, tenant isolation.

**Frontend**
- Create `admin/src/lib/speechRecognition.ts` — the browser speech-recognition feature detector and its narrowed types, in one place. *(Added 2026-07-26 during the Task 3 review: the original plan hand-rolled this detector separately in `WakeWordSettings.tsx` and in `useWakeWord.ts`. The review flagged the duplication and Francis ruled it should be shared. Both consumers import `speechRecognitionCtor()` and `SpeechRecognitionLike` from here — do not reintroduce a private copy.)*
- Create `admin/src/lib/wakeWord.ts` — pure matcher. No DOM, no network. Carries the bulk of the test coverage.
- Create `admin/src/lib/wakeWord.test.ts`
- Create `admin/src/lib/wakeWordApi.ts` — `getWakeWord` / `saveWakeWord`. Kept separate so the matcher stays pure.
- Create `admin/src/hooks/useWakeWord.ts` — SpeechRecognition lifecycle (start/stop/restart/errors).
- Create `admin/src/hooks/useWakeWord.test.ts`
- Create `admin/src/pages/WakeWordSettings.tsx` + `admin/src/pages/WakeWordSettings.test.tsx` — the Settings screen.
- Modify `admin/src/lib/nav.ts` — register the Settings entry.
- Modify `admin/src/App.tsx` — register the route behind `settings.manage`.
- Modify `admin/src/pages/AiSummary.tsx` — lift playback into a hook, add the Listen toggle, wire the wake word.
- Create `admin/src/pages/AiSummary.wakeword.test.tsx`

---

## Task 1: Backend — wake phrase column, controller and routes

**Files:**
- Create: `database/migrations/2026_07_26_000001_add_wake_phrase_to_shops_table.php`
- Create: `app/Http/Controllers/ShopWakeWordController.php`
- Modify: `routes/api.php:255-258` (the `settings.manage` group)
- Test: `tests/Feature/ShopWakeWordTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `GET /api/shop/wake-word` and `PUT /api/shop/wake-word`, both returning JSON `{ phrase: string|null, effective_phrase: string, using_custom: boolean }`. Task 3's `wakeWordApi.ts` consumes this exact shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/ShopWakeWordTest.php`. The `actingOwner` helper mirrors `tests/Feature/AiInsightsEndpointTest.php:20-29` — the repo's proven RBAC token pattern.

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\ShopUser;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ShopWakeWordTest extends TestCase
{
    use RefreshDatabase;

    /** Owner token: full permissions for this shop's team. */
    private function actingOwner(Shop $shop): string
    {
        setPermissionsTeamId($shop->id);
        $owner = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($owner);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    /** A user whose role holds `summary.view` but NOT `settings.manage`. */
    private function actingViewer(Shop $shop): string
    {
        (new PermissionSeeder())->run();
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Viewer', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->givePermissionTo('summary.view');
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $u->id])->save();
        return $new->plainTextToken;
    }

    private function auth(string $token): array
    {
        return ['Authorization' => "Bearer $token"];
    }

    public function test_get_falls_back_to_the_shop_name_when_unset(): void
    {
        // The default must come from the shop's own name — never a hardcoded one.
        $shop = Shop::factory()->create(['name' => 'Northside Barbers']);
        $token = $this->actingOwner($shop);

        $this->withHeaders($this->auth($token))->getJson('/api/shop/wake-word')
            ->assertOk()
            ->assertJsonPath('phrase', null)
            ->assertJsonPath('effective_phrase', 'Northside Barbers')
            ->assertJsonPath('using_custom', false);
    }

    public function test_put_saves_a_custom_phrase(): void
    {
        $shop = Shop::factory()->create(['name' => 'Northside Barbers']);
        $token = $this->actingOwner($shop);

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => '  Northside  '])
            ->assertOk()
            ->assertJsonPath('phrase', 'Northside')      // trimmed on the way in
            ->assertJsonPath('effective_phrase', 'Northside')
            ->assertJsonPath('using_custom', true);

        $this->assertSame('Northside', $shop->fresh()->wake_phrase);
    }

    public function test_put_empty_clears_back_to_the_shop_name(): void
    {
        $shop = Shop::factory()->create(['name' => 'Northside Barbers']);
        $token = $this->actingOwner($shop);
        $this->withHeaders($this->auth($token))->putJson('/api/shop/wake-word', ['phrase' => 'Northside'])->assertOk();

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => '   '])
            ->assertOk()
            ->assertJsonPath('phrase', null)
            ->assertJsonPath('effective_phrase', 'Northside Barbers');
    }

    public function test_put_rejects_a_phrase_under_three_characters(): void
    {
        // A 1-2 character phrase would fire on ordinary conversation.
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => 'ab'])
            ->assertStatus(422);
    }

    public function test_put_rejects_a_phrase_over_sixty_characters(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingOwner($shop);

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => str_repeat('a', 61)])
            ->assertStatus(422);
    }

    public function test_get_is_allowed_without_settings_manage(): void
    {
        // The AI Summary page needs the phrase; summary.view users do not hold
        // settings.manage, and a business name is not sensitive.
        $shop = Shop::factory()->create(['name' => 'Northside Barbers']);
        $token = $this->actingViewer($shop);

        $this->withHeaders($this->auth($token))->getJson('/api/shop/wake-word')
            ->assertOk()
            ->assertJsonPath('effective_phrase', 'Northside Barbers');
    }

    public function test_put_requires_settings_manage(): void
    {
        $shop = Shop::factory()->create();
        $token = $this->actingViewer($shop);

        $this->withHeaders($this->auth($token))
            ->putJson('/api/shop/wake-word', ['phrase' => 'Northside'])
            ->assertStatus(403);
    }

    public function test_requires_authentication(): void
    {
        $this->getJson('/api/shop/wake-word')->assertStatus(401);
    }

    public function test_one_shop_never_reads_or_writes_another_shops_phrase(): void
    {
        $a = Shop::factory()->create(['name' => 'Shop A']);
        $b = Shop::factory()->create(['name' => 'Shop B']);
        $tokenA = $this->actingOwner($a);
        $this->actingOwner($b);

        // The shop comes from the token; there is no shop_id input to abuse.
        $this->withHeaders($this->auth($tokenA))
            ->putJson('/api/shop/wake-word', ['phrase' => 'Alpha'])
            ->assertOk();

        $this->assertSame('Alpha', $a->fresh()->wake_phrase);
        $this->assertNull($b->fresh()->wake_phrase);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
tar -cf - app tests database routes | ssh root@64.227.153.90 'cd /root/testrun && tar -xf - && php artisan optimize:clear && php artisan test --filter=ShopWakeWordTest'
```

Expected: FAIL — the route does not exist (404 instead of 200/403) and `wake_phrase` is not a column.

- [ ] **Step 3: Add the migration**

Create `database/migrations/2026_07_26_000001_add_wake_phrase_to_shops_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive + reversible: the phrase an owner says on the AI Summary page to
// hear it read aloud. Null = fall back to the shop's own name (no hardcoded
// tenant identity).
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->string('wake_phrase', 60)->nullable()->after('simulation_script');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('wake_phrase');
        });
    }
};
```

No `$casts` entry is needed — it is a plain nullable string, and `Shop` uses `protected $guarded = []` so it is already mass-assignable.

- [ ] **Step 4: Write the controller**

Create `app/Http/Controllers/ShopWakeWordController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The per-shop voice wake phrase. Saying it on the AI Summary page plays the
 * summary aloud. Null = use the shop's own name, so every tenant gets a working
 * wake word with no setup and no hardcoded identity.
 */
class ShopWakeWordController extends Controller
{
    public function show(Request $request)
    {
        return response()->json($this->payload($this->requireShop($request)));
    }

    public function update(Request $request)
    {
        $shop = $this->requireShop($request);

        // Trim first, then treat an empty result as "clear it" — so a blank
        // field restores the shop-name fallback instead of failing min:3.
        $raw = $request->input('phrase');
        $trimmed = is_string($raw) ? trim($raw) : null;
        $request->merge(['phrase' => ($trimmed === '' ? null : $trimmed)]);

        $data = $request->validate([
            'phrase' => ['nullable', 'string', 'min:3', 'max:60'],
        ]);

        $shop->update(['wake_phrase' => $data['phrase'] ?? null]);

        return response()->json($this->payload($shop->fresh()));
    }

    private function payload(Shop $shop): array
    {
        $custom = is_string($shop->wake_phrase) && trim($shop->wake_phrase) !== '';

        return [
            'phrase' => $custom ? $shop->wake_phrase : null,
            'effective_phrase' => $custom ? $shop->wake_phrase : (string) $shop->name,
            'using_custom' => $custom,
        ];
    }

    private function requireShop(Request $request): Shop
    {
        $user = $request->user();
        if (!$user || !($user instanceof Shop)) {
            throw new HttpException(403, 'Shop authentication required');
        }
        return $user;
    }
}
```

- [ ] **Step 5: Register the routes**

In `routes/api.php`, immediately above the existing `can.perm:settings.manage` group (currently at line 255), add the auth-only GET, and add the PUT inside that existing group:

```php
// The AI Summary page listens for this phrase, so the READ is auth-only:
// summary.view users do not necessarily hold settings.manage, and the value is
// a business name, not a secret. The shop is derived from the token as always.
Route::middleware(['auth:sanctum', 'rbac.context'])->group(function () {
    Route::get('/shop/wake-word', [\App\Http\Controllers\ShopWakeWordController::class, 'show']);
});
Route::middleware(['auth:sanctum', 'rbac.context', 'can.perm:settings.manage'])->group(function () {
    Route::put('/shop/wake-word', [\App\Http\Controllers\ShopWakeWordController::class, 'update']);
    Route::get('/shop/simulation', [\App\Http\Controllers\ShopSimulationController::class, 'show']);
    Route::put('/shop/simulation', [\App\Http\Controllers\ShopSimulationController::class, 'update']);
});
```

- [ ] **Step 6: Run the tests to verify they pass**

```bash
tar -cf - app tests database routes | ssh root@64.227.153.90 'cd /root/testrun && tar -xf - && php artisan optimize:clear && php artisan test --filter=ShopWakeWordTest'
```

Expected: PASS — 9 tests. If any test 404s, the route cache is stale: re-run with `php artisan optimize:clear` before `php artisan test`.

- [ ] **Step 7: Commit**

```bash
git add database/migrations/2026_07_26_000001_add_wake_phrase_to_shops_table.php app/Http/Controllers/ShopWakeWordController.php routes/api.php tests/Feature/ShopWakeWordTest.php
git commit -m "feat(api): per-shop voice wake phrase, defaulting to the shop name"
```

---

## Task 2: Frontend — the pure fuzzy matcher

**Files:**
- Create: `admin/src/lib/wakeWord.ts`
- Test: `admin/src/lib/wakeWord.test.ts`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `normalise(text: string): string`
  - `matchesWakePhrase(heard: string, phrase: string): boolean`

  Task 4's hook and Task 6's page both call `matchesWakePhrase`.

- [ ] **Step 1: Write the failing test**

Create `admin/src/lib/wakeWord.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { normalise, matchesWakePhrase } from './wakeWord';

describe('normalise', () => {
  it('lowercases, strips punctuation and collapses whitespace', () => {
    expect(normalise('  Hey,   Northside!  ')).toBe('hey northside');
  });
});

describe('matchesWakePhrase', () => {
  const phrase = 'Northside';

  it('matches the phrase said on its own', () => {
    expect(matchesWakePhrase('northside', phrase)).toBe(true);
  });

  it('matches with a "hey" prefix', () => {
    expect(matchesWakePhrase('hey northside', phrase)).toBe(true);
  });

  it('matches other filler prefixes', () => {
    expect(matchesWakePhrase('ok northside', phrase)).toBe(true);
    expect(matchesWakePhrase('okay northside', phrase)).toBe(true);
    expect(matchesWakePhrase('hello northside', phrase)).toBe(true);
  });

  it('matches with a trailing word', () => {
    expect(matchesWakePhrase('northside barbers', phrase)).toBe(true);
  });

  it('matches the phrase mid-sentence', () => {
    expect(matchesWakePhrase('so i said northside please', phrase)).toBe(true);
  });

  it('ignores casing and punctuation', () => {
    expect(matchesWakePhrase('Hey, NORTHSIDE!', phrase)).toBe(true);
  });

  it('tolerates a single-character mishearing', () => {
    // Speech-to-text routinely drops or swaps a letter in a business name.
    expect(matchesWakePhrase('northsid', phrase)).toBe(true);
    expect(matchesWakePhrase('northsyde', phrase)).toBe(true);
  });

  it('does not match an unrelated sentence', () => {
    expect(matchesWakePhrase('what is the weather today', phrase)).toBe(false);
  });

  it('does not match a different, similar-length word', () => {
    expect(matchesWakePhrase('countryside', phrase)).toBe(false);
  });

  it('matches a multi-word phrase', () => {
    expect(matchesWakePhrase('hey northside barbers please', 'Northside Barbers')).toBe(true);
  });

  it('requires an exact match for a short phrase', () => {
    // A 1-edit tolerance on a 3-letter phrase would fire on ordinary speech.
    expect(matchesWakePhrase('zip', 'zap')).toBe(false);
    expect(matchesWakePhrase('zap', 'zap')).toBe(true);
  });

  it('never matches on an empty or whitespace phrase', () => {
    expect(matchesWakePhrase('anything at all', '')).toBe(false);
    expect(matchesWakePhrase('anything at all', '   ')).toBe(false);
  });

  it('never matches on empty heard text', () => {
    expect(matchesWakePhrase('', phrase)).toBe(false);
  });

  it('does not match when the phrase is longer than what was heard', () => {
    expect(matchesWakePhrase('north', 'Northside Barbers Downtown')).toBe(false);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/lib/wakeWord.test.ts`
Expected: FAIL — `Failed to resolve import "./wakeWord"`.

- [ ] **Step 3: Write the implementation**

Create `admin/src/lib/wakeWord.ts`:

```ts
/**
 * Wake-phrase matching for the AI Summary page. Pure and DOM-free — the browser
 * speech API lives in hooks/useWakeWord.ts, so this module can be tested on its
 * own and carries the feature's real correctness coverage.
 *
 * Speech-to-text routinely mishears business names, so matching is fuzzy: an
 * edit budget scaled to the phrase length. Short phrases get a budget of zero,
 * because one allowed edit on a three-letter word fires on ordinary chatter.
 */

/** Optional openers a speaker naturally puts in front of the phrase. */
const FILLERS = ['hey', 'hi', 'hello', 'ok', 'okay'];

/** Lowercase, strip punctuation, collapse whitespace. */
export function normalise(text: string): string {
  return text
    .toLowerCase()
    .replace(/[^\p{L}\p{N}\s]/gu, ' ')
    .replace(/\s+/g, ' ')
    .trim();
}

/** Classic Levenshtein distance between two normalised strings. */
function distance(a: string, b: string): number {
  if (a === b) return 0;
  if (!a.length) return b.length;
  if (!b.length) return a.length;

  let prev = Array.from({ length: b.length + 1 }, (_, i) => i);
  for (let i = 1; i <= a.length; i++) {
    const row = [i];
    for (let j = 1; j <= b.length; j++) {
      const cost = a[i - 1] === b[j - 1] ? 0 : 1;
      row[j] = Math.min(row[j - 1] + 1, prev[j] + 1, prev[j - 1] + cost);
    }
    prev = row;
  }
  return prev[b.length];
}

/** Edit budget: one per five characters, capped at three, none under five. */
function tolerance(phrase: string): number {
  return Math.min(Math.floor(phrase.length / 5), 3);
}

/**
 * True when `heard` contains the wake phrase, allowing an optional filler
 * opener and a small number of mishearings.
 */
export function matchesWakePhrase(heard: string, phrase: string): boolean {
  const target = normalise(phrase);
  if (!target) return false;

  let words = normalise(heard).split(' ').filter(Boolean);
  if (!words.length) return false;
  if (FILLERS.includes(words[0])) words = words.slice(1);

  const span = target.split(' ').length;
  const budget = tolerance(target);

  // Slide a window of the phrase's word count over the heard words. Also try a
  // window one word longer, so "northside barbers" still matches "Northside"
  // when the extra word is short enough to fall inside the edit budget.
  for (const size of [span, span + 1]) {
    for (let i = 0; i + size <= words.length; i++) {
      const window = words.slice(i, i + size).join(' ');
      if (distance(window, target) <= budget) return true;
    }
  }
  return false;
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd admin && npx vitest run src/lib/wakeWord.test.ts`
Expected: PASS — 15 tests.

If `countryside` vs `northside` matches (distance 3, budget 1 for a 9-character phrase — it should not), re-check `tolerance`. If `northsyde` fails, check that `distance` is symmetric.

> **Amended 2026-07-26 after the Task 2 review.** An earlier draft of this step also carried a prefix branch inside the window loop
> (`if (size > span && distance(window.slice(0, target.length), target) <= budget) return true;`).
> The review showed it was dead code — every test passes without it, because
> "northside barbers" already matches "Northside" at `size === span` — and that
> where it did fire it truncated the window before measuring, making trailing
> text free against the edit budget. Francis ruled it out on 2026-07-26. Do not
> reintroduce it.

- [ ] **Step 5: Type-check**

Run: `cd admin && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add admin/src/lib/wakeWord.ts admin/src/lib/wakeWord.test.ts
git commit -m "feat(admin): fuzzy wake-phrase matcher"
```

---

## Task 3: Frontend — API client and the Settings page

**Files:**
- Create: `admin/src/lib/wakeWordApi.ts`
- Create: `admin/src/pages/WakeWordSettings.tsx`
- Modify: `admin/src/lib/nav.ts:60-76` (`ALL_SETTINGS_OPTIONS`)
- Modify: `admin/src/App.tsx:116-119` (the `settings.manage` route group)
- Test: `admin/src/pages/WakeWordSettings.test.tsx`

**Interfaces:**
- Consumes: `GET`/`PUT /api/shop/wake-word` from Task 1; `matchesWakePhrase` from Task 2.
- Produces:
  - `type WakeWordInfo = { phrase: string | null; effective_phrase: string; using_custom: boolean }`
  - `getWakeWord(): Promise<WakeWordInfo>`
  - `saveWakeWord(phrase: string | null): Promise<WakeWordInfo>`

  Task 6 calls `getWakeWord`.

- [ ] **Step 1: Write the failing test**

Create `admin/src/pages/WakeWordSettings.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import * as api from '@/lib/wakeWordApi';
import WakeWordSettings from './WakeWordSettings';

const navigate = vi.fn();
vi.mock('react-router-dom', async (orig) => ({ ...(await orig() as object), useNavigate: () => navigate }));
vi.mock('@/lib/wakeWordApi');

const unset: api.WakeWordInfo = { phrase: null, effective_phrase: 'Northside Barbers', using_custom: false };

describe('WakeWordSettings', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (api.getWakeWord as ReturnType<typeof vi.fn>).mockResolvedValue(unset);
    (api.saveWakeWord as ReturnType<typeof vi.fn>).mockResolvedValue(unset);
  });

  it('shows the effective phrase as the placeholder when nothing is saved', async () => {
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    expect(await screen.findByPlaceholderText('Northside Barbers')).toBeInTheDocument();
  });

  it('shows the saved phrase when one exists', async () => {
    (api.getWakeWord as ReturnType<typeof vi.fn>).mockResolvedValue(
      { phrase: 'Northside', effective_phrase: 'Northside', using_custom: true },
    );
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    expect(await screen.findByDisplayValue('Northside')).toBeInTheDocument();
  });

  it('saves the typed phrase', async () => {
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    const input = await screen.findByPlaceholderText('Northside Barbers');
    fireEvent.change(input, { target: { value: 'Northside' } });
    fireEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(api.saveWakeWord).toHaveBeenCalledWith('Northside'));
  });

  it('saves null when the field is cleared', async () => {
    (api.getWakeWord as ReturnType<typeof vi.fn>).mockResolvedValue(
      { phrase: 'Northside', effective_phrase: 'Northside', using_custom: true },
    );
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    const input = await screen.findByDisplayValue('Northside');
    fireEvent.change(input, { target: { value: '  ' } });
    fireEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(api.saveWakeWord).toHaveBeenCalledWith(null));
  });

  it('shows an error when saving fails', async () => {
    (api.saveWakeWord as ReturnType<typeof vi.fn>).mockRejectedValue(new Error('nope'));
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    const input = await screen.findByPlaceholderText('Northside Barbers');
    fireEvent.change(input, { target: { value: 'Northside' } });
    fireEvent.click(screen.getByRole('button', { name: /save/i }));
    expect(await screen.findByText(/could not save/i)).toBeInTheDocument();
  });

  it('hides the Test button when the browser has no speech recognition', async () => {
    render(<MemoryRouter><WakeWordSettings /></MemoryRouter>);
    await screen.findByPlaceholderText('Northside Barbers');
    expect(screen.queryByRole('button', { name: /test it/i })).toBeNull();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/WakeWordSettings.test.tsx`
Expected: FAIL — `Failed to resolve import "./WakeWordSettings"`.

- [ ] **Step 3: Write the API client**

Create `admin/src/lib/wakeWordApi.ts`:

```ts
import api from './api';

export type WakeWordInfo = {
  /** The saved override, or null when the shop name is being used. */
  phrase: string | null;
  /** What actually gets listened for: the override, or the shop's name. */
  effective_phrase: string;
  using_custom: boolean;
};

/** The shop's voice wake phrase for the AI Summary page. */
export async function getWakeWord(): Promise<WakeWordInfo> {
  const { data } = await api.get('/shop/wake-word');
  return data;
}

/** Save the phrase; pass null to fall back to the shop's name. */
export async function saveWakeWord(phrase: string | null): Promise<WakeWordInfo> {
  const { data } = await api.put('/shop/wake-word', { phrase });
  return data;
}
```

- [ ] **Step 4: Write the Settings page**

Create `admin/src/pages/WakeWordSettings.tsx`. Layout, classnames and the error/notice conventions mirror `admin/src/pages/SimulationSettings.tsx`.

```tsx
import { useEffect, useRef, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getWakeWord, saveWakeWord, type WakeWordInfo } from '@/lib/wakeWordApi';
import { matchesWakePhrase } from '@/lib/wakeWord';

type SpeechCtor = new () => {
  continuous: boolean; interimResults: boolean; lang: string;
  start(): void; stop(): void;
  onresult: ((e: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null;
  onend: (() => void) | null;
  onerror: ((e: { error: string }) => void) | null;
};

/** The browser's speech recognition constructor, or null where unsupported. */
function speechCtor(): SpeechCtor | null {
  const w = window as unknown as { SpeechRecognition?: SpeechCtor; webkitSpeechRecognition?: SpeechCtor };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

/**
 * Sets the phrase the owner says on the AI Summary page to hear it read aloud.
 * Empty = fall back to the business's own name.
 */
export default function WakeWordSettings() {
  const navigate = useNavigate();
  const [info, setInfo] = useState<WakeWordInfo | null>(null);
  const [value, setValue] = useState('');
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  // Live "Test it" state.
  const [testing, setTesting] = useState(false);
  const [heard, setHeard] = useState('');
  const [testResult, setTestResult] = useState<'' | 'hit' | 'miss'>('');
  const recRef = useRef<ReturnType<SpeechCtor> | null>(null);
  const supported = speechCtor() !== null;

  useEffect(() => {
    let alive = true;
    getWakeWord()
      .then((r) => { if (alive) { setInfo(r); setValue(r.phrase ?? ''); } })
      .catch(() => { if (alive) setError('Could not load your wake word.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; recRef.current?.stop(); };
  }, []);

  const save = async () => {
    setSaving(true); setError(''); setNotice('');
    const trimmed = value.trim();
    try {
      const r = await saveWakeWord(trimmed === '' ? null : trimmed);
      setInfo(r); setValue(r.phrase ?? ''); setNotice('Saved.');
    } catch {
      setError('Could not save. Please try again.');
    } finally { setSaving(false); }
  };

  // Listens for ~6s and reports whether what it heard would wake the summary.
  const test = () => {
    const Ctor = speechCtor();
    if (!Ctor || testing) return;
    const target = value.trim() || info?.effective_phrase || '';
    setHeard(''); setTestResult(''); setTesting(true);

    const rec = new Ctor();
    recRef.current = rec;
    rec.continuous = true;
    rec.interimResults = true;
    rec.lang = navigator.language || 'en-US';
    rec.onresult = (e) => {
      const text = Array.from(e.results).map((r) => r[0].transcript).join(' ');
      setHeard(text);
      if (matchesWakePhrase(text, target)) { setTestResult('hit'); rec.stop(); }
    };
    rec.onerror = () => { setTesting(false); };
    rec.onend = () => {
      setTesting(false);
      setTestResult((prev) => (prev === 'hit' ? 'hit' : 'miss'));
    };
    try { rec.start(); } catch { setTesting(false); return; }
    window.setTimeout(() => { try { rec.stop(); } catch { /* already stopped */ } }, 6000);
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading wake word…" /></div>;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">Voice wake word</h1>
          <p className="c-page-sub">Say this on the AI Summary page to hear your summary read aloud, hands-free.</p>
        </div>
        <button className="c-icon-btn" aria-label="Back to settings" onClick={() => navigate('/settings')}><Icons.ChevronLeft size={18} /></button>
      </div>

      {error && <div className="c-error-box">{error}</div>}
      {notice && <div style={{ margin: '0 16px 12px', padding: 12, borderRadius: 'var(--r-md)', background: 'var(--mint-soft)', border: '1px solid var(--border-mint)', color: 'var(--mint-300)', fontSize: 13, textAlign: 'center' }}>{notice}</div>}

      <div style={{ padding: '0 16px 24px', display: 'flex', flexDirection: 'column', gap: 16 }}>
        <label style={{ display: 'flex', flexDirection: 'column', gap: 6, fontSize: 12, color: 'var(--text-4)' }}>
          Wake phrase
          <input
            value={value}
            placeholder={info?.effective_phrase ?? ''}
            maxLength={60}
            onChange={(e) => { setValue(e.target.value); setNotice(''); setTestResult(''); }}
            style={{ background: 'none', border: '1px solid var(--line, #333)', borderRadius: 8, color: 'var(--text-1)', padding: '10px 12px', font: 'inherit', fontSize: 15 }}
          />
        </label>

        <p style={{ margin: 0, fontSize: 12.5, color: 'var(--text-4)', lineHeight: 1.5 }}>
          Saying “hey” first is optional, and close-enough pronunciations still work — so
          “{info?.effective_phrase}” also wakes on “hey {info?.effective_phrase?.toLowerCase()}”.
          Leave this empty to use your business name.
        </p>

        {supported && (
          <div>
            <button className="c-btn-ghost" style={{ width: '100%' }} disabled={testing} onClick={test}>
              <Icons.Mic size={15} /> {testing ? 'Listening…' : 'Test it'}
            </button>
            {(heard || testResult) && (
              <p style={{ margin: '8px 4px 0', fontSize: 12.5, color: testResult === 'hit' ? 'var(--mint-300)' : 'var(--text-4)' }}>
                {heard ? `Heard: “${heard}”` : 'Heard nothing.'}
                {testResult === 'hit' && ' — that would wake it.'}
                {testResult === 'miss' && ' — that would not wake it.'}
              </p>
            )}
          </div>
        )}

        <button className="c-btn" disabled={saving} onClick={() => void save()}>{saving ? 'Saving…' : 'Save'}</button>
      </div>
    </div></div>
  );
}
```

- [ ] **Step 5: Register the Settings entry**

In `admin/src/lib/nav.ts`, add to `ALL_SETTINGS_OPTIONS` immediately after the `Demo simulation` entry (line 67):

```ts
  { label: 'Voice wake word', sub: 'Say this to hear your summary out loud', to: '/settings/wake-word', icon: 'Mic', modules: BOTH, perm: 'settings.manage' },
```

- [ ] **Step 6: Register the route**

In `admin/src/App.tsx`, add the import alongside the other page imports:

```tsx
import WakeWordSettings from '@/pages/WakeWordSettings';
```

and add the route inside the existing `settings.manage` group (line 116-119):

```tsx
            <Route path="/settings/wake-word" element={<WakeWordSettings />} />
```

- [ ] **Step 7: Run the tests to verify they pass**

Run: `cd admin && npx vitest run src/pages/WakeWordSettings.test.tsx src/lib/nav.test.ts`
Expected: PASS. If `nav.test.ts` asserts an exact option count, update that expectation to include the new entry.

- [ ] **Step 8: Type-check**

Run: `cd admin && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 9: Commit**

```bash
git add admin/src/lib/wakeWordApi.ts admin/src/pages/WakeWordSettings.tsx admin/src/pages/WakeWordSettings.test.tsx admin/src/lib/nav.ts admin/src/App.tsx
git commit -m "feat(admin): Voice wake word settings page"
```

---

## Task 4: Frontend — the SpeechRecognition hook

**Files:**
- Create: `admin/src/hooks/useWakeWord.ts`
- Test: `admin/src/hooks/useWakeWord.test.ts`

**Interfaces:**
- Consumes: `matchesWakePhrase` from Task 2.
- Produces:

  ```ts
  useWakeWord(opts: {
    phrase: string;
    enabled: boolean;
    onWake: () => void;
  }): { supported: boolean; listening: boolean; blocked: boolean }
  ```

  Task 6 calls this from `AiSummary.tsx`.

- [ ] **Step 1: Write the failing test**

Create `admin/src/hooks/useWakeWord.test.ts`. The test installs a fake `SpeechRecognition` on `window` — no test ever opens a real microphone.

```ts
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { renderHook, act } from '@testing-library/react';
import { useWakeWord } from './useWakeWord';

/** Minimal stand-in for the browser's SpeechRecognition. */
class FakeRecognition {
  static instances: FakeRecognition[] = [];
  continuous = false;
  interimResults = false;
  lang = '';
  started = false;
  onresult: ((e: unknown) => void) | null = null;
  onend: (() => void) | null = null;
  onerror: ((e: { error: string }) => void) | null = null;

  constructor() { FakeRecognition.instances.push(this); }
  start() { this.started = true; }
  stop() { this.started = false; this.onend?.(); }

  /** Simulate the browser reporting speech. */
  hear(transcript: string) {
    this.onresult?.({ results: [[{ transcript }]] });
  }
  fail(error: string) { this.onerror?.({ error }); }
}

const w = window as unknown as { SpeechRecognition?: unknown; webkitSpeechRecognition?: unknown };

beforeEach(() => {
  vi.useFakeTimers();
  FakeRecognition.instances = [];
  w.SpeechRecognition = FakeRecognition;
});

afterEach(() => {
  vi.useRealTimers();
  delete w.SpeechRecognition;
  delete w.webkitSpeechRecognition;
});

describe('useWakeWord', () => {
  it('reports unsupported when the browser has no speech recognition', () => {
    delete w.SpeechRecognition;
    const { result } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    expect(result.current.supported).toBe(false);
    expect(result.current.listening).toBe(false);
  });

  it('starts listening when enabled', () => {
    const { result } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    expect(result.current.supported).toBe(true);
    expect(FakeRecognition.instances[0].started).toBe(true);
    expect(result.current.listening).toBe(true);
  });

  it('does not start when disabled', () => {
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: false, onWake: vi.fn() }));
    expect(FakeRecognition.instances.length).toBe(0);
  });

  it('does not start with an empty phrase', () => {
    renderHook(() => useWakeWord({ phrase: '', enabled: true, onWake: vi.fn() }));
    expect(FakeRecognition.instances.length).toBe(0);
  });

  it('calls onWake when it hears the phrase', () => {
    const onWake = vi.fn();
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake }));
    act(() => { FakeRecognition.instances[0].hear('hey northside'); });
    expect(onWake).toHaveBeenCalledTimes(1);
  });

  it('ignores speech that is not the phrase', () => {
    const onWake = vi.fn();
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake }));
    act(() => { FakeRecognition.instances[0].hear('what is the weather'); });
    expect(onWake).not.toHaveBeenCalled();
  });

  it('debounces repeated interim results into a single wake', () => {
    const onWake = vi.fn();
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake }));
    act(() => {
      FakeRecognition.instances[0].hear('hey northside');
      FakeRecognition.instances[0].hear('hey northside');
    });
    expect(onWake).toHaveBeenCalledTimes(1);
  });

  it('restarts after the browser ends the session', () => {
    renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    act(() => { FakeRecognition.instances[0].onend?.(); });
    act(() => { vi.advanceTimersByTime(500); });
    expect(FakeRecognition.instances.length).toBe(2);
    expect(FakeRecognition.instances[1].started).toBe(true);
  });

  it('stops and reports blocked when permission is denied, without retrying', () => {
    const { result } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    act(() => { FakeRecognition.instances[0].fail('not-allowed'); });
    act(() => { vi.advanceTimersByTime(5000); });
    expect(result.current.blocked).toBe(true);
    expect(result.current.listening).toBe(false);
    expect(FakeRecognition.instances.length).toBe(1);
  });

  it('stops listening when it becomes disabled', () => {
    const { result, rerender } = renderHook(
      ({ enabled }) => useWakeWord({ phrase: 'Northside', enabled, onWake: vi.fn() }),
      { initialProps: { enabled: true } },
    );
    expect(result.current.listening).toBe(true);
    rerender({ enabled: false });
    expect(result.current.listening).toBe(false);
  });

  it('stops listening on unmount', () => {
    const { unmount } = renderHook(() => useWakeWord({ phrase: 'Northside', enabled: true, onWake: vi.fn() }));
    const rec = FakeRecognition.instances[0];
    unmount();
    expect(rec.started).toBe(false);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/hooks/useWakeWord.test.ts`
Expected: FAIL — `Failed to resolve import "./useWakeWord"`.

- [ ] **Step 3: Write the hook**

> **Amended 2026-07-26 after the Task 4 review.** The reference code below carried three lifecycle bugs that the original fake recogniser (synchronous, non-accumulating) could not expose. All three were fixed in the implementation; do not restore the original forms:
> 1. `onresult` joined the whole of `e.results`. In a real `continuous` session that list grows for the life of the session, so a finalized wake phrase re-fired `onWake` on every later utterance. Now slices from `e.resultIndex`, and `SpeechRecognitionLike`'s event type carries `resultIndex`.
> 2. `onend` checked only the per-effect-run `stopped` flag, so a superseded recogniser's late `onend` set `listening: false` while a fresh one was live — hit on every `enabled` toggle, which is the caller's mute-while-speaking pattern. Now guarded by `recRef.current !== rec`.
> 3. The `visibilitychange` handler set the terminal `stopped` flag, so listening never resumed after a tab switch. Now symmetric: pause on hide, resume on show, while a `blocked` mic stays terminal.

Create `admin/src/hooks/useWakeWord.ts`:

```ts
import { useEffect, useRef, useState } from 'react';
import { matchesWakePhrase } from '@/lib/wakeWord';

type Recognition = {
  continuous: boolean;
  interimResults: boolean;
  lang: string;
  start(): void;
  stop(): void;
  onresult: ((e: { results: ArrayLike<ArrayLike<{ transcript: string }>> }) => void) | null;
  onend: (() => void) | null;
  onerror: ((e: { error: string }) => void) | null;
};

type RecognitionCtor = new () => Recognition;

function speechCtor(): RecognitionCtor | null {
  if (typeof window === 'undefined') return null;
  const w = window as unknown as { SpeechRecognition?: RecognitionCtor; webkitSpeechRecognition?: RecognitionCtor };
  return w.SpeechRecognition ?? w.webkitSpeechRecognition ?? null;
}

/** Two wakes closer together than this are the same utterance (interim results repeat). */
const WAKE_DEBOUNCE_MS = 1500;
/** Browsers end a continuous session every so often; restart after a short beat. */
const RESTART_MS = 400;
/** Give up after this many restarts that produced nothing, rather than looping hot. */
const MAX_RESTARTS = 40;

/**
 * Listens continuously for a wake phrase and calls `onWake` when it hears it.
 *
 * Everything stays in the browser — no audio is uploaded and no assistant
 * credits are spent. The caller is responsible for setting `enabled` to false
 * while it is speaking, so the summary's own audio cannot re-trigger a wake.
 */
export function useWakeWord({ phrase, enabled, onWake }: {
  phrase: string;
  enabled: boolean;
  onWake: () => void;
}): { supported: boolean; listening: boolean; blocked: boolean } {
  const supported = speechCtor() !== null;
  const [listening, setListening] = useState(false);
  const [blocked, setBlocked] = useState(false);

  // Keep the latest callback so a long-lived recogniser never calls a stale one.
  const onWakeRef = useRef(onWake);
  onWakeRef.current = onWake;
  const phraseRef = useRef(phrase);
  phraseRef.current = phrase;

  const recRef = useRef<Recognition | null>(null);
  const lastWakeRef = useRef(0);
  const restartsRef = useRef(0);
  const timerRef = useRef<number | null>(null);

  useEffect(() => {
    const Ctor = speechCtor();
    const active = enabled && !!Ctor && phrase.trim().length > 0;
    if (!active) { setListening(false); return; }

    let stopped = false;
    restartsRef.current = 0;
    setBlocked(false);

    const start = () => {
      if (stopped) return;
      const rec = new Ctor!();
      recRef.current = rec;
      rec.continuous = true;
      rec.interimResults = true;
      rec.lang = (typeof navigator !== 'undefined' && navigator.language) || 'en-US';

      rec.onresult = (e) => {
        const text = Array.from(e.results).map((r) => r[0].transcript).join(' ');
        if (!matchesWakePhrase(text, phraseRef.current)) return;
        const now = Date.now();
        if (now - lastWakeRef.current < WAKE_DEBOUNCE_MS) return;
        lastWakeRef.current = now;
        onWakeRef.current();
      };

      rec.onerror = (e) => {
        // A denied mic is terminal — flag it and stop, never retry in a loop.
        if (e.error === 'not-allowed' || e.error === 'service-not-allowed') {
          stopped = true;
          setBlocked(true);
          setListening(false);
        }
      };

      rec.onend = () => {
        if (stopped) { setListening(false); return; }
        if (restartsRef.current++ >= MAX_RESTARTS) { setListening(false); return; }
        timerRef.current = window.setTimeout(start, RESTART_MS);
      };

      try { rec.start(); setListening(true); }
      catch { setListening(false); }
    };

    start();

    // Never hold the mic open on a page or tab the owner has left.
    const onHidden = () => { if (document.hidden) { stopped = true; try { recRef.current?.stop(); } catch { /* already stopped */ } } };
    document.addEventListener('visibilitychange', onHidden);

    return () => {
      stopped = true;
      document.removeEventListener('visibilitychange', onHidden);
      if (timerRef.current != null) window.clearTimeout(timerRef.current);
      try { recRef.current?.stop(); } catch { /* already stopped */ }
      recRef.current = null;
      setListening(false);
    };
  }, [enabled, phrase]);

  return { supported, listening, blocked };
}
```

- [ ] **Step 4: Run the tests to verify they pass**

Run: `cd admin && npx vitest run src/hooks/useWakeWord.test.ts`
Expected: PASS — 11 tests.

If the restart test sees only one instance, check that `onend` schedules `start` through `window.setTimeout` (the test advances fake timers by 500ms against a 400ms delay).

- [ ] **Step 5: Type-check**

Run: `cd admin && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 6: Commit**

```bash
git add admin/src/hooks/useWakeWord.ts admin/src/hooks/useWakeWord.test.ts
git commit -m "feat(admin): continuous wake-word listening hook"
```

---

## Task 5: Refactor — lift AI Summary playback into a hook

Pure refactor, no behaviour change. Playback state currently lives inside `PlayCard`, but Task 6 needs to trigger playback from outside it and needs to know when it is speaking. Doing this as its own task keeps the wake-word wiring reviewable.

**Files:**
- Modify: `admin/src/pages/AiSummary.tsx:89-134` (`PlayCard`) and `:249-259` (the page body)
- Test: `admin/src/pages/AiSummary.periods.test.tsx` (must still pass untouched)

**Interfaces:**
- Consumes: `speak` from `@/lib/simulation` (already imported).
- Produces, inside `AiSummary.tsx`:

  ```ts
  function useSpeakSummary(text: string): {
    status: 'idle' | 'loading' | 'playing';
    toggle: () => void;
  }
  ```

  and `PlayCard` becomes presentational: `PlayCard({ ready, status, onToggle }: { ready: boolean; status: 'idle' | 'loading' | 'playing'; onToggle: () => void })`. Task 6 reads `status` to pause listening while speaking.

- [ ] **Step 1: Add the hook and make PlayCard presentational**

In `admin/src/pages/AiSummary.tsx`, replace the whole `PlayCard` block (lines 89-134) with:

```tsx
/* ---------- summary playback ------------------------------------------------ */
/**
 * Text-to-speech for the summary. Lifted out of PlayCard so the page can both
 * trigger playback (from the wake word) and see when it is speaking.
 */
function useSpeakSummary(text: string) {
  const [status, setStatus] = useState<'idle' | 'loading' | 'playing'>('idle');
  const audioRef = useRef<HTMLAudioElement | null>(null);
  // Keep the latest values out of the callback's closure.
  const textRef = useRef(text);
  textRef.current = text;
  const statusRef = useRef(status);
  statusRef.current = status;

  useEffect(() => () => { audioRef.current?.pause(); }, []);

  const toggle = useCallback(async () => {
    // Playing → stop it.
    if (statusRef.current === 'playing') { audioRef.current?.pause(); setStatus('idle'); return; }
    if (statusRef.current === 'loading' || !textRef.current) return;
    audioRef.current?.pause();          // start fresh (replay from the beginning)
    try {
      setStatus('loading');
      const url = await speak(textRef.current.slice(0, 900), 'nova');
      const el = new Audio(url);
      audioRef.current = el;
      el.onended = () => { setStatus('idle'); URL.revokeObjectURL(url); };
      el.onerror = () => setStatus('idle');
      await el.play();
      setStatus('playing');
    } catch { setStatus('idle'); }
  }, []);

  return { status, toggle: () => void toggle() };
}

/* ---------- play (mic) card -------------------------------------------------- */
function PlayCard({ ready, status, onToggle }: {
  ready: boolean; status: 'idle' | 'loading' | 'playing'; onToggle: () => void;
}) {
  return (
    <div className="ais-play-card">
      <button className={`ais-mic${status === 'playing' ? ' is-playing' : ''}`}
        onClick={onToggle} disabled={!ready || status === 'loading'}
        aria-label={status === 'playing' ? 'Stop' : 'Play summary'}>
        <span className="ais-mic-rings" aria-hidden="true"><i /><i /><i /></span>
        <span className="ais-mic-core">
          {status === 'playing'
            ? <span className="ais-eq" aria-hidden="true"><i /><i /><i /><i /><i /></span>
            : <Icons.Mic size={38} />}
        </span>
      </button>
      <p className="ais-play-title">
        {status === 'loading' ? 'Preparing…' : status === 'playing' ? 'Speaking…' : ready ? 'Play summary' : 'No summary yet'}
      </p>
      <p className="ais-play-sub">
        {status === 'playing' ? 'Tap to stop.'
          : ready ? 'Tap to hear your summary read aloud — tap again to replay.'
          : 'Generate a summary to listen.'}
      </p>
    </div>
  );
}
```

- [ ] **Step 2: Wire the hook into the page body**

In the `AiSummary` component, add the hook call just after `spokenText` is computed (currently line 245-247), and update the render:

```tsx
  const spokenText = data && data.state === 'ok'
    ? [data.summary, ...data.patterns, ...data.recommendations].filter(Boolean).join('. ')
    : '';

  const play = useSpeakSummary(spokenText);
```

and replace the `<PlayCard …/>` line (currently line 252) with:

```tsx
        <PlayCard ready={!!spokenText} status={play.status} onToggle={play.toggle} />
```

- [ ] **Step 3: Run the existing tests to verify nothing broke**

Run: `cd admin && npx vitest run src/pages/AiSummary.periods.test.tsx`
Expected: PASS — 4 tests, unchanged. This refactor must not alter behaviour.

- [ ] **Step 4: Type-check**

Run: `cd admin && npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 5: Commit**

```bash
git add admin/src/pages/AiSummary.tsx
git commit -m "refactor(admin): lift AI summary playback into useSpeakSummary"
```

---

## Task 6: Wire the wake word into the AI Summary page

**Files:**
- Modify: `admin/src/pages/AiSummary.tsx`
- Test: `admin/src/pages/AiSummary.wakeword.test.tsx`

**Interfaces:**
- Consumes: `useWakeWord` (Task 4), `getWakeWord` (Task 3), `useSpeakSummary` / `PlayCard` (Task 5).
- Produces: the finished feature. Nothing downstream depends on it.

- [ ] **Step 1: Write the failing test**

Create `admin/src/pages/AiSummary.wakeword.test.tsx`:

```tsx
import { render, screen, fireEvent, waitFor } from '@testing-library/react';
import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import AiSummary from './AiSummary';

vi.mock('@/context/ShopContext', () => ({ useShop: () => ({ shop: { id: 1, name: 'Northside Barbers' } }) }));
vi.mock('@/lib/simulation', () => ({ speak: vi.fn().mockResolvedValue('blob:fake') }));

const getAiInsights = vi.fn();
const getAiSummaryHistory = vi.fn();
vi.mock('@/lib/aiInsights', () => ({
  getAiInsights: (...a: unknown[]) => getAiInsights(...a),
  getAiSummaryHistory: (...a: unknown[]) => getAiSummaryHistory(...a),
}));

const getWakeWord = vi.fn();
vi.mock('@/lib/wakeWordApi', () => ({ getWakeWord: () => getWakeWord() }));

// Capture the hook's arguments so the test can fire a wake without a real mic.
let hookArgs: { phrase: string; enabled: boolean; onWake: () => void } | null = null;
const hookReturn = { supported: true, listening: true, blocked: false };
vi.mock('@/hooks/useWakeWord', () => ({
  useWakeWord: (opts: { phrase: string; enabled: boolean; onWake: () => void }) => {
    hookArgs = opts;
    return hookReturn;
  },
}));

const ok = { state: 'ok', summary: 'S', patterns: [], recommendations: [], message: '', generated_at: '', cached: false };

beforeEach(() => {
  hookArgs = null;
  hookReturn.supported = true;
  hookReturn.blocked = false;
  localStorage.clear();
  getAiInsights.mockReset().mockResolvedValue(ok);
  getAiSummaryHistory.mockReset().mockResolvedValue({ data: [], has_more: false });
  getWakeWord.mockReset().mockResolvedValue(
    { phrase: null, effective_phrase: 'Northside Barbers', using_custom: false },
  );
});

afterEach(() => { localStorage.clear(); });

describe('AiSummary wake word', () => {
  it('listens for the shop wake phrase by default', async () => {
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs?.phrase).toBe('Northside Barbers'));
    expect(hookArgs?.enabled).toBe(true);
  });

  it('uses the saved custom phrase when there is one', async () => {
    getWakeWord.mockResolvedValue({ phrase: 'Northside', effective_phrase: 'Northside', using_custom: true });
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs?.phrase).toBe('Northside'));
  });

  it('falls back to the shop name when the request fails', async () => {
    getWakeWord.mockRejectedValue(new Error('offline'));
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs?.phrase).toBe('Northside Barbers'));
  });

  it('plays the summary when the wake phrase is heard', async () => {
    const { speak } = await import('@/lib/simulation');
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs).not.toBeNull());
    await waitFor(() => expect(screen.getByLabelText(/play summary/i)).toBeEnabled());
    hookArgs!.onWake();
    await waitFor(() => expect(speak).toHaveBeenCalled());
  });

  it('hides the Listen toggle when speech recognition is unsupported', async () => {
    hookReturn.supported = false;
    render(<AiSummary />);
    await waitFor(() => expect(hookArgs).not.toBeNull());
    expect(screen.queryByRole('switch', { name: /listen/i })).toBeNull();
  });

  it('turning the toggle off disables listening and is remembered for the device', async () => {
    const { unmount } = render(<AiSummary />);
    await waitFor(() => expect(hookArgs?.enabled).toBe(true));

    fireEvent.click(screen.getByRole('switch', { name: /listen/i }));
    await waitFor(() => expect(hookArgs?.enabled).toBe(false));
    unmount();

    render(<AiSummary />);
    await waitFor(() => expect(hookArgs).not.toBeNull());
    expect(hookArgs?.enabled).toBe(false);
  });

  it('shows a Mic blocked note when permission is denied', async () => {
    hookReturn.blocked = true;
    render(<AiSummary />);
    expect(await screen.findByText(/mic blocked/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/AiSummary.wakeword.test.tsx`
Expected: FAIL — `hookArgs` stays null; the page does not call `useWakeWord`.

- [ ] **Step 3: Add the Listen toggle component**

In `admin/src/pages/AiSummary.tsx`, add above the `PlayCard` definition:

```tsx
/* ---------- listen toggle --------------------------------------------------- */
function ListenToggle({ on, blocked, phrase, onChange }: {
  on: boolean; blocked: boolean; phrase: string; onChange: (v: boolean) => void;
}) {
  return (
    <div className="ais-listen">
      <button role="switch" aria-checked={on && !blocked} aria-label="Listen for the wake word"
        className={`c-toggle ${on && !blocked ? 'on' : ''}`}
        onClick={() => onChange(!on)}>
        <span className="c-toggle-knob" />
      </button>
      <span className="ais-listen-label">
        {blocked ? 'Mic blocked — allow microphone access to use the wake word'
          : on ? `Listening for “${phrase}”`
          : 'Not listening'}
      </span>
    </div>
  );
}
```

- [ ] **Step 4: Wire the phrase, the toggle and the hook into the page**

Add the imports at the top of `admin/src/pages/AiSummary.tsx`:

```tsx
import { useWakeWord } from '@/hooks/useWakeWord';
import { getWakeWord } from '@/lib/wakeWordApi';
```

Inside the `AiSummary` component, after the `const play = useSpeakSummary(spokenText);` line from Task 5, add:

```tsx
  /* ---- wake word ---------------------------------------------------------- */
  // The phrase is shop-wide; whether THIS device listens is a local choice, so a
  // shared laptop can opt out without changing the business setting.
  const offKey = `wakeWord.off.${shop?.id ?? 'none'}`;
  const [phrase, setPhrase] = useState(shop?.name ?? '');
  const [listenOn, setListenOn] = useState(true);

  useEffect(() => {
    setListenOn(localStorage.getItem(offKey) !== '1');
  }, [offKey]);

  useEffect(() => {
    let alive = true;
    getWakeWord()
      .then((r) => { if (alive) setPhrase(r.effective_phrase || shop?.name || ''); })
      // Offline or a slow API must never break the page — the shop name is a
      // perfectly good wake phrase on its own.
      .catch(() => { if (alive) setPhrase(shop?.name ?? ''); });
    return () => { alive = false; };
  }, [shop?.id, shop?.name]);

  const setListen = (v: boolean) => {
    setListenOn(v);
    if (v) localStorage.removeItem(offKey); else localStorage.setItem(offKey, '1');
  };

  const wake = useWakeWord({
    phrase,
    // Stop listening while the summary is speaking, so its own audio can never
    // trigger another wake.
    enabled: listenOn && !!spokenText && play.status === 'idle',
    onWake: play.toggle,
  });
```

Then replace the `<PlayCard …/>` line with the card plus the toggle:

```tsx
        <div className="ais-play-col">
          <PlayCard ready={!!spokenText} status={play.status} onToggle={play.toggle} />
          {wake.supported && (
            <ListenToggle on={listenOn} blocked={wake.blocked} phrase={phrase} onChange={setListen} />
          )}
        </div>
```

- [ ] **Step 5: Add the styles**

Append to `admin/src/styles/insights.css`:

```css
/* Wake-word toggle under the AI summary play button. */
.ais-play-col { display: flex; flex-direction: column; align-items: center; gap: 10px; }
.ais-listen { display: flex; align-items: center; gap: 10px; padding: 0 12px; }
.ais-listen-label { font-size: 12px; color: var(--text-4); text-align: left; line-height: 1.4; }
```

- [ ] **Step 6: Run the tests to verify they pass**

Run: `cd admin && npx vitest run src/pages/AiSummary.wakeword.test.tsx src/pages/AiSummary.periods.test.tsx`
Expected: PASS — 7 new tests plus the 4 existing period tests.

If the period tests now fail on a missing `@/lib/wakeWordApi` mock, add `vi.mock('@/lib/wakeWordApi', () => ({ getWakeWord: vi.fn().mockRejectedValue(new Error('x')) }));` to `AiSummary.periods.test.tsx` — the page must still render when that call fails.

- [ ] **Step 7: Run the full frontend suite and type-check**

Run: `cd admin && npx vitest run && npx tsc --noEmit`
Expected: all tests pass, no type errors.

- [ ] **Step 8: Commit**

```bash
git add admin/src/pages/AiSummary.tsx admin/src/pages/AiSummary.wakeword.test.tsx admin/src/pages/AiSummary.periods.test.tsx admin/src/styles/insights.css
git commit -m "feat(admin): say your business name on AI Summary to hear it read aloud"
```

---

## Task 7: Verify end-to-end on staging

No new code. This is the gate before production, per the standing local → staging → prod rule.

**Files:** none.

**Interfaces:**
- Consumes: everything from Tasks 1-6.
- Produces: a verified staging deployment.

- [ ] **Step 1: Run the whole backend suite in the isolated harness**

```bash
tar -cf - app tests database routes | ssh root@64.227.153.90 'cd /root/testrun && tar -xf - && php artisan optimize:clear && php artisan test'
```

Expected: the full suite passes (~612 tests as of 2026-07-24, plus the 9 new ones). Report the actual number — do not claim a pass without the output.

- [ ] **Step 2: Push, then deploy the backend to staging**

```bash
git push origin main
ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && git pull && php artisan migrate --force && php artisan optimize:clear && php artisan route:cache && php artisan config:cache'
```

The `route:cache` step is required — without it the two new routes 404.

- [ ] **Step 3: Deploy the admin frontend to staging**

Use `admin/deploy.ps1` (the project's deploy script) targeting staging. Do not hand-roll a build + scp.

- [ ] **Step 4: Verify in a browser on staging**

Walk these and confirm each:

1. Settings shows **Voice wake word**; opening it shows the business name as the placeholder.
2. **Test it** → say the business name → shows "Heard: …" and "that would wake it".
3. Save a custom phrase, reload, confirm it persisted.
4. Open `/ai-summary` in Chrome, grant the mic, confirm the toggle reads *Listening for "…"*.
5. Say the phrase → the summary starts speaking.
6. While it speaks, confirm the toggle is not re-triggering itself.
7. Turn the toggle off, reload, confirm it stays off.
8. Log in as a user without `settings.manage` → the Settings entry is hidden, `/settings/wake-word` redirects, and `/ai-summary` still listens.
9. Open `/ai-summary` in Firefox → no toggle, tap-to-play works exactly as before.

- [ ] **Step 5: Report results**

State plainly what passed and what did not, including the iOS Safari behaviour if tested. Do not promote to production until Francis asks.

---

## Self-Review

**Spec coverage**

| Spec section | Task |
|---|---|
| Behaviour (wake → play, re-say → stop) | 5, 6 |
| `shops.wake_phrase` + shop-name fallback | 1 |
| `GET` auth-only, `PUT` gated on `settings.manage` | 1 |
| Validation (trim, min 3, max 60, empty clears) | 1 |
| Settings page + nav entry + route | 3 |
| "Test it" button | 3 |
| Pure matcher, normalise, filler strip, window slide, tolerance | 2 |
| `useWakeWord` hook, auto-restart, unsupported, denied, cleanup, debounce | 4 |
| Pause while speaking (self-trigger guard) | 6 |
| Listen toggle, default on, per-device localStorage keyed by shop | 6 |
| Error-handling table (all rows) | 1, 4, 6 |
| Testing section (matcher, backend, settings page, AiSummary) | 1, 2, 3, 4, 6 |

**Type consistency:** `WakeWordInfo` (Task 3) matches the controller payload (Task 1) field for field. `matchesWakePhrase(heard, phrase)` keeps that argument order in Tasks 3, 4 and its own tests. `useSpeakSummary` returns `{ status, toggle }` in Task 5 and is consumed as `play.status` / `play.toggle` in Task 6. `useWakeWord` returns `{ supported, listening, blocked }` in Task 4 and is read as `wake.supported` / `wake.blocked` in Task 6.

**Note on `listening`:** the hook returns it and Task 6 does not render it — the toggle reflects the owner's *intent* (`listenOn`), not the recogniser's momentary state, which flickers during auto-restart. It stays on the hook's interface because it is asserted in Task 4's tests.
