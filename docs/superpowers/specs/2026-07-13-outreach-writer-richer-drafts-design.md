# Outreach Writer — bug-free, richer WhatsApp drafts

Date: 2026-07-13
Status: Approved for planning
Area: Business Hunt lead outreach (`app/Services/Leads/OutreachWriter.php`)

## Problem

The "Personalize" / "Follow-up" buttons on a lead (LeadDetail) call
`OutreachWriter::personalizeForLead($shop, $lead, $kind)`. Two issues:

1. **Follow-up bug (the visible defect).** In follow-up mode the system prompt
   instructs the model to *"take a NEW angle, never repeat the opening"* — but
   no opening message is ever supplied. The model interprets this as missing
   context and, instead of writing, asks the user for the prior opening message
   ("I don't have the context of an initial opening message…"). This meta-text
   is shown verbatim in the review panel and cannot be sent.

2. **Generic tone.** Even when it works, output is a plain 2–4 line blurb.
   Francis wants the warmer, more concrete shape of a hand-written pitch: a
   friendly greeting with an emoji, a "how I found you" hook, and a short
   `✅` feature list, then a soft CTA.

## Constraints

- **Multi-tenant, no hardcoded identity.** Rich content must come from the
  sending shop's own data and the lead's own data — never a baked-in Eloquent /
  Business Hunt pitch. (Standing rule: never bake one shop's identity into a
  default.)
- **No new schema / no persona fields.** Francis chose "driven by existing shop
  data" over adding per-shop persona columns. Use what already exists.
- **No real search credits or live Claude calls in tests.** `ClaudeClient` is
  mocked; tests assert on the constructed system prompt.
- Tests run on the droplet (php8.4), never locally.

## Available data (existing only)

- **Shop:** `name`, `category` (→ `categoryLabel()`), `location`. No bio /
  tagline / about column exists.
- **Catalog items** (`catalogs`): `title`, `description` (already present but
  currently unused by the writer), `price`. This is the richest source for
  concrete `✅` feature bullets.
- **Lead:** `name`, `category` (→ `categoryLabel()`), `area()` (address). These
  supply the honest "I found you" hook — every Hunt lead was found by searching
  a category in an area.

### Accepted gap

The example's candid "I'm a one-man software company — just me" line has no home
in existing data and will **not** be auto-generated. Decision: acceptable —
Francis edits that line manually in WhatsApp when he wants it. Not a driver for
the hybrid/persona-field option.

## Design

All changes are within `OutreachWriter.php` plus its test. No controller,
route, migration, or frontend change.

### 1. Fix the follow-up branch

Replace the phantom-opening framing. The follow-up instruction becomes, in
effect: *"This business was already contacted once and has not replied. Write a
short, fresh nudge that stands on its own — a different benefit, a light proof
point, or a soft question. Do NOT reference, quote, or assume the wording of any
earlier message."* The impossible "never repeat the opening" clause (which
implies knowledge of an opening) is removed. This eliminates the question-asking
failure mode.

### 2. Feed richer data into the prompt

- **"How I found you" hook.** The lead's `categoryLabel()` + `area()` are passed
  as they already are; the rules gain permission to open with a natural
  discovery hook using them (e.g. "Came across you while looking for marketing
  consultants in Abu Dhabi"). No invented specifics.
- **Catalog descriptions.** `shopProfile()` currently pulls only `title` (+
  `price`). Extend it to include each item's `description` when present, so the
  bullets carry real substance:
  `- {title}{ — description}{ (AED price)}`.

### 3. Upgrade format rules

- Opening messages: **default** to a warm one-line greeting (with at most one
  `👋`), an optional `✅` bullet list of **3–5** items drawn from the shop's
  offerings **when the shop has 2+ concrete catalog items**, then a soft CTA.
  Fall back to short prose (2–4 lines) when fewer than 2 offerings exist.
- Follow-up messages stay **short** (1–2 lines), no bullets — a fresh nudge.
- Retain all guardrails: no invented facts/stats/offers, no placeholders like
  `{name}`, address the business by its business name, never ask for a contact
  person, never explain the task, output only the finished message.

## Testing

Extend `tests/Feature/OutreachWriterTest.php` (same fake-`ClaudeClient` pattern
that records `lastSystem`):

1. **Follow-up prompt is self-contained.** For `kind = followup`, the system
   prompt does NOT tell the model about an "opening" or a prior/earlier message
   to avoid repeating (assert the phantom-opening phrasing is absent), and it
   does instruct a fresh standalone nudge.
2. **Lead discovery data reaches the prompt.** Lead `categoryLabel()` and
   `area()` appear in `lastSystem` for a follow-up (regression guard on the hook
   source).
3. **Catalog descriptions reach the prompt.** A shop with a catalog item whose
   `description` is set has that description text present in `lastSystem`.
4. Keep the existing opening test (message returned, lead + "business name" +
   "not needed" guardrails present).

## Out of scope

- No frontend changes; the review-panel UX stays as-is.
- No persona/bio schema fields.
- No change to `personalize` controller/route or the voice `draft_outreach`
  path (separate assistant tool; unaffected by this change).
