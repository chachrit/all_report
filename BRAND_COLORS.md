# BRAND_COLORS.md

Source of truth for every color used across the 4 dashboards. Before this file
existed, each page's accent was hand-picked independently (`#c9a227`, `#1a1a2e`,
`#8b5cf6` — none of them Journal brand colors; the last one is literally
Tailwind's default `violet-500`). That drift is why this file exists — so it
doesn't happen again page by page.

Brand source: `theme&color/Journal Brandguide 2024.jpg` / `-3.jpg` (ask whoever
has the brand book folder if these move) — a Thai-Tone-inspired palette with a
white/black primary set and 8 secondary hue families.

## Channel → brand color

Each page's `$accentColor` is one brand secondary family, chosen for maximum
visual separation between all 4 pages at a glance:

| Page | Channel | Brand color | Pantone | Hex |
|---|---|---|---|---|
| `index.php` | Overview | Blue (ครามฝรั่ง) | 2175C | `#2f4e9d` |
| `dashboard_online.php` | Online | Gold (รงทอง) | 116C | `#dab937` |
| `dashboard_offline.php` | Offline | Teal (ขนคอหางนกยูง) | 7711C | `#4f8b98` |
| `dashboard_consignment.php` | Consignment | Purple (ม่วงเข็มมะปราง) | 2612C | `#62307a` |

Use the page's own `$accentColor` (or CSS `var(--accent)`, set from it in
`includes/header.php`) for that page's own chrome — buttons, borders, hero
accents, single-series chart bars. When a chart on ANY page needs to show a
specific *channel's* data (e.g. the Overview page's channel-mix donut, or the
"Online"/"Offline" bars in a stacked chart), use that channel's color from the
table above, not the current page's own accent — e.g. `index.php`'s channel
donut shows Online in gold even though the page's own accent is blue.

Shared dark neutral for hero-style cards (`.online-hero`, `.offline-hero`,
`.annual-goal-left`): flat `#091113` (brand black, "ดำเขม่า" Black 6C) — no
gradients, no radial glow, no noise texture. `PRODUCT.md` explicitly bans
"dashboard-cliche gradient hero metrics" — a previous version of these hero
cards violated that directly (dark diagonal gradient + two glow blobs + a
masked dot-grid texture). Differentiate each hero by its `border-left: 4px
solid var(--accent)`, not by re-hueing the background.

## Chart palette (multi-series / categorical only)

**Do not paste brand hex directly into a multi-series chart.** Run
`node scripts/validate_palette.js "<hexes>" --mode light` from the `dataviz`
skill folder before shipping any categorical palette — the raw brand secondary
tones fail the colorblind-safety checks (gold reads too light, emerald/teal/
olive read too gray/desaturated to tell apart). This is the one already
validated and in use — same fixed order everywhere, never reordered per chart:

```
['#4b74d8', '#8e792a', '#09899e', '#9b59bc', '#12933f', '#c55123', '#bf497e']
```
(blue, gold, teal, purple, emerald, orange, pink — each a lightness/chroma-
adjusted version of its brand family, snapped into the validator's passing
band while holding the original hue angle)

Used by: `dashboard_online.php` / `dashboard_offline.php`'s `chartColors`
constant (platform share, product mix, order status charts), and
`dashboard_consignment.php`'s `$colors` PHP array (Partner Contribution donut
— both the chart's `backgroundColor` and its legend swatches read from this
one array so they can't drift apart).

If a chart needs an 8th color, add `'#678813'` (olive) — that completes the
validated 8-hue set; going past 8 categories means the chart should fold extra
categories into "Other" rather than adding a 9th generated hue (see the
`dataviz` skill's anti-patterns reference).

## Single-series charts

A chart with exactly one series needs no legend and no CVD-separation check —
just use that page's own `$accentColor` (or the specific channel's color, per
the table above, if the series represents a channel rather than the current
page). Example: `dashboard_online.php`'s daily-sales bar chart uses `#dab937`
directly, not a slot from the categorical array.

## Status colors (unrelated to brand — do not change)

`#10B981`/`#EF4444` (positive/negative growth), used everywhere paired with an
arrow + number, never color alone. These are semantic, not brand identity —
they intentionally stay as-is regardless of which channel's page they appear
on.

## Known follow-ups (not done in this pass)

- `dashboard_consignment.php`'s Monthly Trend chart is dual-axis (revenue bars
  + orders line, two y-scales) — flagged by the `dataviz` skill as the single
  most common chart mistake. Colors were fixed in place; the chart shape
  itself wasn't restructured in this pass.
- Generic dark tooltip chrome (info-tip boxes, Chart.js tooltip backgrounds)
  intentionally kept as plain near-black/gray — not a brand-identity signal,
  no need to chase brand hex into every dark UI surface.
