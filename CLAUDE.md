# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP business performance dashboard (no framework, no build step) for Journal — tracks sales across Online, Offline (retail), and Consignment channels. Runs on XAMPP; deployed at `C:\xampp\htdocs\all_report` and served via Apache/PHP directly (no router). There is no composer.json, package.json, or test suite — this is plain procedural PHP with inline HTML/CSS/JS per page.

## Running it

Open pages through XAMPP's Apache (e.g. `http://localhost/all_report/index.php`). There is no CLI build/lint/test command — verify changes by loading the page in a browser and checking the rendered output/PHP errors directly. PHP's `sqlsrv` extension must be enabled (all DB access goes through it).

## Architecture

### Pages are independent, not a framework

Each dashboard is a single self-contained PHP file that: starts a session, requires `scripts/helpers.php` (`initPerformanceSettings()` for execution time/memory limits), opens its own SQL Server connection, builds a `$pageTitle`/`$pageSubtitle`/`$accentColor` set of variables, `require`s `includes/header.php`, runs its queries and renders inline HTML/CSS/Chart.js, then `require`s `includes/footer.php` (which closes the connection). There is no shared router, template engine, or autoloader — logic, SQL, and markup all live in the same file per dashboard.

| Page | File | Data status | DB target |
|---|---|---|---|
| Overview | `index.php` | Mock data (`$mockData` array) | connects via `database.php` |
| Online Sales | `dashboard_online.php` | **Real data** — only page wired to production | own inline connection to `production_jst_api` |
| Offline Sales | `dashboard_offline.php` | Mock data | connects via `database.php` |
| Consignment | `dashboard_consignment.php` | Mock data | connects via `database.php` |

`dashboard_online.php` is the reference implementation for what "real" pages should look like — read it before wiring up Offline/Consignment to real data.

### Two separate DB connections exist

- `database.php` — connects to `all_report` database on `203.154.130.236` (server `sa`/`Journal@25`). Used by `index.php`, `dashboard_offline.php`, `dashboard_consignment.php`, and the import scripts.
- `dashboard_online.php` — opens its **own** inline connection (not via `database.php`) to `203.154.130.236,1433`, database `production_jst_api`. This is intentional: Online reads live production sales data (`dbo.OrderSummary` and related views), a different database than the other pages.

Credentials are hardcoded in both places — there is no `.env`/config abstraction. Don't try to unify the connections without checking with the user first; the databases genuinely differ.

### `includes/header.php` is the shared shell

Set these variables before `require`-ing it: `$pageTitle`, `$pageSubtitle`, `$accentColor` (`#1a1a2e` offline-style, `#c9a227` online-style). It derives the active nav item from the current filename against `$navItems`, renders the filter bar, and holds bilingual (`th`/`en`) UI strings in `$headerText` — language is chosen via `?lang=th|en` (default `th`). If you add a new dashboard page, register it in `$navItems` here rather than duplicating nav markup per page.

### Target/KPI logic (Online dashboard)

There is no target table — targets are always derived from a **previous period baseline** (previous month for MTD, previous year for YTD, previous full month for the monthly target), not a real business target. "Today" means "latest date present in `OrderSummary`," not the actual calendar date — if data import lags, the whole dashboard's date window shifts with it. Every KPI section follows the actual/target/variance/achievement pattern; see `SYSTEM_MAP.md` for the full KPI-to-query mapping and known data-quality risks (e.g. product/payment grouping is done via `LIKE` pattern matching on free-text columns, not real categories).

### CSV import scripts

`scripts/import.jst.php` and `scripts/import_ada.php` are standalone upload-and-import endpoints (POST a CSV via `$_FILES['csv_file']`), sharing `scripts/helpers.php` for encoding normalization (`convertToUtf8`, BOM stripping), date parsing (`convertDate`/`convertDateTime`), value cleaning (`cleanNumber`/`cleanString`), and bulk insert helpers. These write into the `all_report` database via `database.php`, separate from the live `production_jst_api` source that `dashboard_online.php` reads.

## Product/design intent

Three docs define *why* the dashboard is shaped the way it is — read them before changing layout, adding KPIs, or introducing new charts:

- **`DASHBOARD.MD`** — core philosophy: Actual vs Target is the default framing for every KPI (actual, target, variance, achievement, trend); summary-before-detail drill-down (Overview → Channel → Analysis → Transaction); charts must answer a specific business question, not just exist; status indicators must never rely on color alone (pair with label/icon/number).
- **`PRODUCT.md`** — audience is executives/managers/operations with different needs (company-wide status vs channel diagnosis vs near-real-time execution signals); brand tone is professional/clean/fast, explicitly not "colorful," "chart-heavy," or "decorative."
- **`SYSTEM_MAP.md`** — current-state map of the Online dashboard only (by design, doesn't cover Offline/Consignment/Overview since those are still mock data). Documents every KPI's query source, the reused tables/views (`dbo.OrderSummary`, `dbo.GetItemSkus`, `dbo.GetWarehouseSkuInventorys`, `dbo.GetAfterSaleOrders`), and a "Known Data Risks" table — check this before trusting a KPI's business meaning.

`SQL_QUERIES.md` documents an earlier/alternate schema (`jst_sale_detail`, `pos_sale_detail`) that does **not** match the tables actually queried in the current code (`dbo.OrderSummary` etc. in `production_jst_api`) — treat it as historical reference, not ground truth; trust the live SQL in `dashboard_online.php` and `SYSTEM_MAP.md` over it.

## Conventions to follow

- New dashboard pages should follow the existing pattern: own connection setup → `$mockData` (until wired to real data) → set header vars → `require includes/header.php` → query/render → `require includes/footer.php`.
- UI strings that need Thai/English support go through the `$headerText`/`$translations` array pattern already used in `header.php` and `dashboard_online.php`, keyed by `$uiLanguage`.
- SQL is written as raw parameterized `sqlsrv_query` calls (see `fetch_one`/`fetch_all` in `database.php` and the `append_filter`/`params_for_periods` helpers in `dashboard_online.php`) — there is no query builder or ORM.
- When adding a KPI, follow the actual/target/variance/achievement/trend shape described in `DASHBOARD.MD`, and update `SYSTEM_MAP.md`'s KPI table if you're changing the Online dashboard's real queries.
