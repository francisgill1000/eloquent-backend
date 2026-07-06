# Lead WhatsApp Drafts & Follow-up — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the lead detail page open WhatsApp pre-drafted with a personalized opening message (advancing the lead to Sent), then show a repeatable Follow-up button that drafts a follow-up message — with both message templates editable per shop.

**Architecture:** Two per-shop template columns on `shops` back two new server-rendered `wa.me?text=` URL accessors on `Lead` (only serialized on the detail endpoint to avoid N+1 on the list). The lead detail page swaps a stage-aware outreach button: New → WhatsApp (opening, marks Sent); Sent/Replied/Demo → Follow-up (logs a `contacted` activity); Won/Not-Interested → none. Templates are edited on a new Lead Messages page reached from the Settings hub, mirroring the existing Assistant/persona page.

**Tech Stack:** Laravel 11 (PHP 8.4 on droplet), React + TypeScript (Vite), Vitest + Testing Library, Axios.

## Global Constraints

- Backend/PHP tests run **on the droplet only**: `ssh root@64.227.153.90 'cd /var/www/eloquent-backend && php8.4 artisan test --filter=<Name>'`. Local PHP is broken. Sync code to the droplet (git push → pull, or the deploy flow) before running. Frontend type-check runs locally: `cd admin && npx tsc --noEmit`.
- All lead/shop endpoints are tenant-scoped to the authenticated `Shop`; never read `shop_id` from the request. Cross-shop access returns 404 (leads) / 403 (shop auth).
- Message placeholder is exactly `{name}` → the lead's business name. Unknown placeholders are left verbatim. Rendered server-side and URL-encoded onto `wa.me/{digits}?text=`.
- The funnel is fixed: `new → sent → replied → demo → won`, plus `pass` (Not Interested). Do not add statuses.
- WhatsApp URLs are only valid for UAE mobiles (`is_mobile`); return `null` otherwise, same guard as `whatsapp_url`.
- Deploy at the end via `admin/deploy.ps1` (frontend) and the backend deploy flow; the migration runs on deploy.

---

### Task 1: Per-shop templates + Lead draft-URL accessors

Adds the two template columns, default constants, the `{name}` renderer, and two `wa.me?text=` accessors that the detail endpoint appends (kept out of `$appends` so the leads list doesn't trigger N+1 on `shop`).

**Files:**
- Create: `database/migrations/2026_07_06_000001_add_lead_outreach_templates_to_shops.php`
- Modify: `app/Models/Lead.php` (add constants + two accessors + `{name}` renderer)
- Modify: `app/Http/Controllers/LeadController.php:215-228` (`show()` — eager-load shop, append the two URLs)
- Test: `tests/Feature/LeadWhatsAppDraftTest.php`

**Interfaces:**
- Produces:
  - `Lead::DEFAULT_OPENING` (string), `Lead::DEFAULT_FOLLOWUP` (string)
  - `Lead::getWhatsappOpeningUrlAttribute(): ?string` → `whatsapp_opening_url`
  - `Lead::getWhatsappFollowupUrlAttribute(): ?string` → `whatsapp_followup_url`
  - `shops.lead_opening_template` (text, nullable), `shops.lead_followup_template` (text, nullable)
  - `GET /shop/leads/{lead}` response `data` now includes `whatsapp_opening_url` and `whatsapp_followup_url`.

- [ ] **Step 1: Write the migration**

Create `database/migrations/2026_07_06_000001_add_lead_outreach_templates_to_shops.php`:

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
            $table->text('lead_opening_template')->nullable()->after('persona');
            $table->text('lead_followup_template')->nullable()->after('lead_opening_template');
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['lead_opening_template', 'lead_followup_template']);
        });
    }
};
```

> If `persona` is not a column on `shops`, drop the `->after(...)` clauses (they are cosmetic).

- [ ] **Step 2: Write the failing test**

Create `tests/Feature/LeadWhatsAppDraftTest.php`. Auth mirrors `LeadFinderTest`: a **master shop** (bypasses the `subscription.active` middleware) plus a bearer token tagged to a `ShopUser` (satisfies `rbac.context` / `current_shop_user()`).

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadWhatsAppDraftTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} [shop, plainTextToken] */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function test_detail_returns_opening_and_followup_draft_urls_with_default_templates(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Pak Cargo', 'phone' => '0501112233',
            'status' => 'new', 'source' => 'google',
        ]);

        $res = $this->auth($token)->getJson("/api/shop/leads/{$lead->id}")->assertOk();

        $opening = $res->json('data.whatsapp_opening_url');
        $followup = $res->json('data.whatsapp_followup_url');

        $this->assertStringStartsWith('https://wa.me/971501112233?text=', $opening);
        $this->assertStringStartsWith('https://wa.me/971501112233?text=', $followup);
        // {name} is substituted and URL-encoded (space -> %20).
        $this->assertStringContainsString('Pak%20Cargo', $opening);
        $this->assertStringContainsString('Pak%20Cargo', $followup);
    }

    public function test_custom_templates_override_defaults_and_render_name(): void
    {
        [$shop, $token] = $this->actingShop();
        $shop->update([
            'lead_opening_template' => 'Hello {name}, opening!',
            'lead_followup_template' => 'Hi {name}, following up!',
        ]);
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'phone' => '0509998877',
            'status' => 'new', 'source' => 'google',
        ]);

        $res = $this->auth($token)->getJson("/api/shop/leads/{$lead->id}")->assertOk();

        $this->assertStringContainsString('Hello%20Acme%2C%20opening%21', $res->json('data.whatsapp_opening_url'));
        $this->assertStringContainsString('Hi%20Acme%2C%20following%20up%21', $res->json('data.whatsapp_followup_url'));
    }

    public function test_draft_urls_are_null_for_non_mobile_numbers(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Landline Co', 'phone' => '04 1234567',
            'status' => 'new', 'source' => 'google',
        ]);

        $res = $this->auth($token)->getJson("/api/shop/leads/{$lead->id}")->assertOk();

        $this->assertNull($res->json('data.whatsapp_opening_url'));
        $this->assertNull($res->json('data.whatsapp_followup_url'));
    }
}
```

- [ ] **Step 3: Add default constants + accessors to `Lead`**

In `app/Models/Lead.php`, add constants near `STATUSES` (line 16):

```php
    /** Editable per shop (shops.lead_opening_template); this is the fallback. */
    public const DEFAULT_OPENING = 'Hi {name}, this is Eloquent — we help UAE businesses take bookings and reply to customers on WhatsApp automatically. Could I share a quick demo?';

    /** Editable per shop (shops.lead_followup_template); this is the fallback. */
    public const DEFAULT_FOLLOWUP = 'Hi {name}, just following up on my earlier message — happy to send a short demo whenever suits you.';
```

Add the renderer + accessors after `getWhatsappUrlAttribute()` (after line 106):

```php
    /** wa.me link pre-filled with the shop's opening template ({name} → business name). */
    public function getWhatsappOpeningUrlAttribute(): ?string
    {
        return $this->draftUrl($this->shop?->lead_opening_template ?: self::DEFAULT_OPENING);
    }

    /** wa.me link pre-filled with the shop's follow-up template ({name} → business name). */
    public function getWhatsappFollowupUrlAttribute(): ?string
    {
        return $this->draftUrl($this->shop?->lead_followup_template ?: self::DEFAULT_FOLLOWUP);
    }

    /** Build wa.me/{digits}?text=... from a template, or null when there's no mobile. */
    private function draftUrl(string $template): ?string
    {
        $d = $this->normalizedDigits();
        if (! $d || ! $this->is_mobile) {
            return null;
        }
        $text = str_replace('{name}', (string) $this->name, $template);
        return "https://wa.me/{$d}?text=" . rawurlencode($text);
    }
```

> Do **not** add these to `$appends` (line 43) — that would query `shop` for every row on the leads list. They are appended per-request in `show()`.

- [ ] **Step 4: Append the URLs in `show()`**

In `app/Http/Controllers/LeadController.php`, `show()` (lines 215-228), load the shop relation and append the two accessors before returning:

```php
    public function show(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        abort_unless($lead->shop_id === $shop->id, 404);

        $lead->setRelation('shop', $shop);
        $lead->append(['whatsapp_opening_url', 'whatsapp_followup_url']);

        $activities = $lead->activities()
            ->orderByDesc('id')
            ->get(['id', 'type', 'payload', 'created_at']);

        return response()->json([
            'data' => $lead,
            'activities' => $activities,
        ]);
    }
```

- [ ] **Step 5: Sync to droplet, run the migration, run the test**

```bash
# after pushing and pulling on the droplet (or via the backend deploy flow):
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && php8.4 artisan migrate --force && php8.4 artisan test --filter=LeadWhatsAppDraftTest'
```
Expected: 3 passing tests.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_06_000001_add_lead_outreach_templates_to_shops.php app/Models/Lead.php app/Http/Controllers/LeadController.php tests/Feature/LeadWhatsAppDraftTest.php
git commit -m "feat(leads): server-rendered WhatsApp opening/follow-up draft URLs"
```

---

### Task 2: Follow-up logging endpoint

A follow-up doesn't change status — it records a `contacted` activity and bumps `last_contacted_at`, so the timeline shows nudges.

**Files:**
- Modify: `app/Http/Controllers/LeadController.php` (add `logFollowup()` after `updateStatus`, ~line 261)
- Modify: `routes/api.php:180` (add the route inside the existing lead group)
- Test: `tests/Feature/LeadFollowupTest.php`

**Interfaces:**
- Consumes: `LeadActivity::TYPE_CONTACTED` (existing).
- Produces: `POST /shop/leads/{lead}/followup` → `{ data: Lead }`; logs a `contacted` activity and bumps `last_contacted_at`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadFollowupTest.php` (same master-shop + tagged-token auth as Task 1):

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadFollowupTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} [shop, plainTextToken] */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function test_followup_logs_contacted_activity_and_bumps_last_contacted(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'phone' => '0501112233',
            'status' => 'sent', 'source' => 'google', 'last_contacted_at' => now()->subDays(3),
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/followup")
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'contacted',
        ]);
        $this->assertTrue($lead->fresh()->last_contacted_at->isToday());
    }

    public function test_followup_is_tenant_scoped(): void
    {
        [$mine, $token] = $this->actingShop();
        $other = Shop::factory()->create(['is_master' => true]);
        $lead = Lead::create([
            'shop_id' => $other->id, 'name' => 'Not Mine', 'phone' => '0501112233',
            'status' => 'sent', 'source' => 'google',
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/followup")->assertNotFound();
    }
}
```

- [ ] **Step 2: Add `logFollowup()` to the controller**

In `app/Http/Controllers/LeadController.php`, add after `updateStatus()` (after line 261):

```php
    /**
     * POST /shop/leads/{lead}/followup
     * Record a follow-up nudge: logs a `contacted` activity and bumps
     * last_contacted_at. Does not change the funnel status.
     */
    public function logFollowup(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        abort_unless($lead->shop_id === $shop->id, 404);

        $lead->last_contacted_at = now();
        $lead->save();

        $lead->activities()->create([
            'type' => LeadActivity::TYPE_CONTACTED,
            'payload' => ['channel' => 'whatsapp', 'kind' => 'followup'],
            'user_id' => current_shop_user()?->id,
        ]);

        return response()->json(['data' => $lead->fresh()]);
    }
```

- [ ] **Step 3: Register the route**

In `routes/api.php`, add inside the lead group after line 180:

```php
    Route::post  ('/shop/leads/{lead}/followup',  [\App\Http\Controllers\LeadController::class, 'logFollowup']);
```

- [ ] **Step 4: Sync to droplet, run the test**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && php8.4 artisan test --filter=LeadFollowupTest'
```
Expected: 2 passing tests.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/LeadController.php routes/api.php tests/Feature/LeadFollowupTest.php
git commit -m "feat(leads): follow-up endpoint logs a contacted activity"
```

---

### Task 3: Lead message templates read/write endpoint

Backs the editor page. Mirrors the persona controller: GET returns saved templates + the effective defaults; PUT saves (blank → null → falls back to default).

**Files:**
- Create: `app/Http/Controllers/LeadMessageController.php`
- Modify: `routes/api.php` (add two routes near the persona routes)
- Test: `tests/Feature/LeadMessagesTest.php`

**Interfaces:**
- Consumes: `Lead::DEFAULT_OPENING`, `Lead::DEFAULT_FOLLOWUP` (Task 1).
- Produces:
  - `GET /shop/lead-messages` → `{ opening: string|null, followup: string|null, default_opening: string, default_followup: string }`
  - `PUT /shop/lead-messages` body `{ opening?: string|null, followup?: string|null }` → same shape.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadMessagesTest.php` (same master-shop + tagged-token auth as Task 1):

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadMessagesTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} [shop, plainTextToken] */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    public function test_get_returns_nulls_and_defaults_when_unset(): void
    {
        [, $token] = $this->actingShop();

        $this->auth($token)->getJson('/api/shop/lead-messages')
            ->assertOk()
            ->assertJson([
                'opening' => null,
                'followup' => null,
                'default_opening' => Lead::DEFAULT_OPENING,
                'default_followup' => Lead::DEFAULT_FOLLOWUP,
            ]);
    }

    public function test_put_saves_templates_and_blank_clears_to_null(): void
    {
        [$shop, $token] = $this->actingShop();

        $this->auth($token)->putJson('/api/shop/lead-messages', [
            'opening' => 'Hi {name}!', 'followup' => 'Nudge {name}',
        ])->assertOk()->assertJsonPath('opening', 'Hi {name}!');

        $this->assertSame('Hi {name}!', $shop->fresh()->lead_opening_template);

        // Blank string clears back to null (default takes over).
        $this->auth($token)->putJson('/api/shop/lead-messages', ['opening' => '  '])
            ->assertOk()->assertJsonPath('opening', null);

        $this->assertNull($shop->fresh()->lead_opening_template);
    }
}
```

- [ ] **Step 2: Create the controller**

Create `app/Http/Controllers/LeadMessageController.php`:

```php
<?php

namespace App\Http\Controllers;

use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Http\Request;

/**
 * The shop owner's editable WhatsApp outreach templates for leads. Blank saves
 * fall back to the packaged defaults (Lead::DEFAULT_OPENING / DEFAULT_FOLLOWUP).
 * `{name}` in a template is replaced with the lead's business name at send time.
 */
class LeadMessageController extends Controller
{
    private function shop(Request $request): Shop
    {
        $shop = $request->user();
        abort_unless($shop instanceof Shop, 401, 'Shop authentication required.');
        return $shop;
    }

    public function show(Request $request)
    {
        return response()->json($this->payload($this->shop($request)));
    }

    public function update(Request $request)
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'opening' => ['nullable', 'string', 'max:2000'],
            'followup' => ['nullable', 'string', 'max:2000'],
        ]);

        $updates = [];
        if ($request->has('opening')) {
            $v = trim((string) ($data['opening'] ?? ''));
            $updates['lead_opening_template'] = $v !== '' ? $v : null;
        }
        if ($request->has('followup')) {
            $v = trim((string) ($data['followup'] ?? ''));
            $updates['lead_followup_template'] = $v !== '' ? $v : null;
        }
        if ($updates) {
            $shop->update($updates);
        }

        return response()->json($this->payload($shop->fresh()));
    }

    private function payload(Shop $shop): array
    {
        return [
            'opening' => $shop->lead_opening_template,
            'followup' => $shop->lead_followup_template,
            'default_opening' => Lead::DEFAULT_OPENING,
            'default_followup' => Lead::DEFAULT_FOLLOWUP,
        ];
    }
}
```

- [ ] **Step 3: Register the routes**

In `routes/api.php`, add inside the same `auth:sanctum` lead group (after the followup route from Task 2, before the group's closing `});` at line 181):

```php
    Route::get   ('/shop/lead-messages',          [\App\Http\Controllers\LeadMessageController::class, 'show']);
    Route::put   ('/shop/lead-messages',          [\App\Http\Controllers\LeadMessageController::class, 'update']);
```

- [ ] **Step 4: Sync to droplet, run the test**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && php8.4 artisan test --filter=LeadMessagesTest'
```
Expected: 2 passing tests.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/LeadMessageController.php routes/api.php tests/Feature/LeadMessagesTest.php
git commit -m "feat(leads): read/write endpoint for lead outreach templates"
```

---

### Task 4: Lead Messages editor page

Frontend editor for the two templates, mirroring `Assistant.tsx`. Reached from the Settings hub.

**Files:**
- Create: `admin/src/lib/leadMessages.ts`
- Create: `admin/src/pages/LeadMessages.tsx`
- Modify: `admin/src/App.tsx:38-72` (import + route `/leads/messages`)
- Modify: `admin/src/pages/Settings.tsx:14-23` (hub link)
- Test: `admin/src/pages/LeadMessages.test.tsx`

**Interfaces:**
- Consumes: `GET/PUT /shop/lead-messages` (Task 3).
- Produces: `getLeadMessages()`, `saveLeadMessages(opening, followup)`; type `LeadMessages`.

- [ ] **Step 1: Write the lib**

Create `admin/src/lib/leadMessages.ts`:

```ts
import api from './api';

export type LeadMessages = {
  opening: string | null;
  followup: string | null;
  default_opening: string;
  default_followup: string;
};

/** The shop's editable WhatsApp outreach templates (opening + follow-up). */
export async function getLeadMessages(): Promise<LeadMessages> {
  const { data } = await api.get('/shop/lead-messages');
  return data;
}

/** Save both templates; pass empty/null to fall back to the packaged default. */
export async function saveLeadMessages(opening: string | null, followup: string | null): Promise<LeadMessages> {
  const { data } = await api.put('/shop/lead-messages', { opening, followup });
  return data;
}
```

- [ ] **Step 2: Write the failing component test**

Create `admin/src/pages/LeadMessages.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import * as lib from '@/lib/leadMessages';
import LeadMessages from './LeadMessages';

function setup() {
  return render(<MemoryRouter><LeadMessages /></MemoryRouter>);
}

describe('LeadMessages', () => {
  beforeEach(() => { vi.restoreAllMocks(); });

  it('loads templates and saves edits', async () => {
    vi.spyOn(lib, 'getLeadMessages').mockResolvedValue({
      opening: null, followup: null,
      default_opening: 'Hi {name}, opening default', default_followup: 'Hi {name}, followup default',
    });
    const save = vi.spyOn(lib, 'saveLeadMessages').mockResolvedValue({
      opening: 'New opening {name}', followup: 'Hi {name}, followup default',
      default_opening: 'Hi {name}, opening default', default_followup: 'Hi {name}, followup default',
    });

    setup();
    // Falls back to defaults in the fields when nothing is saved.
    const opening = await screen.findByLabelText('Opening message');
    expect((opening as HTMLTextAreaElement).value).toBe('Hi {name}, opening default');

    await userEvent.clear(opening);
    await userEvent.type(opening, 'New opening {name}');
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    expect(save).toHaveBeenCalledWith('New opening {name}', 'Hi {name}, followup default');
  });
});
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/LeadMessages.test.tsx`
Expected: FAIL — cannot resolve `./LeadMessages`.

- [ ] **Step 4: Build the page**

Create `admin/src/pages/LeadMessages.tsx`:

```tsx
import { useEffect, useState } from 'react';
import { useNavigate } from 'react-router-dom';
import { Spinner } from '@/components/Spinner';
import { Icons } from '@/components/Icons';
import { getLeadMessages, saveLeadMessages } from '@/lib/leadMessages';

/**
 * Editable WhatsApp outreach templates for leads. `{name}` is replaced with the
 * lead's business name when the draft opens. Blank fields fall back to the
 * packaged defaults.
 */
export default function LeadMessages() {
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState('');
  const [notice, setNotice] = useState('');
  const [opening, setOpening] = useState('');
  const [followup, setFollowup] = useState('');

  useEffect(() => {
    let alive = true;
    getLeadMessages()
      .then((m) => {
        if (!alive) return;
        setOpening(m.opening ?? m.default_opening);
        setFollowup(m.followup ?? m.default_followup);
      })
      .catch(() => { if (alive) setError('Could not load your messages.'); })
      .finally(() => { if (alive) setLoading(false); });
    return () => { alive = false; };
  }, []);

  const save = () => {
    setSaving(true); setError(''); setNotice('');
    saveLeadMessages(opening, followup)
      .then((m) => {
        setOpening(m.opening ?? m.default_opening);
        setFollowup(m.followup ?? m.default_followup);
        setNotice('Saved — new leads will use these messages.');
      })
      .catch(() => setError('Could not save. Please try again.'))
      .finally(() => setSaving(false));
  };

  return (
    <div className="m-screen"><div className="m-scroll">
      <div className="c-page-head" style={{ display: 'flex', alignItems: 'flex-start', gap: 12 }}>
        <div style={{ flex: 1, minWidth: 0 }}>
          <h1 className="c-page-title">Lead messages</h1>
          <p className="c-page-sub">
            The WhatsApp messages drafted when you contact a lead. Use <code>{'{name}'}</code> to
            insert the business name.
          </p>
        </div>
        <button className="c-icon-btn" aria-label="Back to settings" onClick={() => navigate('/settings')}>
          <Icons.ChevronLeft size={18} />
        </button>
      </div>

      {error && <div className="c-error-box">{error}</div>}
      {notice && (
        <div style={{ margin: '0 0 12px', padding: 12, borderRadius: 'var(--r-md)', background: 'var(--mint-soft)', border: '1px solid var(--border-mint)', color: 'var(--mint-300)', fontSize: 13, textAlign: 'center' }}>
          {notice}
        </div>
      )}

      {loading ? <Spinner label="Loading messages…" /> : (
        <div style={{ display: 'flex', flexDirection: 'column', gap: 16 }}>
          <div>
            <div className="c-field-label" style={{ margin: '0 4px 8px' }}>Opening message</div>
            <div className="c-input-row c-input-area">
              <textarea
                aria-label="Opening message"
                value={opening}
                onChange={(e) => { setOpening(e.target.value); setNotice(''); }}
                rows={4}
                style={{ width: '100%', background: 'none', border: 'none', outline: 'none', color: 'var(--text-1)', font: 'inherit', fontSize: 13.5, lineHeight: 1.5, resize: 'vertical' }}
              />
            </div>
          </div>

          <div>
            <div className="c-field-label" style={{ margin: '0 4px 8px' }}>Follow-up message</div>
            <div className="c-input-row c-input-area">
              <textarea
                aria-label="Follow-up message"
                value={followup}
                onChange={(e) => { setFollowup(e.target.value); setNotice(''); }}
                rows={4}
                style={{ width: '100%', background: 'none', border: 'none', outline: 'none', color: 'var(--text-1)', font: 'inherit', fontSize: 13.5, lineHeight: 1.5, resize: 'vertical' }}
              />
            </div>
          </div>

          <button className="c-btn c-btn-block" disabled={saving || !opening.trim() || !followup.trim()} onClick={() => save()}>
            {saving ? 'Saving…' : 'Save messages'}
          </button>
        </div>
      )}
    </div></div>
  );
}
```

- [ ] **Step 5: Register the route**

In `admin/src/App.tsx`, add the import alongside the other page imports (near line 39):

```tsx
import LeadMessages from '@/pages/LeadMessages';
```

Add the route next to the leads routes (after line 68 `<Route path="/leads/:id" ... />`):

```tsx
          <Route path="/leads/messages" element={<LeadMessages />} />
```

> Place `/leads/messages` BEFORE `/leads/:id` so `:id` doesn't capture `messages`. Move the new line above the `:id` route.

- [ ] **Step 6: Add the Settings hub link**

In `admin/src/pages/Settings.tsx`, add to `ALL_OPTIONS` (after the Business Hunt entry, line 15):

```tsx
  { label: 'Lead messages', sub: 'WhatsApp opening & follow-up templates', to: '/leads/messages', icon: 'WhatsApp' },
```

- [ ] **Step 7: Run test + type-check**

Run: `cd admin && npx vitest run src/pages/LeadMessages.test.tsx && npx tsc --noEmit`
Expected: test PASS, no type errors.

- [ ] **Step 8: Commit**

```bash
git add admin/src/lib/leadMessages.ts admin/src/pages/LeadMessages.tsx admin/src/pages/LeadMessages.test.tsx admin/src/App.tsx admin/src/pages/Settings.tsx
git commit -m "feat(leads): editable WhatsApp outreach templates page"
```

---

### Task 5: Stage-aware WhatsApp / Follow-up buttons on the lead detail page

Swap the single WhatsApp action for the stage-aware outreach control.

**Files:**
- Modify: `admin/src/types.ts:243-247` (add two optional fields to `Lead`)
- Modify: `admin/src/lib/leads.ts` (add `logFollowup`)
- Modify: `admin/src/pages/LeadDetail.tsx:145,195-200` (outreach button logic)
- Test: `admin/src/pages/LeadDetail.test.tsx`

**Interfaces:**
- Consumes: `whatsapp_opening_url` / `whatsapp_followup_url` on `Lead` (Task 1); `POST /shop/leads/{lead}/followup` (Task 2); `updateLeadStatus` (existing).
- Produces: `logFollowup(id: number): Promise<Lead>`.

- [ ] **Step 1: Extend the `Lead` type**

In `admin/src/types.ts`, add after line 247 (`map_url?: string | null;`), inside the `Lead` type:

```ts
  whatsapp_opening_url?: string | null;
  whatsapp_followup_url?: string | null;
```

- [ ] **Step 2: Add `logFollowup` to the leads lib**

In `admin/src/lib/leads.ts`, add after `updateLeadStatus` (after line 135):

```ts
/** Record a follow-up nudge; logs a `contacted` activity + bumps last_contacted_at. */
export async function logFollowup(id: number): Promise<Lead> {
  const { data } = await api.post(`/shop/leads/${id}/followup`);
  return data?.data ?? data;
}
```

- [ ] **Step 3: Write the failing component test**

Create `admin/src/pages/LeadDetail.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Routes, Route } from 'react-router-dom';
import * as leadsLib from '@/lib/leads';
import LeadDetail from './LeadDetail';

const baseLead = {
  id: 3, name: 'Pak Cargo', phone: '+971 50 111 2233', status: 'new' as const,
  is_mobile: true, tel_url: 'tel:+971501112233',
  whatsapp_url: 'https://wa.me/971501112233',
  whatsapp_opening_url: 'https://wa.me/971501112233?text=Hi%20Pak%20Cargo',
  whatsapp_followup_url: 'https://wa.me/971501112233?text=Follow%20up%20Pak%20Cargo',
};

function setup() {
  return render(
    <MemoryRouter initialEntries={['/leads/3']}>
      <Routes><Route path="/leads/:id" element={<LeadDetail />} /></Routes>
    </MemoryRouter>,
  );
}

describe('LeadDetail outreach button', () => {
  beforeEach(() => { vi.restoreAllMocks(); vi.stubGlobal('open', vi.fn()); });

  it('shows WhatsApp (opening) for a New lead and marks it Sent on click', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'sent' });

    setup();
    const btn = await screen.findByRole('button', { name: /whatsapp/i });
    await userEvent.click(btn);

    expect(window.open).toHaveBeenCalledWith(baseLead.whatsapp_opening_url, '_blank');
    expect(setStatus).toHaveBeenCalledWith(3, 'sent');
    expect(screen.queryByRole('button', { name: /follow-up/i })).not.toBeInTheDocument();
  });

  it('shows Follow-up for a Sent lead and logs a follow-up on click', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'sent' }, activities: [] });
    const follow = vi.spyOn(leadsLib, 'logFollowup').mockResolvedValue({ ...baseLead, status: 'sent' });

    setup();
    const btn = await screen.findByRole('button', { name: /follow-up/i });
    await userEvent.click(btn);

    expect(window.open).toHaveBeenCalledWith(baseLead.whatsapp_followup_url, '_blank');
    expect(follow).toHaveBeenCalledWith(3);
  });

  it('shows no outreach button for a Won lead', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead, status: 'won' }, activities: [] });

    setup();
    await screen.findByText('Pak Cargo');
    expect(screen.queryByRole('button', { name: /whatsapp/i })).not.toBeInTheDocument();
    expect(screen.queryByRole('button', { name: /follow-up/i })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 4: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/LeadDetail.test.tsx`
Expected: FAIL — the WhatsApp control is an `<a>` link, `logFollowup` not wired, opening doesn't call `updateLeadStatus`.

- [ ] **Step 5: Import `logFollowup` and add the handlers**

In `admin/src/pages/LeadDetail.tsx`, update the import on line 5:

```tsx
import { getLead, updateLeadStatus, logFollowup } from '@/lib/leads';
```

Add handlers inside the component, after `setStatus` (after line 132):

```tsx
  // New lead → open the opening draft, then optimistically move to Sent.
  const sendOpening = async () => {
    if (!lead || busy) return;
    if (lead.whatsapp_opening_url) window.open(lead.whatsapp_opening_url, '_blank');
    setBusy(true); setError('');
    try {
      await updateLeadStatus(lead.id, 'sent');
      await load();
    } catch {
      setError('Could not update status.');
    } finally {
      setBusy(false);
    }
  };

  // Already contacted → open the follow-up draft and log the nudge.
  const sendFollowup = async () => {
    if (!lead || busy) return;
    if (lead.whatsapp_followup_url) window.open(lead.whatsapp_followup_url, '_blank');
    setBusy(true); setError('');
    try {
      await logFollowup(lead.id);
      await load();
    } catch {
      setError('Could not log the follow-up.');
    } finally {
      setBusy(false);
    }
  };
```

- [ ] **Step 6: Render the stage-aware button**

In `admin/src/pages/LeadDetail.tsx`, replace the WhatsApp anchor in `ld-actions` (line 196):

```tsx
              {wa && <a className="ld-act wa" href={wa} target="_blank" rel="noreferrer"><Icons.WhatsApp size={16} /> WhatsApp</a>}
```

with:

```tsx
              {lead.is_mobile && lead.status === 'new' && (
                <button type="button" className="ld-act wa" disabled={busy} onClick={() => void sendOpening()}>
                  <Icons.WhatsApp size={16} /> WhatsApp
                </button>
              )}
              {lead.is_mobile && (lead.status === 'sent' || lead.status === 'replied' || lead.status === 'demo') && (
                <button type="button" className="ld-act wa" disabled={busy} onClick={() => void sendFollowup()}>
                  <Icons.WhatsApp size={16} /> Follow-up
                </button>
              )}
```

> The old `const wa = ...` on line 145 is now unused for this button. Remove line 145 (`const wa = lead.whatsapp_url && lead.is_mobile ? lead.whatsapp_url : null;`) to avoid an unused-var type error. Confirm `wa` is referenced nowhere else first (`grep -n "wa" admin/src/pages/LeadDetail.tsx`); it is used only on line 196.

- [ ] **Step 7: Run test + type-check**

Run: `cd admin && npx vitest run src/pages/LeadDetail.test.tsx && npx tsc --noEmit`
Expected: 3 tests PASS, no type errors.

- [ ] **Step 8: Commit**

```bash
git add admin/src/types.ts admin/src/lib/leads.ts admin/src/pages/LeadDetail.tsx admin/src/pages/LeadDetail.test.tsx
git commit -m "feat(leads): stage-aware WhatsApp opening / follow-up buttons on lead detail"
```

---

### Task 6: Deploy & verify end-to-end

**Files:** none (deploy + manual verify).

- [ ] **Step 1: Run the full frontend type-check + tests**

Run: `cd admin && npx tsc --noEmit && npx vitest run`
Expected: clean.

- [ ] **Step 2: Deploy backend, run migration, run the new suites on the droplet**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && git pull && php8.4 artisan migrate --force && php8.4 artisan test --filter="LeadWhatsAppDraftTest|LeadFollowupTest|LeadMessagesTest"'
```
Expected: all green.

- [ ] **Step 3: Deploy the frontend**

Run `admin/deploy.ps1` (per the admin-deploy-script memory).

- [ ] **Step 4: Manual smoke test on admin.eloquentservice.com**

Verify:
1. New lead detail → **WhatsApp** button → WhatsApp opens pre-filled with the opening message and the business name; the lead becomes **Sent** and the activity log shows the move.
2. Same lead now shows **Follow-up** → opens WhatsApp pre-filled with the follow-up message; activity log gains a follow-up entry; status stays Sent.
3. Won / Not-Interested lead → no outreach button.
4. Settings → **Lead messages** → edit both templates, save, reopen → persisted; a lead's drafts reflect the edits.
