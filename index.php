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

function growth_badge_html(float $value, string $suffix = ' vs last month'): string
{
    $cls = $value >= 0 ? 'positive' : 'negative';
    $arrow = $value >= 0 ? '▲' : '▼';
    return '<div class="change ' . $cls . '">' . $arrow . ' ' . number_format(abs($value), 1) . '%' . $suffix . '</div>';
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
    'channel' => request_value('channel', 'all', ['all', 'online', 'offline']),
];

/**
 * growth_badge_html() used to hard-default every badge's label to " vs last month"
 * regardless of the active date_range — so "Today" mode (which actually compares
 * against yesterday) and "YTD" mode (which compares against last year) both displayed
 * a label describing a comparison that wasn't the one being shown. The math was correct;
 * only the label lied about what it was measuring.
 */
$growthSuffix = match ($filterValues['date_range']) {
    'today' => ' vs yesterday',
    'ytd' => ' vs last year',
    default => ' vs last month',
};

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
 * dashboards. The Monthly Revenue Trend chart's window also follows date_range (see
 * $trendMonthsBack below) and its channel breakdown follows the channel filter. The
 * Annual Goal card intentionally stays unaffected — see its own comment below.
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
    /**
     * Previous-month baseline must span the SAME elapsed length as the current partial
     * month, not the full previous month — otherwise a partial current month (e.g. 8
     * days in) is compared against 30 full days and always reads "down" by
     * construction, regardless of real performance. Audit found this rendering
     * "-71.4% vs last month" when the true pace-adjusted comparison was "+10.6%". Fix
     * mirrors dashboard_online.php's MoM baseline (shift bounds back exactly 1 month,
     * using the real elapsed end date $onlineMaxDate, not the full month's end).
     */
    $onlineTargetStart = clone $onlinePrevMonthStart;
    $onlineTargetEnd = (clone $onlineMaxDate)->modify('+1 day')->modify('-1 month');
}

/**
 * Item-grain aggregate joined 1:1 per order (order_key is unique per row here), so
 * joining it into fact_online_orders never fans out header fields. line_total sums
 * reliably to total_amount (verified against dashboard_online.php); unit_price = 0
 * excludes gift/free lines from unit counts, matching the same default behavior used
 * on the Online dashboard (see SYSTEM_MAP.md Known Data Risks).
 *
 * IMPORTANT — every query below must LEFT JOIN this (never INNER JOIN), and wrap
 * ia.netSales/ia.units in COALESCE(..., o.total_amount / 0). ~25% of real orders have a
 * header row in fact_online_orders but zero rows in fact_online_order_items (an ETL
 * gap) — an INNER JOIN silently drops those orders from every figure on this page. Same
 * root cause and fix as dashboard_online.php's item_agg (see that file's CTE comment).
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
    SELECT 'current' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(COALESCE(ia.units, 0)) AS units, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "

    UNION ALL

    SELECT 'prev' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(COALESCE(ia.units, 0)) AS units, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "

    UNION ALL

    SELECT 'ytd' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(COALESCE(ia.units, 0)) AS units, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "

    UNION ALL

    SELECT 'month' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(COALESCE(ia.units, 0)) AS units, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "

    UNION ALL

    SELECT 'prev_month' AS period, COUNT(DISTINCT o.order_key) AS orders, SUM(COALESCE(ia.units, 0)) AS units, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
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
    SELECT o.platform, COUNT(DISTINCT o.order_key) AS orders, SUM(COALESCE(ia.units, 0)) AS units, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
    GROUP BY o.platform
    ORDER BY netSales DESC;
", [$onlinePeriodStart->format('Y-m-d'), $onlinePeriodEnd->format('Y-m-d')]);
$onlinePlatformPrevRows = fetch_all($conn, "
    WITH " . ONLINE_ITEM_AGG_CTE . "
    SELECT o.platform, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
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

// Monthly Trend chart's window: YTD narrows to Jan..current month; Today/MTD keep the
// trailing-12-months view (a single day/month has no meaningful "monthly" trend).
$trendMonthsBack = ($filterValues['date_range'] === 'ytd')
    ? max(0, (int) $onlineMtdStart->format('n') - 1)
    : 11;
$onlineTrendStart = (clone $onlineMtdStart)->modify("-{$trendMonthsBack} months");
$onlineTrendRows = fetch_all($conn, "
    WITH " . ONLINE_ITEM_AGG_CTE . "
    SELECT FORMAT(o.order_datetime, 'yyyy-MM') AS ym, o.platform, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
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
    // Same elapsed-length fix as Online's MTD baseline above — see that comment.
    $offlineTargetStartKey = $offlinePrevMonthStartKey;
    $offlineTargetEndKey = (int) (clone $offlineMaxDate)->modify('+1 day')->modify('-1 month')->format('Ymd');
}

$offlineSummaryRows = fetch_all($conn, "
    SELECT 'current' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "

    UNION ALL

    SELECT 'prev' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "

    UNION ALL

    SELECT 'ytd' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "

    UNION ALL

    SELECT 'month' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "

    UNION ALL

    SELECT 'prev_month' AS period, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . ";
", [
    $offlinePeriodStartKey, $offlinePeriodEndKey,
    $offlineTargetStartKey, $offlineTargetEndKey,
    $offlineYearStartKey, $offlineMtdEndKey,
    $offlineMtdStartKey, $offlineMtdEndKey,
    $offlinePrevMonthStartKey, $offlineMtdStartKey,
]);
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
    WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
    GROUP BY b.BranchName
    ORDER BY netSales DESC;
", [$offlinePeriodStartKey, $offlinePeriodEndKey]);

$offlineZoneRows = fetch_all($conn, "
    SELECT " . zone_case_sql() . " AS zone, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
    GROUP BY " . zone_case_sql() . ";
", [$offlinePeriodStartKey, $offlinePeriodEndKey]);

$offlineTopProductRows = fetch_all($conn, "
    SELECT TOP 5 p.ProductCode, p.ProductName, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
    GROUP BY p.ProductCode, p.ProductName
    ORDER BY netSales DESC;
", [$offlinePeriodStartKey, $offlinePeriodEndKey]);

$offlineTrendStartKey = (int) (clone $offlineMtdStart)->modify("-{$trendMonthsBack} months")->format('Ymd');
$offlineTrendRows = fetch_all($conn, "
    SELECT LEFT(CAST(f.DateKey AS varchar(8)), 6) AS ym, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
    GROUP BY LEFT(CAST(f.DateKey AS varchar(8)), 6);
", [$offlineTrendStartKey, $offlineMtdEndKey]);
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

// Prev-period real-only baselines for Orders/UPT/AOV growth — consignment has no real
// order count, so it's excluded here the same way it's excluded from $ordersTotal above.
$onlinePrevOrders = (int) n($onlineSummary['prev']['orders'] ?? 0);
$onlinePrevUnits = n($onlineSummary['prev']['units'] ?? 0);
$offlinePrevOrders = (int) n($offlineSummary['prev']['orders'] ?? 0);
$offlinePrevUnits = n($offlineSummary['prev']['units'] ?? 0);
$prevOrdersTotal = $onlinePrevOrders + $offlinePrevOrders;
$prevUnitsTotal = $onlinePrevUnits + $offlinePrevUnits;
$uptTotal = $ordersTotal > 0 ? $unitsSoldTotal / $ordersTotal : 0.0;
$prevUptTotal = $prevOrdersTotal > 0 ? $prevUnitsTotal / $prevOrdersTotal : 0.0;
// Real-only AOV (online+offline net sales / real orders) — matches $ordersTotal's scope.
// Previously this mixed the consignment revenue *estimate* into the numerator while the
// denominator ($ordersTotal) excluded consignment orders entirely, silently inflating AOV.
$aovTotalReal = $ordersTotal > 0 ? ($onlineNetSales + $offlineNetSales) / $ordersTotal : 0.0;
$prevAovTotal = $prevOrdersTotal > 0 ? ($onlinePrevNetSales + $offlinePrevNetSales) / $prevOrdersTotal : 0.0;
$ordersGrowthReal = $onlineBaselineReliable ? pct_change($ordersTotal, $prevOrdersTotal) : 0.0;
$uptGrowthReal = $onlineBaselineReliable ? pct_change($uptTotal, $prevUptTotal) : 0.0;
$aovGrowthReal = $onlineBaselineReliable ? pct_change($aovTotalReal, $prevAovTotal) : 0.0;

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
        'total' => $onlineBaselineReliable ? round(pct_change($onlineNetSales + $offlineNetSales, $onlinePrevNetSales + $offlinePrevNetSales), 1) : 0.0,
        // Real vs-last-month growth for the Orders/UPT/AOV KPI cards below — previously
        // these three cards showed hardcoded fake percentages, not computed values at all.
        'orders' => round($ordersGrowthReal, 1),
        'upt' => round($uptGrowthReal, 1),
        'aov' => round($aovGrowthReal, 1),
    ],
    'orders' => [
        'online' => $onlineOrders,
        'offline' => $offlineOrders,
        'total' => $ordersTotal
    ],
    'unitsSold' => $unitsSoldTotal,
    'upt' => round($uptTotal, 2),

    // Real-only AOV (online+offline net sales / real orders, consignment excluded) — see
    // $aovTotalReal comment above for why the consignment estimate was removed from here.
    'aov' => round($aovTotalReal),

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

// ---------------------------------------------------------------------------
// Total Sales Trend (Online + Offline + Consignment est.), current vs previous
// period — granularity follows date_range: hourly for Today, daily (day-of-month)
// for MTD, monthly for YTD. FactSales has no time-of-day column, so Offline's
// Today view spreads its daily total evenly across hours (an estimate, not real
// intraday data). Consignment has no real time series at all, so it's spread as
// the same flat run-rate in every bucket of both lines (see mock comments above).
// ---------------------------------------------------------------------------
$totalTrendGranularity = match ($filterValues['date_range']) {
    'today' => 'hour',
    'ytd' => 'month',
    default => 'day', // mtd
};
$totalTrendLabels = [];
$totalTrendCurrent = [];
$totalTrendPrevious = [];

if ($totalTrendGranularity === 'hour') {
    $onlineHourRows = fetch_all($conn, "
        WITH " . ONLINE_ITEM_AGG_CTE . "
        SELECT DATEPART(HOUR, o.order_datetime) AS bucket, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
        FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
        GROUP BY DATEPART(HOUR, o.order_datetime);
    ", [$onlinePeriodStart->format('Y-m-d H:i:s'), $onlinePeriodEnd->format('Y-m-d H:i:s')]);
    $onlinePrevHourRows = fetch_all($conn, "
        WITH " . ONLINE_ITEM_AGG_CTE . "
        SELECT DATEPART(HOUR, o.order_datetime) AS bucket, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
        FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
        GROUP BY DATEPART(HOUR, o.order_datetime);
    ", [$onlineTargetStart->format('Y-m-d H:i:s'), $onlineTargetEnd->format('Y-m-d H:i:s')]);

    $onlineByHour = array_fill(0, 24, 0.0);
    foreach ($onlineHourRows as $row) { $onlineByHour[(int) $row['bucket']] = n($row['netSales']); }
    $onlinePrevByHour = array_fill(0, 24, 0.0);
    foreach ($onlinePrevHourRows as $row) { $onlinePrevByHour[(int) $row['bucket']] = n($row['netSales']); }

    $offlineFlatPerHour = $offlineNetSales / 24;
    $offlinePrevFlatPerHour = $offlinePrevNetSales / 24;
    $consignmentFlatPerHour = $consignmentPeriodEstimate / 24;

    for ($h = 0; $h < 24; $h++) {
        $totalTrendLabels[] = sprintf('%02d:00', $h);
        $totalTrendCurrent[] = round($onlineByHour[$h] + $offlineFlatPerHour + $consignmentFlatPerHour);
        $totalTrendPrevious[] = round($onlinePrevByHour[$h] + $offlinePrevFlatPerHour + $consignmentFlatPerHour);
    }
} elseif ($totalTrendGranularity === 'day') {
    // Days elapsed this month, per the real online data's latest date — both lines
    // show the same number of day-of-month buckets so they compare like-for-like.
    $daysInPeriod = max(1, (int) $onlineMaxDate->format('j'));
    $consignmentFlatPerDay = $consignmentMonthlyFlat / 30;

    $onlineDayRows = fetch_all($conn, "
        WITH " . ONLINE_ITEM_AGG_CTE . "
        SELECT DAY(o.order_datetime) AS bucket, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
        FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
        GROUP BY DAY(o.order_datetime);
    ", [$onlinePeriodStart->format('Y-m-d'), $onlinePeriodEnd->format('Y-m-d')]);
    $onlinePrevDayRows = fetch_all($conn, "
        WITH " . ONLINE_ITEM_AGG_CTE . "
        SELECT DAY(o.order_datetime) AS bucket, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
        FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
        GROUP BY DAY(o.order_datetime);
    ", [$onlineTargetStart->format('Y-m-d'), $onlineTargetEnd->format('Y-m-d')]);
    $offlineDayRows = fetch_all($conn, "
        SELECT (f.DateKey % 100) AS bucket, SUM(f.NetTotal) AS netSales
        FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
        WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
        GROUP BY (f.DateKey % 100);
    ", [$offlinePeriodStartKey, $offlinePeriodEndKey]);
    $offlinePrevDayRows = fetch_all($conn, "
        SELECT (f.DateKey % 100) AS bucket, SUM(f.NetTotal) AS netSales
        FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
        WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
        GROUP BY (f.DateKey % 100);
    ", [$offlineTargetStartKey, $offlineTargetEndKey]);

    $onlineByDay = array_fill(1, 31, 0.0);
    foreach ($onlineDayRows as $row) { $onlineByDay[(int) $row['bucket']] = n($row['netSales']); }
    $onlinePrevByDay = array_fill(1, 31, 0.0);
    foreach ($onlinePrevDayRows as $row) { $onlinePrevByDay[(int) $row['bucket']] = n($row['netSales']); }
    $offlineByDay = array_fill(1, 31, 0.0);
    foreach ($offlineDayRows as $row) { $offlineByDay[(int) $row['bucket']] = n($row['netSales']); }
    $offlinePrevByDay = array_fill(1, 31, 0.0);
    foreach ($offlinePrevDayRows as $row) { $offlinePrevByDay[(int) $row['bucket']] = n($row['netSales']); }

    for ($d = 1; $d <= $daysInPeriod; $d++) {
        $totalTrendLabels[] = (string) $d;
        $totalTrendCurrent[] = round($onlineByDay[$d] + $offlineByDay[$d] + $consignmentFlatPerDay);
        $totalTrendPrevious[] = round($onlinePrevByDay[$d] + $offlinePrevByDay[$d] + $consignmentFlatPerDay);
    }
} else { // month (ytd) — reuse $monthlyTrend (already Jan..current month) for "current";
    // query the same Jan..current-month window one year back for "previous".
    $onlineMonthRows = fetch_all($conn, "
        WITH " . ONLINE_ITEM_AGG_CTE . "
        SELECT MONTH(o.order_datetime) AS bucket, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
        FROM fact_online_orders o LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE o.order_datetime >= ? AND o.order_datetime < ?" . ONLINE_VALID_SALES_SQL . "
        GROUP BY MONTH(o.order_datetime);
    ", [$onlineTargetStart->format('Y-m-d'), $onlineTargetEnd->format('Y-m-d')]);
    $offlineMonthRows = fetch_all($conn, "
        SELECT ((f.DateKey / 100) % 100) AS bucket, SUM(f.NetTotal) AS netSales
        FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
        WHERE f.DateKey >= ? AND f.DateKey < ? AND " . RETAIL_BRANCH_SQL . " AND " . SELLABLE_PRODUCT_SQL . "
        GROUP BY ((f.DateKey / 100) % 100);
    ", [$offlineTargetStartKey, $offlineTargetEndKey]);

    $onlinePrevByMonth = array_fill(1, 12, 0.0);
    foreach ($onlineMonthRows as $row) { $onlinePrevByMonth[(int) $row['bucket']] = n($row['netSales']); }
    $offlinePrevByMonth = array_fill(1, 12, 0.0);
    foreach ($offlineMonthRows as $row) { $offlinePrevByMonth[(int) $row['bucket']] = n($row['netSales']); }

    foreach ($monthlyTrend as $index => $monthEntry) {
        $monthNumber = $index + 1; // $monthlyTrend is Jan..current when date_range=ytd
        $totalTrendLabels[] = $monthEntry['month'];
        $totalTrendCurrent[] = round($monthEntry['online'] + $monthEntry['offline'] + $monthEntry['consignment']);
        $totalTrendPrevious[] = round($onlinePrevByMonth[$monthNumber] + $offlinePrevByMonth[$monthNumber] + $monthEntry['consignment']);
    }
}

// Sales channel breakdown for donut chart
$salesChannelBreakdown = [
    ['name'=>'Online','revenue'=>$mockData['totalSales']['online'],'percent'=>round($mockData['totalSales']['online']/$mockData['totalSales']['total']*100,1),'color'=>'#dab937'],
    ['name'=>'Offline','revenue'=>$mockData['totalSales']['offline'],'percent'=>round($mockData['totalSales']['offline']/$mockData['totalSales']['total']*100,1),'color'=>'#4f8b98'],
    ['name'=>'Consignment (est.)','revenue'=>$mockData['totalSales']['consignment'],'percent'=>round($mockData['totalSales']['consignment']/$mockData['totalSales']['total']*100,1),'color'=>'#62307a']
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
$accentColor  = '#2f4e9d';
require_once __DIR__ . '/includes/header.php';

// หาค่าสูงสุดจากข้อมูลจริง (รวมทั้ง online และ offline) แทนตัวหาร hardcode เดิม
$maxTrendValue = max(array_merge(
    array_column($mockData['monthlyTrend'], 'online'),
    array_column($mockData['monthlyTrend'], 'offline')
));
?>
<style>
    .annual-goal-card {
        display: flex;
        align-items: stretch;
        border-radius: 12px;
        overflow: hidden;
        margin-bottom: 30px;
        box-shadow: 0 10px 28px rgba(12,18,32,0.16);
    }
    .annual-goal-left {
        flex: 0 0 390px;
        background: #091113;
        border-left: 4px solid var(--accent);
        color: #fff;
        padding: 28px 26px;
        display: flex;
        flex-direction: column;
        align-items: center;
        text-align: center;
        gap: 14px;
    }
    .annual-goal-headline { display: flex; align-items: center; justify-content: center; gap: 8px; align-self: stretch; }
    .annual-goal-live-dot {
        width: 7px; height: 7px; border-radius: 999px; background: #34d399; flex: 0 0 auto;
        box-shadow: 0 0 0 0 rgba(52,211,153,0.55);
        animation: goal-live-pulse 2.2s ease-out infinite;
    }
    @keyframes goal-live-pulse {
        0% { box-shadow: 0 0 0 0 rgba(52,211,153,0.55); }
        70% { box-shadow: 0 0 0 6px rgba(52,211,153,0); }
        100% { box-shadow: 0 0 0 0 rgba(52,211,153,0); }
    }
    @media (prefers-reduced-motion: reduce) {
        .annual-goal-live-dot { animation: none; }
    }
    .annual-goal-label { font-size: 12px; text-transform: uppercase; letter-spacing: 0.06em; font-weight: 600; color: rgba(255,255,255,0.85); }
    .annual-goal-label .est { font-weight: 400; color: rgba(255,255,255,0.5); text-transform: none; letter-spacing: normal; }
    .annual-goal-status { padding: 4px 14px; border-radius: 20px; font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.04em; }
    .annual-goal-status.is-on { background: rgba(78,125,87,0.22); color: #8FCB9A; }
    .annual-goal-status.is-off { background: rgba(178,58,46,0.22); color: #F0958A; }
    .annual-goal-donut-wrap { position: relative; width: 148px; height: 148px; margin: 6px 0; }
    .annual-goal-donut { width: 100%; height: 100%; }
    .annual-goal-donut .track { stroke: rgba(255,255,255,0.14); }
    .annual-goal-donut .progress { stroke: #2f4e9d; stroke-linecap: round; transition: stroke-dashoffset 0.6s ease; }
    .annual-goal-donut-center { position: absolute; inset: 0; display: flex; flex-direction: column; align-items: center; justify-content: center; }
    .annual-goal-donut-center .pct { font-size: 26px; font-weight: 300; color: #fff; font-variant-numeric: tabular-nums; }
    .annual-goal-donut-center .pct-label { font-size: 10px; color: rgba(255,255,255,0.6); text-transform: uppercase; letter-spacing: 0.05em; margin-top: 2px; }
    .annual-goal-target-line { font-size: 12px; color: rgba(255,255,255,0.65); line-height: 1.5; }
    .annual-goal-target-line b { color: #fff; font-weight: 600; font-variant-numeric: tabular-nums; }
    .annual-goal-right { flex: 1 1 auto; background: #fff; padding: 28px 30px; }
    .annual-goal-stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px 16px; }
    .annual-goal-stat { text-align: left; }
    .annual-goal-stat .stat-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.05em; font-weight: 600; margin-bottom: 6px; }
    .annual-goal-stat .stat-value { font-size: 22px; font-weight: 300; color: var(--text-primary); font-variant-numeric: tabular-nums; }
    .annual-goal-stat .stat-value.is-positive { color: #4E7D57; }
    .annual-goal-stat .stat-value.is-negative { color: #B23A2E; }
    .annual-goal-progress { margin-top: 26px; padding-top: 22px; border-top: 1px solid #F0F0F0; }
    .annual-goal-progress-label { display: flex; justify-content: space-between; font-size: 12px; color: var(--text-secondary); font-weight: 500; margin-bottom: 8px; }
    .annual-goal-progress-track { height: 10px; background: #F0F0F0; border-radius: 6px; overflow: hidden; }
    .annual-goal-progress-fill { height: 100%; border-radius: 6px; transition: width 0.5s ease; }
    @media (max-width: 900px) {
        .annual-goal-card { flex-direction: column; }
        .annual-goal-left { flex: 0 0 auto; }
        .annual-goal-stats-grid { grid-template-columns: repeat(2, 1fr); }
    }
    @media (max-width: 480px) {
        .annual-goal-stats-grid { grid-template-columns: 1fr 1fr; }
    }
    .row1-grid { display: grid; grid-template-columns: 3fr 2fr; gap: 20px; margin-bottom: 20px; }
    .row1-grid canvas { height: 320px; }
    .channel-legend-row { display: flex; align-items: center; justify-content: center; gap: 40px; padding: 20px 0; }
    .channel-donut-wrap { position: relative; width: 180px; height: 180px; flex-shrink: 0; }
    .channel-legend-col { flex: 1; display: flex; flex-direction: column; gap: 10px; min-width: 0; }
    .layer2-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 25px; }
    .kpi-grid { margin-top: 30px; }
    .kpi-grid .kpi-card { padding: 28px 25px; }
    .row2-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px; }
    .row2-grid canvas { height: 280px; }
    .row3-grid { display: grid; grid-template-columns: 1fr; gap: 20px; margin-bottom: 20px; }
    .row3-grid canvas { height: 220px; }
    .row1-grid > *, .row2-grid > *, .row3-grid > *, .layer2-grid > * { min-width: 0; }

    @media (max-width: 1100px) {
        .kpi-grid { grid-template-columns: repeat(2, 1fr); }
        .row1-grid, .row2-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .kpi-grid { grid-template-columns: 1fr; }
        .channel-legend-row { flex-direction: column; gap: 16px; }
        .layer2-grid { grid-template-columns: 1fr; }
        .row1-grid canvas { height: 240px; }
        .row2-grid canvas { height: 220px; }
        .row3-grid canvas { height: 180px; }
    }
</style>
        <!-- Annual Goal Tracking -->
<?php
// Same formulas as before the redesign — only presentation changed.
$gap = $mockData['goal']['currentMonth'] - $mockData['goal']['monthlyTarget'];
$achievement = ($mockData['goal']['monthlyTarget'] > 0) ? ($mockData['goal']['currentMonth'] / $mockData['goal']['monthlyTarget']) * 100 : 0;
$ytdPct = ($mockData['goal']['annual'] > 0) ? ($mockData['goal']['currentYear'] / $mockData['goal']['annual']) * 100 : 0;
$isProjectedAhead = $mockData['goal']['projected'] >= $mockData['goal']['annual'];

/**
 * $achievement is currentMonth (partial, month still in progress) over monthlyTarget
 * (the FULL month's target) — an honest % to show as a number, but coloring red
 * whenever it's under 100% means red lights up almost every day of the month by
 * construction (day 9 of 30 is naturally ~30%), regardless of whether pacing is
 * actually fine. Color decisions below use a pace-adjusted target instead (target
 * scaled by how much of the month has elapsed) — same fix as the channel bars in the
 * "Monthly Target Progress" section further down the page.
 */
$goalMaxDate = max($onlineMaxDate, $offlineMaxDate);
$goalDaysInMonth = (int) $goalMaxDate->format('t');
$goalDayOfMonth = (int) $goalMaxDate->format('j');
$pacedMonthlyTarget = $mockData['goal']['monthlyTarget'] * ($goalDayOfMonth / $goalDaysInMonth);
$monthlyPaceRatio = $pacedMonthlyTarget > 0 ? ($mockData['goal']['currentMonth'] / $pacedMonthlyTarget) * 100 : 100;
if ($monthlyPaceRatio >= 100) {
    $monthlyPaceColor = '#4E7D57';
} elseif ($monthlyPaceRatio >= 85) {
    $monthlyPaceColor = '#B45309';
} else {
    $monthlyPaceColor = '#EF4444';
}
// Was hardcoded `true` — the big status pill above the donut always said "On Track"
// regardless of real pacing, directly contradicting the now-correctly-colored bar
// below it in the same card. Wire it to the same pace math instead of a second,
// disconnected source of truth.
$mockData['goal']['onTrack'] = $monthlyPaceRatio >= 100;

$donutR = 60;
$donutCircumference = 2 * M_PI * $donutR;
$donutOffset = $donutCircumference * (1 - max(0, min(100, $ytdPct)) / 100);
?>
        <div class="dash-section" data-section-id="annual-goal" data-section-label-th="เป้าหมายรายปี" data-section-label-en="Annual Goal Tracking">
        <div class="annual-goal-card">
            <div class="annual-goal-left">
                <div class="annual-goal-headline">
                    <span class="annual-goal-live-dot"></span>
                    <span class="annual-goal-label">Annual Revenue Goal <span class="est">(incl. Consignment est.)</span></span>
                </div>
                <div class="annual-goal-status <?php echo $mockData['goal']['onTrack'] ? 'is-on' : 'is-off'; ?>">
                    <?php echo $mockData['goal']['onTrack'] ? 'On Track' : 'Off Track'; ?>
                </div>
                <div class="annual-goal-donut-wrap">
                    <svg class="annual-goal-donut" viewBox="0 0 140 140">
                        <circle class="track" cx="70" cy="70" r="<?php echo $donutR; ?>" stroke-width="12" fill="none" />
                        <circle class="progress" cx="70" cy="70" r="<?php echo $donutR; ?>" stroke-width="12" fill="none"
                            stroke-dasharray="<?php echo round($donutCircumference, 2); ?>"
                            stroke-dashoffset="<?php echo round($donutOffset, 2); ?>"
                            transform="rotate(-90 70 70)" />
                    </svg>
                    <div class="annual-goal-donut-center">
                        <div class="pct"><?php echo number_format($ytdPct, 1); ?>%</div>
                        <div class="pct-label">YTD Progress</div>
                    </div>
                </div>
                <div class="annual-goal-target-line">
                    Target <b>฿<?php echo number_format($mockData['goal']['annual']); ?></b><br>
                    Current Year <b>฿<?php echo number_format($mockData['goal']['currentYear']); ?></b>
                </div>
            </div>
            <div class="annual-goal-right">
                <div class="annual-goal-stats-grid">
                    <div class="annual-goal-stat">
                        <div class="stat-label">Current Year</div>
                        <div class="stat-value count-up" data-count-to="<?php echo (int) $mockData['goal']['currentYear']; ?>">0</div>
                    </div>
                    <div class="annual-goal-stat">
                        <div class="stat-label">Achievement</div>
                        <div class="stat-value count-up" style="color: <?php echo $monthlyPaceColor; ?>;" data-count-to="<?php echo $achievement; ?>" data-decimals="1" data-suffix="%">0%</div>
                    </div>
                    <div class="annual-goal-stat">
                        <div class="stat-label">Monthly Target</div>
                        <div class="stat-value count-up" data-count-to="<?php echo (int) $mockData['goal']['monthlyTarget']; ?>">0</div>
                    </div>
                    <div class="annual-goal-stat">
                        <div class="stat-label">Gap</div>
                        <div class="stat-value count-up <?php echo $gap >= 0 ? 'is-positive' : 'is-negative'; ?>" data-count-to="<?php echo (int) $gap; ?>">0</div>
                    </div>
                    <div class="annual-goal-stat">
                        <div class="stat-label">Current MTD</div>
                        <div class="stat-value count-up" data-count-to="<?php echo (int) $mockData['goal']['currentMonth']; ?>">0</div>
                    </div>
                    <div class="annual-goal-stat">
                        <div class="stat-label">Projected</div>
                        <div class="stat-value count-up <?php echo $isProjectedAhead ? 'is-positive' : 'is-negative'; ?>" data-count-to="<?php echo (int) $mockData['goal']['projected']; ?>">0</div>
                    </div>
                </div>
                <div class="annual-goal-progress">
                    <div class="annual-goal-progress-label">
                        <span>Monthly Target Progress</span>
                        <span><?php echo number_format(min(100, $achievement), 0); ?>%</span>
                    </div>
                    <div class="annual-goal-progress-track">
                        <div class="annual-goal-progress-fill" style="width: <?php echo max(0, min(100, $achievement)); ?>%; background: <?php echo $monthlyPaceColor; ?>;"></div>
                    </div>
                </div>
            </div>
        </div>
        </div>

        <!-- Monthly Target Progress -->
        <div class="dash-section" data-section-id="monthly-target-progress" data-section-label-th="ความคืบหน้าเป้าหมายรายเดือน" data-section-label-en="Monthly Target Progress">
        <div class="chart-card">
            <h3 style="margin-bottom: 20px;">Monthly Target Progress</h3>
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <?php
                $channels = [
                    ['name' => 'Online', 'current' => $mockData['monthlyActual']['online'], 'target' => $mockData['monthlyTargets']['online'], 'color' => '#dab937', 'isEstimate' => false, 'maxDate' => $onlineMaxDate],
                    ['name' => 'Offline', 'current' => $mockData['monthlyActual']['offline'], 'target' => $mockData['monthlyTargets']['offline'], 'color' => '#4f8b98', 'isEstimate' => false, 'maxDate' => $offlineMaxDate],
                    ['name' => 'Consignment', 'current' => $mockData['monthlyActual']['consignment'], 'target' => $mockData['monthlyTargets']['consignment'], 'color' => '#62307a', 'isEstimate' => true, 'maxDate' => $offlineMaxDate],
                ];
                foreach ($channels as $channel):
                    $progress = min(100, ($channel['current'] / $channel['target']) * 100);
                    /**
                     * "current" is this month SO FAR (partial month), but it used to be
                     * compared directly against the FULL month's target — so on day 9 of a
                     * 30-day month, current sits at ~30% of target and gets flagged "Behind"
                     * (red) even with perfectly normal pacing. Red would then light up almost
                     * every day of the month regardless of actual performance, only clearing on
                     * the last day or two. Fix: compare against a pace-adjusted target (target
                     * scaled by how much of the month has elapsed), same day-fraction idea as
                     * the month-end projection logic in dashboard_online.php.
                     */
                    $daysInMonth = (int) $channel['maxDate']->format('t');
                    $dayOfMonth = (int) $channel['maxDate']->format('j');
                    $pacedTarget = $channel['target'] * ($dayOfMonth / $daysInMonth);
                    $paceRatio = $pacedTarget > 0 ? ($channel['current'] / $pacedTarget) * 100 : 100;
                    if ($paceRatio >= 100) {
                        $statusColor = '#10b981';
                        $statusText = 'On Track';
                        $statusBg = '#d1fae5';
                    } elseif ($paceRatio >= 85) {
                        $statusColor = '#B45309';
                        $statusText = 'Slightly Behind Pace';
                        $statusBg = 'rgba(245,158,11,0.15)';
                    } else {
                        $statusColor = '#ef4444';
                        $statusText = 'Behind Pace';
                        $statusBg = '#fee2e2';
                    }
                    // Consignment has no real sales source yet — both figures are mock, so
                    // an "On Track"/"Behind" verdict here would be fabricated, not measured.
                    // Label says "Estimated" (not "mock") — that word is internal dev
                    // shorthand for placeholder data and means nothing to a viewer reading
                    // this dashboard; the real caveat lives in this comment instead.
                    if ($channel['isEstimate']) {
                        $statusColor = '#9CA3AF';
                        $statusText = 'Estimated';
                        $statusBg = '#F3F4F6';
                    }
                ?>
                <div>
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 8px;">
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <div style="width: 10px; height: 10px; border-radius: 2px; background: <?php echo $channel['color']; ?>;"></div>
                            <span style="font-size: 13px; color: #111827; font-weight: 600;"><?php echo $channel['name']; ?></span>
                        </div>
                        <div style="display: flex; align-items: center; gap: 15px;">
                            <span style="font-size: 12px; color: #6B7280;">Target: <?php echo number_format($channel['target'] / 1000000, 1); ?>M</span>
                            <span style="font-size: 11px; color: <?php echo $statusColor; ?>; font-weight: 600; background: <?php echo $statusBg; ?>; padding: 2px 8px; border-radius: 3px;"><?php echo $statusText; ?></span>
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
<div class="dash-section" data-section-id="kpi-cards" data-section-label-th="การ์ด KPI" data-section-label-en="KPI Cards">
<div class="kpi-grid">

    <div class="kpi-card" onclick="scrollToSection('channel-contribution')">
        <div class="tooltip">Total sales from all channels. Includes a Consignment estimate — no real consignment sales source exists yet.</div>
        <div class="label">Revenue <span style="color:#9CA3AF;font-weight:400;text-transform:none;">(incl. Consignment est.)</span></div>
        <div class="value">
            <?php echo number_format($mockData['totalSales']['total']); ?>
        </div>
        <?php echo growth_badge_html($mockData['growth']['total'], $growthSuffix); ?>
    </div>

    <div class="kpi-card" onclick="scrollToSection('top-products')">
        <div class="tooltip">Total number of orders (Online + Offline — Consignment has no real order count)</div>
        <div class="label">Orders</div>
        <div class="value">
            <?php echo number_format($mockData['orders']['total']); ?>
        </div>
        <?php echo growth_badge_html($mockData['growth']['orders'], $growthSuffix); ?>
    </div>

    <div class="kpi-card" onclick="scrollToSection('top-products')">
        <div class="tooltip">Average units per transaction (Units Sold ÷ Orders)</div>
        <div class="label">Units per Transaction</div>
        <div class="value">
            <?php echo number_format($mockData['upt'], 2); ?>
        </div>
        <?php echo growth_badge_html($mockData['growth']['upt'], $growthSuffix); ?>
    </div>

    <div class="kpi-card" onclick="scrollToSection('top-products')">
        <div class="tooltip">AOV = (Online + Offline Revenue) ÷ Orders. Consignment excluded — no real order count to divide by.</div>
        <div class="label">Average Order Value</div>
        <div class="value">
            <?php echo number_format($mockData['aov']); ?>
        </div>
        <?php echo growth_badge_html($mockData['growth']['aov'], $growthSuffix); ?>
    </div>

</div>
</div>

        <!-- Total Sales Trend: current vs previous period, granularity follows date_range -->
        <div class="dash-section" data-section-id="total-sales-trend" data-section-label-th="แนวโน้มยอดขายรวม" data-section-label-en="Total Sales Trend">
        <div class="chart-card">
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 4px;">
                <h3 style="margin: 0;">
                    <?php
                    $totalTrendSubtitle = match ($filterValues['date_range']) {
                        'today' => 'วันนี้',
                        'ytd' => 'ปีนี้',
                        default => 'เดือนนี้',
                    };
                    echo 'แนวโน้มยอดขาย · ' . htmlspecialchars($totalTrendSubtitle);
                    ?>
                </h3>
            </div>
            <div style="padding: 15px 0 0;">
                <canvas id="totalSalesTrendChart" style="max-height: 260px;"></canvas>
            </div>
        </div>
        </div>

        <!-- Row 1: Overview Trends and Channels -->
        <div class="dash-section" data-section-id="revenue-trend-channel-mix" data-section-label-th="แนวโน้มรายได้และสัดส่วนช่องทาง" data-section-label-en="Revenue Trend & Channel Mix">
        <div class="row1-grid">
            <!-- Monthly Revenue Trend (60%) -->
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Monthly Revenue Trend</h3>
                    <a href="#" style="font-size: 11px; color: #2f4e9d; text-decoration: none; font-weight: 500;">View Details →</a>
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
                                        <span style="display: inline-block; width: 20px; height: 20px; line-height: 20px; text-align: center; border-radius: 50%; background: #dab937; color: white; font-size: 10px; font-weight: 600;"><?php echo $index + 1; ?></span>
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
                                        <span style="display: inline-block; width: 20px; height: 20px; line-height: 20px; text-align: center; border-radius: 50%; background: <?php echo $location['type'] == 'offline' ? '#4f8b98' : '#62307a'; ?>; color: white; font-size: 10px; font-weight: 600;"><?php echo $index + 1; ?></span>
                                        <span style="font-size: 12px; color: #111827; font-weight: 500;"><?php echo htmlspecialchars($location['name']); ?></span>
                                        <span style="font-size: 9px; color: #9CA3AF; background: <?php echo $location['type'] == 'offline' ? '#f0f0f0' : '#fff3e0'; ?>; padding: 2px 6px; border-radius: 3px; text-transform: uppercase;"><?php echo $location['type'] == 'offline' ? 'offline' : 'consignment (est.)'; ?></span>
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
        </div>

        <!-- Row 2: Product and Geographic Dimensions -->
        <div class="dash-section" data-section-id="top-products-geo" data-section-label-th="สินค้าขายดีและการกระจายภูมิศาสตร์" data-section-label-en="Top Products & Geographic Distribution">
        <div class="row2-grid">
            <!-- Top 5 Online Products (50%) -->
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Top 5 Online Products</h3>
                    <a href="dashboard_online.php" style="font-size: 11px; color: #dab937; text-decoration: none; font-weight: 500;">View Details →</a>
                </div>
                <div style="padding: 10px 0;">
                    <canvas id="topProductsChart"></canvas>
                </div>
            </div>

            <!-- Geographic Sales Distribution (50%) -->
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Geographic Sales Distribution</h3>
                    <a href="dashboard_offline.php" style="font-size: 11px; color: #4f8b98; text-decoration: none; font-weight: 500;">View Details →</a>
                </div>
                <div style="padding: 10px 0;">
                    <canvas id="geoChoroplethChart"></canvas>
                </div>
            </div>
        </div>
        </div>

        <!-- Row 3: Top Offline Products (not affected by the Online platform cross-filter — no platform concept applies to retail branches) -->
        <div class="dash-section" data-section-id="top-offline-products" data-section-label-th="สินค้าขายดีหน้าร้าน" data-section-label-en="Top Offline Products">
        <div class="row3-grid">
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Top 5 Offline Products</h3>
                    <a href="dashboard_offline.php" style="font-size: 11px; color: #4f8b98; text-decoration: none; font-weight: 500;">View Details →</a>
                </div>
                <div style="padding: 10px 0;">
                    <canvas id="topOfflineProductsChart"></canvas>
                </div>
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
    // Channel filter (all/online/offline) hides the non-selected bars + the Total line
    // (redundant once isolated to one channel) instead of re-querying — same trend data,
    // just a different view of it.
    <?php
    $trendChannelFilter = $filterValues['channel'];
    $trendHideOnline = ($trendChannelFilter === 'offline');
    $trendHideOffline = ($trendChannelFilter === 'online');
    $trendHideConsignment = ($trendChannelFilter !== 'all');
    $trendHideTotal = ($trendChannelFilter !== 'all');
    ?>
    const monthlyRevenueCtx = document.getElementById('monthlyRevenueChart').getContext('2d');

    const monthlyRevenueData = {
        labels: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'month')); ?>,
        datasets: [
            {
                label: 'Online Revenue',
                data: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'online')); ?>,
                type: 'bar',
                backgroundColor: '#dab937',
                borderColor: '#dab937',
                borderWidth: 1,
                borderRadius: 4,
                order: 4,
                hidden: <?php echo json_encode($trendHideOnline); ?>
            },
            {
                label: 'Offline Revenue',
                data: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'offline')); ?>,
                type: 'bar',
                backgroundColor: '#4f8b98',
                borderColor: '#4f8b98',
                borderWidth: 1,
                borderRadius: 4,
                order: 3,
                hidden: <?php echo json_encode($trendHideOffline); ?>
            },
            {
                label: 'Consignment Revenue (est.)',
                data: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'consignment')); ?>,
                type: 'bar',
                backgroundColor: '#62307a',
                borderColor: '#62307a',
                borderWidth: 1,
                borderRadius: 4,
                order: 2,
                hidden: <?php echo json_encode($trendHideConsignment); ?>
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
                order: 1,
                hidden: <?php echo json_encode($trendHideTotal); ?>
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

    // Total Sales Trend Chart (Online + Offline + Consignment est.) - current vs
    // previous period, area line, granularity follows date_range (see PHP above)
    const totalSalesTrendCtx = document.getElementById('totalSalesTrendChart').getContext('2d');
    new Chart(totalSalesTrendCtx, {
        type: 'line',
        data: {
            labels: <?php echo json_encode($totalTrendLabels); ?>,
            datasets: [
                {
                    label: 'ช่วงนี้',
                    data: <?php echo json_encode($totalTrendCurrent); ?>,
                    borderColor: '#2f4e9d',
                    backgroundColor: 'rgba(47, 78, 157, 0.08)',
                    borderWidth: 2.5,
                    fill: true,
                    tension: 0.35,
                    pointRadius: 0,
                    pointHoverRadius: 4
                },
                {
                    label: 'ช่วงก่อน',
                    data: <?php echo json_encode($totalTrendPrevious); ?>,
                    borderColor: '#9CA3AF',
                    backgroundColor: 'transparent',
                    borderWidth: 1.5,
                    borderDash: [6, 4],
                    fill: false,
                    tension: 0.35,
                    pointRadius: 0,
                    pointHoverRadius: 4
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: { mode: 'index', intersect: false },
            plugins: {
                legend: {
                    display: true,
                    position: 'top',
                    align: 'end',
                    labels: { usePointStyle: true, padding: 15, font: { size: 12, weight: 500 }, color: '#111827' }
                },
                tooltip: {
                    callbacks: {
                        label: ctx => ctx.dataset.label + ': ' + new Intl.NumberFormat('th-TH').format(ctx.raw)
                    }
                }
            },
            scales: {
                x: { grid: { display: false }, ticks: { color: '#6B7280', font: { size: 11 }, maxRotation: 0, autoSkip: true } },
                y: {
                    beginAtZero: true,
                    ticks: {
                        maxTicksLimit: 5,
                        color: '#6B7280',
                        callback: value => value >= 1000000 ? (value / 1000000).toFixed(1) + 'M' : (value >= 1000 ? (value / 1000).toFixed(0) + 'K' : value)
                    },
                    grid: { color: 'rgba(0,0,0,0.05)', drawBorder: false }
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
                backgroundColor: '#dab937',
                borderColor: '#dab937',
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
                backgroundColor: '#4f8b98',
                borderColor: '#4f8b98',
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

</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
