# AI summary card — animated mint border

**Date:** 2026-07-14
**Scope:** Pure CSS. One selector, one file. No TSX, no dependencies.

## Goal

Give the AI summary card a continuously spinning mint gradient border with a soft
outer glow, adapted from the reference demo at `Eloquent/border-animation` but
recolored to the Business Lens dark/mint theme.

## Target

`.c-aisummary .ais-card` only — the unified hero card on the AI Summary page
(`admin/src/pages/AiSummary.tsx`, styled in `admin/src/styles/insights.css`).
It is a single responsive element (mic panel + summary column) used on both
desktop and mobile, so one rule covers every view. It is the only place the AI
summary card renders. No other card is affected.

## Why not copy the reference verbatim

The reference spins a `conic-gradient` **behind** the card and relies on the
card's **opaque** background to mask the center, leaving only a border. `.ais-card`
has a **translucent** mint-gradient background, so a behind-the-card gradient
would bleed across the whole card face instead of reading as a border.

## Technique — masked ring

- `@property --ais-angle` (`<angle>`, initial `0deg`, non-inheriting) drives a
  `conic-gradient` that rotates via a `spin` keyframe.
- `::before` = crisp gradient ring: `inset: 0`, `border-radius: inherit`,
  `padding: 1.5px`, `box-sizing: border-box`, masked with
  `mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0)` +
  `mask-composite: exclude` so only the border ring shows. `pointer-events: none`.
- `::after` = same ring at `padding: 2px`, `filter: blur(9px)`, `opacity: 0.55`
  for the soft outer glow. `.ais-card` already has `overflow: visible`, so the
  halo bleeds outward.
- Card gets `position: relative; isolation: isolate`; both pseudos sit at
  `z-index: -1` (above the card's own background per stacking paint order, below
  in-flow content). Explicit `box-sizing: border-box` on the pseudos (the global
  `*` reset does not reach pseudo-elements).

## Colors

Mint spectrum from the theme tokens:
`#00ffcc → #6ff5cf → #00d4aa → #00b894 → #00ffcc`.

## Motion

- ~5s linear rotation (calmer than the reference's 3s, since it is always-on on a
  hero card).
- `@media (prefers-reduced-motion: reduce)` freezes the animation → static mint
  ring (still intentional), matching the existing guard in this stylesheet.

## Graceful degradation

Where `@property` or `mask-composite` is unsupported, the existing
`border: 1px solid var(--border-mint)` remains as the fallback border (it is kept,
not removed) and the ring simply renders static or not at all.

## Out of scope

No component changes, no theme-token changes, no application to any other card.
