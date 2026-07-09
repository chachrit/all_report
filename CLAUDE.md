# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## What this is

A PHP business performance dashboard (no framework, no build step) for Journal — tracks sales across Online, Offline (retail), and Consignment channels. Runs on XAMPP; deployed at `C:\xampp\htdocs\all_report` and served via Apache/PHP directly (no router). There is no composer.json, package.json, or test suite — this is plain procedural PHP with inline HTML/CSS/JS per page.

## วิธีอธิบายตอนแก้โค้ด (สำคัญ อ่านก่อนเริ่มงาน)

โปรเจกต์นี้ทำโดย vibe coding (ให้ AI เขียนโค้ดเป็นหลัก) เจ้าของโปรเจกต์ต้องการ**อ่านโค้ดและแก้ปัญหาเองได้ในอนาคต** แม้วันหนึ่งจะไม่มี AI ช่วยแล้ว ดังนั้นทุกครั้งที่แก้บั๊กหรือเพิ่มฟีเจอร์:

- อธิบาย **สาเหตุที่แท้จริง (root cause)** ว่าเพราะอะไรโค้ดเดิมถึงพัง/ทำงานผิด ไม่ใช่แค่บอกว่า "แก้ไฟล์ไหนบรรทัดไหน"
- ใช้ภาษาไทยธรรมดา กระชับ อ่านง่าย หลีกเลี่ยงศัพท์เทคนิคที่ไม่จำเป็น ถ้าต้องใช้ศัพท์เทคนิค ให้อธิบายสั้นๆว่ามันคืออะไร
- เน้น "ทำไมต้องทำแบบนี้" มากกว่า "ทำอะไรไปบ้าง" — เช่น ถ้าใช้ pattern เดิมที่มีอยู่แล้วในโค้ด ให้บอกว่าเอา pattern มาจากไหน ทำไมเลือกใช้อันนี้
- ถ้าเป็นบั๊กที่เกิดจาก concept ที่ไม่ตรงไปตรงมา (เช่น CSS cascade, JS closure, race condition) ให้อธิบายหลักการนั้นแบบสั้นๆ ประกอบด้วย ไม่ใช่แค่บอกวิธีแก้
- คอมเมนต์ในโค้ดยังคงให้น้อยที่สุดตามหลักเดิม (ดู "Conventions to follow") — คำอธิบายเชิงลึกให้อยู่ในบทสนทนา ไม่ต้องยัดใส่โค้ด

## Running it

Open pages through XAMPP's Apache (e.g. `http://localhost/all_report/index.php`). There is no CLI build/lint/test command — verify changes by loading the page in a browser and checking the rendered output/PHP errors directly. PHP's `sqlsrv` extension must be enabled (all DB access goes through it).

## Architecture

### Pages are independent, not a framework

Each dashboard is a single self-contained PHP file that: starts a session, requires `scripts/helpers.php` (`initPerformanceSettings()` for execution time/memory limits), opens its own SQL Server connection, builds a `$pageTitle`/`$pageSubtitle`/`$accentColor` set of variables, `require`s `includes/header.php`, runs its queries and renders inline HTML/CSS/Chart.js, then `require`s `includes/footer.php` (which closes the connection). There is no shared router, template engine, or autoloader — logic, SQL, and markup all live in the same file per dashboard.

| Page | File | Data status | DB target |
|---|---|---|---|
| Overview | `index.php` | **Partially real** — Online/Offline real (`fact_online_orders`, `FactSales`), Consignment mock | connects via `database.php` (`all_report`) |
| Online Sales | `dashboard_online.php` | **Real data** — reads `fact_online_orders`/`fact_online_order_items` | connects via `database.php` (`all_report`) |
| Offline Sales | `dashboard_offline.php` | Mock data | connects via `database.php` |
| Consignment | `dashboard_consignment.php` | Mock data — no real consignment sales source exists yet | connects via `database.php` |

`dashboard_online.php` is the reference implementation for what "real" pages should look like — read it before wiring up Offline/Consignment to real data. `index.php` still keeps the `$mockData` array shape/name but assembles most of it from real queries now — see `SYSTEM_MAP.md`'s Overview section for exactly which keys are real vs mock.

### All dashboards now share one database

All four pages (`index.php`, `dashboard_online.php`, `dashboard_offline.php`,
`dashboard_consignment.php`) connect to the `all_report` database on
`203.154.130.236` (server `sa`/`Journal@25`) via `database.php`. Credentials are
hardcoded there — there is no `.env`/config abstraction.

`dashboard_online.php` previously opened its own inline connection to a separate
`production_jst_api` database (`dbo.OrderSummary` and related views) for live
production data. Once the ETL team finished loading Online sales into `all_report`
as a proper star schema (`fact_online_orders`/`fact_online_order_items` +
`DimOnlinePlatform`/`DimOnlineProduct`/etc.), the dashboard was migrated onto that —
see `SYSTEM_MAP.md` for the full mapping and known data-quality gaps in the new
source (some old columns, e.g. payment method and per-item discount, aren't
populated by this ETL; a few sections were dropped as a result).

### `includes/header.php` is the shared shell

Set these variables before `require`-ing it: `$pageTitle`, `$pageSubtitle`, `$accentColor`. Each page's `$accentColor` is one Journal brand secondary color, not an arbitrary hex — see `BRAND_COLORS.md` for the full channel→color mapping and why it matters (three of the four pages used to run on invented/Tailwind-default colors with no connection to the actual brand book, until a UI/color audit caught it). It derives the active nav item from the current filename against `$navItems`, renders the filter bar, and holds bilingual (`th`/`en`) UI strings in `$headerText` — language is chosen via `?lang=th|en` (default `th`). If you add a new dashboard page, register it in `$navItems` here rather than duplicating nav markup per page.

### Target/KPI logic (Online dashboard)

There is no target table — targets are always derived from a **previous period baseline** (previous month for MTD, previous year for YTD, previous full month for the monthly target), not a real business target. "Today" means "latest date present in `fact_online_orders`," not the actual calendar date — if the ETL lags, the whole dashboard's date window shifts with it. Every KPI section follows the actual/target/variance/achievement pattern; see `SYSTEM_MAP.md` for the full KPI-to-query mapping and known data-quality risks (e.g. product grouping is done via `LIKE` pattern matching on free-text columns, not real categories; several old columns like payment method and per-item discount aren't populated by this ETL at all).

### Section Manager (role-based show/hide/reorder, all 4 pages)

Every visual block on `index.php`/`dashboard_online.php`/`dashboard_offline.php`/`dashboard_consignment.php` is wrapped in `<div class="dash-section" data-section-id="..." data-section-label-th="..." data-section-label-en="...">` — a thin non-visual wrapper around the *existing* markup, added without touching what's inside it. `assets/section-manager.js` reads these wrappers on page load and can reorder them (via `appendChild`, not CSS — the page isn't a flex/grid at that level) and toggle `.hidden` per section, driven by:

- A **role quick-select** dropdown in the header (`includes/header.php`, `#roleQuickSelect`) — picking CEO/Manager/Operation applies a preset instantly (`SectionManager.applyRole(role)`).
- A **"Manage Sections" panel** (`includes/section_manager_panel.php`) for manual drag-reorder/checkbox-hide, overriding any preset.
- Presets and section lists live in one static object, `SECTION_PRESETS` in `assets/section-manager.js`, keyed by page filename. There is no real login/role system — "CEO/Manager/Operation" is just a client-side preset name, not a permission.
- Choices persist in `localStorage`, keyed `sectionPrefs:<page>.php`, **per browser** (not per real user — no backend for this).

**เหตุผลที่ทำแบบนี้ (สำคัญถ้าจะแก้):**
- **ทำไมไม่ใช้ CSS `order`/flex ในการจัดเรียง?** เพราะ section ทั้งหมดเป็น `<div>` ลูกของ `.container` ซึ่งเป็น block ธรรมดา ไม่ใช่ flex/grid — การจัดเรียงจริง (`appendChild`) เลยปลอดภัยกว่า ไม่ต้องเปลี่ยน layout parent ที่มีผลกับ element อื่นที่ไม่เกี่ยวข้อง
- **ทำไม localStorage ไม่ใช่ database?** เพราะไม่มีระบบ login จริงในโปรเจกต์นี้ การเก็บใน browser คือทางเลือกที่ถูกที่สุดตอนนี้ — ถ้าจะทำ role จริงในอนาคต ต้องมี auth ก่อน แล้วย้าย logic นี้ไปเก็บฝั่ง server ต่อ user
- **กับดักที่เคยเกิดขึ้นจริง**: ถ้าจะเพิ่ม/ลบ section ใหม่ ต้องอัปเดต `SECTION_PRESETS[page].sections` (รายชื่อทั้งหมด) *และ* `roles.*` (ใครเห็นอะไร) ให้ตรงกับ `data-section-id` ที่มีอยู่จริงในไฟล์ .php นั้น — ถ้าไม่ตรง `prefsMatchCurrentSections()` จะลบ cache เก่าทิ้งอัตโนมัติ (กัน section ใหม่โดนซ่อน/โผล่มาแบบสุ่ม) แต่ role ที่ user เคยเลือกไว้ก็จะรีเซ็ตไปด้วย
- **กับดัก CSS ที่เคยพังจริง**: ห้ามเขียน CSS rule ที่ตั้ง `display` ตรงๆบน `.dash-section` (เช่น `.dash-section { display: block; }`) เพราะ CSS ของหน้าเว็บ (author stylesheet) จะชนะกฎ built-in ของ browser ที่ทำให้ attribute `hidden` ทำงาน (`[hidden] { display: none }`) เสมอ ไม่ว่า specificity จะเป็นยังไง — ทำให้ปุ่มซ่อน section ไม่ทำงานเลยแบบไม่มี error ให้เห็น (เกิดขึ้นมาแล้วจริงตอนเพิ่ม margin ระหว่าง section)
- **ไม่มี cache-busting อัตโนมัติ**: `includes/footer.php` โหลด `assets/section-manager.js` พร้อม `?v=<filemtime>` — ถ้าแก้ไฟล์นี้แล้ว browser ยังเห็นพฤติกรรมเก่า ให้เช็คว่า query string เปลี่ยนตาม timestamp ไฟล์จริงหรือไม่ ก่อนสงสัยว่าโค้ดผิด

### CSV import scripts

`scripts/import.jst.php` and `scripts/import_ada.php` are standalone upload-and-import endpoints (POST a CSV via `$_FILES['csv_file']`), sharing `scripts/helpers.php` for encoding normalization (`convertToUtf8`, BOM stripping), date parsing (`convertDate`/`convertDateTime`), value cleaning (`cleanNumber`/`cleanString`), and bulk insert helpers. These write into `jst_sale_detail`/`pos_sale_detail` in the `all_report` database — legacy/unused tables, currently empty, unrelated to the `fact_online_orders`/`fact_online_order_items` tables `dashboard_online.php` actually reads (those are populated by a separate ETL process, not these scripts).

## Product/design intent

Three docs define *why* the dashboard is shaped the way it is — read them before changing layout, adding KPIs, introducing new charts, or touching any color:

- **`PRODUCT.md`** — audience is executives/managers/operations with different needs (company-wide status vs channel diagnosis vs near-real-time execution signals); brand tone is professional/clean/fast, explicitly not "colorful," "chart-heavy," or "decorative."
- **`SYSTEM_MAP.md`** — current-state map of the dashboards wired to real data: `dashboard_online.php` and the Online/Offline portions of `index.php` (Consignment/`dashboard_offline.php`/`dashboard_consignment.php` are covered by their own inline code comments instead). Documents every KPI's query source, the reused tables (`fact_online_orders`, `fact_online_order_items`, `DimOnlineProduct`, `FactSales`, `DimBranch`, `DimProduct`), and a "Known Data Risks" table — check this before trusting a KPI's business meaning.
- **`BRAND_COLORS.md`** — every color used across the 4 dashboards, sourced from Journal's actual brand book, with the channel→color mapping and the one validated chart-categorical palette. Read this before hardcoding any hex — three pages used to run on invented/Tailwind-default colors with zero connection to the brand book until a color audit caught it.

(A `DASHBOARD.MD` covering the Actual-vs-Target/drill-down philosophy and a `SQL_QUERIES.md` covering an older schema were referenced here previously but no longer exist in the repo — if you need that context, check git history.)

## Conventions to follow

- New dashboard pages should follow the existing pattern: own connection setup → `$mockData` (until wired to real data) → set header vars → `require includes/header.php` → query/render → `require includes/footer.php`.
- UI strings that need Thai/English support go through the `$headerText`/`$translations` array pattern already used in `header.php` and `dashboard_online.php`, keyed by `$uiLanguage`.
- SQL is written as raw parameterized `sqlsrv_query` calls (see `fetch_one`/`fetch_all` and the `append_filter` helper, duplicated per-file in `dashboard_online.php`/`dashboard_offline.php`/`index.php` — `index.php` also duplicates `RETAIL_BRANCH_SQL`/`SELLABLE_PRODUCT_SQL`/`zone_case_sql()` from `dashboard_offline.php` and the `item_agg` CTE pattern from `dashboard_online.php`) — there is no query builder or ORM.
- When adding a KPI, follow the actual/target/variance/achievement/trend shape, and update `SYSTEM_MAP.md`'s KPI table if you're changing the Online dashboard's real queries.
