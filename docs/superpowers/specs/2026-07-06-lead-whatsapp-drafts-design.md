# Lead WhatsApp Drafts & Follow-up — Design

Date: 2026-07-06

## Problem

The lead detail page's WhatsApp button opens a blank `wa.me` chat. The user wants:

1. Clicking WhatsApp to open a **pre-drafted opening message** (personalized with the business name).
2. The app to **remember** that the opening message was already sent to a lead.
3. A **Follow-up** button that appears **only after** the opening message has been sent, which opens WhatsApp pre-drafted with a **follow-up message**. Repeatable.

## Key insight

The funnel already models this. Statuses are `new → sent → replied → demo → won` (or `pass` = Not Interested). The **Sent** stage *is* the record of "opening message sent." We reuse it instead of adding a new flag.

## Scope

- **In scope:** Smart WhatsApp/Follow-up buttons on the **lead detail page only**. Editable message templates in Settings.
- **Out of scope (v1):** Smart buttons on the Leads list cards (list keeps its plain WhatsApp icon). Multi-step escalating follow-ups (follow-up 1/2/3). Detecting whether the message was actually sent.

## Behaviour

Stage-aware outreach button in the hero `ld-actions` row:

| Lead status            | Button shown | Click action |
|------------------------|--------------|--------------|
| `new`                  | **WhatsApp** | Open `whatsapp_opening_url` (opening draft), then mark sent: move `new → sent`, log status-change activity, bump `last_contacted_at`. Reload so the funnel visibly advances. |
| `sent`, `replied`, `demo` | **Follow-up** | Open `whatsapp_followup_url` (follow-up draft), log a `contacted` activity, bump `last_contacted_at`. Status unchanged. Repeatable. |
| `won`, `pass`          | *(none)*     | Deal closed — no outreach button. |

The Call / Website / Map actions are unaffected.

### Honest limitation

A `wa.me` deep link cannot report whether the user actually tapped **send** in WhatsApp — only that the draft was opened. The stage therefore advances **optimistically** on click. The user can drag the stage back on the switch if they didn't send. This matches standard behaviour for click-to-WhatsApp tools and is acceptable.

## Message templates

- Stored per shop as two new columns on `shops`: `lead_opening_template`, `lead_followup_template` (both `text`, nullable). `Shop` uses `$guarded = []`, so no fillable change needed.
- Editable in **Settings** under a new "Lead outreach messages" section: two textareas + helper text explaining the `{name}` placeholder. Saved via the existing settings save flow.
- Ship sensible UAE-B2B defaults (used when the column is null) so the feature works before any editing.
- **Placeholder:** `{name}` → the lead's business name. Rendered server-side. Unknown placeholders are left as-is.

### Default templates (starting point, editable)

- Opening: `Hi {name}, this is [shop] — we help UAE businesses take bookings and reply to customers on WhatsApp automatically. Could I share a quick demo?`
- Follow-up: `Hi {name}, just following up on my earlier message — happy to send a short demo whenever suits you.`

(`[shop]` note: templates are per shop and the owner writes their own name in; we do not auto-inject the shop name in v1 to keep rendering to a single `{name}` placeholder. This can be revisited.)

## Backend

### Model — `app/Models/Lead.php`

Two new computed URL attributes (appended alongside `whatsapp_url`), rendering the shop's template with `{name}` substituted and URL-encoding the result onto `wa.me/{digits}?text=`:

- `getWhatsappOpeningUrlAttribute(): ?string`
- `getWhatsappFollowupUrlAttribute(): ?string`

Both return `null` when there is no valid mobile number (same guard as `whatsapp_url`). The lead must be able to reach its shop's templates (already has `shop()` relation); resolve the template from `$this->shop` with a fallback to the packaged default constant.

### Endpoints — `app/Http/Controllers/LeadController.php`

- **Mark opening sent:** reuse `PATCH /shop/leads/{lead}/status` with `status=sent` (already logs the activity and bumps `last_contacted_at`). No new endpoint needed for the opening.
- **Log follow-up:** add `POST /shop/leads/{lead}/followup` → creates a `contacted` activity, bumps `last_contacted_at`, returns the fresh lead. Tenant-scoped like the other lead endpoints (`abort_unless($lead->shop_id === $shop->id, 404)`).

### Settings persistence

- Extend the shop settings update controller/route that Settings.tsx already posts to, validating `lead_opening_template` / `lead_followup_template` as nullable strings (max ~2000). Confirm the exact controller during implementation (ShopController or the settings-specific one).

### Migration

`add_lead_outreach_templates_to_shops` — add nullable `text` columns `lead_opening_template`, `lead_followup_template`.

## Frontend

### `admin/src/pages/LeadDetail.tsx`

- Replace the single WhatsApp `<a>` in `ld-actions` with a stage-aware control per the behaviour table.
- Opening click: open the draft URL in a new tab, `await updateLeadStatus(lead.id, 'sent')`, then `load()`.
- Follow-up click: open the draft URL, call the new `logFollowup(lead.id)` lib fn, then `load()`.
- Add `whatsapp_opening_url` / `whatsapp_followup_url` to the `Lead` type in `admin/src/types.ts`.
- Add `logFollowup` to `admin/src/lib/leads.ts`.

### `admin/src/pages/Settings.tsx`

- New "Lead outreach messages" section: two labelled textareas bound to the shop settings, helper text noting `{name}` inserts the business name. Save through the existing settings mechanism.

## Testing

- **Backend (run on droplet, php8.4):**
  - `whatsapp_opening_url` / `whatsapp_followup_url` render `{name}` and URL-encode correctly; return null for non-mobile numbers.
  - Templates fall back to defaults when columns are null; use custom text when set.
  - `POST followup` logs a `contacted` activity, bumps `last_contacted_at`, and is tenant-scoped (404 across shops).
  - Settings update persists the two template fields.
- **Frontend:** button swaps by status (new → WhatsApp; sent/replied/demo → Follow-up; won/pass → none). Opening click advances stage to Sent.

## Rollout

Deploy backend + frontend via `admin/deploy.ps1` per the usual flow. Migration runs on deploy.
