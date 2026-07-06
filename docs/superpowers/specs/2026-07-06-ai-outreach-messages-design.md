# AI-Written Outreach Messages — Design

Date: 2026-07-06

## Problem

The lead outreach templates (`Lead::DEFAULT_OPENING` / `DEFAULT_FOLLOWUP`, editable per shop) are one-size-fits-all. Every shop that registers sells something different (salon vs. cargo vs. gym) and messages every lead the same way. Francis wants messages that are **impactful and specific per customer**, not generic — generated automatically so shops don't have to write good copy themselves.

## Insight

Personalize on two axes:
- **Sender** — what *this shop* sells (industry, services, value prop, tone).
- **Recipient** — the specific lead (business name, industry/category, area) — data the app already captured when it found the lead.

## Scope (v1)

Three parts, all built local → staging → prod:

1. **Per-shop generator** — a "✨ Generate with AI" button on the Lead Messages page that writes a tailored opening + follow-up **template** (placeholders intact) from the shop's profile.
2. **Per-lead personalizer** — a "✨ Personalize" button on the lead detail page that writes a **bespoke, ready-to-send message** for one specific lead, shown in a preview before opening WhatsApp.
3. **Richer placeholders** — add `{category}` and `{area}` to the server-side renderer alongside `{name}` and `{shop}`.

**Out of scope:** auto-generation at registration/first-visit (chose on-demand button); persisting generated copy without an explicit Save; per-lead A/B testing; analytics on reply rates.

## Existing building blocks (reuse, don't rebuild)

- `App\Services\Wa\ClaudeClient` — Anthropic Messages API client. `reply(string $system, array $history): string`. Constructor-injectable → bindable to a fake in tests. Model from `config('services.anthropic.model', 'claude-haiku-4-5')`.
- `App\Support\Wa\PromptGenerator` — the pattern for summarizing a shop's profile (name, `categoryLabel()`, `catalogs()` services+prices, `location`, working hours). The new writer builds a compact profile summary the same way.
- `LeadMessageController` (`GET/PUT /shop/lead-messages`), `Lead::DEFAULT_OPENING/DEFAULT_FOLLOWUP`, `Lead::draftUrl()` placeholder renderer, `LeadMessages.tsx` editor, and the lead detail outreach buttons — all already shipped.
- The persona page's "Generate from profile" (`GET /shop/persona/generate` → `{prompt}`, `Assistant.tsx`) is the UX precedent for the generate button.

## Copy rules (the heart of "impactful vs generic")

The system prompt instructs the model to produce WhatsApp cold-outreach that is:
- **Short** — opening 2–4 short lines; follow-up 1–2 lines. Long WhatsApp gets ignored.
- **Specific hook about the recipient** — reference their business/industry, not a feature dump.
- **One value prop** relevant to *the sender's* offering and *the recipient's* industry.
- **Soft CTA** — a low-friction question ("Worth a quick 2-min demo?"), never "buy now".
- **Follow-up = a new angle** (proof point, or a question), never a copy of the opening.
- **No fabricated claims** — don't invent stats, names, or offers not implied by the profile.
- Plain text suitable for WhatsApp; at most a light emoji.

## Backend

### New service — `app/Services/Leads/OutreachWriter.php`

Constructor-injects `ClaudeClient`. Two public methods:

- `templatesForShop(Shop $shop): array` → `['opening' => string, 'followup' => string]`.
  - Builds a system prompt = copy rules + a compact shop-profile summary (name, category label, services with prices, location) + an instruction to **keep the literal placeholders** `{name}` (the lead's business name), `{category}` (lead's industry), `{area}` (lead's location) in the output, and to sign as `{shop}` where natural.
  - Asks for a strict JSON object `{"opening": "...", "followup": "..."}`; parses it. On parse failure or Claude error, throws — the controller maps that to a clean error.
- `personalizeForLead(Shop $shop, Lead $lead, string $kind): string` where `$kind ∈ {opening, followup}`.
  - Same profile summary + the **specific lead's** name, category, area. Instruction: write ONE ready-to-send message (no placeholders — use the real values) matching `$kind`.
  - Returns the message text.

Both methods keep `max_tokens` modest and rely on `ClaudeClient`'s existing retry/error handling.

### Endpoints (add to the existing tenant-scoped lead group in `routes/api.php`)

- `POST /shop/lead-messages/generate` → `LeadMessageController@generate`
  - Returns `{ opening, followup }` from `OutreachWriter::templatesForShop`. **Not saved** — the client fills the editor; the existing `PUT /shop/lead-messages` persists on Save.
  - Claude failure → HTTP 502 `{ message: "Could not generate right now. Please try again." }`.
- `POST /shop/leads/{lead}/personalize` → `LeadController@personalize`
  - Body: `{ kind: 'opening'|'followup' }` (validated; default derived from status if omitted). Tenant-scoped (`abort_unless($lead->shop_id === $shop->id, 404)`).
  - Returns `{ message, kind }`. Does **not** change status or log activity (that happens when the user actually opens WhatsApp via the existing flow).
  - Claude failure → HTTP 502 with the same shape.

### Placeholder renderer — `app/Models/Lead.php`

Extend the `strtr` map in `draftUrl()`:
```php
$text = strtr($template, [
    '{name}'     => (string) $this->name,
    '{shop}'     => (string) ($this->shop?->name ?? ''),
    '{category}' => (string) ($this->categoryLabel() ?? $this->category ?? ''),
    '{area}'     => (string) ($this->area() ?? ''),
]);
```
- `{category}`: a human label for the lead's industry. Lead stores a raw `category` slug; add a small `categoryLabel()` accessor (humanize the slug, e.g. `beauty_salon` → `Beauty Salon`) — or fall back to the raw value.
- `{area}`: add an `area()` helper returning the lead's `address` (leads from Google Places carry a short area string), or empty. Keep it simple — no geocoding.

## Frontend

### Lead Messages page — `admin/src/pages/LeadMessages.tsx`
- Add a "✨ Generate with AI" button (loading state, disabled while generating), above/near Save, styled like the persona page's `c-btn-ghost` Generate button.
- On click: `POST /shop/lead-messages/generate` → fill both textareas with the returned opening/followup (do **not** auto-save; the user reviews, edits, hits Save). Show a notice: "Generated — review, edit, then Save." On error, show the error box.
- Update the helper text to mention `{category}` and `{area}` too.
- New lib fn `generateLeadMessages()` in `admin/src/lib/leadMessages.ts`.

### Lead detail page — `admin/src/pages/LeadDetail.tsx`
- Add a "✨ Personalize" button in `ld-actions`, shown for the same statuses as the outreach button (`is_mobile` and status ∈ {new,sent,replied,demo}).
- On click: `POST /shop/leads/{id}/personalize` with `kind` = `new` ? `opening` : `followup`. Show the returned message in a small **preview panel** (inline card or lightweight modal) with two actions:
  - **Open WhatsApp** → open `https://wa.me/{digits}?text={encoded message}` (build client-side from `lead` digits + the AI text), then run the SAME stage transition as the normal button (`new` → `updateLeadStatus('sent')`; else → `logFollowup`), then reload.
  - **Regenerate** → call the endpoint again.
- New lib fn `personalizeLead(id, kind)` in `admin/src/lib/leads.ts`.

## Errors, cost, safety
- Both endpoints sit behind the existing `auth:sanctum + rbac.context + subscription.active` group.
- One Claude call per button click. No background/auto calls. No new API key (reuses `services.anthropic.key`).
- All Claude failures degrade gracefully: generate → keep current editor contents; personalize → user can retry or just use the normal template button.

## Testing (all on staging; never prod)
- **Backend** (bind a fake `ClaudeClient` in the container, like `LeadFinderTest`'s fake source — no real API calls):
  - `generate` returns the fake's `{opening, followup}`; tenant-scoped; 502 on writer exception.
  - `personalize` returns a message using the lead's real values; validates `kind`; tenant-scoped (404 cross-shop).
  - `Lead::draftUrl` renders `{category}` and `{area}` (and still `{name}`/`{shop}`); `categoryLabel()`/`area()` fall back cleanly when data is missing.
- **Frontend** (vitest, mock the libs): Generate fills both textareas without saving; Personalize shows the preview and Open WhatsApp triggers the stage transition.

## Rollout
Local build + `tsc`/`vitest` → deploy to staging, run `php artisan test` + click-through with a real-looking shop → promote code+migration(none needed here, but the `categoryLabel`/`area` are code-only)+frontend to prod only when Francis approves.
