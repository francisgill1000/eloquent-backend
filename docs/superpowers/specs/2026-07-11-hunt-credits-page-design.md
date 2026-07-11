# Dedicated Business Hunt Credits Page

**Date:** 2026-07-11
**Status:** Approved, ready to implement

## Problem

Business Hunt credit top-up currently lives as a cramped inline card block under
the search box on the Business Hunt page ([Leads.tsx](../../../admin/src/pages/Leads.tsx)).
It looks poor and is off-theme. Francis wants a dedicated, well-designed credits
page that matches the Hunt (dark constellation / mint) theme — and it must never
appear for a bookings-only shop.

## Solution

A dedicated `/leads/credits` page, reached from the Business Hunt page. No new
sidebar item. Shared checkout logic extracted into a hook.

### Routing & tenant gating

- New route `/leads/credits` placed **inside the existing
  `<ModuleGuard module="leads">` block** in [App.tsx](../../../admin/src/App.tsx).
- Same gate as Business Hunt itself → a bookings-only shop cannot reach it.
- **No sidebar nav item added**, so nothing new appears in a bookings shop's nav.
- The only links to the page are the balance chip / "Buy credits" link on the
  Business Hunt page, which is itself leads-gated.

This is the complete answer to "must not show for bookings app."

### Architecture — `useHuntCredits()` hook

Extract the credit + Ziina checkout logic (~100 lines currently inside
`FindPane` in Leads.tsx) into `admin/src/hooks/useHuntCredits.ts` so the search
page's balance chip and the new page share one source of truth.

Hook owns: balance load (`getLeadCredits`), `canPurchase`, `embeddedCheckout`,
`packs`, `buyingId`, `buyMsg`, `buyPack(pack)`, `checkoutUrl` + `setCheckoutUrl`,
the `?pay=...` return handler, the Ziina `postMessage` listener, and `refresh()`.

Returns: `{ balance, packs, canPurchase, buyingId, buyMsg, buyPack, checkoutUrl,
setCheckoutUrl, refresh }`.

### New page — `admin/src/pages/LeadCredits.tsx`

Renders inside the same AppShell (inherits the constellation/dark background and
`--mint` tokens). Reuses existing `lf-*` styles plus a new `lfc-*` block.

Sections:
1. Back link → Business Hunt; header "Hunt Credits".
2. **Balance hero card** — large current balance, mint accent, caption
   "1 credit = 1 new live search".
3. **How credits work** — short explainer (new searches cost 1; repeat searches
   are always free).
4. **Pack grid** — proper cards (not the cramped strip): credits, AED price,
   hover/selected states. Self-serve shops buy via Ziina (`buyPack`); non
   self-serve shops get the WhatsApp "Message us to top up" fallback (unchanged
   logic). "Secure checkout via Ziina" note.
5. Ziina checkout iframe overlay (moved here from Leads.tsx).

### Business Hunt page changes ([Leads.tsx](../../../admin/src/pages/Leads.tsx))

- `FindPane` consumes `useHuntCredits()` instead of its own credit state.
- Balance chip keeps the "Buy credits" link, but it now `navigate('/leads/credits')`
  rather than expanding the inline block.
- Remove the inline `lf-limit` pack block and the `lf-checkout` overlay.
- A blocked (out-of-credits) search shows a compact inline notice that links to
  `/leads/credits`, instead of expanding packs inline.

## Testing

- `LeadCredits.test.tsx`: renders packs for a self-serve shop; clicking a pack
  starts checkout; a non-purchase shop shows the WhatsApp fallback instead of
  buy buttons.
- Route gating already covered by the existing `ModuleGuard` test.
- Typecheck + build + existing suite must stay green.

## Out of scope (YAGNI)

Purchase history, receipts, credit-usage analytics.
