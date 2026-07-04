# Voice Full Control — Plan 01: Foundation Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Introduce the modular assistant-tool architecture (a registry of domain tool modules, a `ToolCall` value object, a `MutatingTool` base with an enforced confirm-gate + RBAC, and a kill-switch) with **zero behaviour change** — the existing owner assistant runs exactly as today, just routed through the new registry.

**Architecture:** A thin `AssistantToolRegistry` aggregates tool `defs()` from a list of modules and routes `execute()` to the module that owns each tool. The existing `OwnerAssistantTools` is adapted to implement the module interface so nothing regresses; new gated domain modules (Bookings, Services, …) are added in later plans (02–09). Read modules are always available; modules extending `MutatingTool` are hidden by the `assistant.mutations_enabled` kill-switch.

**Tech Stack:** Laravel (PHP 8.2+), Pest/PHPUnit feature tests, Anthropic Messages API via the existing `ClaudeClient::toolLoop`.

## Global Constraints

- Every tool is scoped to the authenticated `Shop` (`$call->shop`) — no cross-shop access. (copied from spec)
- RBAC uses the existing `App\Support\Rbac::userCan(?ShopUser, string $permission)`; owner/untagged (null) sessions are all-allowed for backward compatibility.
- Permission names come only from `App\Support\PermissionCatalog` — never invent new ones.
- The confirm-gate invariant: a mutating tool writes **nothing** unless its `confirmed` input is `true`.
- Kill-switch config key is exactly `assistant.mutations_enabled` (default `true`).
- A tool's execute result returned to the model is a **JSON string** (the registry JSON-encodes the module's array result).
- Tests are run by the user on the branch (local/CI), not on this dev box. Every task still specifies the exact command + expected result.
- Reply/voice UX style (short, spoken, AED as "dirhams") is unchanged in this plan.

---

## File Structure

- Create `config/assistant.php` — kill-switch config.
- Create `app/Services/Assistant/Contracts/AssistantToolModule.php` — module interface.
- Create `app/Services/Assistant/Support/ToolCall.php` — per-call value object.
- Create `app/Services/Assistant/Support/AssistantModule.php` — abstract base: RBAC + `handles()` + ambiguous/notFound helpers.
- Create `app/Services/Assistant/Support/MutatingTool.php` — abstract base extending `AssistantModule`: the confirm `gate()` + preview/applied helpers; marker type for the kill-switch.
- Create `app/Services/Assistant/AssistantToolRegistry.php` — aggregation + routing + kill-switch filter.
- Modify `app/Services/Assistant/OwnerAssistantTools.php` — implement `AssistantToolModule` (add instance `defs()`, `handles()`, `run()`) delegating to the existing `execute()`. No behaviour change.
- Modify `app/Http/Controllers/OwnerAssistantController.php:22-28,90-95` — depend on `AssistantToolRegistry` instead of `OwnerAssistantTools`.
- Test `tests/Feature/AssistantToolRegistryTest.php`, `tests/Unit/MutatingToolTest.php`.

---

### Task 1: Kill-switch config

**Files:**
- Create: `config/assistant.php`
- Test: `tests/Feature/AssistantConfigTest.php`

**Interfaces:**
- Produces: config key `assistant.mutations_enabled` (bool, default `true`), env override `ASSISTANT_MUTATIONS_ENABLED`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature;

use Tests\TestCase;

class AssistantConfigTest extends TestCase
{
    public function test_mutations_enabled_defaults_true(): void
    {
        $this->assertTrue(config('assistant.mutations_enabled'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AssistantConfigTest`
Expected: FAIL — `config('assistant.mutations_enabled')` is `null`, not `true`.

- [ ] **Step 3: Create the config file**

```php
<?php
// config/assistant.php
return [
    /*
     | Master switch for the owner assistant's data-changing tools. When false,
     | the AssistantToolRegistry omits every MutatingTool module from defs() and
     | routing, so the assistant instantly reverts to read-only — no redeploy.
     */
    'mutations_enabled' => (bool) env('ASSISTANT_MUTATIONS_ENABLED', true),
];
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AssistantConfigTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add config/assistant.php tests/Feature/AssistantConfigTest.php
git commit -m "feat(assistant): add mutations_enabled kill-switch config"
```

---

### Task 2: Module interface + ToolCall value object

**Files:**
- Create: `app/Services/Assistant/Contracts/AssistantToolModule.php`
- Create: `app/Services/Assistant/Support/ToolCall.php`
- Test: `tests/Unit/ToolCallTest.php`

**Interfaces:**
- Produces `AssistantToolModule`:
  - `toolDefs(): array` — Anthropic tool schemas this module exposes. (Named `toolDefs`, not `defs`, because the legacy `OwnerAssistantTools` already has a **static** `defs()`; PHP forbids a class having both a static and an instance method of the same name.)
  - `handles(string $tool): bool` — whether this module owns the named tool.
  - `run(ToolCall $call): array` — execute; returns a plain array (registry JSON-encodes it).
- Produces `ToolCall` (readonly): `Shop $shop`, `?ShopUser $actingUser`, `string $tool`, `array $input`, `bool $confirmed`; helper `get(string $key, mixed $default = null): mixed`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Assistant\Support\ToolCall;
use PHPUnit\Framework\TestCase;

class ToolCallTest extends TestCase
{
    public function test_get_returns_input_value_or_default(): void
    {
        $call = new ToolCall(new Shop(), null, 'demo', ['price' => 50], true);
        $this->assertSame(50, $call->get('price'));
        $this->assertSame('x', $call->get('missing', 'x'));
        $this->assertTrue($call->confirmed);
        $this->assertSame('demo', $call->tool);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=ToolCallTest`
Expected: FAIL — class `App\Services\Assistant\Support\ToolCall` not found.

- [ ] **Step 3: Create the interface and value object**

```php
<?php
// app/Services/Assistant/Contracts/AssistantToolModule.php
namespace App\Services\Assistant\Contracts;

use App\Services\Assistant\Support\ToolCall;

interface AssistantToolModule
{
    /** @return array<int, array<string, mixed>> Anthropic tool schemas. */
    public function toolDefs(): array;

    public function handles(string $tool): bool;

    /** @return array<string, mixed> */
    public function run(ToolCall $call): array;
}
```

```php
<?php
// app/Services/Assistant/Support/ToolCall.php
namespace App\Services\Assistant\Support;

use App\Models\Shop;
use App\Models\ShopUser;

/** One assistant tool invocation, with the acting shop + user resolved. */
final class ToolCall
{
    public function __construct(
        public readonly Shop $shop,
        public readonly ?ShopUser $actingUser,
        public readonly string $tool,
        public readonly array $input,
        public readonly bool $confirmed,
    ) {}

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->input[$key] ?? $default;
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=ToolCallTest`
Expected: PASS

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/Contracts/AssistantToolModule.php app/Services/Assistant/Support/ToolCall.php tests/Unit/ToolCallTest.php
git commit -m "feat(assistant): add AssistantToolModule interface and ToolCall value object"
```

---

### Task 3: AssistantModule base (RBAC + handles + response helpers)

**Files:**
- Create: `app/Services/Assistant/Support/AssistantModule.php`
- Test: covered by Task 4's `MutatingToolTest` (the base is abstract; it is exercised through `MutatingTool`).

**Interfaces:**
- Produces abstract `AssistantModule implements AssistantToolModule`:
  - abstract `protected function permissions(): array` — `[toolName => permissionName]`.
  - abstract `protected function handle(ToolCall $call): array` — run an owned tool (RBAC already passed).
  - `handles(string $tool): bool` — true when `$tool` is a key of `permissions()`.
  - `run(ToolCall $call): array` — checks `Rbac::userCan`; denied → `['error' => 'no_permission']`; else `handle()`.
  - `protected function ambiguous(array $matches): array` → `['ambiguous' => true, 'matches' => $matches]`.
  - `protected function notFound(string $what = 'record'): array` → `['error' => 'not_found', 'what' => $what]`.
  - Still leaves `toolDefs()` abstract (each module supplies its own schemas).

- [ ] **Step 1: Create the abstract base** (no standalone test — verified via Task 4)

```php
<?php
// app/Services/Assistant/Support/AssistantModule.php
namespace App\Services\Assistant\Support;

use App\Services\Assistant\Contracts\AssistantToolModule;
use App\Support\Rbac;

/**
 * Shared base for every assistant tool module. Owns the RBAC gate and the
 * standard "ambiguous"/"not found" response shapes so every tool answers the
 * model consistently. Subclasses declare a tool=>permission map and a handler.
 */
abstract class AssistantModule implements AssistantToolModule
{
    /** @return array<string, string> toolName => required permission */
    abstract protected function permissions(): array;

    /** Handle a tool this module owns (RBAC already checked). */
    abstract protected function handle(ToolCall $call): array;

    public function handles(string $tool): bool
    {
        return array_key_exists($tool, $this->permissions());
    }

    public function run(ToolCall $call): array
    {
        $perm = $this->permissions()[$call->tool] ?? null;
        if ($perm !== null && ! Rbac::userCan($call->actingUser, $perm)) {
            return ['error' => 'no_permission'];
        }
        return $this->handle($call);
    }

    /** @param array<int, mixed> $matches */
    protected function ambiguous(array $matches): array
    {
        return ['ambiguous' => true, 'matches' => $matches];
    }

    protected function notFound(string $what = 'record'): array
    {
        return ['error' => 'not_found', 'what' => $what];
    }
}
```

- [ ] **Step 2: Commit** (bundled with Task 4, which tests it)

No separate commit — proceed to Task 4; commit together after `MutatingToolTest` passes.

---

### Task 4: MutatingTool base (the enforced confirm-gate)

**Files:**
- Create: `app/Services/Assistant/Support/MutatingTool.php`
- Test: `tests/Unit/MutatingToolTest.php` (defines a small fake subclass)

**Interfaces:**
- Consumes: `AssistantModule` (Task 3), `ToolCall` (Task 2).
- Produces abstract `MutatingTool extends AssistantModule`:
  - `protected function gate(ToolCall $call, callable $resolve, callable $describe, callable $write): array`
    - `$resolve(): array` — return the target record array, **or** a terminal response (`notFound()`/`ambiguous()`), which is passed straight through.
    - `$describe(array $target): array` — return `[string $action, array $changes]` for the preview.
    - `$write(array $target): array` — perform the write, return extra data merged into the result. Called **only** when `$call->confirmed === true`.
  - `protected function preview(string $action, array $changes = []): array` → `['preview' => true, 'action' => $action, 'changes' => $changes]`.
  - `protected function applied(array $data = []): array` → `['done' => true, ...$data]`.
  - Being an instance of `MutatingTool` is the marker the registry uses for the kill-switch.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Unit;

use App\Models\Shop;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;
use PHPUnit\Framework\TestCase;

/** Minimal concrete MutatingTool exercising the gate. */
class FakeRenameTool extends MutatingTool
{
    /** @var array<int, string> */
    public array $store = [1 => 'Old'];

    protected function permissions(): array
    {
        return ['rename_thing' => 'staff.manage'];
    }

    public function toolDefs(): array
    {
        return [];
    }

    protected function handle(ToolCall $call): array
    {
        return $this->gate(
            $call,
            resolve: fn () => $call->get('name') === 'ghost'
                ? $this->notFound('thing')
                : ['id' => 1, 'name' => $this->store[1]],
            describe: fn (array $t) => ["Rename to {$call->get('new')}", ['name' => "{$t['name']} → {$call->get('new')}"]],
            write: function (array $t) use ($call) {
                $this->store[$t['id']] = $call->get('new');
                return ['id' => $t['id']];
            },
        );
    }
}

class MutatingToolTest extends TestCase
{
    private function call(bool $confirmed, array $input = ['name' => 'x', 'new' => 'New']): ToolCall
    {
        return new ToolCall(new Shop(), null, 'rename_thing', $input, $confirmed);
    }

    public function test_unconfirmed_call_returns_preview_and_writes_nothing(): void
    {
        $tool = new FakeRenameTool();
        $out = $tool->run($this->call(confirmed: false));

        $this->assertTrue($out['preview']);
        $this->assertSame('Rename to New', $out['action']);
        $this->assertSame(['name' => 'Old → New'], $out['changes']);
        $this->assertSame('Old', $tool->store[1]); // NOT written
    }

    public function test_confirmed_call_performs_the_write(): void
    {
        $tool = new FakeRenameTool();
        $out = $tool->run($this->call(confirmed: true));

        $this->assertTrue($out['done']);
        $this->assertSame('New', $tool->store[1]); // written
    }

    public function test_resolve_not_found_short_circuits_before_write(): void
    {
        $tool = new FakeRenameTool();
        $out = $tool->run($this->call(confirmed: true, input: ['name' => 'ghost', 'new' => 'New']));

        $this->assertSame('not_found', $out['error']);
        $this->assertSame('Old', $tool->store[1]); // untouched
    }

    public function test_handles_reflects_permission_map(): void
    {
        $tool = new FakeRenameTool();
        $this->assertTrue($tool->handles('rename_thing'));
        $this->assertFalse($tool->handles('delete_universe'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=MutatingToolTest`
Expected: FAIL — class `App\Services\Assistant\Support\MutatingTool` not found.

- [ ] **Step 3: Create the MutatingTool base**

```php
<?php
// app/Services/Assistant/Support/MutatingTool.php
namespace App\Services\Assistant\Support;

/**
 * Base for every data-changing tool. Enforces the confirm-everything gate:
 * a tool writes NOTHING unless the model re-calls it with confirmed=true.
 * The first (unconfirmed) call resolves the real target and returns a preview
 * the assistant reads back. Being a MutatingTool also marks the module for the
 * assistant.mutations_enabled kill-switch in the registry.
 */
abstract class MutatingTool extends AssistantModule
{
    /**
     * @param callable():array $resolve  target record, or notFound()/ambiguous() to short-circuit
     * @param callable(array):array $describe  target => [string $action, array $changes]
     * @param callable(array):array $write  performs the change, returns extra result data
     */
    protected function gate(ToolCall $call, callable $resolve, callable $describe, callable $write): array
    {
        $target = $resolve();

        // resolve() may hand back a terminal response — pass it straight through.
        if (isset($target['error']) || isset($target['ambiguous'])) {
            return $target;
        }

        if (! $call->confirmed) {
            [$action, $changes] = $describe($target);
            return $this->preview($action, $changes);
        }

        return $this->applied($write($target));
    }

    /** @param array<string, mixed> $changes */
    protected function preview(string $action, array $changes = []): array
    {
        return ['preview' => true, 'action' => $action, 'changes' => $changes];
    }

    /** @param array<string, mixed> $data */
    protected function applied(array $data = []): array
    {
        return array_merge(['done' => true], $data);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=MutatingToolTest`
Expected: PASS (4 tests)

> Note: RBAC-**denied** coverage (a real `ShopUser` lacking a permission) lands in the first module plan (02-bookings), where user/role fixtures are established. The `run()` RBAC branch itself is created here.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/Support/AssistantModule.php app/Services/Assistant/Support/MutatingTool.php tests/Unit/MutatingToolTest.php
git commit -m "feat(assistant): add AssistantModule + MutatingTool base with enforced confirm-gate"
```

---

### Task 5: Adapt OwnerAssistantTools to the module interface

**Files:**
- Modify: `app/Services/Assistant/OwnerAssistantTools.php`
- Test: `tests/Feature/OwnerAssistantToolsTest.php` (existing — add one assertion)

**Interfaces:**
- Consumes: `AssistantToolModule` (Task 2, method `toolDefs()`).
- Produces: `OwnerAssistantTools implements AssistantToolModule` — instance `toolDefs(): array` (returns the existing static `defs()`), `handles(string $tool): bool` (true for any tool in `static::defs()`), `run(ToolCall $call): array` (delegates to existing `execute($call->shop, $call->tool, $call->input)`, decoded to an array). The existing **static** `defs()` and `execute()` are untouched, so all current behaviour holds. The interface method is `toolDefs()` precisely so it does not clash with this pre-existing static `defs()`. This is the temporary "legacy" module; later plans move its tools into gated domain modules and delete them here.

- [ ] **Step 1: Write the failing test** (append to the existing `OwnerAssistantToolsTest`)

```php
    public function test_module_run_delegates_to_execute(): void
    {
        $shop = $this->seedShopWithBooking();
        $call = new \App\Services\Assistant\Support\ToolCall($shop, null, 'get_revenue', ['period' => 'this_month'], false);

        $out = $this->tools()->run($call);

        $this->assertSame(50, (int) $out['kpis']['gross_revenue']);
        $this->assertTrue($this->tools()->handles('get_revenue'));
        $this->assertFalse($this->tools()->handles('nope'));
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=OwnerAssistantToolsTest`
Expected: FAIL — `OwnerAssistantTools::run()` / `handles()` do not exist.

- [ ] **Step 3: Implement the interface on OwnerAssistantTools**

Add `implements AssistantToolModule`, the two imports, and the three instance methods below. Leave the existing static `defs()` and `execute()` exactly as they are.

```php
// at top of app/Services/Assistant/OwnerAssistantTools.php
use App\Services\Assistant\Contracts\AssistantToolModule;
use App\Services\Assistant\Support\ToolCall;

// class signature:
class OwnerAssistantTools implements AssistantToolModule

// add these instance methods to the class body:

    /** @return array<int, array<string, mixed>> */
    public function toolDefs(): array
    {
        return static::defs();
    }

    public function handles(string $tool): bool
    {
        foreach (static::defs() as $def) {
            if (($def['name'] ?? null) === $tool) {
                return true;
            }
        }
        return false;
    }

    /** @return array<string, mixed> */
    public function run(ToolCall $call): array
    {
        return json_decode($this->execute($call->shop, $call->tool, $call->input), true) ?? [];
    }
```

- [ ] **Step 4: Run tests to verify they pass**

Run: `php artisan test --filter=OwnerAssistantToolsTest`
Then: `php artisan test --filter=MutatingToolTest`
Expected: PASS for both.

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/OwnerAssistantTools.php tests/Feature/OwnerAssistantToolsTest.php
git commit -m "refactor(assistant): OwnerAssistantTools implements AssistantToolModule (toolDefs)"
```

---

### Task 6: AssistantToolRegistry (aggregate + route + kill-switch)

**Files:**
- Create: `app/Services/Assistant/AssistantToolRegistry.php`
- Test: `tests/Feature/AssistantToolRegistryTest.php`

**Interfaces:**
- Consumes: `AssistantToolModule::toolDefs()/handles()/run()`, `MutatingTool` marker, `ToolCall`, `current_shop_user()`, `config('assistant.mutations_enabled')`.
- Produces:
  - `__construct(OwnerAssistantTools $legacy)` — modules list is `[$legacy]` for now; later plans add domain modules to the constructor + `modules()`.
  - `defs(): array` — merged `toolDefs()` of all **active** modules (kill-switch filters out `MutatingTool` instances when mutations are disabled).
  - `execute(Shop $shop, string $tool, array $input): string` — builds a `ToolCall` (acting user via `current_shop_user()`, `confirmed` from `input['confirmed']`), routes to the owning active module, JSON-encodes the result; unknown tool → `{"error":"unknown_tool"}`.

- [ ] **Step 1: Write the failing test**

```php
<?php
namespace Tests\Feature;

use App\Models\Shop;
use App\Services\Assistant\AssistantToolRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AssistantToolRegistryTest extends TestCase
{
    use RefreshDatabase;

    private function shop(): Shop
    {
        return Shop::create(['name' => 'R', 'shop_code' => '7001', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
    }

    public function test_defs_include_the_legacy_read_tools(): void
    {
        $names = array_column(app(AssistantToolRegistry::class)->defs(), 'name');
        $this->assertContains('get_revenue', $names);
    }

    public function test_execute_routes_to_the_owning_module_and_returns_json(): void
    {
        $shop = $this->shop();
        $json = app(AssistantToolRegistry::class)->execute($shop, 'get_revenue', ['period' => 'this_month']);
        $out = json_decode($json, true);
        $this->assertArrayHasKey('kpis', $out);
    }

    public function test_unknown_tool_returns_error_json(): void
    {
        $json = app(AssistantToolRegistry::class)->execute($this->shop(), 'no_such_tool', []);
        $this->assertSame('unknown_tool', json_decode($json, true)['error']);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `php artisan test --filter=AssistantToolRegistryTest`
Expected: FAIL — class `AssistantToolRegistry` not found.

- [ ] **Step 3: Create the registry**

```php
<?php
// app/Services/Assistant/AssistantToolRegistry.php
namespace App\Services\Assistant;

use App\Models\Shop;
use App\Services\Assistant\Contracts\AssistantToolModule;
use App\Services\Assistant\Support\MutatingTool;
use App\Services\Assistant\Support\ToolCall;

/**
 * Aggregates every assistant tool module's schemas and routes each tool call to
 * its owning module. Read modules are always active; MutatingTool modules are
 * hidden when config('assistant.mutations_enabled') is false (the kill-switch).
 */
class AssistantToolRegistry
{
    public function __construct(
        protected OwnerAssistantTools $legacy,
        // Later plans add: protected BookingTools $bookings, etc.
    ) {}

    /** @return array<int, AssistantToolModule> */
    protected function modules(): array
    {
        return [$this->legacy];
    }

    /** @return array<int, AssistantToolModule> */
    protected function activeModules(): array
    {
        $mutationsOn = (bool) config('assistant.mutations_enabled', true);

        return array_values(array_filter(
            $this->modules(),
            fn (AssistantToolModule $m) => $mutationsOn || ! $m instanceof MutatingTool,
        ));
    }

    /** @return array<int, array<string, mixed>> */
    public function defs(): array
    {
        $defs = [];
        foreach ($this->activeModules() as $module) {
            foreach ($module->toolDefs() as $def) {
                $defs[] = $def;
            }
        }
        return $defs;
    }

    public function execute(Shop $shop, string $tool, array $input): string
    {
        $call = new ToolCall(
            shop: $shop,
            actingUser: current_shop_user(),
            tool: $tool,
            input: $input,
            confirmed: (bool) ($input['confirmed'] ?? false),
        );

        foreach ($this->activeModules() as $module) {
            if ($module->handles($tool)) {
                return json_encode($module->run($call), JSON_UNESCAPED_UNICODE);
            }
        }

        return json_encode(['error' => 'unknown_tool'], JSON_UNESCAPED_UNICODE);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `php artisan test --filter=AssistantToolRegistryTest`
Expected: PASS (3 tests)

- [ ] **Step 5: Commit**

```bash
git add app/Services/Assistant/AssistantToolRegistry.php tests/Feature/AssistantToolRegistryTest.php
git commit -m "feat(assistant): add AssistantToolRegistry with kill-switch filtering"
```

---

### Task 7: Wire the controller to the registry

**Files:**
- Modify: `app/Http/Controllers/OwnerAssistantController.php:22-28` (constructor) and `:90-95` (toolLoop call)
- Test: `tests/Feature/OwnerAssistantConfirmGateTest.php`, `tests/Feature/OwnerAssistantMutationTest.php`, `tests/Feature/OwnerAssistantControllerTest.php` (all existing — must stay green)

**Interfaces:**
- Consumes: `AssistantToolRegistry::defs()` and `::execute()` (Task 6).
- Produces: no API change; the controller now sources tools from the registry.

- [ ] **Step 1: Run the existing suites first (baseline green)**

Run: `php artisan test --filter=OwnerAssistant`
Expected: PASS (baseline — before the change).

- [ ] **Step 2: Change the constructor dependency**

Replace `protected OwnerAssistantTools $tools,` with `protected AssistantToolRegistry $registry,` and update the import:

```php
// remove: use App\Services\Assistant\OwnerAssistantTools;
use App\Services\Assistant\AssistantToolRegistry;
```

```php
    public function __construct(
        protected AssistantToolRegistry $registry,
        protected ClaudeClient $claude,
        protected Speech $speech,
        protected Transcriber $transcriber,
        protected ConversationStore $store,
    ) {}
```

- [ ] **Step 3: Point the tool loop at the registry**

In `respond()`, replace the `toolLoop` tool arguments:

```php
            $replyText = $this->claude->toolLoop(
                AssistantPrompt::for($shop),
                $messages,
                $this->registry->defs(),
                fn (string $tool, array $input) => $this->registry->execute($shop, $tool, $input),
            );
```

- [ ] **Step 4: Run the existing suites to verify they still pass**

Run: `php artisan test --filter=OwnerAssistant`
Expected: PASS — identical behaviour, now via the registry.

- [ ] **Step 5: Run the full assistant-related suite**

Run: `php artisan test --filter=Assistant`
Expected: PASS (registry, config, tool-call, mutating-tool, controller, confirm-gate, mutation, tools tests all green).

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/OwnerAssistantController.php
git commit -m "refactor(assistant): route the controller's tool loop through AssistantToolRegistry"
```

---

## Self-Review

**Spec coverage (foundation slice):**
- Domain-module + registry architecture → Tasks 2, 3, 6. ✓ (domain modules themselves land in plans 02–09)
- Confirm-everything gate, enforced not prompted → Task 4 (`MutatingTool::gate`, `confirmed` invariant). ✓
- RBAC per tool via `Rbac::userCan` → Task 3 (`AssistantModule::run`). ✓
- Kill-switch `assistant.mutations_enabled` → Tasks 1, 6. ✓
- No behaviour change / existing tests green → Tasks 5, 7. ✓
- Deferred to later plans (correctly out of this slice): the 8 domain modules, fuzzy resolution per domain, prompt changes for collect-then-confirm, frontend copy tweaks, RBAC-denied fixtures.

**Placeholder scan:** No "TBD"/"handle edge cases"/"similar to Task N" — every step shows real code and commands. ✓

**Type consistency:** The interface method is `toolDefs()` everywhere (Contracts in Task 2, registry `defs()` iterates `$module->toolDefs()` in Task 6, `OwnerAssistantTools::toolDefs()` in Task 5, `FakeRenameTool::toolDefs()` in Task 4); the static `OwnerAssistantTools::defs()` is a distinct, untouched method — the interface is deliberately named `toolDefs()` to avoid the static/instance clash. `ToolCall` shape (`shop, actingUser, tool, input, confirmed` + `get()`) is used identically in Tasks 4, 6. `gate(resolve, describe, write)` signature matches its sole caller pattern in Task 4. ✓

## Next Plans (authored just-in-time, each shippable + tested)

02 Bookings · 03 Services · 04 Categories · 05 Staff · 06 Hours · 07 Customers · 08 Access (users & roles) · 09 Profile — plus a final wiring pass updating `AssistantPrompt` (collect-then-confirm, plain-language read-back, `confirmed:true` after yes) and the mic page copy tweaks. Each module plan: study that domain's real schema → write gated tool(s) as a `MutatingTool` subclass with TDD (RBAC-denied, confirm-gate, resolution one/ambiguous/none) → add the module to `AssistantToolRegistry`'s constructor + `modules()` → remove the corresponding legacy tool from `OwnerAssistantTools`.
