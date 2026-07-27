# Home becomes the Hunt dashboard — design

**Date:** 2026-07-27
**Status:** Approved (Francis delegated the detail: "handle accordingly")

## Problem

Home (`/`) is the Ask assistant and nothing else. The business numbers live on a
separate Overview page (`/hunt-insights`) that has to be navigated to. Francis
wants the numbers on Home.

Home and `/ask` currently render the identical `VoiceAssistant` component;
`Landing.tsx` is an 18-line wrapper whose permission check is dead code, because
`RequirePerm perm="assistant.use"` already ran.

## Decisions taken

1. **Home becomes the dashboard.** The mic becomes a floating button.
   `/hunt-insights` folds into Home.
2. **Gate per section, not per page.** Nobody is bounced off their own landing
   page.
3. **Sidebar keeps Home, drops Overview.**
4. **Bookings-only shops keep today's Home** — the Ask assistant inline. No
   bookings dashboard is being built; Lens is on hold.

## What Home renders

| Condition | Home shows |
|---|---|
| Leads module **and** `leads.view` | The Hunt dashboard |
| No leads module **and** `assistant.use` | The Ask assistant inline, as today |
| Neither | The existing "No access" empty state |

A Hunt shop whose user holds `assistant.use` but not `leads.view` falls through
to the assistant, so an assistant-only user on a Hunt shop still has a working
Home.

## Structure

`HuntInsights.tsx` (a page) becomes `components/HuntDashboard.tsx` (a
component), so Home composes a component rather than importing a page. The page
file is deleted and `/hunt-insights` becomes `<Navigate to="/" replace />` so
existing links and bookmarks keep working.

`Landing.tsx` stops being a dead wrapper and becomes the real composition
described above.

## Permissions

`RequirePerm perm="assistant.use"` comes off `/`. It stays on `/ask` and
`/ask/:conversationId`, which remain assistant-only.

Two guards inside Home:

- **The Hunt AI card stays hidden from lead agents.** This already exists in
  `HuntInsights.tsx` and moves across unchanged — `isLeadAgent()` in
  `lib/nav.ts`. Without it an agent lands on a card guaranteed to 403, because
  `ReportsController::blockLeadAgent()` denies them the shop-wide summary.
- **`firstAccessiblePath()`** (`lib/nav.ts`) treats `/` as requiring
  `assistant.use`. It gets the same either/or rule, or a redirected user skips
  past a Home they can now see.

## Navigation

- `DesktopSidebar.tsx` — drop the Overview entry; Home loses `perm: 'assistant.use'`.
- `MobileLayout.tsx` — the Home tab loses `perm: 'assistant.use'`.

Home stays visible to every authenticated user; what it *renders* is what
varies. That is the point of per-section gating.

## The mic

`VoiceAssistantFab` hides on `/` today. That line goes, so the mic floats over
the new dashboard. It also gains a `can('assistant.use')` check it currently
lacks — without it, a user without the permission gets a button that lands them
on a blocked page.

## Francis's in-flight work, folded in

Two uncommitted changes are groundwork for this and get committed as part of it:

- `HuntAiCard` gained a `span2` prop so it can sit in a narrower column.
- The Credits card was removed from the dashboard.

The second is why `HuntInsights.test.tsx` currently fails: the test
"shows the credit balance" still asserts a card that no longer renders. That
test is dropped along with the card.

## Testing

`HuntInsights.test.tsx` moves to `HuntDashboard.test.tsx` — same assertions,
minus the credit-balance test, retargeted at the component.

New tests for Home's composition:

- A Hunt user with `leads.view` sees the dashboard.
- A bookings-only shop sees the Ask assistant.
- A user with neither sees the "No access" state.
- A lead agent sees the dashboard **without** the AI card.
- A Hunt user with `assistant.use` but no `leads.view` sees the assistant.
- `/hunt-insights` redirects to `/`.
- The FAB renders on `/` with `assistant.use`, and not without it.

## Out of scope

A bookings dashboard for Lens shops. Any change to the assistant itself. The
conversational voice loop, which was explicitly declined.
