# Lead Contact Channels Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Record which channel every lead touch happened on (and in which direction), store per-lead social handles so any channel is one tap away, and report which channel actually produces wins.

**Architecture:** `channel` and `direction` become real columns on `lead_activities` — not JSON payload keys — because `ReportsAggregator` deliberately avoids JSON SQL for sqlite/pgsql portability, and channel reporting through `payload` would inherit that in-PHP aggregation with no index and no write-time validation. Five nullable handle columns land on `leads`, normalized through one pure `SocialHandle` class. A single `POST /shop/leads/{lead}/touch` endpoint replaces `/followup` and serves both directions; `Lead::recordTouch()` holds the write rules so the controller and the voice tool cannot drift.

**Tech Stack:** Laravel 11 (PHP 8.4), Postgres in prod / sqlite `:memory:` in tests, React + TypeScript SPA (Vite, Vitest), Spatie permissions.

**Spec:** `docs/superpowers/specs/2026-07-27-lead-contact-channels-design.md`

## Global Constraints

- **Channel vocabulary is fixed and identical everywhere:** `whatsapp`, `instagram`, `facebook`, `tiktok`, `linkedin`, `phone`, `email`, `walk_in`, `other`. Defined once in `LeadActivity::CHANNELS` and mirrored once in `admin/src/types.ts`. Not user-configurable, in the same spirit as `Lead::STATUSES`.
- **Direction vocabulary:** `out` (we contacted them) and `in` (they contacted us). Nothing else.
- **`direction: in` must never touch `last_contacted_at`.** It drives `Lead::scopeStale`; a reply from the lead means the ball is in *our* court, which is exactly when a lead is most at risk of being dropped.
- **Win attribution is the FIRST outbound touch on the lead**, not the last, and not date-bounded. Wins with no outbound touch go to an `unattributed` bucket that is always rendered.
- **Never bake one shop's identity into a default.** Multi-tenant rule; every string here is generic.
- **Static `/shop/leads/*` routes are declared before `{lead}` routes** in `routes/api.php` — order matters (see the comment at `routes/api.php:290`).
- **Backend tests run on the droplet, never locally** (local PHP is broken), against the isolated `/root/testrun` checkout — **never** the production database.
- **No feature branches.** Commit directly to `main`.

### Test harness

The sync-test script for this session already exists and is verified working:

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=SomeTest
```

It tars `app tests database routes` to `root@64.227.153.90:/root/testrun`, runs `php8.4 artisan optimize:clear` (the critical step — a cached config makes phpunit's sqlite `:memory:` lose to the real database), then `php8.4 artisan test`, passing through any `artisan test` argument.

Frontend tests run locally:

```bash
cd admin && npx vitest run
```

---

## File Structure

**Backend — create**

| File | Responsibility |
|---|---|
| `app/Support/SocialHandle.php` | Pure handle/URL normalizer. No I/O, no models — trivially testable. |
| `database/migrations/2026_07_27_000001_add_channel_to_lead_activities.php` | `channel` + `direction` columns, index, backfill. |
| `database/migrations/2026_07_27_000002_add_handles_to_leads_table.php` | Five handle columns on `leads`. |
| `tests/Feature/SocialHandleTest.php` | Normalizer unit tests. |
| `tests/Feature/LeadTouchTest.php` | Touch endpoint + `recordTouch` rules. |
| `tests/Feature/LeadUpdateTest.php` | Scoped edit endpoint, incl. privilege-escalation guard. |
| `tests/Feature/HuntByChannelTest.php` | Channel reporting + attribution. |

**Backend — modify**

| File | Change |
|---|---|
| `app/Models/LeadActivity.php` | `CHANNELS`, `DIRECTIONS` consts; `channel`/`direction` fillable. |
| `app/Models/Lead.php` | Handle fields fillable; `recordTouch()`. |
| `app/Http/Controllers/LeadController.php` | `logTouch()`, `update()`, `reply_channel` on `updateStatus()`, deprecate `logFollowup()`. |
| `app/Services/Leads/LeadImporter.php` | Facebook page URL salvaged out of `website`. |
| `app/Services/Assistant/Modules/HuntTools.php` | `channel`/`direction` args on `log_followup`. |
| `app/Services/Reports/ReportsAggregator.php` | `huntByChannel()`. |
| `app/Http/Controllers/ReportsController.php` | `channels` key in the hunt payload. |
| `routes/api.php` | `/touch` + `PATCH {lead}`; `/followup` marked deprecated. |
| `tests/Feature/LeadFollowupTest.php` | Retargeted at `/touch`. |

**Frontend — create**

| File | Responsibility |
|---|---|
| `admin/src/lib/channels.ts` | Channel labels, colours, and per-lead link resolution — the single source both the timeline and the chart read. |
| `admin/src/components/ContactDetails.tsx` | Handles editor panel. |
| `admin/src/components/ChannelPicker.tsx` | Channel chooser used by "Log a touch", "They replied", and the Replied status move. |
| `admin/src/lib/channels.test.ts` | Link resolution + label tests. |
| `admin/src/components/ContactDetails.test.tsx` | Editor tests. |

**Frontend — modify**

| File | Change |
|---|---|
| `admin/src/types.ts` | `LeadChannel`, `TouchDirection`; handle fields on `Lead`; `channel`/`direction` on `LeadActivity`. |
| `admin/src/lib/leads.ts` | `logTouch()`, `updateLead()`; `replyChannel` on `updateLeadStatus()`. |
| `admin/src/lib/huntInsights.ts` | `HuntChannelRow` + `channels`. |
| `admin/src/pages/LeadDetail.tsx` | Channel action row, reply action, timeline text, editor mount. |
| `admin/src/components/HuntDashboard.tsx` | "Which channel works" card. |
| `admin/src/styles/leads.css` | Channel button + editor styles. |

---

## Task 1: SocialHandle normalizer

**Files:**
- Create: `app/Support/SocialHandle.php`
- Test: `tests/Feature/SocialHandleTest.php`

**Interfaces:**
- Consumes: nothing (first task, no dependencies).
- Produces:
  - `SocialHandle::PLATFORMS` — `['instagram','facebook','tiktok','linkedin','email']`
  - `SocialHandle::normalize(string $platform, string $input): ?string` — canonical URL (or lowercased email), `null` when uninterpretable or cross-platform.
  - `SocialHandle::detectPlatform(string $input): ?string` — platform slug when the string's host belongs to a known platform, else `null`. Used by `LeadImporter` in Task 7.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/SocialHandleTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Support\SocialHandle;
use Tests\TestCase;

class SocialHandleTest extends TestCase
{
    public function test_bare_and_at_prefixed_handles_normalize_to_a_url(): void
    {
        $this->assertSame('https://instagram.com/acmegym', SocialHandle::normalize('instagram', 'acmegym'));
        $this->assertSame('https://instagram.com/acmegym', SocialHandle::normalize('instagram', '@acmegym'));
        $this->assertSame('https://instagram.com/acmegym', SocialHandle::normalize('instagram', '  @acmegym  '));
    }

    public function test_handles_containing_dots_are_not_mistaken_for_domains(): void
    {
        // Instagram handles may contain dots — "acme.gym" is a handle, not a host.
        $this->assertSame('https://instagram.com/acme.gym', SocialHandle::normalize('instagram', 'acme.gym'));
    }

    public function test_urls_in_any_form_normalize_to_the_same_canonical_url(): void
    {
        foreach ([
            'instagram.com/acmegym',
            'www.instagram.com/acmegym',
            'https://instagram.com/acmegym',
            'https://www.instagram.com/acmegym/',
            'https://www.instagram.com/acmegym/?hl=en',
        ] as $input) {
            $this->assertSame('https://instagram.com/acmegym', SocialHandle::normalize('instagram', $input), $input);
        }
    }

    public function test_tiktok_handles_carry_the_at_sign(): void
    {
        $this->assertSame('https://tiktok.com/@acmegym', SocialHandle::normalize('tiktok', 'acmegym'));
        $this->assertSame('https://tiktok.com/@acmegym', SocialHandle::normalize('tiktok', 'https://www.tiktok.com/@acmegym'));
    }

    public function test_facebook_and_linkedin_preserve_their_full_path(): void
    {
        // /company/x and /in/x are different things on LinkedIn; FB pages can be
        // /pages/Name/123. Collapsing to a first segment would break both.
        $this->assertSame(
            'https://linkedin.com/company/acme-gym',
            SocialHandle::normalize('linkedin', 'https://www.linkedin.com/company/acme-gym/')
        );
        $this->assertSame(
            'https://linkedin.com/in/jane-doe',
            SocialHandle::normalize('linkedin', 'https://linkedin.com/in/jane-doe')
        );
        $this->assertSame(
            'https://facebook.com/pages/Acme-Gym/12345',
            SocialHandle::normalize('facebook', 'https://www.facebook.com/pages/Acme-Gym/12345')
        );
        $this->assertSame(
            'https://facebook.com/profile.php?id=12345',
            SocialHandle::normalize('facebook', 'https://www.facebook.com/profile.php?id=12345')
        );
    }

    public function test_a_bare_linkedin_handle_becomes_a_company_url(): void
    {
        $this->assertSame('https://linkedin.com/company/acme-gym', SocialHandle::normalize('linkedin', 'acme-gym'));
    }

    public function test_cross_platform_input_is_rejected(): void
    {
        $this->assertNull(SocialHandle::normalize('linkedin', 'https://instagram.com/acmegym'));
        $this->assertNull(SocialHandle::normalize('instagram', 'https://tiktok.com/@acmegym'));
    }

    public function test_unrelated_urls_and_empty_input_are_rejected(): void
    {
        $this->assertNull(SocialHandle::normalize('instagram', 'https://acmegym.ae/about'));
        $this->assertNull(SocialHandle::normalize('instagram', ''));
        $this->assertNull(SocialHandle::normalize('instagram', '   '));
        $this->assertNull(SocialHandle::normalize('instagram', 'https://instagram.com/'));
        $this->assertNull(SocialHandle::normalize('nonsense', 'acmegym'));
    }

    public function test_email_is_validated_and_lowercased(): void
    {
        $this->assertSame('owner@acmegym.ae', SocialHandle::normalize('email', 'Owner@AcmeGym.ae'));
        $this->assertNull(SocialHandle::normalize('email', 'not-an-email'));
        $this->assertNull(SocialHandle::normalize('email', 'owner@'));
    }

    public function test_detect_platform_identifies_known_hosts_only(): void
    {
        $this->assertSame('facebook', SocialHandle::detectPlatform('https://www.facebook.com/acmegym'));
        $this->assertSame('instagram', SocialHandle::detectPlatform('instagram.com/acmegym'));
        $this->assertNull(SocialHandle::detectPlatform('https://acmegym.ae'));
        $this->assertNull(SocialHandle::detectPlatform('acmegym'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=SocialHandleTest
```

Expected: FAIL — `Class "App\Support\SocialHandle" not found`.

- [ ] **Step 3: Write the implementation**

Create `app/Support/SocialHandle.php`:

```php
<?php

namespace App\Support;

/**
 * Turns whatever someone pastes — @handle, bare handle, or a URL in any of its
 * usual shapes — into one canonical link per platform, so the UI can always
 * link out and two spellings of the same profile never look like two profiles.
 *
 * Pure: no I/O, no models, no config. Cross-platform input is rejected rather
 * than coerced — an Instagram URL in the LinkedIn field is a mistake worth
 * surfacing, not a link worth mislabelling.
 */
class SocialHandle
{
    public const PLATFORMS = ['instagram', 'facebook', 'tiktok', 'linkedin', 'email'];

    /** Hosts that identify a platform. Subdomains (www., m.) are matched too. */
    private const HOSTS = [
        'instagram' => ['instagram.com'],
        'facebook'  => ['facebook.com', 'fb.com'],
        'tiktok'    => ['tiktok.com'],
        'linkedin'  => ['linkedin.com'],
    ];

    /** Platforms whose path carries meaning (/in/x vs /company/x) and is kept whole. */
    private const PATH_PLATFORMS = ['facebook', 'linkedin'];

    public static function normalize(string $platform, string $input): ?string
    {
        $input = trim($input);
        if ($input === '' || ! in_array($platform, self::PLATFORMS, true)) {
            return null;
        }

        if ($platform === 'email') {
            $email = strtolower($input);
            return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null;
        }

        $detected = self::detectPlatform($input);
        if ($detected !== null) {
            // A known platform host: it must be THIS platform's host.
            return $detected === $platform ? self::fromUrl($platform, $input) : null;
        }

        // No recognisable platform host. Anything with a scheme or a slash is
        // some other site's URL, not a handle — reject rather than mangle.
        if (str_contains($input, '/')) {
            return null;
        }

        return self::fromHandle($platform, ltrim($input, '@'));
    }

    /** The platform a string's host belongs to, or null when it isn't one of ours. */
    public static function detectPlatform(string $input): ?string
    {
        $host = strtolower(trim($input));
        $host = (string) preg_replace('~^[a-z][a-z0-9+.-]*://~i', '', $host);
        $host = explode('/', $host)[0];

        foreach (self::HOSTS as $platform => $hosts) {
            foreach ($hosts as $known) {
                if ($host === $known || str_ends_with($host, '.' . $known)) {
                    return $platform;
                }
            }
        }

        return null;
    }

    private static function fromUrl(string $platform, string $url): ?string
    {
        if (! preg_match('~^[a-z][a-z0-9+.-]*://~i', $url)) {
            $url = 'https://' . $url;
        }

        $parts = parse_url($url);
        if ($parts === false) {
            return null;
        }

        $path = trim((string) ($parts['path'] ?? ''), '/');
        if ($path === '') {
            return null;
        }

        if (in_array($platform, self::PATH_PLATFORMS, true)) {
            $query = ($parts['query'] ?? '') !== '' ? '?' . $parts['query'] : '';
            return 'https://' . self::HOSTS[$platform][0] . '/' . $path . $query;
        }

        $handle = ltrim(explode('/', $path)[0], '@');

        return $handle === '' ? null : self::url($platform, $handle);
    }

    private static function fromHandle(string $platform, string $handle): ?string
    {
        if (! preg_match('/^[A-Za-z0-9._-]{1,100}$/', $handle)) {
            return null;
        }

        return self::url($platform, $handle);
    }

    private static function url(string $platform, string $handle): string
    {
        return match ($platform) {
            'instagram' => "https://instagram.com/{$handle}",
            'facebook'  => "https://facebook.com/{$handle}",
            'tiktok'    => "https://tiktok.com/@{$handle}",
            'linkedin'  => "https://linkedin.com/company/{$handle}",
        };
    }
}
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=SocialHandleTest
```

Expected: PASS — 10 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Support/SocialHandle.php tests/Feature/SocialHandleTest.php
git commit -m "feat(hunt): normalize social handles into canonical per-platform URLs"
```

---

## Task 2: Channel + direction columns on lead_activities

**Files:**
- Create: `database/migrations/2026_07_27_000001_add_channel_to_lead_activities.php`
- Modify: `app/Models/LeadActivity.php`
- Test: `tests/Feature/LeadActivityChannelTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `LeadActivity::CHANNELS` — `['whatsapp','instagram','facebook','tiktok','linkedin','phone','email','walk_in','other']`
  - `LeadActivity::DIRECTIONS` — `['out','in']`
  - `LeadActivity::DIRECTION_OUT` = `'out'`, `LeadActivity::DIRECTION_IN` = `'in'`
  - `lead_activities.channel` (nullable string 20), `lead_activities.direction` (nullable string 3)

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadActivityChannelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadActivityChannelTest extends TestCase
{
    use RefreshDatabase;

    public function test_channel_and_direction_persist(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'sent', 'source' => 'google',
        ]);

        $lead->activities()->create([
            'type' => LeadActivity::TYPE_CONTACTED,
            'channel' => 'instagram',
            'direction' => LeadActivity::DIRECTION_OUT,
        ]);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'channel' => 'instagram', 'direction' => 'out',
        ]);
    }

    public function test_non_touch_activities_carry_no_channel(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'sent', 'source' => 'google',
        ]);

        $activity = $lead->activities()->create([
            'type' => LeadActivity::TYPE_STATUS_CHANGE,
            'payload' => ['from' => 'new', 'to' => 'sent'],
        ]);

        $this->assertNull($activity->fresh()->channel);
        $this->assertNull($activity->fresh()->direction);
    }

    public function test_the_channel_vocabulary_is_the_agreed_fixed_list(): void
    {
        $this->assertSame(
            ['whatsapp', 'instagram', 'facebook', 'tiktok', 'linkedin', 'phone', 'email', 'walk_in', 'other'],
            LeadActivity::CHANNELS
        );
        $this->assertSame(['out', 'in'], LeadActivity::DIRECTIONS);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadActivityChannelTest
```

Expected: FAIL — no `channel` column / undefined constant `CHANNELS`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_27_000001_add_channel_to_lead_activities.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lead_activities', function (Blueprint $table) {
            // Nullable rather than defaulted: a status change has no channel,
            // and a default would fabricate one.
            $table->string('channel', 20)->nullable();
            $table->string('direction', 3)->nullable();
            $table->index('channel');
        });

        // Every touch logged before this migration carried a payload channel
        // hardcoded to 'whatsapp'. This is not a guess — it is exactly what the
        // old code asserted. Other activity types have no channel and stay null.
        DB::table('lead_activities')
            ->where('type', 'contacted')
            ->update(['channel' => 'whatsapp', 'direction' => 'out']);
    }

    public function down(): void
    {
        Schema::table('lead_activities', function (Blueprint $table) {
            $table->dropIndex(['channel']);
            $table->dropColumn(['channel', 'direction']);
        });
    }
};
```

- [ ] **Step 4: Add the constants and fillable fields**

In `app/Models/LeadActivity.php`, after the existing `TYPE_ASSIGNED` constant, add:

```php
    /**
     * How a touch happened. Fixed and opinionated, like Lead::STATUSES —
     * deliberately not user-configurable, so reports can never fragment across
     * three spellings of "Instagram".
     */
    public const CHANNELS = [
        'whatsapp', 'instagram', 'facebook', 'tiktok', 'linkedin',
        'phone', 'email', 'walk_in', 'other',
    ];

    /** Who reached out: `out` = we contacted them, `in` = they contacted us. */
    public const DIRECTION_OUT = 'out';
    public const DIRECTION_IN = 'in';
    public const DIRECTIONS = [self::DIRECTION_OUT, self::DIRECTION_IN];
```

And extend `$fillable` to:

```php
    protected $fillable = [
        'lead_id',
        'type',
        'channel',
        'direction',
        'payload',
        'user_id',
    ];
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadActivityChannelTest
```

Expected: PASS — 3 tests.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_27_000001_add_channel_to_lead_activities.php app/Models/LeadActivity.php tests/Feature/LeadActivityChannelTest.php
git commit -m "feat(hunt): channel + direction columns on lead_activities, backfilled to whatsapp/out"
```

---

## Task 3: Handle columns on leads

**Files:**
- Create: `database/migrations/2026_07_27_000002_add_handles_to_leads_table.php`
- Modify: `app/Models/Lead.php:39-62` (the `$fillable` array)
- Test: `tests/Feature/LeadHandlesTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `leads.instagram`, `leads.facebook`, `leads.tiktok`, `leads.linkedin` (nullable string 2048), `leads.email` (nullable string 255) — all fillable.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadHandlesTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadHandlesTest extends TestCase
{
    use RefreshDatabase;

    public function test_handle_columns_are_fillable_and_persist(): void
    {
        $shop = Shop::factory()->create();

        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new', 'source' => 'google',
            'instagram' => 'https://instagram.com/acmegym',
            'facebook' => 'https://facebook.com/acmegym',
            'tiktok' => 'https://tiktok.com/@acmegym',
            'linkedin' => 'https://linkedin.com/company/acme-gym',
            'email' => 'owner@acmegym.ae',
        ]);

        $fresh = $lead->fresh();
        $this->assertSame('https://instagram.com/acmegym', $fresh->instagram);
        $this->assertSame('https://facebook.com/acmegym', $fresh->facebook);
        $this->assertSame('https://tiktok.com/@acmegym', $fresh->tiktok);
        $this->assertSame('https://linkedin.com/company/acme-gym', $fresh->linkedin);
        $this->assertSame('owner@acmegym.ae', $fresh->email);
    }

    public function test_handles_default_to_null(): void
    {
        $shop = Shop::factory()->create();
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new', 'source' => 'google',
        ]);

        foreach (['instagram', 'facebook', 'tiktok', 'linkedin', 'email'] as $field) {
            $this->assertNull($lead->fresh()->{$field}, $field);
        }
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadHandlesTest
```

Expected: FAIL — no such column `instagram`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_07_27_000002_add_handles_to_leads_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            // Canonical absolute URLs (SocialHandle::normalize), so generously
            // sized — Facebook page URLs are long.
            foreach (['instagram', 'facebook', 'tiktok', 'linkedin'] as $column) {
                $table->string($column, 2048)->nullable();
            }
            $table->string('email', 255)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropColumn(['instagram', 'facebook', 'tiktok', 'linkedin', 'email']);
        });
    }
};
```

- [ ] **Step 4: Add the fields to `$fillable`**

In `app/Models/Lead.php`, in the `$fillable` array, insert after `'website',`:

```php
        'instagram',
        'facebook',
        'tiktok',
        'linkedin',
        'email',
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadHandlesTest
```

Expected: PASS — 2 tests.

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_07_27_000002_add_handles_to_leads_table.php app/Models/Lead.php tests/Feature/LeadHandlesTest.php
git commit -m "feat(hunt): per-lead instagram/facebook/tiktok/linkedin/email handles"
```

---

## Task 4: `Lead::recordTouch()` and the touch endpoint

**Files:**
- Modify: `app/Models/Lead.php` (add `recordTouch()` next to `assignTo()` at line 274)
- Modify: `app/Http/Controllers/LeadController.php:386-401` (`logFollowup` → deprecated alias; add `logTouch`)
- Modify: `routes/api.php:308`
- Modify: `tests/Feature/LeadFollowupTest.php`
- Test: `tests/Feature/LeadTouchTest.php`

**Interfaces:**
- Consumes: `LeadActivity::CHANNELS`, `LeadActivity::DIRECTIONS`, `LeadActivity::DIRECTION_OUT`, `LeadActivity::DIRECTION_IN` (Task 2).
- Produces:
  - `Lead::recordTouch(?string $channel, string $direction = LeadActivity::DIRECTION_OUT, ?string $note = null, ?ShopUser $actor = null): LeadActivity`
  - `POST /api/shop/leads/{lead}/touch` — body `{channel, direction, note?}`, returns `{data: Lead}`
  - `POST /api/shop/leads/{lead}/followup` — deprecated alias, forwards as `whatsapp`/`out`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadTouchTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadTouchTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} [shop, plainTextToken] */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        setPermissionsTeamId($shop->id);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]
        ));
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function lead(Shop $shop, array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'shop_id' => $shop->id, 'name' => 'Acme', 'phone' => '0501112233',
            'status' => 'sent', 'source' => 'google', 'last_contacted_at' => now()->subDays(3),
        ], $attrs));
    }

    public function test_an_outbound_touch_logs_the_channel_and_bumps_last_contacted(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'instagram', 'direction' => 'out',
        ])->assertOk()->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'contacted',
            'channel' => 'instagram', 'direction' => 'out',
        ]);
        $this->assertTrue($lead->fresh()->last_contacted_at->isToday());
    }

    /**
     * The subtlest rule in this feature. last_contacted_at drives scopeStale;
     * a reply from the lead means the ball is in OUR court, so it must not make
     * the lead look freshly worked.
     */
    public function test_an_inbound_touch_does_not_bump_last_contacted(): void
    {
        [$shop, $token] = $this->actingShop();
        $was = now()->subDays(3);
        $lead = $this->lead($shop, ['last_contacted_at' => $was]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'whatsapp', 'direction' => 'in',
        ])->assertOk();

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'direction' => 'in',
        ]);
        $this->assertSame(
            $was->toDateTimeString(),
            $lead->fresh()->last_contacted_at->toDateTimeString()
        );
    }

    public function test_a_touch_never_changes_the_funnel_status(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop, ['status' => 'demo']);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'phone', 'direction' => 'out',
        ])->assertOk();

        $this->assertSame('demo', $lead->fresh()->status);
    }

    public function test_an_optional_note_is_stored_on_the_payload(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'walk_in', 'direction' => 'out', 'note' => 'Dropped in, owner away',
        ])->assertOk();

        $activity = LeadActivity::where('lead_id', $lead->id)->firstOrFail();
        $this->assertSame('Dropped in, owner away', $activity->payload['note']);
    }

    public function test_unknown_channels_and_directions_are_rejected(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'carrier_pigeon', 'direction' => 'out',
        ])->assertStatus(422)->assertJsonValidationErrors('channel');

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'whatsapp', 'direction' => 'sideways',
        ])->assertStatus(422)->assertJsonValidationErrors('direction');

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [])
            ->assertStatus(422)->assertJsonValidationErrors(['channel', 'direction']);
    }

    public function test_touch_is_tenant_scoped(): void
    {
        [, $token] = $this->actingShop();
        $other = Shop::factory()->create(['is_master' => true]);
        $lead = $this->lead($other, ['name' => 'Not Mine']);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'whatsapp', 'direction' => 'out',
        ])->assertNotFound();
    }

    public function test_the_deprecated_followup_alias_still_logs_whatsapp_out(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/followup")->assertOk();

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'direction' => 'out',
        ]);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadTouchTest
```

Expected: FAIL — 404 on `/touch` (route does not exist).

- [ ] **Step 3: Add `recordTouch()` to the Lead model**

In `app/Models/Lead.php`, immediately after the `assignTo()` method (ends line 299), add:

```php
    /**
     * Record one contact touch on this lead. Shared by the web controller, the
     * status-change reply capture, and the voice tool, so the rules live in one
     * place and cannot drift between them.
     *
     * A null channel means "we know a touch happened but not how" — honest, and
     * preferable to defaulting to whatsapp, which is the bug this feature fixes.
     */
    public function recordTouch(
        ?string $channel,
        string $direction = LeadActivity::DIRECTION_OUT,
        ?string $note = null,
        ?ShopUser $actor = null,
    ): LeadActivity {
        return DB::transaction(function () use ($channel, $direction, $note, $actor) {
            // Only OUR outbound work resets the staleness clock. A reply from
            // the lead leaves the ball in our court — precisely when a lead is
            // most at risk of being dropped — so it must not look freshly worked.
            if ($direction === LeadActivity::DIRECTION_OUT) {
                $this->last_contacted_at = now();
                $this->save();
            }

            return $this->activities()->create([
                'type' => LeadActivity::TYPE_CONTACTED,
                'channel' => $channel,
                'direction' => $direction,
                'payload' => $note !== null && $note !== '' ? ['note' => $note] : null,
                'user_id' => $actor?->id,
            ]);
        });
    }
```

- [ ] **Step 4: Replace `logFollowup` in the controller**

In `app/Http/Controllers/LeadController.php`, replace the whole `logFollowup` method (lines 381-401, including its docblock) with:

```php
    /**
     * POST /shop/leads/{lead}/touch {channel, direction, note?}
     * Record one contact touch. One endpoint for both directions — a touch is a
     * touch, and two near-identical endpoints would drift. Never changes the
     * funnel status; that stays under updateStatus().
     */
    public function logTouch(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        $this->guardLead($lead, $shop);

        $data = $request->validate([
            'channel' => ['required', Rule::in(LeadActivity::CHANNELS)],
            'direction' => ['required', Rule::in(LeadActivity::DIRECTIONS)],
            'note' => ['nullable', 'string', 'max:2000'],
        ]);

        $lead->recordTouch(
            $data['channel'],
            $data['direction'],
            $data['note'] ?? null,
            current_shop_user(),
        );

        return response()->json(['data' => $lead->fresh()]);
    }

    /**
     * POST /shop/leads/{lead}/followup
     *
     * @deprecated Deploy-window alias only. The backend ships before the SPA, so
     * the live admin build still calls this. Delete it (and its route) in the
     * follow-up commit once the new SPA is deployed — a route that silently
     * means "whatsapp, outbound" is the exact bug this feature fixes.
     */
    public function logFollowup(Request $request, Lead $lead)
    {
        $request->merge([
            'channel' => 'whatsapp',
            'direction' => LeadActivity::DIRECTION_OUT,
        ]);

        return $this->logTouch($request, $lead);
    }
```

- [ ] **Step 5: Add the route**

In `routes/api.php`, replace line 308 with:

```php
    Route::post  ('/shop/leads/{lead}/touch',     [\App\Http\Controllers\LeadController::class, 'logTouch'])->middleware('can.perm:leads.manage');
    // DEPRECATED deploy-window alias — remove once the new admin SPA is live.
    Route::post  ('/shop/leads/{lead}/followup',  [\App\Http\Controllers\LeadController::class, 'logFollowup'])->middleware('can.perm:leads.manage');
```

- [ ] **Step 6: Return the new columns from `show()`**

`LeadController::show()` selects activity columns explicitly (`app/Http/Controllers/LeadController.php:313-315`), so `channel` and `direction` would be silently dropped before reaching the SPA and every timeline would read "Contacted" forever. Change the `get()` call to:

```php
        $activities = $lead->activities()
            ->orderByDesc('id')
            ->get(['id', 'type', 'channel', 'direction', 'payload', 'created_at']);
```

Add this assertion to `tests/Feature/LeadTouchTest.php`:

```php
    public function test_the_detail_endpoint_returns_the_channel_and_direction(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);
        $lead->recordTouch('instagram', LeadActivity::DIRECTION_OUT);

        $this->auth($token)->getJson("/api/shop/leads/{$lead->id}")
            ->assertOk()
            ->assertJsonPath('activities.0.channel', 'instagram')
            ->assertJsonPath('activities.0.direction', 'out');
    }
```

- [ ] **Step 7: Retarget the old followup test**

In `tests/Feature/LeadFollowupTest.php`, change both `postJson` calls from `/followup` to `/touch` and give them a body. Line 43 becomes:

```php
        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'whatsapp', 'direction' => 'out',
        ])
```

Line 62 becomes:

```php
        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/touch", [
            'channel' => 'whatsapp', 'direction' => 'out',
        ])->assertNotFound();
```

- [ ] **Step 8: Run both test files to verify they pass**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter='LeadTouchTest|LeadFollowupTest'
```

Expected: PASS — 10 tests.

- [ ] **Step 9: Commit**

```bash
git add app/Models/Lead.php app/Http/Controllers/LeadController.php routes/api.php tests/Feature/LeadTouchTest.php tests/Feature/LeadFollowupTest.php
git commit -m "feat(hunt): POST /shop/leads/{lead}/touch records channel + direction"
```

---

## Task 5: Scoped lead-edit endpoint

**Files:**
- Modify: `app/Http/Controllers/LeadController.php` (add `update()` after `logFollowup`)
- Modify: `routes/api.php`
- Test: `tests/Feature/LeadUpdateTest.php`

**Interfaces:**
- Consumes: `SocialHandle::PLATFORMS`, `SocialHandle::normalize()` (Task 1); handle columns (Task 3).
- Produces: `PATCH /api/shop/leads/{lead}` — accepts `phone`, `whatsapp`, `website`, `notes`, `instagram`, `facebook`, `tiktok`, `linkedin`, `email`; returns `{data: Lead}`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadUpdateTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadUpdateTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} [shop, plainTextToken] */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        setPermissionsTeamId($shop->id);
        $user->assignRole(\Spatie\Permission\Models\Role::firstOrCreate(
            ['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]
        ));
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();

        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function lead(Shop $shop, array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'sent', 'source' => 'google',
        ], $attrs));
    }

    public function test_handles_are_normalized_on_save(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'instagram' => '@acmegym',
            'tiktok' => 'https://www.tiktok.com/@acmegym',
            'email' => 'Owner@AcmeGym.ae',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('https://instagram.com/acmegym', $fresh->instagram);
        $this->assertSame('https://tiktok.com/@acmegym', $fresh->tiktok);
        $this->assertSame('owner@acmegym.ae', $fresh->email);
    }

    public function test_an_empty_string_clears_a_handle(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop, ['instagram' => 'https://instagram.com/acmegym']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", ['instagram' => ''])->assertOk();

        $this->assertNull($lead->fresh()->instagram);
    }

    public function test_an_uninterpretable_handle_is_a_422_not_a_silent_null(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop, ['instagram' => 'https://instagram.com/acmegym']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'instagram' => 'https://example.com/not-a-profile',
        ])->assertStatus(422)->assertJsonValidationErrors('instagram');

        // The prior value must survive a rejected write.
        $this->assertSame('https://instagram.com/acmegym', $lead->fresh()->instagram);
    }

    public function test_cross_platform_handles_are_rejected(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'linkedin' => 'https://instagram.com/acmegym',
        ])->assertStatus(422)->assertJsonValidationErrors('linkedin');
    }

    /**
     * The privilege-escalation surface. A general edit endpoint must not become
     * a side door around leads.assign or the guarded deal/status endpoints.
     */
    public function test_status_assignment_and_deal_fields_are_not_editable_here(): void
    {
        [$shop, $token] = $this->actingShop();
        $agent = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $lead = $this->lead($shop, ['status' => 'sent']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'email' => 'owner@acmegym.ae',
            'status' => 'won',
            'assigned_to_id' => $agent->id,
            'deal_amount' => 99999,
            'deal_type' => 'recurring',
            'deal_term_months' => 12,
            'shop_id' => 999,
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('owner@acmegym.ae', $fresh->email, 'the allowed field should still save');
        $this->assertSame('sent', $fresh->status);
        $this->assertNull($fresh->assigned_to_id);
        $this->assertNull($fresh->deal_amount);
        $this->assertNull($fresh->deal_type);
        $this->assertSame($shop->id, $fresh->shop_id);
    }

    public function test_contact_fields_other_than_handles_still_save(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'phone' => '0509998877',
            'website' => 'https://acmegym.ae',
            'notes' => 'Prefers a call after 4pm',
        ])->assertOk();

        $fresh = $lead->fresh();
        $this->assertSame('0509998877', $fresh->phone);
        $this->assertSame('https://acmegym.ae', $fresh->website);
        $this->assertSame('Prefers a call after 4pm', $fresh->notes);
    }

    public function test_update_is_tenant_scoped(): void
    {
        [, $token] = $this->actingShop();
        $other = Shop::factory()->create(['is_master' => true]);
        $lead = $this->lead($other, ['name' => 'Not Mine']);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}", [
            'email' => 'owner@acmegym.ae',
        ])->assertNotFound();
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadUpdateTest
```

Expected: FAIL — 405/404, no PATCH route.

- [ ] **Step 3: Add the `update` method**

In `app/Http/Controllers/LeadController.php`, add `use App\Support\SocialHandle;` and `use Illuminate\Validation\ValidationException;` to the imports, then add this method after `logFollowup`:

```php
    /**
     * PATCH /shop/leads/{lead}
     * Edit a lead's contact details — the only lead-editing endpoint.
     *
     * Status, assignment and deal value are deliberately absent: each has its
     * own endpoint with its own permission and its own activity log. This must
     * not become a side door around leads.assign.
     */
    public function update(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        $this->guardLead($lead, $shop);

        $data = $request->validate([
            'phone' => ['nullable', 'string', 'max:60'],
            'whatsapp' => ['nullable', 'string', 'max:60'],
            'website' => ['nullable', 'string', 'max:2048'],
            'notes' => ['nullable', 'string', 'max:5000'],
            'instagram' => ['nullable', 'string', 'max:2048'],
            'facebook' => ['nullable', 'string', 'max:2048'],
            'tiktok' => ['nullable', 'string', 'max:2048'],
            'linkedin' => ['nullable', 'string', 'max:2048'],
            'email' => ['nullable', 'string', 'max:255'],
        ]);

        foreach (SocialHandle::PLATFORMS as $platform) {
            if (! array_key_exists($platform, $data)) {
                continue;
            }

            $raw = trim((string) ($data[$platform] ?? ''));
            if ($raw === '') {
                $lead->{$platform} = null;
                continue;
            }

            $normalized = SocialHandle::normalize($platform, $raw);
            if ($normalized === null) {
                // A 422, never a silent null — storing nothing would look like
                // the user simply hadn't filled it in.
                throw ValidationException::withMessages([
                    $platform => ["That doesn't look like a valid {$platform} handle or link."],
                ]);
            }

            $lead->{$platform} = $normalized;
        }

        foreach (['phone', 'whatsapp', 'website', 'notes'] as $field) {
            if (array_key_exists($field, $data)) {
                $lead->{$field} = $data[$field];
            }
        }

        $lead->save();

        return response()->json(['data' => $lead->fresh()]);
    }
```

- [ ] **Step 4: Add the route**

In `routes/api.php`, directly after the `GET /shop/leads/{lead}` line (line 305), add:

```php
    Route::patch ('/shop/leads/{lead}',           [\App\Http\Controllers\LeadController::class, 'update'])->middleware('can.perm:leads.manage');
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadUpdateTest
```

Expected: PASS — 7 tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/LeadController.php routes/api.php tests/Feature/LeadUpdateTest.php
git commit -m "feat(hunt): PATCH /shop/leads/{lead} for contact details only"
```

---

## Task 6: Reply channel on the status move

**Files:**
- Modify: `app/Http/Controllers/LeadController.php:328-379` (`updateStatus`)
- Test: `tests/Feature/LeadTouchTest.php` (append)

**Interfaces:**
- Consumes: `Lead::recordTouch()` (Task 4), `LeadActivity::CHANNELS` (Task 2).
- Produces: `PATCH /api/shop/leads/{lead}/status` additionally accepts `reply_channel`.

- [ ] **Step 1: Write the failing test**

Append these two methods to `tests/Feature/LeadTouchTest.php`, inside the class:

```php
    public function test_moving_to_replied_with_a_channel_logs_an_inbound_touch(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'replied', 'reply_channel' => 'instagram',
        ])->assertOk();

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'status_change',
        ]);
        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'contacted',
            'channel' => 'instagram', 'direction' => 'in',
        ]);
        $this->assertSame('replied', $lead->fresh()->status);
    }

    public function test_omitting_the_reply_channel_logs_no_touch(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'replied',
        ])->assertOk();

        $this->assertDatabaseMissing('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'contacted',
        ]);
    }

    public function test_an_unknown_reply_channel_is_rejected(): void
    {
        [$shop, $token] = $this->actingShop();
        $lead = $this->lead($shop);

        $this->auth($token)->patchJson("/api/shop/leads/{$lead->id}/status", [
            'status' => 'replied', 'reply_channel' => 'carrier_pigeon',
        ])->assertStatus(422)->assertJsonValidationErrors('reply_channel');
    }
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadTouchTest
```

Expected: FAIL — no inbound `contacted` row is written.

- [ ] **Step 3: Extend `updateStatus`**

In `app/Http/Controllers/LeadController.php`, in `updateStatus`, add to the `$request->validate([...])` array (after the `deal_term_months` rule):

```php
            // How the lead got back to us. Only meaningful on a move to
            // `replied`; absent means unknown, which must not be defaulted.
            'reply_channel' => ['nullable', Rule::in(LeadActivity::CHANNELS)],
```

Then, immediately after the existing `$lead->activities()->create([...])` block that logs the status change (ends line 376), add:

```php
        // The reply came in on some channel — record it as a real inbound touch
        // so the channel report sees it, not just as a note on the status row.
        if ($data['status'] === 'replied' && ($data['reply_channel'] ?? null) !== null) {
            $lead->recordTouch(
                $data['reply_channel'],
                LeadActivity::DIRECTION_IN,
                null,
                current_shop_user(),
            );
        }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadTouchTest
```

Expected: PASS — 11 tests.

- [ ] **Step 5: Commit**

```bash
git add app/Http/Controllers/LeadController.php tests/Feature/LeadTouchTest.php
git commit -m "feat(hunt): capture the reply channel when a lead moves to replied"
```

---

## Task 7: Salvage Facebook pages out of `website` on import

**Files:**
- Modify: `app/Services/Leads/LeadImporter.php:40-51`
- Test: `tests/Feature/LeadImporterFacebookTest.php`

**Interfaces:**
- Consumes: `SocialHandle::detectPlatform()`, `SocialHandle::normalize()` (Task 1); `leads.facebook` (Task 3).
- Produces: no new API. `LeadImporter::import()` moves a `facebook.com` website into the `facebook` column.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadImporterFacebookTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Leads\LeadImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImporterFacebookTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_facebook_page_website_becomes_the_facebook_handle(): void
    {
        $shop = Shop::factory()->create();

        $out = app(LeadImporter::class)->import($shop, [[
            'name' => 'Acme Gym',
            'website' => 'https://www.facebook.com/acmegym',
            'source' => 'meta_ad_library',
        ]]);

        $lead = $out['saved'][0]->fresh();
        $this->assertSame('https://facebook.com/acmegym', $lead->facebook);
        $this->assertNull($lead->website, 'a page URL is a channel, not a website');
    }

    public function test_a_real_website_is_left_alone(): void
    {
        $shop = Shop::factory()->create();

        $out = app(LeadImporter::class)->import($shop, [[
            'name' => 'Acme Gym',
            'website' => 'https://acmegym.ae',
            'source' => 'google_places',
        ]]);

        $lead = $out['saved'][0]->fresh();
        $this->assertSame('https://acmegym.ae', $lead->website);
        $this->assertNull($lead->facebook);
    }

    public function test_a_lead_with_no_website_imports_cleanly(): void
    {
        $shop = Shop::factory()->create();

        $out = app(LeadImporter::class)->import($shop, [[
            'name' => 'Acme Gym', 'source' => 'google_places',
        ]]);

        $lead = $out['saved'][0]->fresh();
        $this->assertNull($lead->website);
        $this->assertNull($lead->facebook);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadImporterFacebookTest
```

Expected: FAIL — `facebook` is null, `website` still holds the page URL.

- [ ] **Step 3: Implement the salvage**

In `app/Services/Leads/LeadImporter.php`, add `use App\Support\SocialHandle;` to the imports. Then inside `import()`, directly after the `$attrs = [...]` assignment (ends line 51), add:

```php
            // AdLibraryService parks Facebook page URLs in `website` (it even
            // filters on that, AdLibraryService.php:266). A page URL is a
            // channel, not a website — move it so the lead arrives with a
            // working Facebook button instead of a misleading site link.
            if ($attrs['website'] !== null && SocialHandle::detectPlatform($attrs['website']) === 'facebook') {
                $attrs['facebook'] = SocialHandle::normalize('facebook', $attrs['website']);
                $attrs['website'] = null;
            }
```

- [ ] **Step 4: Run the test to verify it passes**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadImporterFacebookTest
```

Expected: PASS — 3 tests.

- [ ] **Step 5: Run the existing importer suite for regressions**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=LeadFinderTest
```

Expected: PASS — no regressions.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Leads/LeadImporter.php tests/Feature/LeadImporterFacebookTest.php
git commit -m "feat(hunt): route imported Facebook page URLs into the facebook handle"
```

---

## Task 8: Channel-aware voice tool

**Files:**
- Modify: `app/Services/Assistant/Modules/HuntTools.php:209-228` (`logFollowup`) and `:315-318` (the `log_followup` schema)
- Test: `tests/Feature/HuntAssistantToolsTest.php` (append)

**Interfaces:**
- Consumes: `Lead::recordTouch()` (Task 4), `LeadActivity::CHANNELS` (Task 2).
- Produces: `log_followup` tool accepts optional `channel` (string) and `direction` (`out`|`in`).

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/HuntAssistantToolsTest.php`, inside the class. It already provides `leadsShop(): Shop` and `exec(Shop $shop, string $tool, array $input = []): array` — use those, do not add new setup helpers:

```php
    /** A saved lead on $shop for the channel tests. */
    private function channelLead(Shop $shop, array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'shop_id' => $shop->id, 'name' => 'Acme Gym', 'phone' => '0501112233',
            'status' => 'sent', 'source' => 'google',
        ], $attrs));
    }

    public function test_log_followup_records_the_spoken_channel(): void
    {
        $shop = $this->leadsShop();
        $lead = $this->channelLead($shop);

        $this->exec($shop, 'log_followup', [
            'name' => 'Acme Gym', 'channel' => 'instagram', 'confirmed' => true,
        ]);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'channel' => 'instagram', 'direction' => 'out',
        ]);
    }

    public function test_log_followup_maps_spoken_aliases_onto_the_fixed_list(): void
    {
        $shop = $this->leadsShop();
        $lead = $this->channelLead($shop);

        $this->exec($shop, 'log_followup', [
            'name' => 'Acme Gym', 'channel' => 'insta', 'confirmed' => true,
        ]);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'channel' => 'instagram',
        ]);
    }

    /** The assistant must not invent how a conversation happened. */
    public function test_log_followup_without_a_channel_records_null_not_whatsapp(): void
    {
        $shop = $this->leadsShop();
        $lead = $this->channelLead($shop);

        $this->exec($shop, 'log_followup', ['name' => 'Acme Gym', 'confirmed' => true]);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'type' => 'contacted', 'channel' => null,
        ]);
    }

    public function test_log_followup_can_record_an_inbound_reply(): void
    {
        $shop = $this->leadsShop();
        $lead = $this->channelLead($shop, ['last_contacted_at' => now()->subDays(3)]);

        $this->exec($shop, 'log_followup', [
            'name' => 'Acme Gym', 'channel' => 'whatsapp', 'direction' => 'in', 'confirmed' => true,
        ]);

        $this->assertDatabaseHas('lead_activities', [
            'lead_id' => $lead->id, 'channel' => 'whatsapp', 'direction' => 'in',
        ]);
        $this->assertTrue($lead->fresh()->last_contacted_at->lt(now()->subDay()));
    }
```

Add `use App\Models\Lead;` to the file's imports if it is not already there.

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=HuntAssistantToolsTest
```

Expected: FAIL — `channel` is `whatsapp` on every row.

- [ ] **Step 3: Rewrite `logFollowup` in HuntTools**

In `app/Services/Assistant/Modules/HuntTools.php`, replace the `logFollowup` method (lines 209-228) with:

```php
    private function logFollowup(ToolCall $call): array
    {
        $channel = $this->channelArg($call);
        $direction = $call->get('direction') === LeadActivity::DIRECTION_IN
            ? LeadActivity::DIRECTION_IN
            : LeadActivity::DIRECTION_OUT;

        $on = $channel !== null ? ' on ' . $this->channelLabel($channel) : '';

        return $this->gate(
            $call,
            resolve: fn () => $this->resolveLead($call),
            describe: fn ($lead) => [
                $direction === LeadActivity::DIRECTION_IN
                    ? "Log that {$lead->name} replied{$on} (no status change)"
                    : "Log a follow-up with {$lead->name}{$on} (no status change)",
                array_filter(['followup' => 'logged', 'channel' => $channel]),
            ],
            write: function ($lead) use ($channel, $direction) {
                $lead->recordTouch($channel, $direction, null, current_shop_user());

                return ['name' => $lead->name, 'logged' => true, 'channel' => $channel];
            },
        );
    }

    /**
     * Map what was actually said onto the fixed channel list. Returns null for
     * anything unrecognised — the assistant must not invent how a conversation
     * happened, and a null channel is honest.
     */
    private function channelArg(ToolCall $call): ?string
    {
        $raw = strtolower(trim((string) $call->get('channel')));
        if ($raw === '') {
            return null;
        }

        $aliases = [
            'ig' => 'instagram', 'insta' => 'instagram', 'gram' => 'instagram',
            'wa' => 'whatsapp', 'whats app' => 'whatsapp', 'whatsap' => 'whatsapp',
            'fb' => 'facebook', 'messenger' => 'facebook',
            'tik tok' => 'tiktok', 'tik-tok' => 'tiktok',
            'call' => 'phone', 'called' => 'phone', 'phone call' => 'phone',
            'mail' => 'email', 'e-mail' => 'email',
            'walk in' => 'walk_in', 'walk-in' => 'walk_in', 'in person' => 'walk_in', 'visit' => 'walk_in',
        ];
        $raw = $aliases[$raw] ?? $raw;

        return in_array($raw, LeadActivity::CHANNELS, true) ? $raw : null;
    }

    /** Human label for a channel, for the confirm-gate preview text. */
    private function channelLabel(string $channel): string
    {
        return $channel === 'walk_in' ? 'a walk-in visit' : ucfirst($channel);
    }
```

- [ ] **Step 4: Update the tool schema**

In the same file, replace the `log_followup` schema entry (line 315) with:

```php
            ['name' => 'log_followup', 'description' => 'Record that a lead was contacted, or that they replied, WITHOUT changing its funnel stage. Use when the owner says they messaged/called a lead again, or that a lead got back to them, but nothing moved yet. Identify the lead by business name. Set channel to how it happened and direction to "out" (we contacted them) or "in" (they contacted us). Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).'],
                'channel' => ['type' => 'string', 'enum' => LeadActivity::CHANNELS, 'description' => 'How the contact happened. Omit only if genuinely unknown.'],
                'direction' => ['type' => 'string', 'enum' => LeadActivity::DIRECTIONS, 'description' => 'Defaults to "out". Use "in" when the LEAD contacted US.'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name']]],
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=HuntAssistantToolsTest
```

Expected: PASS. Hunt voice search must stay mocked — a live search spends a real credit.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Assistant/Modules/HuntTools.php tests/Feature/HuntAssistantToolsTest.php
git commit -m "feat(hunt): voice log_followup takes a channel and a direction"
```

---

## Task 9: `huntByChannel` reporting

**Files:**
- Modify: `app/Services/Reports/ReportsAggregator.php` (add after `huntByAgent`, which ends around line 560)
- Modify: `app/Http/Controllers/ReportsController.php:58-74` (`hunt`)
- Test: `tests/Feature/HuntByChannelTest.php`

**Interfaces:**
- Consumes: `LeadActivity::CHANNELS` (Task 2), `Lead::recordTouch()` (Task 4), the private `dealTotal()` and `agentLeadFilter()` helpers already in the aggregator.
- Produces:
  - `ReportsAggregator::huntByChannel(int $shopId, Carbon $from, Carbon $to): array` — a list of `['channel' => string, 'touches' => int, 'replies' => int, 'won' => int, 'won_value' => float]`, one row per channel plus `unattributed`, always in that fixed order.
  - `GET /shop/reports/hunt` gains a `channels` key.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/HuntByChannelTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Models\Shop;
use App\Services\Reports\ReportsAggregator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class HuntByChannelTest extends TestCase
{
    use RefreshDatabase;

    private function rows(int $shopId): array
    {
        $out = app(ReportsAggregator::class)->huntByChannel(
            $shopId, now()->subDays(30)->startOfDay(), now()->endOfDay()
        );

        return collect($out)->keyBy('channel')->all();
    }

    private function lead(Shop $shop, array $attrs = []): Lead
    {
        return Lead::create(array_merge([
            'shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'sent', 'source' => 'google',
        ], $attrs));
    }

    public function test_touches_and_replies_are_counted_per_channel(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop);

        $lead->recordTouch('instagram', LeadActivity::DIRECTION_OUT);
        $lead->recordTouch('instagram', LeadActivity::DIRECTION_OUT);
        $lead->recordTouch('whatsapp', LeadActivity::DIRECTION_IN);

        $rows = $this->rows($shop->id);
        $this->assertSame(2, $rows['instagram']['touches']);
        $this->assertSame(0, $rows['instagram']['replies']);
        $this->assertSame(0, $rows['whatsapp']['touches']);
        $this->assertSame(1, $rows['whatsapp']['replies']);
    }

    public function test_every_channel_is_present_even_at_zero(): void
    {
        $shop = Shop::factory()->create();

        $rows = $this->rows($shop->id);

        foreach (array_merge(LeadActivity::CHANNELS, ['unattributed']) as $channel) {
            $this->assertArrayHasKey($channel, $rows, $channel);
            $this->assertSame(0, $rows[$channel]['touches'], $channel);
        }
    }

    /** A win belongs to the channel that OPENED the conversation. */
    public function test_a_win_is_credited_to_the_first_outbound_touch(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 500, 'deal_type' => 'one_off',
        ]);

        $lead->recordTouch('instagram', LeadActivity::DIRECTION_OUT);
        $lead->recordTouch('whatsapp', LeadActivity::DIRECTION_OUT);
        $lead->recordTouch('phone', LeadActivity::DIRECTION_OUT);

        $rows = $this->rows($shop->id);
        $this->assertSame(1, $rows['instagram']['won']);
        $this->assertSame(500.0, $rows['instagram']['won_value']);
        $this->assertSame(0, $rows['whatsapp']['won']);
        $this->assertSame(0, $rows['phone']['won']);
    }

    /** An inbound reply must never be mistaken for the opening touch. */
    public function test_an_inbound_touch_does_not_attribute_a_win(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 500, 'deal_type' => 'one_off',
        ]);

        $lead->recordTouch('linkedin', LeadActivity::DIRECTION_IN);
        $lead->recordTouch('whatsapp', LeadActivity::DIRECTION_OUT);

        $rows = $this->rows($shop->id);
        $this->assertSame(0, $rows['linkedin']['won']);
        $this->assertSame(1, $rows['whatsapp']['won']);
    }

    public function test_a_win_with_no_outbound_touch_is_unattributed(): void
    {
        $shop = Shop::factory()->create();
        $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 300, 'deal_type' => 'one_off',
        ]);

        $rows = $this->rows($shop->id);
        $this->assertSame(1, $rows['unattributed']['won']);
        $this->assertSame(300.0, $rows['unattributed']['won_value']);
    }

    /** The opening touch often predates the report window; the credit still stands. */
    public function test_attribution_uses_touches_outside_the_report_window(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 400, 'deal_type' => 'one_off',
        ]);

        $touch = $lead->recordTouch('tiktok', LeadActivity::DIRECTION_OUT);
        $touch->forceFill(['created_at' => now()->subDays(120)])->save();

        $rows = $this->rows($shop->id);
        $this->assertSame(1, $rows['tiktok']['won']);
        $this->assertSame(0, $rows['tiktok']['touches'], 'the touch itself is outside the window');
    }

    public function test_recurring_deal_value_matches_amount_times_term(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop, [
            'status' => 'won', 'deal_won_at' => now(),
            'deal_amount' => 149, 'deal_type' => 'recurring', 'deal_term_months' => 12,
        ]);
        $lead->recordTouch('email', LeadActivity::DIRECTION_OUT);

        $rows = $this->rows($shop->id);
        $this->assertSame(1, $rows['email']['won']);
        // 149/mo × 12 months — must match dealTotal(), used by every other report.
        $this->assertSame(1788.0, $rows['email']['won_value']);
    }

    public function test_channels_do_not_leak_across_shops(): void
    {
        $mine = Shop::factory()->create();
        $other = Shop::factory()->create();
        $this->lead($other, ['name' => 'Not Mine'])->recordTouch('tiktok', LeadActivity::DIRECTION_OUT);

        $rows = $this->rows($mine->id);
        $this->assertSame(0, $rows['tiktok']['touches']);
    }

    public function test_touches_outside_the_range_are_excluded(): void
    {
        $shop = Shop::factory()->create();
        $lead = $this->lead($shop);
        $old = $lead->recordTouch('facebook', LeadActivity::DIRECTION_OUT);
        $old->forceFill(['created_at' => now()->subDays(90)])->save();

        $rows = $this->rows($shop->id);
        $this->assertSame(0, $rows['facebook']['touches']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=HuntByChannelTest
```

Expected: FAIL — `Call to undefined method ... huntByChannel()`.

- [ ] **Step 3: Implement `huntByChannel`**

In `app/Services/Reports/ReportsAggregator.php`, add `use App\Models\LeadActivity;` to the imports if absent, then add this method after `huntByAgent`:

```php
    /**
     * Which channel actually works: outbound touches, inbound replies, wins and
     * won value per channel, for the Hunt dashboard.
     *
     * Reads the real `channel`/`direction` columns, so this is a plain GROUP BY
     * and behaves identically on sqlite and pgsql — unlike the payload-based
     * aggregations elsewhere in this class, which must group in PHP.
     *
     * @return array<int, array{channel: string, touches: int, replies: int, won: int, won_value: float}>
     */
    public function huntByChannel(int $shopId, Carbon $from, Carbon $to): array
    {
        $agent = $this->agentLeadFilter();

        $touches = fn (string $direction) => DB::table('lead_activities')
            ->join('leads', 'leads.id', '=', 'lead_activities.lead_id')
            ->where('leads.shop_id', $shopId)
            ->when($agent !== null, fn ($b) => $b->where('leads.assigned_to_id', $agent))
            ->where('lead_activities.type', 'contacted')
            ->where('lead_activities.direction', $direction)
            ->whereNotNull('lead_activities.channel')
            ->whereBetween('lead_activities.created_at', [$from, $to])
            ->selectRaw('lead_activities.channel as ch, count(*) as c')
            ->groupBy('ch')
            ->pluck('c', 'ch');

        $out = $touches(LeadActivity::DIRECTION_OUT);
        $in = $touches(LeadActivity::DIRECTION_IN);

        $wonRows = DB::table('leads')->where('shop_id', $shopId)
            ->when($agent !== null, fn ($b) => $b->where('assigned_to_id', $agent))
            ->where('status', 'won')
            ->whereNotNull('deal_won_at')
            ->whereBetween('deal_won_at', [$from, $to])
            ->get(['id', 'deal_amount', 'deal_type', 'deal_term_months']);

        // Attribution: the FIRST outbound touch ever recorded on the lead — the
        // channel that opened the conversation, not whichever one happened to be
        // in use at closing. Deliberately NOT date-bounded: the opener often
        // predates the report window, and dropping it would silently move real
        // wins into `unattributed`.
        $firstTouch = [];
        if ($wonRows->isNotEmpty()) {
            $rows = DB::table('lead_activities')
                ->whereIn('lead_id', $wonRows->pluck('id')->all())
                ->where('type', 'contacted')
                ->where('direction', LeadActivity::DIRECTION_OUT)
                ->whereNotNull('channel')
                ->orderBy('lead_id')->orderBy('id')
                ->get(['lead_id', 'channel']);

            foreach ($rows as $row) {
                // Ordered by id, so the first row per lead wins.
                $firstTouch[(int) $row->lead_id] ??= $row->channel;
            }
        }

        $wonCount = [];
        $wonValue = [];
        foreach ($wonRows as $row) {
            $channel = $firstTouch[(int) $row->id] ?? 'unattributed';
            $wonCount[$channel] = ($wonCount[$channel] ?? 0) + 1;

            $total = $this->dealTotal($row->deal_amount, $row->deal_type, $row->deal_term_months);
            if ($total === null) {
                continue;
            }
            $wonValue[$channel] = ($wonValue[$channel] ?? 0) + $total;
        }

        // Zero-filled and always in the same order, so the card renders a stable
        // shape and a channel with no activity is visibly zero rather than absent.
        $result = [];
        foreach (array_merge(LeadActivity::CHANNELS, ['unattributed']) as $channel) {
            $result[] = [
                'channel' => $channel,
                'touches' => (int) ($out[$channel] ?? 0),
                'replies' => (int) ($in[$channel] ?? 0),
                'won' => (int) ($wonCount[$channel] ?? 0),
                'won_value' => round((float) ($wonValue[$channel] ?? 0), 2),
            ];
        }

        return $result;
    }
```

- [ ] **Step 4: Wire it into the hunt payload**

In `app/Http/Controllers/ReportsController.php`, in the `hunt()` response array, add after the `'agents'` line:

```php
            'channels'  => $this->aggregator->huntByChannel($shopId, $from, $to),
```

- [ ] **Step 5: Run the test to verify it passes**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh" --filter=HuntByChannelTest
```

Expected: PASS — 9 tests.

- [ ] **Step 6: Run the whole backend suite**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh"
```

Expected: all green. Fix any regression before committing.

- [ ] **Step 7: Commit**

```bash
git add app/Services/Reports/ReportsAggregator.php app/Http/Controllers/ReportsController.php tests/Feature/HuntByChannelTest.php
git commit -m "feat(hunt): huntByChannel report with first-touch win attribution"
```

---

## Task 10: Frontend types and API client

**Files:**
- Modify: `admin/src/types.ts:256-291` (`Lead`), `:306-312` (`LeadActivity`), and the Lead Finder section at `:200`
- Modify: `admin/src/lib/leads.ts:170-180`
- Modify: `admin/src/lib/huntInsights.ts`
- Create: `admin/src/lib/channels.ts`
- Test: `admin/src/lib/channels.test.ts`

**Interfaces:**
- Consumes: the endpoints from Tasks 4, 5, 6, 9.
- Produces:
  - `LEAD_CHANNELS`, `type LeadChannel`, `type TouchDirection` in `types.ts`
  - `logTouch(id, channel, direction?, note?): Promise<Lead>` and `updateLead(id, fields): Promise<Lead>` in `lib/leads.ts`
  - `updateLeadStatus(id, status, note?, deal?, replyChannel?)` — `replyChannel` appended as the 5th parameter
  - `CHANNEL_META`, `channelHref(lead, channel)`, `availableChannels(lead)` in `lib/channels.ts`
  - `HuntChannelRow` + `channels` in `lib/huntInsights.ts`

- [ ] **Step 1: Write the failing test**

Create `admin/src/lib/channels.test.ts`:

```ts
import { describe, it, expect } from 'vitest';
import { CHANNEL_META, channelHref, availableChannels } from './channels';
import { LEAD_CHANNELS } from '@/types';
import type { Lead } from '@/types';

const base: Lead = { id: 1, name: 'Acme', status: 'sent' };

describe('CHANNEL_META', () => {
  it('has a label and colour for every channel', () => {
    for (const channel of LEAD_CHANNELS) {
      expect(CHANNEL_META[channel]?.label, channel).toBeTruthy();
      expect(CHANNEL_META[channel]?.color, channel).toBeTruthy();
    }
  });
});

describe('channelHref', () => {
  it('uses the server-normalized whatsapp and tel urls', () => {
    const lead = { ...base, whatsapp_url: 'https://wa.me/971501112233', tel_url: 'tel:+971501112233' };
    expect(channelHref(lead, 'whatsapp')).toBe('https://wa.me/971501112233');
    expect(channelHref(lead, 'phone')).toBe('tel:+971501112233');
  });

  it('builds a mailto for email', () => {
    expect(channelHref({ ...base, email: 'owner@acmegym.ae' }, 'email')).toBe('mailto:owner@acmegym.ae');
  });

  it('returns the stored handle url for social channels', () => {
    const lead = { ...base, instagram: 'https://instagram.com/acmegym' };
    expect(channelHref(lead, 'instagram')).toBe('https://instagram.com/acmegym');
  });

  it('returns null when the lead has no handle for that channel', () => {
    expect(channelHref(base, 'instagram')).toBeNull();
    expect(channelHref(base, 'whatsapp')).toBeNull();
  });

  it('has no link for walk_in or other', () => {
    expect(channelHref(base, 'walk_in')).toBeNull();
    expect(channelHref(base, 'other')).toBeNull();
  });
});

describe('availableChannels', () => {
  it('lists only channels the lead can actually be reached on', () => {
    const lead = {
      ...base,
      whatsapp_url: 'https://wa.me/971501112233',
      instagram: 'https://instagram.com/acmegym',
    };
    expect(availableChannels(lead)).toEqual(['whatsapp', 'instagram']);
  });

  it('is empty for a lead with no contact details at all', () => {
    expect(availableChannels(base)).toEqual([]);
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd admin && npx vitest run src/lib/channels.test.ts
```

Expected: FAIL — cannot resolve `./channels`.

- [ ] **Step 3: Add the types**

In `admin/src/types.ts`, in the Lead Finder section after the `LeadStatus` definition (around line 204), add:

```ts
/** How a touch happened — mirrors the backend LeadActivity::CHANNELS. */
export const LEAD_CHANNELS = [
  'whatsapp', 'instagram', 'facebook', 'tiktok', 'linkedin',
  'phone', 'email', 'walk_in', 'other',
] as const;
export type LeadChannel = (typeof LEAD_CHANNELS)[number];

/** `out` = we contacted them, `in` = they contacted us. */
export type TouchDirection = 'out' | 'in';
```

In the `Lead` type, after `website?: string | null;` (line 265), add:

```ts
  instagram?: string | null;
  facebook?: string | null;
  tiktok?: string | null;
  linkedin?: string | null;
  email?: string | null;
```

Replace the `LeadActivity` type (lines 306-312) with:

```ts
/** One row in a lead's activity history (status changes, notes, contacts). */
export type LeadActivity = {
  id: number;
  type: 'status_change' | 'note' | 'contacted' | 'assigned' | string;
  /** Set on `contacted` rows only. Null on rows logged before channels existed. */
  channel?: LeadChannel | null;
  direction?: TouchDirection | null;
  payload?: { from?: string; to?: string; note?: string; from_name?: string; to_name?: string } | null;
  created_at?: string | null;
};
```

- [ ] **Step 4: Create `admin/src/lib/channels.ts`**

```ts
import type { Lead, LeadChannel } from '@/types';
import { LEAD_CHANNELS } from '@/types';

/**
 * One place defining what a channel looks like, so the lead timeline and the
 * dashboard chart can never disagree about a channel's name or colour.
 * Colours are the existing leads.css / theme tokens.
 */
export const CHANNEL_META: Record<LeadChannel, { label: string; color: string }> = {
  whatsapp: { label: 'WhatsApp', color: 'var(--mint-500)' },
  instagram: { label: 'Instagram', color: 'var(--violet)' },
  facebook: { label: 'Facebook', color: 'var(--info)' },
  tiktok: { label: 'TikTok', color: 'var(--text-2)' },
  linkedin: { label: 'LinkedIn', color: 'var(--mint-300)' },
  phone: { label: 'Phone', color: 'var(--warn)' },
  email: { label: 'Email', color: 'var(--info)' },
  walk_in: { label: 'Walk-in', color: 'var(--warn)' },
  other: { label: 'Other', color: 'var(--text-4)' },
};

/** Label for a channel key, including the report's synthetic `unattributed`. */
export function channelLabel(key: string): string {
  if (key === 'unattributed') return 'Unattributed';
  return CHANNEL_META[key as LeadChannel]?.label ?? key;
}

export function channelColor(key: string): string {
  if (key === 'unattributed') return 'var(--text-4)';
  return CHANNEL_META[key as LeadChannel]?.color ?? 'var(--text-4)';
}

/**
 * Where tapping this channel should take you, or null when the lead has no
 * handle for it. walk_in and other are never links — they are logged, not opened.
 */
export function channelHref(lead: Lead, channel: LeadChannel): string | null {
  switch (channel) {
    case 'whatsapp': return lead.whatsapp_url ?? null;
    case 'phone': return lead.tel_url ?? null;
    case 'email': return lead.email ? `mailto:${lead.email}` : null;
    case 'instagram': return lead.instagram ?? null;
    case 'facebook': return lead.facebook ?? null;
    case 'tiktok': return lead.tiktok ?? null;
    case 'linkedin': return lead.linkedin ?? null;
    default: return null;
  }
}

/** The channels this lead can actually be reached on right now, in list order. */
export function availableChannels(lead: Lead): LeadChannel[] {
  return LEAD_CHANNELS.filter((channel) => channelHref(lead, channel) !== null);
}
```

- [ ] **Step 5: Add the API client functions**

In `admin/src/lib/leads.ts`, replace the `logFollowup` function (lines 170-174) with:

```ts
/** Record one contact touch; logs a `contacted` activity with its channel. */
export async function logTouch(
  id: number,
  channel: LeadChannel,
  direction: TouchDirection = 'out',
  note?: string,
): Promise<Lead> {
  const { data } = await api.post(`/shop/leads/${id}/touch`, { channel, direction, note });
  return data?.data ?? data;
}

/** Edit a lead's contact details. Status/assignment/deal have their own endpoints. */
export async function updateLead(
  id: number,
  fields: Partial<Pick<Lead, 'phone' | 'whatsapp' | 'website' | 'notes' | 'instagram' | 'facebook' | 'tiktok' | 'linkedin' | 'email'>>,
): Promise<Lead> {
  const { data } = await api.patch(`/shop/leads/${id}`, fields);
  return data?.data ?? data;
}
```

Add `LeadChannel` and `TouchDirection` to the type import at the top of the file.

Then extend `updateLeadStatus` (line ~165) to accept and forward a reply channel — add a 5th parameter and include it in the body:

```ts
  replyChannel?: LeadChannel,
): Promise<Lead> {
  const { data } = await api.patch(`/shop/leads/${id}/status`, {
    status, note, ...(deal ?? {}), ...(replyChannel ? { reply_channel: replyChannel } : {}),
  });
  return data?.data ?? data;
}
```

- [ ] **Step 6: Add the report row type**

In `admin/src/lib/huntInsights.ts`, add after `HuntAgentRow`:

```ts
export type HuntChannelRow = {
  /** A LeadChannel, or the synthetic `unattributed` bucket. */
  channel: string;
  touches: number;
  replies: number;
  won: number;
  won_value: number;
};
```

Add `channels: HuntChannelRow[];` to the `HuntInsights` type, and in `getHuntInsights`'s return object add:

```ts
    channels: Array.isArray(data?.channels) ? data.channels : [],
```

- [ ] **Step 7: Run the test to verify it passes**

```bash
cd admin && npx vitest run src/lib/channels.test.ts
```

Expected: PASS — 8 tests.

- [ ] **Step 8: Typecheck and commit**

```bash
cd admin && npx tsc --noEmit
```

Expected: no errors (`LeadDetail.tsx` still calls `logFollowup`, so if `tsc` complains, leave the old `logFollowup` export in place until Task 11 removes its last caller).

```bash
git add admin/src/types.ts admin/src/lib/channels.ts admin/src/lib/channels.test.ts admin/src/lib/leads.ts admin/src/lib/huntInsights.ts
git commit -m "feat(admin): channel types, channel metadata, touch + lead-edit API clients"
```

---

## Task 11: Channel actions and timeline on the lead detail page

**Files:**
- Create: `admin/src/components/ChannelPicker.tsx`
- Modify: `admin/src/pages/LeadDetail.tsx` (`activityText` at :102-115, `sendFollowup` at :257-270, the `ld-actions` block at :472-490)
- Modify: `admin/src/styles/leads.css`
- Test: `admin/src/pages/LeadDetail.test.tsx` (append)

**Interfaces:**
- Consumes: `logTouch`, `CHANNEL_META`, `channelHref`, `availableChannels`, `channelLabel` (Task 10).
- Produces: `<ChannelPicker open onPick={(c: LeadChannel) => void} onClose={() => void} title={string} />`

- [ ] **Step 1: Write the failing test**

Append to `admin/src/pages/LeadDetail.test.tsx`, following the file's existing render/mock helpers:

```tsx
  it('offers an Instagram action for a lead with no mobile', async () => {
    renderLead({ id: 1, name: 'Acme', status: 'sent', is_mobile: false, instagram: 'https://instagram.com/acmegym' });

    expect(await screen.findByRole('button', { name: /instagram/i })).toBeInTheDocument();
  });

  it('does not offer channels the lead has no handle for', async () => {
    renderLead({ id: 1, name: 'Acme', status: 'sent', is_mobile: false, instagram: 'https://instagram.com/acmegym' });

    await screen.findByRole('button', { name: /instagram/i });
    expect(screen.queryByRole('button', { name: /^linkedin$/i })).not.toBeInTheDocument();
  });

  it('names the channel and direction in the timeline', async () => {
    renderLead(
      { id: 1, name: 'Acme', status: 'replied' },
      [
        { id: 2, type: 'contacted', channel: 'whatsapp', direction: 'in' },
        { id: 1, type: 'contacted', channel: 'instagram', direction: 'out' },
      ],
    );

    expect(await screen.findByText(/You messaged them on Instagram/i)).toBeInTheDocument();
    expect(screen.getByText(/They replied on WhatsApp/i)).toBeInTheDocument();
  });

  it('falls back to Contacted for a touch with no channel', async () => {
    renderLead({ id: 1, name: 'Acme', status: 'sent' }, [{ id: 1, type: 'contacted' }]);

    expect(await screen.findByText('Contacted')).toBeInTheDocument();
  });
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd admin && npx vitest run src/pages/LeadDetail.test.tsx
```

Expected: FAIL — no Instagram button; timeline says "Contacted".

- [ ] **Step 3: Create the ChannelPicker**

Create `admin/src/components/ChannelPicker.tsx`:

```tsx
import { LEAD_CHANNELS } from '@/types';
import type { LeadChannel } from '@/types';
import { CHANNEL_META } from '@/lib/channels';

type Props = {
  open: boolean;
  title: string;
  onPick: (channel: LeadChannel) => void;
  onClose: () => void;
};

/**
 * Small channel chooser, shared by "Log a touch", "They replied", and the move
 * to Replied — so the same nine options are offered wherever a channel is asked
 * for. Dismissing it is always allowed: an unknown channel is honest.
 */
export function ChannelPicker({ open, title, onPick, onClose }: Props) {
  if (!open) return null;

  return (
    <div className="cp-backdrop" role="dialog" aria-label={title} onClick={onClose}>
      <div className="cp-panel" onClick={(e) => e.stopPropagation()}>
        <h3 className="cp-title">{title}</h3>
        <div className="cp-grid">
          {LEAD_CHANNELS.map((channel) => (
            <button
              key={channel}
              type="button"
              className="cp-opt"
              style={{ ['--ch' as string]: CHANNEL_META[channel].color }}
              onClick={() => onPick(channel)}
            >
              {CHANNEL_META[channel].label}
            </button>
          ))}
        </div>
        <button type="button" className="cp-skip" onClick={onClose}>Skip</button>
      </div>
    </div>
  );
}
```

- [ ] **Step 4: Rewrite `activityText` in LeadDetail**

In `admin/src/pages/LeadDetail.tsx`, replace the `contacted` branch of `activityText` (line 109) with:

```tsx
  if (a.type === 'contacted') {
    // A touch logged before channels existed (or with an unknown channel) keeps
    // the original wording rather than claiming a channel it never recorded.
    if (!a.channel) return 'Contacted';
    const label = channelLabel(a.channel);
    return a.direction === 'in' ? `They replied on ${label}` : `You messaged them on ${label}`;
  }
```

Add to the imports:

```tsx
import { CHANNEL_META, availableChannels, channelHref, channelLabel } from '@/lib/channels';
import { ChannelPicker } from '@/components/ChannelPicker';
import type { LeadChannel } from '@/types';
```

Also update `activityColor` (line 52) so a touch is tinted by its channel:

```tsx
function activityColor(a: LeadActivity): string {
  if (a.type === 'status_change' && a.payload?.to) {
    return STAGE_COLOR[a.payload.to as LeadStatus] ?? 'var(--mint-300)';
  }
  if (a.type === 'contacted' && a.channel) {
    return CHANNEL_META[a.channel]?.color ?? 'var(--mint-300)';
  }
  return 'var(--mint-300)';
}
```

- [ ] **Step 5: Replace `sendFollowup` with channel-aware touch logging**

Replace `sendFollowup` (lines 257-270) with:

```tsx
  // Open the lead on this channel and record the touch. The log must not depend
  // on the window actually opening — a blocked popup should not lose the touch.
  const touchOn = async (channel: LeadChannel) => {
    if (!lead || locked) return;
    const href = channelHref(lead, channel);
    if (href) window.open(href, '_blank');

    setBusy(true); setError('');
    try {
      await logTouch(lead.id, channel, 'out');
      await load();
    } catch {
      setError('Could not log the touch.');
    } finally {
      setBusy(false);
    }
  };

  // They got back to us — possibly on a different channel than we sent on.
  const logReply = async (channel: LeadChannel) => {
    if (!lead || locked) return;
    setReplyPicker(false);
    setBusy(true); setError('');
    try {
      await logTouch(lead.id, channel, 'in');
      await load();
    } catch {
      setError('Could not log the reply.');
    } finally {
      setBusy(false);
    }
  };
```

Add the picker state next to the other `useState` calls:

```tsx
  const [touchPicker, setTouchPicker] = useState(false);
  const [replyPicker, setReplyPicker] = useState(false);
```

- [ ] **Step 6: Replace the actions block**

Replace the WhatsApp/Follow-up buttons (lines 473-482) with a channel row, and drop the `is_mobile` gate from Personalize (line 483):

```tsx
              {availableChannels(lead).map((channel) => (
                <button
                  key={channel}
                  type="button"
                  className="ld-act ch"
                  style={{ ['--ch' as string]: CHANNEL_META[channel].color }}
                  disabled={locked}
                  onClick={() => void touchOn(channel)}
                >
                  {CHANNEL_META[channel].label}
                </button>
              ))}
              <button type="button" className="ld-act" disabled={locked} onClick={() => setTouchPicker(true)}>
                Log a touch
              </button>
              <button type="button" className="ld-act" disabled={locked} onClick={() => setReplyPicker(true)}>
                They replied
              </button>
              {(lead.status === 'new' || lead.status === 'sent' || lead.status === 'followup' || lead.status === 'replied' || lead.status === 'demo') && (
                <button type="button" className="ld-act" disabled={aiBusy || locked} onClick={() => void personalize()}>
                  <Icons.Sparkle size={16} /> {aiBusy ? 'Writing…' : 'Personalize'}
                </button>
              )}
```

Then mount both pickers near the won-deal modal at the end of the component's JSX:

```tsx
      <ChannelPicker
        open={touchPicker}
        title="How did you contact them?"
        onPick={(channel) => { setTouchPicker(false); void touchOn(channel); }}
        onClose={() => setTouchPicker(false)}
      />
      <ChannelPicker
        open={replyPicker}
        title="How did they reply?"
        onPick={(channel) => void logReply(channel)}
        onClose={() => setReplyPicker(false)}
      />
```

- [ ] **Step 7: Add the styles**

Append to `admin/src/styles/leads.css`:

```css
/* Channel actions — tinted per channel from CHANNEL_META via --ch. */
.ld-act.ch { border-color: color-mix(in srgb, var(--ch) 45%, transparent); color: var(--ch); }
.ld-act.ch:hover:not(:disabled) { background: color-mix(in srgb, var(--ch) 12%, transparent); }

/* Channel picker */
.cp-backdrop { position: fixed; inset: 0; z-index: 60; display: grid; place-items: center;
  background: rgba(0, 0, 0, .45); backdrop-filter: blur(2px); }
.cp-panel { width: min(420px, calc(100vw - 32px)); padding: 20px; border-radius: var(--r-lg);
  background: var(--surface-1); border: 1px solid var(--border-1); }
.cp-title { margin: 0 0 14px; font-size: 15px; font-weight: 600; }
.cp-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 8px; }
.cp-opt { padding: 10px 8px; border-radius: 10px; font-size: 13px; cursor: pointer;
  background: transparent; border: 1px solid color-mix(in srgb, var(--ch) 45%, transparent); color: var(--ch); }
.cp-opt:hover { background: color-mix(in srgb, var(--ch) 12%, transparent); }
.cp-skip { margin-top: 14px; width: 100%; padding: 9px; border-radius: 10px; cursor: pointer;
  background: transparent; border: 1px solid var(--border-1); color: var(--text-3); }
```

These use the project's existing tokens (`--surface-1`, `--border-1`, `--text-3`, `--r-lg` — all defined in `admin/src/styles/tokens.css` and used throughout `hunt-insights.css`). Do not introduce new tokens.

- [ ] **Step 8: Run the test to verify it passes**

```bash
cd admin && npx vitest run src/pages/LeadDetail.test.tsx
```

Expected: PASS, including the pre-existing tests in that file.

- [ ] **Step 9: Commit**

```bash
git add admin/src/pages/LeadDetail.tsx admin/src/components/ChannelPicker.tsx admin/src/styles/leads.css admin/src/pages/LeadDetail.test.tsx
git commit -m "feat(admin): per-channel lead actions, reply logging, channel-aware timeline"
```

---

## Task 12: Contact details editor and the Replied channel prompt

**Files:**
- Create: `admin/src/components/ContactDetails.tsx`
- Create: `admin/src/components/ContactDetails.test.tsx`
- Modify: `admin/src/pages/LeadDetail.tsx` (mount the editor; add the reply prompt to the Replied status move)

**Interfaces:**
- Consumes: `updateLead`, `updateLeadStatus` (Task 10), `ChannelPicker` (Task 11).
- Produces: `<ContactDetails lead={Lead} canEdit={boolean} onSaved={(lead: Lead) => void} />`

- [ ] **Step 1: Write the failing test**

Create `admin/src/components/ContactDetails.test.tsx`:

```tsx
import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { ContactDetails } from './ContactDetails';
import * as leads from '@/lib/leads';
import type { Lead } from '@/types';

const lead: Lead = { id: 7, name: 'Acme', status: 'sent' };

beforeEach(() => vi.restoreAllMocks());

describe('ContactDetails', () => {
  it('saves an edited handle', async () => {
    const spy = vi.spyOn(leads, 'updateLead').mockResolvedValue({ ...lead, instagram: 'https://instagram.com/acmegym' });
    render(<ContactDetails lead={lead} canEdit onSaved={() => {}} />);

    await userEvent.type(screen.getByLabelText(/instagram/i), '@acmegym');
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    await waitFor(() => expect(spy).toHaveBeenCalledWith(7, expect.objectContaining({ instagram: '@acmegym' })));
  });

  it('surfaces a server rejection against the field', async () => {
    vi.spyOn(leads, 'updateLead').mockRejectedValue({
      response: { status: 422, data: { errors: { instagram: ["That doesn't look like a valid instagram handle or link."] } } },
    });
    render(<ContactDetails lead={lead} canEdit onSaved={() => {}} />);

    await userEvent.type(screen.getByLabelText(/instagram/i), 'https://example.com/x');
    await userEvent.click(screen.getByRole('button', { name: /save/i }));

    expect(await screen.findByText(/doesn't look like a valid instagram/i)).toBeInTheDocument();
  });

  it('renders read-only without permission', () => {
    render(<ContactDetails lead={lead} canEdit={false} onSaved={() => {}} />);

    expect(screen.queryByRole('button', { name: /save/i })).not.toBeInTheDocument();
  });
});
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd admin && npx vitest run src/components/ContactDetails.test.tsx
```

Expected: FAIL — cannot resolve `./ContactDetails`.

- [ ] **Step 3: Create the editor**

Create `admin/src/components/ContactDetails.tsx`:

```tsx
import { useState } from 'react';
import { updateLead } from '@/lib/leads';
import type { Lead } from '@/types';

const FIELDS: { key: keyof Lead; label: string; placeholder: string }[] = [
  { key: 'phone', label: 'Phone', placeholder: '050 111 2233' },
  { key: 'whatsapp', label: 'WhatsApp', placeholder: '050 111 2233' },
  { key: 'email', label: 'Email', placeholder: 'owner@business.ae' },
  { key: 'instagram', label: 'Instagram', placeholder: '@handle or link' },
  { key: 'facebook', label: 'Facebook', placeholder: '@handle or link' },
  { key: 'tiktok', label: 'TikTok', placeholder: '@handle or link' },
  { key: 'linkedin', label: 'LinkedIn', placeholder: 'company link' },
  { key: 'website', label: 'Website', placeholder: 'https://…' },
];

type Props = { lead: Lead; canEdit: boolean; onSaved: (lead: Lead) => void };

/**
 * Contact details for a lead. Handles are sent raw — the server normalizes them
 * (one implementation, so the SPA and the voice path cannot disagree) and
 * returns 422 per field when a value cannot be interpreted.
 */
export function ContactDetails({ lead, canEdit, onSaved }: Props) {
  const [draft, setDraft] = useState<Record<string, string>>(
    Object.fromEntries(FIELDS.map((f) => [f.key, (lead[f.key] as string) ?? ''])),
  );
  const [errors, setErrors] = useState<Record<string, string>>({});
  const [busy, setBusy] = useState(false);

  const save = async () => {
    setBusy(true); setErrors({});
    try {
      const saved = await updateLead(lead.id, draft as Parameters<typeof updateLead>[1]);
      onSaved(saved);
    } catch (e) {
      const raw = (e as { response?: { data?: { errors?: Record<string, string[]> } } })?.response?.data?.errors;
      setErrors(Object.fromEntries(Object.entries(raw ?? {}).map(([k, v]) => [k, v[0]])));
    } finally {
      setBusy(false);
    }
  };

  return (
    <section className="ld-contact">
      <h3 className="ld-contact-title">Contact details</h3>
      <div className="ld-contact-grid">
        {FIELDS.map((field) => (
          <label key={String(field.key)} className="ld-contact-row">
            <span>{field.label}</span>
            <input
              aria-label={field.label}
              value={draft[field.key as string] ?? ''}
              placeholder={field.placeholder}
              disabled={!canEdit || busy}
              onChange={(e) => setDraft({ ...draft, [field.key as string]: e.target.value })}
            />
            {errors[field.key as string] && (
              <em className="ld-contact-err">{errors[field.key as string]}</em>
            )}
          </label>
        ))}
      </div>
      {canEdit && (
        <button type="button" className="ld-act" disabled={busy} onClick={() => void save()}>
          {busy ? 'Saving…' : 'Save'}
        </button>
      )}
    </section>
  );
}
```

- [ ] **Step 4: Mount it and add the Replied prompt in LeadDetail**

In `admin/src/pages/LeadDetail.tsx`, import it:

```tsx
import { ContactDetails } from '@/components/ContactDetails';
```

Render it below the actions block:

```tsx
            <ContactDetails lead={lead} canEdit={mayManage} onSaved={() => void load()} />
```

Then make the move to `replied` ask for the channel, mirroring exactly how `won` already defers to `wonModal`.

Add the state beside `wonModal` (`LeadDetail.tsx:145`):

```tsx
  const [replyModal, setReplyModal] = useState(false);
```

Give `commitStatus` (`LeadDetail.tsx:205`) a third parameter and forward it:

```tsx
  const commitStatus = async (status: LeadStatus, deal?: DealInput, replyChannel?: LeadChannel) => {
```

and inside it change the call to:

```tsx
      await updateLeadStatus(lead.id, status, undefined, deal, replyChannel);
```

In `setStatus` (`LeadDetail.tsx:195`), intercept `replied` the same way `won` is intercepted — set `setReplyModal(true)` and return instead of committing.

Render the picker next to the won modal (`LeadDetail.tsx:387`):

```tsx
      <ChannelPicker
        open={replyModal}
        title="How did they reply?"
        onPick={(channel) => { setReplyModal(false); void commitStatus('replied', undefined, channel); }}
        onClose={() => { setReplyModal(false); void commitStatus('replied'); }}
      />
```

Dismissing must still commit the status change — an unknown channel is honest, and blocking the funnel move on it would be worse than recording nothing.

- [ ] **Step 5: Run the tests to verify they pass**

```bash
cd admin && npx vitest run src/components/ContactDetails.test.tsx src/pages/LeadDetail.test.tsx
```

Expected: PASS.

- [ ] **Step 6: Commit**

```bash
git add admin/src/components/ContactDetails.tsx admin/src/components/ContactDetails.test.tsx admin/src/pages/LeadDetail.tsx
git commit -m "feat(admin): contact-details editor and reply-channel prompt on Replied"
```

---

## Task 13: "Which channel works" dashboard card

**Files:**
- Modify: `admin/src/components/HuntDashboard.tsx` (add a `Channels` function after `Leaderboard` at :91-112; render it in the `ins-grid` block at :242-263)
- Modify: `admin/src/styles/hunt-insights.css`
- Modify: `admin/src/components/HuntDashboard.test.tsx` (append)

**Interfaces:**
- Consumes: `HuntChannelRow`, `channels` (Task 10), `channelLabel`/`channelColor` (Task 10).
- Produces: no exports beyond the dashboard's own rendering.

The dashboard's existing conventions, which this must follow rather than invent around: sections are wrapped in `<ChartCard icon title sub>`, side-by-side cards live inside `<div className="ins-grid">`, Hunt-specific classes use the `hi-` prefix, shared chart classes use `ins-`, and empty states render `<div className="ins-empty"><span className="ins-empty-txt">…</span></div>`.

- [ ] **Step 1: Write the failing test**

Append to `admin/src/components/HuntDashboard.test.tsx`, following its existing mock/render helpers. Rows are located by `data-testid` rather than by card role, because `ChartCard` renders no landmark:

```tsx
  it('ranks channels by wins, then replies, then touches', async () => {
    renderDashboard({
      channels: [
        { channel: 'whatsapp', touches: 10, replies: 2, won: 1, won_value: 500 },
        { channel: 'instagram', touches: 4, replies: 3, won: 3, won_value: 4500 },
        { channel: 'tiktok', touches: 0, replies: 0, won: 0, won_value: 0 },
        { channel: 'unattributed', touches: 0, replies: 0, won: 2, won_value: 900 },
      ],
    });

    const names = (await screen.findAllByTestId('hi-channel-name')).map((n) => n.textContent);

    // Instagram (3 wins) outranks WhatsApp (1); unattributed is always last.
    expect(names[0]).toMatch(/Instagram/i);
    expect(names[1]).toMatch(/WhatsApp/i);
    expect(names[names.length - 1]).toMatch(/Unattributed/i);
  });

  it('always shows the unattributed bucket, even at zero', async () => {
    renderDashboard({
      channels: [{ channel: 'unattributed', touches: 0, replies: 0, won: 0, won_value: 0 }],
    });

    const names = (await screen.findAllByTestId('hi-channel-name')).map((n) => n.textContent);
    expect(names).toEqual(['Unattributed']);
  });

  it('hides channels with no activity at all', async () => {
    renderDashboard({
      channels: [
        { channel: 'whatsapp', touches: 3, replies: 0, won: 0, won_value: 0 },
        { channel: 'tiktok', touches: 0, replies: 0, won: 0, won_value: 0 },
      ],
    });

    const names = (await screen.findAllByTestId('hi-channel-name')).map((n) => n.textContent);
    expect(names).toContain('WhatsApp');
    expect(names).not.toContain('TikTok');
  });
```

If `renderDashboard` in that file does not already let you override the `getHuntInsights` payload per test, extend its existing mock to merge the passed partial over the default — do not introduce a second mocking style.

- [ ] **Step 2: Run the test to verify it fails**

```bash
cd admin && npx vitest run src/components/HuntDashboard.test.tsx
```

Expected: FAIL — no elements with testid `hi-channel-name`.

- [ ] **Step 3: Add the `Channels` component**

In `admin/src/components/HuntDashboard.tsx`, add after the `Leaderboard` function (ends line 112):

```tsx
/**
 * Which channel actually produces wins. Ranked by wins, then replies, then
 * touches. Channels with no activity at all are hidden to keep the card
 * readable — but `unattributed` is always shown, and always last, so wins that
 * belong to no channel can never silently vanish from the comparison.
 */
function Channels({ rows }: { rows: Data['channels'] }) {
  const unattributed = rows.find((r) => r.channel === 'unattributed');
  const active = rows
    .filter((r) => r.channel !== 'unattributed')
    .filter((r) => r.touches > 0 || r.replies > 0 || r.won > 0)
    .sort((a, b) => b.won - a.won || b.replies - a.replies || b.touches - a.touches);

  // `unattributed` is ALWAYS shown when present, even at zero, and always last.
  // It is the guard against silently under-counting wins — a win that belongs
  // to no channel has to stay visible, or the comparison quietly lies.
  const shown = unattributed ? [...active, unattributed] : active;

  if (shown.length === 0) {
    return (
      <div className="ins-empty">
        <span className="ins-empty-txt">No touches logged in this range yet.</span>
      </div>
    );
  }

  const max = Math.max(1, ...shown.map((r) => r.touches));

  return (
    <ul className="hi-channels">
      {shown.map((row) => (
        <li key={row.channel} className="hi-channel" style={{ ['--ch' as string]: channelColor(row.channel) }}>
          <span className="hi-channel-name" data-testid="hi-channel-name">{channelLabel(row.channel)}</span>
          <span className="hi-channel-track" aria-hidden>
            <span className="hi-channel-fill" style={{ width: `${Math.round((row.touches / max) * 100)}%` }} />
          </span>
          <span className="hi-channel-stats">
            {fmtNum(row.touches)} sent · {fmtNum(row.replies)} replies · <strong>{fmtNum(row.won)} won</strong>
          </span>
        </li>
      ))}
    </ul>
  );
}
```

Add to the imports at the top of the file:

```tsx
import { channelColor, channelLabel } from '@/lib/channels';
```

- [ ] **Step 4: Render it in the grid**

In the `ins-grid` block, after the agent-leaderboard `ChartCard` (line ~262), add:

```tsx
              <ChartCard icon="Chart" title="Which channel works" sub="Ranked by wins in this range">
                <Channels rows={data.channels} />
              </ChartCard>
```

No extra permission gate: it renders inside the same Hunt dashboard body that is already gated, and `huntByChannel` applies `agentLeadFilter()` server-side, so an agent sees only their own leads.

- [ ] **Step 5: Add the styles**

Append to `admin/src/styles/hunt-insights.css`:

```css
/* --- Channel breakdown ----------------------------------------------------- */
.hi-channels { list-style: none; margin: 0; padding: 0; display: grid; gap: 10px; }
.hi-channel {
  display: grid;
  grid-template-columns: 84px 1fr auto;
  align-items: center;
  gap: 10px;
  font-size: 13px;
}
.hi-channel-name { color: var(--ch); font-weight: 600; }
.hi-channel-track {
  height: 6px;
  border-radius: 999px;
  background: color-mix(in srgb, var(--ch) 15%, transparent);
  overflow: hidden;
}
.hi-channel-fill { display: block; height: 100%; background: var(--ch); }
.hi-channel-stats { color: var(--text-3); white-space: nowrap; }

@media (max-width: 480px) {
  .hi-channel { grid-template-columns: 72px 1fr; }
  .hi-channel-stats { grid-column: 2; font-size: 12px; }
}
```

- [ ] **Step 6: Run the full frontend suite**

```bash
cd admin && npx vitest run
```

Expected: green, including the dashboard's pre-existing tests.

- [ ] **Step 7: Typecheck, build, commit**

```bash
cd admin && npx tsc --noEmit && npx vite build
```

```bash
git add admin/src/components/HuntDashboard.tsx admin/src/components/HuntDashboard.test.tsx admin/src/styles/hunt-insights.css
git commit -m "feat(admin): which-channel-works card on the Hunt dashboard"
```

---

## Task 14: Deploy, then delete the deprecated alias

**Files:**
- Modify: `app/Http/Controllers/LeadController.php` (remove `logFollowup`)
- Modify: `routes/api.php` (remove the `/followup` route)
- Modify: `tests/Feature/LeadTouchTest.php` (remove the alias test)
- Delete: `tests/Feature/LeadFollowupTest.php` (fully superseded by `LeadTouchTest`)

**Interfaces:**
- Consumes: everything above.
- Produces: nothing new — this closes the deploy window.

- [ ] **Step 1: Run the full backend suite**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh"
```

Expected: all green. Do not proceed otherwise.

- [ ] **Step 2: Push and deploy the backend to staging**

```bash
git push origin main
ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && git fetch -q && git reset -q --hard origin/main && php artisan migrate --force && php artisan optimize:clear && php artisan route:cache'
```

- [ ] **Step 3: Confirm the migrations ran on staging**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && php artisan migrate:status | tail -5'
```

Expected: both `2026_07_27_*` migrations show `Ran`.

- [ ] **Step 4: Deploy the admin SPA to staging**

```bash
cd admin && powershell -File ./deploy-staging.ps1
```

- [ ] **Step 5: Verify on staging by hand**

Open a lead on staging and confirm, in order:

1. A lead with only an Instagram handle shows a working Instagram action (this is the regression the feature exists to fix).
2. Tapping it opens Instagram and adds "You messaged them on Instagram" to the timeline.
3. "They replied" → WhatsApp adds "They replied on WhatsApp" and does **not** change the lead's last-contacted date.
4. Moving a lead to Replied prompts for a channel, and dismissing still moves it.
5. Pasting `@somehandle` into Instagram in Contact details saves as a full link; pasting a non-Instagram URL shows an inline error.
6. The Hunt dashboard shows the "Which channel works" card.

Do not proceed until all six pass.

- [ ] **Step 6: Remove the deprecated alias**

Delete the `logFollowup` method from `app/Http/Controllers/LeadController.php`, delete the `/shop/leads/{lead}/followup` route line from `routes/api.php`, delete `tests/Feature/LeadFollowupTest.php`, and delete `test_the_deprecated_followup_alias_still_logs_whatsapp_out` from `tests/Feature/LeadTouchTest.php`.

- [ ] **Step 7: Verify nothing still calls it**

```bash
grep -rn "followup" --include=*.php --include=*.ts --include=*.tsx app routes admin/src | grep -v "next_followup_at\|followups\|'followup'\|\"followup\"\|DEFAULT_FOLLOWUP\|whatsapp_followup_url\|log_followup"
```

Expected: no hits referencing the removed route.

- [ ] **Step 8: Run the full suite and commit**

```bash
bash "C:/Users/franc/AppData/Local/Temp/claude/D--Francis-projects-2026-Eloquent-Solutions-Business-Lens/75157662-274f-40c7-b75d-94999abb1ead/scratchpad/synctest.sh"
```

```bash
git add -A
git commit -m "chore(hunt): drop the deprecated /followup alias now the SPA ships /touch"
git push origin main
```

- [ ] **Step 9: Promote to production**

Only after staging has been exercised and is good:

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && git fetch -q && git reset -q --hard origin/main && php artisan migrate --force && php artisan optimize:clear && php artisan route:cache'
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && php artisan migrate:status | tail -3'
```

```bash
cd admin && powershell -File ./deploy.ps1
```

- [ ] **Step 10: Verify production**

Repeat the six staging checks against production, on a real lead. Confirm the backfill landed:

```bash
ssh root@64.227.153.90 "cd /var/www/eloquent-backend && php artisan tinker --execute=\"echo App\\Models\\LeadActivity::where('type','contacted')->whereNull('channel')->count();\""
```

Expected: `0` — every historical touch carries `whatsapp`.

---

## Notes for the implementer

- **The one rule that is easy to get wrong:** `direction: in` must not bump `last_contacted_at`. Task 4 Step 1 asserts it directly. If that test is ever "fixed" by making it pass the other way, stale-lead detection quietly breaks.
- **`unattributed` is not a placeholder.** It is a real bucket that must always render, including at zero. A win with no logged outbound touch has to go somewhere visible, or the channel columns under-count wins without saying so.
- **Do not default an unknown channel to `whatsapp` anywhere.** That is the original bug. Null is the correct value for "we don't know".
- **Existing helper names matter.** Tasks 8, 11, 12 and 13 append to existing test files — reuse their current setup helpers rather than introducing a second convention in the same file.
