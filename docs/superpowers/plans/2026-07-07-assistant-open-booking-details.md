# "Open Booking Details" After Creation — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** After the owner creates a booking by voice, the assistant offers to open it and — on agreement — redirects the `/ask` screen to the booking detail page.

**Architecture:** Add a request-scoped `AssistantActions` collector. A new non-mutating `open_booking` tool records a navigate target on it; after the tool loop, `OwnerAssistantController` reads the collector and adds `action:{type:'navigate',route:'/booking/:id'}` to the JSON reply; the React `VoiceAssistant` navigates on receiving it.

**Tech Stack:** Laravel 11 (PHP 8.4), Eloquent; React + TypeScript + Vite (admin SPA), React Router, Vitest/RTL. Backend tests run on the droplet (php8.4, in-memory sqlite), never local.

## Global Constraints

- **Multi-tenant safety:** `open_booking` resolves only through `resolveBooking()` (already filters by `shop_id`); another shop's reference → `not_found`. Never navigate to a booking the acting shop doesn't own.
- **Model-driven navigation:** the model decides to navigate (by calling `open_booking`) only after the owner agrees; "yes" works in any language — no server-side keyword matching.
- **Non-mutating:** `open_booking` is NOT a MutatingTool — no confirm gate. Permission `bookings.view`.
- **No navigation on failure:** the controller adds `action` only on the successful reply path; an unresolved reference records nothing.
- **Scope:** wire the offer only after booking **create** (YAGNI — the tool is generic but reschedule/find offers are out of scope).
- **Booking detail route:** `/booking/:id` (`admin/src/App.tsx:69`, `BookingAction`).
- **Testing DB:** `RefreshDatabase` on in-memory sqlite (`phpunit.xml`). Run on droplet; `config:clear` first. Never prod.
- **Rollout:** local → staging (64.227.153.90) → prod (prod only on explicit approval). Frontend: `admin/deploy-staging.ps1`.

---

## File Structure

**Backend**
- Create: `app/Services/Assistant/Support/AssistantActions.php` — request-scoped navigation collector.
- Modify: `app/Providers/AppServiceProvider.php` — bind `AssistantActions` as a singleton.
- Modify: `app/Services/Assistant/Modules/BookingTools.php` — inject collector; add `open_booking` tool.
- Modify: `app/Http/Controllers/OwnerAssistantController.php` — inject collector; surface `action` in `respond()`.
- Modify: `app/Support/Assistant/AssistantPrompt.php` — add the "offer to open" rule.
- Test: `tests/Unit/AssistantActionsTest.php` (create), `tests/Feature/Assistant/BookingToolsModuleTest.php` (extend), `tests/Feature/OwnerAssistantOpenBookingTest.php` (create).

**Frontend**
- Modify: `admin/src/lib/assistant.ts` — `AssistantReply.action`.
- Modify: `admin/src/pages/VoiceAssistant.tsx` — navigate on `action`.
- Test: `admin/src/pages/VoiceAssistant.test.tsx` (extend).

---

## Task 1: `AssistantActions` collector

**Files:**
- Create: `app/Services/Assistant/Support/AssistantActions.php`
- Modify: `app/Providers/AppServiceProvider.php`
- Test: `tests/Unit/AssistantActionsTest.php`

**Interfaces:**
- Produces `App\Services\Assistant\Support\AssistantActions`:
  - `navigate(string $route): void`
  - `pending(): ?array` — returns `['type' => 'navigate', 'route' => $route]` or `null`.
- Bound as a container **singleton** so one instance is shared across a request.

- [ ] **Step 1: Write the failing unit test**

Create `tests/Unit/AssistantActionsTest.php`:

```php
<?php
namespace Tests\Unit;

use App\Services\Assistant\Support\AssistantActions;
use PHPUnit\Framework\TestCase;

class AssistantActionsTest extends TestCase
{
    public function test_fresh_collector_has_no_pending_action(): void
    {
        $this->assertNull((new AssistantActions())->pending());
    }

    public function test_navigate_records_a_directive(): void
    {
        $a = new AssistantActions();
        $a->navigate('/booking/7');
        $this->assertSame(['type' => 'navigate', 'route' => '/booking/7'], $a->pending());
    }

    public function test_last_navigate_wins(): void
    {
        $a = new AssistantActions();
        $a->navigate('/booking/1');
        $a->navigate('/booking/2');
        $this->assertSame('/booking/2', $a->pending()['route']);
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run (droplet): `php8.4 artisan test --filter=AssistantActionsTest`
Expected: FAIL — `Class "App\Services\Assistant\Support\AssistantActions" not found`.

- [ ] **Step 3: Create the collector**

Create `app/Services/Assistant/Support/AssistantActions.php`:

```php
<?php
namespace App\Services\Assistant\Support;

/**
 * Request-scoped sink for UI directives a tool wants to hand back to the chat
 * client (currently just navigation). A tool records intent here; the owner
 * assistant controller reads it after the tool loop and attaches it to the reply.
 * Bound as a singleton so the tool and the controller share one instance.
 */
class AssistantActions
{
    private ?array $action = null;

    public function navigate(string $route): void
    {
        $this->action = ['type' => 'navigate', 'route' => $route];
    }

    /** @return array{type: string, route: string}|null */
    public function pending(): ?array
    {
        return $this->action;
    }
}
```

- [ ] **Step 4: Bind it as a singleton**

In `app/Providers/AppServiceProvider.php`, add the import near the top (after the existing `use` lines):

```php
use App\Services\Assistant\Support\AssistantActions;
```

And inside `register()`, after the existing `LeadSourceInterface` bind block, add:

```php
        // One navigation-action sink per request, shared by the assistant tools
        // and the owner assistant controller.
        $this->app->singleton(AssistantActions::class);
```

- [ ] **Step 5: Run the test to verify it passes**

Run (droplet): `php8.4 artisan test --filter=AssistantActionsTest`
Expected: PASS (3 tests).

- [ ] **Step 6: Commit**

```bash
git add app/Services/Assistant/Support/AssistantActions.php app/Providers/AppServiceProvider.php tests/Unit/AssistantActionsTest.php
git commit -m "feat(assistant): request-scoped AssistantActions navigation collector"
```

---

## Task 2: `open_booking` tool

**Files:**
- Modify: `app/Services/Assistant/Modules/BookingTools.php`
- Test: `tests/Feature/Assistant/BookingToolsModuleTest.php`

**Interfaces:**
- Consumes: `AssistantActions` (Task 1).
- Produces: tool `open_booking` (input `reference`, permission `bookings.view`). On a
  resolvable, shop-owned booking it calls `$actions->navigate("/booking/{$booking->id}")`
  and returns `['opening' => true, 'reference' => $booking->booking_reference]`; unknown
  or cross-shop reference → `['error' => 'not_found', 'what' => 'booking']` and records
  no navigation.

- [ ] **Step 1: Write the failing test (extend the module test)**

In `tests/Feature/Assistant/BookingToolsModuleTest.php`, add these methods inside the class (after `test_booking_from_another_shop_is_not_found`). Note the added `use` — add `use App\Services\Assistant\Support\AssistantActions;` to the file's imports:

```php
    public function test_open_booking_records_navigation_and_returns_opening(): void
    {
        $shop = $this->shop();
        $b = $this->booking($shop, 'BK00042');
        $actions = app(AssistantActions::class);

        $out = app(BookingTools::class)->run($this->toolCall($shop, 'open_booking', ['reference' => 'BK00042'], false));

        $this->assertTrue($out['opening']);
        $this->assertSame('BK00042', $out['reference']);
        $this->assertSame(['type' => 'navigate', 'route' => "/booking/{$b->id}"], $actions->pending());
    }

    public function test_open_unknown_booking_is_not_found_and_records_nothing(): void
    {
        $shop = $this->shop();
        $actions = app(AssistantActions::class);

        $out = app(BookingTools::class)->run($this->toolCall($shop, 'open_booking', ['reference' => 'NOPE'], false));

        $this->assertSame('not_found', $out['error']);
        $this->assertNull($actions->pending());
    }

    public function test_open_booking_from_another_shop_is_not_found(): void
    {
        $shop = $this->shop();
        $other = Shop::create(['name' => 'O', 'shop_code' => '8298', 'pin' => '0', 'status' => 'active', 'category_id' => 11]);
        $this->booking($other, 'BK08888');

        $out = app(BookingTools::class)->run($this->toolCall($shop, 'open_booking', ['reference' => 'BK08888'], false));
        $this->assertSame('not_found', $out['error']);
    }
```

- [ ] **Step 2: Run the test to verify it fails**

Run (droplet): `php8.4 artisan test --filter=BookingToolsModuleTest`
Expected: FAIL — `open_booking` is not handled (module returns unknown/`not_found` incorrectly, `opening` key missing).

- [ ] **Step 3: Inject the collector into `BookingTools`**

In `app/Services/Assistant/Modules/BookingTools.php`, add the import:

```php
use App\Services\Assistant\Support\AssistantActions;
```

Replace the constructor:

```php
    public function __construct(
        protected BookingCreator $creator,
        protected BookingStatusService $status,
    ) {}
```

with:

```php
    public function __construct(
        protected BookingCreator $creator,
        protected BookingStatusService $status,
        protected AssistantActions $actions,
    ) {}
```

- [ ] **Step 4: Register the tool in the permission map and handler**

In `permissions()`, add the entry (after `'find_booking' => 'bookings.view',`):

```php
            'open_booking'          => 'bookings.view',
```

In `handle()`, add the match arm (after the `find_booking` arm):

```php
            'open_booking'          => $this->open($call),
```

- [ ] **Step 5: Implement `open()`**

Add this private method (place it right after the `find()` method):

```php
    /** Resolve a booking and hand the chat UI a directive to open its detail page. */
    private function open(ToolCall $call): array
    {
        $booking = $this->resolveBooking($call);
        if (! $booking) {
            return $this->notFound('booking');
        }
        $this->actions->navigate("/booking/{$booking->id}");
        return ['opening' => true, 'reference' => $booking->booking_reference];
    }
```

- [ ] **Step 6: Add the tool schema**

In `toolDefs()`, add this entry to the returned array (right after the `find_booking` def):

```php
            ['name' => 'open_booking', 'description' => 'Open a booking\'s detail page for the owner. Use ONLY after the owner agrees to view it. Pass its reference.', 'input_schema' => ['type' => 'object', 'properties' => $ref, 'required' => ['reference']]],
```

- [ ] **Step 7: Run the test to verify it passes**

Run (droplet): `php8.4 artisan test --filter=BookingToolsModuleTest`
Expected: PASS (all methods, including the 3 new ones).

- [ ] **Step 8: Commit**

```bash
git add app/Services/Assistant/Modules/BookingTools.php tests/Feature/Assistant/BookingToolsModuleTest.php
git commit -m "feat(assistant): open_booking tool records a navigate directive"
```

---

## Task 3: Controller surfaces `action` + prompt rule

**Files:**
- Modify: `app/Http/Controllers/OwnerAssistantController.php`
- Modify: `app/Support/Assistant/AssistantPrompt.php`
- Test: `tests/Feature/OwnerAssistantOpenBookingTest.php`

**Interfaces:**
- Consumes: `AssistantActions` (Task 1), `open_booking` tool (Task 2).
- Produces: on a successful reply where `open_booking` ran, the `text`/`voice` JSON
  response includes `action` = `['type' => 'navigate', 'route' => '/booking/{id}']`.

- [ ] **Step 1: Write the failing API test**

Create `tests/Feature/OwnerAssistantOpenBookingTest.php`:

```php
<?php
namespace Tests\Feature;

use App\Models\Booking;
use App\Models\Shop;
use App\Models\Staff;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class OwnerAssistantOpenBookingTest extends TestCase
{
    use RefreshDatabase;

    public function test_open_booking_turn_returns_a_navigate_action(): void
    {
        Storage::fake('local');
        $shop = Shop::create(['name' => 'A', 'shop_code' => '7001', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);

        $staff = Staff::create(['shop_id' => $shop->id, 'name' => 'Ali', 'is_active' => true]);
        $b = Booking::create([
            'shop_id' => $shop->id, 'staff_id' => $staff->id, 'date' => now()->toDateString(),
            'start_time' => '10:00', 'end_time' => '10:30', 'status' => 'booked', 'charges' => 40,
            'discount_amount' => 0, 'services' => [], 'customer_name' => 'Hina',
        ]);
        $b->update(['booking_reference' => 'BK00042']);

        // Turn: owner says "yes"; model calls open_booking, then summarizes.
        Http::fake([
            'api.anthropic.com/*' => Http::sequence()
                ->push(['content' => [['type' => 'tool_use', 'id' => 'tu1', 'name' => 'open_booking', 'input' => ['reference' => 'BK00042']]]])
                ->push(['content' => [['type' => 'text', 'text' => 'Opening the booking now.']]]),
        ]);

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'yes open it'])
            ->assertCreated()
            ->assertJsonPath('reply_text', 'Opening the booking now.')
            ->assertJsonPath('action.type', 'navigate')
            ->assertJsonPath('action.route', "/booking/{$b->id}");
    }

    public function test_normal_turn_has_no_action_key(): void
    {
        Storage::fake('local');
        $shop = Shop::create(['name' => 'B', 'shop_code' => '7002', 'pin' => '1', 'status' => 'active', 'category_id' => 11]);
        $this->startTrial($shop);
        Sanctum::actingAs($shop, ['*']);

        Http::fake(['api.anthropic.com/*' => Http::response(['content' => [['type' => 'text', 'text' => 'You made fifty dirhams.']]])]);

        $res = $this->postJson('/api/shop/assistant/text', ['text' => 'how much today'])->assertCreated();
        $this->assertArrayNotHasKey('action', $res->json());
    }
}
```

- [ ] **Step 2: Run the test to verify it fails**

Run (droplet): `php8.4 artisan test --filter=OwnerAssistantOpenBookingTest`
Expected: FAIL — response has no `action` key.

- [ ] **Step 3: Inject the collector into the controller**

In `app/Http/Controllers/OwnerAssistantController.php`, add the import:

```php
use App\Services\Assistant\Support\AssistantActions;
```

Add the constructor dependency (append to the existing promoted-property list):

```php
    public function __construct(
        protected AssistantToolRegistry $registry,
        protected ClaudeClient $claude,
        protected Speech $speech,
        protected Transcriber $transcriber,
        protected ConversationStore $store,
        protected AssistantActions $actions,
    ) {}
```

- [ ] **Step 4: Attach the action to the successful reply**

In `respond()`, find the success payload block:

```php
        $payload = [
            'conversation_id' => $conversation->id,
            'title' => $conversation->title,
            'reply_text' => $replyText,
            'reply_audio_url' => $this->store->signedUrl($assistantMsg),
        ];
        if ($transcript !== null) {
            $payload['transcript'] = $transcript;
        }
        return response()->json($payload, 201);
```

Insert the action lookup immediately before `if ($transcript !== null)`:

```php
        if ($action = $this->actions->pending()) {
            $payload['action'] = $action;
        }
```

(So the block becomes: build `$payload`, then the `if ($action …)` block, then the `if ($transcript …)` block, then `return`.)

- [ ] **Step 5: Add the prompt rule**

In `app/Support/Assistant/AssistantPrompt.php`, inside the `MAKING CHANGES` list, add this bullet right after the CRITICAL confirmation rule (the line beginning "CRITICAL: NEVER tell the owner…"):

```php
        - After you CREATE a booking (a create_booking result with done=true), end your reply by offering to open it, e.g. "Do you want to see the booking details?". If the owner agrees, call open_booking with that booking's reference to take them to it. Never open it without being asked to.
```

- [ ] **Step 6: Run the test to verify it passes**

Run (droplet): `php8.4 artisan test --filter=OwnerAssistantOpenBookingTest`
Expected: PASS (2 tests).

- [ ] **Step 7: Run the full assistant suite (no regressions)**

Run (droplet): `php8.4 artisan test --filter=Assistant`
Expected: PASS.

- [ ] **Step 8: Commit**

```bash
git add app/Http/Controllers/OwnerAssistantController.php app/Support/Assistant/AssistantPrompt.php tests/Feature/OwnerAssistantOpenBookingTest.php
git commit -m "feat(assistant): surface navigate action + prompt offer-to-open after booking create"
```

---

## Task 4: Frontend — navigate on action

**Files:**
- Modify: `admin/src/lib/assistant.ts`
- Modify: `admin/src/pages/VoiceAssistant.tsx`
- Test: `admin/src/pages/VoiceAssistant.test.tsx`

**Interfaces:**
- Consumes: the `action` field in the reply JSON (Task 3).
- `AssistantReply` gains `action?: { type: 'navigate'; route: string }`.
- `VoiceAssistant` navigates to `action.route` after rendering the reply.

- [ ] **Step 1: Add the type**

In `admin/src/lib/assistant.ts`, extend `AssistantReply`:

```ts
export type AssistantReply = {
  conversation_id?: number;
  title?: string;
  transcript?: string;
  reply_text: string;
  reply_audio_url: string | null;
  action?: { type: 'navigate'; route: string };
};
```

- [ ] **Step 2: Write the failing test**

In `admin/src/pages/VoiceAssistant.test.tsx`, add these two tests inside the `describe` block (after the "adopts the returned conversation id" test):

```tsx
  it('navigates to the booking when the reply carries a navigate action', async () => {
    asMock(postText).mockResolvedValue({ conversation_id: 9, title: 't', reply_text: 'Opening it.', reply_audio_url: null, action: { type: 'navigate', route: '/booking/7' } });
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'yes' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('Opening it.')).toBeInTheDocument());
    expect(navigate).toHaveBeenCalledWith('/booking/7');
  });

  it('does not navigate when the reply has no action', async () => {
    render(<VoiceAssistant />);
    await screen.findByPlaceholderText(/type/i);
    fireEvent.change(screen.getByPlaceholderText(/type/i), { target: { value: 'how much' } });
    fireEvent.click(screen.getByRole('button', { name: /send/i }));
    await waitFor(() => expect(screen.getByText('You made 50 dirhams.')).toBeInTheDocument());
    expect(navigate).not.toHaveBeenCalledWith(expect.stringContaining('/booking/'));
  });
```

- [ ] **Step 3: Run the test to verify it fails**

Run (in `admin/`): `npx vitest run src/pages/VoiceAssistant.test.tsx`
Expected: FAIL — `navigate` is not called with `/booking/7`.

- [ ] **Step 4: Navigate on action in `send()`**

In `admin/src/pages/VoiceAssistant.tsx`, in `send()`, after the reply is appended and `adopt(...)` is called, add the navigation. The block becomes:

```tsx
    try {
      const res = await postText(text, conversationId ?? undefined);
      setMessages((m) => [...m, { role: 'assistant', content: res.reply_text, audioUrl: res.reply_audio_url }]);
      adopt(res.conversation_id);
      if (res.action?.type === 'navigate') navigate(res.action.route);
    } catch { setError('Could not reach the assistant.'); }
    finally { setBusy(false); }
```

- [ ] **Step 5: Navigate on action in `toggleMic()`**

In the same file, in `toggleMic()`, after its `adopt(res.conversation_id);` line, add the same guard:

```tsx
        adopt(res.conversation_id);
        if (res.action?.type === 'navigate') navigate(res.action.route);
```

- [ ] **Step 6: Run the tests to verify they pass**

Run (in `admin/`): `npx vitest run src/pages/VoiceAssistant.test.tsx`
Expected: PASS (all, including the 2 new tests).

- [ ] **Step 7: Typecheck**

Run (in `admin/`): `npx tsc --noEmit`
Expected: no errors.

- [ ] **Step 8: Commit**

```bash
git add admin/src/lib/assistant.ts admin/src/pages/VoiceAssistant.tsx admin/src/pages/VoiceAssistant.test.tsx
git commit -m "feat(assistant): redirect to booking detail on a navigate action"
```

---

## Task 5: Staging deploy + verification

**Files:** none (deploy + manual verification).

- [ ] **Step 1: Full backend suite on the droplet**

Merge to `main`, push, `git pull` in `/var/www/eloquent-backend-staging` only, then:
Run (droplet): `php8.4 artisan config:clear && php8.4 artisan route:clear && php8.4 artisan test`
Expected: all green (no migration in this feature).

- [ ] **Step 2: Rebuild caches**

Run (droplet, in the staging dir): `php8.4 artisan config:cache && php8.4 artisan route:cache && php8.4 artisan view:cache && chown -R www-data:www-data .`

- [ ] **Step 3: Deploy frontend to staging**

Run (in `admin/`): `./deploy-staging.ps1`
Expected: build succeeds, `HTTP/1.1 200 OK`.

- [ ] **Step 4: Manual smoke test on staging**

At `staging-admin.eloquentservice.com` (code 390676 / PIN 5648):
1. Create a booking by voice; confirm it (should really create — the confirm-gate fix).
2. The assistant should ask if you want to see the details.
3. Say/type "yes" → the app redirects to `/booking/:id` for that booking.
4. Back button returns to the chat.
5. Decline case: say "no" → stays in chat, no redirect.

- [ ] **Step 5: Prod promotion — deferred**

Do NOT promote to prod. Wait for explicit approval (per the standing rule).

---

## Self-Review Notes

- **Spec coverage:** `AssistantActions` collector + singleton (Task 1); `open_booking` non-mutating tool, shop-scoped, `bookings.view` (Task 2); controller surfaces `action` on success only + prompt offer-to-open (Task 3); frontend type + navigate on both text and voice (Task 4); staging rollout, prod deferred (Task 5). All spec sections covered.
- **Placeholder scan:** none — every code step shows complete code.
- **Type consistency:** `navigate(string)`, `pending(): ?array` returning `['type'=>'navigate','route'=>…]` used identically across the collector (Task 1), tool (Task 2), controller (Task 3), and the frontend `action:{type:'navigate',route:string}` (Task 4). Booking route `"/booking/{$booking->id}"` matches the `/booking/:id` SPA route and the test assertions.
- **No-action path:** controller adds `action` only when `pending()` is non-null, so existing replies (and the `test_normal_turn_has_no_action_key` assertion) are unchanged.
