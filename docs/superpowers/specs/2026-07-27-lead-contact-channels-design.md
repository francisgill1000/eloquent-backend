# Lead Contact Channels — Design

**Date:** 2026-07-27
**Product:** Business Hunt (`leads` module)
**Status:** Design approved, awaiting implementation plan

## Problem

Business Hunt records *that* a lead was contacted but not *how*. Outreach in
practice spans Instagram, WhatsApp, Facebook, TikTok, LinkedIn, phone, email and
in-person visits, and a lead may be approached on one channel and reply on
another. None of that is captured.

Three concrete gaps in the current code:

1. **The channel is a lie by omission.** `lead_activities.payload` already has a
   `channel` key, hardcoded to `'whatsapp'` in both
   `LeadController::logFollowup` (`app/Http/Controllers/LeadController.php:396`)
   and `HuntTools::logFollowup` (`app/Services/Assistant/Modules/HuntTools.php:221`).
   Every touch ever logged claims to be WhatsApp.
2. **Non-phone leads cannot be worked.** The Follow-up button on the lead detail
   page renders only when `is_mobile` is true
   (`admin/src/pages/LeadDetail.tsx:478`). A lead reachable only on Instagram has
   no way to log a touch at all.
3. **Nowhere to put a handle.** A lead stores `phone`, `whatsapp` and `website`.
   Neither lead source supplies social handles — `GooglePlacesSource` returns a
   `website`, and `AdLibraryService` stuffs a Facebook page URL into that same
   `website` field. There is also no lead-edit endpoint, so a handle could not be
   entered by hand even if the column existed.

The timeline renders every touch as the flat string `'Contacted'`
(`admin/src/pages/LeadDetail.tsx:109`).

## Goals

1. **Per-touch channel.** Every logged contact records which channel was used and
   in which direction.
2. **Handles per lead.** Store Instagram / Facebook / TikTok / LinkedIn / email so
   the app can link out and log the touch in one action.
3. **Inbound capture.** Record the channel a lead replied on, both as part of the
   move to `replied` and as a standalone action.
4. **Reporting.** A "which channel works" breakdown on the Hunt dashboard.

## Non-goals

- Sending or receiving messages in-app. Every channel button opens the external
  app; Business Hunt records the touch, it does not become an inbox.
- Auto-discovering handles by scraping websites or social platforms. Handles are
  entered by hand, with one exception (see *Facebook import salvage*).
- Making the channel list user-configurable. It is fixed, in the same spirit as
  `Lead::STATUSES` ("deliberately not user-configurable").
- Per-channel message templates. `OutreachWriter` keeps writing one message;
  channel-specific tone is a later question.
- Threading multiple touches into conversations.

---

## Architecture

### Channel vocabulary

A fixed list on `LeadActivity`:

```
whatsapp · instagram · facebook · tiktok · linkedin · phone · email · walk_in · other
```

`walk_in` is included because in-person visits are a real part of the sales
motion and are otherwise invisible. `other` is the escape hatch so an unusual
channel never blocks logging.

Direction is `out` (we contacted them) or `in` (they contacted us).

### Data model

**Migration — `add_channel_to_lead_activities`**

| Column | Type | Notes |
|---|---|---|
| `channel` | nullable string | one of the fixed list; null for `status_change` / `note` / `assigned` rows |
| `direction` | nullable string | `out` \| `in`; null where not applicable |

Index: `(channel)` — reports group on it. The existing `(lead_id)` index stays.

Both columns are nullable rather than defaulted: a status change has no channel,
and a default would fabricate one.

**Backfill (same migration).** Every existing row with `type = 'contacted'` gets
`channel = 'whatsapp'`, `direction = 'out'`. This is not a guess — it is exactly
what the hardcoded payload already asserted. Rows of other types are left null.

**Migration — `add_handles_to_leads_table`**

| Column | Type |
|---|---|
| `instagram` | nullable string |
| `facebook` | nullable string |
| `tiktok` | nullable string |
| `linkedin` | nullable string |
| `email` | nullable string |

All added to `Lead::$fillable`.

**Why columns and not JSON.** `ReportsAggregator::huntSummary` aggregates
activity payloads *in PHP*, with the comment "portable across sqlite/pgsql — no
JSON SQL" (`app/Services/Reports/ReportsAggregator.php:426`). Channel reporting
via `payload` would inherit that constraint: load every row, group in memory,
with no index and no write-time validation. Real columns give a plain
`GROUP BY channel` that behaves identically on sqlite and Postgres.

A separate `lead_touches` table was rejected: it would split a lead's story
across two tables and force the timeline to merge them.

### Handle normalization

`App\Support\SocialHandle` — a small pure class, no I/O, so it is trivially
testable.

```php
SocialHandle::normalize(string $platform, string $input): ?string
```

Accepts `@name`, `name`, `instagram.com/name`, `https://www.instagram.com/name/`,
and with-query variants. Stores one canonical absolute URL per platform. Returns
null for input that cannot be interpreted as a handle for that platform, so the
caller can reject rather than store garbage.

Per-platform URL shapes:

| Platform | Canonical form |
|---|---|
| instagram | `https://instagram.com/{handle}` |
| facebook | `https://facebook.com/{handle}` |
| tiktok | `https://tiktok.com/@{handle}` |
| linkedin | preserved as given when a full URL (company vs. personal paths differ); a bare handle becomes `https://linkedin.com/company/{handle}` |
| email | not a URL — validated as an email address, stored as-is |

Cross-platform input is rejected: pasting an Instagram URL into the LinkedIn
field returns null rather than storing a mislabelled link.

**Facebook import salvage.** `AdLibraryService` writes Facebook page URLs into
`website` and already discriminates on them (`AdLibraryService.php:266`). On
import, a `website` value on the `facebook.com` host moves to the `facebook`
column instead, leaving `website` null. A lead whose only link was its Facebook
page therefore arrives with a working channel button. Applied on new imports
only — no retroactive migration of existing rows.

### API

**Replaces** `POST /shop/leads/{lead}/followup` with:

```
POST /shop/leads/{lead}/touch   { channel, direction, note? }
```

`leads.manage`, inside the existing `module:leads` group. One endpoint for both
directions — a touch is a touch, and two near-identical endpoints would drift.
`channel` is required and validated against the fixed list; `direction` is
required and validated.

`POST /shop/leads/{lead}/followup` survives only as a deprecated deploy-window
alias that forwards to the touch handler with `whatsapp` / `out`, because the
backend ships before the SPA does. It is deleted in the follow-up commit once
the new SPA is live (see *Migration & rollout*). It must not outlive that
window: a route that silently means "whatsapp, outbound" is the original bug.

Behaviour:

- `direction: out` → bumps `last_contacted_at`, logs a `contacted` activity.
- `direction: in` → logs the activity but **does not** touch `last_contacted_at`.

That asymmetry is deliberate. `last_contacted_at` drives `Lead::scopeStale`
("worked leads that have gone cold"). Letting a reply from the lead reset the
clock would mark a lead as freshly worked when in fact the ball is in *our*
court — precisely the lead most at risk of being dropped.

Neither direction changes `status`. The funnel stays under `updateStatus`.

**New** `PATCH /shop/leads/{lead}` — `leads.manage`.

Accepts only contact fields: `instagram`, `facebook`, `tiktok`, `linkedin`,
`email`, `phone`, `whatsapp`, `website`, `notes`. Handle fields pass through
`SocialHandle::normalize` and a value that fails normalization is a 422, not a
silent null. Explicitly **not** accepted: `status`, `assigned_to_id`,
`deal_amount`, `deal_type`, `deal_term_months`, `shop_id`. Those have guarded
endpoints with their own permissions and activity logging; a general edit
endpoint must not become a side door around `leads.assign`.

Route ordering note: `/shop/leads/{lead}` PATCH is declared with the other
`{lead}` routes, after the static `/shop/leads/*` paths, per the existing
comment in `routes/api.php:290`.

**Extended** `PATCH /shop/leads/{lead}/status` — accepts an optional
`reply_channel`, meaningful only when `status = 'replied'`. When present, it is
validated against the fixed list and an inbound `contacted` activity is logged
alongside the status change, in the same transaction. Absent means unknown,
which is honest and must not be defaulted.

**Extended** `GET /shop/leads/{lead}` — activities now carry `channel` and
`direction` in the selected columns.

### Voice tools

`HuntTools::logFollowup` gains an optional `channel` argument and an optional
`direction` (default `out`), so "log that I messaged Al Noor Gym on Instagram"
and "Al Noor replied on WhatsApp" both work. Omitted channel logs null rather
than assuming WhatsApp — the assistant should not invent facts about how a
conversation happened. The tool description lists the valid channels so the
model maps spoken words ("insta", "IG") onto `instagram`.

The confirm-gate `describe` string names the channel, so the owner hears what
will be recorded before it is written.

Permissions are unchanged: `log_followup` stays on `leads.manage`.

### Frontend — lead detail

**Channel action row.** The single `is_mobile`-gated WhatsApp button is replaced
by a row of channel buttons, one per channel the lead actually has a handle for
(WhatsApp and phone from the existing normalized fields, the rest from the new
columns). Each button opens the external target in a new tab and logs
`{channel, direction: out}`. A lead with only an Instagram handle is now fully
workable.

An overflow **"Log a touch"** control covers channels with no stored handle —
phone call placed from elsewhere, walk-in visit, `other`. It opens a channel
picker and logs without navigating anywhere.

The AI **Personalize** button loses its `is_mobile` gate: a written opener is
just as useful pasted into Instagram as into WhatsApp.

**Handles editor.** A contact-details panel on the lead detail page, saving via
the new PATCH. Visible to `leads.manage` only, matching the rest of the page's
`locked` treatment.

**"They replied" action.** A standalone control logging `{channel, direction:
in}` at any stage, so a second reply on a different channel is not lost.

**Replied picker.** Moving a lead to `replied` opens a small channel picker
before committing, reusing the interaction pattern of the existing won-deal
modal. Dismissing it commits the status change with no channel.

**Timeline.** `activityText` becomes direction-aware and names the channel:
"You messaged them on Instagram", "They replied on WhatsApp". A touch with no
channel reads "Contacted", preserving today's wording for backfilled and
unknown-channel rows. Each row carries a small per-channel icon, and channel
colours are defined once and shared between the timeline and the dashboard chart
so a channel reads as the same colour everywhere.

### Reporting

`ReportsAggregator::huntByChannel(int $shopId, Carbon $from, Carbon $to): array`

Per channel: outbound touches, inbound replies, leads won, won value. Plus an
always-present `unattributed` bucket.

**Attribution rule.** A won lead is credited to the channel of its **first
outbound touch** — the channel that opened the conversation, not whichever one
happened to be in use at closing. Without a fixed rule the same win could be
counted differently by two queries.

A lead won with no logged outbound touch falls into `unattributed`. That bucket
is always rendered, including at zero, so the channel columns can never silently
under-count wins.

Won value reuses the existing `dealTotal()` helper, so a recurring deal is valued
identically here and on every other Hunt report.

Agent scoping follows the existing Hunt reports exactly: `agentLeadFilter()` is
applied to the underlying lead join, so an agent sees only their own leads and a
manager sees the shop.

Surfaced on the Hunt dashboard (Home) as a "Which channel works" card, inside
the existing per-section permission gating — it is Hunt reporting data and is
gated the same way the dashboard's other Hunt sections already are.

**Known cold start:** the backfill makes every historical touch `whatsapp`, so
the card reads as a single bar until new touches accumulate. That is the data
telling the truth, not a defect.

### Error handling

- Invalid `channel` or `direction` → 422 with the valid values named.
- Handle failing normalization → 422 naming the field. Never stored as null
  silently.
- Cross-platform handle (Instagram URL in the LinkedIn field) → 422.
- Touch on a lead outside the tenant or outside an agent's assignment → the
  existing `guardLead` / `visibleTo` path, unchanged.
- External link opening is best-effort; a blocked popup must not prevent the
  touch from being logged. The log call does not depend on the window opening.
- `huntByChannel` on a shop with no touches returns every channel at zero rather
  than an empty array, so the card renders a stable shape.

### Testing

Run on the droplet (`php8.4`), never locally, against a scratch database — never
the production database.

**Backend**

- Touch endpoint: validates channel and direction; rejects unknown values.
- `direction: out` bumps `last_contacted_at`; `direction: in` leaves it
  unchanged — asserted explicitly, it is the subtlest rule here.
- Neither direction changes `status`.
- Backfill migration sets existing `contacted` rows to whatsapp/out and leaves
  `status_change` rows null.
- `SocialHandle::normalize` across `@name` / bare / URL / trailing-slash /
  query-string forms, per platform, plus cross-platform rejection.
- Facebook import salvage: an Ad Library lead with a `facebook.com` website
  lands in `facebook` with `website` null.
- `PATCH /shop/leads/{lead}` updates contact fields, and sending `status`,
  `assigned_to_id`, or deal fields leaves those columns **unchanged** (the
  request still succeeds for the contact fields — the disallowed keys are simply
  not in the validated set). Asserted directly against the database: this is the
  privilege-escalation surface.
- `reply_channel` on a move to `replied` logs an inbound activity in the same
  transaction; omitting it logs none.
- `huntByChannel`: first-touch attribution; the `unattributed` bucket; recurring
  deal value matches `dealTotal`; zero-fill on an empty shop.
- Agent scoping: an agent's channel report excludes colleagues' leads.
- Tenant isolation: no channel data crosses shops.
- Voice tool: `log_followup` with a channel writes it; without one writes null,
  not whatsapp. Hunt voice search is mocked — a live search spends real credit.

**Frontend**

- Channel buttons render only for channels with a stored handle.
- A lead with an Instagram handle and no mobile still shows a working touch
  action (the regression this feature exists to fix).
- Timeline strings per direction and per channel; null channel falls back to
  "Contacted".
- Reply picker commits with and without a channel.
- Handles editor hidden without `leads.manage`.

## Migration & rollout

Standing flow: local → staging → prod, promoted only when green.

Both migrations are additive — new nullable columns plus a data backfill over
`lead_activities`. No column is dropped or retyped, so the schema change is
backward-compatible with the currently deployed code; the removed `/followup`
route is the only breaking change, and its only callers ship in the same
release.

Deploy order: backend (migrations + API) first, then the admin SPA via
`admin/deploy.ps1`. In the window between the two, the deployed SPA still calls
`/followup`; the route is therefore kept as a thin alias that forwards to the
touch handler with `whatsapp`/`out` **for the duration of the release only**, and
removed in the follow-up commit once the SPA is live. This is called out
explicitly so it is not left behind permanently.

## Open questions

None. Channel list, attribution rule, and the scoped edit endpoint were all
confirmed during design.
