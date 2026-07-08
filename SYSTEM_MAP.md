# SYSTEM_MAP.md

Current-state map of the dashboards wired to real data: `dashboard_online.php` and
`index.php` (Overview — Online/Offline portions only). `dashboard_offline.php` is
covered by its own inline comments (`RETAIL_BRANCH_SQL`, `SELLABLE_PRODUCT_SQL`,
`zone_case_sql()`). `dashboard_consignment.php` and Overview's Consignment figures
are still mock — there is no consignment sales data yet (`FactSales` excludes
consignment/warehouse branches by design).

## Data source

`dashboard_online.php` connects via `database.php` to the `all_report` database
(same connection the Offline/Consignment/Overview pages use), reading:

- `fact_online_orders` — order header: one row per order (`order_id`, `platform`,
  `order_datetime`, `order_status`, `subtotal`, `discount_amount`, `total_amount`,
  `net_revenue`, `commission_amount`).
- `fact_online_order_items` — line items: `order_key` (FK to `fact_online_orders`),
  `product_key`, `sku_code`, `product_name`, `quantity`, `unit_price`,
  `discount_per_item`, `line_total`, `cost_price`, `profit`.
- `DimOnlineProduct` — joined via `product_key` for a more complete `sku_code` (see
  Known Data Risks below).

This replaced a prior live connection to a separate `production_jst_api` database
(`dbo.OrderSummary` + related views) once the ETL team finished loading Online sales
into `all_report` as this star schema.

## KPI-to-query mapping

| Section | Source | Notes |
|---|---|---|
| Hero (MTD Net Sales), Orders/Units/AOV/UPT KPI cards | `fact_online_orders` + `fact_online_order_items` (joined via a per-order item aggregate) | Revenue = `SUM(line_total)`, validated to exactly equal `total_amount` per order |
| Daily Net Sales chart | same join, grouped by day | |
| Platform Sales Share / Platform Performance table | same join, grouped by `platform` | |
| Product Mix chart | `fact_online_order_items` joined to `fact_online_orders` for date/platform filtering, grouped by the existing LIKE-pattern category heuristic (`product_group_case()`) applied to `product_name` | Unchanged from the old heuristic — not `DimOnlineProduct.category_name`, which encodes sellable-vs-not (`FINISH GOOD`/`NOT FOR SALE`/etc.), not marketing categories |
| Top Products table | `fact_online_order_items` grouped by `product_key`/`product_name`, left-joined to `DimOnlineProduct` for `sku_code` | Disc % column removed (see Known Data Risks); product thumbnails always fall back to the placeholder icon (no image source in this ETL) |
| Order Status chart / Exception Orders card | `fact_online_orders.order_status` | Statuses actually present: `Delivered`, `Cancelled`, `WaitConfirm`, `Delivering`, `WaitPay`, `WaitOuterDeliver`, `Question` — no `Return` status in this data |
| Cancel-Rate Watch, Platform Attention List | same order+item join, latest complete day vs 30-day/4-week baseline, grouped by `platform` | |
| Discount Anomaly table | `fact_online_orders.discount_amount`/`subtotal` only (order header, not item-level) | See Known Data Risks — item-level discount data is unusable |
| Month-End Projection | same order+item join, weekday-weighted average of the trailing 8 weeks | |

## Removed sections (no equivalent data in `all_report`)

- **Payment Mix chart** — `payment_method`, `payment_status`, `shipping_method` are
  100% blank across all 378K+ rows of `fact_online_orders`. Not a naming mismatch;
  the ETL simply doesn't populate this.
- **Stock Coverage table** — no warehouse/inventory table exists anywhere in
  `all_report` for online SKUs.
- **After-Sales Queue card** — no return/refund/case-tracking table exists.

## Known Data Risks

| Risk | Detail |
|---|---|
| `discount_per_item` is unusable | Always `0` across every sampled row of `fact_online_order_items`. Any per-line discount % or "Disc %" column has been dropped rather than shown as a misleading `0.0%`. |
| `subtotal`/`unit_price` don't reconcile | `SUM(unit_price * quantity)` per order does not match `o.subtotal`, and neither matches `o.total_amount`. Likely explanation: `unit_price` reflects catalog/pre-livestream pricing, not the actual transaction price (many orders are livestream flash-sale items). `SUM(line_total)` per order, however, was verified to exactly equal `o.total_amount` across every sampled order — so revenue figures are reliable; gross/discount-rate figures (Discount Anomaly, the discounted/no_discount/high_discount campaign filter) rely on order-header `subtotal`/`discount_amount` as the least-bad available source, and are order-grain, not line-grain — a filtered order's discount % describes the *whole order*, not just the matching category slice. |
| No `itemType` (Normal/Combined/Gift) column anywhere | The `sales_type` filter's Gift option and the default "exclude gift/free lines from unit counts" behavior now use `unit_price = 0` as a proxy. This lines up with the old system's own property that gift/sample lines always had zero net revenue, but it's a different underlying signal. "Combined" has no equivalent and was dropped from the filter. |
| `sku_code` is only ~38% filled on `fact_online_order_items`, ~92% via `DimOnlineProduct.sku_code` (joined on `product_key`) | Top Products displays `COALESCE(DimOnlineProduct.sku_code, item.sku_code)`; blank is possible when both are empty. |
| `items_count` on `fact_online_orders` is always `0` | Stale/unpopulated column — never used; item counts are always computed via a join to `fact_online_order_items`. |
| Real order volume starts December 2025 | April–November 2025 is a ramp-up period (hundreds to low-thousands of orders/month, peaking ~฿2.6M) vs ~฿20-29M/month from December 2025 on. `RELIABLE_DATA_FROM = '2025-12-01'` in `dashboard_online.php` gates which growth-baseline comparisons are shown (YoY only once the shifted window lands entirely after this date). |

## `index.php` (Overview)

Company-wide dashboard combining all three channels. Online and Offline are real
(reusing the same table/column mapping as their own dashboards, duplicated inline
per this repo's established per-file pattern — `RETAIL_BRANCH_SQL`,
`SELLABLE_PRODUCT_SQL`, `zone_case_sql()`, and the Online `item_agg` CTE pattern are
all copied verbatim rather than shared). Consignment stays entirely mock (hardcoded
King Power/Sephora branch figures, flat `฿18.5M`/`฿20M` totals/targets) — there is no
consignment sales source yet.

| Section | Source | Notes |
|---|---|---|
| Goal/Target card | `annual`/`monthlyTarget`/`projected`/`onTrack` are fixed business-target placeholders (no target table exists anywhere in this codebase); `currentYear`/`currentMonth` are real Online + Offline actual, plus the Consignment mock estimate | `currentYear` prorates the Consignment mock by elapsed months in the year (flat run-rate, not real) |
| Total Sales / Monthly Targets / Growth | Real current-month vs previous-month actual for Online/Offline (same "baseline as target" pattern as their own dashboards); Consignment unchanged mock | `orders`/`unitsSold` totals exclude Consignment (no real order-count source), matching the pre-existing mock's own scope |
| Regional Sales | Offline (retail branches) only, via `zone_case_sql()` | Online/Consignment have no geographic attribution — not fabricated |
| Platforms table / donut | Real `platform` values from `fact_online_orders`: `shopee`, `line_shopping`, `own_website` | Replaces the old mock's 4 fake platforms (Shopee/TikTok/Website/Lazada) — TikTok/Lazada don't exist in this data |
| Branches table | Real Offline branches (`FactSales`/`DimBranch`) + the two mock Consignment branches appended | Real branches don't carry a `lat`/`lng`/`region`/per-platform `channels` breakdown (those were unused/fictional in the old mock — verified not referenced anywhere in the rendered markup) |
| Top Online Products / Top Offline Products | Two separate real lists — `fact_online_order_items`/`DimOnlineProduct` for Online, `FactSales`/`DimProduct` for Offline | Not merged: `DimProduct.ProductCode` and `DimOnlineProduct.sku_code` only overlap on ~204 of ~1,379 coded online SKUs |
| Monthly Trend chart | Real last-12-months for Online (by platform) and Offline; Consignment is a flat run-rate (average of the old mock's 12 monthly values) | X-axis anchors to whichever of Online/Offline has the more recent month, so a lagging source shows trailing zeros rather than truncating the other |

**Dead code, left untouched (pre-existing, not introduced by this work):**
- The "Executive Alerts" card (hardcoded Shopee/TikTok/Lazada messages) is wrapped
  entirely in an HTML comment — never rendered.
- The entire `filterByChannel`/`resetChannelFilter` cross-filter JS block (~270
  lines) is never invoked — no markup anywhere has `onclick="filterByChannel(...)"`.
  `resetDashboardData()` also references a `#top-products` element that doesn't
  exist in the markup, so it would throw if ever called. Left as dead code rather
  than fixed/removed — flag to the team if this feature was actually intended to
  ship.
