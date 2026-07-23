# Lead Assignment & Agent Flow Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Give every Business Hunt lead an owner, hide other agents' leads from a plain agent, and let a manager hand leads out in bulk or by automatic round-robin.

**Architecture:** A nullable `leads.assigned_to_id` FK to `shop_users` plus a global Eloquent scope on `Lead` that narrows every query to the acting user unless they hold `leads.view_all` or the `Owner` role. `LeadImporter` is the single deliberate bypass. Distribution is a `LeadAssigner` service advancing a per-shop cursor under a row lock.

**Tech Stack:** Laravel 12 (PHP 8.4), PostgreSQL (prod/staging) + SQLite `:memory:` (tests), spatie/laravel-permission in teams mode, React 18 + TypeScript + Vite (admin SPA), Vitest.

**Spec:** `docs/superpowers/specs/2026-07-24-lead-assignment-design.md`

## Global Constraints

- **Work on `main`.** No feature branches, no merges. Commit after every task.
- **Never run tests locally and never against a live backend directory.** Use the isolated throwaway harness below.
- **One-time test-harness setup per session** (run before Task 1):
  ```bash
  ssh root@64.227.153.90 'rm -rf /root/testrun && cp -a /var/www/eloquent-backend-staging /root/testrun && cd /root/testrun && php artisan config:clear'
  ```
  `config:clear` is the critical step — it lets `phpunit.xml`'s `DB_CONNECTION=sqlite :memory:` win so no Postgres DB is ever touched.
- **Sync + run tests** (from the repo root, after every code change):
  ```bash
  scp -r app tests database routes root@64.227.153.90:/root/testrun/
  ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php'
  ```
- **New routes 404 until the route cache is refreshed.** In `/root/testrun` run `php artisan optimize:clear` after any `routes/api.php` change.
- **Frontend type-check runs locally:** `cd admin && npx tsc --noEmit`. Frontend tests: `cd admin && npx vitest run <file>`.
- **Permission names are exactly** `leads.view_all` and `leads.assign`. Never `leads.view.all`.
- **Multi-tenancy:** the shop id always comes from `$this->shop($request)` (the authenticated token), never from the request body.
- **Static routes before `{lead}` routes** in `routes/api.php`, per the ordering note already in that file.
- Tear down with `ssh root@64.227.153.90 'rm -rf /root/testrun'` when the whole plan is done.

---

### Task 1: Schema, permissions, backfill, and the visibility authority

**Files:**
- Create: `database/migrations/2026_07_24_000001_add_assignment_to_leads_table.php`
- Create: `database/migrations/2026_07_24_000002_add_lead_auto_assign_to_shops.php`
- Create: `database/migrations/2026_07_24_000003_backfill_leads_view_all.php`
- Modify: `app/Support/PermissionCatalog.php:67-72` (the `hunt` group)
- Modify: `app/Support/Rbac.php` (append `seesAllLeads`)
- Modify: `app/Models/Lead.php` (`$fillable`, `$casts`, `assignedTo` relation)
- Modify: `app/Models/Shop.php` (`$fillable`, `$casts`)
- Test: `tests/Feature/LeadAssignmentTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces:
  - `leads.assigned_to_id` (nullable int), `leads.assigned_at` (nullable datetime)
  - `shops.lead_auto_assign` (bool), `shops.lead_assign_cursor` (nullable int)
  - `Rbac::seesAllLeads(?ShopUser $user): bool`
  - `Lead::assignedTo(): BelongsTo`
  - Permissions `leads.view_all`, `leads.assign`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/LeadAssignmentTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Support\Rbac;
use Database\Seeders\PermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class LeadAssignmentTest extends TestCase
{
    use RefreshDatabase;

    /** An Owner-role user for the shop. */
    private function owner(Shop $shop): ShopUser
    {
        setPermissionsTeamId($shop->id);
        $role = Role::firstOrCreate(['name' => 'Owner', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);
        return $u;
    }

    /** A non-owner user holding exactly $perms. */
    private function agent(Shop $shop, array $perms = ['leads.view', 'leads.manage']): ShopUser
    {
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Agent-'.uniqid(), 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions($perms);
        $u = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $u->assignRole($role);
        return $u;
    }

    public function test_leads_carry_an_assignee_and_shops_carry_rotation_state(): void
    {
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);

        $lead = Lead::create([
            'shop_id' => $shop->id,
            'name' => 'Acme Salon',
            'status' => 'new',
            'assigned_to_id' => $user->id,
            'assigned_at' => now(),
        ]);

        $this->assertSame($user->id, $lead->fresh()->assigned_to_id);
        $this->assertSame($user->id, $lead->fresh()->assignedTo->id);

        $shop->update(['lead_auto_assign' => true, 'lead_assign_cursor' => $user->id]);
        $this->assertTrue($shop->fresh()->lead_auto_assign);
        $this->assertSame($user->id, $shop->fresh()->lead_assign_cursor);
    }

    public function test_sees_all_leads_is_true_for_owner_null_and_view_all_holders(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        $this->assertTrue(Rbac::seesAllLeads(null));
        $this->assertTrue(Rbac::seesAllLeads($this->owner($shop)));
        $this->assertTrue(Rbac::seesAllLeads($this->agent($shop, ['leads.view', 'leads.view_all'])));
        $this->assertFalse(Rbac::seesAllLeads($this->agent($shop, ['leads.view'])));
    }

    public function test_backfill_grants_view_all_to_existing_roles_holding_leads_view(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        // A role that predates this feature: has leads.view, not leads.view_all.
        setPermissionsTeamId($shop->id);
        $role = Role::create(['name' => 'Legacy', 'guard_name' => 'web', 'team_id' => $shop->id]);
        $role->syncPermissions(['leads.view']);
        $this->assertFalse($role->hasPermissionTo('leads.view_all'));

        (new \Database\Seeders\PermissionSeeder())->run();
        \Illuminate\Support\Facades\Artisan::call('leads:backfill-view-all');

        $this->assertTrue($role->fresh()->hasPermissionTo('leads.view_all'));
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php'
```
Expected: FAIL — `SQLSTATE ... no such column: assigned_to_id`, and `Call to undefined method App\Support\Rbac::seesAllLeads()`.

- [ ] **Step 3: Write the leads migration**

Create `database/migrations/2026_07_24_000001_add_assignment_to_leads_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lead ownership. assigned_to_id nulls on user delete so a departing agent's
 * leads fall back into the unassigned pool rather than becoming orphans only
 * the database can see.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->foreignId('assigned_to_id')->nullable()
                ->constrained('shop_users')->nullOnDelete();
            $table->timestamp('assigned_at')->nullable();

            $table->index(['shop_id', 'assigned_to_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::table('leads', function (Blueprint $table) {
            $table->dropIndex(['shop_id', 'assigned_to_id', 'status']);
            $table->dropConstrainedForeignId('assigned_to_id');
            $table->dropColumn('assigned_at');
        });
    }
};
```

- [ ] **Step 4: Write the shops migration**

Create `database/migrations/2026_07_24_000002_add_lead_auto_assign_to_shops.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-shop round-robin state. Off by default — a shop opts in. The cursor holds
 * the shop_users.id that last received an auto-assigned lead so rotation
 * survives across requests.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->boolean('lead_auto_assign')->default(false);
            $table->unsignedBigInteger('lead_assign_cursor')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('shops', function (Blueprint $table) {
            $table->dropColumn(['lead_auto_assign', 'lead_assign_cursor']);
        });
    }
};
```

- [ ] **Step 5: Add the two permissions to the catalog**

In `app/Support/PermissionCatalog.php`, replace the `hunt` group (currently lines 67-72) with:

```php
            'hunt' => ['label' => 'Business Hunt', 'module' => 'leads', 'section' => null, 'permissions' => [
                'leads.view'     => 'View leads, pipeline & Hunt summary',
                'leads.view_all' => 'See every lead, not just their own',
                'leads.search'   => 'Search businesses (spends credits)',
                'leads.manage'   => 'Save & work leads (status, follow-ups)',
                'leads.assign'   => 'Assign leads to other users',
                'leads.purchase' => 'Buy credit packs',
            ]],
```

- [ ] **Step 6: Add the visibility authority to Rbac**

In `app/Support/Rbac.php`, append this method inside the class (after `userCan`):

```php
    /**
     * May this user see every lead in the shop, or only the ones assigned to
     * them? Delegates to userCan so owners and untagged (legacy) sessions stay
     * all-allowed, and an unseeded permission fails closed to "own leads only".
     */
    public static function seesAllLeads(?ShopUser $user): bool
    {
        return self::userCan($user, 'leads.view_all');
    }
```

- [ ] **Step 7: Wire the model fields**

In `app/Models/Lead.php`, add `'assigned_to_id'` and `'assigned_at'` to `$fillable` (after `'shop_id'`), add `'assigned_at' => 'datetime'` to `$casts`, and add this relation after the `shop()` method:

```php
    /** The agent who owns this lead. Null means unassigned (owner-visible pool). */
    public function assignedTo(): BelongsTo
    {
        return $this->belongsTo(ShopUser::class, 'assigned_to_id');
    }
```

In `app/Models/Shop.php`, add `'lead_auto_assign'` and `'lead_assign_cursor'` to `$fillable`, and `'lead_auto_assign' => 'boolean'` to `$casts`.

- [ ] **Step 8: Write the backfill command and migration**

Create `app/Console/Commands/BackfillLeadsViewAll.php`:

```php
<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Every lead predating this feature is unassigned, so switching the global
 * scope on would blank the Hunt screen for every non-owner user in every shop.
 * Granting leads.view_all to each role that already holds leads.view keeps
 * behaviour identical on deploy day. Removing it from a role later is the
 * deliberate act that turns an employee into an agent. Idempotent.
 */
class BackfillLeadsViewAll extends Command
{
    protected $signature = 'leads:backfill-view-all';
    protected $description = 'Grant leads.view_all to every role that already holds leads.view';

    public function handle(): int
    {
        $viewAll = Permission::where('name', 'leads.view_all')->where('guard_name', 'web')->first();
        if ($viewAll === null) {
            $this->error('leads.view_all is not seeded — run the PermissionSeeder first.');
            return self::FAILURE;
        }

        $granted = 0;
        Role::with('permissions')->chunk(100, function ($roles) use ($viewAll, &$granted) {
            foreach ($roles as $role) {
                $names = $role->permissions->pluck('name');
                if ($names->contains('leads.view') && ! $names->contains('leads.view_all')) {
                    $role->givePermissionTo($viewAll);
                    $granted++;
                }
            }
        });

        $this->info("Granted leads.view_all to {$granted} role(s).");

        return self::SUCCESS;
    }
}
```

Create `database/migrations/2026_07_24_000003_backfill_leads_view_all.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Artisan;

/**
 * Seeds the new permissions, then hands every existing role that can view leads
 * the widened leads.view_all so the deploy is a no-op for live shops.
 */
return new class extends Migration
{
    public function up(): void
    {
        Artisan::call('db:seed', ['--class' => \Database\Seeders\PermissionSeeder::class, '--force' => true]);
        Artisan::call('leads:backfill-view-all');
    }

    public function down(): void
    {
        // Leave the grants in place — removing them would hide leads.
    }
};
```

- [ ] **Step 9: Run the tests to verify they pass**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php'
```
Expected: PASS — 3 passed.

- [ ] **Step 10: Commit**

```bash
git add app database tests
git commit -m "feat(hunt): lead assignment schema, permissions and backfill

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 2: The global scope (isolation) and the importer bypass

**Files:**
- Create: `app/Models/Scopes/AssignedLeadScope.php`
- Modify: `app/Models/Lead.php` (add `booted()`)
- Modify: `app/Services/Leads/LeadImporter.php:48-51` and `:86-88`
- Test: `tests/Feature/LeadAssignmentTest.php`

**Interfaces:**
- Consumes: `Rbac::seesAllLeads()`, `leads.assigned_to_id` (Task 1).
- Produces: `App\Models\Scopes\AssignedLeadScope` — bypass with `Lead::withoutGlobalScope(AssignedLeadScope::class)`.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/LeadAssignmentTest.php`:

```php
    public function test_an_agent_sees_only_their_own_leads(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);
        $b = $this->agent($shop);

        $mine = Lead::create(['shop_id' => $shop->id, 'name' => 'Mine', 'status' => 'new', 'assigned_to_id' => $a->id]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Theirs', 'status' => 'new', 'assigned_to_id' => $b->id]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Pool', 'status' => 'new']);

        \App\Support\CurrentShopUser::set($a);

        $names = Lead::forShop($shop->id)->pluck('name')->all();
        $this->assertSame(['Mine'], $names);
        $this->assertSame($mine->id, Lead::find($mine->id)?->id);
        $this->assertNull(Lead::find($mine->id + 1));
    }

    public function test_owner_and_view_all_holders_see_every_lead_including_unassigned(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);

        Lead::create(['shop_id' => $shop->id, 'name' => 'Mine', 'status' => 'new', 'assigned_to_id' => $a->id]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Pool', 'status' => 'new']);

        \App\Support\CurrentShopUser::set($this->owner($shop));
        $this->assertCount(2, Lead::forShop($shop->id)->get());

        \App\Support\CurrentShopUser::set($this->agent($shop, ['leads.view', 'leads.view_all']));
        $this->assertCount(2, Lead::forShop($shop->id)->get());

        \App\Support\CurrentShopUser::set(null);
        $this->assertCount(2, Lead::forShop($shop->id)->get());
    }

    public function test_importer_updates_a_lead_owned_by_another_agent_instead_of_500ing(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);
        $b = $this->agent($shop);

        $existing = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Old Name', 'status' => 'new',
            'external_ref' => 'place-123', 'assigned_to_id' => $b->id,
        ]);

        \App\Support\CurrentShopUser::set($a);

        $out = app(\App\Services\Leads\LeadImporter::class)
            ->import($shop, [['name' => 'New Name', 'external_ref' => 'place-123']]);

        $this->assertSame(0, $out['created']);
        $this->assertSame('New Name', $existing->fresh()->name);
        // Ownership is not stolen by a re-save.
        $this->assertSame($b->id, $existing->fresh()->assigned_to_id);
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php'
```
Expected: FAIL — the agent sees all three leads (no scope yet).

- [ ] **Step 3: Write the scope**

Create `app/Models/Scopes/AssignedLeadScope.php`:

```php
<?php

namespace App\Models\Scopes;

use App\Support\Rbac;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Narrows every Lead read to the acting agent unless they hold leads.view_all
 * or the Owner role. Applied globally rather than at each call site because a
 * missed call site leaks another agent's leads silently — and there are a dozen
 * of them across the controller, the assistant tools and reports.
 *
 * The one deliberate bypass is LeadImporter, which must see the whole shop to
 * dedupe on (shop_id, external_ref).
 *
 * A null acting user (console, queue, legacy untagged token) is owner-equivalent
 * throughout Rbac, so no filter is applied.
 */
class AssignedLeadScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $user = current_shop_user();
        if (Rbac::seesAllLeads($user)) {
            return;
        }

        $builder->where($model->qualifyColumn('assigned_to_id'), $user->id);
    }
}
```

- [ ] **Step 4: Register the scope on the model**

In `app/Models/Lead.php`, add `use App\Models\Scopes\AssignedLeadScope;` to the imports and this method after the `$appends` property:

```php
    protected static function booted(): void
    {
        static::addGlobalScope(new AssignedLeadScope());
    }
```

- [ ] **Step 5: Bypass the scope in the importer**

In `app/Services/Leads/LeadImporter.php`, add `use App\Models\Scopes\AssignedLeadScope;` to the imports.

Replace the `firstOrNew` block (currently lines 48-51):

```php
            if (! empty($row['external_ref'])) {
                // Scope-free: an agent re-saving a business owned by a colleague
                // must find and update that row, not insert a duplicate that
                // violates unique(shop_id, external_ref).
                $lead = Lead::withoutGlobalScope(AssignedLeadScope::class)->firstOrNew([
                    'shop_id' => $shop->id,
                    'external_ref' => $row['external_ref'],
                ]);
                $lead = $this->saveDeduped($lead, $attrs, $shop->id, $row['external_ref']);
```

Replace the recovery query inside `saveDeduped` (currently line 87):

```php
            $lead = Lead::withoutGlobalScope(AssignedLeadScope::class)
                ->where('shop_id', $shopId)->where('external_ref', $externalRef)->firstOrFail();
```

- [ ] **Step 6: Run to verify they pass**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php'
```
Expected: PASS — 6 passed.

- [ ] **Step 7: Run the existing Hunt suites for regressions**

```bash
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadFinderTest.php tests/Feature/HuntPermissionsTest.php tests/Feature/HuntAssistantToolsTest.php tests/Feature/LeadDealValueTest.php tests/Feature/LeadFollowupTest.php'
```
Expected: PASS. These act as Owner or untagged, so the scope is inert for them. If any fail, the scope is being applied where `seesAllLeads` should be true — fix that before continuing.

- [ ] **Step 8: Commit**

```bash
git add app tests
git commit -m "feat(hunt): global scope so an agent sees only their own leads

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 3: Self-assign on save

**Files:**
- Modify: `app/Services/Leads/LeadImporter.php`
- Test: `tests/Feature/LeadAssignmentTest.php`

**Interfaces:**
- Consumes: `AssignedLeadScope`, `Rbac::seesAllLeads()`.
- Produces: newly created leads carry `assigned_to_id = <acting agent>` when that agent cannot see all leads.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/LeadAssignmentTest.php`:

```php
    public function test_an_agent_saving_search_results_keeps_them(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);

        \App\Support\CurrentShopUser::set($a);

        $out = app(\App\Services\Leads\LeadImporter::class)->import($shop, [
            ['name' => 'Found One', 'external_ref' => 'p1'],
            ['name' => 'Found Two'],
        ], 'my pipeline');

        $this->assertSame(2, $out['created']);
        // Both are visible to the agent that found them — the whole point.
        $this->assertCount(2, Lead::forShop($shop->id)->get());
        foreach ($out['saved'] as $lead) {
            $this->assertSame($a->id, $lead->fresh()->assigned_to_id);
            $this->assertNotNull($lead->fresh()->assigned_at);
        }
    }

    public function test_an_owner_saving_leaves_them_unassigned(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);

        \App\Support\CurrentShopUser::set($this->owner($shop));

        $out = app(\App\Services\Leads\LeadImporter::class)
            ->import($shop, [['name' => 'Pool Lead']], 'pipeline');

        $this->assertNull($out['saved'][0]->fresh()->assigned_to_id);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php --filter=saving'
```
Expected: FAIL — `Failed asserting that null is identical to <int>`, and the agent sees 0 leads.

- [ ] **Step 3: Implement self-assign**

In `app/Services/Leads/LeadImporter.php`, add `use App\Support\Rbac;` to the imports. Inside `import()`, immediately after the `$pipeline = $pipeline === '' ? null : $pipeline;` line, add:

```php
        // An agent who cannot see the whole shop must own what they save —
        // otherwise the lead vanishes from their screen the instant it is
        // created. Takes priority over round-robin (you keep what you find).
        $actor = current_shop_user();
        $selfAssign = $actor !== null && ! Rbac::seesAllLeads($actor);
```

Then, inside the `foreach ($rows as $row)` loop, replace the block that starts `if ($lead->wasRecentlyCreated) {` with:

```php
            if ($lead->wasRecentlyCreated) {
                $created++;

                if ($selfAssign && $lead->assigned_to_id === null) {
                    $lead->assigned_to_id = $actor->id;
                    $lead->assigned_at = now();
                    $lead->save();
                }
            }
```

- [ ] **Step 4: Run to verify it passes**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php'
```
Expected: PASS — 8 passed.

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "feat(hunt): leads an agent saves are assigned to that agent

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 4: assignTo, the activity type, and the assign endpoints

**Files:**
- Modify: `app/Models/LeadActivity.php` (add `TYPE_ASSIGNED`)
- Modify: `app/Models/Lead.php` (add `assignTo`)
- Modify: `app/Http/Controllers/LeadController.php` (add `assign`, `assignBulk`, `resolveAssignee`)
- Modify: `routes/api.php:276-288`
- Test: `tests/Feature/LeadAssignmentTest.php`

**Interfaces:**
- Consumes: `Lead::assignedTo()`, `AssignedLeadScope`.
- Produces:
  - `LeadActivity::TYPE_ASSIGNED = 'assigned'`
  - `Lead::assignTo(?ShopUser $target, ?ShopUser $actor = null): void` — saves the lead and logs the activity in one transaction; a no-op when the owner is unchanged.
  - `PATCH /api/shop/leads/{lead}/assign` body `{assigned_to_id: int|null}`
  - `POST /api/shop/leads/assign` body `{ids: int[], assigned_to_id: int|null}` → `{assigned: int}`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/LeadAssignmentTest.php`:

```php
    private function tokenFor(Shop $shop, ShopUser $user): string
    {
        $new = $shop->createToken('t');
        $new->accessToken->forceFill(['shop_user_id' => $user->id])->save();
        return $new->plainTextToken;
    }

    private function authJson(string $token, string $method, string $url, array $body = [])
    {
        return $this->withHeaders(['Authorization' => "Bearer $token", 'Accept' => 'application/json'])
            ->json($method, $url, $body);
    }

    public function test_assigning_a_lead_records_owner_and_activity(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign']);
        $agent = $this->agent($shop);
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new']);

        $this->authJson($this->tokenFor($shop, $manager), 'PATCH', "/api/shop/leads/{$lead->id}/assign", [
            'assigned_to_id' => $agent->id,
        ])->assertOk();

        $this->assertSame($agent->id, $lead->fresh()->assigned_to_id);
        $this->assertNotNull($lead->fresh()->assigned_at);

        $activity = \App\Models\LeadActivity::where('lead_id', $lead->id)->where('type', 'assigned')->first();
        $this->assertNotNull($activity);
        $this->assertSame($agent->id, $activity->payload['to_id']);
        $this->assertSame($manager->id, $activity->user_id);
    }

    public function test_assigning_requires_the_assign_permission(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $agent = $this->agent($shop);
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new', 'assigned_to_id' => $agent->id]);

        $this->authJson($this->tokenFor($shop, $agent), 'PATCH', "/api/shop/leads/{$lead->id}/assign", [
            'assigned_to_id' => $agent->id,
        ])->assertStatus(403);
    }

    public function test_null_unassigns_and_a_foreign_user_is_rejected(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $other = Shop::factory()->create(['modules' => ['leads']]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign']);
        $agent = $this->agent($shop);
        $outsider = ShopUser::factory()->create(['shop_id' => $other->id]);
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Acme', 'status' => 'new', 'assigned_to_id' => $agent->id]);

        $token = $this->tokenFor($shop, $manager);

        $this->authJson($token, 'PATCH', "/api/shop/leads/{$lead->id}/assign", ['assigned_to_id' => null])->assertOk();
        $this->assertNull($lead->fresh()->assigned_to_id);

        $this->app['auth']->forgetGuards();
        $this->authJson($token, 'PATCH', "/api/shop/leads/{$lead->id}/assign", [
            'assigned_to_id' => $outsider->id,
        ])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $inactive = ShopUser::factory()->create(['shop_id' => $shop->id, 'is_active' => false]);
        $this->authJson($token, 'PATCH', "/api/shop/leads/{$lead->id}/assign", [
            'assigned_to_id' => $inactive->id,
        ])->assertStatus(422);
    }

    public function test_bulk_assign_moves_many_leads_at_once(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign']);
        $agent = $this->agent($shop);

        $ids = collect(range(1, 3))->map(fn ($i) => Lead::create([
            'shop_id' => $shop->id, 'name' => "Lead {$i}", 'status' => 'new',
        ])->id)->all();

        $this->authJson($this->tokenFor($shop, $manager), 'POST', '/api/shop/leads/assign', [
            'ids' => $ids, 'assigned_to_id' => $agent->id,
        ])->assertOk()->assertJson(['assigned' => 3]);

        foreach ($ids as $id) {
            $this->assertSame($agent->id, Lead::withoutGlobalScope(\App\Models\Scopes\AssignedLeadScope::class)->find($id)->assigned_to_id);
        }
    }

    public function test_an_agent_cannot_open_another_agents_lead(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);
        $b = $this->agent($shop);
        $theirs = Lead::create(['shop_id' => $shop->id, 'name' => 'Theirs', 'status' => 'new', 'assigned_to_id' => $b->id]);

        $this->authJson($this->tokenFor($shop, $a), 'GET', "/api/shop/leads/{$theirs->id}")
            ->assertStatus(404);
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan optimize:clear && php artisan test tests/Feature/LeadAssignmentTest.php'
```
Expected: FAIL — 404 on the new assign routes.

- [ ] **Step 3: Add the activity type**

In `app/Models/LeadActivity.php`, add after `TYPE_CONTACTED`:

```php
    public const TYPE_ASSIGNED = 'assigned';
```

- [ ] **Step 4: Add `assignTo` to the model**

In `app/Models/Lead.php`, add `use Illuminate\Support\Facades\DB;` to the imports and this method after `applyWonDeal`:

```php
    /**
     * Hand this lead to an agent (or null to return it to the pool). Saves the
     * lead and logs an `assigned` activity in one transaction so the timeline
     * can never disagree with the column. Shared by the controller and the voice
     * tool so the rules live in one place. Re-assigning to the same person is a
     * no-op — it must not spam the timeline.
     */
    public function assignTo(?ShopUser $target, ?ShopUser $actor = null): void
    {
        $fromId = $this->assigned_to_id;
        if ($fromId === $target?->id) {
            return;
        }

        $fromName = $fromId !== null ? ShopUser::find($fromId)?->name : null;

        DB::transaction(function () use ($target, $actor, $fromId, $fromName) {
            $this->assigned_to_id = $target?->id;
            $this->assigned_at = $target !== null ? now() : null;
            $this->save();

            $this->activities()->create([
                'type' => LeadActivity::TYPE_ASSIGNED,
                'payload' => array_filter([
                    'from_id'   => $fromId,
                    'from_name' => $fromName,
                    'to_id'     => $target?->id,
                    'to_name'   => $target?->name,
                ], fn ($v) => $v !== null),
                'user_id' => $actor?->id,
            ]);
        });
    }
```

Note: `Model::save()` builds its update query without global scopes, so an agent can still save a lead they legitimately hold.

- [ ] **Step 5: Add the controller actions**

In `app/Http/Controllers/LeadController.php`, add `use App\Models\ShopUser;` to the imports and these three methods after `logFollowup`:

```php
    /**
     * PATCH /shop/leads/{lead}/assign {assigned_to_id}
     * Hand one lead to an agent, or pass null to return it to the pool.
     */
    public function assign(Request $request, Lead $lead)
    {
        $shop = $this->shop($request);
        abort_unless($lead->shop_id === $shop->id, 404);

        $data = $request->validate([
            'assigned_to_id' => ['present', 'nullable', 'integer'],
        ]);

        $lead->assignTo($this->resolveAssignee($shop, $data['assigned_to_id']), current_shop_user());

        return response()->json(['data' => $lead->fresh()->load('assignedTo:id,name,is_active')]);
    }

    /**
     * POST /shop/leads/assign {ids, assigned_to_id}
     * Bulk hand-out from the pipeline's multi-select. Deliberately runs through
     * the normal visibility scope, so a manager can only assign leads they can
     * already see.
     */
    public function assignBulk(Request $request)
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1', 'max:500'],
            'ids.*' => ['integer'],
            'assigned_to_id' => ['present', 'nullable', 'integer'],
        ]);

        $target = $this->resolveAssignee($shop, $data['assigned_to_id']);
        $actor = current_shop_user();

        $leads = Lead::forShop($shop->id)->whereIn('id', $data['ids'])->get();
        foreach ($leads as $lead) {
            $lead->assignTo($target, $actor);
        }

        return response()->json(['assigned' => $leads->count()]);
    }

    /**
     * Resolve an assignee id to an active ShopUser of THIS shop. The shop is
     * taken from the token, never the body, so a valid id from another tenant
     * is rejected rather than silently accepted.
     */
    private function resolveAssignee(Shop $shop, ?int $id): ?ShopUser
    {
        if ($id === null) {
            return null;
        }

        $user = ShopUser::where('shop_id', $shop->id)
            ->where('id', $id)
            ->where('is_active', true)
            ->first();

        abort_if($user === null, 422, 'Assignee must be an active user of this shop.');

        return $user;
    }
```

- [ ] **Step 6: Register the routes**

In `routes/api.php`, inside the `module:leads` group, add the bulk route **with the other static `/shop/leads/*` routes** (immediately after the `/shop/leads/ad-search/{runId}` line), and the per-lead route after the `status` route:

```php
    Route::post  ('/shop/leads/assign',            [\App\Http\Controllers\LeadController::class, 'assignBulk'])->middleware('can.perm:leads.assign');
```

```php
    Route::patch ('/shop/leads/{lead}/assign',    [\App\Http\Controllers\LeadController::class, 'assign'])->middleware('can.perm:leads.assign');
```

- [ ] **Step 7: Run to verify they pass**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan optimize:clear && php artisan test tests/Feature/LeadAssignmentTest.php'
```
Expected: PASS — 13 passed.

- [ ] **Step 8: Commit**

```bash
git add app routes tests
git commit -m "feat(hunt): assign and bulk-assign lead endpoints

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 5: Assignment on the list endpoint

**Files:**
- Modify: `app/Http/Controllers/LeadController.php` (`index`)
- Test: `tests/Feature/LeadAssignmentTest.php`

**Interfaces:**
- Consumes: `Lead::assignedTo()`, `resolveAssignee` context.
- Produces: `GET /api/shop/leads` gains query param `assigned_to=me|unassigned|<id>` and response keys `assignees` (array of `{id, name}`, only for `leads.assign` holders) and `auto_assign` (bool). Each lead carries `assigned_to: {id, name, is_active} | null`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/LeadAssignmentTest.php`:

```php
    public function test_index_exposes_owner_filter_assignees_and_auto_assign_flag(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads'], 'lead_auto_assign' => true]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign']);
        $agent = $this->agent($shop);

        Lead::create(['shop_id' => $shop->id, 'name' => 'Owned', 'status' => 'new', 'assigned_to_id' => $agent->id]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Pool', 'status' => 'new']);

        $token = $this->tokenFor($shop, $manager);

        $all = $this->authJson($token, 'GET', '/api/shop/leads')->assertOk()->json();
        $this->assertCount(2, $all['data']);
        $this->assertTrue($all['auto_assign']);
        $this->assertContains($agent->id, array_column($all['assignees'], 'id'));
        $owned = collect($all['data'])->firstWhere('name', 'Owned');
        $this->assertSame($agent->id, $owned['assigned_to']['id']);

        $this->app['auth']->forgetGuards();
        $pool = $this->authJson($token, 'GET', '/api/shop/leads?assigned_to=unassigned')->assertOk()->json();
        $this->assertCount(1, $pool['data']);
        $this->assertSame('Pool', $pool['data'][0]['name']);

        $this->app['auth']->forgetGuards();
        $byId = $this->authJson($token, 'GET', "/api/shop/leads?assigned_to={$agent->id}")->assertOk()->json();
        $this->assertCount(1, $byId['data']);
        $this->assertSame('Owned', $byId['data'][0]['name']);
    }

    public function test_assignees_are_withheld_from_users_who_cannot_assign(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $agent = $this->agent($shop);

        $body = $this->authJson($this->tokenFor($shop, $agent), 'GET', '/api/shop/leads')->assertOk()->json();
        $this->assertSame([], $body['assignees']);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php --filter=index_exposes'
```
Expected: FAIL — `Undefined array key "auto_assign"`.

- [ ] **Step 3: Implement the filter and payload**

In `app/Http/Controllers/LeadController.php`, inside `index()`:

Change the query construction line to eager-load the owner:

```php
        $query = Lead::forShop($shop->id)->with('assignedTo:id,name,is_active');
```

Add this filter after the `followups` block (just before `$leads = $query->orderByDesc('id')->get();`):

```php
        // Owner filter: 'me' | 'unassigned' | a shop_user id. Independent of the
        // visibility scope — an agent filtering by 'me' just sees what they
        // already see.
        $assigned = $request->query('assigned_to');
        if ($assigned !== null && $assigned !== '') {
            if ($assigned === 'unassigned') {
                $query->whereNull('assigned_to_id');
            } elseif ($assigned === 'me') {
                $me = current_shop_user()?->id;
                $me === null ? $query->whereRaw('1 = 0') : $query->where('assigned_to_id', $me);
            } else {
                $query->where('assigned_to_id', (int) $assigned);
            }
        }
```

Add this just before the `return response()->json([` at the end of `index()`:

```php
        // The pool the hand-out picker offers. Withheld from users who cannot
        // assign, so the staff list never leaks through the leads endpoint to
        // someone without users.view.
        $assignees = Rbac::userCan(current_shop_user(), 'leads.assign')
            ? ShopUser::where('shop_id', $shop->id)->where('is_active', true)
                ->orderBy('name')->get(['id', 'name'])
            : collect();
```

And extend the response array with:

```php
            'assignees' => $assignees,
            'auto_assign' => (bool) $shop->lead_auto_assign,
```

Add `use App\Support\Rbac;` to the imports if it is not already there.

- [ ] **Step 4: Run to verify it passes**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php'
```
Expected: PASS — 15 passed.

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "feat(hunt): owner filter, assignee list and auto-assign flag on the leads list

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 6: Round-robin distribution

**Files:**
- Create: `app/Services/Leads/LeadAssigner.php`
- Modify: `app/Services/Leads/LeadImporter.php` (constructor + create hook)
- Modify: `app/Http/Controllers/LeadController.php` (add `updateSettings`)
- Modify: `routes/api.php`
- Test: `tests/Feature/LeadAssignmentTest.php`

**Interfaces:**
- Consumes: `Rbac::isOwner()`, `Rbac::userCan()`, `shops.lead_assign_cursor`.
- Produces:
  - `LeadAssigner::pool(Shop $shop): \Illuminate\Support\Collection` — eligible `ShopUser`s, ordered by id
  - `LeadAssigner::next(Shop $shop): ?ShopUser` — advances and persists the cursor
  - `PATCH /api/shop/leads/settings` body `{lead_auto_assign: bool}` → `{lead_auto_assign: bool}`

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/LeadAssignmentTest.php`:

```php
    public function test_round_robin_spreads_new_leads_and_excludes_the_owner(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads'], 'lead_auto_assign' => true]);
        $this->owner($shop);
        $a = $this->agent($shop);
        $b = $this->agent($shop);
        $c = $this->agent($shop);

        // Acting as the owner: no self-assign, so rotation takes over.
        \App\Support\CurrentShopUser::set($this->owner($shop));

        $rows = collect(range(1, 6))->map(fn ($i) => ['name' => "Biz {$i}"])->all();
        $out = app(\App\Services\Leads\LeadImporter::class)->import($shop, $rows, 'batch');

        $counts = collect($out['saved'])
            ->map(fn ($l) => $l->fresh()->assigned_to_id)
            ->countBy();

        $this->assertSame(2, $counts[$a->id] ?? 0);
        $this->assertSame(2, $counts[$b->id] ?? 0);
        $this->assertSame(2, $counts[$c->id] ?? 0);
        $this->assertNotNull($shop->fresh()->lead_assign_cursor);
    }

    public function test_rotation_skips_inactive_users_and_users_without_hunt_access(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads'], 'lead_auto_assign' => true]);
        $a = $this->agent($shop);
        $this->agent($shop, ['bookings.view']);           // no leads.view — skipped
        $inactive = $this->agent($shop);
        $inactive->update(['is_active' => false]);        // skipped

        $pool = app(\App\Services\Leads\LeadAssigner::class)->pool($shop);

        $this->assertSame([$a->id], $pool->pluck('id')->all());
    }

    public function test_auto_assign_off_by_default_leaves_leads_in_the_pool(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $this->agent($shop);

        \App\Support\CurrentShopUser::set($this->owner($shop));

        $out = app(\App\Services\Leads\LeadImporter::class)->import($shop, [['name' => 'Biz']], 'batch');

        $this->assertNull($out['saved'][0]->fresh()->assigned_to_id);
    }

    public function test_an_empty_rotation_pool_leaves_leads_unassigned_without_error(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads'], 'lead_auto_assign' => true]);

        \App\Support\CurrentShopUser::set($this->owner($shop));

        $out = app(\App\Services\Leads\LeadImporter::class)->import($shop, [['name' => 'Biz']], 'batch');

        $this->assertNull($out['saved'][0]->fresh()->assigned_to_id);
    }

    public function test_self_assign_beats_rotation(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads'], 'lead_auto_assign' => true]);
        $a = $this->agent($shop);
        $this->agent($shop);

        \App\Support\CurrentShopUser::set($a);

        $out = app(\App\Services\Leads\LeadImporter::class)->import($shop, [['name' => 'Found by A']], 'batch');

        $this->assertSame($a->id, $out['saved'][0]->fresh()->assigned_to_id);
    }

    public function test_auto_assign_toggle_requires_the_assign_permission(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign']);
        $agent = $this->agent($shop);

        $this->authJson($this->tokenFor($shop, $agent), 'PATCH', '/api/shop/leads/settings', [
            'lead_auto_assign' => true,
        ])->assertStatus(403);

        $this->app['auth']->forgetGuards();
        $this->authJson($this->tokenFor($shop, $manager), 'PATCH', '/api/shop/leads/settings', [
            'lead_auto_assign' => true,
        ])->assertOk()->assertJson(['lead_auto_assign' => true]);

        $this->assertTrue($shop->fresh()->lead_auto_assign);
    }
```

- [ ] **Step 2: Run to verify they fail**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan optimize:clear && php artisan test tests/Feature/LeadAssignmentTest.php --filter=rotation'
```
Expected: FAIL — `Target class [App\Services\Leads\LeadAssigner] does not exist`.

- [ ] **Step 3: Write the assigner**

Create `app/Services/Leads/LeadAssigner.php`:

```php
<?php

namespace App\Services\Leads;

use App\Models\Shop;
use App\Models\ShopUser;
use App\Support\Rbac;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Round-robin hand-out of newly saved leads. The rotation is every active shop
 * user EXCEPT the Owner (Francis: the admin/main account stays out of it) and
 * except anyone with no Hunt access at all, who would otherwise be handed leads
 * they cannot open.
 *
 * Fairness comes from shops.lead_assign_cursor — the id that last received a
 * lead. The cursor is read and written under a row lock so two concurrent
 * imports cannot hand the same position to both.
 */
class LeadAssigner
{
    /**
     * Eligible agents for this shop, ordered by id (stable rotation order).
     *
     * @return Collection<int, ShopUser>
     */
    public function pool(Shop $shop): Collection
    {
        // spatie is in teams mode — permission checks need the shop context,
        // which a queue/console caller may not have set.
        setPermissionsTeamId($shop->id);

        return ShopUser::where('shop_id', $shop->id)
            ->where('is_active', true)
            ->orderBy('id')
            ->get()
            ->filter(fn (ShopUser $u) => ! Rbac::isOwner($u) && Rbac::userCan($u, 'leads.view'))
            ->values();
    }

    /**
     * The next agent in the rotation, advancing and persisting the cursor.
     * Null when nobody is eligible — the caller leaves the lead in the pool.
     */
    public function next(Shop $shop): ?ShopUser
    {
        $pool = $this->pool($shop);
        if ($pool->isEmpty()) {
            return null;
        }

        return DB::transaction(function () use ($shop, $pool) {
            $locked = Shop::whereKey($shop->id)->lockForUpdate()->first();
            if ($locked === null) {
                return null;
            }

            $ids = $pool->pluck('id')->all();
            $at = array_search($locked->lead_assign_cursor, $ids, true);
            $chosen = $pool[$at === false ? 0 : ($at + 1) % count($ids)];

            $locked->lead_assign_cursor = $chosen->id;
            $locked->save();

            // Keep the caller's instance in step so a multi-row import advances.
            $shop->lead_assign_cursor = $chosen->id;

            return $chosen;
        });
    }
}
```

- [ ] **Step 4: Wire it into the importer**

In `app/Services/Leads/LeadImporter.php`, add a constructor above `import()`:

```php
    public function __construct(private LeadAssigner $assigner)
    {
    }
```

Then replace the created-lead block written in Task 3 with:

```php
            if ($lead->wasRecentlyCreated) {
                $created++;

                if ($lead->assigned_to_id === null) {
                    // An agent keeps what they find; otherwise the shop's
                    // rotation decides. Rotation only runs when switched on.
                    $owner = $selfAssign
                        ? $actor
                        : ($shop->lead_auto_assign ? $this->assigner->next($shop) : null);

                    if ($owner !== null) {
                        $lead->assigned_to_id = $owner->id;
                        $lead->assigned_at = now();
                        $lead->save();
                    }
                }
            }
```

- [ ] **Step 5: Add the settings endpoint and route**

In `app/Http/Controllers/LeadController.php`, add after `assignBulk`:

```php
    /**
     * PATCH /shop/leads/settings {lead_auto_assign}
     * Hunt hand-out behaviour. Lives here rather than under Settings because
     * it is Hunt behaviour, and Settings is a permission surface we keep narrow.
     */
    public function updateSettings(Request $request)
    {
        $shop = $this->shop($request);

        $data = $request->validate([
            'lead_auto_assign' => ['required', 'boolean'],
        ]);

        $shop->lead_auto_assign = $data['lead_auto_assign'];
        $shop->save();

        return response()->json(['lead_auto_assign' => (bool) $shop->lead_auto_assign]);
    }
```

In `routes/api.php`, add with the other static routes (before the `{lead}` routes):

```php
    Route::patch ('/shop/leads/settings',          [\App\Http\Controllers\LeadController::class, 'updateSettings'])->middleware('can.perm:leads.assign');
```

- [ ] **Step 6: Run to verify they pass**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan optimize:clear && php artisan test tests/Feature/LeadAssignmentTest.php'
```
Expected: PASS — 21 passed.

- [ ] **Step 7: Commit**

```bash
git add app routes tests
git commit -m "feat(hunt): round-robin lead distribution with per-shop toggle

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 7: Scope the Hunt reports to the acting agent

**Files:**
- Modify: `app/Services/Reports/ReportsAggregator.php:317-432`
- Test: `tests/Feature/LeadAssignmentTest.php`

**Interfaces:**
- Consumes: `Rbac::seesAllLeads()`.
- Produces: `ReportsAggregator::huntSummary()` and `wonValueTotals()` return only the acting agent's numbers when that agent cannot see all leads.

**Why this is its own task:** these methods use raw `DB::table('leads')`, which a global Eloquent scope does **not** touch. Without an explicit filter an agent's Hunt summary would report the whole shop's pipeline and revenue.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/LeadAssignmentTest.php`:

```php
    public function test_hunt_reports_show_an_agent_only_their_own_numbers(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);
        $b = $this->agent($shop);

        Lead::create(['shop_id' => $shop->id, 'name' => 'A won', 'status' => 'won',
            'assigned_to_id' => $a->id, 'deal_amount' => 100, 'deal_type' => 'one_off', 'deal_won_at' => now()]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'B won', 'status' => 'won',
            'assigned_to_id' => $b->id, 'deal_amount' => 500, 'deal_type' => 'one_off', 'deal_won_at' => now()]);

        $agg = app(\App\Services\Reports\ReportsAggregator::class);
        $from = now()->subMonth();
        $to = now()->addDay();

        \App\Support\CurrentShopUser::set($a);
        $mine = $agg->huntSummary($shop->id, $from, $to);
        $this->assertSame(1, $mine['total_leads']);
        $this->assertSame(1, $mine['won']);
        $this->assertSame(100.0, $mine['won_value']);

        \App\Support\CurrentShopUser::set($this->owner($shop));
        $all = $agg->huntSummary($shop->id, $from, $to);
        $this->assertSame(2, $all['total_leads']);
        $this->assertSame(600.0, $all['won_value']);
    }
```

- [ ] **Step 2: Run to verify it fails**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php --filter=hunt_reports'
```
Expected: FAIL — `2 is not identical to 1` (the agent sees the whole shop).

- [ ] **Step 3: Add the agent filter**

In `app/Services/Reports/ReportsAggregator.php`, add `use App\Support\Rbac;` to the imports and this private helper immediately above `wonValueTotals`:

```php
    /**
     * The agent whose leads the caller is limited to, or null when they see the
     * whole shop. These methods use the raw query builder for portability, so
     * the Lead model's global scope does NOT apply — the filter must be explicit
     * or an agent would read the whole shop's pipeline and revenue.
     */
    private function agentLeadFilter(): ?int
    {
        $user = current_shop_user();

        return Rbac::seesAllLeads($user) ? null : $user?->id;
    }
```

In `wonValueTotals`, change the query construction to:

```php
        $agent = $this->agentLeadFilter();
        $q = DB::table('leads')->where('shop_id', $shopId)->where('status', 'won')
            ->when($agent !== null, fn ($b) => $b->where('assigned_to_id', $agent));
```

In `huntSummary`, add at the top of the method (after `$statuses = Lead::STATUSES;`):

```php
        $agent = $this->agentLeadFilter();
```

Then add `->when($agent !== null, fn ($b) => $b->where('assigned_to_id', $agent))` to each of these four queries:

- the pipeline snapshot `DB::table('leads')->where('shop_id', $shopId)`
- the `$newLeads` count
- the `$wonInPeriod` count
- the `lead_activities` join — here the column is qualified: `->when($agent !== null, fn ($b) => $b->where('leads.assigned_to_id', $agent))`

- [ ] **Step 4: Run to verify it passes**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php tests/Feature/LeadDealValueTest.php'
```
Expected: PASS — 22 passed plus the existing deal-value suite.

- [ ] **Step 5: Write the failing test for the per-agent breakdown**

Append to `tests/Feature/LeadAssignmentTest.php`:

```php
    public function test_managers_get_a_per_agent_breakdown_and_agents_get_nothing(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $a = $this->agent($shop);
        $b = $this->agent($shop);

        Lead::create(['shop_id' => $shop->id, 'name' => 'A1', 'status' => 'won',
            'assigned_to_id' => $a->id, 'deal_amount' => 100, 'deal_type' => 'one_off', 'deal_won_at' => now()]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'A2', 'status' => 'new', 'assigned_to_id' => $a->id]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'B1', 'status' => 'won',
            'assigned_to_id' => $b->id, 'deal_amount' => 50, 'deal_type' => 'one_off', 'deal_won_at' => now()]);
        Lead::create(['shop_id' => $shop->id, 'name' => 'Pool', 'status' => 'new']);

        $agg = app(\App\Services\Reports\ReportsAggregator::class);
        $from = now()->subMonth();
        $to = now()->addDay();

        \App\Support\CurrentShopUser::set($this->owner($shop));
        $rows = $agg->huntByAgent($shop->id, $from, $to);

        $byId = collect($rows)->keyBy('id');
        $this->assertSame(2, $byId[$a->id]['leads']);
        $this->assertSame(1, $byId[$a->id]['won']);
        $this->assertSame(100.0, $byId[$a->id]['won_value']);
        $this->assertSame(1, $byId[$b->id]['leads']);
        $this->assertSame(50.0, $byId[$b->id]['won_value']);

        // An agent gets no leaderboard — their own figures already mean "mine".
        \App\Support\CurrentShopUser::set($a);
        $this->assertSame([], $agg->huntByAgent($shop->id, $from, $to));
    }
```

- [ ] **Step 6: Run to verify it fails**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php --filter=per_agent_breakdown'
```
Expected: FAIL — `Call to undefined method ...::huntByAgent()`.

- [ ] **Step 7: Implement the breakdown**

In `app/Services/Reports/ReportsAggregator.php`, add this method after `huntSummary`:

```php
    /**
     * Per-agent Hunt performance for the shop, newest-won first. Returns [] for
     * a caller who cannot see all leads — an agent's own huntSummary already
     * means "mine", so a one-row leaderboard would be noise.
     *
     * `leads` is a current snapshot of leads held; `won` and `won_value` are
     * period-bound, matching huntSummary's conventions.
     *
     * @return array<int, array{id: int, name: string, leads: int, won: int, won_value: float}>
     */
    public function huntByAgent(int $shopId, Carbon $from, Carbon $to): array
    {
        if ($this->agentLeadFilter() !== null) {
            return [];
        }

        $held = DB::table('leads')->where('shop_id', $shopId)
            ->whereNotNull('assigned_to_id')
            ->selectRaw('assigned_to_id, count(*) as c')
            ->groupBy('assigned_to_id')
            ->pluck('c', 'assigned_to_id');

        $wonRows = DB::table('leads')->where('shop_id', $shopId)
            ->whereNotNull('assigned_to_id')
            ->where('status', 'won')
            ->whereNotNull('deal_won_at')
            ->whereBetween('deal_won_at', [$from, $to])
            ->get(['assigned_to_id', 'deal_amount', 'deal_type', 'deal_term_months']);

        $wonCount = [];
        $wonValue = [];
        foreach ($wonRows as $row) {
            $id = (int) $row->assigned_to_id;
            $wonCount[$id] = ($wonCount[$id] ?? 0) + 1;

            $amount = (float) ($row->deal_amount ?? 0);
            if ($amount <= 0) {
                continue;
            }
            if ($row->deal_type === 'recurring') {
                $term = (int) ($row->deal_term_months ?? 0);
                if ($term <= 0) {
                    continue; // incomplete recurring — no computable total
                }
                $amount *= $term;
            }
            $wonValue[$id] = ($wonValue[$id] ?? 0) + $amount;
        }

        $names = DB::table('shop_users')->where('shop_id', $shopId)->pluck('name', 'id');

        $out = [];
        foreach ($names as $id => $name) {
            $id = (int) $id;
            $leads = (int) ($held[$id] ?? 0);
            $won = (int) ($wonCount[$id] ?? 0);
            if ($leads === 0 && $won === 0) {
                continue; // never handed a lead — keep the table to real agents
            }
            $out[] = [
                'id' => $id,
                'name' => (string) $name,
                'leads' => $leads,
                'won' => $won,
                'won_value' => round((float) ($wonValue[$id] ?? 0), 2),
            ];
        }

        usort($out, fn ($a, $b) => $b['won_value'] <=> $a['won_value']);

        return $out;
    }
```

- [ ] **Step 8: Run to verify it passes**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php tests/Feature/LeadDealValueTest.php'
```
Expected: PASS — 23 passed plus the existing deal-value suite.

- [ ] **Step 9: Commit**

```bash
git add app tests
git commit -m "fix(hunt): scope raw report queries to the acting agent, add per-agent breakdown

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 8: The `assign_lead` voice tool

**Files:**
- Modify: `app/Services/Assistant/Modules/HuntTools.php`
- Test: `tests/Feature/LeadAssignmentTest.php`

**Interfaces:**
- Consumes: `Lead::assignTo()`.
- Produces: assistant tool `assign_lead(lead: string, assignee: string)`, gated `leads.assign`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/LeadAssignmentTest.php`:

```php
    public function test_voice_tool_assigns_a_lead_and_refuses_ambiguous_names(): void
    {
        (new PermissionSeeder())->run();
        $shop = Shop::factory()->create(['modules' => ['leads']]);
        $manager = $this->agent($shop, ['leads.view', 'leads.view_all', 'leads.assign', 'leads.manage']);
        $sara = ShopUser::factory()->create(['shop_id' => $shop->id, 'name' => 'Sara']);
        $lead = Lead::create(['shop_id' => $shop->id, 'name' => 'Acme Salon', 'status' => 'new']);

        \App\Support\CurrentShopUser::set($manager);
        setPermissionsTeamId($shop->id);

        $tools = app(\App\Services\Assistant\Modules\HuntTools::class);

        $ok = $tools->run(new \App\Services\Assistant\Support\ToolCall(
            'assign_lead', ['lead' => 'Acme', 'assignee' => 'Sara', 'confirmed' => true], $shop,
        ));
        $this->assertTrue($ok['ok'] ?? false);
        $this->assertSame($sara->id, $lead->fresh()->assigned_to_id);

        $missing = $tools->run(new \App\Services\Assistant\Support\ToolCall(
            'assign_lead', ['lead' => 'Acme', 'assignee' => 'Nobody', 'confirmed' => true], $shop,
        ));
        $this->assertSame('user_not_found', $missing['error'] ?? null);
    }
```

> If `ToolCall`'s constructor signature differs, match the one used in `tests/Feature/HuntAssistantToolsTest.php` — read that file first and mirror it exactly.

- [ ] **Step 2: Run to verify it fails**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php --filter=voice_tool'
```
Expected: FAIL — `unknown_tool`.

- [ ] **Step 3: Implement the tool**

In `app/Services/Assistant/Modules/HuntTools.php`, add `use App\Models\ShopUser;` to the imports.

Add to the `permissions()` array:

```php
            'assign_lead' => 'leads.assign',
```

Add to the `handle()` match:

```php
            'assign_lead' => $this->assignLead($call),
```

Add this method to the class:

```php
    /**
     * Hand a lead to a colleague by name. Both the lead and the person are
     * resolved in PHP against the shop — the model never decides identity, and
     * an ambiguous match is refused rather than guessed at.
     */
    private function assignLead(ToolCall $call): array
    {
        $leadRef = trim((string) ($call->get('lead') ?? ''));
        $assignee = trim((string) ($call->get('assignee') ?? ''));
        if ($leadRef === '' || $assignee === '') {
            return ['error' => 'missing_arguments'];
        }

        $leads = Lead::forShop($call->shop->id)
            ->where('name', 'like', "%{$leadRef}%")->limit(5)->get();
        if ($leads->isEmpty()) {
            return ['error' => 'lead_not_found'];
        }
        if ($leads->count() > 1) {
            return ['error' => 'ambiguous_lead', 'matches' => $leads->pluck('name')->all()];
        }

        $users = ShopUser::where('shop_id', $call->shop->id)
            ->where('is_active', true)
            ->where('name', 'like', "%{$assignee}%")->limit(5)->get();
        if ($users->isEmpty()) {
            return ['error' => 'user_not_found'];
        }
        if ($users->count() > 1) {
            return ['error' => 'ambiguous_user', 'matches' => $users->pluck('name')->all()];
        }

        $lead = $leads->first();
        $target = $users->first();
        $lead->assignTo($target, current_shop_user());

        return ['ok' => true, 'lead' => $lead->name, 'assigned_to' => $target->name];
    }
```

Add the schema to the array returned by the tool-definitions method (alongside `save_leads`):

```php
            ['name' => 'assign_lead', 'description' => 'Hand a saved lead to a colleague so they own it. Give the business name and the person\'s name. Confirm first.', 'input_schema' => ['type' => 'object', 'properties' => [
                'lead' => ['type' => 'string', 'description' => 'The business name of the saved lead.'],
                'assignee' => ['type' => 'string', 'description' => 'The name of the team member to give it to.'],
            ], 'required' => ['lead', 'assignee']]],
```

- [ ] **Step 4: Run to verify it passes**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan test tests/Feature/LeadAssignmentTest.php tests/Feature/HuntAssistantToolsTest.php'
```
Expected: PASS — 24 passed plus the existing assistant suite.

- [ ] **Step 5: Commit**

```bash
git add app tests
git commit -m "feat(hunt): assign_lead voice tool

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 9: Frontend types and API client

**Files:**
- Modify: `admin/src/types.ts:253-307`
- Modify: `admin/src/lib/leads.ts:182-199`
- Test: `admin/src/lib/leads.assign.test.ts` (create)

**Interfaces:**
- Consumes: the endpoints from Tasks 4-6.
- Produces:
  - `Assignee = { id: number; name: string; is_active?: boolean }`
  - `Lead.assigned_to?: Assignee | null`
  - `LeadListResponse.assignees: Assignee[]`, `.auto_assign: boolean`
  - `LeadFilters.assigned_to?: 'me' | 'unassigned' | number`
  - `assignLead(id, assigneeId)`, `assignLeadsBulk(ids, assigneeId)`, `setLeadAutoAssign(on)`

- [ ] **Step 1: Write the failing test**

Create `admin/src/lib/leads.assign.test.ts`:

```ts
import { describe, expect, it, vi, beforeEach } from 'vitest';
import api from './api';
import { assignLead, assignLeadsBulk, setLeadAutoAssign, listLeads } from './leads';

vi.mock('./api', () => ({
  default: { get: vi.fn(), post: vi.fn(), patch: vi.fn() },
}));

describe('lead assignment client', () => {
  beforeEach(() => { vi.clearAllMocks(); });

  it('assigns one lead', async () => {
    (api.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { data: { id: 1 } } });
    await assignLead(1, 7);
    expect(api.patch).toHaveBeenCalledWith('/shop/leads/1/assign', { assigned_to_id: 7 });
  });

  it('unassigns with null', async () => {
    (api.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { data: { id: 1 } } });
    await assignLead(1, null);
    expect(api.patch).toHaveBeenCalledWith('/shop/leads/1/assign', { assigned_to_id: null });
  });

  it('bulk assigns', async () => {
    (api.post as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { assigned: 3 } });
    const n = await assignLeadsBulk([1, 2, 3], 7);
    expect(api.post).toHaveBeenCalledWith('/shop/leads/assign', { ids: [1, 2, 3], assigned_to_id: 7 });
    expect(n).toBe(3);
  });

  it('toggles auto-assign', async () => {
    (api.patch as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { lead_auto_assign: true } });
    expect(await setLeadAutoAssign(true)).toBe(true);
    expect(api.patch).toHaveBeenCalledWith('/shop/leads/settings', { lead_auto_assign: true });
  });

  it('defaults assignees and auto_assign when the API omits them', async () => {
    (api.get as ReturnType<typeof vi.fn>).mockResolvedValue({ data: { data: [] } });
    const res = await listLeads();
    expect(res.assignees).toEqual([]);
    expect(res.auto_assign).toBe(false);
  });
});
```

- [ ] **Step 2: Run to verify it fails**

```bash
cd admin && npx vitest run src/lib/leads.assign.test.ts
```
Expected: FAIL — `assignLead is not a function`.

- [ ] **Step 3: Extend the types**

In `admin/src/types.ts`, add above `export type Lead`:

```ts
/** A team member a lead can be handed to. */
export type Assignee = { id: number; name: string; is_active?: boolean };
```

Add to the `Lead` type (after `pipeline`):

```ts
  /** The agent who owns this lead. null = unassigned pool. */
  assigned_to?: Assignee | null;
  assigned_to_id?: number | null;
  assigned_at?: string | null;
```

Extend `LeadListResponse`:

```ts
  /** Team members this user may hand leads to. Empty without leads.assign. */
  assignees: Assignee[];
  /** Whether this shop auto-distributes newly saved leads. */
  auto_assign: boolean;
```

Extend the `LeadActivity` payload union:

```ts
  payload?: { from?: string; to?: string; note?: string; from_name?: string; to_name?: string } | null;
```

- [ ] **Step 4: Extend the API client**

In `admin/src/lib/leads.ts`, add `assigned_to` to `LeadFilters`:

```ts
export type LeadFilters = {
  status?: LeadStatus;
  category?: string;
  pipeline?: string;
  search?: string;
  followups?: 'due';
  /** 'me' | 'unassigned' | a shop user id. */
  assigned_to?: 'me' | 'unassigned' | number;
};
```

Add the two new keys to the object `listLeads` returns:

```ts
    assignees: Array.isArray(data?.assignees) ? data.assignees : [],
    auto_assign: Boolean(data?.auto_assign),
```

Append these functions to the file:

```ts
/** Hand one lead to a team member, or pass null to return it to the pool. */
export async function assignLead(id: number, assigneeId: number | null): Promise<Lead> {
  const { data } = await api.patch(`/shop/leads/${id}/assign`, { assigned_to_id: assigneeId });
  return data.data;
}

/** Hand several leads at once. Resolves to the number actually assigned. */
export async function assignLeadsBulk(ids: number[], assigneeId: number | null): Promise<number> {
  const { data } = await api.post('/shop/leads/assign', { ids, assigned_to_id: assigneeId });
  return Number(data?.assigned ?? 0);
}

/** Turn this shop's automatic round-robin hand-out on or off. */
export async function setLeadAutoAssign(on: boolean): Promise<boolean> {
  const { data } = await api.patch('/shop/leads/settings', { lead_auto_assign: on });
  return Boolean(data?.lead_auto_assign);
}
```

Ensure `Lead` is imported in `leads.ts` from `@/types` (it already imports other lead types — add `Assignee` only if you reference it).

- [ ] **Step 5: Run to verify it passes**

```bash
cd admin && npx vitest run src/lib/leads.assign.test.ts && npx tsc --noEmit
```
Expected: PASS — 5 passed, and no type errors.

> `tsc` will flag every existing construction of `LeadListResponse` that now lacks `assignees`/`auto_assign`. Fix each by adding `assignees: []` and `auto_assign: false` — mostly in `admin/src/pages/Leads.test.tsx`.

- [ ] **Step 6: Commit**

```bash
git add admin/src/types.ts admin/src/lib/leads.ts admin/src/lib/leads.assign.test.ts admin/src/pages/Leads.test.tsx
git commit -m "feat(admin): lead assignment types and API client

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 10: Owner chip, filters, multi-select and the auto-assign toggle

**Files:**
- Modify: `admin/src/pages/Leads.tsx` (the `PipelinePane` component, lines 471-660)
- Modify: `admin/src/styles/` — append to the stylesheet that already defines `.lf-filters` (find it with `grep -rn "lf-filters" admin/src/styles/`)
- Test: `admin/src/pages/Leads.assign.test.tsx` (create)

**Interfaces:**
- Consumes: `assignLead`, `assignLeadsBulk`, `setLeadAutoAssign`, `listLeads`, `useCan`.
- Produces: no new exports.

- [ ] **Step 1: Write the failing test**

Create `admin/src/pages/Leads.assign.test.tsx`:

```tsx
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import { MemoryRouter } from 'react-router-dom';
import Leads from './Leads';

const listLeads = vi.fn();
const assignLeadsBulk = vi.fn();

vi.mock('@/lib/leads', async () => {
  const actual = await vi.importActual<typeof import('@/lib/leads')>('@/lib/leads');
  return {
    ...actual,
    listLeads: (...a: unknown[]) => listLeads(...a),
    assignLeadsBulk: (...a: unknown[]) => assignLeadsBulk(...a),
    getLeadCredits: vi.fn().mockResolvedValue({ credits: 5, can_purchase: false, embedded_checkout: false, packs: [] }),
  };
});

vi.mock('@/lib/useCan', () => ({ useCan: () => () => true }));

const lead = (id: number, name: string, owner: { id: number; name: string } | null) => ({
  id, name, status: 'new' as const, assigned_to: owner,
});

describe('Leads pipeline assignment', () => {
  beforeEach(() => {
    vi.clearAllMocks();
    listLeads.mockResolvedValue({
      data: [lead(1, 'Acme Salon', { id: 7, name: 'Sara' }), lead(2, 'Pool Co', null)],
      funnel: { new: 2, sent: 0, followup: 0, replied: 0, demo: 0, won: 0, pass: 0 },
      pipelines: [],
      won_value: 0,
      assignees: [{ id: 7, name: 'Sara' }],
      auto_assign: false,
    });
  });

  it('shows the owner on each lead and Unassigned when there is none', async () => {
    render(<MemoryRouter><Leads /></MemoryRouter>);
    await waitFor(() => expect(screen.getByText('Sara')).toBeInTheDocument());
    expect(screen.getByText('Unassigned')).toBeInTheDocument();
  });

  it('bulk assigns the selected leads', async () => {
    assignLeadsBulk.mockResolvedValue(1);
    render(<MemoryRouter><Leads /></MemoryRouter>);
    await waitFor(() => expect(screen.getByText('Acme Salon')).toBeInTheDocument());

    await userEvent.click(screen.getByLabelText('Select Acme Salon'));
    await userEvent.selectOptions(screen.getByLabelText('Assign selected to'), '7');

    await waitFor(() => expect(assignLeadsBulk).toHaveBeenCalledWith([1], 7));
  });
});
```

> The pipeline list only renders after the pane is shown. If `Leads` opens on the Find tab, click the Pipeline tab first — read the `Mode` toggle at `Leads.tsx:67` and add the click before the assertions.

- [ ] **Step 2: Run to verify it fails**

```bash
cd admin && npx vitest run src/pages/Leads.assign.test.tsx
```
Expected: FAIL — `Unable to find an element with the text: Sara`.

- [ ] **Step 3: Add state and handlers to `PipelinePane`**

In `admin/src/pages/Leads.tsx`, add these imports at the top:

```tsx
import { assignLeadsBulk, setLeadAutoAssign } from '@/lib/leads';
import { useCan } from '@/lib/useCan';
import type { Assignee } from '@/types';
```

Inside `PipelinePane`, add after the `const [view, setView] = ...` block:

```tsx
  const can = useCan();
  const mayAssign = can('leads.assign');
  const [assignees, setAssignees] = useState<Assignee[]>([]);
  const [autoAssign, setAutoAssign] = useState(false);
  const [ownerFilter, setOwnerFilter] = useState<'me' | 'unassigned' | number | null>(null);
  const [picked, setPicked] = useState<Record<number, boolean>>({});
  const pickedIds = Object.keys(picked).filter((k) => picked[Number(k)]).map(Number);
```

Add `ownerFilter` to the paging reset effect's dependency array (line 498) and to the `fetch` callback: pass `assigned_to: ownerFilter ?? undefined` in the `listLeads` argument, capture the new response fields, and add `ownerFilter` to the `useCallback` deps:

```tsx
      const res = await listLeads({
        status: statusFilter ?? undefined,
        pipeline: pipelineFilter ?? undefined,
        search: search.trim() || undefined,
        followups: dueOnly ? 'due' : undefined,
        assigned_to: ownerFilter ?? undefined,
      });
      setLeads(res.data);
      setFunnel(res.funnel);
      setPipelines(res.pipelines);
      setAssignees(res.assignees);
      setAutoAssign(res.auto_assign);
```

Add these handlers after `changeStatus`:

```tsx
  const assignPicked = async (assigneeId: number | null) => {
    if (pickedIds.length === 0) return;
    try {
      await assignLeadsBulk(pickedIds, assigneeId);
      setPicked({});
      await fetch();
    } catch {
      setError('Could not assign those leads.');
    }
  };

  const toggleAutoAssign = async (on: boolean) => {
    setAutoAssign(on);
    try {
      await setLeadAutoAssign(on);
    } catch {
      setAutoAssign(!on);
      setError('Could not change auto-assign.');
    }
  };
```

- [ ] **Step 4: Render the filter, toggle and action bar**

Inside the `<div className="lf-filters">` block, after the "Due today" button, add:

```tsx
        {mayAssign && (
          <label className="lf-pipefilter">
            <Icons.User size={14} />
            <select
              value={ownerFilter === null ? '' : String(ownerFilter)}
              onChange={(e) => {
                const v = e.target.value;
                setOwnerFilter(v === '' ? null : v === 'me' || v === 'unassigned' ? v : Number(v));
              }}
              aria-label="Filter by owner"
            >
              <option value="">All owners</option>
              <option value="unassigned">Unassigned</option>
              {assignees.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
            </select>
          </label>
        )}
        {mayAssign && (
          <label className="lf-autoassign">
            <input type="checkbox" checked={autoAssign} onChange={(e) => void toggleAutoAssign(e.target.checked)} />
            <span>Auto-assign new leads</span>
          </label>
        )}
```

> If `Icons.User` does not exist, use `Icons.Users` — check the export list in the icons module before writing this.

Immediately after the closing `</div>` of `lf-filters`, add the action bar:

```tsx
      {mayAssign && pickedIds.length > 0 && (
        <div className="lf-assignbar">
          <span>{pickedIds.length} selected</span>
          <select
            defaultValue=""
            aria-label="Assign selected to"
            onChange={(e) => {
              const v = e.target.value;
              if (v !== '') void assignPicked(v === 'unassigned' ? null : Number(v));
              e.target.value = '';
            }}
          >
            <option value="" disabled>Assign to…</option>
            <option value="unassigned">Unassigned (pool)</option>
            {assignees.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
          <button className="lf-linkbtn" onClick={() => setPicked({})}>Clear</button>
        </div>
      )}
```

- [ ] **Step 5: Add the checkbox and owner chip to each lead row**

Inside the card renderer (the `view === 'cards'` branch starting line 598) and the list renderer, add to each lead's markup — the checkbox first, then the chip near the status control:

```tsx
              {mayAssign && (
                <input
                  type="checkbox"
                  className="lf-pick"
                  aria-label={`Select ${lead.name}`}
                  checked={!!picked[lead.id]}
                  onChange={(e) => setPicked((p) => ({ ...p, [lead.id]: e.target.checked }))}
                  onClick={(e) => e.stopPropagation()}
                />
              )}
```

```tsx
              <span className={`lf-owner${lead.assigned_to ? '' : ' none'}`}>
                {lead.assigned_to?.name ?? 'Unassigned'}
              </span>
```

- [ ] **Step 6: Add the styles**

Append to the stylesheet that defines `.lf-filters`:

```css
/* --- Lead ownership ------------------------------------------------- */
.lf-owner {
  display: inline-flex;
  align-items: center;
  padding: 2px 8px;
  border-radius: 999px;
  font-size: 12px;
  font-weight: 600;
  background: rgba(16, 185, 129, 0.12);
  color: var(--mint-700, #047857);
  white-space: nowrap;
}
.lf-owner.none {
  background: rgba(148, 163, 184, 0.16);
  color: #64748b;
  font-weight: 500;
}
.lf-autoassign {
  display: inline-flex;
  align-items: center;
  gap: 6px;
  font-size: 13px;
  white-space: nowrap;
}
.lf-assignbar {
  display: flex;
  align-items: center;
  gap: 10px;
  flex-wrap: wrap;
  margin: 8px 0 4px;
  padding: 8px 12px;
  border-radius: 12px;
  background: rgba(16, 185, 129, 0.08);
  border: 1px solid rgba(16, 185, 129, 0.22);
  font-size: 13px;
}
.lf-pick { width: 16px; height: 16px; cursor: pointer; }

@media (prefers-color-scheme: dark) {
  .lf-owner { background: rgba(16, 185, 129, 0.18); color: #6ee7b7; }
  .lf-owner.none { background: rgba(148, 163, 184, 0.2); color: #94a3b8; }
}
```

- [ ] **Step 7: Run to verify it passes**

```bash
cd admin && npx vitest run src/pages/Leads.assign.test.tsx src/pages/Leads.test.tsx && npx tsc --noEmit
```
Expected: PASS — both suites green, no type errors.

- [ ] **Step 8: Commit**

```bash
git add admin/src
git commit -m "feat(admin): lead owner chip, owner filter, bulk assign and auto-assign toggle

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 11: The assignee picker on lead detail

**Files:**
- Modify: `admin/src/pages/LeadDetail.tsx`
- Test: `admin/src/pages/LeadDetail.test.tsx`

**Interfaces:**
- Consumes: `assignLead`, `listLeads` (for the assignee list), `useCan`.
- Produces: no new exports.

- [ ] **Step 1: Write the failing test**

Append to `admin/src/pages/LeadDetail.test.tsx` (mirroring the mock setup already at the top of that file — read it first and extend the existing `vi.mock('@/lib/leads', …)` rather than adding a second one):

```tsx
  it('assigns the lead from the detail page', async () => {
    render(<MemoryRouter initialEntries={['/leads/1']}><LeadDetail /></MemoryRouter>);
    await waitFor(() => expect(screen.getByLabelText('Assigned to')).toBeInTheDocument());

    await userEvent.selectOptions(screen.getByLabelText('Assigned to'), '7');

    await waitFor(() => expect(assignLead).toHaveBeenCalledWith(1, 7));
  });
```

Add `assignLead` to that file's `vi.mock('@/lib/leads', …)` factory as `assignLead: (...a: unknown[]) => assignLead(...a)` with a matching `const assignLead = vi.fn();` above, and make the mocked `listLeads` resolve `assignees: [{ id: 7, name: 'Sara' }]`.

- [ ] **Step 2: Run to verify it fails**

```bash
cd admin && npx vitest run src/pages/LeadDetail.test.tsx
```
Expected: FAIL — `Unable to find a label with the text of: Assigned to`.

- [ ] **Step 3: Implement the picker**

In `admin/src/pages/LeadDetail.tsx`, add the imports:

```tsx
import { assignLead, listLeads } from '@/lib/leads';
import { useCan } from '@/lib/useCan';
import type { Assignee } from '@/types';
```

Add state inside the component, alongside the existing lead state:

```tsx
  const can = useCan();
  const mayAssign = can('leads.assign');
  const [assignees, setAssignees] = useState<Assignee[]>([]);

  // The assignee list rides along on the leads index — no extra endpoint, and
  // it is already withheld from users who cannot assign.
  useEffect(() => {
    if (!mayAssign) return;
    let alive = true;
    listLeads().then((r) => { if (alive) setAssignees(r.assignees); }).catch(() => {});
    return () => { alive = false; };
  }, [mayAssign]);

  const changeOwner = async (value: string) => {
    if (!lead) return;
    try {
      const updated = await assignLead(lead.id, value === '' ? null : Number(value));
      setLead((prev) => (prev ? { ...prev, ...updated } : prev));
    } catch {
      setError('Could not change the owner.');
    }
  };
```

> Match the existing state setter names in this file — if the lead state is held as `setLead`/`setError` under different names, use those.

Render the row in the lead's detail card, near the status control:

```tsx
      <div className="ld-row">
        <span className="ld-label">Owner</span>
        {mayAssign ? (
          <select
            aria-label="Assigned to"
            value={lead.assigned_to?.id ?? ''}
            onChange={(e) => void changeOwner(e.target.value)}
          >
            <option value="">Unassigned</option>
            {assignees.map((a) => <option key={a.id} value={a.id}>{a.name}</option>)}
          </select>
        ) : (
          <span className={`lf-owner${lead.assigned_to ? '' : ' none'}`}>
            {lead.assigned_to?.name ?? 'Unassigned'}
          </span>
        )}
      </div>
```

> Use whatever row/label class names this file already uses for its other detail rows instead of `ld-row`/`ld-label` if they differ.

- [ ] **Step 4: Render `assigned` activities in the timeline**

Find where the activity list maps `type === 'status_change'` and add a branch:

```tsx
              : a.type === 'assigned'
                ? `Assigned to ${a.payload?.to_name ?? 'nobody'}${a.payload?.from_name ? ` (was ${a.payload.from_name})` : ''}`
```

- [ ] **Step 5: Run to verify it passes**

```bash
cd admin && npx vitest run src/pages/LeadDetail.test.tsx && npx tsc --noEmit
```
Expected: PASS, no type errors.

- [ ] **Step 6: Commit**

```bash
git add admin/src
git commit -m "feat(admin): assignee picker and assigned activity on lead detail

Co-Authored-By: Claude Opus 4.8 <noreply@anthropic.com>"
```

---

### Task 12: Full verification and deploy

**Files:** none changed unless a regression is found.

- [ ] **Step 1: Run the entire backend suite**

```bash
scp -r app tests database routes root@64.227.153.90:/root/testrun/
ssh root@64.227.153.90 'cd /root/testrun && php artisan optimize:clear && php artisan test'
```
Expected: PASS — every test green. The baseline before this work was 569 passing; this plan adds 24, so expect ≥593. If a pre-existing broken test file aborts collection, pass explicit file paths instead.

- [ ] **Step 2: Run the entire frontend suite and type-check**

```bash
cd admin && npx vitest run && npx tsc --noEmit
```
Expected: PASS, no type errors.

- [ ] **Step 3: Push to main**

```bash
git push origin main
```

- [ ] **Step 4: Deploy the backend to staging**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && git fetch && git reset --hard origin/main && php8.4 artisan migrate --force && php8.4 artisan optimize:clear'
```
Expected: the three new migrations run, and the backfill reports the number of roles granted `leads.view_all`.

- [ ] **Step 5: Deploy the admin SPA to staging**

```bash
cd admin && VITE_API_URL=https://staging-api.eloquentservice.com/api npm run build
```
Then tar `dist/` → extract into `/var/www/admin-staging` and `chown www-data`.

- [ ] **Step 6: Verify on staging by hand**

On `https://staging-admin.eloquentservice.com`, with a Hunt shop:
1. Confirm existing users still see every lead (the backfill worked).
2. Create a role without `leads.view_all`, assign a user to it, log in as them — confirm they see nothing until a lead is assigned.
3. Assign a lead to them as the owner; confirm it appears for them and the activity timeline shows "Assigned to …".
4. Turn on Auto-assign, save a search batch as the owner, confirm the batch spreads across agents and the owner receives none.
5. As the agent, run a search and save — confirm the results stay visible to them.

- [ ] **Step 7: Promote to production**

Only after step 6 is clean:

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && git fetch && git reset --hard origin/main && php8.4 artisan migrate --force && php8.4 artisan optimize:clear && php8.4 artisan route:cache && php8.4 artisan config:cache'
```
Then deploy the admin SPA with `admin/deploy.ps1`.

- [ ] **Step 8: Tear down the test harness**

```bash
ssh root@64.227.153.90 'rm -rf /root/testrun'
```
