<?php
session_start();

require_once __DIR__ . '/scripts/helpers.php';
initPerformanceSettings();

$dbServer = '203.154.130.236,1433';
$dbOptions = [
    'Database' => 'production_jst_api',
    'Uid' => 'sa',
    'PWD' => 'Journal@25',
    'CharacterSet' => 'UTF-8',
    'Encrypt' => false,
    'TrustServerCertificate' => true,
];

$conn = sqlsrv_connect($dbServer, $dbOptions);
if (!$conn) {
    die('Connection failed: ' . print_r(sqlsrv_errors(), true));
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

function money_short(float $value): string
{
    if (abs($value) >= 1000000) {
        return number_format($value / 1000000, 1) . 'M';
    }
    if (abs($value) >= 1000) {
        return number_format($value / 1000, 0) . 'K';
    }
    return number_format($value, 0);
}

function trend_label(float $value, string $language = 'en', string $suffix = ''): string
{
    $class = $value >= 0 ? 'positive' : 'negative';
    $word = $value >= 0 ? ($language === 'th' ? 'เพิ่มขึ้น' : 'Up') : ($language === 'th' ? 'ลดลง' : 'Down');
    if ($suffix === '') {
        $suffix = $language === 'th' ? 'เทียบช่วงเดียวกันปีก่อน' : 'vs last year';
    }
    return '<div class="change ' . $class . '">' . $word . ' ' . number_format(abs($value), 1) . '% ' . $suffix . '</div>';
}

function neutral_label(string $text): string
{
    return '<div class="change neutral">' . htmlspecialchars($text) . '</div>';
}

function request_value(string $key, string $default, array $allowed): string
{
    $value = isset($_GET[$key]) ? (string) $_GET[$key] : $default;
    return in_array($value, $allowed, true) ? $value : $default;
}

function product_group_case(string $column = 'productName'): string
{
    return "CASE
            WHEN {$column} LIKE '%Body Oil Sunscreen%' OR {$column} LIKE '%Sunscreen%' THEN 'body_oil_sunscreen'
            WHEN {$column} LIKE '%Body Oil%' THEN 'body_oil'
            WHEN {$column} LIKE '%Parfum%' OR {$column} LIKE '%Perfume%' THEN 'parfum'
            WHEN {$column} LIKE '%Body Mist%' THEN 'body_mist'
            WHEN {$column} LIKE '%Lip Oil%' THEN 'lip_oil'
            WHEN {$column} LIKE '%Hand Cream%' THEN 'hand_cream'
            ELSE 'other'
        END";
}

function product_group_label(string $key): string
{
    $labels = [
        'body_oil_sunscreen' => 'Body Oil Sunscreen',
        'body_oil' => 'Body Oil',
        'parfum' => 'Parfum',
        'body_mist' => 'Body Mist',
        'lip_oil' => 'Lip Oil',
        'hand_cream' => 'Hand Cream',
        'other' => 'Other',
    ];
    return $labels[$key] ?? $key;
}

function ui_text(array $ui, string $key): string
{
    return $ui[$key] ?? $key;
}

function product_image_html(?string $picture, string $sku): string
{
    $fallback = '<div class="product-thumb product-thumb-icon" aria-label="Image not available"><i class="fas fa-image"></i></div>';
    if ($picture === null || trim($picture) === '') {
        return '<div class="product-thumb-wrap">' . $fallback . '</div>';
    }

    $src = htmlspecialchars($picture, ENT_QUOTES, 'UTF-8');
    $alt = htmlspecialchars($sku, ENT_QUOTES, 'UTF-8');
    $fallbackJs = htmlspecialchars("this.replaceWith(Object.assign(document.createElement('div'), {className: 'product-thumb product-thumb-icon', innerHTML: '<i class=\"fas fa-image\"></i>'}));", ENT_QUOTES, 'UTF-8');

    return '<div class="product-thumb-wrap"><img class="product-thumb" src="' . $src . '" alt="' . $alt . '" loading="lazy" onerror="' . $fallbackJs . '"></div>';
}

function append_filter(array &$conditions, array &$params, string $condition, $value = null): void
{
    $conditions[] = $condition;
    if ($value !== null) {
        $params[] = $value;
    }
}

function repeated_params(array $base, array $filters, int $filterRepeats = 1): array
{
    $params = $base;
    for ($i = 0; $i < $filterRepeats; $i++) {
        foreach ($filters as $filterParam) {
            $params[] = $filterParam;
        }
    }
    return $params;
}

function params_for_periods(array $periodPairs, array $filters): array
{
    $params = [];
    foreach ($periodPairs as $pair) {
        $params[] = $pair[0];
        $params[] = $pair[1];
        foreach ($filters as $filterParam) {
            $params[] = $filterParam;
        }
    }
    return $params;
}

$dateInfo = fetch_one($conn, "
    SELECT
        CAST(MAX(paymentDate) AS date) AS maxDate,
        DATEFROMPARTS(YEAR(MAX(paymentDate)), MONTH(MAX(paymentDate)), 1) AS mtdStart
    FROM dbo.OrderSummary
");

$maxDate = $dateInfo['maxDate'];
$mtdStart = $dateInfo['mtdStart'];
$uiLanguage = request_value('lang', 'th', ['th', 'en']);
$translations = [
    'th' => [
        'page_title' => 'แดชบอร์ดยอดขายออนไลน์',
        'page_subtitle' => 'ติดตามผลการขายจากข้อมูลจริง production_jst_api',
        'all_platforms' => 'ทุกแพลตฟอร์ม',
        'today' => 'วันนี้',
        'mtd' => 'เดือนนี้',
        'ytd' => 'ปีนี้',
        'online' => 'ออนไลน์',
        'all_online' => 'ออนไลน์ทั้งหมด',
        'platform' => 'แพลตฟอร์ม',
        'all_categories' => 'ทุกหมวดหมู่',
        'body_oil_sunscreen' => 'Body Oil Sunscreen',
        'body_oil' => 'Body Oil',
        'parfum' => 'Parfum',
        'body_mist' => 'Body Mist',
        'lip_oil' => 'Lip Oil',
        'hand_cream' => 'Hand Cream',
        'other' => 'อื่นๆ',
        'all_campaigns' => 'ทุกแคมเปญ',
        'discounted' => 'มีส่วนลด',
        'no_discount' => 'ไม่มีส่วนลด',
        'high_discount' => 'ส่วนลดสูง',
        'all_sales' => 'ทุกประเภทการขาย',
        'normal' => 'ปกติ',
        'combined' => 'จัดชุด',
        'gift' => 'ของแถม',
        'language' => 'ภาษา',
        'thai' => 'ไทย',
        'english' => 'อังกฤษ',
        'hero_title' => 'ยอดขายสุทธิออนไลน์เดือนนี้',
        'source_ordersummary' => 'แหล่งข้อมูล: OrderSummary',
        'discount' => 'ส่วนลด',
        'orders' => 'ออเดอร์',
        'units_sold' => 'จำนวนชิ้นที่ขาย',
        'aov' => 'AOV',
        'upt' => 'UPT',
        'top_platform' => 'แพลตฟอร์มอันดับหนึ่ง',
        'top_sku' => 'SKU อันดับหนึ่ง',
        'units_per_transaction' => 'จำนวนชิ้นต่อออเดอร์',
        'performance_diagnosis' => 'วิเคราะห์ผลการดำเนินงาน',
        'manager_review' => 'สำหรับผู้จัดการช่องทางและสินค้า',
        'daily_sales_chart' => 'ยอดขายสุทธิรายวัน',
        'platform_share' => 'สัดส่วนยอดขายตามแพลตฟอร์ม',
        'payment_mix' => 'สัดส่วนวิธีชำระเงิน',
        'product_mix' => 'สัดส่วนสินค้า',
        'order_status' => 'สถานะออเดอร์',
        'platform_performance' => 'ผลการดำเนินงานตามแพลตฟอร์ม',
        'product' => 'สินค้า',
        'net_sales' => 'ยอดขายสุทธิ',
        'units' => 'ชิ้น',
        'disc_pct' => 'ส่วนลด %',
        'top_products' => 'สินค้าขายดี',
        'gift_excluded' => 'ไม่รวมรายการของแถม เรียงตามยอดขายสุทธิเดือนนี้',
        'daily_control' => 'ควบคุมงานรายวัน',
        'operator_followup' => 'สำหรับทีมปฏิบัติการติดตามต่อ',
        'today_sales' => 'ยอดขายวันนี้',
        'exception_orders' => 'ออเดอร์ที่ต้องติดตาม',
        'cancelled' => 'ยกเลิก',
        'return' => 'คืนสินค้า',
        'pending' => 'รอดำเนินการ',
        'low_cover_skus' => 'SKU สต็อกต่ำ',
        'under_14_days' => 'SKU ขายดีที่มีสต็อกครอบคลุมต่ำกว่า 14 วัน',
        'stock_coverage' => 'ความครอบคลุมสต็อกของ SKU ขายดี',
        'sales' => 'ยอดขาย',
        'stock' => 'สต็อก',
        'cover' => 'ครอบคลุม',
        'days' => 'วัน',
        'after_sales_queue' => 'คิวงานหลังการขาย',
        'status' => 'สถานะ',
        'type' => 'ประเภท',
        'cases' => 'เคส',
        'refund' => 'ยอดคืนเงิน',
        'chart_net_sales' => 'ยอดขายสุทธิ',
        'baseline_yoy' => 'เทียบช่วงเดียวกันปีก่อน',
        'baseline_mom' => 'เทียบช่วงเดียวกันเดือนก่อน',
        'baseline_wow' => 'เทียบวันเดียวกันสัปดาห์ก่อน',
        'no_baseline' => 'ยังไม่มีฐานปีก่อนให้เทียบ (ข้อมูลเริ่ม ธ.ค. 2025)',
        'excluded_note' => 'ไม่รวมยกเลิก/คืนสินค้า',
        'excluded_orders' => 'ออเดอร์',
    ],
    'en' => [
        'page_title' => 'Online Sales Dashboard',
        'page_subtitle' => 'Sales performance from production_jst_api',
        'all_platforms' => 'All Platforms',
        'today' => 'Today',
        'mtd' => 'MTD',
        'ytd' => 'YTD',
        'online' => 'Online',
        'all_online' => 'All Online',
        'platform' => 'Platform',
        'all_categories' => 'All Categories',
        'body_oil_sunscreen' => 'Body Oil Sunscreen',
        'body_oil' => 'Body Oil',
        'parfum' => 'Parfum',
        'body_mist' => 'Body Mist',
        'lip_oil' => 'Lip Oil',
        'hand_cream' => 'Hand Cream',
        'other' => 'Other',
        'all_campaigns' => 'All Campaigns',
        'discounted' => 'Discounted',
        'no_discount' => 'No Discount',
        'high_discount' => 'High Discount',
        'all_sales' => 'All Sales',
        'normal' => 'Normal',
        'combined' => 'Combined',
        'gift' => 'Gift',
        'language' => 'Language',
        'thai' => 'Thai',
        'english' => 'English',
        'hero_title' => 'MTD Online Net Sales',
        'source_ordersummary' => 'Source: OrderSummary',
        'discount' => 'Discount',
        'orders' => 'Orders',
        'units_sold' => 'Units Sold',
        'aov' => 'AOV',
        'upt' => 'UPT',
        'top_platform' => 'Top platform',
        'top_sku' => 'Top SKU',
        'units_per_transaction' => 'Units per transaction',
        'performance_diagnosis' => 'Performance Diagnosis',
        'manager_review' => 'For channel and merchandising managers',
        'daily_sales_chart' => 'Daily Net Sales',
        'platform_share' => 'Platform Sales Share',
        'payment_mix' => 'Payment Mix',
        'product_mix' => 'Product Mix',
        'order_status' => 'Order Status',
        'platform_performance' => 'Platform Performance',
        'product' => 'Product',
        'net_sales' => 'Net Sales',
        'units' => 'Units',
        'disc_pct' => 'Disc %',
        'top_products' => 'Top Products',
        'gift_excluded' => 'Gift lines excluded. Ranked by MTD net sales.',
        'daily_control' => 'Daily Control',
        'operator_followup' => 'For operator follow-up',
        'today_sales' => 'Today Sales',
        'exception_orders' => 'Exception Orders',
        'cancelled' => 'Cancelled',
        'return' => 'Return',
        'pending' => 'Pending',
        'low_cover_skus' => 'Low Cover SKUs',
        'under_14_days' => 'Top SKUs under 14 days cover',
        'stock_coverage' => 'Top SKU Stock Coverage',
        'sales' => 'Sales',
        'stock' => 'Stock',
        'cover' => 'Cover',
        'days' => 'days',
        'after_sales_queue' => 'After-Sales Queue',
        'status' => 'Status',
        'type' => 'Type',
        'cases' => 'Cases',
        'refund' => 'Refund',
        'chart_net_sales' => 'Net Sales',
        'baseline_yoy' => 'vs same period last year',
        'baseline_mom' => 'vs same period last month',
        'baseline_wow' => 'vs same day last week',
        'no_baseline' => 'No prior-year baseline yet (data starts Dec 2025)',
        'excluded_note' => 'Excl. cancelled/returns',
        'excluded_orders' => 'orders',
    ],
];
$ui = $translations[$uiLanguage];
$shopRows = fetch_all($conn, "
    SELECT shopName
    FROM dbo.OrderSummary
    WHERE NULLIF(shopName, '') IS NOT NULL
    GROUP BY shopName
    ORDER BY shopName;
");
$shopOptions = ['all' => ui_text($ui, 'all_platforms')];
foreach ($shopRows as $shopRow) {
    $shopOptions[(string) $shopRow['shopName']] = (string) $shopRow['shopName'];
}

$filterValues = [
    'lang' => $uiLanguage,
    'date_range' => request_value('date_range', 'mtd', ['today', 'mtd', 'ytd']),
    'channel' => request_value('channel', 'online', ['all', 'online']),
    'branch' => request_value('branch', 'all', array_keys($shopOptions)),
    'category' => request_value('category', 'all', ['all', 'body_oil_sunscreen', 'body_oil', 'parfum', 'body_mist', 'lip_oil', 'hand_cream', 'other']),
    'campaign' => request_value('campaign', 'all', ['all', 'discounted', 'no_discount', 'high_discount']),
    'sales_type' => request_value('sales_type', 'all', ['all', 'Normal', 'Combined', 'Gift']),
];
$filterOptions = [
    'date_range' => ['today' => ui_text($ui, 'today'), 'mtd' => ui_text($ui, 'mtd'), 'ytd' => ui_text($ui, 'ytd')],
    'channel' => ['online' => ui_text($ui, 'online'), 'all' => ui_text($ui, 'all_online')],
    'branch_label' => ui_text($ui, 'platform'),
    'branch' => $shopOptions,
    'category' => [
        'all' => ui_text($ui, 'all_categories'),
        'body_oil_sunscreen' => ui_text($ui, 'body_oil_sunscreen'),
        'body_oil' => ui_text($ui, 'body_oil'),
        'parfum' => ui_text($ui, 'parfum'),
        'body_mist' => ui_text($ui, 'body_mist'),
        'lip_oil' => ui_text($ui, 'lip_oil'),
        'hand_cream' => ui_text($ui, 'hand_cream'),
        'other' => ui_text($ui, 'other'),
    ],
    'campaign' => [
        'all' => ui_text($ui, 'all_campaigns'),
        'discounted' => ui_text($ui, 'discounted'),
        'no_discount' => ui_text($ui, 'no_discount'),
        'high_discount' => ui_text($ui, 'high_discount'),
    ],
    'sales_type' => [
        'all' => ui_text($ui, 'all_sales'),
        'Normal' => ui_text($ui, 'normal'),
        'Combined' => ui_text($ui, 'combined'),
        'Gift' => ui_text($ui, 'gift'),
    ],
];

$maxDateString = $maxDate->format('Y-m-d');
$maxDatePlusOne = (clone $maxDate)->modify('+1 day')->format('Y-m-d');
$currentYearStart = $maxDate->format('Y') . '-01-01';

if ($filterValues['date_range'] === 'today') {
    $periodStart = $maxDateString;
    $periodEnd = $maxDatePlusOne;
} elseif ($filterValues['date_range'] === 'ytd') {
    $periodStart = $currentYearStart;
    $periodEnd = $maxDatePlusOne;
} else {
    $periodStart = $mtdStart->format('Y-m-d');
    $periodEnd = $maxDatePlusOne;
}

/**
 * Growth baseline = the exact same-length window shifted back — never a full prior
 * period prorated by elapsed-day fraction (that produced wrong-sign badges; sales
 * cluster by weekday within a month).
 *
 * WHICH shift is chosen depends on data availability: OrderSummary only has real
 * volume from Dec 2025 (Jul 2025 holds ฿370K of partial-import rows vs ~฿40M/month
 * real — a YoY badge against that reads +10,000% and is pure noise). So YoY is used
 * only when the shifted window lands entirely in reliable data; otherwise fall back
 * to same-day-last-week (today) or same-span-last-month (MTD), and for YTD — which
 * has no honest short-shift equivalent — show a neutral "no baseline yet" badge.
 * Once the calendar passes Dec 2026 the MTD/today badges upgrade to YoY on their own.
 */
const RELIABLE_DATA_FROM = '2025-12-01';
$yoyStart = (new DateTime($periodStart))->modify('-1 year')->format('Y-m-d');
if ($yoyStart >= RELIABLE_DATA_FROM) {
    $baselineMode = 'yoy';
    $targetStart = $yoyStart;
    $targetEnd = (new DateTime($periodEnd))->modify('-1 year')->format('Y-m-d');
} elseif ($filterValues['date_range'] === 'today') {
    $baselineMode = 'wow';
    $targetStart = (new DateTime($periodStart))->modify('-7 days')->format('Y-m-d');
    $targetEnd = (new DateTime($periodEnd))->modify('-7 days')->format('Y-m-d');
} elseif ($filterValues['date_range'] === 'mtd') {
    $baselineMode = 'mom';
    $targetStart = (new DateTime($periodStart))->modify('-1 month')->format('Y-m-d');
    $targetEnd = (new DateTime($periodEnd))->modify('-1 month')->format('Y-m-d');
} else {
    $baselineMode = 'none';
    $targetStart = $yoyStart;
    $targetEnd = (new DateTime($periodEnd))->modify('-1 year')->format('Y-m-d');
}

$dayOfMonth = max(1, (int) ((new DateTime($periodStart))->diff(new DateTime($periodEnd))->days));
$periodLabel = (new DateTime($periodStart))->format('M j') . ' - ' . (new DateTime($periodEnd))->modify('-1 day')->format('M j, Y');
$productGroupSql = product_group_case('productName');
$filterConditions = [];
$filterParams = [];
if ($filterValues['branch'] !== 'all') {
    append_filter($filterConditions, $filterParams, 'shopName = ?', $filterValues['branch']);
}
if ($filterValues['category'] !== 'all') {
    append_filter($filterConditions, $filterParams, product_group_case('productName') . ' = ?', $filterValues['category']);
}
if ($filterValues['campaign'] === 'discounted') {
    append_filter($filterConditions, $filterParams, '(disShop + disVC) > 0');
} elseif ($filterValues['campaign'] === 'no_discount') {
    append_filter($filterConditions, $filterParams, '(disShop + disVC) = 0');
} elseif ($filterValues['campaign'] === 'high_discount') {
    append_filter($filterConditions, $filterParams, 'priceBeforeDisc > 0 AND ((disShop + disVC) * 100.0 / priceBeforeDisc) >= 20');
}
if ($filterValues['sales_type'] !== 'all') {
    append_filter($filterConditions, $filterParams, 'itemType = ?', $filterValues['sales_type']);
} else {
    // Default view claims "Gift lines excluded" (see the Top Products table note) but
    // previously didn't actually filter them out. Gift rows always carry netSale = 0, so
    // revenue/AOV/orders were never affected, but qty was — free/sample (NFS) giveaways
    // were counted as "units sold" (~10% inflation in spot checks) and fed into the stock
    // coverage day-count, understating real days of cover. Excluding by default here
    // matches the same intent as SELLABLE_PRODUCT_SQL on the offline dashboard. Picking
    // "Gift" explicitly from the Sales Type dropdown still works and overrides this.
    append_filter($filterConditions, $filterParams, "itemType <> 'Gift'");
}
$filterSql = $filterConditions ? ' AND ' . implode(' AND ', $filterConditions) : '';

/**
 * Cancelled/Return rows carry full netSale values (live check on a 30-day window:
 * ฿5.5M cancelled + ฿0.5M returned ≈ 15% of the raw total), so counting them
 * overstates every revenue/volume KPI. Applied to revenue queries only — the Order
 * Status chart and the Exception Orders card must keep seeing all statuses, that is
 * their whole job. The offline dashboard never had this problem (POS posts only
 * completed sales), so excluding here brings the two channels onto the same meaning
 * of "net sales".
 */
$validSalesSql = " AND orderStatus NOT IN ('Cancelled', 'Return')";

$summaryRows = fetch_all($conn, "
    SELECT
        'mtd' AS period,
        COUNT(DISTINCT orderId) AS orders,
        SUM(CAST(qty AS decimal(18,2))) AS units,
        SUM(netSale) AS netSales,
        SUM(priceBeforeDisc) AS grossSales,
        SUM(disShop + disVC) AS discount
    FROM dbo.OrderSummary
    WHERE paymentDate >= ? AND paymentDate < ? {$filterSql}{$validSalesSql}

    UNION ALL

    SELECT
        'prev_mtd' AS period,
        COUNT(DISTINCT orderId) AS orders,
        SUM(CAST(qty AS decimal(18,2))) AS units,
        SUM(netSale) AS netSales,
        SUM(priceBeforeDisc) AS grossSales,
        SUM(disShop + disVC) AS discount
    FROM dbo.OrderSummary
    WHERE paymentDate >= ? AND paymentDate < ? {$filterSql}{$validSalesSql};
", params_for_periods([
    [$periodStart, $periodEnd],
    [$targetStart, $targetEnd],
], $filterParams));

$summary = ['mtd' => [], 'prev_mtd' => []];
foreach ($summaryRows as $row) {
    $summary[$row['period']] = $row;
}

$mtdNetSales = n($summary['mtd']['netSales'] ?? 0);
$targetBaseNetSales = n($summary['prev_mtd']['netSales'] ?? 0);
$mtdOrders = n($summary['mtd']['orders'] ?? 0);
$targetBaseOrders = n($summary['prev_mtd']['orders'] ?? 0);
$mtdUnits = n($summary['mtd']['units'] ?? 0);
$targetBaseUnits = n($summary['prev_mtd']['units'] ?? 0);
$mtdGross = n($summary['mtd']['grossSales'] ?? 0);
$mtdDiscount = n($summary['mtd']['discount'] ?? 0);

// "prev*" is the exact same-length period one year ago (see $targetStart/$targetEnd
// above) — used only to drive the YoY growth % badges.
$prevNetSales = $targetBaseNetSales;
$prevOrders = $targetBaseOrders;
$prevUnits = $targetBaseUnits;
$mtdAov = $mtdOrders > 0 ? $mtdNetSales / $mtdOrders : 0;
$prevAov = $targetBaseOrders > 0 ? $targetBaseNetSales / $targetBaseOrders : 0;
$mtdUpt = $mtdOrders > 0 ? $mtdUnits / $mtdOrders : 0;

$dailyTrend = fetch_all($conn, "
    SELECT
        CONVERT(varchar(10), CAST(paymentDate AS date), 120) AS saleDate,
        COUNT(DISTINCT orderId) AS orders,
        SUM(qty) AS units,
        SUM(netSale) AS netSales,
        SUM(disShop + disVC) AS discount
    FROM dbo.OrderSummary
    WHERE paymentDate >= ? AND paymentDate < ? {$filterSql}{$validSalesSql}
    GROUP BY CAST(paymentDate AS date)
    ORDER BY CAST(paymentDate AS date);
", repeated_params([$periodStart, $periodEnd], $filterParams));

$todaySales = 0.0;
if (!empty($dailyTrend)) {
    $lastDailyRow = $dailyTrend[count($dailyTrend) - 1];
    $todaySales = n($lastDailyRow['netSales'] ?? 0);
}

$platforms = fetch_all($conn, "
    SELECT
        shopName,
        COUNT(DISTINCT orderId) AS orders,
        SUM(qty) AS units,
        SUM(netSale) AS netSales,
        SUM(priceBeforeDisc) AS grossSales,
        SUM(disShop + disVC) AS discount
    FROM dbo.OrderSummary
    WHERE paymentDate >= ? AND paymentDate < ? {$filterSql}{$validSalesSql}
    GROUP BY shopName
    ORDER BY netSales DESC;
", repeated_params([$periodStart, $periodEnd], $filterParams));

$payments = fetch_all($conn, "
    SELECT TOP 10
        CASE
            WHEN paymentMethod LIKE '%COD%' THEN 'COD'
            WHEN paymentMethod LIKE '%PayLater%' OR paymentMethod LIKE '%PAY_LATER%' OR paymentMethod LIKE '%SPayLater%' THEN 'Pay Later'
            WHEN paymentMethod LIKE '%Credit%' OR paymentMethod LIKE '%card%' OR paymentMethod LIKE '%CARD%' OR paymentMethod LIKE '%2C2P%' THEN 'Card'
            WHEN paymentMethod LIKE '%PromptPay%' OR paymentMethod LIKE '%PROMPTPAY%' THEN 'PromptPay'
            WHEN paymentMethod LIKE '%Banking%' OR paymentMethod LIKE '%Mbanking%' THEN 'Mobile Banking'
            WHEN paymentMethod LIKE '%ShopeePay%' OR paymentMethod LIKE '%TrueMoney%' OR paymentMethod LIKE '%Balance%' THEN 'Wallet'
            ELSE COALESCE(NULLIF(paymentMethod, ''), 'Unknown')
        END AS paymentGroup,
        COUNT(DISTINCT orderId) AS orders,
        SUM(netSale) AS netSales
    FROM dbo.OrderSummary
    WHERE paymentDate >= ? AND paymentDate < ? {$filterSql}{$validSalesSql}
    GROUP BY CASE
            WHEN paymentMethod LIKE '%COD%' THEN 'COD'
            WHEN paymentMethod LIKE '%PayLater%' OR paymentMethod LIKE '%PAY_LATER%' OR paymentMethod LIKE '%SPayLater%' THEN 'Pay Later'
            WHEN paymentMethod LIKE '%Credit%' OR paymentMethod LIKE '%card%' OR paymentMethod LIKE '%CARD%' OR paymentMethod LIKE '%2C2P%' THEN 'Card'
            WHEN paymentMethod LIKE '%PromptPay%' OR paymentMethod LIKE '%PROMPTPAY%' THEN 'PromptPay'
            WHEN paymentMethod LIKE '%Banking%' OR paymentMethod LIKE '%Mbanking%' THEN 'Mobile Banking'
            WHEN paymentMethod LIKE '%ShopeePay%' OR paymentMethod LIKE '%TrueMoney%' OR paymentMethod LIKE '%Balance%' THEN 'Wallet'
            ELSE COALESCE(NULLIF(paymentMethod, ''), 'Unknown')
        END
    ORDER BY netSales DESC;
", repeated_params([$periodStart, $periodEnd], $filterParams));

$productMix = fetch_all($conn, "
    SELECT
        {$productGroupSql} AS productGroup,
        SUM(qty) AS units,
        SUM(netSale) AS netSales,
        SUM(disShop + disVC) AS discount
    FROM dbo.OrderSummary
    WHERE paymentDate >= ? AND paymentDate < ? {$filterSql}{$validSalesSql}
    GROUP BY {$productGroupSql}
    ORDER BY netSales DESC;
", repeated_params([$periodStart, $periodEnd], $filterParams));

$topProducts = fetch_all($conn, "
    WITH product_sales AS (
        SELECT TOP 15
            sku,
            productName,
            SUM(qty) AS units,
            SUM(netSale) AS netSales,
            SUM(disShop + disVC) AS discount,
            CASE WHEN SUM(priceBeforeDisc) > 0 THEN SUM(disShop + disVC) * 100.0 / SUM(priceBeforeDisc) ELSE 0 END AS discountRate
        FROM dbo.OrderSummary
        WHERE paymentDate >= ? AND paymentDate < ? {$filterSql}{$validSalesSql}
        GROUP BY sku, productName
        ORDER BY netSales DESC
    ),
    sku_picture AS (
        SELECT
            skuId,
            MAX(NULLIF(picture, '')) AS picture
        FROM dbo.GetItemSkus
        WHERE NULLIF(picture, '') IS NOT NULL
        GROUP BY skuId
    )
    SELECT
        p.sku,
        p.productName,
        p.units,
        p.netSales,
        p.discount,
        p.discountRate,
        s.picture
    FROM product_sales p
    LEFT JOIN sku_picture s ON p.sku = s.skuId
    ORDER BY p.netSales DESC;
", repeated_params([$periodStart, $periodEnd], $filterParams));

$statusRows = fetch_all($conn, "
    SELECT
        orderStatus,
        COUNT(DISTINCT orderId) AS orders,
        SUM(netSale) AS netSales
    FROM dbo.OrderSummary
    WHERE paymentDate >= ? AND paymentDate < ? {$filterSql}
    GROUP BY orderStatus
    ORDER BY netSales DESC;
", repeated_params([$periodStart, $periodEnd], $filterParams));

$statusChartRows = array_values(array_filter($statusRows, function ($row) {
    return in_array($row['orderStatus'], ['Delivered', 'Cancelled'], true);
}));

$coverageRows = fetch_all($conn, "
    WITH mtdSku AS (
        SELECT TOP 15 sku, productName, SUM(qty) AS units, SUM(netSale) AS netSales
        FROM dbo.OrderSummary
        WHERE paymentDate >= ? AND paymentDate < ? {$filterSql}{$validSalesSql}
        GROUP BY sku, productName
        ORDER BY netSales DESC
    ),
    inv AS (
        SELECT skuId, SUM(qty - pickLock - defectiveQty) AS availableQty
        FROM dbo.GetWarehouseSkuInventorys
        GROUP BY skuId
    ),
    sku_picture AS (
        SELECT
            skuId,
            MAX(NULLIF(picture, '')) AS picture
        FROM dbo.GetItemSkus
        WHERE NULLIF(picture, '') IS NOT NULL
        GROUP BY skuId
    )
    SELECT
        m.sku,
        m.productName,
        m.units,
        m.netSales,
        COALESCE(i.availableQty, 0) AS availableQty,
        CASE WHEN m.units > 0 THEN COALESCE(i.availableQty, 0) / (m.units * 1.0 / ?) ELSE NULL END AS daysCover,
        s.picture
    FROM mtdSku m
    LEFT JOIN inv i ON m.sku = i.skuId
    LEFT JOIN sku_picture s ON m.sku = s.skuId
    ORDER BY m.netSales DESC;
", array_merge(repeated_params([$periodStart, $periodEnd], $filterParams), [$dayOfMonth]));

$afterSales = fetch_all($conn, "
    SELECT TOP 8
        status,
        afterSaleType,
        COUNT(DISTINCT afterSaleOrderId) AS cases,
        SUM(refundAmount) AS refundAmount
    FROM dbo.GetAfterSaleOrders
    WHERE DATEADD(second, orderPayTime, '1970-01-01') >= ?
      AND DATEADD(second, orderPayTime, '1970-01-01') < ?
    GROUP BY status, afterSaleType
    ORDER BY refundAmount DESC;
", [$periodStart, $periodEnd]);

$salesGrowth = pct_change($mtdNetSales, $prevNetSales);
$orderGrowth = pct_change($mtdOrders, $prevOrders);
$unitGrowth = pct_change($mtdUnits, $prevUnits);
$aovGrowth = pct_change($mtdAov, $prevAov);

$growthBadge = function (float $growth) use ($baselineMode, $uiLanguage, $ui): string {
    if ($baselineMode === 'none') {
        return neutral_label(ui_text($ui, 'no_baseline'));
    }
    return trend_label($growth, $uiLanguage, ui_text($ui, 'baseline_' . $baselineMode));
};

// Money the KPIs deliberately leave out (see $validSalesSql) — surfaced in the hero
// so the total doesn't silently drop ~15% vs what people saw before the fix.
$excludedNet = 0.0;
$excludedOrders = 0;
foreach ($statusRows as $row) {
    if (in_array((string) $row['orderStatus'], ['Cancelled', 'Return'], true)) {
        $excludedNet += n($row['netSales']);
        $excludedOrders += (int) $row['orders'];
    }
}

$cancelled = 0;
$returns = 0;
$pending = 0;
foreach ($statusRows as $row) {
    $status = (string) $row['orderStatus'];
    if ($status === 'Cancelled') {
        $cancelled += (int) $row['orders'];
    } elseif ($status === 'Return') {
        $returns += (int) $row['orders'];
    } elseif (!in_array($status, ['Delivered', 'Cancelled', 'Return'], true)) {
        $pending += (int) $row['orders'];
    }
}

$lowCoverage = array_values(array_filter($coverageRows, function ($row) {
    return n($row['daysCover']) > 0 && n($row['daysCover']) < 14;
}));

$topPlatform = $platforms[0] ?? null;
$topProduct = $topProducts[0] ?? null;

$pageTitle = ui_text($ui, 'page_title');
$pageSubtitle = ui_text($ui, 'page_subtitle');
$accentColor = '#c9a227';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .online-hero { background: #111827; color: #fff; padding: 28px; border-radius: 8px; margin-bottom: 24px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .online-hero-top { display: flex; justify-content: space-between; gap: 24px; align-items: flex-start; }
    .online-hero-title { font-size: 13px; color: rgba(255,255,255,0.72); text-transform: uppercase; font-weight: 600; }
    .online-hero-value { font-size: 44px; line-height: 1.1; font-weight: 300; margin-top: 8px; }
    .online-hero-trend { margin-top: 8px; font-size: 13px; }
    .online-hero-meta { text-align: right; color: rgba(255,255,255,0.78); font-size: 13px; }
    .metric-grid .kpi-card .target-line { color: #6B7280; font-size: 12px; line-height: 1.45; margin-top: 8px; }
    .change.positive { color: #10B981; }
    .change.negative { color: #EF4444; }
    .change.neutral { color: #9CA3AF; }
    .section-title { display: flex; justify-content: space-between; align-items: center; margin: 6px 0 14px; }
    .section-title h2 { margin: 0; font-size: 16px; font-weight: 600; color: #111827; }
    .section-title span { font-size: 12px; color: #6B7280; }
    .metric-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .analysis-grid { display: grid; grid-template-columns: 1.35fr 1fr; gap: 20px; margin-bottom: 24px; }
    .three-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
    .ops-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
    .ops-card { background: #fff; border-radius: 8px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .ops-card .label { color: #6B7280; font-size: 12px; font-weight: 600; text-transform: uppercase; }
    .ops-card .value { color: #111827; font-size: 32px; font-weight: 300; margin: 8px 0 4px; }
    .ops-card .note { color: #9CA3AF; font-size: 12px; }
    .chart-box { height: 300px; }
    .chart-box.small { height: 240px; }
    .status-pill { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; background: #F3F4F6; color: #6B7280; }
    .status-pill.high { background: rgba(239,68,68,0.12); color: #EF4444; }
    .status-pill.medium { background: rgba(245,158,11,0.15); color: #B45309; }
    .status-pill.low { background: rgba(16,185,129,0.12); color: #047857; }
    .table-note { color: #9CA3AF; font-size: 11px; margin-top: -10px; margin-bottom: 14px; }
    .product-cell { display: flex; align-items: center; gap: 12px; min-width: 260px; }
    .product-thumb-wrap { position: relative; width: 48px; height: 48px; flex: 0 0 auto; }
    .product-thumb { width: 48px; height: 48px; border-radius: 6px; object-fit: cover; background: #F3F4F6; border: 1px solid #E5E7EB; transition: transform 0.16s ease, box-shadow 0.16s ease; }
    .product-thumb-wrap:hover .product-thumb { position: absolute; z-index: 20; transform: scale(2.35); transform-origin: left center; box-shadow: 0 12px 30px rgba(0,0,0,0.22); border-color: #fff; }
    .product-thumb-icon { display: flex; align-items: center; justify-content: center; color: #9CA3AF; font-size: 20px; background: linear-gradient(135deg, #F9FAFB, #EEF2F7); }
    .product-meta { min-width: 0; }
    .product-meta .sku { color: #6B7280; font-size: 11px; font-weight: 600; margin-bottom: 3px; }
    .product-meta .name { color: #111827; font-size: 13px; line-height: 1.35; }
    @media (max-width: 1100px) {
        .metric-grid, .ops-grid { grid-template-columns: repeat(2, 1fr); }
        .analysis-grid, .three-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .online-hero-top { flex-direction: column; }
        .online-hero-meta { text-align: left; }
        .metric-grid, .ops-grid { grid-template-columns: 1fr; }
        .online-hero-value { font-size: 34px; }
    }
</style>

<div class="online-hero">
    <div class="online-hero-top">
        <div>
            <div class="online-hero-title"><?php echo htmlspecialchars(ui_text($ui, 'hero_title')); ?></div>
            <div class="online-hero-value"><?php echo number_format($mtdNetSales, 0); ?></div>
            <div class="online-hero-trend"><?php echo $growthBadge($salesGrowth); ?></div>
        </div>
        <div class="online-hero-meta">
            <div><?php echo htmlspecialchars($periodLabel); ?></div>
            <div><?php echo htmlspecialchars(ui_text($ui, 'source_ordersummary')); ?></div>
            <?php if ($excludedNet > 0 || $excludedOrders > 0): ?>
                <div><?php echo htmlspecialchars(ui_text($ui, 'excluded_note')); ?> <?php echo number_format($excludedNet, 0); ?> (<?php echo number_format($excludedOrders); ?> <?php echo htmlspecialchars(ui_text($ui, 'excluded_orders')); ?>)</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="metric-grid">
    <div class="kpi-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'orders')); ?></div>
        <div class="value"><?php echo number_format($mtdOrders, 0); ?></div>
        <?php echo $growthBadge($orderGrowth); ?>
    </div>
    <div class="kpi-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'units_sold')); ?></div>
        <div class="value"><?php echo number_format($mtdUnits, 0); ?></div>
        <?php echo $growthBadge($unitGrowth); ?>
    </div>
    <div class="kpi-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'aov')); ?></div>
        <div class="value"><?php echo number_format($mtdAov, 0); ?></div>
        <?php echo $growthBadge($aovGrowth); ?>
    </div>
    <div class="kpi-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'upt')); ?></div>
        <div class="value"><?php echo number_format($mtdUpt, 2); ?></div>
        <div class="change positive"><?php echo htmlspecialchars(ui_text($ui, 'units_per_transaction')); ?></div>
        <div class="target-line"><?php echo htmlspecialchars(ui_text($ui, 'top_platform')); ?> <?php echo htmlspecialchars($topPlatform['shopName'] ?? '-'); ?> | <?php echo htmlspecialchars(ui_text($ui, 'top_sku')); ?> <?php echo htmlspecialchars($topProduct['sku'] ?? '-'); ?></div>
    </div>
</div>

<div class="section-title">
    <h2><?php echo htmlspecialchars(ui_text($ui, 'performance_diagnosis')); ?></h2>
    <span><?php echo htmlspecialchars(ui_text($ui, 'manager_review')); ?></span>
</div>
<div class="analysis-grid">
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'daily_sales_chart')); ?></h3>
        <div class="chart-box"><canvas id="dailySalesChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'platform_share')); ?></h3>
        <div class="chart-box"><canvas id="platformShareChart"></canvas></div>
    </div>
</div>

<div class="three-grid">
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'payment_mix')); ?></h3>
        <div class="chart-box small"><canvas id="paymentChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'product_mix')); ?></h3>
        <div class="chart-box small"><canvas id="productMixChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'order_status')); ?></h3>
        <div class="chart-box small"><canvas id="statusChart"></canvas></div>
    </div>
</div>

<div class="split-grid">
    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'platform_performance')); ?></h3>
        <table>
            <thead>
            <tr>
                <th><?php echo htmlspecialchars(ui_text($ui, 'platform')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'net_sales')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'orders')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'aov')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($platforms as $row): ?>
                <?php $orders = n($row['orders']); ?>
                <?php $platformActual = n($row['netSales']); ?>
                <tr>
                    <td><span class="badge online"><?php echo htmlspecialchars($row['shopName']); ?></span></td>
                    <td><?php echo number_format($platformActual, 0); ?></td>
                    <td><?php echo number_format($orders, 0); ?></td>
                    <td><?php echo number_format($orders > 0 ? $platformActual / $orders : 0, 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'top_products')); ?></h3>
        <div class="table-note"><?php echo htmlspecialchars(ui_text($ui, 'gift_excluded')); ?></div>
        <table>
            <thead>
            <tr>
                <th><?php echo htmlspecialchars(ui_text($ui, 'product')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'net_sales')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'units')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'disc_pct')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($topProducts, 0, 8) as $row): ?>
                <tr>
                    <td>
                        <div class="product-cell">
                            <?php echo product_image_html($row['picture'] ?? null, (string) $row['sku']); ?>
                            <div class="product-meta">
                                <div class="sku"><?php echo htmlspecialchars($row['sku']); ?></div>
                                <div class="name"><?php echo htmlspecialchars(mb_strimwidth($row['productName'], 0, 52, '...')); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo number_format(n($row['netSales']), 0); ?></td>
                    <td><?php echo number_format(n($row['units']), 0); ?></td>
                    <td><?php echo number_format(n($row['discountRate']), 1); ?>%</td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="section-title">
    <h2><?php echo htmlspecialchars(ui_text($ui, 'daily_control')); ?></h2>
    <span><?php echo htmlspecialchars(ui_text($ui, 'operator_followup')); ?></span>
</div>
<div class="ops-grid">
    <div class="ops-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'today_sales')); ?></div>
        <div class="value"><?php echo number_format($todaySales, 0); ?></div>
    </div>
    <div class="ops-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'exception_orders')); ?></div>
        <div class="value"><?php echo number_format($cancelled + $returns + $pending); ?></div>
        <div class="note"><?php echo htmlspecialchars(ui_text($ui, 'cancelled')); ?> <?php echo number_format($cancelled); ?> | <?php echo htmlspecialchars(ui_text($ui, 'return')); ?> <?php echo number_format($returns); ?> | <?php echo htmlspecialchars(ui_text($ui, 'pending')); ?> <?php echo number_format($pending); ?></div>
    </div>
    <div class="ops-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'low_cover_skus')); ?></div>
        <div class="value"><?php echo number_format(count($lowCoverage)); ?></div>
        <div class="note"><?php echo htmlspecialchars(ui_text($ui, 'under_14_days')); ?></div>
    </div>
</div>

<div class="split-grid">
    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'stock_coverage')); ?></h3>
        <table>
            <thead>
            <tr>
                <th><?php echo htmlspecialchars(ui_text($ui, 'product')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'sales')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'stock')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'cover')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($coverageRows, 0, 10) as $row): ?>
                <?php $cover = n($row['daysCover']); ?>
                <tr>
                    <td>
                        <div class="product-cell">
                            <?php echo product_image_html($row['picture'] ?? null, (string) $row['sku']); ?>
                            <div class="product-meta">
                                <div class="sku"><?php echo htmlspecialchars($row['sku']); ?></div>
                                <div class="name"><?php echo htmlspecialchars(mb_strimwidth($row['productName'], 0, 52, '...')); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo number_format(n($row['netSales']), 0); ?></td>
                    <td><?php echo number_format(n($row['availableQty']), 0); ?></td>
                    <td><span class="status-pill <?php echo $cover < 14 ? 'high' : ($cover < 21 ? 'medium' : 'low'); ?>"><?php echo number_format($cover, 1); ?> <?php echo htmlspecialchars(ui_text($ui, 'days')); ?></span></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'after_sales_queue')); ?></h3>
        <table>
            <thead>
            <tr>
                <th><?php echo htmlspecialchars(ui_text($ui, 'status')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'type')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'cases')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'refund')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($afterSales as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['status']); ?></td>
                    <td><?php echo htmlspecialchars($row['afterSaleType']); ?></td>
                    <td><?php echo number_format(n($row['cases']), 0); ?></td>
                    <td><?php echo number_format(n($row['refundAmount']), 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
const chartColors = ['#c9a227', '#1a1a2e', '#10B981', '#3B82F6', '#EF4444', '#F59E0B', '#6B7280'];
const numberFormat = new Intl.NumberFormat('<?php echo $uiLanguage === 'th' ? 'th-TH' : 'en-US'; ?>');

window.updateDashboardData = function () {
    document.querySelector('.filter-bar').submit();
};

function shortNumber(value) {
    if (Math.abs(value) >= 1000000) return (value / 1000000).toFixed(1) + 'M';
    if (Math.abs(value) >= 1000) return (value / 1000).toFixed(0) + 'K';
    return value;
}

new Chart(document.getElementById('dailySalesChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($dailyTrend, 'saleDate')); ?>,
        datasets: [
            {
                label: <?php echo json_encode(ui_text($ui, 'chart_net_sales')); ?>,
                data: <?php echo json_encode(array_map('n', array_column($dailyTrend, 'netSales'))); ?>,
                backgroundColor: '#c9a227',
                borderWidth: 0,
                borderRadius: 4
            },
            {
                label: <?php echo json_encode(ui_text($ui, 'discount')); ?>,
                data: <?php echo json_encode(array_map('n', array_column($dailyTrend, 'discount'))); ?>,
                backgroundColor: '#EF4444',
                borderWidth: 0,
                borderRadius: 4
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', align: 'end', labels: { usePointStyle: true } },
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + numberFormat.format(ctx.raw) } }
        },
        scales: {
            x: { stacked: true, grid: { display: false }, ticks: { color: '#6B7280', maxRotation: 45 } },
            y: { stacked: false, ticks: { callback: shortNumber, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } }
        }
    }
});

new Chart(document.getElementById('platformShareChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($platforms, 'shopName')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_map('n', array_column($platforms, 'netSales'))); ?>,
            backgroundColor: chartColors,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } }
    }
});

new Chart(document.getElementById('paymentChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($payments, 'paymentGroup')); ?>,
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'chart_net_sales')); ?>,
            data: <?php echo json_encode(array_map('n', array_column($payments, 'netSales'))); ?>,
            backgroundColor: '#1a1a2e',
            borderWidth: 0,
            borderRadius: 4
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { ticks: { callback: shortNumber, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } },
            y: { grid: { display: false }, ticks: { color: '#111827' } }
        }
    }
});

new Chart(document.getElementById('productMixChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map(function ($key) use ($filterOptions) {
            return $filterOptions['category'][$key] ?? product_group_label($key);
        }, array_column($productMix, 'productGroup'))); ?>,
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'chart_net_sales')); ?>,
            data: <?php echo json_encode(array_map('n', array_column($productMix, 'netSales'))); ?>,
            backgroundColor: chartColors,
            borderWidth: 0,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#6B7280', maxRotation: 35 } },
            y: { ticks: { callback: shortNumber, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } }
        }
    }
});

new Chart(document.getElementById('statusChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_column($statusChartRows, 'orderStatus')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_map('n', array_column($statusChartRows, 'orders'))); ?>,
            backgroundColor: chartColors,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } }
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
