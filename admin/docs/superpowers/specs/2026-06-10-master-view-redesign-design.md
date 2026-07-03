# Master view redesign + per-shop persona + active/inactive toggle

**Date:** 2026-06-10
**Status:** Approved design, pending implementation plan

## Problem

The admin "All Businesses" master view (`/master`) renders every business as a dense
card with the ID/PIN credentials and a row of metadata crammed inline. Francis dislikes
this display style and wants it to follow the cleaner card → detail pattern used in the
customer app (`eloquent-bookings`).

Two capabilities are also missing from the master view:

1. **Per-shop persona** — each business's WhatsApp assistant currently replies with a
   generic, category-derived prompt. Francis wants each shop to have its own editable
   persona (custom system prompt).
2. **Active/inactive toggle** — a way to hide a business from the customer-facing shop
   list without deleting it.

## Goals

- Redesign the master list to use the `eloquent-bookings` horizontal card layout, adapted
  for the master context.
- Add a per-business **detail screen** holding credentials, activity, the persona editor,
  and the visibility toggle.
- Make persona **per-shop** end-to-end (storage → API → live WhatsApp bot), with the bot
  change strictly **additive** so live traffic is unaffected until a persona is set.
- Let the master flip a shop between `active` / `inactive`, which controls whether it
  appears in the customer app.

## Non-goals

- No change to the global sales-number persona (`bot_prompts` / `MasterPrompts` page).
  That remains a separate concern for the sales/test number.
- No persona **preset picker** per shop — a single free-text textarea per shop (Francis
  chose option 1). Empty persona = fall back to the default category-based prompt.
- No login-as / impersonation, no editing of shop profile fields from the master detail
  screen beyond persona + visibility.

## Affected repositories

| Repo | Path | Changes |
|------|------|---------|
| admin (master UI) | `Booking Manager/admin` | New card component, new detail page, route, lib calls, CSS |
| backend (Laravel API) | `Booking Manager/backend` | Migration, master update endpoint, payload additions |
| whatsapp-autoreply (Node bot, **live**) | `Solutions/whatsapp-autoreply` | Additive: use persona when present |

---

## A. List redesign — `admin`

### `MasterShopCard` (new component)

Adopts the `eloquent-bookings` `ShopCard` horizontal layout: square thumbnail on the left,
body on the right. `MasterShop` has **no logo field**, so the thumbnail is a **monogram
avatar** — the business's first initial on a mint tile (same visual recipe as the project
favicons).

Body content:
- **Top row:** WhatsApp status badge (`● Connected` mint / `Not set up` grey) and a
  `master` tag when `is_master`. When `status !== 'active'`, also show a small `Inactive`
  badge so hidden shops are visible at a glance.
- **Name** (bold).
- **Meta line:** `category · phone`.
- **Foot line:** `N bookings · Joined <short date>`.

The **entire card is a button** → `navigate('/master/' + shop.id, { state: { shop } })`.
No ID/PIN/copy on the card anymore.

### `MasterShops.tsx` (list page) — refactor

Unchanged: page header (title + bot-prompts icon + logout), the "Add business" inline form,
the created-shop success card, the search box, loading/empty states, the `is_master`
redirect guard. The only change is swapping the inline credential card markup for
`<MasterShopCard shop={s} onOpen={...} />`.

---

## B. Detail screen — `/master/:id` (`admin`)

New page `MasterShopDetail.tsx`, modeled on `eloquent-bookings` `ShopDetail`'s visual
structure (appbar with Back, hero, sectioned vertical scroll).

**Data source:** read the shop from router navigation state when arriving from the list;
on direct load / refresh, fall back to `getMasterShops()` and `.find(id)`. No single-shop
master GET endpoint is added.

Sections, top to bottom:

1. **App bar** — Back button (← Back).
2. **Hero** — monogram tile + business name + `category · location` + WhatsApp status.
3. **Credentials** — large `ID <code>` / `PIN <pin>` pill + copy button. Below it a
   **"Send login to owner"** action that opens `https://wa.me/<digits(phone)>` with a
   prefilled message containing the business ID and PIN. Helper text: "Send these login
   details to the owner." Hidden/disabled if the shop has no phone.
4. **Visibility** — an Active / Inactive toggle.
   - Active ⇒ appears in the customer app.
   - Inactive ⇒ "Hidden from the customer app" — calls the master update endpoint with
     `status`. Optimistic UI with revert on failure.
5. **Persona** — a `<textarea>` for this shop's WhatsApp assistant persona plus a **Save**
   button. Empty value = "Using the default persona (based on the business category)."
   Helper text explains it controls how the bot replies on **this business's own WhatsApp
   number**. Save calls the master update endpoint with `persona` (empty string is sent as
   `null` to clear). Disabled while saving; shows saved confirmation.
6. **Activity** — bookings count, joined date, last login.

### Frontend lib additions (`src/lib/shops.ts`)

- `getMasterShops()` already returns the list; extend `MasterShop` type with
  `persona?: string | null` (and `status` is already present).
- New `updateMasterShop(id, payload: { status?: 'active' | 'inactive'; persona?: string | null })`
  → `PATCH /master/shops/{id}`, returns the updated `MasterShop`.

### Routing (`src/App.tsx`)

Add `<Route path="/master/:id" element={<MasterShopDetail />} />` inside the existing
`RequireShop` block (full-screen, not in the tabbed `MobileLayout`).

### CSS (`src/styles/customer.css`)

Port the `eloquent-bookings` `.c-shop-card` family (adapted; monogram thumb instead of image)
and add detail-screen styles (hero, credentials block, toggle, persona editor). admin
shares the same mint token palette (`tokens.css`), so colours carry over directly.

---

## C. Backend — `Booking Manager/backend` (Laravel)

1. **Migration** — add nullable `persona` (`text`) column to `shops`. `null` = use the
   auto-generated category prompt. (`status` string column already exists with
   `Shop::ACTIVE` / `Shop::INACTIVE`.)

2. **`MasterController::shops()`** — include `'persona' => $shop->persona` in the mapped
   payload. (`status` is already returned.)

3. **New endpoint** `PATCH /master/shops/{shop}` → `MasterController::updateShop()`,
   master-guarded via the existing `requireMaster($request)` helper. Validates and applies:
   - `status` — `in:active,inactive` (optional)
   - `persona` — `nullable|string` (optional); empty string stored as `null`
   Returns the updated shop in the same shape as the `shops()` list entry.
   Registered inside the existing `auth:sanctum` master group in `routes/api.php`.

4. **`WaWebhookController::shopContext()`** — add `'persona' => $shop?->persona` to the
   JSON response (alongside the existing `shop_name`, `category`, `phone_number_id`,
   `token`). Null when unset.

No change to `ShopController::index()` — it already filters `where('status', Shop::ACTIVE)`,
so flipping `status` to `inactive` hides a shop from the customer app automatically.

---

## D. Node bot — `whatsapp-autoreply` (LIVE — additive only)

The tenant-number branch of `POST /wa/webhook` currently does:

```js
activePrompt = buildProviderPrompt(shopAccount.shopName, shopAccount.category);
```

Change so the per-shop persona is used **only when present**:

```js
activePrompt = (shopAccount.persona && shopAccount.persona.trim())
  ? shopAccount.persona
  : buildProviderPrompt(shopAccount.shopName, shopAccount.category);
```

`resolveShopAccount()` (which calls Laravel `shop-context`) carries the new `persona`
field through into `shopAccount`. `offerTools` stays `false` for tenant numbers.

**Safety guarantee:** with no persona set on any shop (the state of every shop today),
`shopContext` returns `persona: null`, the condition is false, and the bot calls
`buildProviderPrompt` exactly as it does now. Zero behavior change for live traffic until
Francis deliberately sets a persona on a shop. The sales-number branch is untouched.

---

## Data flow summary

```
Master sets persona / toggles status
  → admin MasterShopDetail
  → PATCH /master/shops/{id}  (Laravel, master-guarded)
  → shops.persona / shops.status updated

Customer opens app
  → GET /shops  → only status='active' shops returned  (existing behavior)

Customer messages a business's WhatsApp number
  → Meta webhook → Node bot /wa/webhook
  → resolveShopAccount → Laravel GET /wa/shop-context  (now returns persona)
  → persona present ? use it : buildProviderPrompt(...)   (additive)
  → Claude reply
```

## Error handling

- **Detail data missing** (direct nav, shop not found after refetch): show a "Business not
  found" state with a Back button (mirrors `eloquent-bookings` ShopDetail).
- **Update failures** (status toggle / persona save): surface an inline error; revert the
  optimistic UI state for the toggle.
- **No phone** for "Send login to owner": hide/disable that action.
- **Bot:** persona lookup failures already degrade gracefully to the existing
  category-prompt path; the additive change preserves that.

## Testing

- **admin:** component tests for `MasterShopCard` (badges: connected/not, inactive,
  master) and `MasterShopDetail` (renders creds, persona save calls `updateMasterShop`,
  toggle calls it with `status`, not-found state). Follow the existing Vitest +
  Testing-Library patterns in `src/pages/*.test.tsx`.
- **backend:** feature test for `PATCH /master/shops/{id}` — master-guarded (403 for
  non-master), updates `status` and `persona`, empty persona clears to null; and that
  `shops()` / `shopContext()` include `persona`.
- **bot:** unit-level assertion that persona, when present, is chosen over
  `buildProviderPrompt`, and that a null/empty persona falls back unchanged.

## Rollout order

1. Backend migration + endpoint + payload additions (deploy via git pull on droplet).
2. admin frontend (redesign + detail + persona editor + toggle).
3. Node bot additive change — deploy when ready; safe to deploy at any point since it is
   backward compatible.
