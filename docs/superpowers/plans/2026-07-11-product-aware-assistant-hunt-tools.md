# Product-aware Assistant + Business Hunt Tools Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Make the owner voice/text assistant product-aware — gate every tool module by the shop's enabled `modules`, add a real Business Hunt (`leads`) tool set, and compose the system prompt from per-module sections.

**Architecture:** A new `moduleKey()` tag on each assistant tool module lets `AssistantToolRegistry` filter tools by `Shop::hasModule()`. Two new Hunt modules (a non-mutating read module and a confirm-gated mutating module, mirroring the existing `OwnerAssistantTools`/`BookingTools` split) add lead search, save, pipeline and status tools. `AssistantPrompt::for()` composes shared + bookings + hunt sections by module.

**Tech Stack:** Laravel (PHP 8.4), Eloquent, PHPUnit, Anthropic tool-use schemas.

## Global Constraints

- **Tests run on the droplet only** (php8.4), against a **scratch DB** — never local (local PHP is broken), never the prod DB. See memory `run-tests-on-droplet` and `never-run-tests-against-prod-db`. Test commands below assume execution on the droplet.
- **Multi-tenant:** never hardcode one shop's identity. All lead/booking queries stay scoped via `Lead::forShop($shop->id)` / `where('shop_id', …)`.
- **Modules** are exactly `bookings` and `leads`. `Shop::hasModule(string): bool` and `$shop->is_master` already exist. Master shops get every module.
- **Leads has no RBAC permission** — Hunt tools map to permission `null` (no check), matching the module-gated `/leads` routes.
- Work directly on `main`; commit after each task (memory `no-feature-branches`). Do NOT deploy — promotion to staging/prod is a separate, explicit step.

---

### Task 1: Module-key gating in the registry

Add a `moduleKey()` tag to every tool module and make the registry filter tools by the shop's modules.

**Files:**
- Modify: `app/Services/Assistant/Contracts/AssistantToolModule.php`
- Modify: `app/Services/Assistant/Support/AssistantModule.php`
- Modify: `app/Services/Assistant/OwnerAssistantTools.php`
- Modify: `app/Services/Assistant/Modules/BookingTools.php`, `ServiceTools.php`, `CategoryTools.php`, `StaffTools.php`, `HoursTools.php`, `CustomerTools.php`
- Modify: `app/Services/Assistant/AssistantToolRegistry.php`
- Modify: `app/Http/Controllers/OwnerAssistantController.php:123`
- Test: `tests/Feature/AssistantToolRegistryTest.php`

**Interfaces:**
- Produces: `AssistantToolModule::moduleKey(): ?string` (`null` = universal, always on; `'bookings'` / `'leads'` = product-gated). `AssistantToolRegistry::defs(?Shop $shop = null): array` and `execute(Shop $shop, string $tool, array $input): string` filter by `activeModules(?Shop $shop)`.
- Consumes: `Shop::hasModule(string): bool`, `Shop::$is_master`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AssistantToolRegistryTest.php`:

```php
public function test_leads_only_shop_excludes_booking_tools(): void
{
    $shop = Shop::create(['name' => 'H', 'shop_code' => '7002', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
    $names = array_column(app(AssistantToolRegistry::class)->defs($shop), 'name');
    $this->assertNotContains('create_booking', $names);
    $this->assertNotContains('get_revenue', $names);
    $this->assertContains('get_business_profile', $names); // universal module stays
}

public function test_bookings_only_shop_keeps_booking_tools(): void
{
    $shop = Shop::create(['name' => 'B', 'shop_code' => '7003', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings']]);
    $names = array_column(app(AssistantToolRegistry::class)->defs($shop), 'name');
    $this->assertContains('create_booking', $names);
    $this->assertContains('get_revenue', $names);
    $this->assertContains('get_business_profile', $names);
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AssistantToolRegistryTest`
Expected: FAIL — `defs()` takes no argument yet (`get_revenue` present for a leads shop).

- [ ] **Step 3: Add `moduleKey()` to the contract**

In `app/Services/Assistant/Contracts/AssistantToolModule.php`, add to the interface:

```php
    /** Product module this tool belongs to: null = universal, else 'bookings'|'leads'. */
    public function moduleKey(): ?string;
```

- [ ] **Step 4: Default `moduleKey()` on the base + tag the bookings modules**

In `app/Services/Assistant/Support/AssistantModule.php`, add (default universal):

```php
    public function moduleKey(): ?string
    {
        return null;
    }
```

`OwnerAssistantTools` implements the interface directly (not via the base), so add the method to `app/Services/Assistant/OwnerAssistantTools.php`:

```php
    public function moduleKey(): ?string
    {
        return 'bookings';
    }
```

Add this same `'bookings'` override to each of `BookingTools.php`, `ServiceTools.php`, `CategoryTools.php`, `StaffTools.php`, `HoursTools.php`, `CustomerTools.php`:

```php
    public function moduleKey(): ?string
    {
        return 'bookings';
    }
```

Leave `ProfileTools` and `AccessTools` untouched — they inherit `null` (universal) from the base.

- [ ] **Step 5: Make the registry shop-aware**

In `app/Services/Assistant/AssistantToolRegistry.php`, add the `Shop` import and replace `activeModules()`, `defs()`, and the `execute()` loop:

```php
use App\Models\Shop;
```

```php
    /** @return array<int, AssistantToolModule> */
    protected function activeModules(?Shop $shop): array
    {
        $mutationsOn = (bool) config('assistant.mutations_enabled', true);

        return array_values(array_filter($this->modules(), function (AssistantToolModule $m) use ($mutationsOn, $shop) {
            // Global kill-switch: hide every data-changing module when off.
            if (! $mutationsOn && $m instanceof MutatingTool) {
                return false;
            }
            // Product gate: universal (null) modules and master shops see all;
            // otherwise the shop must have the module enabled. A null shop
            // (no context, e.g. a bare defs() call in tests) sees everything.
            $key = $m->moduleKey();
            if ($key === null || $shop === null || $shop->is_master) {
                return true;
            }
            return $shop->hasModule($key);
        }));
    }

    /** @return array<int, array<string, mixed>> */
    public function defs(?Shop $shop = null): array
    {
        $defs = [];
        foreach ($this->activeModules($shop) as $module) {
            foreach ($module->toolDefs() as $def) {
                $defs[] = $def;
            }
        }
        return $defs;
    }
```

In `execute()`, change the loop to pass the shop:

```php
        foreach ($this->activeModules($shop) as $module) {
            if ($module->handles($tool)) {
                return json_encode($module->run($call), JSON_UNESCAPED_UNICODE);
            }
        }
```

- [ ] **Step 6: Pass the shop from the controller**

In `app/Http/Controllers/OwnerAssistantController.php`, line ~123, change `$this->registry->defs(),` to:

```php
                $this->registry->defs($shop),
```

- [ ] **Step 7: Run tests to verify they pass**

Run: `php artisan test --filter=AssistantToolRegistryTest`
Expected: PASS — all cases including the pre-existing `defs()`/kill-switch tests (they call `defs()` with no arg → null shop → all modules).

- [ ] **Step 8: Commit**

```bash
git add app/Services/Assistant tests/Feature/AssistantToolRegistryTest.php app/Http/Controllers/OwnerAssistantController.php
git commit -m "feat(assistant): gate tool modules by the shop's product module"
```

---

### Task 2: Shared leads plumbing — `LeadImporter` + `LeadSearchService::cached()`

Extract the lead-persistence dedupe out of the controller into a reusable service, and add a credit-free cache-only search lookup. Both are consumed by the Hunt tools in Task 4.

**Files:**
- Create: `app/Services/Leads/LeadImporter.php`
- Modify: `app/Http/Controllers/LeadController.php` (constructor + `store()`)
- Modify: `app/Services/Leads/LeadSearchService.php` (add `cached()`)
- Test: `tests/Unit/LeadImporterTest.php`

**Interfaces:**
- Produces: `LeadImporter::import(Shop $shop, array $rows): array{saved: array<Lead>, created: int}`. `LeadSearchService::cached(string $query, ?string $area): ?array` (array of result rows, or `null` on cache miss — **never** spends a credit).
- Consumes: `Lead` (fillable `name,phone,whatsapp,website,address,category,lat,lng,source,external_ref,status`).

- [ ] **Step 1: Write the failing test**

Create `tests/Unit/LeadImporterTest.php`:

```php
<?php
namespace Tests\Unit;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Leads\LeadImporter;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LeadImporterTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'S', 'shop_code' => '7300', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
    }

    public function test_import_creates_new_leads_and_counts_created(): void
    {
        $shop = $this->shop();
        $out = app(LeadImporter::class)->import($shop, [
            ['name' => 'Gym One', 'external_ref' => 'g1', 'phone' => '0501112222'],
            ['name' => 'Gym Two', 'external_ref' => 'g2'],
        ]);

        $this->assertSame(2, $out['created']);
        $this->assertCount(2, $out['saved']);
        $this->assertSame(2, Lead::forShop($shop->id)->count());
        $this->assertSame('new', Lead::forShop($shop->id)->first()->status);
    }

    public function test_import_dedupes_on_external_ref(): void
    {
        $shop = $this->shop();
        $importer = app(LeadImporter::class);
        $importer->import($shop, [['name' => 'Gym One', 'external_ref' => 'g1']]);
        $out = $importer->import($shop, [['name' => 'Gym One (renamed)', 'external_ref' => 'g1']]);

        $this->assertSame(0, $out['created']); // updated, not cloned
        $this->assertSame(1, Lead::forShop($shop->id)->count());
        $this->assertSame('Gym One (renamed)', Lead::forShop($shop->id)->first()->name);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=LeadImporterTest`
Expected: FAIL — `App\Services\Leads\LeadImporter` does not exist.

- [ ] **Step 3: Create `LeadImporter`**

Create `app/Services/Leads/LeadImporter.php`:

```php
<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Shop;

/**
 * Persists discovered businesses as leads, deduping on (shop_id, external_ref)
 * so re-saving the same business updates rather than clones. Shared by
 * LeadController::store (bulk save from the UI) and the Hunt assistant's
 * save_leads tool, so both paths dedupe identically.
 */
class LeadImporter
{
    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return array{saved: array<int, Lead>, created: int}
     */
    public function import(Shop $shop, array $rows): array
    {
        $saved = [];
        $created = 0;

        foreach ($rows as $row) {
            $attrs = [
                'name' => $row['name'],
                'phone' => $row['phone'] ?? null,
                'whatsapp' => $row['whatsapp'] ?? ($row['phone'] ?? null),
                'website' => $row['website'] ?? null,
                'address' => $row['address'] ?? null,
                'category' => $row['category'] ?? null,
                'lat' => $row['lat'] ?? null,
                'lng' => $row['lng'] ?? null,
                'source' => $row['source'] ?? 'manual',
            ];

            if (! empty($row['external_ref'])) {
                $lead = Lead::firstOrNew([
                    'shop_id' => $shop->id,
                    'external_ref' => $row['external_ref'],
                ]);
                $lead->fill($attrs);
                if (! $lead->exists) {
                    $lead->status = 'new';
                }
                $lead->save();
            } else {
                $lead = Lead::create($attrs + [
                    'shop_id' => $shop->id,
                    'status' => 'new',
                ]);
            }

            if ($lead->wasRecentlyCreated) {
                $created++;
            }
            $saved[] = $lead;
        }

        return ['saved' => $saved, 'created' => $created];
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=LeadImporterTest`
Expected: PASS.

- [ ] **Step 5: Refactor `LeadController::store()` to use `LeadImporter`**

In `app/Http/Controllers/LeadController.php`, add the import and constructor dependency:

```php
use App\Services\Leads\LeadImporter;
```

```php
    public function __construct(
        private LeadSearchService $search,
        private AdLibraryService $adLibrary,
        private HuntCreditService $credits,
        private LeadImporter $importer,
    ) {
    }
```

Replace the body of `store()` after `$data = $request->validate([...]);` (the `$saved`/`$created` loop through the `return`) with:

```php
        $out = $this->importer->import($shop, $data['leads']);

        // `created` = rows actually inserted (re-saving an existing lead dedupes),
        // so the client can bump the funnel count accurately.
        return response()->json(['data' => $out['saved'], 'created' => $out['created']], 201);
```

Leave the `$request->validate([...])` block unchanged.

- [ ] **Step 6: Add `cached()` to `LeadSearchService`**

In `app/Services/Leads/LeadSearchService.php`, add this method (reuses the private `queryKey()` and `hydrateFromCache()`):

```php
    /**
     * Cache-only lookup of a prior search — returns the same result rows a live
     * search produced, or null on a cache miss. NEVER spends a credit; used to
     * recover a just-run search (e.g. to save its results) without re-billing.
     *
     * @return array<int, array<string, mixed>>|null
     */
    public function cached(string $query, ?string $area): ?array
    {
        $cached = DB::table('lead_search_cache')
            ->where('source', $this->source->key())
            ->where('query_key', $this->queryKey($query, $area))
            ->first();

        if (! $cached) {
            return null;
        }

        $refs = json_decode($cached->external_refs, true) ?: [];

        return $this->hydrateFromCache($refs);
    }
```

- [ ] **Step 7: Run the leads tests to verify no regression**

Run: `php artisan test --filter=LeadFinderTest && php artisan test --filter=LeadImporterTest`
Expected: PASS — the controller refactor is behaviour-preserving.

- [ ] **Step 8: Commit**

```bash
git add app/Services/Leads/LeadImporter.php app/Services/Leads/LeadSearchService.php app/Http/Controllers/LeadController.php tests/Unit/LeadImporterTest.php
git commit -m "refactor(leads): extract LeadImporter + add credit-free cached() lookup"
```

---

### Task 3: Hunt read tools module

A non-mutating `leads` module (survives the mutation kill-switch, like `OwnerAssistantTools`): credit balance, pipeline, find, and open-lead navigation. Plus a shared `ResolvesLeads` trait used again in Task 4.

**Files:**
- Create: `app/Services/Assistant/Support/ResolvesLeads.php`
- Create: `app/Services/Assistant/Modules/HuntReadTools.php`
- Modify: `app/Services/Assistant/AssistantToolRegistry.php` (constructor + `modules()`)
- Test: `tests/Feature/HuntAssistantToolsTest.php`

**Interfaces:**
- Produces: tools `hunt_credits`, `list_leads`, `find_lead`, `open_lead`. `ResolvesLeads::resolveLead(ToolCall $call): Lead|array` (a `Lead`, or a `notFound()`/`ambiguous()` array).
- Consumes: `HuntCreditService::balance(Shop): int`, `AssistantActions::navigate(string): void`, `Lead::STATUSES`, `Lead::forShop(int)`.

- [ ] **Step 1: Write the failing tests**

Create `tests/Feature/HuntAssistantToolsTest.php`:

```php
<?php
namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Assistant\AssistantToolRegistry;
use App\Services\Credits\HuntCreditService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HuntAssistantToolsTest extends TestCase
{
    use RefreshDatabase;

    private function leadsShop(): Shop
    {
        return Shop::create(['name' => 'Hunt Co', 'shop_code' => '7100', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
    }

    private function exec(Shop $shop, string $tool, array $input = []): array
    {
        return json_decode(app(AssistantToolRegistry::class)->execute($shop, $tool, $input), true);
    }

    public function test_leads_shop_exposes_hunt_read_tools(): void
    {
        $names = array_column(app(AssistantToolRegistry::class)->defs($this->leadsShop()), 'name');
        $this->assertContains('hunt_credits', $names);
        $this->assertContains('list_leads', $names);
        $this->assertContains('find_lead', $names);
        $this->assertContains('open_lead', $names);
    }

    public function test_bookings_only_shop_hides_hunt_tools(): void
    {
        $shop = Shop::create(['name' => 'B', 'shop_code' => '7101', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings']]);
        $names = array_column(app(AssistantToolRegistry::class)->defs($shop), 'name');
        $this->assertNotContains('hunt_credits', $names);
        $this->assertNotContains('search_businesses', $names);
    }

    public function test_hunt_credits_returns_balance(): void
    {
        $shop = $this->leadsShop();
        app(HuntCreditService::class)->grant($shop, 5);
        $this->assertSame(5, $this->exec($shop, 'hunt_credits')['credits']);
    }

    public function test_list_leads_returns_funnel_counts(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'A', 'status' => 'new']);
        Lead::create(['shop_id' => $shop->id, 'name' => 'B', 'status' => 'won']);
        $out = $this->exec($shop, 'list_leads');
        $this->assertSame(2, $out['total']);
        $this->assertSame(1, $out['funnel']['won']);
    }

    public function test_find_lead_returns_details(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'sent', 'phone' => '0501234567']);
        $out = $this->exec($shop, 'find_lead', ['name' => 'marina']);
        $this->assertSame('Marina Gym', $out['name']);
        $this->assertSame('sent', $out['status']);
    }

    public function test_find_lead_is_ambiguous_when_multiple_match(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'Gold Gym', 'status' => 'new']);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Gold Spa', 'status' => 'new']);
        $out = $this->exec($shop, 'find_lead', ['name' => 'Gold']);
        $this->assertTrue($out['ambiguous']);
    }

    public function test_find_lead_not_found(): void
    {
        $out = $this->exec($this->leadsShop(), 'find_lead', ['name' => 'nobody']);
        $this->assertSame('not_found', $out['error']);
    }
}
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=HuntAssistantToolsTest`
Expected: FAIL — Hunt tools not registered (`execute` returns `unknown_tool`).

- [ ] **Step 3: Create the `ResolvesLeads` trait**

Create `app/Services/Assistant/Support/ResolvesLeads.php`:

```php
<?php
namespace App\Services\Assistant\Support;

use App\Models\Lead;

/**
 * Shared fuzzy-by-name lead resolution for the Hunt assistant modules. Hosting
 * classes must extend AssistantModule (for notFound()/ambiguous()).
 */
trait ResolvesLeads
{
    /** @return Lead|array a Lead, or a notFound()/ambiguous() response array. */
    protected function resolveLead(ToolCall $call): Lead|array
    {
        $name = trim((string) $call->get('name'));
        if ($name === '') {
            return $this->notFound('lead');
        }

        $matches = Lead::forShop($call->shop->id)
            ->where('name', 'like', "%{$name}%")
            ->orderByDesc('id')->limit(6)->get();

        if ($matches->isEmpty()) {
            return $this->notFound('lead');
        }
        if ($matches->count() > 1) {
            return $this->ambiguous($matches->map(fn ($l) => ['name' => $l->name, 'status' => $l->status])->all());
        }

        return $matches->first();
    }
}
```

- [ ] **Step 4: Create `HuntReadTools`**

Create `app/Services/Assistant/Modules/HuntReadTools.php`:

```php
<?php
namespace App\Services\Assistant\Modules;

use App\Models\Lead;
use App\Services\Assistant\Support\AssistantActions;
use App\Services\Assistant\Support\AssistantModule;
use App\Services\Assistant\Support\ResolvesLeads;
use App\Services\Assistant\Support\ToolCall;
use App\Services\Credits\HuntCreditService;

/**
 * Owner-assistant Business Hunt READ tools (leads module). Non-mutating, so they
 * survive the assistant.mutations_enabled kill-switch — mirrors how the booking
 * read tools live in OwnerAssistantTools, separate from BookingTools.
 */
class HuntReadTools extends AssistantModule
{
    use ResolvesLeads;

    public function __construct(
        protected HuntCreditService $credits,
        protected AssistantActions $actions,
    ) {}

    public function moduleKey(): ?string
    {
        return 'leads';
    }

    protected function permissions(): array
    {
        // Leads has no RBAC permission (module-gated only) — null = no check.
        return [
            'hunt_credits' => null,
            'list_leads' => null,
            'find_lead' => null,
            'open_lead' => null,
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'hunt_credits' => ['credits' => $this->credits->balance($call->shop)],
            'list_leads' => $this->list($call),
            'find_lead' => $this->find($call),
            'open_lead' => $this->open($call),
            default => ['error' => 'unknown_tool'],
        };
    }

    private function list(ToolCall $call): array
    {
        $funnel = array_fill_keys(Lead::STATUSES, 0);
        foreach (
            Lead::forShop($call->shop->id)
                ->selectRaw('status, COUNT(*) as c')
                ->groupBy('status')
                ->pluck('c', 'status') as $st => $c
        ) {
            $funnel[$st] = (int) $c;
        }

        $result = ['total' => array_sum($funnel), 'funnel' => $funnel];

        // Optional status filter → up to 8 names for a spoken summary.
        $status = strtolower(trim((string) $call->get('status')));
        if ($status !== '') {
            if (! in_array($status, Lead::STATUSES, true)) {
                return ['error' => 'invalid_status'];
            }
            $result['leads'] = Lead::forShop($call->shop->id)
                ->where('status', $status)
                ->orderByDesc('id')->limit(8)
                ->pluck('name')->all();
        }

        return $result;
    }

    private function find(ToolCall $call): array
    {
        $lead = $this->resolveLead($call);
        if (is_array($lead)) {
            return $lead; // notFound / ambiguous
        }

        return [
            'name' => $lead->name,
            'status' => $lead->status,
            'phone' => $lead->phone,
            'whatsapp' => $lead->whatsapp,
            'category' => $lead->category,
            'address' => $lead->address,
            'last_contacted' => $lead->last_contacted_at?->toDateString(),
        ];
    }

    private function open(ToolCall $call): array
    {
        $lead = $this->resolveLead($call);
        if (is_array($lead)) {
            return $lead;
        }
        $this->actions->navigate("/leads/{$lead->id}");

        return ['opening' => true, 'name' => $lead->name];
    }

    public function toolDefs(): array
    {
        $name = ['name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).']];

        return [
            ['name' => 'hunt_credits', 'description' => 'The shop\'s current Business Hunt credit balance (1 credit = one live search).', 'input_schema' => ['type' => 'object', 'properties' => new \stdClass()]],
            ['name' => 'list_leads', 'description' => 'The lead pipeline: a total plus a count for each funnel stage (new, sent, replied, demo, won, pass). Pass a status to also get up to 8 lead names in that stage.', 'input_schema' => ['type' => 'object', 'properties' => [
                'status' => ['type' => 'string', 'enum' => Lead::STATUSES],
            ]]],
            ['name' => 'find_lead', 'description' => 'Look up one saved lead by business name and return its funnel status and contact details.', 'input_schema' => ['type' => 'object', 'properties' => $name, 'required' => ['name']]],
            ['name' => 'open_lead', 'description' => 'Open/show a lead\'s detail page for the owner in the app (redirects them to it). Use whenever the owner asks to open, show, view, or see a lead. Pass the business name.', 'input_schema' => ['type' => 'object', 'properties' => $name, 'required' => ['name']]],
        ];
    }
}
```

- [ ] **Step 5: Register `HuntReadTools` in the registry**

In `app/Services/Assistant/AssistantToolRegistry.php`, add the constructor param and the `modules()` entry:

```php
        protected \App\Services\Assistant\Modules\HuntReadTools $huntRead,
```

Add `$this->huntRead,` to the array returned by `modules()`.

- [ ] **Step 6: Run tests to verify they pass**

Run: `php artisan test --filter=HuntAssistantToolsTest`
Expected: PASS (the read-tool + gating cases; mutating cases arrive in Task 4).

- [ ] **Step 7: Commit**

```bash
git add app/Services/Assistant/Support/ResolvesLeads.php app/Services/Assistant/Modules/HuntReadTools.php app/Services/Assistant/AssistantToolRegistry.php tests/Feature/HuntAssistantToolsTest.php
git commit -m "feat(assistant): add Hunt read tools (credits, pipeline, find, open lead)"
```

---

### Task 4: Hunt mutating tools module

A confirm-gated `leads` module: run a credit-spending search, save results, and move a lead's funnel status.

**Files:**
- Create: `app/Services/Assistant/Modules/HuntTools.php`
- Modify: `app/Services/Assistant/AssistantToolRegistry.php` (constructor + `modules()`)
- Test: `tests/Feature/HuntAssistantToolsTest.php` (add methods)

**Interfaces:**
- Consumes: `LeadSearchService::search(Shop,string,?string): array{results,from_cache,credits}` and `::cached(string,?string): ?array` (Task 2), `SearchInterpreter::interpret(Shop,string,?string): array{keyword,area}`, `LeadImporter::import(Shop,array): array{saved,created}` (Task 2), `ResolvesLeads::resolveLead()` (Task 3), `MutatingTool::gate()/preview()/applied()`, `InsufficientCredits(public int $balance, public int $required)`, `LeadActivity::TYPE_STATUS_CHANGE`, `current_shop_user()`.
- Produces: tools `search_businesses`, `save_leads`, `update_lead_status`.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/HuntAssistantToolsTest.php` (add the imports at the top of the file):

```php
use App\Models\LeadActivity;
use App\Services\Credits\Exceptions\InsufficientCredits;
use App\Services\Leads\LeadSearchService;
use App\Services\Leads\SearchInterpreter;
use Mockery;
```

```php
    /** Bind non-network fakes for the search + interpreter, then resolve a fresh registry. */
    private function fakeSearch(callable $configure): void
    {
        $interp = Mockery::mock(SearchInterpreter::class);
        $interp->shouldReceive('interpret')->andReturn(['keyword' => 'gyms', 'area' => 'Dubai']);
        $this->app->instance(SearchInterpreter::class, $interp);

        $search = Mockery::mock(LeadSearchService::class);
        $configure($search);
        $this->app->instance(LeadSearchService::class, $search);
    }

    public function test_search_businesses_previews_then_confirms(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('search')->once()->andReturn([
                'results' => [['name' => 'Gym One', 'external_ref' => 'g1']],
                'from_cache' => false,
                'credits' => 4,
            ]);
        });

        $preview = $this->exec($shop, 'search_businesses', ['category' => 'gyms']);
        $this->assertTrue($preview['preview']);
        $this->assertFalse($preview['saved']);

        $done = $this->exec($shop, 'search_businesses', ['category' => 'gyms', 'confirmed' => true]);
        $this->assertTrue($done['done']);
        $this->assertSame(1, $done['count']);
        $this->assertSame(4, $done['credits_left']);
        $this->assertSame(['Gym One'], $done['sample']);
    }

    public function test_search_businesses_relays_insufficient_credits(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('search')->andThrow(new InsufficientCredits(0, 1));
        });

        $out = $this->exec($shop, 'search_businesses', ['category' => 'gyms', 'confirmed' => true]);
        $this->assertSame('insufficient_credits', $out['error']);
        $this->assertArrayNotHasKey('done', $out);
    }

    public function test_save_leads_persists_cached_results(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('cached')->andReturn([
                ['name' => 'Gym One', 'external_ref' => 'g1', 'phone' => '0501112222'],
                ['name' => 'Gym Two', 'external_ref' => 'g2'],
            ]);
        });

        $preview = $this->exec($shop, 'save_leads', ['category' => 'gyms', 'area' => 'Dubai']);
        $this->assertTrue($preview['preview']);
        $this->assertSame(0, Lead::forShop($shop->id)->count());

        $done = $this->exec($shop, 'save_leads', ['category' => 'gyms', 'area' => 'Dubai', 'confirmed' => true]);
        $this->assertTrue($done['done']);
        $this->assertSame(2, $done['created']);
        $this->assertSame(2, Lead::forShop($shop->id)->count());
    }

    public function test_save_leads_not_found_when_no_cache(): void
    {
        $shop = $this->leadsShop();
        $this->fakeSearch(function ($s) {
            $s->shouldReceive('cached')->andReturn(null);
        });

        $out = $this->exec($shop, 'save_leads', ['category' => 'gyms']);
        $this->assertSame('not_found', $out['error']);
    }

    public function test_update_lead_status_moves_funnel_and_logs_activity(): void
    {
        $shop = $this->leadsShop();
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'new']);

        $preview = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won']);
        $this->assertTrue($preview['preview']);
        $this->assertSame('new', $lead->fresh()->status);

        $done = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'won', 'confirmed' => true]);
        $this->assertTrue($done['done']);
        $this->assertSame('won', $lead->fresh()->status);
        $this->assertSame(1, $lead->activities()->where('type', LeadActivity::TYPE_STATUS_CHANGE)->count());
    }

    public function test_update_lead_status_rejects_invalid_status(): void
    {
        $shop = $this->leadsShop();
        Lead::create(['shop_id' => $shop->id, 'name' => 'Marina Gym', 'status' => 'new']);
        $out = $this->exec($shop, 'update_lead_status', ['name' => 'marina', 'status' => 'nonsense', 'confirmed' => true]);
        $this->assertSame('invalid_status', $out['error']);
    }
```

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=HuntAssistantToolsTest`
Expected: FAIL — `search_businesses`/`save_leads`/`update_lead_status` return `unknown_tool`.

- [ ] **Step 3: Create `HuntTools`**

Create `app/Services/Assistant/Modules/HuntTools.php`:

```php
<?php
namespace App\Services\Assistant\Modules;

use App\Models\Lead;
use App\Models\LeadActivity;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ResolvesLeads;
use App\Services\Assistant\Support\ToolCall;
use App\Services\Credits\Exceptions\InsufficientCredits;
use App\Services\Credits\HuntCreditService;
use App\Services\Leads\LeadImporter;
use App\Services\Leads\LeadSearchService;
use App\Services\Leads\SearchInterpreter;

/**
 * Owner-assistant Business Hunt MUTATING tools (leads module): run a live
 * (credit-spending) search, save results to the pipeline, and move a lead
 * through the funnel. Confirm-gated via MutatingTool.
 */
class HuntTools extends MutatingTool
{
    use ResolvesLeads;

    public function __construct(
        protected LeadSearchService $search,
        protected SearchInterpreter $interpreter,
        protected LeadImporter $importer,
        protected HuntCreditService $credits,
    ) {}

    public function moduleKey(): ?string
    {
        return 'leads';
    }

    protected function permissions(): array
    {
        return [
            'search_businesses' => null,
            'save_leads' => null,
            'update_lead_status' => null,
        ];
    }

    protected function handle(ToolCall $call): array
    {
        return match ($call->tool) {
            'search_businesses' => $this->searchBusinesses($call),
            'save_leads' => $this->saveLeads($call),
            'update_lead_status' => $this->updateStatus($call),
            default => ['error' => 'unknown_tool'],
        };
    }

    /** Interpret the raw category+area into a real search term. Never throws. */
    private function interpret(ToolCall $call): array
    {
        $keyword = (string) $call->get('category');
        $area = $call->get('area');
        try {
            $out = $this->interpreter->interpret($call->shop, $keyword, $area);

            return [$out['keyword'], $out['area']];
        } catch (\Throwable $e) {
            report($e);

            return [$keyword, $area];
        }
    }

    /**
     * Live search — spends 1 credit on a cache miss. Hand-rolled (not gate())
     * so an InsufficientCredits result comes back as a plain error, NOT wrapped
     * in applied()'s done=true.
     */
    private function searchBusinesses(ToolCall $call): array
    {
        if (trim((string) $call->get('category')) === '') {
            return ['error' => 'not_found', 'what' => 'missing_category'];
        }

        if (! $call->confirmed) {
            $bal = $this->credits->balance($call->shop);
            $where = $call->get('area') ? " in {$call->get('area')}" : '';

            return $this->preview(
                "Search for \"{$call->get('category')}\"{$where}. A live search uses 1 credit — you have {$bal} (a repeat of a recent search is free).",
                ['credits' => "{$bal} → up to " . max(0, $bal - 1)],
            );
        }

        [$keyword, $area] = $this->interpret($call);
        try {
            $result = $this->search->search($call->shop, $keyword, $area);
        } catch (InsufficientCredits $e) {
            return ['error' => 'insufficient_credits', 'credits' => $e->balance];
        }

        $rows = $result['results'];

        return $this->applied([
            'count' => count($rows),
            'from_cache' => $result['from_cache'],
            'credits_left' => $result['credits'],
            'searched_for' => $keyword,
            'area' => $area,
            'sample' => array_slice(array_map(fn ($r) => $r['name'] ?? 'Unknown', $rows), 0, 5),
        ]);
    }

    /**
     * Save the results of the just-run search — a credit-free cache lookup, so
     * this never bills. not_found when there is no matching cached search.
     */
    private function saveLeads(ToolCall $call): array
    {
        if (trim((string) $call->get('category')) === '') {
            return ['error' => 'not_found', 'what' => 'missing_category'];
        }

        [$keyword, $area] = $this->interpret($call);
        $rows = $this->search->cached($keyword, $area);

        return $this->gate(
            $call,
            resolve: fn () => empty($rows) ? $this->notFound('search results') : ['rows' => $rows],
            describe: fn () => [
                'Save all ' . count($rows) . " \"{$keyword}\" businesses" . ($area ? " in {$area}" : '') . ' to your leads',
                ['leads' => count($rows) . ' to add'],
            ],
            write: function () use ($call, $rows) {
                $out = $this->importer->import($call->shop, $rows);

                return ['saved' => count($out['saved']), 'created' => $out['created']];
            },
        );
    }

    private function updateStatus(ToolCall $call): array
    {
        $new = strtolower(trim((string) $call->get('status')));
        if (! in_array($new, Lead::STATUSES, true)) {
            return ['error' => 'invalid_status'];
        }

        return $this->gate(
            $call,
            resolve: fn () => $this->resolveLead($call),
            describe: fn ($lead) => ["Move {$lead->name} from {$lead->status} to {$new}", ['status' => "{$lead->status} → {$new}"]],
            write: function ($lead) use ($new) {
                $from = $lead->status;
                $lead->status = $new;
                $lead->last_contacted_at = now();
                $lead->save();

                // Mirrors LeadController::updateStatus — status change is not an
                // import, so it does not go through LeadImporter.
                $lead->activities()->create([
                    'type' => LeadActivity::TYPE_STATUS_CHANGE,
                    'payload' => ['from' => $from, 'to' => $new],
                    'user_id' => current_shop_user()?->id,
                ]);

                return ['name' => $lead->name, 'status' => $new];
            },
        );
    }

    public function toolDefs(): array
    {
        return [
            ['name' => 'search_businesses', 'description' => 'Run a live business search to find leads. Give a category (e.g. "gyms", "hotels") and an optional area. A live search spends 1 Business Hunt credit; a repeat of a recent search is free. Confirm first (call with confirmed:true only after the owner agrees). Does NOT save — use save_leads afterwards.', 'input_schema' => ['type' => 'object', 'properties' => [
                'category' => ['type' => 'string', 'description' => 'Business type to look for, e.g. "gyms in Dubai Marina".'],
                'area' => ['type' => 'string', 'description' => 'Optional UAE area/city.'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['category']]],
            ['name' => 'save_leads', 'description' => 'Save the businesses from the most recent search into the lead pipeline. Pass the same category and area that were just searched. Spends no credit. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'category' => ['type' => 'string'],
                'area' => ['type' => 'string'],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['category']]],
            ['name' => 'update_lead_status', 'description' => 'Move a lead through the funnel (new, sent, replied, demo, won, pass). Identify the lead by business name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'name' => ['type' => 'string', 'description' => 'The business/lead name (fuzzy match).'],
                'status' => ['type' => 'string', 'enum' => Lead::STATUSES],
                'confirmed' => ['type' => 'boolean'],
            ], 'required' => ['name', 'status']]],
        ];
    }
}
```

- [ ] **Step 4: Register `HuntTools` in the registry**

In `app/Services/Assistant/AssistantToolRegistry.php`, add the constructor param and the `modules()` entry:

```php
        protected \App\Services\Assistant\Modules\HuntTools $hunt,
```

Add `$this->hunt,` to the array returned by `modules()`.

- [ ] **Step 5: Run tests to verify they pass**

Run: `php artisan test --filter=HuntAssistantToolsTest`
Expected: PASS (all read + mutating cases).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Assistant/Modules/HuntTools.php app/Services/Assistant/AssistantToolRegistry.php tests/Feature/HuntAssistantToolsTest.php
git commit -m "feat(assistant): add Hunt mutating tools (search, save, status)"
```

---

### Task 5: Product-aware system prompt

Compose `AssistantPrompt::for()` from a shared header + a bookings section + a hunt section, chosen by the shop's modules.

**Files:**
- Modify: `app/Support/Assistant/AssistantPrompt.php`
- Test: `tests/Feature/AssistantPromptTest.php`

**Interfaces:**
- Consumes: `Shop::hasModule(string)`, `Shop::$is_master`, `Shop::$name`.
- Produces: `AssistantPrompt::for(Shop): string` containing only the sections the shop's modules enable.

- [ ] **Step 1: Write the failing tests**

Add to `tests/Feature/AssistantPromptTest.php`:

```php
public function test_leads_only_shop_prompt_has_hunt_and_no_booking_section(): void
{
    $shop = Shop::create(['name' => 'Hunt Co', 'shop_code' => '7200', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['leads']]);
    $prompt = AssistantPrompt::for($shop);
    $this->assertStringContainsString('BUSINESS HUNT', $prompt);
    $this->assertStringContainsString('search_businesses', $prompt);
    $this->assertStringNotContainsString('BOOKINGS & SERVICES', $prompt);
}

public function test_bookings_only_shop_prompt_has_bookings_and_no_hunt_section(): void
{
    $shop = Shop::create(['name' => 'B', 'shop_code' => '7201', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings']]);
    $prompt = AssistantPrompt::for($shop);
    $this->assertStringContainsString('BOOKINGS & SERVICES', $prompt);
    $this->assertStringNotContainsString('BUSINESS HUNT', $prompt);
}

public function test_multi_module_shop_prompt_has_both_sections(): void
{
    $shop = Shop::create(['name' => 'Both', 'shop_code' => '7202', 'pin' => '0', 'status' => 'active', 'category_id' => 11, 'modules' => ['bookings', 'leads']]);
    $prompt = AssistantPrompt::for($shop);
    $this->assertStringContainsString('BOOKINGS & SERVICES', $prompt);
    $this->assertStringContainsString('BUSINESS HUNT', $prompt);
}
```

The existing `test_prompt_includes_shop_name_currency_and_confirm_rule` stays — its shop has no `modules`, so the `Shop::creating` default (`['bookings']`) applies and the bookings section renders (still contains the name, "dirhams", and "confirm").

- [ ] **Step 2: Run tests to verify they fail**

Run: `php artisan test --filter=AssistantPromptTest`
Expected: FAIL — the single hardcoded prompt has no `BUSINESS HUNT` section and always includes booking claims.

- [ ] **Step 3: Rewrite `AssistantPrompt` to compose sections**

Replace the body of `app/Support/Assistant/AssistantPrompt.php` with:

```php
<?php
namespace App\Support\Assistant;

use App\Models\Shop;
use Illuminate\Support\Facades\DB;

/** Builds the owner-assistant system prompt for one shop, per its product modules. */
class AssistantPrompt
{
    public static function for(Shop $shop): string
    {
        $sections = [self::sharedHeader($shop)];

        if ($shop->is_master || $shop->hasModule('bookings')) {
            $sections[] = self::bookingsSection($shop);
        }
        if ($shop->is_master || $shop->hasModule('leads')) {
            $sections[] = self::huntSection();
        }

        $sections[] = self::sharedClosing();

        return implode("\n\n", $sections);
    }

    private static function sharedHeader(Shop $shop): string
    {
        $today = now()->toDateString();

        return <<<PROMPT
        You are the business assistant for "{$shop->name}", a service business. You help the OWNER (not customers) understand AND run their business by voice — you can look things up and also make changes. What you can do depends on the sections below.

        Today is {$today}. Currency is AED — say "dirhams" out loud, never a currency symbol.

        RULES:
        - The owner may speak any language (English, Arabic, Hindi, Urdu, and others). Always reply in the SAME language they used, and follow the confirm rules below in every language.
        - Keep answers short and natural for a voice note. No markdown, no tables, no bullet lists — speak in sentences. Summarize long lists.
        - Use the tools to get real numbers and to make changes. Never invent figures or claim you changed something you did not.
        - Keep the FIRST couple of replies especially short. If the owner is just getting started, or asks what you can do / how to use this / to be guided, answer in one or two short sentences: briefly say what you can help with (see the sections below), then ask what they'd like to do.
        - When you'd read out several items, say them briefly, then finish with a short question — don't explain every one unprompted.

        MAKING CHANGES (very important):
        - Every changing tool takes a "confirmed" flag. The FIRST time, call the tool WITHOUT confirmed (or confirmed=false): it returns a preview of exactly what will change but does NOT change anything. Read that preview back to the owner in plain words and ask them to confirm.
        - Only AFTER the owner clearly says yes, call the SAME tool again with confirmed=true to actually make the change. If they don't clearly confirm, do not proceed.
        - CRITICAL: NEVER tell the owner that a change is done, and NEVER say a reference number, unless your most recent tool result contains done=true (saved=true). A preview result (preview=true, saved=false) means NOTHING was saved. If the owner confirms, you MUST call the same tool again with confirmed=true and WAIT for a done=true result before you tell them it is done.
        - If a create/update is missing a required detail, ask for it in one short question before previewing.
        - Relay tool results honestly: "no_permission" means it's above their access level — don't retry; "ambiguous" means ask which one they mean; "not_found" means say you couldn't find it.
        PROMPT;
    }

    private static function bookingsSection(Shop $shop): string
    {
        $services = DB::table('catalogs')->where('shop_id', $shop->id)->pluck('title')->implode(', ') ?: 'none yet';
        $staff = DB::table('staff')->where('shop_id', $shop->id)->pluck('name')->implode(', ') ?: 'none';

        return <<<PROMPT
        BOOKINGS & SERVICES:
        You can manage bookings, services, categories, staff, working hours, customers, users and roles, and the business profile.
        Services offered: {$services}.
        Staff: {$staff}.
        - Speak days by name (Monday, Friday), permissions by their plain labels (use list_permissions), and money and times naturally.
        - You CAN open a booking's detail page for the owner inside the app via open_booking — you are not limited to talking. Whenever the owner asks to open, show, view, see, or be taken to a booking, call open_booking with that booking's reference. Reuse the reference already in the conversation. NEVER say you cannot open a booking page.
        - After you CREATE a booking (create_booking with done=true), state its reference and offer to open it, e.g. "Booking BK00042 is created — do you want to see the details?". If the owner agrees, call open_booking. Never open it without being asked.
        - Do not invent or guess a booking reference under any circumstances.
        PROMPT;
    }

    private static function huntSection(): string
    {
        return <<<PROMPT
        BUSINESS HUNT (LEADS):
        This shop uses Business Hunt to find and pursue other businesses as leads. You can:
        - Search for businesses to approach with search_businesses (a category like "gyms" and an optional area). A live search costs 1 Business Hunt credit; a repeat of a recent search is free. Always confirm before searching and tell the owner the credit cost. After a search, offer to save the results.
        - Save the businesses from the last search to the pipeline with save_leads (this spends no credit).
        - Report the pipeline with list_leads: a total plus counts for each funnel stage (new, sent, replied, demo, won, pass).
        - Look up one lead's status and contact details with find_lead (by business name).
        - Move a lead through the funnel with update_lead_status.
        - Tell the owner their credit balance with hunt_credits.
        - You CAN open a lead's detail page via open_lead. Whenever the owner asks to open, show, view, or see a lead, call open_lead with the business name. NEVER say you cannot open a lead page.
        PROMPT;
    }

    private static function sharedClosing(): string
    {
        return 'Every reply should end with a short question that moves the owner to the next step.';
    }
}
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=AssistantPromptTest`
Expected: PASS — including the pre-existing name/currency/confirm test.

- [ ] **Step 5: Run the full assistant test group for regressions**

Run: `php artisan test --filter=Assistant && php artisan test --filter=Hunt`
Expected: PASS across `AssistantPromptTest`, `AssistantToolRegistryTest`, `HuntAssistantToolsTest`, and any other `*Assistant*` suites.

- [ ] **Step 6: Commit**

```bash
git add app/Support/Assistant/AssistantPrompt.php tests/Feature/AssistantPromptTest.php
git commit -m "feat(assistant): compose the system prompt from per-module sections"
```

---

## Deployment (out of scope for these tasks)

Do not deploy as part of implementation. Once all tasks are green on the droplet's scratch DB, promotion follows the standing rule (memory `deploy-flow-local-staging-prod`): stage → verify → promote to prod when great. The frontend is unaffected (backend-only change). Flag for Francis to trigger.

## Self-Review

- **Spec coverage:** §1 registry gating → Task 1. §2 HuntTools (read + mutating) → Tasks 3–4 (split into read/mutating modules to preserve the kill-switch semantics the spec references). §3 product-aware prompt → Task 5. §4 LeadImporter → Task 2. Testing §→ tests in every task. All spec sections mapped.
- **Split note:** the spec described one `HuntTools` module; the plan splits it into `HuntReadTools` (non-mutating) + `HuntTools` (mutating) so Hunt reads survive `assistant.mutations_enabled=false`, exactly like the existing `OwnerAssistantTools`/`BookingTools` pair. Same tools, same behaviour.
- **Type consistency:** `moduleKey(): ?string`, `defs(?Shop)`, `import(Shop,array): {saved,created}`, `cached(string,?string): ?array`, `resolveLead(ToolCall): Lead|array`, `InsufficientCredits->balance` — consistent across tasks.
- **Placeholder scan:** none — every step has full code and exact commands.
