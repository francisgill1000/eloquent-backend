# Assistant Confirm Directive Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Move assistant write-confirmation from the model's discretion to a Confirm button in the SPA, so a skipped model turn can no longer produce a false "Done!" with no write.

**Architecture:** `MutatingTool::gate()` records every preview as an `assistant_pending_actions` row and hands its id to the request-scoped `AssistantActions` sink, which `OwnerAssistantController` already attaches to the reply as `action`. A new `POST /shop/assistant/confirm` re-executes the tool server-side from the stored input. Destructive tools refuse a `confirmed: true` that came from the model; everything else may still self-confirm, and doing so resolves the open row so the card can't double-write.

**Tech Stack:** Laravel 12 + PostgreSQL (prod/staging) / sqlite (tests), PHPUnit 11, React 18 + TypeScript + Vite, Vitest + Testing Library.

## Global Constraints

- Work directly on `main`. No feature branches (standing project rule).
- Backend tests run on the droplet: `ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit <path>"`. Local PHP cannot run the suite. Copy changed files up with `scp` before running, or `git pull` on staging after pushing.
- NEVER run the test suite against the prod database or the prod app directory. `phpunit.xml` pins sqlite `:memory:`, but a cached config has previously overridden it and wiped prod.
- Frontend tests run locally: `cd admin && npx vitest run <path>` and `npx tsc --noEmit`.
- Baseline before this plan: 688 backend tests green, 369 frontend tests green.
- Pending action TTL is exactly 30 minutes.
- The confirmation line persisted after a successful confirm is `'✅ ' . $summary`, composed server-side. The model never writes it.
- Destructive tool list, verbatim: `delete_booking`, `cancel_booking`, `update_booking_status`, `delete_staff`, `delete_service`, `delete_category`, `delete_customer`, `delete_user`, `delete_role`.

---

## File Structure

**Create:**
- `database/migrations/2026_08_04_000001_create_assistant_pending_actions_table.php` — the table.
- `app/Models/AssistantPendingAction.php` — model + `open()` scope + `isLive()`.
- `tests/Feature/Assistant/PendingActionGateTest.php` — gate-level behaviour.
- `tests/Feature/Assistant/AssistantConfirmEndpointTest.php` — HTTP endpoint behaviour.

**Modify:**
- `app/Services/Assistant/Support/ToolCall.php` — add `userConfirmed`.
- `app/Services/Assistant/AssistantToolRegistry.php` — `execute()` gains `$userConfirmed`.
- `app/Services/Assistant/Support/MutatingTool.php` — `destructive()`, pending-row recording, self-confirm resolution.
- `app/Services/Assistant/Support/AssistantActions.php` — `confirm()` + `forConversation()`.
- Six modules declare destructive tools: `Modules/BookingTools.php`, `Modules/StaffTools.php`, `Modules/ServiceTools.php`, `Modules/CategoryTools.php`, `Modules/CustomerTools.php`, `Modules/AccessTools.php`.
- `app/Http/Controllers/OwnerAssistantController.php` — set conversation on the sink, backfill `conversation_id`, add `confirm()`.
- `routes/api.php` — the new route.
- `tests/Feature/OwnerAssistantConfirmGateTest.php` — existing test asserts the OLD destructive behaviour; must be updated.
- `admin/src/lib/assistant.ts` — action union + `confirmAction()`.
- `admin/src/pages/VoiceAssistant.tsx` — the card.
- `admin/src/styles/assistant.css` — card styling (confirm the exact filename with `ls admin/src/styles` before editing; use whichever file already holds the `va-` classes).
- `admin/src/pages/VoiceAssistant.test.tsx` — card tests.

---

### Task 1: Pending actions table and model

**Files:**
- Create: `database/migrations/2026_08_04_000001_create_assistant_pending_actions_table.php`
- Create: `app/Models/AssistantPendingAction.php`
- Test: `tests/Feature/Assistant/PendingActionGateTest.php`

**Interfaces:**
- Consumes: nothing.
- Produces: `App\Models\AssistantPendingAction` with fillable `shop_id, conversation_id, tool, input, summary, changes, destructive, resolved_at, expires_at`; casts `input` and `changes` to `array`, `destructive` to `bool`, `resolved_at` and `expires_at` to `datetime`. Instance method `isLive(): bool`. Query scope `open(int $shopId, string $tool)`.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Assistant/PendingActionGateTest.php`:

```php
<?php
namespace Tests\Feature\Assistant;

use App\Models\AssistantPendingAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PendingActionGateTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_row_is_live_and_a_resolved_or_expired_one_is_not(): void
    {
        $live = AssistantPendingAction::create([
            'shop_id' => 1, 'tool' => 'create_staff', 'input' => ['name' => 'Jhon'],
            'summary' => 'Add staff member "Jhon"', 'changes' => ['staff' => 'new: Jhon'],
            'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ]);
        $this->assertTrue($live->isLive());
        $this->assertSame(['name' => 'Jhon'], $live->fresh()->input); // json round-trip

        $resolved = AssistantPendingAction::create([
            'shop_id' => 1, 'tool' => 'create_staff', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false,
            'expires_at' => now()->addMinutes(30), 'resolved_at' => now(),
        ]);
        $this->assertFalse($resolved->isLive());

        $expired = AssistantPendingAction::create([
            'shop_id' => 1, 'tool' => 'create_staff', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false, 'expires_at' => now()->subMinute(),
        ]);
        $this->assertFalse($expired->isLive());
    }

    public function test_open_scope_finds_only_live_rows_for_that_shop_and_tool(): void
    {
        $mine = AssistantPendingAction::create([
            'shop_id' => 1, 'tool' => 'create_staff', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ]);
        AssistantPendingAction::create([ // other shop
            'shop_id' => 2, 'tool' => 'create_staff', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ]);
        AssistantPendingAction::create([ // other tool
            'shop_id' => 1, 'tool' => 'create_service', 'input' => [], 'summary' => 'x',
            'changes' => [], 'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ]);

        $found = AssistantPendingAction::open(1, 'create_staff')->get();
        $this->assertSame([$mine->id], $found->pluck('id')->all());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

```bash
scp tests/Feature/Assistant/PendingActionGateTest.php root@64.227.153.90:/var/www/eloquent-backend-staging/tests/Feature/Assistant/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php"
```

Expected: FAIL — `Class "App\Models\AssistantPendingAction" not found`.

- [ ] **Step 3: Write the migration**

Create `database/migrations/2026_08_04_000001_create_assistant_pending_actions_table.php`:

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assistant_pending_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('shop_id');
            // Nullable: the controller creates the conversation lazily AFTER a
            // successful reply, so a first-turn preview has no thread yet.
            $table->unsignedBigInteger('conversation_id')->nullable();
            $table->string('tool');
            $table->json('input');
            $table->string('summary');
            $table->json('changes');
            $table->boolean('destructive')->default(false);
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();
            $table->index(['shop_id', 'tool', 'resolved_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assistant_pending_actions');
    }
};
```

- [ ] **Step 4: Write the model**

Create `app/Models/AssistantPendingAction.php`:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * A previewed-but-unwritten assistant tool call, held until the owner taps
 * Confirm in the chat. The SPA only ever receives the id — confirming
 * re-executes from `input` here, so the values written are the values shown.
 */
class AssistantPendingAction extends Model
{
    protected $fillable = [
        'shop_id', 'conversation_id', 'tool', 'input', 'summary',
        'changes', 'destructive', 'resolved_at', 'expires_at',
    ];

    protected $casts = [
        'input' => 'array',
        'changes' => 'array',
        'destructive' => 'bool',
        'resolved_at' => 'datetime',
        'expires_at' => 'datetime',
    ];

    /** Still confirmable: neither already applied nor timed out. */
    public function isLive(): bool
    {
        return $this->resolved_at === null && $this->expires_at->isFuture();
    }

    /** Live rows for one shop + tool — used to resolve a card the model self-confirmed. */
    public function scopeOpen(Builder $q, int $shopId, string $tool): Builder
    {
        return $q->where('shop_id', $shopId)
            ->where('tool', $tool)
            ->whereNull('resolved_at')
            ->where('expires_at', '>', now());
    }
}
```

- [ ] **Step 5: Run test to verify it passes**

```bash
scp database/migrations/2026_08_04_000001_create_assistant_pending_actions_table.php root@64.227.153.90:/var/www/eloquent-backend-staging/database/migrations/
scp app/Models/AssistantPendingAction.php root@64.227.153.90:/var/www/eloquent-backend-staging/app/Models/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php"
```

Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
git add database/migrations/2026_08_04_000001_create_assistant_pending_actions_table.php app/Models/AssistantPendingAction.php tests/Feature/Assistant/PendingActionGateTest.php
git commit -m "feat(assistant): assistant_pending_actions table and model"
```

---

### Task 2: Distinguish a user confirm from a model confirm

**Files:**
- Modify: `app/Services/Assistant/Support/ToolCall.php`
- Modify: `app/Services/Assistant/AssistantToolRegistry.php:88-105`
- Test: `tests/Feature/Assistant/PendingActionGateTest.php`

**Interfaces:**
- Consumes: nothing from Task 1.
- Produces: `ToolCall` gains a sixth constructor parameter `public readonly bool $userConfirmed = false`. `AssistantToolRegistry::execute(Shop $shop, string $tool, array $input, bool $userConfirmed = false): string`.

The gate needs to know *who* set `confirmed`. Without this, the confirm endpoint could not write a destructive tool, because the gate refuses model confirms on exactly those tools.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Assistant/PendingActionGateTest.php`:

```php
    public function test_tool_call_defaults_user_confirmed_to_false(): void
    {
        $shop = \App\Models\Shop::create(['name' => 'S', 'shop_code' => '7401', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $call = new \App\Services\Assistant\Support\ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], true);
        $this->assertFalse($call->userConfirmed);

        $userCall = new \App\Services\Assistant\Support\ToolCall($shop, null, 'create_staff', [], true, true);
        $this->assertTrue($userCall->userConfirmed);
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
scp tests/Feature/Assistant/PendingActionGateTest.php root@64.227.153.90:/var/www/eloquent-backend-staging/tests/Feature/Assistant/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php"
```

Expected: FAIL — `Undefined property: ToolCall::$userConfirmed`.

- [ ] **Step 3: Add the property**

In `app/Services/Assistant/Support/ToolCall.php`, add a sixth promoted parameter after `$confirmed`:

```php
    public function __construct(
        public readonly Shop $shop,
        public readonly ?ShopUser $actingUser,
        public readonly string $tool,
        public readonly array $input,
        public readonly bool $confirmed,
        /** True only when the owner tapped Confirm in the app — never when the
         *  model set confirmed itself. Destructive tools require this. */
        public readonly bool $userConfirmed = false,
    ) {}
```

- [ ] **Step 4: Thread it through the registry**

In `app/Services/Assistant/AssistantToolRegistry.php`, replace the `execute()` signature and `ToolCall` construction:

```php
    public function execute(Shop $shop, string $tool, array $input, bool $userConfirmed = false): string
    {
        $call = new ToolCall(
            shop: $shop,
            actingUser: current_shop_user(),
            tool: $tool,
            input: $input,
            confirmed: $userConfirmed || (bool) ($input['confirmed'] ?? false),
            userConfirmed: $userConfirmed,
        );
```

The rest of the method body is unchanged.

- [ ] **Step 5: Run tests to verify they pass**

```bash
scp app/Services/Assistant/Support/ToolCall.php root@64.227.153.90:/var/www/eloquent-backend-staging/app/Services/Assistant/Support/
scp app/Services/Assistant/AssistantToolRegistry.php root@64.227.153.90:/var/www/eloquent-backend-staging/app/Services/Assistant/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php tests/Feature/AssistantToolRegistryTest.php"
```

Expected: PASS. The default `false` keeps every existing caller working.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Assistant/Support/ToolCall.php app/Services/Assistant/AssistantToolRegistry.php tests/Feature/Assistant/PendingActionGateTest.php
git commit -m "feat(assistant): distinguish a user confirm from a model confirm on ToolCall"
```

---

### Task 3: Gate records a pending row and refuses model confirms on destructive tools

**Files:**
- Modify: `app/Services/Assistant/Support/MutatingTool.php`
- Modify: `app/Services/Assistant/Support/AssistantActions.php`
- Test: `tests/Feature/Assistant/PendingActionGateTest.php`

**Interfaces:**
- Consumes: `AssistantPendingAction` (Task 1), `ToolCall::$userConfirmed` (Task 2).
- Produces: `MutatingTool::destructive(): array` (protected, defaults `[]`). `AssistantActions::confirm(AssistantPendingAction $row): void`, `AssistantActions::forConversation(?int $id): void`, and `pending()` now returns either the navigate shape or `['type' => 'confirm', 'id' => int, 'summary' => string, 'changes' => array, 'destructive' => bool]`.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Assistant/PendingActionGateTest.php` (add `use App\Models\Shop;`, `use App\Models\Staff;`, `use App\Services\Assistant\Modules\StaffTools;`, `use App\Services\Assistant\Support\AssistantActions;`, `use App\Services\Assistant\Support\ToolCall;` at the top):

```php
    private function shop(string $code): Shop
    {
        return Shop::create(['name' => 'S', 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_preview_records_a_pending_row_and_emits_a_confirm_action(): void
    {
        $shop = $this->shop('7410');
        $actions = app(AssistantActions::class);

        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], false));

        $this->assertTrue($out['preview']);
        $row = AssistantPendingAction::where('shop_id', $shop->id)->firstOrFail();
        $this->assertSame('create_staff', $row->tool);
        $this->assertSame(['name' => 'Jhon'], $row->input);
        $this->assertSame('Add staff member "Jhon"', $row->summary);
        $this->assertFalse($row->destructive);
        $this->assertTrue($row->isLive());

        $this->assertSame([
            'type' => 'confirm', 'id' => $row->id,
            'summary' => 'Add staff member "Jhon"',
            'changes' => ['staff' => 'new: Jhon'],
            'destructive' => false,
        ], $actions->pending());
    }

    public function test_destructive_tool_refuses_a_model_supplied_confirm_and_writes_nothing(): void
    {
        $shop = $this->shop('7411');
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        // confirmed:true, but it came from the model — not a user tap.
        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'delete_staff', ['name' => 'Ali'], true, false));

        $this->assertTrue($out['preview']);
        $this->assertFalse($out['saved']);
        $this->assertSame(1, Staff::where('shop_id', $shop->id)->count()); // still there
        $this->assertTrue(AssistantPendingAction::where('shop_id', $shop->id)->firstOrFail()->destructive);
    }

    public function test_destructive_tool_writes_when_the_user_confirmed(): void
    {
        $shop = $this->shop('7412');
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);

        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'delete_staff', ['name' => 'Ali'], true, true));

        $this->assertTrue($out['done']);
        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_non_destructive_tool_still_self_confirms(): void
    {
        $shop = $this->shop('7413');

        $out = app(StaffTools::class)->run(new ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], true, false));

        $this->assertTrue($out['done']);
        $this->assertSame(1, Staff::where('shop_id', $shop->id)->count());
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
scp tests/Feature/Assistant/PendingActionGateTest.php root@64.227.153.90:/var/www/eloquent-backend-staging/tests/Feature/Assistant/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php"
```

Expected: FAIL — no pending row is written, and `delete_staff` with `confirmed: true` deletes.

- [ ] **Step 3: Extend AssistantActions**

Replace the body of `app/Services/Assistant/Support/AssistantActions.php`:

```php
<?php
namespace App\Services\Assistant\Support;

use App\Models\AssistantPendingAction;

/**
 * Request-scoped sink for UI directives a tool wants to hand back to the chat
 * client: a navigation, or a pending change awaiting the owner's tap. A tool
 * records intent here; the owner assistant controller reads it after the tool
 * loop and attaches it to the reply. Bound as a singleton so the tool and the
 * controller share one instance.
 */
class AssistantActions
{
    private ?array $action = null;

    private ?int $conversationId = null;

    public function navigate(string $route): void
    {
        $this->action = ['type' => 'navigate', 'route' => $route];
    }

    /** Hand the client a change to confirm. Only the id crosses the wire. */
    public function confirm(AssistantPendingAction $row): void
    {
        $this->action = [
            'type' => 'confirm',
            'id' => $row->id,
            'summary' => $row->summary,
            'changes' => $row->changes,
            'destructive' => $row->destructive,
        ];
    }

    /**
     * The thread this turn belongs to, so a pending row can be tied to it. Null
     * on the first turn of a new chat — the controller backfills it once the
     * conversation exists.
     */
    public function forConversation(?int $id): void
    {
        $this->conversationId = $id;
    }

    public function conversationId(): ?int
    {
        return $this->conversationId;
    }

    /** @return array<string, mixed>|null */
    public function pending(): ?array
    {
        return $this->action;
    }
}
```

- [ ] **Step 4: Rewrite the gate**

In `app/Services/Assistant/Support/MutatingTool.php`, add the imports and replace `gate()`, keeping `preview()` and `applied()` exactly as they are:

```php
use App\Models\AssistantPendingAction;

    /**
     * Tools whose write cannot be triggered by the model — only by the owner
     * tapping Confirm. Override per module.
     *
     * @return array<int, string>
     */
    protected function destructive(): array
    {
        return [];
    }

    protected function gate(ToolCall $call, callable $resolve, callable $describe, callable $write): array
    {
        $target = $resolve();

        // resolve() may hand back a terminal response (notFound()/ambiguous(),
        // always arrays) — pass it straight through. Guard with is_array first:
        // a resolved record may be a plain stdClass (DB row) which would throw
        // on array access, and Eloquent models need no such check.
        if (is_array($target) && (isset($target['error']) || isset($target['ambiguous']))) {
            return $target;
        }

        // A destructive tool ignores a confirmed flag the model set itself: the
        // model skips the confirm turn ~12% of the time and narrates success
        // anyway, so its say-so is not enough to delete anything.
        $destructive = in_array($call->tool, $this->destructive(), true);
        $mayWrite = $call->confirmed && (! $destructive || $call->userConfirmed);

        if (! $mayWrite) {
            [$action, $changes] = $describe($target);
            $row = AssistantPendingAction::create([
                'shop_id' => $call->shop->id,
                'conversation_id' => app(AssistantActions::class)->conversationId(),
                'tool' => $call->tool,
                'input' => $call->input,
                'summary' => $action,
                'changes' => $changes,
                'destructive' => $destructive,
                'expires_at' => now()->addMinutes(30),
            ]);
            app(AssistantActions::class)->confirm($row);
            return $this->preview($action, $changes);
        }

        return $this->applied($write($target));
    }
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
scp app/Services/Assistant/Support/MutatingTool.php app/Services/Assistant/Support/AssistantActions.php root@64.227.153.90:/var/www/eloquent-backend-staging/app/Services/Assistant/Support/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php"
```

Expected: the two non-destructive tests PASS. `test_destructive_tool_refuses_a_model_supplied_confirm_and_writes_nothing` and `test_destructive_tool_writes_when_the_user_confirmed` still FAIL, because `StaffTools` has not declared `delete_staff` destructive yet — Task 4 does that. Do not attempt to fix them here.

- [ ] **Step 6: Commit**

```bash
git add app/Services/Assistant/Support/MutatingTool.php app/Services/Assistant/Support/AssistantActions.php tests/Feature/Assistant/PendingActionGateTest.php
git commit -m "feat(assistant): record every preview as a pending action, ban model confirms on destructive tools"
```

---

### Task 4: Modules declare their destructive tools

**Files:**
- Modify: `app/Services/Assistant/Modules/BookingTools.php`, `StaffTools.php`, `ServiceTools.php`, `CategoryTools.php`, `CustomerTools.php`, `AccessTools.php`
- Test: `tests/Feature/Assistant/PendingActionGateTest.php` (already written in Task 3)

**Interfaces:**
- Consumes: `MutatingTool::destructive()` (Task 3).
- Produces: nothing new.

- [ ] **Step 1: Confirm the failing tests**

```bash
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php --filter destructive"
```

Expected: FAIL on both destructive tests.

- [ ] **Step 2: Add the override to each module**

Insert this method directly beneath the existing `permissions()` method in each file, with the listed tools:

`Modules/BookingTools.php`:
```php
    protected function destructive(): array
    {
        return ['delete_booking', 'cancel_booking', 'update_booking_status'];
    }
```

`Modules/StaffTools.php`:
```php
    protected function destructive(): array
    {
        return ['delete_staff'];
    }
```

`Modules/ServiceTools.php`:
```php
    protected function destructive(): array
    {
        return ['delete_service'];
    }
```

`Modules/CategoryTools.php`:
```php
    protected function destructive(): array
    {
        return ['delete_category'];
    }
```

`Modules/CustomerTools.php`:
```php
    protected function destructive(): array
    {
        return ['delete_customer'];
    }
```

`Modules/AccessTools.php`:
```php
    protected function destructive(): array
    {
        return ['delete_user', 'delete_role'];
    }
```

Before editing, run `grep -n "'delete_\|'cancel_\|'update_booking_status'" app/Services/Assistant/Modules/*.php` and confirm every name above exists in that module's `permissions()` map. If a name is absent, the module has been renamed since this plan was written — use the actual name and note the discrepancy in the commit message.

- [ ] **Step 3: Run tests to verify they pass**

```bash
scp app/Services/Assistant/Modules/*.php root@64.227.153.90:/var/www/eloquent-backend-staging/app/Services/Assistant/Modules/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php"
```

Expected: PASS, all tests in the file.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Assistant/Modules/
git commit -m "feat(assistant): declare the nine destructive tools"
```

---

### Task 5: A model self-confirm resolves the open card

**Files:**
- Modify: `app/Services/Assistant/Support/MutatingTool.php`
- Test: `tests/Feature/Assistant/PendingActionGateTest.php`

**Interfaces:**
- Consumes: `AssistantPendingAction::open()` (Task 1), the gate (Task 3).
- Produces: nothing new.

Without this, a non-destructive tool the model self-confirms leaves a live card, and tapping it writes a second staff member.

- [ ] **Step 1: Write the failing test**

Append to `tests/Feature/Assistant/PendingActionGateTest.php`:

```php
    public function test_a_self_confirmed_write_resolves_the_open_card(): void
    {
        $shop = $this->shop('7414');

        // Turn 1: preview leaves a live card.
        app(StaffTools::class)->run(new ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], false));
        $row = AssistantPendingAction::where('shop_id', $shop->id)->firstOrFail();
        $this->assertTrue($row->isLive());

        // Turn 2: the model confirms it itself.
        app(StaffTools::class)->run(new ToolCall($shop, null, 'create_staff', ['name' => 'Jhon'], true, false));

        $this->assertSame(1, Staff::where('shop_id', $shop->id)->count());
        $this->assertFalse($row->fresh()->isLive()); // card can no longer double-write
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
scp tests/Feature/Assistant/PendingActionGateTest.php root@64.227.153.90:/var/www/eloquent-backend-staging/tests/Feature/Assistant/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php --filter self_confirmed"
```

Expected: FAIL — the row is still live.

- [ ] **Step 3: Resolve open rows on write**

In `app/Services/Assistant/Support/MutatingTool.php`, replace the final line of `gate()`:

```php
        $result = $this->applied($write($target));

        // The model confirmed this itself, so any card the client is still
        // showing for the same tool is spent — resolve it or a tap would
        // write a second time.
        AssistantPendingAction::open($call->shop->id, $call->tool)->update(['resolved_at' => now()]);

        return $result;
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
scp app/Services/Assistant/Support/MutatingTool.php root@64.227.153.90:/var/www/eloquent-backend-staging/app/Services/Assistant/Support/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/PendingActionGateTest.php"
```

Expected: PASS, all tests in the file.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/Support/MutatingTool.php tests/Feature/Assistant/PendingActionGateTest.php
git commit -m "feat(assistant): a self-confirmed write resolves the open confirm card"
```

---

### Task 6: The confirm endpoint

**Files:**
- Modify: `app/Http/Controllers/OwnerAssistantController.php`
- Modify: `routes/api.php:282`
- Test: `tests/Feature/Assistant/AssistantConfirmEndpointTest.php`

**Interfaces:**
- Consumes: `AssistantPendingAction` (Task 1), `AssistantToolRegistry::execute($shop, $tool, $input, $userConfirmed)` (Task 2), `ConversationStore::append(Conversation $c, string $role, string $content)` and `ConversationStore::toApi(AssistantMessage $m): array` (existing).
- Produces: `POST /api/shop/assistant/confirm` accepting `{id: int}`, returning `201 {applied: bool, reply_text: string, message: array}` on success, `404` for another shop's id, `409` for a resolved or expired row.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/Assistant/AssistantConfirmEndpointTest.php`:

```php
<?php
namespace Tests\Feature\Assistant;

use App\Models\AssistantPendingAction;
use App\Models\Conversation;
use App\Models\Shop;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class AssistantConfirmEndpointTest extends TestCase
{
    use RefreshDatabase;

    private function shop(string $code): Shop
    {
        $shop = Shop::create(['name' => 'S', 'shop_code' => $code, 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        return $shop;
    }

    private function pending(Shop $shop, array $attrs = []): AssistantPendingAction
    {
        return AssistantPendingAction::create(array_merge([
            'shop_id' => $shop->id, 'tool' => 'create_staff', 'input' => ['name' => 'Jhon'],
            'summary' => 'Add staff member "Jhon"', 'changes' => ['staff' => 'new: Jhon'],
            'destructive' => false, 'expires_at' => now()->addMinutes(30),
        ], $attrs));
    }

    public function test_confirming_writes_from_the_stored_input(): void
    {
        $shop = $this->shop('7420');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])
            ->assertCreated()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('reply_text', '✅ Add staff member "Jhon"');

        $this->assertSame(['Jhon'], Staff::where('shop_id', $shop->id)->pluck('name')->all());
        $this->assertNotNull($row->fresh()->resolved_at);
    }

    public function test_the_client_cannot_smuggle_different_values(): void
    {
        $shop = $this->shop('7421');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop);

        // Extra keys in the request body must be ignored: only `id` is read.
        $this->postJson('/api/shop/assistant/confirm', [
            'id' => $row->id, 'tool' => 'delete_user', 'input' => ['name' => 'Mallory'],
        ])->assertCreated();

        $this->assertSame(['Jhon'], Staff::where('shop_id', $shop->id)->pluck('name')->all());
    }

    public function test_a_destructive_row_writes_because_the_user_confirmed_it(): void
    {
        $shop = $this->shop('7422');
        Sanctum::actingAs($shop, ['*']);
        Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $row = $this->pending($shop, [
            'tool' => 'delete_staff', 'input' => ['name' => 'Ali'],
            'summary' => 'Delete staff member "Ali"', 'destructive' => true,
        ]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertCreated();

        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_another_shops_row_is_not_found(): void
    {
        $mine = $this->shop('7423');
        $theirs = $this->shop('7424');
        Sanctum::actingAs($mine, ['*']);
        $row = $this->pending($theirs);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertNotFound();
        $this->assertSame(0, Staff::where('shop_id', $theirs->id)->count());
    }

    public function test_a_resolved_row_conflicts_and_writes_nothing(): void
    {
        $shop = $this->shop('7425');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop, ['resolved_at' => now()]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertStatus(409);
        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_an_expired_row_conflicts_and_writes_nothing(): void
    {
        $shop = $this->shop('7426');
        Sanctum::actingAs($shop, ['*']);
        $row = $this->pending($shop, ['expires_at' => now()->subMinute()]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertStatus(409);
        $this->assertSame(0, Staff::where('shop_id', $shop->id)->count());
    }

    public function test_the_confirmation_line_is_appended_to_the_thread(): void
    {
        $shop = $this->shop('7427');
        Sanctum::actingAs($shop, ['*']);
        $conversation = Conversation::create(['shop_id' => $shop->id, 'title' => 'setup', 'source' => 'owner']);
        $row = $this->pending($shop, ['conversation_id' => $conversation->id]);

        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertCreated();

        $this->assertSame(
            ['✅ Add staff member "Jhon"'],
            $conversation->messages()->where('role', 'assistant')->pluck('content')->all(),
        );
    }
}
```

Before running, confirm the `Conversation` relation name with `grep -n "function messages" app/Models/Conversation.php`. If it differs, use the real name in the last test.

- [ ] **Step 2: Run test to verify it fails**

```bash
scp tests/Feature/Assistant/AssistantConfirmEndpointTest.php root@64.227.153.90:/var/www/eloquent-backend-staging/tests/Feature/Assistant/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/AssistantConfirmEndpointTest.php"
```

Expected: FAIL — 404 on every test, the route does not exist.

- [ ] **Step 3: Add the controller method**

In `app/Http/Controllers/OwnerAssistantController.php`, add `use App\Models\AssistantPendingAction;` and this method after `respond()`:

```php
    /**
     * Apply a previewed change the owner tapped Confirm on. Re-executes the
     * tool from the row's stored input — the client sends only an id, so the
     * values written are exactly the values it was shown. The confirmation
     * line is composed here, never by the model.
     */
    public function confirm(Request $request): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['id' => ['required', 'integer']]);

        $row = AssistantPendingAction::find($data['id']);
        abort_unless($row && $row->shop_id === $request->user()->id, 404);
        abort_unless($row->isLive(), 409, 'This change was already applied or has expired.');

        $result = json_decode(
            $this->registry->execute($request->user(), $row->tool, $row->input, userConfirmed: true),
            true,
        ) ?: [];

        $row->update(['resolved_at' => now()]);

        $applied = (bool) ($result['done'] ?? false);
        $line = $applied
            ? '✅ ' . $row->summary
            : "⚠️ Couldn't apply that — " . ($result['error'] ?? 'unknown_error') . '.';

        $message = null;
        if ($row->conversation_id && $conversation = Conversation::find($row->conversation_id)) {
            $message = $this->store->toApi($this->store->append($conversation, 'assistant', $line));
        }

        return response()->json(['applied' => $applied, 'reply_text' => $line, 'message' => $message], 201);
    }
```

Check the controller's constructor property names for the registry and store with `grep -n "public function __construct" -A 10 app/Http/Controllers/OwnerAssistantController.php` and use the actual names.

- [ ] **Step 4: Add the route**

In `routes/api.php`, directly after the `/shop/assistant/voice` line (282), inside the same middleware group:

```php
    // Applying a previewed change costs no model call, but it writes — so it
    // needs the same assistant gate as the turns that produced the preview.
    Route::post('/shop/assistant/confirm',                        [\App\Http\Controllers\OwnerAssistantController::class, 'confirm'])->middleware('can.perm:assistant.use');
```

- [ ] **Step 5: Run tests to verify they pass**

```bash
scp app/Http/Controllers/OwnerAssistantController.php root@64.227.153.90:/var/www/eloquent-backend-staging/app/Http/Controllers/
scp routes/api.php root@64.227.153.90:/var/www/eloquent-backend-staging/routes/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/Assistant/AssistantConfirmEndpointTest.php"
```

Expected: PASS (7 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/OwnerAssistantController.php routes/api.php tests/Feature/Assistant/AssistantConfirmEndpointTest.php
git commit -m "feat(assistant): POST /shop/assistant/confirm applies a previewed change"
```

---

### Task 7: Wire the conversation id, and fix the existing gate test

**Files:**
- Modify: `app/Http/Controllers/OwnerAssistantController.php:113-153`
- Modify: `tests/Feature/OwnerAssistantConfirmGateTest.php`
- Test: `tests/Feature/OwnerAssistantConfirmGateTest.php`

**Interfaces:**
- Consumes: `AssistantActions::forConversation()` (Task 3), the whole gate.
- Produces: nothing new.

`OwnerAssistantConfirmGateTest` currently asserts that `cancel_booking` with a model-supplied `confirmed: true` cancels the booking. That is exactly the behaviour this plan removes, so the test must be rewritten to assert the new contract. Rewriting it is the point of this task — do not "fix" the production code to keep the old assertion.

- [ ] **Step 1: Rewrite the existing test**

Replace the body of `test_mutation_only_runs_after_confirmation_turn` in `tests/Feature/OwnerAssistantConfirmGateTest.php` (keep the class, imports, and add `use App\Models\AssistantPendingAction;`):

```php
    public function test_a_destructive_turn_returns_a_confirm_card_instead_of_writing(): void
    {
        Storage::fake('public');
        $shop = Shop::create(['name' => 'A', 'shop_code' => '1', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);
        DB::table('bookings')->insert([
            'shop_id' => $shop->id, 'date' => now()->toDateString(), 'start_time' => '10:00',
            'end_time' => '10:30', 'status' => 'booked', 'charges' => 10, 'discount_amount' => 0,
            'services' => '[]', 'booking_reference' => 'BK00001', 'customer_name' => 'X',
            'created_at' => now(), 'updated_at' => now(),
        ]);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'cancel_booking', 'input' => ['reference' => 'BK00001', 'confirmed' => true]]]])
                ->push(['content' => [['type' => 'text', 'text' => 'Cancel BK00001? Confirm below.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGG', 200),
        ]);

        // Even with confirmed:true from the model, a destructive tool must not write.
        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'cancel BK00001'])->assertCreated();
        $this->assertSame('booked', DB::table('bookings')->where('booking_reference', 'BK00001')->value('status'));

        $res->assertJsonPath('action.type', 'confirm')->assertJsonPath('action.destructive', true);
        $row = AssistantPendingAction::firstOrFail();
        $this->assertSame('cancel_booking', $row->tool);
        $this->assertSame($res->json('action.id'), $row->id);

        // The owner taps Confirm — now it writes.
        $this->postJson('/api/shop/assistant/confirm', ['id' => $row->id])->assertCreated();
        $this->assertSame('cancelled', DB::table('bookings')->where('booking_reference', 'BK00001')->value('status'));
    }

    public function test_the_pending_row_is_tied_to_the_thread_the_turn_created(): void
    {
        Storage::fake('public');
        $shop = Shop::create(['name' => 'B', 'shop_code' => '2', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);

        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'create_staff', 'input' => ['name' => 'Jhon']]]])
                ->push(['content' => [['type' => 'text', 'text' => 'Add Jhon? Confirm below.']]]),
            'api.openai.com/v1/audio/speech' => Http::response('OGG', 200),
        ]);

        // First turn of a brand-new chat: the conversation is created lazily
        // AFTER the tool ran, so the row must be backfilled with its id.
        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'add staff Jhon'])->assertCreated();

        $this->assertSame($res->json('conversation_id'), AssistantPendingAction::firstOrFail()->conversation_id);
    }
```

- [ ] **Step 2: Run test to verify it fails**

```bash
scp tests/Feature/OwnerAssistantConfirmGateTest.php root@64.227.153.90:/var/www/eloquent-backend-staging/tests/Feature/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/OwnerAssistantConfirmGateTest.php"
```

Expected: the first test passes already (Tasks 3–6 delivered it); the second FAILS with `conversation_id` null.

- [ ] **Step 3: Set and backfill the conversation id**

In `app/Http/Controllers/OwnerAssistantController.php::respond()`, add this immediately before the `$this->claude->toolLoop(` call:

```php
        // Tie any preview this turn records to the thread it belongs to.
        $this->actions->forConversation($conversation?->id);
```

And immediately after `$conversation ??= $this->store->create($shop, $userText);`:

```php
        // A first turn creates the thread only after the tool ran, so a pending
        // row written mid-turn has no conversation_id yet. Backfill it.
        if ($action = $this->actions->pending()) {
            if (($action['type'] ?? null) === 'confirm') {
                \App\Models\AssistantPendingAction::where('id', $action['id'])
                    ->whereNull('conversation_id')
                    ->update(['conversation_id' => $conversation->id]);
            }
        }
```

- [ ] **Step 4: Run tests to verify they pass**

```bash
scp app/Http/Controllers/OwnerAssistantController.php root@64.227.153.90:/var/www/eloquent-backend-staging/app/Http/Controllers/
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit tests/Feature/OwnerAssistantConfirmGateTest.php tests/Feature/OwnerAssistantOpenBookingTest.php"
```

Expected: PASS. `OwnerAssistantOpenBookingTest` must stay green — `navigate` actions are untouched.

- [ ] **Step 5: Run the whole backend suite**

```bash
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && php8.4 vendor/bin/phpunit"
```

Expected: PASS. Any other test that asserted a model-confirmed destructive write now fails — fix those tests to the new contract, never the gate. Report each one you change.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/OwnerAssistantController.php tests/Feature/OwnerAssistantConfirmGateTest.php
git commit -m "feat(assistant): tie pending actions to their thread; update the confirm-gate test to the new contract"
```

---

### Task 8: SPA client — action union and confirm call

**Files:**
- Modify: `admin/src/lib/assistant.ts`
- Test: `admin/src/lib/assistant.test.ts`

**Interfaces:**
- Consumes: `POST /api/shop/assistant/confirm` (Task 6).
- Produces: exported types `ConfirmAction = { type: 'confirm'; id: number; summary: string; changes: Record<string, string>; destructive: boolean }` and `NavigateAction = { type: 'navigate'; route: string }`; `AssistantReply.action?: NavigateAction | ConfirmAction`; `confirmAction(id: number): Promise<{ applied: boolean; reply_text: string }>`.

- [ ] **Step 1: Write the failing test**

Append to `admin/src/lib/assistant.test.ts` (match the file's existing mocking style for `./api` — read the top of the file first and reuse it rather than inventing a second pattern):

```ts
  it('posts the pending action id and returns the applied line', async () => {
    asMock(api.post).mockResolvedValue({ data: { applied: true, reply_text: '✅ Add staff member "Jhon"', message: null } });

    const res = await confirmAction(42);

    expect(api.post).toHaveBeenCalledWith('/shop/assistant/confirm', { id: 42 });
    expect(res.applied).toBe(true);
    expect(res.reply_text).toBe('✅ Add staff member "Jhon"');
  });
```

- [ ] **Step 2: Run test to verify it fails**

```bash
cd admin && npx vitest run src/lib/assistant.test.ts
```

Expected: FAIL — `confirmAction is not exported`.

- [ ] **Step 3: Widen the type and add the call**

In `admin/src/lib/assistant.ts`, replace the `AssistantReply` type and append the function:

```ts
export type NavigateAction = { type: 'navigate'; route: string };
/** A change the assistant previewed but has NOT written. Confirming it posts
 *  only the id — the server re-executes from the arguments it stored. */
export type ConfirmAction = {
  type: 'confirm';
  id: number;
  summary: string;
  changes: Record<string, string>;
  destructive: boolean;
};

export type AssistantReply = {
  conversation_id?: number;
  title?: string;
  transcript?: string;
  reply_text: string;
  reply_audio_url: string | null;
  action?: NavigateAction | ConfirmAction;
};

export async function confirmAction(id: number): Promise<{ applied: boolean; reply_text: string }> {
  const { data } = await api.post('/shop/assistant/confirm', { id });
  return data;
}
```

- [ ] **Step 4: Run test and typecheck**

```bash
cd admin && npx vitest run src/lib/assistant.test.ts && npx tsc --noEmit
```

Expected: PASS, and no type errors. `tsc` will flag `VoiceAssistant.tsx:144` if it narrows `action` unsafely — if so, change that line to `if (res.action?.type === 'navigate') navigate(res.action.route);` which already discriminates correctly, and leave the rest to Task 9.

- [ ] **Step 5: Commit**

```bash
git add admin/src/lib/assistant.ts admin/src/lib/assistant.test.ts
git commit -m "feat(admin): confirm action type and confirmAction() client call"
```

---

### Task 9: SPA — the confirm card

**Files:**
- Modify: `admin/src/pages/VoiceAssistant.tsx`
- Modify: `admin/src/styles/assistant.css` (verify the filename holding the `va-` classes first)
- Test: `admin/src/pages/VoiceAssistant.test.tsx`

**Interfaces:**
- Consumes: `ConfirmAction`, `confirmAction()` (Task 8).
- Produces: nothing further.

Only the most recent turn can have a live card, so a single `pendingConfirm` state rendered at the end of the thread is enough — no per-message tracking.

- [ ] **Step 1: Write the failing test**

Append to `admin/src/pages/VoiceAssistant.test.tsx`, and add `confirmAction: vi.fn()` to the existing `vi.mock('@/lib/assistant', …)` factory:

```tsx
  it('shows a confirm card when the reply carries one, and applies it on tap', async () => {
    asMock(postText).mockResolvedValue({
      conversation_id: 9, title: 't', reply_text: 'Add Jhon? Confirm below.', reply_audio_url: null,
      action: { type: 'confirm', id: 42, summary: 'Add staff member "Jhon"', changes: { staff: 'new: Jhon' }, destructive: false },
    });
    asMock(confirmAction).mockResolvedValue({ applied: true, reply_text: '✅ Add staff member "Jhon"' });

    render(<VoiceAssistant />);
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'add staff Jhon' } });
    fireEvent.submit(screen.getByRole('textbox'));

    await waitFor(() => expect(screen.getByText('Add staff member "Jhon"')).toBeInTheDocument());
    expect(screen.getByText('new: Jhon')).toBeInTheDocument();

    fireEvent.click(screen.getByRole('button', { name: /confirm/i }));

    await waitFor(() => expect(confirmAction).toHaveBeenCalledWith(42));
    await waitFor(() => expect(screen.getByText('✅ Add staff member "Jhon"')).toBeInTheDocument());
    expect(screen.queryByRole('button', { name: /confirm/i })).not.toBeInTheDocument();
  });

  it('dismisses a confirm card without calling the server', async () => {
    asMock(postText).mockResolvedValue({
      conversation_id: 9, title: 't', reply_text: 'Delete Ali? Confirm below.', reply_audio_url: null,
      action: { type: 'confirm', id: 43, summary: 'Delete staff member "Ali"', changes: {}, destructive: true },
    });

    render(<VoiceAssistant />);
    fireEvent.change(screen.getByRole('textbox'), { target: { value: 'delete Ali' } });
    fireEvent.submit(screen.getByRole('textbox'));

    await waitFor(() => expect(screen.getByText('Delete staff member "Ali"')).toBeInTheDocument());
    fireEvent.click(screen.getByRole('button', { name: /dismiss/i }));

    await waitFor(() => expect(screen.queryByText('Delete staff member "Ali"')).not.toBeInTheDocument());
    expect(confirmAction).not.toHaveBeenCalled();
  });
```

The exact query for the text input and submit may differ — read the file's existing tests and copy how they drive a text turn.

- [ ] **Step 2: Run test to verify it fails**

```bash
cd admin && npx vitest run src/pages/VoiceAssistant.test.tsx
```

Expected: FAIL — no card renders.

- [ ] **Step 3: Add state and capture the action**

In `admin/src/pages/VoiceAssistant.tsx`, import `confirmAction` and `type ConfirmAction` from `@/lib/assistant`, then add beside the other `useState` calls:

```tsx
  const [pendingConfirm, setPendingConfirm] = useState<ConfirmAction | null>(null);
  const [confirming, setConfirming] = useState(false);
```

At both places that currently read `if (res.action?.type === 'navigate') navigate(res.action.route);` (lines 144 and 163), replace with:

```tsx
      if (res.action?.type === 'navigate') navigate(res.action.route);
      else if (res.action?.type === 'confirm') setPendingConfirm(res.action);
```

- [ ] **Step 4: Render the card**

In `VoiceAssistant.tsx`, directly after the `{messages.map(...)}` block and before `{(busy || simThinking) && <ThinkingBubble />}`:

```tsx
        {pendingConfirm && (
          <div className={`va-confirm${pendingConfirm.destructive ? ' va-confirm-danger' : ''}`}>
            <div className="va-confirm-summary">{pendingConfirm.summary}</div>
            {Object.entries(pendingConfirm.changes).map(([k, v]) => (
              <div key={k} className="va-confirm-change"><span>{k}</span>{v}</div>
            ))}
            <div className="va-confirm-actions">
              <button className="va-confirm-no" onClick={() => setPendingConfirm(null)} disabled={confirming}>Dismiss</button>
              <button
                className="va-confirm-yes"
                disabled={confirming}
                onClick={async () => {
                  setConfirming(true);
                  try {
                    const res = await confirmAction(pendingConfirm.id);
                    setMessages((m) => [...m, { role: 'assistant', content: res.reply_text, audioUrl: null, autoPlay: false }]);
                    setPendingConfirm(null);
                  } catch {
                    setError('Could not apply that change.');
                  } finally {
                    setConfirming(false);
                  }
                }}
              >
                {confirming ? 'Applying…' : 'Confirm'}
              </button>
            </div>
          </div>
        )}
```

Match the message-object shape to whatever `setMessages` already pushes elsewhere in the file — read one of the existing `setMessages` calls and mirror its fields exactly.

- [ ] **Step 5: Style the card**

Append to the stylesheet holding the `va-` classes:

```css
.va-confirm {
  align-self: flex-start;
  max-width: 85%;
  margin: 6px 0 2px;
  padding: 12px 14px;
  border: 1px solid var(--c-border, rgba(255, 255, 255, 0.14));
  border-radius: 14px;
  background: var(--c-card, rgba(255, 255, 255, 0.05));
}
.va-confirm-danger { border-color: rgba(239, 68, 68, 0.55); }
.va-confirm-summary { font-weight: 600; margin-bottom: 6px; }
.va-confirm-change { display: flex; gap: 8px; font-size: 13px; opacity: 0.85; }
.va-confirm-change span { opacity: 0.6; }
.va-confirm-actions { display: flex; gap: 8px; margin-top: 10px; }
.va-confirm-yes, .va-confirm-no {
  flex: 1; padding: 8px 12px; border-radius: 10px; font-weight: 600; cursor: pointer;
}
.va-confirm-yes { border: 0; background: var(--c-mint, #34d399); color: #04231a; }
.va-confirm-danger .va-confirm-yes { background: #ef4444; color: #fff; }
.va-confirm-no { border: 1px solid var(--c-border, rgba(255, 255, 255, 0.18)); background: transparent; color: inherit; }
.va-confirm-yes:disabled, .va-confirm-no:disabled { opacity: 0.55; cursor: default; }
```

Use the project's real CSS custom-property names — check the top of the stylesheet and substitute them rather than relying on the fallbacks above.

- [ ] **Step 6: Run tests and typecheck**

```bash
cd admin && npx vitest run && npx tsc --noEmit
```

Expected: PASS, all frontend tests, no type errors.

- [ ] **Step 7: Commit**

```bash
git add admin/src/pages/VoiceAssistant.tsx admin/src/pages/VoiceAssistant.test.tsx admin/src/styles/
git commit -m "feat(admin): confirm card in the assistant chat"
```

---

### Task 10: Deploy

**Files:** none.

**Interfaces:**
- Consumes: every prior task.
- Produces: the feature live on staging, then prod.

- [ ] **Step 1: Full backend suite on staging**

```bash
git push origin main
ssh root@64.227.153.90 "cd /var/www/eloquent-backend-staging && git pull --ff-only origin main && php artisan migrate --force && php artisan config:clear && php8.4 vendor/bin/phpunit"
```

Expected: PASS, and the migration applies cleanly.

- [ ] **Step 2: Verify the card end-to-end on staging**

Open `https://staging-admin.eloquentservice.com`, ask the assistant to add a staff member, and confirm that a card appears, that tapping Confirm writes the row, and that a second tap reports it already applied. Then ask it to delete that staff member and confirm the model cannot delete without your tap.

- [ ] **Step 3: Promote to prod**

```bash
ssh root@64.227.153.90 "cd /var/www/eloquent-backend && git pull --ff-only origin main && php artisan migrate --force && php artisan config:clear && php artisan config:cache"
cd admin && ./deploy.ps1
```

- [ ] **Step 4: Verify prod**

```bash
ssh root@64.227.153.90 "PGPASSWORD='1@Ab56ab56' psql -h 127.0.0.1 -U laravel_user -d laravel12_db -c '\d assistant_pending_actions'"
curl -sI https://api.eloquentservice.com/ | head -1
```

Expected: the table exists, and the API responds.

---

## Self-Review

**Spec coverage.** Data model → Task 1. `userConfirmed` split → Task 2. `gate()` recording previews and refusing model confirms on destructive tools → Task 3. The nine destructive declarations → Task 4. Double-write guard via self-confirm resolution → Task 5. Confirm endpoint, 404/409 handling, server-composed line → Task 6. Lazy-conversation backfill → Task 7. SPA types and client → Task 8. Card UI → Task 9. Deployment → Task 10. Out-of-scope items (tool-call logging, WhatsApp and public-booking assistants, model choice) have no tasks, as intended.

**Known interaction.** `tests/Feature/OwnerAssistantConfirmGateTest.php` asserts the behaviour this plan removes, and Task 7 rewrites it. Task 7 Step 5 also runs the full suite specifically to catch any other test that assumed a model-confirmed destructive write.

**Type consistency.** `AssistantPendingAction` fields are used identically in Tasks 1, 3, 5, 6, 7. `AssistantActions::confirm()` emits exactly the keys the SPA's `ConfirmAction` declares in Task 8 and destructures in Task 9. `execute()`'s fourth parameter is named `userConfirmed` in Task 2 and called by name in Task 6.
