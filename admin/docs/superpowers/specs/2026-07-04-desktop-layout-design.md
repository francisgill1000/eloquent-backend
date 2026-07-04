# Desktop layout for the admin app

**Date:** 2026-07-04
**Status:** Approved (design)

## Problem

The admin app is a mobile-first single-column layout (`.m-screen` / `.m-scroll`
content + bottom `.m-tabbar`). It looks great on phone and tablet. After we
removed the `max-width` cap, on laptops/large screens the single column stretches
edge-to-edge — cards become too wide, the tab bar spans the whole screen, and
there is a sea of empty space. Most users are expected on laptops/big screens.

Goal: a genuine desktop experience **without changing** the mobile/tablet design.

## Decisions

- **True desktop layout** (sidebar + multi-column), not just a centered column.
- **Breakpoint: ≥1024px** activates desktop. Below 1024px is byte-for-byte
  unchanged (phones, portrait + landscape tablets keep bottom tabs).
- **First cut scope:** the desktop *shell* for every authenticated screen, plus a
  full multi-column **Dashboard**. Other screens sit centered/readable in the
  shell but keep single-column content (richer layouts come later).
- **Sidebar style:** frosted **glass** (translucent white + `backdrop-filter:
  blur`, hairline right border) matching existing glass surfaces (`.c-chat-row`,
  `.c-set-link`) so the ambient ParticleField shows through.

## Architecture

Additive layer, ~90% CSS + one component + one layout wrapper. Mobile/tablet CSS
paths are never touched, so they cannot regress.

1. **`AppShell` layout** (`src/layout/AppShell.tsx`) wraps ALL authenticated
   routes (both the tabbed routes under `MobileLayout` and the drill-down
   full-screen routes). Renders a persistent `DesktopSidebar` + `<Outlet/>`.
   - ≥1024px: flex row — sidebar (fixed width) + scrollable content region.
   - <1024px: passthrough (sidebar `display:none`); existing layout renders as-is.
   - Must preserve the `height:100%` chain so `.m-screen` keeps filling height.

2. **`DesktopSidebar`** (`src/layout/DesktopSidebar.tsx`), glass panel:
   - Header: shop logo/avatar + name + Open/Closed status.
   - Nav (uses existing `Icons`, mint active state): Home, Bookings, Services,
     Staff, Working Hours, Settings, Profile. Master accounts also get
     "All Businesses." WhatsApp "Chats" stays gated by `WHATSAPP_ENABLED`.
   - Footer: Log out (pinned bottom).
   - Only rendered/visible at ≥1024px.

3. **Bottom tab bar** hidden at ≥1024px (`.m-tabbar { display:none }` in the
   desktop media query). Unchanged below.

4. **Content width:** at ≥1024px, single-column screens are centered at a
   readable max-width so un-redesigned screens stop stretching. The Dashboard
   opts out to use full width.

5. **Dashboard desktop grid** (`src/pages/Dashboard.tsx` + CSS): the same mint
   cards rearranged into a two-column grid at ≥1024px — revenue + stat tiles on
   top, Quick Actions beside the Upcoming Bookings list. Collapses to the current
   single stack below 1024px (grid rules live behind the media query, so mobile
   markup/behaviour is untouched).

- **New CSS** lives in `src/styles/desktop.css` (imported after the others in
  `main.tsx`), keeping all desktop rules in one place, all behind
  `@media (min-width: 1024px)`.

## Files

- New: `src/layout/AppShell.tsx`, `src/layout/DesktopSidebar.tsx`,
  `src/styles/desktop.css`
- Edit: `src/App.tsx` (wrap authenticated routes in `AppShell`),
  `src/main.tsx` (import desktop.css), `src/pages/Dashboard.tsx` (grid wrappers)

## Risk & testing

- Primary risk: the inserted wrapper breaking the `height:100%` chain that
  `.m-screen` relies on. Mitigation: `.app-shell` / `.app-shell-main` carry
  `height:100%` so the chain holds on both mobile and desktop; verify by driving
  the real app at <1024px (unchanged) and ≥1024px (sidebar + dashboard grid).
- Because every desktop rule is behind `@media (min-width:1024px)` and new
  classes, mobile/tablet render paths are physically unaffected.

## Out of scope (later)

- Bespoke multi-column layouts for Bookings, Settings, Services, Staff, etc.
- Collapsible/hamburger sidebar, keyboard nav polish.
