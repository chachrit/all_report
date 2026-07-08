<?php
session_start();

// Load helpers
require_once __DIR__ . '/scripts/helpers.php';
initPerformanceSettings();

// Database connection
require_once __DIR__ . '/database.php';
if (!$conn) {
    die("Connection failed: " . print_r(sqlsrv_errors(), true));
}

function fetch_one($conn, string $sql, array $params = []): array
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new RuntimeException(print_r(sqlsrv_errors(), true));
    }
    $row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC) ?: [];
    sqlsrv_free_stmt($stmt);
    return $row;
}

function fetch_all($conn, string $sql, array $params = []): array
{
    $stmt = sqlsrv_query($conn, $sql, $params);
    if ($stmt === false) {
        throw new RuntimeException(print_r(sqlsrv_errors(), true));
    }
    $rows = [];
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $rows[] = $row;
    }
    sqlsrv_free_stmt($stmt);
    return $rows;
}

function n($value): float
{
    return is_numeric($value) ? (float) $value : 0.0;
}

function pct_change(float $current, float $previous): float
{
    return $previous == 0.0 ? 0.0 : (($current - $previous) / $previous) * 100.0;
}

function request_value(string $key, string $default, array $allowed): string
{
    $value = isset($_GET[$key]) ? (string) $_GET[$key] : $default;
    return in_array($value, $allowed, true) ? $value : $default;
}

// includes/header.php always renders a Date Range dropdown (today/mtd/ytd) with its
// own defaults, regardless of whether the page wires it up — this reads the actual
// selection so the KPI cards/tables below (and the dropdown's own "selected" state)
// respect it, instead of always showing a hardcoded current month.
$filterValues = [
    'date_range' => request_value('date_range', 'mtd', ['today', 'mtd', 'ytd']),
];

/**
 * Retail branch scope, copied verbatim from dashboard_offline.php: FactSales only
 * ever carries rows for physical "Journal <mall>" POS branches (warehouses/
 * consignment/HQ/online never post sales lines here), except "Journal Online" which
 * belongs on the Online dashboard instead.
 */
const RETAIL_BRANCH_SQL = "b.BranchName LIKE 'Journal %' AND b.BranchName <> 'Journal Online'";
/** DimProduct.ProductGroup separates real merchandise from gift/sample/packaging lines. */
const SELLABLE_PRODUCT_SQL = "p.ProductGroup = 'FINISH GOOD'";

/**
 * Region grouping is inferred from branch name (no region column exists on DimBranch),
 * copied verbatim from dashboard_offline.php. Heuristic, not a verified operational
 * hierarchy — treat as a rough view only.
 */
function zone_case_sql(string $branchCol = 'b.BranchName'): string
{
    return "CASE
            WHEN {$branchCol} LIKE '%Chiangmai%' OR {$branchCol} LIKE '%Chiang Mai%' OR {$branchCol} LIKE '%MAYA%' OR {$branchCol} LIKE '%Nimman%' THEN 'North'
            WHEN {$branchCol} LIKE '%Phuket%' OR {$branchCol} LIKE '%Hatyai%' THEN 'South'
            WHEN {$branchCol} LIKE '%Pattaya%' OR {$branchCol} LIKE '%Chonburi%' THEN 'East'
            WHEN {$branchCol} LIKE '%Udon%' OR {$branchCol} LIKE '%Khonkaen%' THEN 'Northeast'
            ELSE 'Bangkok & Metro'
        END";
}

/** Growth-baseline reliability cutoff for Online data, same constant as dashboard_online.php. */
const ONLINE_RELIABLE_DATA_FROM = '2025-12-01';

// ---------------------------------------------------------------------------
// Real Online aggregates (fact_online_orders / fact_online_order_items)
// ---------------------------------------------------------------------------

$onlineDateInfo = fetch_one($conn, "
    SELECT CAST(MAX(order_datetime) AS date) AS maxDate
    FROM fact_online_orders
");
$onlineMaxDate = $onlineDateInfo['maxDate'] ?? new DateTime();
$onlineMtdStart = new DateTime($onlineMaxDate->format('Y-m-01'));
$onlineMtdEnd = (clone $onlineMtdStart)->modify('+1 month');
$onlinePrevMonthStart = (clone $onlineMtdStart)->modify('-1 month');
$onlineYearStart = new DateTime($onlineMaxDate->format('Y-01-01'));

/**
 * The KPI cards/platform/branch/top-product sections below all use this
 * date_range-driven window (today/mtd/ytd), same three options as the Online/Offline
 * dashboards. Trend charts and the Annual Goal card intentionally stay unaffected —
 * see their own comments below.
 */
if ($filterValues['date_range'] === 'today') {
    $onlinePeriodStart = clone $onlineMaxDate;
    $onlinePeriodEnd = (clone $onlineMaxDate)->modify('+1 day');
    $onlineTargetStart = (clone $onlinePeriodStart)->modify('-1 day');
    $onlineTargetEnd = (clone $onlinePeriodEnd)->modify('-1 day');
} elseif ($filterValues['date_range'] === 'ytd') {
    $onlinePeriodStart = clone $onlineYearStart;
    $onlinePeriodEnd = clone $onlineMtdEnd;
    $onlineTargetStart = (clone $onlineYearStart)->modify('-1 year');
    $onlineTargetEnd = (clone $onlineMtdEnd)->modify('-1 year');
} else {
    $onlinePeriodStart = clone $onlineMtdStart;
    $onlinePeriodEnd = clone $onlineMtdEnd;
    $onlineTargetStart = clone $onlinePrevMonthStart;
    $onlineTargetEnd = clone $onlineMtdStart;
}

/**
 * Item-grain aggregate joined 1:1 per order (order_key is unique per row here), so
 * joining it into fact_online_orders never fans out header fields. line_total sums
 * reliably to total_amount (verified against dashboard_online.php); unit_price = 0
 * excludes gift/free lines from unit counts, matching the same default behavior used
 * on the Online dashboard (see SYSTEM_MAP.md Known Data Risks).
 */
const ONLINE_ITEM_AGG_CTE = "item_agg AS (
        SELECT i.order_key, SUM(i.quantity) AS units, SUM(i.line_total) AS netSales
        FROM fact_online_order_items i
        WHERE i.unit_price <> 0
        GROUP BY i.order_key
    )";
const ONLINE_VALID_SALES_SQL = " AND o.order_status NOT IN ('Cancelled', 'Return')";

$onlineSummaryRows = fetch_all($conn, "
    WITH " . ONLINE_ITEM_AGG_CTE . "
    SELECT 'current' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(ia.units) AS units, SUM(ia.netSales) AS netSales
    FROM fact_online_orders o JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "

    UNION ALL

    SELECT 'prev' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(ia.units) AS units, SUM(ia.netSales) AS netSales
    FROM fact_online_orders o JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "

    UNION ALL

    SELECT 'ytd' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(ia.units) AS units, SUM(ia.netSales) AS netSales
    FROM fact_online_orders o JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "

    UNION ALL

    SELECT 'month' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(ia.units) AS units, SUM(ia.netSales) AS netSales
    FROM fact_online_orders o JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "

    UNION ALL

    SELECT 'prev_month' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(ia.units) AS units, SUM(ia.netSales) AS netSales
    FROM fact_online_orders o JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . ";
", [
    $onlinePeriodStart->format('Y-m-d'), $onlinePeriodEnd->format('Y-m-d'),
    $onlineTargetStart->format('Y-m-d'), $onlineTargetEnd->format('Y-m-d'),
    $onlineYearStart->format('Y-m-d'), $onlineMtdEnd->format('Y-m-d'), // 'ytd' branch always true YTD, feeds goal.currentYear regardless of the filter
    $onlineMtdStart->format('Y-m-d'), $onlineMtdEnd->format('Y-m-d'), // 'month'/'prev_month' always true calendar month, feeds Monthly Target Progress regardless of the filter
    $onlinePrevMonthStart->format('Y-m-d'), $onlineMtdStart->format('Y-m-d'),
]);
$onlineSummary = ['current' => [], 'prev' => [], 'ytd' => [], 'month' => [], 'prev_month' => []];
foreach ($onlineSummaryRows as $row) {
    $onlineSummary[$row['period']] = $row;
}
$onlineNetSales = n($onlineSummary['current']['netSales'] ?? 0);
$onlinePrevNetSales = n($onlineSummary['prev']['netSales'] ?? 0);
$onlineYtdNetSales = n($onlineSummary['ytd']['netSales'] ?? 0);
$onlineMonthActual = n($onlineSummary['month']['netSales'] ?? 0);
$onlineMonthPrevActual = n($onlineSummary['prev_month']['netSales'] ?? 0);
$onlineOrders = (int) n($onlineSummary['current']['orders'] ?? 0);
$onlineUnits = n($onlineSummary['current']['units'] ?? 0);
/**
 * YTD's baseline (same window last year) lands before ONLINE_RELIABLE_DATA_FROM,
 * where real volume is near-zero (see dashboard_online.php's own baseline-reliability
 * gate) — a % against that reads as noise (e.g. +3,900,000%). Suppress rather than
 * show a misleading number.
 */
$onlineBaselineReliable = $onlineTargetStart->format('Y-m-d') >= ONLINE_RELIABLE_DATA_FROM;
$onlineGrowth = $onlineBaselineReliable ? pct_change($onlineNetSales, $onlinePrevNetSales) : 0.0;

$onlinePlatformCurrentRows = fetch_all($conn, "
    WITH " . ONLINE_ITEM_AGG_CTE . "
    SELECT o.platform, COUNT(DISTINCT o.order_key) AS orders, SUM(ia.units) AS units, SUM(ia.netSales) AS netSales
    FROM fact_online_orders o JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
    GROUP BY o.platform
    ORDER BY netSales DESC;
", [$onlinePeriodStart->format('Y-m-d'), $onlinePeriodEnd->format('Y-m-d')]);
$onlinePlatformPrevRows = fetch_all($conn, "
    WITH " . ONLINE_ITEM_AGG_CTE . "
    SELECT o.platform, SUM(ia.netSales) AS netSales
    FROM fact_online_orders o JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
    GROUP BY o.platform;
", [$onlineTargetStart->format('Y-m-d'), $onlineTargetEnd->format('Y-m-d')]);
$onlinePlatformPrevByName = [];
foreach ($onlinePlatformPrevRows as $row) {
    $onlinePlatformPrevByName[$row['platform']] = n($row['netSales']);
}
$onlinePlatforms = [];
foreach ($onlinePlatformCurrentRows as $row) {
    $prevNet = $onlinePlatformPrevByName[$row['platform']] ?? 0.0;
    $onlinePlatforms[] = [
        'name' => (string) $row['platform'],
        'channel' => (string) $row['platform'],
        'sales' => n($row['netSales']),
        'orders' => (int) n($row['orders']),
        'units' => n($row['units']),
        'growth' => $onlineBaselineReliable ? round(pct_change(n($row['netSales']), $prevNet), 1) : 0.0,
    ];
}

$onlineTopProductRows = fetch_all($conn, "
    WITH top5 AS (
        SELECT TOP 5 i.product_key, i.product_name AS productName,
            SUM(i.quantity) AS units, SUM(i.line_total) AS netSales
        FROM fact_online_orders o
        JOIN fact_online_order_items i ON i.order_key = o.order_key
        WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . " AND i.unit_price <> 0
        GROUP BY i.product_key, i.product_name
        ORDER BY netSales DESC
    ),
    item_sku AS (
        SELECT product_key, MAX(NULLIF(sku_code, '')) AS itemSku
        FROM fact_online_order_items
        GROUP BY product_key
    )
    SELECT t.product_key, t.productName, t.units, t.netSales,
        COALESCE(NULLIF(p.sku_code, ''), s.itemSku) AS sku
    FROM top5 t
    LEFT JOIN DimOnlineProduct p ON p.product_key = t.product_key
    LEFT JOIN item_sku s ON s.product_key = t.product_key
    ORDER BY t.netSales DESC;
", [$onlinePeriodStart->format('Y-m-d'), $onlinePeriodEnd->format('Y-m-d')]);

$onlineTopProductKeys = array_column($onlineTopProductRows, 'product_key');
$onlineTopProductChannels = [];
if (!empty($onlineTopProductKeys)) {
    $placeholders = implode(',', array_fill(0, count($onlineTopProductKeys), '?'));
    $channelRows = fetch_all($conn, "
        SELECT i.product_key, o.platform, SUM(i.line_total) AS netSales
        FROM fact_online_orders o
        JOIN fact_online_order_items i ON i.order_key = o.order_key
        WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
          AND i.unit_price <> 0 AND i.product_key IN ({$placeholders})
        GROUP BY i.product_key, o.platform;
    ", array_merge([$onlinePeriodStart->format('Y-m-d'), $onlinePeriodEnd->format('Y-m-d')], $onlineTopProductKeys));
    foreach ($channelRows as $row) {
        $onlineTopProductChannels[$row['product_key']][$row['platform']] = n($row['netSales']);
    }
}

// Last 12 real months, by platform (for the Monthly Trend chart's channel breakdown)
$trendMonthsBack = 11;
$onlineTrendStart = (clone $onlineMtdStart)->modify("-{$trendMonthsBack} months");
$onlineTrendRows = fetch_all($conn, "
    WITH " . ONLINE_ITEM_AGG_CTE . "
    SELECT FORMAT(o.order_datetime, 'yyyy-MM') AS ym, o.platform, SUM(ia.netSales) AS netSales
    FROM fact_online_orders o JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
    GROUP BY FORMAT(o.order_datetime, 'yyyy-MM'), o.platform;
", [$onlineTrendStart->format('Y-m-d'), $onlineMtdEnd->format('Y-m-d')]);
$onlineTrendByMonth = [];
foreach ($onlineTrendRows as $row) {
    $onlineTrendByMonth[$row['ym']][$row['platform']] = n($row['netSales']);
}

// ---------------------------------------------------------------------------
// Real Offline aggregates (FactSales / DimBranch / DimProduct)
// ---------------------------------------------------------------------------

$offlineDateInfo = fetch_one($conn, "
    SELECT MAX(f.DateKey) AS maxDateKey
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey
    WHERE " . RETAIL_BRANCH_SQL . "
");
$offlineMaxDateKeyInt = (int) ($offlineDateInfo['maxDateKey'] ?? (int) date('Ymd'));
$offlineMaxDate = DateTime::createFromFormat('Ymd', (string) $offlineMaxDateKeyInt) ?: new DateTime();
$offlineMtdStart = new DateTime($offlineMaxDate->format('Y-m-01'));
$offlineMtdEnd = (clone $offlineMtdStart)->modify('+1 month');
$offlinePrevMonthStart = (clone $offlineMtdStart)->modify('-1 month');
$offlineYearStart = new DateTime($offlineMaxDate->format('Y-01-01'));
$offlineMtdStartKey = (int) $offlineMtdStart->format('Ymd');
$offlineMtdEndKey = (int) $offlineMtdEnd->format('Ymd');
$offlinePrevMonthStartKey = (int) $offlinePrevMonthStart->format('Ymd');
$offlineYearStartKey = (int) $offlineYearStart->format('Ymd');

// Same date_range-driven window as Online, expressed as DateKey ints (FactSales' key).
if ($filterValues['date_range'] === 'today') {
    $offlinePeriodStartKey = $offlineMaxDateKeyInt;
    $offlinePeriodEndKey = (int) (clone $offlineMaxDate)->modify('+1 day')->format('Ymd');
    $offlineTargetStartKey = (int) (clone $offlineMaxDate)->modify('-1 day')->format('Ymd');
    $offlineTargetEndKey = $offlineMaxDateKeyInt;
} elseif ($filterValues['date_range'] === 'ytd') {
    $offlinePeriodStartKey = $offlineYearStartKey;
    $offlinePeriodEndKey = $offlineMtdEndKey;
    $offlineTargetStartKey = (int) (clone $offlineYearStart)->modify('-1 year')->format('Ymd');
    $offlineTargetEndKey = (int) (clone $offlineMtdEnd)->modify('-1 year')->format('Ymd');
} else {
    $offlinePeriodStartKey = $offlineMtdStartKey;
    $offlinePeriodEndKey = $offlineMtdEndKey;
    $offlineTargetStartKey = $offlinePrevMonthStartKey;
    $offlineTargetEndKey = $offlineMtdStartKey;
}

$offlineSummaryRows = fetch_all($conn, "
    SELECT 'current' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$offlinePeriodStartKey} AND f.DateKey < {$offlinePeriodEndKey} AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "

    UNION ALL

    SELECT 'prev' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$offlineTargetStartKey} AND f.DateKey < {$offlineTargetEndKey} AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "

    UNION ALL

    SELECT 'ytd' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$offlineYearStartKey} AND f.DateKey < {$offlineMtdEndKey} AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "

    UNION ALL

    SELECT 'month' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$offlineMtdStartKey} AND f.DateKey < {$offlineMtdEndKey} AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "

    UNION ALL

    SELECT 'prev_month' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$offlinePrevMonthStartKey} AND f.DateKey < {$offlineMtdStartKey} AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . ";
");
$offlineSummary = ['current' => [], 'prev' => [], 'ytd' => [], 'month' => [], 'prev_month' => []];
foreach ($offlineSummaryRows as $row) {
    $offlineSummary[$row['period']] = $row;
}
$offlineNetSales = n($offlineSummary['current']['netSales'] ?? 0);
$offlinePrevNetSales = n($offlineSummary['prev']['netSales'] ?? 0);
$offlineYtdNetSales = n($offlineSummary['ytd']['netSales'] ?? 0);
$offlineMonthActual = n($offlineSummary['month']['netSales'] ?? 0);
$offlineMonthPrevActual = n($offlineSummary['prev_month']['netSales'] ?? 0);
$offlineOrders = (int) n($offlineSummary['current']['orders'] ?? 0);
$offlineUnits = n($offlineSummary['current']['units'] ?? 0);
$offlineGrowth = pct_change($offlineNetSales, $offlinePrevNetSales);

$offlineBranchRows = fetch_all($conn, "
    SELECT b.BranchName, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$offlinePeriodStartKey} AND f.DateKey < {$offlinePeriodEndKey} AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
    GROUP BY b.BranchName
    ORDER BY netSales DESC;
");

$offlineZoneRows = fetch_all($conn, "
    SELECT " . zone_case_sql() . " AS zone, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$offlinePeriodStartKey} AND f.DateKey < {$offlinePeriodEndKey} AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
    GROUP BY " . zone_case_sql() . ";
");

$offlineTopProductRows = fetch_all($conn, "
    SELECT TOP 5 p.ProductCode, p.ProductName, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$offlinePeriodStartKey} AND f.DateKey < {$offlinePeriodEndKey} AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
    GROUP BY p.ProductCode, p.ProductName
    ORDER BY netSales DESC;
");

$offlineTrendStartKey = (int) (clone $offlineMtdStart)->modify("-{$trendMonthsBack} months")->format('Ymd');
$offlineTrendRows = fetch_all($conn, "
    SELECT LEFT(CAST(f.DateKey AS varchar(8)), 6) AS ym, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$offlineTrendStartKey} AND f.DateKey < {$offlineMtdEndKey} AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
    GROUP BY LEFT(CAST(f.DateKey AS varchar(8)), 6);
");
$offlineTrendByMonth = [];
foreach ($offlineTrendRows as $row) {
    // DateKey-derived 'yyyyMM' -> 'yyyy-MM' to match the Online trend's key format
    $ym = substr((string) $row['ym'], 0, 4) . '-' . substr((string) $row['ym'], 4, 2);
    $offlineTrendByMonth[$ym] = n($row['netSales']);
}

// Shared 12-month axis: the later of the two channels' latest month, so a lagging
// source shows trailing zeros/nulls rather than silently truncating the other's data.
$trendAnchor = max($onlineMtdStart, $offlineMtdStart);
$trendMonths = [];
for ($i = $trendMonthsBack; $i >= 0; $i--) {
    $trendMonths[] = (clone $trendAnchor)->modify("-{$i} months");
}

// Consignment has no real sales data yet (FactSales excludes consignment/warehouse
// branches by design — see RETAIL_BRANCH_SQL comment). These figures stay mock
// placeholders until that data exists.
$consignmentTotalSalesMock = 18500000;
$consignmentMonthlyTargetMock = 20000000;
$consignmentBranchesMock = [
    ['name' => 'King Power', 'sales' => 12000000, 'orders' => 950, 'aov' => 12631, 'type' => 'consignment'],
    ['name' => 'Sephora', 'sales' => 8500000, 'orders' => 680, 'aov' => 12500, 'type' => 'consignment'],
];

// Annual Goal card is always true current-month/current-year, independent of the
// page's date_range filter — it's a fixed business-tracking widget, not a "view".
$goalMonthsElapsed = (int) $trendAnchor->format('n');
$goalCurrentMonth = $onlineMonthActual + $offlineMonthActual + $consignmentTotalSalesMock;
$goalCurrentYear = $onlineYtdNetSales + $offlineYtdNetSales + ($consignmentTotalSalesMock * $goalMonthsElapsed);

/**
 * Consignment has no real per-period figure, so its contribution to the filter-driven
 * KPI cards/donut is scaled to roughly match whichever window is selected (using the
 * same flat monthly mock as the base rate) — otherwise a flat monthly number would
 * either overstate "today" or understate "this year" while Online/Offline correctly
 * scale with the filter.
 */
$consignmentPeriodEstimate = match ($filterValues['date_range']) {
    'today' => round($consignmentTotalSalesMock / 30),
    'ytd' => $consignmentTotalSalesMock * $goalMonthsElapsed,
    default => $consignmentTotalSalesMock, // mtd
};

$ordersTotal = $onlineOrders + $offlineOrders; // consignment order count unavailable, excluded (same as before)
$unitsSoldTotal = $onlineUnits + $offlineUnits;

$regionalSales = [];
foreach ($offlineZoneRows as $row) {
    $regionalSales[(string) $row['zone']] = n($row['netSales']);
}

$offlineBranches = [];
foreach ($offlineBranchRows as $row) {
    $sales = n($row['netSales']);
    $orders = (int) n($row['orders']);
    $offlineBranches[] = [
        'name' => (string) $row['BranchName'],
        'sales' => $sales,
        'orders' => $orders,
        'aov' => $orders > 0 ? round($sales / $orders) : 0,
        'type' => 'offline',
    ];
}

$topOnlineProductsReal = [];
foreach ($onlineTopProductRows as $row) {
    $channels = $onlineTopProductChannels[$row['product_key']] ?? [];
    $sales = n($row['netSales']);
    $topOnlineProductsReal[] = [
        'name' => (string) $row['productName'],
        'sku' => $row['sku'] ?? '',
        'sales' => $sales,
        'unit' => n($row['units']),
        'contribution' => $onlineNetSales > 0 ? round($sales / $onlineNetSales * 100) : 0,
        'channels' => array_map('n', $channels),
    ];
}

$topOfflineProductsReal = [];
foreach ($offlineTopProductRows as $row) {
    $sales = n($row['netSales']);
    $topOfflineProductsReal[] = [
        'name' => (string) $row['ProductName'],
        'sku' => (string) $row['ProductCode'],
        'sales' => $sales,
        'unit' => n($row['units']),
        'contribution' => $offlineNetSales > 0 ? round($sales / $offlineNetSales * 100) : 0,
    ];
}

// Consignment has no real monthly history — carry forward a flat run-rate (average
// of the old mock's 12 monthly values) rather than fabricating month-specific numbers.
$consignmentMonthlyFlat = round((15000000 + 16000000 + 17000000 + 18000000 + 18500000 + 19000000 + 19500000 + 18000000 + 17500000 + 17000000 + 18000000 + 20000000) / 12);

$monthlyTrend = [];
foreach ($trendMonths as $monthDate) {
    $ym = $monthDate->format('Y-m');
    $onlineChannelsForMonth = $onlineTrendByMonth[$ym] ?? [];
    $monthlyTrend[] = [
        'month' => $monthDate->format('M'),
        'online' => array_sum($onlineChannelsForMonth),
        'offline' => $offlineTrendByMonth[$ym] ?? 0.0,
        'consignment' => $consignmentMonthlyFlat,
        'channels' => array_map('n', $onlineChannelsForMonth),
    ];
}

// Overview data: Online/Offline are real (all_report), Consignment stays mock —
// there is no consignment sales data yet (see comment above).
$mockData = [
    'goal' => [
        'annual' => 1000000000, // business target placeholder — no target table exists (same as Online/Offline dashboards)
        'currentYear' => $goalCurrentYear,
        'monthlyTarget' => 83333333, // business target placeholder
        'currentMonth' => $goalCurrentMonth,
        'projected' => 1040000000, // business target placeholder
        'onTrack' => true
    ],
    'totalSales' => [
        'online' => $onlineNetSales,
        'offline' => $offlineNetSales,
        'consignment' => $consignmentPeriodEstimate,
        'total' => $onlineNetSales + $offlineNetSales + $consignmentPeriodEstimate
    ],
    // Always true previous-month actual, independent of the date_range filter — "Monthly
    // Target Progress" below is a fixed month-over-month widget, not a filtered view.
    'monthlyTargets' => [
        'online' => $onlineMonthPrevActual,
        'offline' => $offlineMonthPrevActual,
        'consignment' => $consignmentMonthlyTargetMock,
        'total' => $onlineMonthPrevActual + $offlineMonthPrevActual + $consignmentMonthlyTargetMock
    ],
    // Always true current-month actual — feeds "Monthly Target Progress" bars (see monthlyTargets above).
    'monthlyActual' => [
        'online' => $onlineMonthActual,
        'offline' => $offlineMonthActual,
        'consignment' => $consignmentTotalSalesMock,
    ],
    'regionalSales' => $regionalSales, // Offline (retail branches) only — Online/Consignment have no geographic attribution
    'growth' => [
        'online' => round($onlineGrowth, 1),
        'offline' => round($offlineGrowth, 1),
        'total' => $onlineBaselineReliable ? round(pct_change($onlineNetSales + $offlineNetSales, $onlinePrevNetSales + $offlinePrevNetSales), 1) : 0.0
    ],
    'orders' => [
        'online' => $onlineOrders,
        'offline' => $offlineOrders,
        'total' => $ordersTotal
    ],
    'unitsSold' => $unitsSoldTotal,
    'upt' => $ordersTotal > 0 ? round($unitsSoldTotal / $ordersTotal, 2) : 0,

    // AOV matches the same filtered period as Revenue/Orders above (not the fixed monthly goal figure).
    'aov' => $ordersTotal > 0 ? round(($onlineNetSales + $offlineNetSales + $consignmentPeriodEstimate) / $ordersTotal) : 0,

    // Dead code below (wrapped in an HTML comment in the markup) — left as-is, out of scope.
    'alerts' => [
        ['type' => 'positive', 'message' => 'Shopee +45.2% vs last month'],
        ['type' => 'positive', 'message' => 'TikTok +32.5% vs last month'],
        ['type' => 'positive', 'message' => 'Central World +18.1% vs last month'],
        ['type' => 'negative', 'message' => 'Lazada -12.4% vs last month'],
        ['type' => 'negative', 'message' => 'Legacy Parfum -8.3% vs last month']
    ],

    'platforms' => $onlinePlatforms, // real platforms: shopee, line_shopping, own_website

    'branches' => array_merge($offlineBranches, $consignmentBranchesMock),

    'topOnlineProducts' => $topOnlineProductsReal,
    'topOfflineProducts' => $topOfflineProductsReal,

    'monthlyTrend' => $monthlyTrend
];
// Sales channel breakdown for donut chart
$salesChannelBreakdown = [
    ['name'=>'Online','revenue'=>$mockData['totalSales']['online'],'percent'=>round($mockData['totalSales']['online']/$mockData['totalSales']['total']*100,1),'color'=>'#c9a227'],
    ['name'=>'Offline','revenue'=>$mockData['totalSales']['offline'],'percent'=>round($mockData['totalSales']['offline']/$mockData['totalSales']['total']*100,1),'color'=>'#1a1a2e'],
    ['name'=>'Consignment','revenue'=>$mockData['totalSales']['consignment'],'percent'=>round($mockData['totalSales']['consignment']/$mockData['totalSales']['total']*100,1),'color'=>'#e67e22']
];

// Top online platforms
$topOnlinePlatforms = array_slice($mockData['platforms'], 0, 3);

// Top offline & consignment locations
$offlineLocations = array_filter($mockData['branches'], function($b) { return $b['type'] == 'offline'; });
$consignmentLocations = array_filter($mockData['branches'], function($b) { return $b['type'] == 'consignment'; });
$topOfflineConsignment = array_merge($offlineLocations, $consignmentLocations);
usort($topOfflineConsignment, function($a, $b) { return $b['sales'] <=> $a['sales']; });
$topOfflineConsignment = array_slice($topOfflineConsignment, 0, 5);

// ----- ตัวแปรสำหรับ header.php -----
$pageTitle    = 'Executive Dashboard';
$pageSubtitle = 'Journal Sales Performance Overview';
$accentColor  = '#c9a227';
require_once __DIR__ . '/includes/header.php';

// หาค่าสูงสุดจากข้อมูลจริง (รวมทั้ง online และ offline) แทนตัวหาร hardcode เดิม
$maxTrendValue = max(array_merge(
    array_column($mockData['monthlyTrend'], 'online'),
    array_column($mockData['monthlyTrend'], 'offline')
));
?>
<style>
    .goal-progress-row { display: flex; align-items: center; gap: 25px; }
    .goal-progress-donut { position: relative; width: 130px; height: 130px; flex-shrink: 0; }
    .row1-grid { display: grid; grid-template-columns: 3fr 2fr; gap: 20px; margin-bottom: 20px; }
    .row1-grid canvas { height: 320px; }
    .channel-legend-row { display: flex; align-items: center; justify-content: center; gap: 40px; padding: 20px 0; }
    .channel-donut-wrap { position: relative; width: 180px; height: 180px; flex-shrink: 0; }
    .channel-legend-col { flex: 1; display: flex; flex-direction: column; gap: 10px; min-width: 0; }
    .layer2-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
    .row2-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .row2-grid canvas { height: 280px; }
    .row3-grid { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 20px; }
    .row3-grid canvas { height: 220px; }

    @media (max-width: 1100px) {
        .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        .row1-grid, .row2-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .kpi-grid { grid-template-columns: 1fr; }
        .goal-progress-row { flex-direction: column; align-items: stretch; }
        .channel-legend-row { flex-direction: column; gap: 16px; }
        .layer2-grid { grid-template-columns: 1fr; }
        .row1-grid canvas { height: 240px; }
        .row2-grid canvas { height: 220px; }
        .row3-grid canvas { height: 180px; }
    }
</style>
        <!-- Annual Goal Tracking -->
        <div class="goal-card">
            <div class="goal-header">
                <div>
                    <div class="goal-title">Annual Revenue Goal</div>
                    <div class="goal-amount"><?php echo number_format($mockData['goal']['annual']); ?></div>
                </div>
                <div class="goal-status <?php echo $mockData['goal']['onTrack'] ? 'on-track' : 'off-track'; ?>">
                    <?php echo $mockData['goal']['onTrack'] ? 'On Track' : 'Off Track'; ?>
                </div>
            </div>

            <div class="progress-section">
                <div class="progress-label">
                    <span>Year-to-Date Progress</span>
                </div>
                <div class="goal-progress-row">
                    <div class="goal-progress-donut">
                        <canvas id="goalProgressChart"></canvas>
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none;">
                            <div style="font-size: 28px; font-weight: 300; color: white; line-height: 1;"><?php echo number_format(($mockData['goal']['currentYear'] / $mockData['goal']['annual']) * 100, 1); ?>%</div>
                        </div>
                    </div>
                    <div style="flex: 1;">
                        <div style="font-size: 11px; color: rgba(255,255,255,0.7); margin-bottom: 2px; font-weight: 500;">Achievement</div>
                        <div style="font-size: 13px; color: rgba(255,255,255,0.9);">Year-to-Date vs Annual Goal</div>
                    </div>
                </div>
            </div>

<?php
$gap = $mockData['goal']['currentMonth']
     - $mockData['goal']['monthlyTarget'];
$achievement = ($mockData['goal']['currentMonth'] / $mockData['goal']['monthlyTarget']) * 100;
?>

<div class="stats-grid six-items-compact">

    <div class="stat-item">
        <div class="stat-label">Current Year</div>
        <div class="stat-value count-up" data-count-to="<?php echo (int) $mockData['goal']['currentYear']; ?>">0</div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Achievement</div>
        <div class="stat-value count-up" data-count-to="<?php echo $achievement; ?>" data-decimals="1" data-suffix="%" style="color: <?php echo $achievement >= 100 ? '#6ee7b7' : '#fca5a5'; ?>;">0%</div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Monthly Target</div>
        <div class="stat-value count-up" data-count-to="<?php echo (int) $mockData['goal']['monthlyTarget']; ?>">0</div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Gap</div>
        <div class="stat-value count-up" data-count-to="<?php echo (int) $gap; ?>">0</div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Current MTD</div>
        <div class="stat-value count-up" data-count-to="<?php echo (int) $mockData['goal']['currentMonth']; ?>">0</div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Projected</div>
        <div class="stat-value count-up" data-count-to="<?php echo (int) $mockData['goal']['projected']; ?>">0</div>
    </div>
</div>
    </div>

        <!-- Monthly Target Progress -->
        <div class="chart-card">
            <h3 style="margin-bottom: 20px;">Monthly Target Progress</h3>
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <?php
                $channels = [
                    ['name' => 'Online', 'current' => $mockData['monthlyActual']['online'], 'target' => $mockData['monthlyTargets']['online'], 'color' => '#c9a227'],
                    ['name' => 'Offline', 'current' => $mockData['monthlyActual']['offline'], 'target' => $mockData['monthlyTargets']['offline'], 'color' => '#1a1a2e'],
                    ['name' => 'Consignment', 'current' => $mockData['monthlyActual']['consignment'], 'target' => $mockData['monthlyTargets']['consignment'], 'color' => '#e67e22']
                ];
                foreach ($channels as $channel):
                    $progress = min(100, ($channel['current'] / $channel['target']) * 100);
                    $isOnTrack = $channel['current'] >= $channel['target'];
                    $statusColor = $isOnTrack ? '#10b981' : '#ef4444';
                    $statusText = $isOnTrack ? 'On Track' : 'Behind';
                ?>
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 10px; height: 10px; border-radius: 2px; background: <?php echo $channel['color']; ?>;"></div>
                            <span style="font-size: 13px; color: #111827; font-weight: 600;"><?php echo $channel['name']; ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <span style="font-size: 12px; color: #6B7280;">Target: <?php echo number_format($channel['target'] / 1000000, 1); ?>M</span>
                            <span style="font-size: 11px; color: <?php echo $statusColor; ?>; font-weight: 600; background: <?php echo $isOnTrack ? '#d1fae5' : '#fee2e2'; ?>; padding: 2px 8px; border-radius: 3px;"><?php echo $statusText; ?></span>
                        </div>
                    </div>
                    <div style="position: relative; height: 24px; background: #f5f5f5; border-radius: 4px; overflow: hidden;">
                        <div style="position: absolute; top: 0; left: 0; height: 100%; width: <?php echo $progress; ?>%; background: <?php echo $channel['color']; ?>; border-radius: 4px; transition: width 0.5s ease;"></div>
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); font-size: 11px; color: #111827; font-weight: 600; z-index: 1;">
                            <?php echo number_format($channel['current'] / 1000000, 1); ?>M (<?php echo number_format($progress, 0); ?>%)
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
<!-- 
    <div class="table-card">
    <h3>Executive Alerts</h3>
    <?php //foreach($mockData['alerts'] as $alert): ?>
        <div style="
            padding:12px;
            margin-bottom:10px;
            border-radius:8px;
            background:#f9fafb;
            border-left:4px solid <?php //echo $alert['type']=='positive' ? '#10b981' : '#ef4444'; ?>;
        ">

            <?php //if($alert['type']=='positive'): ?>
                <span style="color:#10b981;font-weight:bold;">
                    ▲
                </span>
            <?php //else: ?>
                <span style="color:#ef4444;font-weight:bold;">
                    ▼
                </span>
            <?php //endif; ?>

            <?php //echo $alert['message']; ?>

        </div>

    <?php //endforeach; ?>

</div> -->

        <!-- KPI Cards -->
<div class="kpi-grid">

    <div class="kpi-card" onclick="scrollToSection('channel-contribution')">
        <div class="tooltip">Total sales from all channels</div>
        <div class="label">Revenue</div>
        <div class="value">
            <?php echo number_format($mockData['totalSales']['total']); ?>
        </div>
        <div class="change positive">
            ▲ <?php echo $mockData['growth']['total']; ?>% vs last month
        </div>
    </div>

    <div class="kpi-card" onclick="scrollToSection('top-products')">
        <div class="tooltip">Total number of orders</div>
        <div class="label">Orders</div>
        <div class="value">
            <?php echo number_format($mockData['orders']['total']); ?>
        </div>
        <div class="change positive">
            ▲ 10.2% vs last month
        </div>
    </div>

    <div class="kpi-card" onclick="scrollToSection('top-products')">
        <div class="tooltip">Average units per transaction (Units Sold ÷ Orders)</div>
        <div class="label">Units per Transaction</div>
        <div class="value">
            <?php echo number_format($mockData['upt'], 2); ?>
        </div>
        <div class="change positive">
            ▲ 8.7% vs last month
        </div>
    </div>

    <div class="kpi-card" onclick="scrollToSection('top-products')">
        <div class="tooltip">AOV = Revenue ÷ Orders</div>
        <div class="label">Average Order Value</div>
        <div class="value">
            <?php echo number_format($mockData['aov']); ?>
        </div>
        <div class="change positive">
            ▲ 8.1% vs last month
        </div>
    </div>

</div>

        <!-- Row 1: Overview Trends and Channels -->
        <div class="row1-grid">
            <!-- Monthly Revenue Trend (60%) -->
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Monthly Revenue Trend</h3>
                    <a href="#" style="font-size: 11px; color: #c9a227; text-decoration: none; font-weight: 500;">View Details →</a>
                </div>
                <div style="padding: 20px 0;">
                    <canvas id="monthlyRevenueChart"></canvas>
                </div>
            </div>

            <!-- Sales Channel Distribution (40%) -->
            <div class="chart-card">
                <h3 id="channel-contribution">Sales Channel Distribution</h3>

                <!-- Layer 1: Donut Chart -->
                <div class="channel-legend-row">
                    <div class="channel-donut-wrap">
                        <canvas id="channelContributionChart"></canvas>
                        <!-- Center Text -->
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none;">
                            <div style="font-size: 24px; font-weight: 600; color: #111827;"><?php echo number_format($mockData['totalSales']['total'] / 1000000, 1); ?>M</div>
                            <div style="font-size: 10px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.5px;">Total Sales</div>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div class="channel-legend-col">
                        <?php foreach ($salesChannelBreakdown as $row): ?>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 12px; height: 12px; border-radius: 3px; background: <?php echo $row['color']; ?>;"></div>
                            <div style="flex: 1;">
                                <div style="font-size: 13px; color: #111827; font-weight: 600;"><?php echo $row['name']; ?></div>
                                <div style="font-size: 11px; color: #6B7280;"><?php echo number_format($row['revenue'] / 1000000, 1); ?>M · <?php echo $row['percent']; ?>%</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <!-- Layer 2: Top Platform/Location Tables -->
                <div class="layer2-grid" style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #f0f0f0;">
                    <!-- Top Online Platforms -->
                    <div>
                        <div style="font-size: 11px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; font-weight: 600;">Top Online Platforms</div>
                        <table style="width: 100%; border-collapse: collapse;">
                            <?php foreach ($topOnlinePlatforms as $index => $platform): ?>
                            <tr>
                                <td style="padding: 8px 0; border-bottom: 1px solid #f5f5f5;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="display: inline-block; width: 20px; height: 20px; line-height: 20px; text-align: center; border-radius: 50%; background: #c9a227; color: white; font-size: 10px; font-weight: 600;"><?php echo $index + 1; ?></span>
                                        <span style="font-size: 12px; color: #111827; font-weight: 500;"><?php echo htmlspecialchars($platform['name']); ?></span>
                                    </div>
                                </td>
                                <td style="padding: 8px 0; border-bottom: 1px solid #f5f5f5; text-align: right;">
                                    <span style="font-size: 12px; color: #111827; font-weight: 600;"><?php echo number_format($platform['sales'] / 1000000, 1); ?>M</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>

                    <!-- Top Offline & Consignment -->
                    <div>
                        <div style="font-size: 11px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 12px; font-weight: 600;">Top Offline & Consignment</div>
                        <table style="width: 100%; border-collapse: collapse;">
                            <?php foreach ($topOfflineConsignment as $index => $location): ?>
                            <tr>
                                <td style="padding: 8px 0; border-bottom: 1px solid #f5f5f5;">
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <span style="display: inline-block; width: 20px; height: 20px; line-height: 20px; text-align: center; border-radius: 50%; background: <?php echo $location['type'] == 'offline' ? '#1a1a2e' : '#e67e22'; ?>; color: white; font-size: 10px; font-weight: 600;"><?php echo $index + 1; ?></span>
                                        <span style="font-size: 12px; color: #111827; font-weight: 500;"><?php echo htmlspecialchars($location['name']); ?></span>
                                        <span style="font-size: 9px; color: #9CA3AF; background: <?php echo $location['type'] == 'offline' ? '#f0f0f0' : '#fff3e0'; ?>; padding: 2px 6px; border-radius: 3px; text-transform: uppercase;"><?php echo $location['type']; ?></span>
                                    </div>
                                </td>
                                <td style="padding: 8px 0; border-bottom: 1px solid #f5f5f5; text-align: right;">
                                    <span style="font-size: 12px; color: #111827; font-weight: 600;"><?php echo number_format($location['sales'] / 1000000, 1); ?>M</span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Row 2: Product and Geographic Dimensions -->
        <div class="row2-grid">
            <!-- Top 5 Online Products (50%) -->
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Top 5 Online Products</h3>
                    <a href="dashboard_online.php" style="font-size: 11px; color: #c9a227; text-decoration: none; font-weight: 500;">View Details →</a>
                </div>
                <div style="padding: 10px 0;">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>

            <!-- Geographic Sales Distribution (50%) -->
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Geographic Sales Distribution</h3>
                    <a href="dashboard_offline.php" style="font-size: 11px; color: #c9a227; text-decoration: none; font-weight: 500;">View Details →</a>
                </div>
                <div style="padding: 10px 0;">
                    <canvas id="geoChoroplethChart"></canvas>
                </div>
            </div>
        </div>

        <!-- Row 3: Top Offline Products (not affected by the Online platform cross-filter — no platform concept applies to retail branches) -->
        <div class="row3-grid">
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Top 5 Offline Products</h3>
                    <a href="dashboard_offline.php" style="font-size: 11px; color: #c9a227; text-decoration: none; font-weight: 500;">View Details →</a>
                </div>
                <div style="padding: 10px 0;">
                    <canvas id="topOfflineProductsChart"></canvas>
                </div>
            </div>
        </div>

<script>
    // Count-up animation for every stat in the Annual Goal card on page load
    document.querySelectorAll('.count-up').forEach(function (el) {
        const target = parseFloat(el.dataset.countTo) || 0;
        const decimals = parseInt(el.dataset.decimals, 10) || 0;
        const suffix = el.dataset.suffix || '';
        const duration = 1200;
        const startTime = performance.now();
        const fmt = new Intl.NumberFormat('th-TH', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

        function tick(now) {
            const progress = Math.min((now - startTime) / duration, 1);
            const eased = 1 - Math.pow(1 - progress, 3); // ease-out cubic
            el.textContent = fmt.format(target * eased) + suffix;
            if (progress < 1) {
                requestAnimationFrame(tick);
            }
        }
        requestAnimationFrame(tick);
    });

    // Monthly Revenue Trend Chart - Combo Chart (Bar + Line)
    const monthlyRevenueCtx = document.getElementById('monthlyRevenueChart').getContext('2d');

    const monthlyRevenueData = {
        labels: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'month')); ?>,
        datasets: [
            {
                label: 'Online Revenue',
                data: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'online')); ?>,
                type: 'bar',
                backgroundColor: '#c9a227',
                borderColor: '#c9a227',
                borderWidth: 1,
                borderRadius: 4,
                order: 4
            },
            {
                label: 'Offline Revenue',
                data: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'offline')); ?>,
                type: 'bar',
                backgroundColor: '#1a1a2e',
                borderColor: '#1a1a2e',
                borderWidth: 1,
                borderRadius: 4,
                order: 3
            },
            {
                label: 'Consignment Revenue',
                data: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'consignment')); ?>,
                type: 'bar',
                backgroundColor: '#e67e22',
                borderColor: '#e67e22',
                borderWidth: 1,
                borderRadius: 4,
                order: 2
            },
            {
                label: 'Total Revenue',
                data: <?php echo json_encode(array_map(function($t) { return $t['online'] + $t['offline'] + $t['consignment']; }, $mockData['monthlyTrend'])); ?>,
                type: 'line',
                backgroundColor: '#10b981',
                borderColor: '#10b981',
                borderWidth: 3,
                pointBackgroundColor: '#10b981',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.3,
                order: 1
            }
        ]
    };

    const monthlyRevenueChart = new Chart(monthlyRevenueCtx, {
        type: 'bar',
        data: monthlyRevenueData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            layout: {
                padding: {
                    top: 20,
                    bottom: 10,
                    left: 10,
                    right: 10
                }
            },
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                legend: {
                    display: true,
                    position: 'bottom',
                    align: 'center',
                    labels: {
                        usePointStyle: true,
                        padding: 15,
                        font: {
                            size: 12,
                            weight: 500
                        },
                        color: '#111827'
                    }
                },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleFont: {
                        size: 13,
                        weight: 600
                    },
                    bodyFont: {
                        size: 12,
                        weight: 500
                    },
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            if (label) {
                                label += ': ';
                            }
                            label += new Intl.NumberFormat('th-TH').format(context.raw);
                            return label;
                        }
                    }
                }
            },
            scales: {
                x: {
                    grid: {
                        display: false
                    },
                    ticks: {
                        font: {
                            size: 12,
                            weight: 500
                        },
                        color: '#6B7280'
                    }
                },
                y: {
                    beginAtZero: true,
                    ticks: {
                        maxTicksLimit: 5,
                        callback: function(value) {
                            if (value >= 1000000) {
                                return (value / 1000000).toFixed(0) + 'M';
                            }
                            return value;
                        },
                        font: {
                            size: 11,
                            weight: 500
                        },
                        color: '#6B7280'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)',
                        drawBorder: false
                    }
                }
            }
        }
    });

    // Channel Contribution Doughnut Chart
    const channelContributionCtx = document.getElementById('channelContributionChart').getContext('2d');

    const channelContributionData = {
        labels: <?php echo json_encode(array_column($salesChannelBreakdown, 'name')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($salesChannelBreakdown, 'revenue')); ?>,
            backgroundColor: <?php echo json_encode(array_column($salesChannelBreakdown, 'color')); ?>,
            borderWidth: 0,
            hoverOffset: 4
        }]
    };

    const channelContributionChart = new Chart(channelContributionCtx, {
        type: 'doughnut',
        data: channelContributionData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '65%',
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleFont: {
                        size: 13,
                        weight: 600
                    },
                    bodyFont: {
                        size: 12,
                        weight: 500
                    },
                    callbacks: {
                        label: function(context) {
                            const value = context.raw;
                            const total = context.dataset.data.reduce((a, b) => a + b, 0);
                            const percentage = ((value / total) * 100).toFixed(1);
                            return [
                                'Revenue: ' + new Intl.NumberFormat('th-TH').format(value),
                                'Share: ' + percentage + '%'
                            ];
                        }
                    }
                }
            }
        }
    });

    // Top Online Products Horizontal Bar Chart
    const topProductsCtx = document.getElementById('topProductsChart').getContext('2d');
    const top5Products = <?php echo json_encode(array_slice($mockData['topOnlineProducts'], 0, 5)); ?>;

    const topProductsChart = new Chart(topProductsCtx, {
        type: 'bar',
        data: {
            labels: top5Products.map(p => p.name),
            datasets: [{
                label: 'Sales (฿)',
                data: top5Products.map(p => p.sales),
                backgroundColor: '#c9a227',
                borderColor: '#c9a227',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            const product = top5Products[context.dataIndex];
                            return [
                                'Sales: ' + new Intl.NumberFormat('th-TH').format(product.sales),
                                'Units: ' + new Intl.NumberFormat('th-TH').format(product.unit),
                                'Contribution: ' + product.contribution + '%'
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000000) {
                                return (value / 1000000).toFixed(1) + 'M';
                            }
                            return value;
                        },
                        font: {
                            size: 11,
                            weight: 500
                        },
                        color: '#6B7280'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                y: {
                    ticks: {
                        font: {
                            size: 12,
                            weight: 500
                        },
                        color: '#374151'
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });

    // Top Offline Products Horizontal Bar Chart (not affected by the platform cross-filter)
    const topOfflineProductsCtx = document.getElementById('topOfflineProductsChart').getContext('2d');
    const top5OfflineProducts = <?php echo json_encode(array_slice($mockData['topOfflineProducts'], 0, 5)); ?>;

    const topOfflineProductsChart = new Chart(topOfflineProductsCtx, {
        type: 'bar',
        data: {
            labels: top5OfflineProducts.map(p => p.name),
            datasets: [{
                label: 'Sales (฿)',
                data: top5OfflineProducts.map(p => p.sales),
                backgroundColor: '#1a1a2e',
                borderColor: '#1a1a2e',
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: { display: false },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            const product = top5OfflineProducts[context.dataIndex];
                            return [
                                'Sales: ' + new Intl.NumberFormat('th-TH').format(product.sales),
                                'Units: ' + new Intl.NumberFormat('th-TH').format(product.unit),
                                'Contribution: ' + product.contribution + '%'
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000000) {
                                return (value / 1000000).toFixed(1) + 'M';
                            }
                            return value;
                        },
                        font: { size: 11, weight: 500 },
                        color: '#6B7280'
                    },
                    grid: { color: 'rgba(0, 0, 0, 0.05)' }
                },
                y: {
                    ticks: { font: { size: 12, weight: 500 }, color: '#374151' },
                    grid: { display: false }
                }
            }
        }
    });

    // Geographic Choropleth Map - Horizontal Bar with Color Shading
    const geoChoroplethCtx = document.getElementById('geoChoroplethChart').getContext('2d');
    const regionalData = <?php echo json_encode($mockData['regionalSales']); ?>;
    const maxRegionalSales = Math.max(...Object.values(regionalData));

    const regions = Object.keys(regionalData).map((region, index) => {
        const sales = regionalData[region];
        const intensity = sales / maxRegionalSales;
        // Color from light gold to dark gold based on sales intensity
        const lightness = 85 - (intensity * 40); // 85% to 45% lightness
        const color = `hsl(45, 70%, ${lightness}%)`;
        return {
            name: region,
            sales: sales,
            color: color,
            intensity: intensity
        };
    });

    const geoChoroplethChart = new Chart(geoChoroplethCtx, {
        type: 'bar',
        data: {
            labels: regions.map(r => r.name),
            datasets: [{
                label: 'Sales (฿)',
                data: regions.map(r => r.sales),
                backgroundColor: regions.map(r => r.color),
                borderColor: regions.map(r => r.color),
                borderWidth: 1,
                borderRadius: 4
            }]
        },
        options: {
            indexAxis: 'y',
            responsive: true,
            maintainAspectRatio: false,
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            const region = regions[context.dataIndex];
                            const percentage = ((region.sales / Object.values(regionalData).reduce((a, b) => a + b, 0)) * 100).toFixed(1);
                            return [
                                'Sales: ' + new Intl.NumberFormat('th-TH').format(region.sales),
                                'Share: ' + percentage + '%'
                            ];
                        }
                    }
                }
            },
            scales: {
                x: {
                    ticks: {
                        callback: function(value) {
                            if (value >= 1000000) {
                                return (value / 1000000).toFixed(0) + 'M';
                            }
                            return value;
                        },
                        font: {
                            size: 11,
                            weight: 500
                        },
                        color: '#6B7280'
                    },
                    grid: {
                        color: 'rgba(0, 0, 0, 0.05)'
                    }
                },
                y: {
                    ticks: {
                        font: {
                            size: 12,
                            weight: 600
                        },
                        color: '#111827'
                    },
                    grid: {
                        display: false
                    }
                }
            }
        }
    });

    // Cross-filter functionality
    let currentChannelFilter = null;

    // Store original data for filtering
    const originalData = {
        monthlyTrend: <?php echo json_encode($mockData['monthlyTrend']); ?>,
        topOnlineProducts: <?php echo json_encode($mockData['topOnlineProducts']); ?>,
        branches: <?php echo json_encode($mockData['branches']); ?>,
        platforms: <?php echo json_encode($mockData['platforms']); ?>,
        kpi: {
            revenue: <?php echo $mockData['totalSales']['total']; ?>,
            orders: <?php echo $mockData['orders']['total']; ?>,
            unitsSold: <?php echo $mockData['unitsSold']; ?>,
            aov: <?php echo $mockData['aov']; ?>
        }
    };

    function filterByChannel(channelName) {
        currentChannelFilter = channelName;
        // Update filter bar display
        const filterValue = document.querySelector('.filter-bar .filter-value');
        if (filterValue) {
            filterValue.textContent = channelName;
        }
        // Show reset button
        const resetBtn = document.getElementById('resetFilterBtn');
        if (resetBtn) {
            resetBtn.style.display = 'block';
        }
        // Highlight selected channel in legend
        updateLegendHighlight(channelName);
        // Apply filtering to all dashboard components (except Hero Card)
        applyChannelFilter(channelName);
    }

    function resetChannelFilter() {
        currentChannelFilter = null;
        const filterValue = document.querySelector('.filter-bar .filter-value');
        if (filterValue) {
            filterValue.textContent = 'All Channels';
        }
        // Hide reset button
        const resetBtn = document.getElementById('resetFilterBtn');
        if (resetBtn) {
            resetBtn.style.display = 'none';
        }
        updateLegendHighlight(null);
        // Reset all dashboard components to original data (except Hero Card)
        resetDashboardData();
    }

    function applyChannelFilter(channelName) {
        // Filter Monthly Revenue Trend
        updateMonthlyTrendChart(channelName);

        // Filter KPI Cards
        updateKPICards(channelName);

        // Filter Top Products Table
        updateTopProductsTable(channelName);

        // Filter Top Branches Table
        updateBranchesTable(channelName);

        // Filter Top Online Platforms Table
        updatePlatformsTable(channelName);

        // Note: Hero Card (Annual Revenue Goal) is NOT filtered - it's a global business goal
    }

    function updateMonthlyTrendChart(channelName) {
        const filteredTrend = originalData.monthlyTrend.map(month => {
            const channelRevenue = month.channels[channelName] || 0;
            return {
                month: month.month,
                online: channelRevenue,
                offline: 0, // Offline not channel-specific
                consignment: 0 // Consignment not channel-specific
            };
        });

        monthlyRevenueChart.data.datasets[0].data = filteredTrend.map(d => d.online);
        monthlyRevenueChart.data.datasets[1].data = filteredTrend.map(d => d.offline);
        monthlyRevenueChart.data.datasets[2].data = filteredTrend.map(d => d.consignment);
        monthlyRevenueChart.data.datasets[3].data = filteredTrend.map(d => d.online + d.offline + d.consignment);
        monthlyRevenueChart.update();
    }

    function updateKPICards(channelName) {
        // Calculate filtered KPI values
        let filteredRevenue = 0;
        let filteredOrders = 0;
        let filteredUnits = 0;

        originalData.monthlyTrend.forEach(month => {
            if (month.channels[channelName]) {
                filteredRevenue += month.channels[channelName];
                // Estimate orders based on average (simplified)
                filteredOrders += Math.floor(month.channels[channelName] / originalData.kpi.aov);
            }
        });

        // Estimate units (simplified calculation)
        filteredUnits = Math.floor(filteredRevenue / (originalData.kpi.revenue / originalData.kpi.unitsSold));

        // Update KPI card values
        const kpiCards = document.querySelectorAll('.kpi-card');
        if (kpiCards[0]) {
            kpiCards[0].querySelector('.value').textContent = new Intl.NumberFormat('th-TH').format(filteredRevenue);
        }
        if (kpiCards[1]) {
            kpiCards[1].querySelector('.value').textContent = new Intl.NumberFormat('th-TH').format(filteredOrders);
        }
        if (kpiCards[2]) {
            kpiCards[2].querySelector('.value').textContent = new Intl.NumberFormat('th-TH').format(filteredUnits);
        }
        if (kpiCards[3]) {
            const filteredAOV = filteredOrders > 0 ? Math.round(filteredRevenue / filteredOrders) : 0;
            kpiCards[3].querySelector('.value').textContent = new Intl.NumberFormat('th-TH').format(filteredAOV);
        }
    }

    function updateTopProductsTable(channelName) {
        const filteredProducts = originalData.topOnlineProducts
            .map(product => ({
                ...product,
                filteredSales: product.channels[channelName] || 0
            }))
            .filter(product => product.filteredSales > 0)
            .sort((a, b) => b.filteredSales - a.filteredSales);

        // Update the horizontal bar chart
        topProductsChart.data.labels = filteredProducts.map(p => p.name);
        topProductsChart.data.datasets[0].data = filteredProducts.map(p => p.filteredSales);
        topProductsChart.update();
    }

    function updateBranchesTable(channelName) {
        const filteredBranches = originalData.branches
            .map(branch => ({
                ...branch,
                filteredSales: branch.channels[channelName] || 0
            }))
            .filter(branch => branch.filteredSales > 0)
            .sort((a, b) => b.filteredSales - a.filteredSales);

        const tableBody = document.querySelector('.split-grid .table-card:nth-child(2) tbody');
        if (tableBody) {
            tableBody.innerHTML = '';
            if (filteredBranches.length > 0) {
                filteredBranches.forEach((branch, index) => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><span style="display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:50%;background:#1a1a2e;color:white;font-size:11px;font-weight:600;">${index + 1}</span></td>
                        <td><span class="badge branch">${branch.name}</span></td>
                        <td>${new Intl.NumberFormat('th-TH').format(branch.filteredSales)}</td>
                        <td>${new Intl.NumberFormat('th-TH').format(Math.floor(branch.filteredSales / (branch.sales / branch.orders)))}</td>
                        <td>${new Intl.NumberFormat('th-TH').format(Math.round(branch.filteredSales / Math.floor(branch.filteredSales / (branch.sales / branch.orders))))}</td>
                    `;
                    tableBody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="5" style="text-align: center; color: #9CA3AF; padding: 20px;">No data available for this channel</td>';
                tableBody.appendChild(row);
            }
        }
    }

    function updatePlatformsTable(channelName) {
        const filteredPlatforms = originalData.platforms.filter(platform => platform.channel === channelName);

        const tableBody = document.querySelector('.split-grid .table-card:nth-child(1) tbody');
        if (tableBody) {
            tableBody.innerHTML = '';
            if (filteredPlatforms.length > 0) {
                filteredPlatforms.forEach(platform => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td><span class="badge platform">${platform.name}</span></td>
                        <td>${new Intl.NumberFormat('th-TH').format(platform.sales)}</td>
                        <td>${new Intl.NumberFormat('th-TH').format(platform.orders)}</td>
                        <td>${new Intl.NumberFormat('th-TH').format(Math.round(platform.sales / platform.orders))}</td>
                        <td style="color: #10B981; font-weight: 500;">▲ ${platform.growth}%</td>
                        <td>${Math.round((platform.sales / originalData.kpi.revenue) * 100)}%</td>
                    `;
                    tableBody.appendChild(row);
                });
            } else {
                const row = document.createElement('tr');
                row.innerHTML = '<td colspan="6" style="text-align: center; color: #9CA3AF; padding: 20px;">No data available for this channel</td>';
                tableBody.appendChild(row);
            }
        }
    }

    function resetDashboardData() {
        // Reset Monthly Revenue Trend
        monthlyRevenueChart.data.datasets[0].data = originalData.monthlyTrend.map(d => d.online);
        monthlyRevenueChart.data.datasets[1].data = originalData.monthlyTrend.map(d => d.offline);
        monthlyRevenueChart.data.datasets[2].data = originalData.monthlyTrend.map(d => d.consignment);
        monthlyRevenueChart.data.datasets[3].data = originalData.monthlyTrend.map(d => d.online + d.offline + d.consignment);
        monthlyRevenueChart.update();

        // Reset KPI Cards
        const kpiCards = document.querySelectorAll('.kpi-card');
        if (kpiCards[0]) {
            kpiCards[0].querySelector('.value').textContent = new Intl.NumberFormat('th-TH').format(originalData.kpi.revenue);
        }
        if (kpiCards[1]) {
            kpiCards[1].querySelector('.value').textContent = new Intl.NumberFormat('th-TH').format(originalData.kpi.orders);
        }
        if (kpiCards[2]) {
            kpiCards[2].querySelector('.value').textContent = new Intl.NumberFormat('th-TH').format(originalData.kpi.unitsSold);
        }
        if (kpiCards[3]) {
            kpiCards[3].querySelector('.value').textContent = new Intl.NumberFormat('th-TH').format(originalData.kpi.aov);
        }

        // Reset Top Products Table
        const productsTableBody = document.querySelector('#top-products').closest('.table-card').querySelector('tbody');
        if (productsTableBody) {
            productsTableBody.innerHTML = '';
            originalData.topOnlineProducts.forEach(product => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td>${product.name}</td>
                    <td>${new Intl.NumberFormat('th-TH').format(product.sales)}</td>
                    <td>${new Intl.NumberFormat('th-TH').format(product.unit)}</td>
                    <td>${product.contribution}%</td>
                `;
                productsTableBody.appendChild(row);
            });
        }

        // Reset Branches Table
        const branchesTableBody = document.querySelector('.split-grid .table-card:nth-child(2) tbody');
        if (branchesTableBody) {
            branchesTableBody.innerHTML = '';
            originalData.branches.forEach((branch, index) => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><span style="display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:50%;background:#1a1a2e;color:white;font-size:11px;font-weight:600;">${index + 1}</span></td>
                    <td><span class="badge branch">${branch.name}</span></td>
                    <td>${new Intl.NumberFormat('th-TH').format(branch.sales)}</td>
                    <td>${new Intl.NumberFormat('th-TH').format(branch.orders)}</td>
                    <td>${new Intl.NumberFormat('th-TH').format(branch.aov)}</td>
                `;
                branchesTableBody.appendChild(row);
            });
        }

        // Reset Platforms Table
        const platformsTableBody = document.querySelector('.split-grid .table-card:nth-child(1) tbody');
        if (platformsTableBody) {
            platformsTableBody.innerHTML = '';
            originalData.platforms.forEach(platform => {
                const row = document.createElement('tr');
                row.innerHTML = `
                    <td><span class="badge platform">${platform.name}</span></td>
                    <td>${new Intl.NumberFormat('th-TH').format(platform.sales)}</td>
                    <td>${new Intl.NumberFormat('th-TH').format(platform.orders)}</td>
                    <td>${new Intl.NumberFormat('th-TH').format(Math.round(platform.sales / platform.orders))}</td>
                    <td style="color: #10B981; font-weight: 500;">▲ ${platform.growth}%</td>
                    <td>${Math.round((platform.sales / originalData.kpi.revenue) * 100)}%</td>
                `;
                platformsTableBody.appendChild(row);
            });
        }

        // Note: Hero Card (Annual Revenue Goal) is NOT reset - it's a global business goal
    }

    function updateLegendHighlight(channelName) {
        // Add visual feedback to legend items
        const legendContainer = document.querySelector('#channel-contribution').parentElement;
        const legendItems = legendContainer.querySelectorAll('div[onclick^="filterByChannel"]');
        legendItems.forEach(item => {
            const name = item.querySelector('div:nth-child(2) > div:first-child').textContent;
            if (channelName === null || name === channelName) {
                item.style.opacity = '1';
            } else {
                item.style.opacity = '0.4';
            }
        });
    }

    // Goal Progress Doughnut Chart
    const goalProgressCtx = document.getElementById('goalProgressChart').getContext('2d');

    const goalAchievement = <?php echo ($mockData['goal']['currentYear'] / $mockData['goal']['annual']) * 100; ?>;
    const goalRemaining = 100 - goalAchievement;

    const goalProgressData = {
        labels: ['Achieved', 'Remaining'],
        datasets: [{
            data: [goalAchievement, goalRemaining],
            backgroundColor: ['#c9a227', 'rgba(255,255,255,0.15)'],
            borderWidth: 0,
            circumference: 360,
            rotation: -90
        }]
    };

    const goalProgressChart = new Chart(goalProgressCtx, {
        type: 'doughnut',
        data: goalProgressData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
            cutout: '80%',
            plugins: {
                legend: {
                    display: false
                },
                tooltip: {
                    enabled: true,
                    backgroundColor: 'rgba(0, 0, 0, 0.8)',
                    padding: 12,
                    titleFont: {
                        size: 13,
                        weight: 'bold'
                    },
                    bodyFont: {
                        size: 12
                    },
                    callbacks: {
                        label: function(context) {
                            const label = context.label;
                            const value = context.raw.toFixed(1) + '%';
                            return label + ': ' + value;
                        }
                    }
                }
            }
        }
    });
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
