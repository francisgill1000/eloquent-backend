# AI-Written Outreach Messages Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Let each shop generate a tailored WhatsApp opening + follow-up template from its profile via AI, personalize a message for a specific lead via AI, and support `{category}`/`{area}` placeholders — so outreach is specific, not generic.

**Architecture:** A new `OutreachWriter` service builds a system prompt (copy rules + shop profile) and calls the existing `App\Services\Wa\ClaudeClient` (raw-HTTP Anthropic Messages client). Two new endpoints expose it: `POST /shop/lead-messages/generate` (shop-level templates, placeholders kept) and `POST /shop/leads/{lead}/personalize` (bespoke message for one lead, real values). The `Lead::draftUrl()` renderer gains `{category}`/`{area}`. Frontend adds a "✨ Generate with AI" button on the Lead Messages page and a "✨ Personalize" button + preview on the lead detail page.

**Tech Stack:** Laravel (PHP 8.4 dev / php8.3 runtime), React + TypeScript (Vite), Vitest, the app's existing `ClaudeClient` (no new SDK, no new API key).

## Global Constraints

- **Deploy flow:** implement on local → deploy to STAGING and run all tests there → promote to production only after it's confirmed good. NEVER run tests or destructive DB commands against production. (Staging: `staging-api.eloquentservice.com`, dir `/var/www/eloquent-backend-staging`, DB `laravel12_staging_db`, `ALLOW_DESTRUCTIVE_DB=true`. Prod: `api.eloquentservice.com`, dir `/var/www/eloquent-backend`, php8.3-fpm — never tested.)
- **No real Claude calls in tests.** Bind a fake `ClaudeClient` into the container (`$this->app->instance(ClaudeClient::class, $fake)`), exactly like `LeadFinderTest` fakes `LeadSourceInterface`.
- Tenant scoping on every endpoint: resolve the Shop from `$request->user()`; cross-shop lead access returns 404 (`abort_unless($lead->shop_id === $shop->id, 404)`).
- Placeholders: `{name}` = lead's business name, `{shop}` = sender shop's name, `{category}` = lead's industry, `{area}` = lead's location. Rendered server-side via `strtr` and `rawurlencode`d onto `wa.me/{digits}?text=`. Unknown placeholders left verbatim.
- Per-shop generate returns a **template** with placeholders intact; per-lead personalize returns a **final message** with real values (no placeholders).
- Reuse `ClaudeClient` as-is; the model comes from `config('services.anthropic.model')` (currently `claude-haiku-4-5`). Do not change the client or the model.
- Claude failure → HTTP 502 `{ message: "Could not generate right now. Please try again." }`; UI degrades gracefully.
- No DB migration in this feature (the template columns already exist). Promotion to prod is code + frontend only.

---

### Task 1: Lead `{category}` / `{area}` placeholders

Adds the two lead-context placeholders and their accessors so any template (AI-generated or hand-written) can reference the lead's industry and area.

**Files:**
- Modify: `app/Models/Lead.php` (add `categoryLabel()` + `area()`, extend `draftUrl()` `strtr` map)
- Test: `tests/Feature/LeadWhatsAppDraftTest.php` (add one test)

**Interfaces:**
- Produces: `Lead::categoryLabel(): ?string`, `Lead::area(): ?string`; `draftUrl()` now substitutes `{category}` and `{area}`.

- [ ] **Step 1: Add the failing test**

Append to `tests/Feature/LeadWhatsAppDraftTest.php` before the final closing `}`:

```php
    public function test_category_and_area_placeholders_render(): void
    {
        [$shop, $token] = $this->actingShop();
        $shop->update(['lead_opening_template' => 'Hi {name}, a {category} in {area} — from {shop}']);
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'phone' => '0501112233',
            'category' => 'beauty_salon', 'address' => 'Dubai Marina',
            'status' => 'new', 'source' => 'google',
        ]);

        $opening = $this->auth($token)
            ->getJson("/api/shop/leads/{$lead->id}")->assertOk()
            ->json('data.whatsapp_opening_url');

        // beauty_salon -> "Beauty Salon"; "Dubai Marina" -> "Dubai%20Marina"
        $this->assertStringContainsString('Beauty%20Salon', $opening);
        $this->assertStringContainsString('Dubai%20Marina', $opening);
        $this->assertStringNotContainsString('%7Bcategory%7D', $opening); // no leftover {category}
        $this->assertStringNotContainsString('%7Barea%7D', $opening);
    }
```

- [ ] **Step 2: Add accessors + extend the renderer in `Lead.php`**

Add these two methods to `app/Models/Lead.php` (e.g. right after `getMapUrlAttribute()`):

```php
    /** Human label for the lead's industry (slug -> Title Case), else null. */
    public function categoryLabel(): ?string
    {
        $c = trim((string) ($this->category ?? ''));
        if ($c === '') {
            return null;
        }
        return ucwords(str_replace(['_', '-'], ' ', $c));
    }

    /** Best available area/location string for the lead (its address), else null. */
    public function area(): ?string
    {
        $a = trim((string) ($this->address ?? ''));
        return $a !== '' ? $a : null;
    }
```

Then change the `strtr` map inside `draftUrl()` from:

```php
        $text = strtr($template, [
            '{name}' => (string) $this->name,
            '{shop}' => (string) ($this->shop?->name ?? ''),
        ]);
```

to:

```php
        $text = strtr($template, [
            '{name}' => (string) $this->name,
            '{shop}' => (string) ($this->shop?->name ?? ''),
            '{category}' => (string) ($this->categoryLabel() ?? ''),
            '{area}' => (string) ($this->area() ?? ''),
        ]);
```

- [ ] **Step 3: Run on staging (after deploying — see Task 6 for the deploy commands; during dev, run once staging has the code)**

Run: `ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && php8.3 artisan test tests/Feature/LeadWhatsAppDraftTest.php'`
Expected: all tests pass, including `category and area placeholders render`.

- [ ] **Step 4: Commit**

```bash
git add app/Models/Lead.php tests/Feature/LeadWhatsAppDraftTest.php
git commit -m "feat(leads): {category} and {area} placeholders in outreach templates"
```

---

### Task 2: `OutreachWriter` service

The AI copywriter. Builds the system prompt (copy rules + a compact shop profile) and calls `ClaudeClient`. Two methods: shop-level templates (placeholders kept) and per-lead personalization (real values).

**Files:**
- Create: `app/Services/Leads/OutreachWriter.php`
- Test: `tests/Feature/OutreachWriterTest.php`

**Interfaces:**
- Consumes: `App\Services\Wa\ClaudeClient::reply(string $system, array $history): string`; `Shop::categoryLabel()`, `Shop::catalogs()`, `Shop->name`, `Shop->location`; `Lead::name`, `Lead::categoryLabel()`, `Lead::area()`.
- Produces:
  - `OutreachWriter::templatesForShop(Shop $shop): array` → `['opening' => string, 'followup' => string]` (templates, placeholders intact)
  - `OutreachWriter::personalizeForLead(Shop $shop, Lead $lead, string $kind): string` (`$kind` ∈ `opening`|`followup`; a final ready-to-send message)
  - Throws `\RuntimeException` on Claude error or unparseable output.

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OutreachWriterTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Leads\OutreachWriter;
use App\Services\Wa\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachWriterTest extends TestCase
{
    use RefreshDatabase;

    /** Bind a fake ClaudeClient that echoes a canned reply and records the prompt. */
    private function fakeClaude(string $reply): object
    {
        $fake = new class($reply) extends ClaudeClient {
            public string $lastSystem = '';
            public function __construct(public string $reply) {}
            public function reply(string $system, array $history): string
            {
                $this->lastSystem = $system;
                return $this->reply;
            }
        };
        $this->app->instance(ClaudeClient::class, $fake);
        return $fake;
    }

    public function test_templates_for_shop_parses_json_and_keeps_placeholders(): void
    {
        $this->fakeClaude('{"opening":"Hi {name}, from {shop}","followup":"Following up, {name}"}');
        $shop = Shop::factory()->create(['name' => 'Marina Spa']);

        $out = app(OutreachWriter::class)->templatesForShop($shop);

        $this->assertSame('Hi {name}, from {shop}', $out['opening']);
        $this->assertSame('Following up, {name}', $out['followup']);
    }

    public function test_templates_for_shop_tolerates_json_wrapped_in_prose(): void
    {
        // Model sometimes adds a sentence around the JSON — we extract the object.
        $this->fakeClaude('Sure! {"opening":"Hi {name}","followup":"Ping {name}"} Hope that helps.');
        $shop = Shop::factory()->create(['name' => 'Acme']);

        $out = app(OutreachWriter::class)->templatesForShop($shop);

        $this->assertSame('Hi {name}', $out['opening']);
        $this->assertSame('Ping {name}', $out['followup']);
    }

    public function test_templates_for_shop_throws_on_unparseable_reply(): void
    {
        $this->fakeClaude('no json here');
        $shop = Shop::factory()->create();

        $this->expectException(\RuntimeException::class);
        app(OutreachWriter::class)->templatesForShop($shop);
    }

    public function test_personalize_for_lead_returns_message_and_includes_lead_in_prompt(): void
    {
        $fake = $this->fakeClaude('Hi Pak Cargo, saw you ship across Dubai — quick demo?');
        $shop = Shop::factory()->create(['name' => 'Eloquent']);
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Pak Cargo', 'phone' => '0501112233',
            'category' => 'cargo', 'address' => 'Deira', 'status' => 'new', 'source' => 'google',
        ]);

        $msg = app(OutreachWriter::class)->personalizeForLead($shop, $lead, 'opening');

        $this->assertStringContainsString('Pak Cargo', $msg);
        // The lead's real details were put into the prompt the model saw.
        $this->assertStringContainsString('Pak Cargo', $fake->lastSystem);
        $this->assertStringContainsString('Cargo', $fake->lastSystem);
    }
}
```

- [ ] **Step 2: Implement the service**

Create `app/Services/Leads/OutreachWriter.php`:

```php
<?php

namespace App\Services\Leads;

use App\Models\Lead;
use App\Models\Shop;
use App\Services\Wa\ClaudeClient;

/**
 * Writes WhatsApp cold-outreach copy for a shop's leads using Claude.
 *
 * Two modes:
 *  - templatesForShop(): a reusable opening + follow-up TEMPLATE (keeps the
 *    literal {name}/{category}/{area} placeholders, signs as {shop}).
 *  - personalizeForLead(): one ready-to-send message for a specific lead
 *    (real values, no placeholders).
 *
 * The model, key, retries and error handling all live in ClaudeClient.
 */
class OutreachWriter
{
    public function __construct(private ClaudeClient $claude)
    {
    }

    /** @return array{opening: string, followup: string} */
    public function templatesForShop(Shop $shop): array
    {
        $system = $this->rules()
            . "\n\n" . $this->shopProfile($shop)
            . "\n\nWrite a reusable OPENING and FOLLOW-UP template this shop can send to any prospect it finds."
            . " Keep these literal placeholders in the text so they fill per-lead: {name} (the prospect's business name),"
            . " {category} (the prospect's industry), {area} (the prospect's location). Sign as {shop} where natural."
            . "\n\nReturn ONLY a JSON object, no prose, exactly: {\"opening\": \"...\", \"followup\": \"...\"}";

        $raw = $this->claude->reply($system, [
            ['role' => 'user', 'content' => 'Write the opening and follow-up templates.'],
        ]);

        $json = $this->extractJson($raw);
        $opening = trim((string) ($json['opening'] ?? ''));
        $followup = trim((string) ($json['followup'] ?? ''));
        if ($opening === '' || $followup === '') {
            throw new \RuntimeException('OutreachWriter: model returned no usable templates.');
        }

        return ['opening' => $opening, 'followup' => $followup];
    }

    public function personalizeForLead(Shop $shop, Lead $lead, string $kind): string
    {
        $kind = $kind === 'followup' ? 'follow-up' : 'opening';

        $leadLines = array_filter([
            'Business name: ' . ($lead->name ?: '(unknown)'),
            $lead->categoryLabel() ? 'Industry: ' . $lead->categoryLabel() : null,
            $lead->area() ? 'Area: ' . $lead->area() : null,
        ]);

        $system = $this->rules()
            . "\n\n" . $this->shopProfile($shop)
            . "\n\nThe specific prospect you are messaging:\n" . implode("\n", $leadLines)
            . "\n\nWrite ONE ready-to-send WhatsApp {$kind} message to THIS prospect."
            . " Use their real name and details — do NOT use placeholders like {name}."
            . " Return ONLY the message text, nothing else.";

        $msg = trim($this->claude->reply($system, [
            ['role' => 'user', 'content' => "Write the {$kind} message."],
        ]));

        if ($msg === '') {
            throw new \RuntimeException('OutreachWriter: model returned an empty message.');
        }

        return $msg;
    }

    /** Shared copy rules that make the output impactful rather than generic. */
    private function rules(): string
    {
        return implode("\n", [
            'You write WhatsApp cold-outreach for a business reaching out to prospective business customers.',
            'Rules:',
            '- Short: opening 2-4 short lines; follow-up 1-2 lines. Long WhatsApp messages get ignored.',
            '- Open with a specific hook about the recipient (their business or industry), not a feature dump.',
            '- State ONE value prop relevant to the sender\'s offering and the recipient\'s industry.',
            '- End with a soft, low-friction question as the CTA (e.g. "Worth a quick 2-min demo?"). Never "buy now".',
            '- The follow-up must take a NEW angle (a proof point or a question), never repeat the opening.',
            '- No invented statistics, names, or offers. Plain text; at most one light emoji.',
        ]);
    }

    /** Compact profile of the sending shop, built from its own data. */
    private function shopProfile(Shop $shop): string
    {
        $lines = ['The sender (you are writing on their behalf):'];
        $lines[] = 'Business name: ' . ($shop->name ?: '(unnamed)');
        if ($label = $shop->categoryLabel()) {
            $lines[] = 'Industry: ' . $label;
        }
        if ($shop->location) {
            $lines[] = 'Location: ' . $shop->location;
        }

        $services = $shop->catalogs()->get(['title', 'price'])
            ->filter(fn ($s) => trim((string) $s->title) !== '')
            ->map(fn ($s) => '- ' . trim($s->title)
                . ($s->price !== null ? ' (AED ' . number_format((float) $s->price, 0) . ')' : ''))
            ->implode("\n");
        if ($services !== '') {
            $lines[] = "What they offer:\n" . $services;
        }

        return implode("\n", $lines);
    }

    /** Pull the first JSON object out of a model reply that may include prose. */
    private function extractJson(string $raw): array
    {
        $start = strpos($raw, '{');
        $end = strrpos($raw, '}');
        if ($start === false || $end === false || $end < $start) {
            throw new \RuntimeException('OutreachWriter: no JSON object in model reply.');
        }
        $decoded = json_decode(substr($raw, $start, $end - $start + 1), true);
        if (! is_array($decoded)) {
            throw new \RuntimeException('OutreachWriter: could not parse model JSON.');
        }
        return $decoded;
    }
}
```

- [ ] **Step 3: Run on staging**

Run: `ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && php8.3 artisan test tests/Feature/OutreachWriterTest.php'`
Expected: 4 passing tests.

- [ ] **Step 4: Commit**

```bash
git add app/Services/Leads/OutreachWriter.php tests/Feature/OutreachWriterTest.php
git commit -m "feat(leads): OutreachWriter service (AI opening/follow-up copy via ClaudeClient)"
```

---

### Task 3: `generate` and `personalize` endpoints

Expose `OutreachWriter` over HTTP, tenant-scoped, with graceful 502 on AI failure.

**Files:**
- Modify: `app/Http/Controllers/LeadMessageController.php` (add `generate()`)
- Modify: `app/Http/Controllers/LeadController.php` (add `personalize()`)
- Modify: `routes/api.php` (two routes in the existing lead group)
- Test: `tests/Feature/OutreachEndpointsTest.php`

**Interfaces:**
- Consumes: `OutreachWriter` (Task 2, via method injection).
- Produces:
  - `POST /shop/lead-messages/generate` → `{ opening, followup }` (not saved)
  - `POST /shop/leads/{lead}/personalize` body `{ kind: 'opening'|'followup' }` → `{ message, kind }`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/OutreachEndpointsTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Lead;
use App\Models\Shop;
use App\Models\ShopUser;
use App\Services\Wa\ClaudeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OutreachEndpointsTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{0: Shop, 1: string} [shop, plainTextToken] */
    private function actingShop(): array
    {
        $shop = Shop::factory()->create(['is_master' => true, 'name' => 'Marina Spa']);
        $user = ShopUser::factory()->create(['shop_id' => $shop->id]);
        $token = $shop->createToken('t');
        $token->accessToken->forceFill(['shop_user_id' => $user->id])->save();
        return [$shop, $token->plainTextToken];
    }

    private function auth(string $token): self
    {
        return $this->withHeaders(['Authorization' => 'Bearer ' . $token]);
    }

    private function fakeClaude(string $reply): void
    {
        $fake = new class($reply) extends ClaudeClient {
            public function __construct(public string $reply) {}
            public function reply(string $system, array $history): string { return $this->reply; }
        };
        $this->app->instance(ClaudeClient::class, $fake);
    }

    private function failingClaude(): void
    {
        $fake = new class extends ClaudeClient {
            public function reply(string $system, array $history): string
            {
                throw new \RuntimeException('claude down');
            }
        };
        $this->app->instance(ClaudeClient::class, $fake);
    }

    public function test_generate_returns_templates(): void
    {
        [, $token] = $this->actingShop();
        $this->fakeClaude('{"opening":"Hi {name}","followup":"Ping {name}"}');

        $this->auth($token)->postJson('/api/shop/lead-messages/generate')
            ->assertOk()
            ->assertJson(['opening' => 'Hi {name}', 'followup' => 'Ping {name}']);
    }

    public function test_generate_returns_502_on_ai_failure(): void
    {
        [, $token] = $this->actingShop();
        $this->failingClaude();

        $this->auth($token)->postJson('/api/shop/lead-messages/generate')
            ->assertStatus(502)
            ->assertJsonPath('message', 'Could not generate right now. Please try again.');
    }

    public function test_personalize_returns_message_for_lead(): void
    {
        [$shop, $token] = $this->actingShop();
        $this->fakeClaude('Hi Pak Cargo, quick demo?');
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Pak Cargo', 'phone' => '0501112233',
            'status' => 'new', 'source' => 'google',
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/personalize", ['kind' => 'opening'])
            ->assertOk()
            ->assertJson(['message' => 'Hi Pak Cargo, quick demo?', 'kind' => 'opening']);
    }

    public function test_personalize_is_tenant_scoped(): void
    {
        [, $token] = $this->actingShop();
        $this->fakeClaude('x');
        $other = Shop::factory()->create(['is_master' => true]);
        $lead = Lead::create([
            'shop_id' => $other->id, 'name' => 'Not Mine', 'phone' => '0501112233',
            'status' => 'new', 'source' => 'google',
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/personalize", ['kind' => 'opening'])
            ->assertNotFound();
    }

    public function test_personalize_validates_kind(): void
    {
        [$shop, $token] = $this->actingShop();
        $this->fakeClaude('x');
        $lead = Lead::create([
            'shop_id' => $shop->id, 'name' => 'Acme', 'phone' => '0501112233',
            'status' => 'new', 'source' => 'google',
        ]);

        $this->auth($token)->postJson("/api/shop/leads/{$lead->id}/personalize", ['kind' => 'bogus'])
            ->assertStatus(422);
    }
}
```

- [ ] **Step 2: Add `generate()` to `LeadMessageController`**

In `app/Http/Controllers/LeadMessageController.php`, add `use` imports at the top (below the existing `use` lines):

```php
use App\Services\Leads\OutreachWriter;
```

Add this method (after `update()`):

```php
    /**
     * POST /shop/lead-messages/generate
     * AI-writes an opening + follow-up TEMPLATE from the shop profile. Not saved —
     * the client fills the editor; the owner reviews, edits, then PUTs to save.
     */
    public function generate(Request $request, OutreachWriter $writer)
    {
        $shop = $this->shop($request);

        try {
            $out = $writer->templatesForShop($shop);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Could not generate right now. Please try again.'], 502);
        }

        return response()->json($out);
    }
```

- [ ] **Step 3: Add `personalize()` to `LeadController`**

In `app/Http/Controllers/LeadController.php`, add the import (below existing `use` lines):

```php
use App\Services\Leads\OutreachWriter;
```

Add this method (after `logFollowup()`):

```php
    /**
     * POST /shop/leads/{lead}/personalize
     * AI-writes ONE ready-to-send message for this specific lead. Does not change
     * status or log activity (that happens when the user opens WhatsApp).
     */
    public function personalize(Request $request, Lead $lead, OutreachWriter $writer)
    {
        $shop = $this->shop($request);
        abort_unless($lead->shop_id === $shop->id, 404);

        $data = $request->validate([
            'kind' => ['required', Rule::in(['opening', 'followup'])],
        ]);

        try {
            $message = $writer->personalizeForLead($shop, $lead, $data['kind']);
        } catch (\Throwable $e) {
            report($e);
            return response()->json(['message' => 'Could not generate right now. Please try again.'], 502);
        }

        return response()->json(['message' => $message, 'kind' => $data['kind']]);
    }
```

- [ ] **Step 4: Register the routes**

In `routes/api.php`, inside the existing `auth:sanctum` lead group (next to the other `/shop/lead*` routes), add:

```php
    Route::post  ('/shop/lead-messages/generate',      [\App\Http\Controllers\LeadMessageController::class, 'generate']);
    Route::post  ('/shop/leads/{lead}/personalize',    [\App\Http\Controllers\LeadController::class, 'personalize']);
```

> Order note: `/shop/lead-messages/generate` is a static path and won't collide with `/shop/leads/{lead}`; but keep it grouped with the other `/shop/lead-messages` routes for clarity.

- [ ] **Step 5: Run on staging (routes are new → clear route cache first)**

Run:
```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && php8.3 artisan optimize:clear && php8.3 artisan test tests/Feature/OutreachEndpointsTest.php'
```
Expected: 5 passing tests.

- [ ] **Step 6: Commit**

```bash
git add app/Http/Controllers/LeadMessageController.php app/Http/Controllers/LeadController.php routes/api.php tests/Feature/OutreachEndpointsTest.php
git commit -m "feat(leads): generate (shop templates) + personalize (per-lead) AI endpoints"
```

---

### Task 4: "✨ Generate with AI" on the Lead Messages page

**Files:**
- Modify: `admin/src/lib/leadMessages.ts` (add `generateLeadMessages`)
- Modify: `admin/src/pages/LeadMessages.tsx` (button + wiring)
- Test: `admin/src/pages/LeadMessages.test.tsx` (add a test)

**Interfaces:**
- Consumes: `POST /shop/lead-messages/generate` (Task 3).
- Produces: `generateLeadMessages(): Promise<{ opening: string; followup: string }>`.

- [ ] **Step 1: Add the lib function**

In `admin/src/lib/leadMessages.ts`, add:

```ts
/** AI-generate opening + follow-up templates from the shop profile. Not saved. */
export async function generateLeadMessages(): Promise<{ opening: string; followup: string }> {
  const { data } = await api.post('/shop/lead-messages/generate');
  return { opening: data?.opening ?? '', followup: data?.followup ?? '' };
}
```

- [ ] **Step 2: Write the failing test**

In `admin/src/pages/LeadMessages.test.tsx`, add a test inside the existing `describe`:

```tsx
  it('generates templates with AI and fills the fields without saving', async () => {
    vi.spyOn(lib, 'getLeadMessages').mockResolvedValue({
      opening: null, followup: null,
      default_opening: 'Hi {name}, opening default', default_followup: 'Hi {name}, followup default',
    });
    const gen = vi.spyOn(lib, 'generateLeadMessages').mockResolvedValue({
      opening: 'AI opening {name}', followup: 'AI followup {name}',
    });
    const save = vi.spyOn(lib, 'saveLeadMessages');

    setup();
    await screen.findByLabelText('Opening message');
    await userEvent.click(screen.getByRole('button', { name: /generate with ai/i }));

    expect(gen).toHaveBeenCalled();
    expect((await screen.findByLabelText('Opening message') as HTMLTextAreaElement).value).toBe('AI opening {name}');
    expect((screen.getByLabelText('Follow-up message') as HTMLTextAreaElement).value).toBe('AI followup {name}');
    expect(save).not.toHaveBeenCalled(); // generate never auto-saves
  });
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/LeadMessages.test.tsx`
Expected: FAIL — no "Generate with AI" button / `generateLeadMessages` not imported.

- [ ] **Step 4: Wire the button into `LeadMessages.tsx`**

Update the import line to include the new fn:

```tsx
import { getLeadMessages, saveLeadMessages, generateLeadMessages } from '@/lib/leadMessages';
```

Add generating state next to the existing state hooks:

```tsx
  const [generating, setGenerating] = useState(false);
```

Add the handler (next to `save`):

```tsx
  const generate = () => {
    setGenerating(true); setError(''); setNotice('');
    generateLeadMessages()
      .then((m) => {
        setOpening(m.opening);
        setFollowup(m.followup);
        setNotice('Generated — review, edit, then Save.');
      })
      .catch(() => setError('Could not generate right now. Please try again.'))
      .finally(() => setGenerating(false));
  };
```

Add the button just above the existing "Save messages" button (inside the same container, after the follow-up field):

```tsx
          <button className="c-btn-ghost" style={{ width: '100%', display: 'inline-flex', alignItems: 'center', justifyContent: 'center', gap: 8 }}
            disabled={generating || saving} onClick={() => generate()}>
            <Icons.Sparkle size={16} /> {generating ? 'Generating…' : 'Generate with AI'}
          </button>
```

> `Icons.Sparkle` is already used on the AI Assistant page (`Assistant.tsx`) — confirm it exists in `admin/src/components/Icons.tsx` (it does). If missing, use `Icons.Chat` as a fallback.

- [ ] **Step 5: Run test + type-check**

Run: `cd admin && npx vitest run src/pages/LeadMessages.test.tsx && npx tsc --noEmit`
Expected: tests PASS, no type errors.

- [ ] **Step 6: Commit**

```bash
git add admin/src/lib/leadMessages.ts admin/src/pages/LeadMessages.tsx admin/src/pages/LeadMessages.test.tsx
git commit -m "feat(leads): Generate-with-AI button on the Lead Messages page"
```

---

### Task 5: "✨ Personalize" + preview on the lead detail page

**Files:**
- Modify: `admin/src/lib/leads.ts` (add `personalizeLead`)
- Modify: `admin/src/pages/LeadDetail.tsx` (button + preview panel)
- Test: `admin/src/pages/LeadDetail.test.tsx` (add a test)

**Interfaces:**
- Consumes: `POST /shop/leads/{id}/personalize` (Task 3); existing `updateLeadStatus`, `logFollowup`; `lead` digits for the wa.me URL.
- Produces: `personalizeLead(id: number, kind: 'opening'|'followup'): Promise<string>`.

- [ ] **Step 1: Add the lib function**

In `admin/src/lib/leads.ts`, add after `logFollowup`:

```ts
/** AI-write one ready-to-send message for this specific lead. Not saved. */
export async function personalizeLead(id: number, kind: 'opening' | 'followup'): Promise<string> {
  const { data } = await api.post(`/shop/leads/${id}/personalize`, { kind });
  return data?.message ?? '';
}
```

- [ ] **Step 2: Write the failing test**

In `admin/src/pages/LeadDetail.test.tsx`, add a test inside the existing `describe` (reuses the file's `baseLead`/`setup`):

```tsx
  it('personalizes a New lead: previews AI text, then opens WhatsApp and marks Sent', async () => {
    vi.spyOn(leadsLib, 'getLead').mockResolvedValue({ lead: { ...baseLead }, activities: [] });
    const personalize = vi.spyOn(leadsLib, 'personalizeLead').mockResolvedValue('Hi Pak Cargo, quick demo?');
    const setStatus = vi.spyOn(leadsLib, 'updateLeadStatus').mockResolvedValue({ ...baseLead, status: 'sent' });

    setup();
    await userEvent.click(await screen.findByRole('button', { name: /personalize/i }));

    // Preview shows the AI message
    expect(await screen.findByText('Hi Pak Cargo, quick demo?')).toBeInTheDocument();
    expect(personalize).toHaveBeenCalledWith(3, 'opening');

    // Opening WhatsApp from the preview uses the AI text and advances the stage
    await userEvent.click(screen.getByRole('button', { name: /open whatsapp/i }));
    expect(window.open).toHaveBeenCalledWith(
      'https://wa.me/971501112233?text=' + encodeURIComponent('Hi Pak Cargo, quick demo?'),
      '_blank',
    );
    expect(setStatus).toHaveBeenCalledWith(3, 'sent');
  });
```

- [ ] **Step 3: Run the test to verify it fails**

Run: `cd admin && npx vitest run src/pages/LeadDetail.test.tsx`
Expected: FAIL — no Personalize button / `personalizeLead` not wired.

- [ ] **Step 4: Implement the button + preview in `LeadDetail.tsx`**

Update the leads import to include `personalizeLead`:

```tsx
import { getLead, updateLeadStatus, logFollowup, personalizeLead } from '@/lib/leads';
```

Add state for the preview (next to the other hooks):

```tsx
  const [aiText, setAiText] = useState<string | null>(null);
  const [aiKind, setAiKind] = useState<'opening' | 'followup'>('opening');
  const [aiBusy, setAiBusy] = useState(false);
```

Add a helper that maps status → kind, and the personalize + confirm handlers (after `sendFollowup`):

```tsx
  const outreachKind = (): 'opening' | 'followup' => (lead?.status === 'new' ? 'opening' : 'followup');

  // Digits for the wa.me link (server already normalized whatsapp_url).
  const waDigits = (): string | null => {
    const m = lead?.whatsapp_url?.match(/wa\.me\/(\d+)/);
    return m ? m[1] : null;
  };

  const personalize = async () => {
    if (!lead || aiBusy) return;
    const kind = outreachKind();
    setAiBusy(true); setError('');
    try {
      const text = await personalizeLead(lead.id, kind);
      setAiKind(kind);
      setAiText(text);
    } catch {
      setError('Could not generate right now. Please try again.');
    } finally {
      setAiBusy(false);
    }
  };

  // Send the previewed AI message: open WhatsApp with it, then run the same
  // stage transition as the normal outreach button.
  const sendAi = async () => {
    if (!lead || !aiText || busy) return;
    const digits = waDigits();
    if (digits) window.open(`https://wa.me/${digits}?text=${encodeURIComponent(aiText)}`, '_blank');
    setBusy(true); setError('');
    try {
      if (aiKind === 'opening') await updateLeadStatus(lead.id, 'sent');
      else await logFollowup(lead.id);
      setAiText(null);
      await load();
    } catch {
      setError('Could not update status.');
    } finally {
      setBusy(false);
    }
  };
```

Add the Personalize button in `ld-actions`, right after the WhatsApp/Follow-up buttons (same visibility gate — mobile + not won/pass):

```tsx
              {lead.is_mobile && (lead.status === 'new' || lead.status === 'sent' || lead.status === 'replied' || lead.status === 'demo') && (
                <button type="button" className="ld-act" disabled={aiBusy || busy} onClick={() => void personalize()}>
                  <Icons.Sparkle size={16} /> {aiBusy ? 'Writing…' : 'Personalize'}
                </button>
              )}
```

Add the preview panel just after the `ld-actions` closing `</div>` (only when there's AI text):

```tsx
            {aiText && (
              <div className="ba-card" style={{ padding: 14, marginTop: 12, display: 'flex', flexDirection: 'column', gap: 10 }}>
                <div className="c-field-label" style={{ margin: 0 }}>AI {aiKind === 'opening' ? 'opening' : 'follow-up'} — review before sending</div>
                <p style={{ margin: 0, whiteSpace: 'pre-wrap', color: 'var(--text-1)', fontSize: 13.5, lineHeight: 1.5 }}>{aiText}</p>
                <div style={{ display: 'flex', gap: 8 }}>
                  <button type="button" className="ld-act wa" disabled={busy} onClick={() => void sendAi()}>
                    <Icons.WhatsApp size={16} /> Open WhatsApp
                  </button>
                  <button type="button" className="c-btn-ghost" disabled={aiBusy} onClick={() => void personalize()}>
                    {aiBusy ? 'Writing…' : 'Regenerate'}
                  </button>
                </div>
              </div>
            )}
```

- [ ] **Step 5: Run test + type-check**

Run: `cd admin && npx vitest run src/pages/LeadDetail.test.tsx && npx tsc --noEmit`
Expected: all LeadDetail tests PASS (existing + new), no type errors.

- [ ] **Step 6: Commit**

```bash
git add admin/src/lib/leads.ts admin/src/pages/LeadDetail.tsx admin/src/pages/LeadDetail.test.tsx
git commit -m "feat(leads): Personalize-with-AI button + preview on lead detail"
```

---

### Task 6: Deploy to staging, verify, then promote to production

**Files:** none (deploy + verify).

- [ ] **Step 1: Local frontend gate**

Run: `cd admin && npx tsc --noEmit && npx vitest run src/pages/LeadMessages.test.tsx src/pages/LeadDetail.test.tsx`
Expected: clean.

- [ ] **Step 2: Push, then deploy backend to STAGING**

```bash
git push origin main
ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && git pull --ff-only && php8.3 artisan optimize:clear'
```

- [ ] **Step 3: Run the new backend suites on STAGING**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend-staging && php8.3 artisan test tests/Feature/LeadWhatsAppDraftTest.php tests/Feature/OutreachWriterTest.php tests/Feature/OutreachEndpointsTest.php'
```
Expected: all green.

- [ ] **Step 4: Deploy the STAGING frontend**

Run: `admin/deploy-staging.ps1`

- [ ] **Step 5: Manual smoke test on staging (real AI calls)**

On `staging-admin.eloquentservice.com`: Settings → Lead messages → **Generate with AI** → fields fill with shop-specific copy → edit → Save. Open a New lead → **Personalize** → preview shows a lead-specific message → Open WhatsApp drafts it and the lead moves to Sent. Confirm `{category}`/`{area}` render on a saved template.

- [ ] **Step 6: Promote to PRODUCTION (only after staging is confirmed good; ask Francis to confirm first)**

```bash
ssh root@64.227.153.90 'cd /var/www/eloquent-backend && git pull --ff-only && php8.3 artisan config:cache && php8.3 artisan route:cache && systemctl reload php8.3-fpm'
```
Then deploy the prod frontend: `admin/deploy.ps1`.
Do NOT run tests or any destructive DB command on production. No migration is needed for this feature.
