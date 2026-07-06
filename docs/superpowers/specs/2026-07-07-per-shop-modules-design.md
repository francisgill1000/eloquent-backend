# Per-Shop Modules — master-controlled B2C / B2B feature gating

**Date:** 2026-07-07
**Status:** Approved design, ready for planning

## Problem

The admin app ships two products in one codebase that serve *opposite* users:

- **Bookings (B2C)** — a service business receiving customers: Bookings, Services, Staff, Working Hours, plus the AI assistant (Home) and Overview dashboard.
- **Business Hunt / Leads (B2B)** — a seller prospecting for other businesses: the Lead Finder + pipeline.

Showing both to every tenant confuses the user (a salon does not prospect; an agency does not take bookings). We want one codebase to present a purpose-built app per tenant, decided by the **master** account per client. Pricing is unchanged — this is purely *which modules a client can see*, not billing.

## Goal

A per-shop list of enabled modules, controlled from master, that gates the nav (desktop rail + mobile tabs + the Settings list), the client-side routes, and the corresponding API endpoints — with no change to the existing per-shop pricing/subscription structure.

## Data model

A JSON array column on `shops`:

```
modules  =  ['bookings']            // most shops (default)
            ['bookings', 'leads']   // shop with both enabled
            ['leads']               // pure B2B tenant
```

- **Column:** `modules` JSON, non-null.
- **Cast:** `'modules' => 'array'` in `Shop` (`app/Models/Shop.php`). It serialises to the shop payload automatically (`$guarded = []`, not in `$hidden`), so `shop.modules` reaches the frontend via the existing shop context with no serializer change.
- **Backfill migration:** set every existing shop to `['bookings']`. Business Hunt then disappears for all tenants until master turns it on per client. (Confirmed: all current shops are booking businesses.)
- **Default for new shops:** `['bookings']` (set in the `creating` hook alongside `shop_code`/`pin`, or as a column default).

Array (not a single `'both'` enum) is deliberate: adding a third module later is one more string and one more nav tag — no `'all'`/combination explosion.

## Module → menu map

| Menu item | Visible when shop has… | Surfaces |
|---|---|---|
| Home, Overview | **always** (both modules) | desktop rail, mobile tab |
| Bookings | `bookings` | desktop rail, mobile tab |
| Services, Staff, Working Hours | `bookings` | desktop rail; mobile = links in Settings page |
| Business Hunt (Leads) | `leads` | desktop rail; mobile = link in Settings page |
| Settings, Profile | **always** (both modules) | desktop rail, mobile tab |

Home + Overview stay in **both** modules deliberately: every shop then always has a valid landing page (Home/Overview), which removes any need for mode-dependent landing routing. The only thing that varies per shop is which *other* items appear.

## Enforcement — four surfaces

Hiding a menu is cosmetic. Each layer below is independent; the API layer is the real gate.

### 1. Backend API (the real gate)
Add a route middleware `module:leads` (and `module:bookings`) that 403s when the authed shop lacks the module. Apply it to the existing Leads route group in `routes/api.php:172` (the `/shop/leads/*` and `/shop/lead-messages/*` routes). A `Shop::hasModule(string $m): bool` helper backs both the middleware and any controller checks.

> Bookings-endpoint gating (`module:bookings`) is only needed the day a `['leads']`-only tenant exists. Ship the middleware generically; apply it to booking routes in the same change so the pure-B2B case is safe.

### 2. Client-side route guard
A `<ModuleGuard module="leads">` wrapper around the `/leads` route subtree in the app's react-router table; redirects to `/` (Home) when the module is absent. Same wrapper reused for booking routes under `module="bookings"`.

### 3. Desktop rail — `admin/src/layout/DesktopSidebar.tsx`
Tag each `BASE_NAV` item with `modules: Module[]` and extend the existing `.filter(...)` (which already handles `WHATSAPP_ENABLED` and `is_master`) with a module-intersection check. Master's single "All Businesses" item is untouched.

### 4. Mobile — `admin/src/layout/MobileLayout.tsx` + `admin/src/pages/Settings.tsx`
- `ALL_TABS` (Home, Overview, Bookings, Settings, Profile): tag Bookings with `['bookings']`; filter the same way. Home/Overview/Settings/Profile always show.
- Business Hunt and Services/Staff/Working Hours are **links inside the Settings page**, not tabs — gate those links in `Settings.tsx` by module.

### Shared helper
A single `admin/src/lib/modules.ts` exporting the `Module` type, `shopHasModule(shop, m)`, and `navVisible(item, shop)` so desktop, mobile, Settings, and the route guard all agree. No per-component logic drift.

## Master control — `admin/src/pages/MasterShopDetail.tsx`

Two checkboxes on the shop detail screen — **Bookings** / **Business Hunt** — bound to `shop.modules`. Saving PATCHes the shop through the existing master shop-update endpoint (add `modules` to its validated/fillable fields). No new pricing UI.

## Out of scope

- Separate pricing/trial/credits per module — pricing stays per-shop as today. (Revisit only if modules become separately billed; that would promote `modules` to a `shop_products` pivot. Not now.)
- Any change to the AI assistant's tool set per module.
- Master bulk-editing modules across many shops at once.

## Testing

- **Backend:** `hasModule()` unit; middleware returns 403 without module, passes with it; backfill migration sets `['bookings']`; master update persists `modules`. (Run on the droplet php8.4 against a scratch DB — never prod.)
- **Frontend:** `navVisible` unit table (each menu × each module combo); DesktopSidebar/MobileLayout render the right items for `['bookings']`, `['leads']`, `['bookings','leads']`; ModuleGuard redirects; MasterShopDetail toggles persist (extend `MasterShopDetail.test.tsx`).

## Rollout

Local → staging → prod (standing rule). Deploy backend + backfill, verify a `['bookings']` shop no longer shows Business Hunt on staging, enable `leads` on one staging shop to confirm it reappears, then promote.
