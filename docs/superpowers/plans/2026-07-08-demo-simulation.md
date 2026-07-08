# Demo Simulation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** A reusable, per-shop, editable dry-run simulation that replays a scripted voice booking conversation on the real Ask chat screen (voice notes both sides, professional female voices) and ends on a booking preview — creating no real booking.

**Architecture:** Fully client-side playback plus text-to-speech. A per-shop JSON "script" (turns, booking fields, voices, pacing) is stored on the shop and edited from Settings. Pressing Play opens the Ask screen in `?sim=1` mode, which reads the script, voices each line via the existing `/tts` endpoint, plays it as a voice-note bubble, then navigates to a read-only booking preview rendered from the script. No booking/customer rows are ever written.

**Tech Stack:** Laravel (PHP 8.4) backend, React + TypeScript + Vite admin SPA, Vitest for frontend tests, PHPUnit for backend, OpenAI TTS (already wired).

## Global Constraints

- **No feature branches.** Work directly on `main`; commit and push to `main` (per project rule).
- **Backend (PHP) tests run ONLY on the droplet** (`64.227.153.90`, `php8.4`) against a scratch DB — NEVER locally (local PHP is broken) and NEVER against the prod DB. Frontend Vitest runs locally with `npm test`.
- **No hardcoded tenant identity.** The default script must be built from the *current shop's* real services/staff — never bake one shop's name/brand into defaults.
- **Dry-run guarantee:** the simulation must never call a booking-create / customer-create endpoint, never persist a `Booking`/`ShopCustomer`, never fire reminders/notifications, and never use a real phone number (ship a fake one).
- OpenAI TTS voice whitelist (both female defaults): `nova` (assistant), `shimmer` (owner). Full whitelist: `nova`, `shimmer`, `coral`, `sage`, `alloy`.

---

## File Structure

**Backend**
- Modify `app/Http/Controllers/TtsController.php` — accept optional whitelisted `voice`.
- Modify `tests/Feature/TtsControllerTest.php` — voice honoured/rejected.
- Create `database/migrations/2026_07_08_000001_add_simulation_script_to_shops_table.php` — nullable json column.
- Modify `app/Models/Shop.php` — `$fillable` + `$casts` for `simulation_script`.
- Create `app/Http/Controllers/ShopSimulationController.php` — GET/PUT the script, default generator.
- Create `tests/Feature/ShopSimulationControllerTest.php`.
- Modify `routes/api.php` — two routes.

**Frontend**
- Create `admin/src/lib/simulation.ts` — types, `getSimulation`, `saveSimulation`, `speak`.
- Create `admin/src/lib/simulation.test.ts`.
- Create `admin/src/pages/SimulationSettings.tsx` — editor + Play.
- Create `admin/src/pages/SimulationSettings.test.tsx`.
- Create `admin/src/pages/BookingPreview.tsx` — read-only booking payoff from route state.
- Create `admin/src/pages/BookingPreview.test.tsx`.
- Modify `admin/src/pages/VoiceAssistant.tsx` — `?sim=1` player, `AudioBubble` gains `onEnded`.
- Modify `admin/src/pages/VoiceAssistant.test.tsx` — sim-mode test.
- Modify `admin/src/pages/Settings.tsx` — add the "Demo simulation" card.
- Modify `admin/src/App.tsx` — routes for `/settings/simulation` and `/booking/preview`.

---

## Task 1: Backend — TTS accepts a whitelisted voice

**Files:**
- Modify: `app/Http/Controllers/TtsController.php`
- Test: `tests/Feature/TtsControllerTest.php`

**Interfaces:**
- Produces: `POST /api/tts` now accepts optional `voice` (string). When present and in the whitelist it is used; otherwise the configured default. Response unchanged (`audio/mpeg`).

- [ ] **Step 1: Write the failing test** — add to `tests/Feature/TtsControllerTest.php`:

```php
    public function test_uses_whitelisted_voice_from_request(): void
    {
        config(['services.openai.key' => 'k', 'services.openai.tts_voice' => 'nova']);
        \Illuminate\Support\Facades\Cache::flush();
        Http::fake(['api.openai.com/*' => Http::response('BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->postJson('/api/tts', ['text' => 'hello there', 'voice' => 'shimmer'])->assertOk();

        Http::assertSent(fn ($req) => str_contains($req->url(), '/v1/audio/speech') && $req['voice'] === 'shimmer');
    }

    public function test_ignores_unknown_voice_and_uses_default(): void
    {
        config(['services.openai.key' => 'k', 'services.openai.tts_voice' => 'nova']);
        \Illuminate\Support\Facades\Cache::flush();
        Http::fake(['api.openai.com/*' => Http::response('BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);

        $this->postJson('/api/tts', ['text' => 'hello there', 'voice' => 'bogus'])->assertOk();

        Http::assertSent(fn ($req) => $req['voice'] === 'nova');
    }
```

- [ ] **Step 2: Run the tests to verify they fail** (on the droplet — see Global Constraints)

Run (from droplet, scratch DB): `php8.4 artisan test --filter=TtsControllerTest`
Expected: the two new tests FAIL (request voice ignored; still sends `nova`).

- [ ] **Step 3: Implement** — in `app/Http/Controllers/TtsController.php`, replace the voice resolution (around the current `$voice = (string) config(...)` line) with:

```php
        $model = (string) config('services.openai.tts_model', 'gpt-4o-mini-tts');
        $default = (string) config('services.openai.tts_voice', 'nova');

        // Optional caller-chosen voice, whitelisted to the voices the demo uses.
        $allowed = ['nova', 'shimmer', 'coral', 'sage', 'alloy'];
        $requested = (string) $request->input('voice', '');
        $voice = in_array($requested, $allowed, true) ? $requested : $default;
```

(The rest of the method — cache key already includes `$voice`, the HTTP call, response — is unchanged.)

- [ ] **Step 4: Run the tests to verify they pass**

Run: `php8.4 artisan test --filter=TtsControllerTest`
Expected: PASS (all four tests, including the two originals).

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/TtsController.php tests/Feature/TtsControllerTest.php
git commit -m "feat(tts): accept optional whitelisted voice for demo simulation"
```

---

## Task 2: Backend — add `simulation_script` column to shops

**Files:**
- Create: `database/migrations/2026_07_08_000001_add_simulation_script_to_shops_table.php`
- Modify: `app/Models/Shop.php` (`$fillable`, `$casts`)

**Interfaces:**
- Produces: `Shop::$simulation_script` is a nullable array (JSON column), mass-assignable.

- [ ] **Step 1: Create the migration**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Additive + reversible: stores the per-shop demo-simulation script (turns,
// booking preview fields, voices, pacing). Null = use the generated default.
return new class extends Migration {
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->json('simulation_script')->nullable()->after('persona');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn('simulation_script');
        });
    }
};
```

Note: if `persona` is not a column on `shops` in this codebase, drop the `->after('persona')` clause. Verify with a quick check before running: `php8.4 artisan db:table shops` (or inspect `\Schema::getColumnListing('shops')` in tinker).

- [ ] **Step 2: Update the model** — in `app/Models/Shop.php`, add `'simulation_script'` to `$fillable` (wherever the fillable array is) and add to `$casts` (currently lines 31–34):

```php
    protected $casts = [
        'last_login_at' => 'datetime',
        'modules' => 'array',
        'simulation_script' => 'array',
    ];
```

- [ ] **Step 3: Run the migration on the scratch/staging DB** (NEVER prod)

Run (droplet, staging or scratch DB): `php8.4 artisan migrate`
Expected: `Migrating: ...add_simulation_script_to_shops_table` → `DONE`.

- [ ] **Step 4: Sanity-check the column exists**

Run: `php8.4 artisan tinker --execute='echo in_array("simulation_script", Schema::getColumnListing("shops")) ? "OK" : "MISSING";'`
Expected: `OK`.

- [ ] **Step 5: Commit**

```bash
git add database/migrations/2026_07_08_000001_add_simulation_script_to_shops_table.php app/Models/Shop.php
git commit -m "feat(shops): add nullable simulation_script json column"
```

---

## Task 3: Backend — ShopSimulationController (GET/PUT + default)

**Files:**
- Create: `app/Http/Controllers/ShopSimulationController.php`
- Create: `tests/Feature/ShopSimulationControllerTest.php`
- Modify: `routes/api.php`

**Interfaces:**
- Consumes: `Shop::$simulation_script` (Task 2).
- Produces:
  - `GET /api/shop/simulation` → `{ script: <object> }` — the saved script, or a generated default when none saved.
  - `PUT /api/shop/simulation` with body `{ script: <object|null> }` → `{ message, script }`. Null/empty clears back to the default.
  - Script shape (the object): `{ turns: [{who:'owner'|'assistant', text:string}], booking: {customer_name, customer_phone, service, price, date, start_time, end_time, staff_name}, voices: {owner:string, assistant:string}, thinking_ms:number }`.

- [ ] **Step 1: Write the failing test** — `tests/Feature/ShopSimulationControllerTest.php`. Match the auth pattern used by other shop-scoped feature tests in this repo (look at `tests/Feature/OwnerAssistantControllerTest.php` for how a `Shop` is authenticated — reuse that helper/setup verbatim).

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Models\Service;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopSimulationControllerTest extends TestCase
{
    use RefreshDatabase;

    private function actingShop(): Shop
    {
        $shop = Shop::factory()->create(['modules' => ['bookings']]);
        // Authenticate as the shop the same way other shop-scoped tests do.
        $this->actingAs($shop, 'shop'); // adjust guard name to match this repo
        return $shop;
    }

    public function test_get_returns_default_when_none_saved(): void
    {
        $shop = $this->actingShop();
        Service::factory()->create(['shop_id' => $shop->id, 'title' => 'Hair Cut', 'price' => '150.00']);

        $res = $this->getJson('/api/shop/simulation')->assertOk();

        $res->assertJsonPath('script.voices.owner', 'shimmer');
        $res->assertJsonPath('script.voices.assistant', 'nova');
        $this->assertNotEmpty($res->json('script.turns'));
        // Default must be built from the shop's real service — no hardcoded identity.
        $this->assertStringContainsString('Hair Cut', json_encode($res->json('script')));
        // Fake number only.
        $this->assertNotEmpty($res->json('script.booking.customer_phone'));
    }

    public function test_put_saves_and_get_returns_it(): void
    {
        $this->actingShop();
        $script = [
            'turns' => [
                ['who' => 'owner', 'text' => 'Book Mia for a facial tomorrow at 2.'],
                ['who' => 'assistant', 'text' => 'Done — Mia is booked.'],
            ],
            'booking' => [
                'customer_name' => 'Mia', 'customer_phone' => '055 010 2030',
                'service' => 'Facial', 'price' => '200.00',
                'date' => '2026-07-09', 'start_time' => '14:00', 'end_time' => '14:45',
                'staff_name' => 'Aisha',
            ],
            'voices' => ['owner' => 'coral', 'assistant' => 'nova'],
            'thinking_ms' => 1200,
        ];

        $this->putJson('/api/shop/simulation', ['script' => $script])->assertOk();

        $this->getJson('/api/shop/simulation')
            ->assertOk()
            ->assertJsonPath('script.turns.0.text', 'Book Mia for a facial tomorrow at 2.')
            ->assertJsonPath('script.voices.owner', 'coral');
    }

    public function test_put_null_clears_back_to_default(): void
    {
        $shop = $this->actingShop();
        Service::factory()->create(['shop_id' => $shop->id, 'title' => 'Hair Cut', 'price' => '150.00']);
        $this->putJson('/api/shop/simulation', ['script' => ['turns' => [['who' => 'owner', 'text' => 'x']], 'booking' => [], 'voices' => ['owner' => 'coral', 'assistant' => 'nova'], 'thinking_ms' => 1000]])->assertOk();

        $this->putJson('/api/shop/simulation', ['script' => null])->assertOk();

        $this->getJson('/api/shop/simulation')->assertOk()->assertJsonPath('script.voices.owner', 'shimmer');
    }

    public function test_requires_shop_auth(): void
    {
        $this->getJson('/api/shop/simulation')->assertStatus(401); // or 403 — match repo convention
    }
}
```

Note: adjust the guard name (`'shop'`), the unauth status code, and factory usage to match this repo's existing feature tests before running — copy the exact setup from `OwnerAssistantControllerTest`.

- [ ] **Step 2: Run to verify it fails**

Run (droplet, scratch DB): `php8.4 artisan test --filter=ShopSimulationControllerTest`
Expected: FAIL (no route / controller).

- [ ] **Step 3: Create the controller** — `app/Http/Controllers/ShopSimulationController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Shop;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Per-shop demo-simulation script. This drives a client-side, dry-run replay
 * of a voice booking for recording marketing videos — it NEVER creates a real
 * booking. Only the script config is stored here. Null = use a default built
 * from the shop's own services/staff (no hardcoded tenant identity).
 */
class ShopSimulationController extends Controller
{
    private const VOICES = ['nova', 'shimmer', 'coral', 'sage', 'alloy'];

    public function show(Request $request)
    {
        $shop = $this->requireShop($request);
        $script = $shop->simulation_script ?: $this->defaultScript($shop);

        return response()->json(['script' => $script]);
    }

    public function update(Request $request)
    {
        $shop = $this->requireShop($request);

        $data = $request->validate([
            'script' => ['nullable', 'array'],
            'script.turns' => ['sometimes', 'array'],
            'script.turns.*.who' => ['required_with:script.turns', 'in:owner,assistant'],
            'script.turns.*.text' => ['required_with:script.turns', 'string', 'max:800'],
            'script.voices.owner' => ['sometimes', 'in:' . implode(',', self::VOICES)],
            'script.voices.assistant' => ['sometimes', 'in:' . implode(',', self::VOICES)],
            'script.thinking_ms' => ['sometimes', 'integer', 'min:0', 'max:6000'],
            'script.booking' => ['sometimes', 'array'],
        ]);

        $shop->update(['simulation_script' => $data['script'] ?? null]);

        $fresh = $shop->fresh();
        return response()->json([
            'message' => 'Simulation saved',
            'script' => $fresh->simulation_script ?: $this->defaultScript($fresh),
        ]);
    }

    /** Build a believable default from the shop's real first service + staff. */
    private function defaultScript(Shop $shop): array
    {
        $service = $shop->services()->orderBy('id')->first();
        $staff = method_exists($shop, 'staff') ? $shop->staff()->orderBy('id')->first() : null;

        $serviceName = $service->title ?? $service->name ?? 'Hair Cut';
        $price = (string) ($service->price ?? '150.00');
        $staffName = $staff->name ?? 'our stylist';
        $tomorrow = Carbon::tomorrow();

        return [
            'turns' => [
                ['who' => 'owner', 'text' => "Book Sarah in for a {$serviceName} tomorrow at 9:30."],
                ['who' => 'assistant', 'text' => "Done — Sarah's booked for a {$serviceName} tomorrow at 9:30am with {$staffName}. Want me to text her a confirmation?"],
                ['who' => 'owner', 'text' => 'Yes please, send it.'],
                ['who' => 'assistant', 'text' => "Sent. Sarah will get a WhatsApp confirmation and a reminder the day before. Anything else?"],
            ],
            'booking' => [
                'customer_name' => 'Sarah',
                'customer_phone' => '055 010 2030',
                'service' => $serviceName,
                'price' => $price,
                'date' => $tomorrow->toDateString(),
                'start_time' => '09:30',
                'end_time' => '10:00',
                'staff_name' => $staffName,
            ],
            'voices' => ['owner' => 'shimmer', 'assistant' => 'nova'],
            'thinking_ms' => 1400,
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

Note: confirm `$shop->services()` and `$shop->staff()` relationship names against `app/Models/Shop.php`; adjust if named differently.

- [ ] **Step 4: Add routes** — in `routes/api.php`, alongside the persona routes (the block around lines 167–169, inside the same shop-authenticated group):

```php
    Route::get('/shop/simulation', [\App\Http\Controllers\ShopSimulationController::class, 'show']);
    Route::put('/shop/simulation', [\App\Http\Controllers\ShopSimulationController::class, 'update']);
```

- [ ] **Step 5: Run to verify it passes**

Run: `php8.4 artisan test --filter=ShopSimulationControllerTest`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/ShopSimulationController.php tests/Feature/ShopSimulationControllerTest.php routes/api.php
git commit -m "feat(simulation): per-shop demo-simulation script GET/PUT with generated default"
```

---

## Task 4: Frontend — simulation lib + speak helper

**Files:**
- Create: `admin/src/lib/simulation.ts`
- Create: `admin/src/lib/simulation.test.ts`

**Interfaces:**
- Produces:
  - `type SimTurn = { who: 'owner' | 'assistant'; text: string }`
  - `type SimBooking = { customer_name: string; customer_phone: string; service: string; price: string; date: string; start_time: string; end_time: string; staff_name: string }`
  - `type SimScript = { turns: SimTurn[]; booking: SimBooking; voices: { owner: string; assistant: string }; thinking_ms: number }`
  - `getSimulation(): Promise<SimScript>`
  - `saveSimulation(script: SimScript | null): Promise<SimScript>`
  - `speak(text: string, voice: string): Promise<string>` — returns an object URL for the MP3.

- [ ] **Step 1: Write the failing test** — `admin/src/lib/simulation.test.ts`:

```ts
import { describe, it, expect, vi, beforeEach } from 'vitest';
import api from './api';
import { getSimulation, saveSimulation, speak } from './simulation';

vi.mock('./api');

describe('simulation lib', () => {
  beforeEach(() => vi.clearAllMocks());

  it('getSimulation returns the script', async () => {
    (api.get as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { script: { turns: [], booking: {}, voices: { owner: 'shimmer', assistant: 'nova' }, thinking_ms: 1400 } } });
    const s = await getSimulation();
    expect(s.voices.owner).toBe('shimmer');
    expect(api.get).toHaveBeenCalledWith('/shop/simulation');
  });

  it('saveSimulation PUTs the script and returns it', async () => {
    const script = { turns: [{ who: 'owner', text: 'hi' }], booking: {}, voices: { owner: 'coral', assistant: 'nova' }, thinking_ms: 1000 } as never;
    (api.put as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { script } });
    const out = await saveSimulation(script);
    expect(api.put).toHaveBeenCalledWith('/shop/simulation', { script });
    expect(out.voices.owner).toBe('coral');
  });

  it('speak posts text+voice as a blob and returns an object URL', async () => {
    (api.post as unknown as ReturnType<typeof vi.fn>).mockResolvedValue({ data: new Blob(['x'], { type: 'audio/mpeg' }) });
    const spy = vi.spyOn(URL, 'createObjectURL').mockReturnValue('blob:url');
    const url = await speak('hello', 'shimmer');
    expect(api.post).toHaveBeenCalledWith('/tts', { text: 'hello', voice: 'shimmer' }, { responseType: 'blob' });
    expect(url).toBe('blob:url');
    spy.mockRestore();
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd admin && npm test -- simulation`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement** — `admin/src/lib/simulation.ts`:

```ts
import api from './api';

export type SimTurn = { who: 'owner' | 'assistant'; text: string };
export type SimBooking = {
  customer_name: string; customer_phone: string; service: string; price: string;
  date: string; start_time: string; end_time: string; staff_name: string;
};
export type SimScript = {
  turns: SimTurn[];
  booking: SimBooking;
  voices: { owner: string; assistant: string };
  thinking_ms: number;
};

/** The shop's saved demo-simulation script (or a server-generated default). */
export async function getSimulation(): Promise<SimScript> {
  const { data } = await api.get('/shop/simulation');
  return data.script as SimScript;
}

/** Save the script; pass null to clear back to the generated default. */
export async function saveSimulation(script: SimScript | null): Promise<SimScript> {
  const { data } = await api.put('/shop/simulation', { script });
  return data.script as SimScript;
}

/** Voice one line of text; returns an object URL for the MP3 (caller revokes). */
export async function speak(text: string, voice: string): Promise<string> {
  const { data } = await api.post('/tts', { text, voice }, { responseType: 'blob' });
  return URL.createObjectURL(data as Blob);
}
```

- [ ] **Step 4: Run to verify it passes**

Run: `cd admin && npm test -- simulation`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add admin/src/lib/simulation.ts admin/src/lib/simulation.test.ts
git commit -m "feat(simulation): admin lib for script fetch/save + tts speak helper"
```

---

## Task 5: Frontend — Simulation editor page, Settings card, route

**Files:**
- Create: `admin/src/pages/SimulationSettings.tsx`
- Create: `admin/src/pages/SimulationSettings.test.tsx`
- Modify: `admin/src/pages/Settings.tsx`
- Modify: `admin/src/App.tsx`

**Interfaces:**
- Consumes: `getSimulation`, `saveSimulation`, `SimScript`, `SimTurn` (Task 4).
- Produces: route `/settings/simulation` renders `SimulationSettings`; a "Play" button navigates to `/ask?sim=1`.

- [ ] **Step 1: Write the failing test** — `admin/src/pages/SimulationSettings.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import * as sim from '@/lib/simulation';
import SimulationSettings from './SimulationSettings';

const navigate = vi.fn();
vi.mock('react-router-dom', async (orig) => ({ ...(await orig() as object), useNavigate: () => navigate }));
vi.mock('@/lib/simulation');

const script: sim.SimScript = {
  turns: [{ who: 'owner', text: 'Book Sarah for a haircut.' }, { who: 'assistant', text: 'Done.' }],
  booking: { customer_name: 'Sarah', customer_phone: '055 010 2030', service: 'Hair Cut', price: '150.00', date: '2026-07-09', start_time: '09:30', end_time: '10:00', staff_name: 'Aisha' },
  voices: { owner: 'shimmer', assistant: 'nova' },
  thinking_ms: 1400,
};

describe('SimulationSettings', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    (sim.getSimulation as ReturnType<typeof vi.fn>).mockResolvedValue(script);
    (sim.saveSimulation as ReturnType<typeof vi.fn>).mockResolvedValue(script);
  });

  it('loads and shows the script turns', async () => {
    render(<MemoryRouter><SimulationSettings /></MemoryRouter>);
    expect(await screen.findByDisplayValue('Book Sarah for a haircut.')).toBeInTheDocument();
  });

  it('saves on Save', async () => {
    render(<MemoryRouter><SimulationSettings /></MemoryRouter>);
    await screen.findByDisplayValue('Book Sarah for a haircut.');
    fireEvent.click(screen.getByRole('button', { name: /save/i }));
    await waitFor(() => expect(sim.saveSimulation).toHaveBeenCalled());
  });

  it('Play navigates to the Ask screen in sim mode', async () => {
    render(<MemoryRouter><SimulationSettings /></MemoryRouter>);
    await screen.findByDisplayValue('Book Sarah for a haircut.');
    fireEvent.click(screen.getByRole('button', { name: /play/i }));
    expect(navigate).toHaveBeenCalledWith('/ask?sim=1');
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd admin && npm test -- SimulationSettings`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement the editor** — `admin/src/pages/SimulationSettings.tsx`. Follow the visual patterns already in `admin/src/pages/Assistant.tsx` (page head, `c-btn`, `c-btn-ghost`, `c-error-box`, notice box) for consistency:

```tsx
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getSimulation, saveSimulation, type SimScript, type SimTurn } from '@/lib/simulation';

const VOICES = ['nova', 'shimmer', 'coral', 'sage', 'alloy'];

/**
 * Editor for the shop's demo simulation — a scripted, dry-run voice booking used
 * to record marketing videos. Saving stores the script per shop; Play opens the
 * real Ask screen in sim mode. No real booking is ever created.
 */
export default function SimulationSettings() {
  const navigate = useNavigate();
  const [script, setScript] = useState<SimScript | null>(null);
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');

  useEffect(() => {
    let alive = true;
    getSimulation()
      .then((s) => { if (alive) setScript(s); })
      .catch(() => { if (alive) setError('Could not load your simulation.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const patch = (p: Partial<SimScript>) => { setScript((s) => (s ? { ...s, ...p } : s)); setNotice(''); };
  const patchTurn = (i: number, t: Partial<SimTurn>) =>
    setScript((s) => (s ? { ...s, turns: s.turns.map((x, j) => (j === i ? { ...x, ...t } : x)) } : s));
  const addTurn = () =>
    setScript((s) => (s ? { ...s, turns: [...s.turns, { who: 'owner', text: '' }] } : s));
  const removeTurn = (i: number) =>
    setScript((s) => (s ? { ...s, turns: s.turns.filter((_, j) => j !== i) } : s));
  const patchBooking = (p: Partial<SimScript['booking']>) =>
    setScript((s) => (s ? { ...s, booking: { ...s.booking, ...p } } : s));

  const save = async () => {
    if (!script) return;
    setSaving(true); setError(''); setNotice('');
    try { setScript(await saveSimulation(script)); setNotice('Saved.'); }
    catch { setError('Could not save. Please try again.'); }
    finally { setSaving(false); }
  };

  if (loading) return <div className="m-screen"><Spinner label="Loading simulation…" /></div>;
  if (!script) return <div className="m-screen"><div className="m-scroll"><div className="c-error-box">{error || 'No simulation.'}</div></div></div>;

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">Demo simulation</h1>
          <p className="c-page-sub">A scripted voice booking for recording videos. Nothing here creates a real booking.</p>
        </div>
        <button className="c-icon-btn" aria-label="Back to settings" onClick={() => navigate('/settings')}><Icons.ChevronLeft size={18} /></button>
      </div>

      {error && <div className="c-error-box">{error}</div>}
      {notice && <div style={{ margin: '0 16px 12px', padding: 12, borderRadius: 'var(--r-md)', background: 'var(--mint-soft)', border: '1px solid var(--border-mint)', color: 'var(--mint-300)', fontSize: 13, textAlign: 'center' }}>{notice}</div>}

      <div style={{ padding: '0 16px 24px', display: 'flex', flexDirection: 'column', gap: 16 }}>
        {/* Conversation turns */}
        <div>
          <div className="c-field-label" style={{ margin: '0 4px 8px' }}>Conversation</div>
          <div style={{ display: 'flex', flexDirection: 'column', gap: 10 }}>
            {script.turns.map((t, i) => (
              <div key={i} className="c-input-row c-input-area" style={{ flexDirection: 'column', alignItems: 'stretch', gap: 6, padding: 10 }}>
                <div style={{ display: 'flex', gap: 8, alignItems: 'center' }}>
                  <select aria-label={`Turn ${i + 1} speaker`} value={t.who} onChange={(e) => patchTurn(i, { who: e.target.value as SimTurn['who'] })}
                    style={{ background: 'none', color: 'var(--text-1)', border: '1px solid var(--line, #333)', borderRadius: 8, padding: '4px 8px', font: 'inherit' }}>
                    <option value="owner">You</option>
                    <option value="assistant">Assistant</option>
                  </select>
                  <button className="c-icon-btn" aria-label={`Remove turn ${i + 1}`} onClick={() => removeTurn(i)} style={{ marginLeft: 'auto' }}><Icons.Trash size={15} /></button>
                </div>
                <textarea aria-label={`Turn ${i + 1} text`} rows={2} value={t.text} onChange={(e) => patchTurn(i, { text: e.target.value })}
                  style={{ background: 'none', border: 'none', outline: 'none', color: 'var(--text-1)', font: 'inherit', fontSize: 13.5, resize: 'vertical' }} />
              </div>
            ))}
          </div>
          <button className="c-btn-ghost" style={{ width: '100%', marginTop: 10 }} onClick={addTurn}><Icons.Plus size={15} /> Add line</button>
        </div>

        {/* Booking preview fields */}
        <div>
          <div className="c-field-label" style={{ margin: '0 4px 8px' }}>Booking shown at the end</div>
          <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
            {([
              ['customer_name', 'Customer'], ['customer_phone', 'Phone (fake)'],
              ['service', 'Service'], ['price', 'Price'],
              ['date', 'Date'], ['start_time', 'Start'], ['end_time', 'End'], ['staff_name', 'Staff'],
            ] as [keyof SimScript['booking'], string][]).map(([k, label]) => (
              <label key={k} style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--text-4)' }}>
                {label}
                <input value={script.booking[k]} onChange={(e) => patchBooking({ [k]: e.target.value })}
                  style={{ background: 'none', border: '1px solid var(--line, #333)', borderRadius: 8, color: 'var(--text-1)', padding: '6px 8px', font: 'inherit' }} />
              </label>
            ))}
          </div>
        </div>

        {/* Voices + pacing */}
        <div style={{ display: 'grid', gridTemplateColumns: '1fr 1fr', gap: 8 }}>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--text-4)' }}>
            Your voice
            <select value={script.voices.owner} onChange={(e) => patch({ voices: { ...script.voices, owner: e.target.value } })}
              style={{ background: 'none', color: 'var(--text-1)', border: '1px solid var(--line, #333)', borderRadius: 8, padding: '6px 8px', font: 'inherit' }}>
              {VOICES.map((v) => <option key={v} value={v}>{v}</option>)}
            </select>
          </label>
          <label style={{ display: 'flex', flexDirection: 'column', gap: 4, fontSize: 12, color: 'var(--text-4)' }}>
            Assistant voice
            <select value={script.voices.assistant} onChange={(e) => patch({ voices: { ...script.voices, assistant: e.target.value } })}
              style={{ background: 'none', color: 'var(--text-1)', border: '1px solid var(--line, #333)', borderRadius: 8, padding: '6px 8px', font: 'inherit' }}>
              {VOICES.map((v) => <option key={v} value={v}>{v}</option>)}
            </select>
          </label>
        </div>

        <div style={{ display: 'flex', gap: 10 }}>
          <button className="c-btn" style={{ flex: 1 }} disabled={saving} onClick={() => void save()}>{saving ? 'Saving…' : 'Save'}</button>
          <button className="c-btn-ghost" style={{ flex: 1, display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8 }} onClick={() => navigate('/ask?sim=1')}>
            <Icons.Mic size={16} /> Play
          </button>
        </div>
      </div>
    </div></div>
  );
}
```

Note: if `Icons.Trash` / `Icons.Plus` / `Icons.Mic` names differ, check `admin/src/components/Icons.tsx` and use the actual exports.

- [ ] **Step 4: Add the Settings card** — in `admin/src/pages/Settings.tsx`, add to `ALL_OPTIONS` (after the `Customers` entry):

```tsx
  { label: 'Demo simulation', sub: 'Record a scripted voice booking — no real booking made', to: '/settings/simulation', icon: 'Mic', modules: ['bookings'] },
```

- [ ] **Step 5: Add the route** — in `admin/src/App.tsx`, add near the other `/settings/*` routes (e.g. after line 91 `/settings/access`), and add the import at the top with the other page imports:

```tsx
          <Route path="/settings/simulation" element={<SimulationSettings />} />
```
```tsx
import SimulationSettings from '@/pages/SimulationSettings';
```

- [ ] **Step 6: Run to verify it passes**

Run: `cd admin && npm test -- SimulationSettings Settings`
Expected: PASS (new page tests + existing Settings tests still green).

- [ ] **Step 7: Commit**

```bash
git add admin/src/pages/SimulationSettings.tsx admin/src/pages/SimulationSettings.test.tsx admin/src/pages/Settings.tsx admin/src/App.tsx
git commit -m "feat(simulation): Settings editor page + card + route"
```

---

## Task 6: Frontend — Ask screen sim-mode player

**Files:**
- Modify: `admin/src/pages/VoiceAssistant.tsx`
- Modify: `admin/src/pages/VoiceAssistant.test.tsx`

**Interfaces:**
- Consumes: `getSimulation`, `speak`, `SimScript` (Task 4). Reads `?sim=1` from the URL.
- Produces: when `?sim=1`, the Ask screen shows a "Start" overlay; on tap it plays the script as voice-note bubbles (owner then assistant, with a Thinking bubble before assistant turns) and finally `navigate('/booking/preview', { state: { booking } })`.

- [ ] **Step 1: Add `onEnded` to `AudioBubble`** — in `admin/src/pages/VoiceAssistant.tsx`, change the `AudioBubble` signature and the `<audio onEnded>` handler:

```tsx
function AudioBubble({ src, autoPlay = false, onEnded }: { src: string; autoPlay?: boolean; onEnded?: () => void }) {
```
and in the `<audio>` element:
```tsx
        onEnded={() => { setPlaying(false); setProgress(0); setElapsed(0); onEnded?.(); }}
```

- [ ] **Step 2: Write the failing test** — add to `admin/src/pages/VoiceAssistant.test.tsx`. Mock the simulation lib and assert playback drives TTS and ends by navigating to the preview. (HTMLMediaElement.play is not implemented in jsdom — stub it and fire `ended` manually.)

```tsx
// at top with other imports
import * as sim from '@/lib/simulation';
vi.mock('@/lib/simulation');

it('sim mode plays the script and ends on the booking preview', async () => {
  // jsdom has no real audio — make play() resolve and let us fire `ended`.
  window.HTMLMediaElement.prototype.play = vi.fn().mockResolvedValue(undefined);
  window.HTMLMediaElement.prototype.pause = vi.fn();

  const script = {
    turns: [{ who: 'owner', text: 'Book Sarah.' }, { who: 'assistant', text: 'Done.' }],
    booking: { customer_name: 'Sarah', customer_phone: '055 010 2030', service: 'Hair Cut', price: '150.00', date: '2026-07-09', start_time: '09:30', end_time: '10:00', staff_name: 'Aisha' },
    voices: { owner: 'shimmer', assistant: 'nova' }, thinking_ms: 0,
  };
  (sim.getSimulation as ReturnType<typeof vi.fn>).mockResolvedValue(script);
  (sim.speak as ReturnType<typeof vi.fn>).mockResolvedValue('blob:audio');

  render(
    <MemoryRouter initialEntries={['/ask?sim=1']}>
      <Routes><Route path="/ask" element={<VoiceAssistant />} /></Routes>
    </MemoryRouter>,
  );

  // Tap the Start overlay (user gesture for autoplay).
  fireEvent.click(await screen.findByRole('button', { name: /start/i }));

  // First line requested in the owner voice.
  await waitFor(() => expect(sim.speak).toHaveBeenCalledWith('Book Sarah.', 'shimmer'));
});
```

Note: match the existing test file's imports (`render`, `screen`, `fireEvent`, `waitFor`, `Routes`, `Route`, `MemoryRouter`, the `navigate` mock, `asMock` helper). Reuse its `navigate` mock to assert the final `navigate('/booking/preview', ...)` if you extend the test; the minimal assertion above (TTS called with the owner voice after Start) is enough to prove the player runs.

- [ ] **Step 3: Run to verify it fails**

Run: `cd admin && npm test -- VoiceAssistant`
Expected: FAIL (no Start overlay / no sim playback).

- [ ] **Step 4: Implement sim mode** — in `admin/src/pages/VoiceAssistant.tsx`:

Add imports:
```tsx
import { useNavigate, useParams, useSearchParams, Navigate } from 'react-router-dom';
import { getSimulation, speak, type SimScript } from '@/lib/simulation';
```

Inside the component, after the existing state declarations, add:
```tsx
  const [params] = useSearchParams();
  const simMode = params.get('sim') === '1';
  const [simScript, setSimScript] = useState<SimScript | null>(null);
  const [simStarted, setSimStarted] = useState(false);
  const [simThinking, setSimThinking] = useState(false);
  // Resolves when the currently-playing sim bubble finishes.
  const audioDone = useRef<(() => void) | null>(null);

  // Load the script when entering sim mode.
  useEffect(() => {
    if (!simMode) return;
    let alive = true;
    getSimulation().then((s) => { if (alive) setSimScript(s); }).catch(() => { if (alive) setError('Could not load the simulation.'); });
    return () => { alive = false; };
  }, [simMode]);

  async function runSimulation() {
    if (!simScript) return;
    setSimStarted(true);
    setMessages([]);
    const wait = (ms: number) => new Promise<void>((r) => setTimeout(r, ms));
    for (const turn of simScript.turns) {
      if (turn.who === 'assistant') {
        setSimThinking(true);
        await wait(simScript.thinking_ms);
        setSimThinking(false);
      }
      let url = '';
      try { url = await speak(turn.text, simScript.voices[turn.who]); } catch { /* show text only */ }
      await new Promise<void>((resolve) => {
        audioDone.current = resolve;
        setMessages((m) => [...m, { role: turn.who === 'assistant' ? 'assistant' : 'user', content: turn.text, audioUrl: url || null, autoPlay: !!url }]);
        if (!url) resolve(); // nothing to play — advance after a beat
      });
      await wait(400); // brief gap between voice notes
    }
    navigate('/booking/preview', { state: { booking: simScript.booking } });
  }
```

Extend the `Msg` type to carry an explicit autoplay flag:
```tsx
type Msg = { role: 'user' | 'assistant'; content: string; audioUrl?: string | null; autoPlay?: boolean };
```

In the message render, use the explicit flag when present and wire `onEnded` so the player advances:
```tsx
        {messages.map((m, i) => (
          <div key={i} className={`va-bubble ${m.role === 'user' ? 'va-user' : 'va-ai'}`}>
            {m.audioUrl && (
              <AudioBubble
                src={m.audioUrl}
                autoPlay={m.autoPlay ?? (m.role === 'assistant' && i >= restoredCount.current)}
                onEnded={() => { if (audioDone.current) { const done = audioDone.current; audioDone.current = null; done(); } }}
              />
            )}
            {m.content && <div className="va-text">{m.content}</div>}
          </div>
        ))}
        {(busy || simThinking) && <ThinkingBubble />}
```

Render the Start overlay and hide the normal controls in sim mode. Right after the `va-head` block (or as the first child of the `va-screen` div), add:
```tsx
      {simMode && !simStarted && (
        <div className="va-drawer-backdrop" style={{ zIndex: 20 }}>
          <button className="c-btn" style={{ padding: '14px 28px', fontSize: 16 }} disabled={!simScript} onClick={() => void runSimulation()}>
            ▶ Start simulation
          </button>
        </div>
      )}
```
Wrap the `<div className="va-controls">…</div>` block so it is hidden during sim mode:
```tsx
      {!simMode && (
        <div className="va-controls">
          {/* …existing input + send + mic… */}
        </div>
      )}
```

- [ ] **Step 5: Run to verify it passes**

Run: `cd admin && npm test -- VoiceAssistant`
Expected: PASS (new sim test + all existing VoiceAssistant tests).

- [ ] **Step 6: Commit**

```bash
git add admin/src/pages/VoiceAssistant.tsx admin/src/pages/VoiceAssistant.test.tsx
git commit -m "feat(simulation): Ask-screen sim mode player with start overlay"
```

---

## Task 7: Frontend — Booking preview payoff

**Files:**
- Create: `admin/src/pages/BookingPreview.tsx`
- Create: `admin/src/pages/BookingPreview.test.tsx`
- Modify: `admin/src/App.tsx`

**Interfaces:**
- Consumes: `location.state.booking` (a `SimScript['booking']`) set by Task 6's final `navigate`.
- Produces: route `/booking/preview` renders a read-only booking detail from the passed booking. No API call, no persistence.

- [ ] **Step 1: Write the failing test** — `admin/src/pages/BookingPreview.test.tsx`:

```tsx
import { describe, it, expect } from 'vitest';
import { render, screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import BookingPreview from './BookingPreview';

const booking = { customer_name: 'Sarah', customer_phone: '055 010 2030', service: 'Hair Cut', price: '150.00', date: '2026-07-09', start_time: '09:30', end_time: '10:00', staff_name: 'Aisha' };

describe('BookingPreview', () => {
  it('renders the passed booking without any API call', () => {
    render(<MemoryRouter initialEntries={[{ pathname: '/booking/preview', state: { booking } }]}><BookingPreview /></MemoryRouter>);
    expect(screen.getByText('Sarah')).toBeInTheDocument();
    expect(screen.getByText('Hair Cut')).toBeInTheDocument();
    expect(screen.getByText(/150/)).toBeInTheDocument();
    expect(screen.getByText('Aisha')).toBeInTheDocument();
  });

  it('shows a fallback when opened directly without state', () => {
    render(<MemoryRouter initialEntries={['/booking/preview']}><BookingPreview /></MemoryRouter>);
    expect(screen.getByText(/no simulation/i)).toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run to verify it fails**

Run: `cd admin && npm test -- BookingPreview`
Expected: FAIL (module not found).

- [ ] **Step 3: Implement** — `admin/src/pages/BookingPreview.tsx`. Reuse the same `ba-*` classes as `BookingAction.tsx` so it looks identical, but read-only (no status knob, no fetch):

```tsx
import { useLocation, useNavigate } from 'react-router-dom';
import { Icons } from '@/components/Icons';
import type { SimBooking } from '@/lib/simulation';

// Read-only booking payoff for the demo simulation. Renders from navigation
// state only — it never fetches or persists anything. Mirrors BookingAction's
// hero card so the recording ends on a screen identical to the real product.
export default function BookingPreview() {
  const navigate = useNavigate();
  const b = (useLocation().state as { booking?: SimBooking } | null)?.booking;

  if (!b) {
    return (
      <div className="m-screen"><div className="m-scroll">
        <button className="c-back" onClick={() => navigate('/ask')}><Icons.ChevronLeft size={16} /> Back</button>
        <p className="c-muted-center">No simulation to preview. Start one from Settings → Demo simulation.</p>
      </div></div>
    );
  }

  const name = b.customer_name || 'Guest';
  return (
    <div className="m-screen c-booking-action"><div className="m-scroll">
      <div className="ba-wrap">
        <button className="c-back" onClick={() => navigate('/ask?sim=1')}><Icons.ChevronLeft size={16} /> Back</button>

        <div className="ba-card ba-timeline-card">
          <ol className="ba-timeline">
            {[{ label: 'Queued', state: 'done' }, { label: 'Booked', state: 'current' }, { label: 'Completed', state: 'todo' }].map((step, i) => (
              <li key={step.label} className={`ba-tstep ${step.state}`}>
                <span className="ba-tstep-in">
                  <span className="ba-tnode">{step.state === 'done' ? <Icons.Check size={16} /> : i + 1}</span>
                  <span className="ba-tmeta">
                    <span className="ba-tlabel">{step.label}</span>
                    <span className="ba-tstate">{step.state === 'done' ? 'Done' : step.state === 'current' ? 'Current' : 'Pending'}</span>
                  </span>
                </span>
              </li>
            ))}
          </ol>
        </div>

        <div className="ba-card ba-hero">
          <div className="ba-hero-main">
            <div className="ba-hero-top">
              <div className="ba-avatar">{name.charAt(0).toUpperCase()}</div>
              <div className="ba-hero-id">
                <span className="ba-name">{name}</span>
                <span className="ba-ref">New booking</span>
              </div>
              <span className="c-chip c-chip-booked">Booked</span>
            </div>

            <div className="ba-service">
              <span className="ba-tile-l">Service</span>
              <span className="ba-service-val">{b.service || '—'}</span>
            </div>

            <div className="ba-grid">
              <div className="ba-tile"><Icons.Calendar size={15} /><span className="ba-tile-l">Date</span><span className="ba-tile-v">{b.date || '—'}</span></div>
              <div className="ba-tile"><Icons.Clock size={15} /><span className="ba-tile-l">Time</span><span className="ba-tile-v">{b.start_time || '—'}</span></div>
              <div className="ba-tile"><Icons.User size={15} /><span className="ba-tile-l">Staff</span><span className="ba-tile-v">{b.staff_name || 'Unassigned'}</span></div>
              <div className="ba-tile ba-tile-amount"><Icons.Tag size={15} /><span className="ba-tile-l">Charges</span><span className="ba-tile-v">AED {b.price || 0}</span></div>
            </div>
          </div>
        </div>
      </div>
    </div></div>
  );
}
```

Note: export `SimBooking` from `admin/src/lib/simulation.ts` (Task 4 already defines the type — ensure it is `export type SimBooking`). Confirm `Icons.Calendar/Clock/User/Tag/Check` exist in `admin/src/components/Icons.tsx`.

- [ ] **Step 4: Add the route** — in `admin/src/App.tsx`, next to `/booking/:id` (line 70), add the more specific static route BEFORE the `:id` param route so it matches first, and import it:

```tsx
          <Route path="/booking/preview" element={<BookingPreview />} />
          <Route path="/booking/:id" element={<BookingAction />} />
```
```tsx
import BookingPreview from '@/pages/BookingPreview';
```

- [ ] **Step 5: Run to verify it passes**

Run: `cd admin && npm test -- BookingPreview`
Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add admin/src/pages/BookingPreview.tsx admin/src/pages/BookingPreview.test.tsx admin/src/App.tsx
git commit -m "feat(simulation): read-only booking preview payoff"
```

---

## Task 8: Full-suite verification + deploy to staging

**Files:** none (verification only).

- [ ] **Step 1: Run the whole frontend suite**

Run: `cd admin && npm test`
Expected: all tests PASS (no regressions in Settings, VoiceAssistant, etc.).

- [ ] **Step 2: Run the whole backend suite on the droplet (scratch DB, NEVER prod)**

Run (droplet): `php8.4 artisan test`
Expected: green — new `TtsControllerTest` + `ShopSimulationControllerTest` pass, no regressions.

- [ ] **Step 3: Build the admin SPA**

Run: `cd admin && npm run build`
Expected: build succeeds, no type errors.

- [ ] **Step 4: Deploy to STAGING and smoke-test end to end** (per deploy-flow-local→staging→prod rule — staging first, promote to prod only when great)

- Deploy backend (`eloquent-backend-staging`) + run the migration on the staging DB.
- Deploy the admin SPA to staging.
- Manually: log in on staging → Settings → Demo simulation → Save → Play → confirm both voices play as voice notes, thinking bubble shows, and it ends on the booking preview.
- Confirm the dry-run guarantee: after playing, query the staging DB and verify NO new `Booking` / `ShopCustomer` row was created:
  `php8.4 artisan tinker --execute='echo App\Models\Booking::max("id");'` before and after — the max id must not change.

- [ ] **Step 5: Commit any staging fixes, then push `main`**

```bash
git push origin main
```

---

## Self-Review

**Spec coverage:**
- Settings card + editor → Task 5. ✅
- Editable messages/booking/voices/pacing, saved per shop → Tasks 3 (storage) + 5 (editor). ✅
- Dry-run, no booking stored → guaranteed by design (no booking endpoint called); explicitly verified in Task 8 Step 4. ✅
- Voice notes both sides, two female voices → Tasks 1 (voice param) + 6 (player). ✅
- Plays on real Ask screen → Task 6 (`?sim=1`). ✅
- Ends by opening the booking page (preview, not persisted) → Task 7. ✅
- Default generated from shop's real services/staff, no hardcoded identity → Task 3 `defaultScript`. ✅
- Deep link `/ask?sim=1` → Task 6 (Play navigates there; bookmarkable). ✅

**Placeholder scan:** No TBD/TODO; every code step shows complete code. Repo-specific unknowns (auth guard name, relationship names, icon export names) are flagged inline with exact where-to-check instructions — not left vague.

**Type consistency:** `SimScript`/`SimTurn`/`SimBooking` defined in Task 4 and consumed by name in Tasks 5–7. `speak(text, voice)`, `getSimulation()`, `saveSimulation()` signatures identical across tasks. `Msg` gains `autoPlay?: boolean` in Task 6 and is used consistently. Backend `script` JSON shape identical between controller default (Task 3) and frontend types (Task 4).
