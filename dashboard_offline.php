<?php
session_start();

require_once __DIR__ . '/scripts/helpers.php';
initPerformanceSettings();

require_once __DIR__ . '/database.php';
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

function trend_label(float $value, string $language = 'en'): string
{
    $class = $value >= 0 ? 'positive' : 'negative';
    $word = $value >= 0 ? ($language === 'th' ? 'เพิ่มขึ้น' : 'Up') : ($language === 'th' ? 'ลดลง' : 'Down');
    $suffix = $language === 'th' ? 'เทียบช่วงเดียวกันปีก่อน' : 'vs last year';
    return '<div class="change ' . $class . '">' . $word . ' ' . number_format(abs($value), 1) . '% ' . $suffix . '</div>';
}

function money(float $value, int $decimals = 0): string
{
    return '฿' . number_format($value, $decimals);
}

function request_value(string $key, string $default, array $allowed): string
{
    $value = isset($_GET[$key]) ? (string) $_GET[$key] : $default;
    return in_array($value, $allowed, true) ? $value : $default;
}

function ui_text(array $ui, string $key): string
{
    return $ui[$key] ?? $key;
}

function info_tip(array $ui, string $key): string
{
    return '<span class="info-tip"><span class="tip-icon">i</span><span class="tip-box">'
        . htmlspecialchars(ui_text($ui, $key)) . '</span></span>';
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

function date_key_to_iso(int $dateKey): string
{
    $s = (string) $dateKey;
    return substr($s, 0, 4) . '-' . substr($s, 4, 2) . '-' . substr($s, 6, 2);
}

/**
 * Builds a link to this same page with the given query params merged in (or removed,
 * for a null value) — used so clicking a chart segment or table row cross-filters the
 * whole page via a normal navigation, reusing the exact same SQL every other filter uses.
 */
function url_with(array $set): string
{
    $params = $_GET;
    foreach ($set as $key => $value) {
        if ($value === null) {
            unset($params[$key]);
        } else {
            $params[$key] = $value;
        }
    }
    $query = http_build_query($params);
    return basename($_SERVER['PHP_SELF']) . ($query ? '?' . $query : '');
}

function url_without(array $remove): string
{
    $params = $_GET;
    foreach ($remove as $key) {
        unset($params[$key]);
    }
    $query = http_build_query($params);
    return basename($_SERVER['PHP_SELF']) . ($query ? '?' . $query : '');
}

/**
 * Retail branch scope: FactSales only ever carries rows for physical "Journal <mall>"
 * POS branches (confirmed against DimBranch — warehouses/consignment/HQ/online never
 * post sales lines here), except "Journal Online" which is the e-commerce fulfillment
 * branch and belongs on the Online dashboard instead.
 */
const RETAIL_BRANCH_SQL = "b.BranchName LIKE 'Journal %' AND b.BranchName <> 'Journal Online'";
/**
 * DimProduct.ProductGroup separates real merchandise ("FINISH GOOD") from gift/sample/
 * packaging/service lines ("NOT FOR SALE" etc). Those non-sellable lines are ~50% of row
 * volume but ~0.003% of revenue — including them would roughly double-count "units sold".
 */
const SELLABLE_PRODUCT_SQL = "p.ProductGroup = 'FINISH GOOD'";

/**
 * Region grouping is inferred from branch name (no region column exists on DimBranch).
 * Heuristic, not a verified operational hierarchy — treat as a rough view only.
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

$dateInfo = fetch_one($conn, "
    SELECT MAX(f.DateKey) AS maxDateKey
    FROM FactSales f
    JOIN DimBranch b ON f.BranchKey = b.BranchKey
    WHERE " . RETAIL_BRANCH_SQL . "
");
$maxDateKeyInt = (int) ($dateInfo['maxDateKey'] ?? (int) date('Ymd'));
$maxDate = DateTime::createFromFormat('Ymd', (string) $maxDateKeyInt) ?: new DateTime();
$mtdStart = new DateTime($maxDate->format('Y-m-01'));

$uiLanguage = request_value('lang', 'th', ['th', 'en']);
$translations = [
    'th' => [
        'page_title' => 'แดชบอร์ดยอดขายหน้าร้าน',
        'page_subtitle' => 'ติดตามผลการขายจากข้อมูลจริง FactSales (POS หน้าร้าน)',
        'all_branches' => 'ทุกสาขา',
        'today' => 'วันนี้', 'mtd' => 'เดือนนี้', 'ytd' => 'ปีนี้',
        'offline' => 'หน้าร้าน',
        'branch' => 'สาขา',
        'all_categories' => 'ทุกหมวดหมู่',
        'skincare' => 'สกินแคร์', 'perfume' => 'น้ำหอม', 'home_lifestyle' => 'โฮมแอนด์ไลฟ์สไตล์', 'bag' => 'กระเป๋า', 'other' => 'อื่นๆ',
        'all_campaigns' => 'ทุกแคมเปญ', 'discounted' => 'มีส่วนลด', 'no_discount' => 'ไม่มีส่วนลด', 'high_discount' => 'ส่วนลดสูง',
        'source_factsales' => 'แหล่งข้อมูล: FactSales',
        'status_summary_no_data' => 'ไม่พบรายการขายตามตัวกรองที่เลือก ลองขยายช่วงเวลาหรือล้างตัวกรองสาขา/หมวดหมู่/แคมเปญ',
        'no_data_title' => 'ไม่พบข้อมูลตามตัวกรองนี้',
        'discount' => 'ส่วนลด', 'orders' => 'ออเดอร์', 'units_sold' => 'จำนวนชิ้นที่ขาย', 'aov' => 'AOV', 'upt' => 'UPT',
        'tooltip_orders' => 'จำนวนออเดอร์ทั้งหมด (นับเลขที่บิลไม่ซ้ำ)',
        'tooltip_units' => 'จำนวนชิ้นที่ขาย (ไม่รวมของแถม/แพ็กเกจจิ้ง)',
        'tooltip_aov' => 'AOV = ยอดขายสุทธิ ÷ จำนวนออเดอร์',
        'tooltip_upt' => 'UPT = จำนวนชิ้นที่ขาย ÷ จำนวนออเดอร์',
        'top_branch' => 'สาขาอันดับหนึ่ง', 'top_product' => 'สินค้าอันดับหนึ่ง',
        'performance_diagnosis' => 'วิเคราะห์ผลการดำเนินงาน', 'manager_review' => 'สำหรับผู้จัดการสาขาและสินค้า',
        'monthly_trend_chart' => 'แนวโน้มยอดขายรายเดือน', 'last_12_months' => '12 เดือนล่าสุด เทียบเดือนเดียวกันปีก่อน',
        'this_period' => 'ช่วงนี้', 'last_year_same_month' => 'ปีก่อน (เดือนเดียวกัน)',
        'daily_sales_chart' => 'ยอดขายสุทธิรายวัน', 'branch_share' => 'สัดส่วนยอดขายตามสาขา',
        'zone_mix' => 'สัดส่วนยอดขายตามภูมิภาค (โดยประมาณ)', 'product_mix' => 'สัดส่วนยอดขายตามหมวดสินค้า', 'discount_mix' => 'สัดส่วนออเดอร์ตามส่วนลด',
        'branch_performance' => 'ผลการดำเนินงานตามสาขา', 'branch_col' => 'สาขา',
        'top_products' => 'สินค้าขายดี', 'product_col' => 'สินค้า', 'net_sales' => 'ยอดขายสุทธิ', 'units' => 'ชิ้น', 'disc_pct' => 'ส่วนลด %',
        'daily_control' => 'ควบคุมงานรายวัน', 'operator_followup' => 'สำหรับทีมปฏิบัติการสาขา',
        'tip_daily_control' => 'สรุปสถานะปฏิบัติการวันนี้ในภาพรวม ก่อนลงรายละเอียดในตารางด้านล่าง',
        'tip_attention' => 'เทียบยอดขายวันล่าสุดของแต่ละสาขากับค่าเฉลี่ยวันเดียวกัน 4 สัปดาห์ก่อน เพื่อจับสาขาที่ยอดตกผิดปกติหรือยังไม่ส่งยอด',
        'tip_stall' => 'สินค้าที่เคยขายต่อเนื่องแต่เงียบไป 3 วันล่าสุด มักแปลว่าของหมดสต็อกที่สาขานั้น ควรเช็คก่อนลูกค้าถาม',
        'tip_disc_anomaly' => 'จับสาขาที่ใช้ส่วนลดวันล่าสุดสูงผิดปกติเทียบกับพฤติกรรมปกติของสาขาเอง ใช้ตรวจสอบการกดส่วนลดหน้า POS',
        'tip_heatmap' => 'ดูว่าสาขาไหนขายดีวันไหนของสัปดาห์ เพื่อวางแผนจัดกะพนักงานให้ตรงกับวันขายดี',
        'today_sales' => 'ยอดขายวันนี้',
        'discounted_orders' => 'ออเดอร์ที่มีส่วนลด', 'of_total_orders' => 'จากออเดอร์ทั้งหมด',
        'active_branches' => 'สาขาที่มียอดขาย', 'of_total_branches' => 'จากสาขาทั้งหมด',
        'attention_title' => 'สาขาต้องติดตาม',
        'attention_subtitle' => 'ยอดวันล่าสุดเทียบค่าเฉลี่ยวันเดียวกันของ 4 สัปดาห์ก่อน',
        'attention_ok' => 'ทุกสาขายอดขายอยู่ในเกณฑ์ปกติ',
        'status_no_sales' => 'ยังไม่มียอด — ติดต่อสาขา', 'status_below' => 'ต่ำกว่าปกติ',
        'latest_day_col' => 'ยอดวันล่าสุด', 'baseline_col' => 'ค่าปกติ', 'vs_normal_col' => 'เทียบปกติ', 'status_col' => 'สถานะ',
        'stall_title' => 'สินค้าน่าจะขาดสต็อก',
        'stall_subtitle' => 'ขายต่อเนื่องใน 14 วันก่อนหน้า แต่ไม่มียอดขาย 3 วันล่าสุด — ควรเช็คสต็อกที่สาขา',
        'stall_ok' => 'ไม่พบสินค้าที่ยอดขายสะดุด',
        'avg_units_day' => 'ชิ้น/วัน', 'last_sold_col' => 'ขายล่าสุด', 'days_silent_col' => 'เงียบ (วัน)',
        'projection_label' => 'คาดการณ์ยอดสิ้นเดือน',
        'projection_note' => 'จากอัตราปัจจุบัน ถ่วงตามวันในสัปดาห์ (8 สัปดาห์ล่าสุด)',
        'vs_prev_month' => 'เทียบเดือนก่อน (คาดการณ์)', 'vs_same_month_ly' => 'เทียบเดือนเดียวกันปีก่อน (คาดการณ์)',
        'prev_month_actual' => 'เดือนก่อนทำได้', 'ly_month_actual' => 'ปีก่อนทำได้',
        'disc_anomaly_title' => 'การใช้ส่วนลดผิดปกติ',
        'disc_anomaly_subtitle' => 'สาขาที่ % ส่วนลดวันล่าสุดสูงกว่าค่าปกติ 28 วันของตัวเองชัดเจน — ควรตรวจสอบการทำรายการหน้า POS',
        'disc_anomaly_ok' => 'ไม่พบการใช้ส่วนลดผิดปกติในวันล่าสุด',
        'disc_latest_col' => 'ส่วนลดวันล่าสุด', 'disc_norm_col' => 'ค่าปกติ 28 วัน', 'disc_diff_col' => 'ต่าง', 'gross_col' => 'ยอดก่อนส่วนลด',
        'heatmap_title' => 'ยอดขายเฉลี่ยตามวันในสัปดาห์ รายสาขา',
        'heatmap_subtitle' => 'เฉลี่ยต่อวัน 8 สัปดาห์ล่าสุด · สีเข้ม = วันขายดีของสาขานั้น (เทียบภายในสาขาเดียวกัน) · ใช้ประกอบการจัดกะพนักงาน',
        'stale_data_prefix' => 'ข้อมูลขายล่าสุดถึง', 'stale_data_suffix' => 'ช้ากว่าวันนี้', 'stale_data_days' => 'วัน — ตรวจสอบการนำเข้าข้อมูล',
        'zone' => 'ภูมิภาค', 'other' => 'อื่นๆ',
        'cross_filter_active' => 'กรองจากกราฟ:', 'clear_all' => 'ล้างตัวกรองทั้งหมด',
        'category_label' => 'หมวดหมู่', 'campaign_label' => 'แคมเปญ',
        'click_to_filter' => 'คลิกเพื่อกรองข้อมูลทั้งหน้าตามค่านี้',
        'chart_click_hint' => 'คลิกเพื่อกรอง',
    ],
    'en' => [
        'page_title' => 'Offline Sales Dashboard',
        'page_subtitle' => 'Retail store performance from real FactSales POS data',
        'all_branches' => 'All Branches',
        'today' => 'Today', 'mtd' => 'MTD', 'ytd' => 'YTD',
        'offline' => 'Offline',
        'branch' => 'Branch',
        'all_categories' => 'All Categories',
        'skincare' => 'Skincare', 'perfume' => 'Perfume', 'home_lifestyle' => 'Home & Lifestyle', 'bag' => 'Bag', 'other' => 'Other',
        'all_campaigns' => 'All Campaigns', 'discounted' => 'Discounted', 'no_discount' => 'No Discount', 'high_discount' => 'High Discount',
        'source_factsales' => 'Source: FactSales',
        'status_summary_no_data' => 'No sales match these filters. Try widening the date range or clearing the branch/category/campaign filters.',
        'no_data_title' => 'No sales match these filters',
        'discount' => 'Discount', 'orders' => 'Orders', 'units_sold' => 'Units Sold', 'aov' => 'AOV', 'upt' => 'UPT',
        'tooltip_orders' => 'Total orders (distinct receipt count)',
        'tooltip_units' => 'Units sold (excludes gift/packaging lines)',
        'tooltip_aov' => 'AOV = Net Sales ÷ Orders',
        'tooltip_upt' => 'UPT = Units Sold ÷ Orders',
        'top_branch' => 'Top branch', 'top_product' => 'Top product',
        'performance_diagnosis' => 'Performance Diagnosis', 'manager_review' => 'For branch and merchandising managers',
        'monthly_trend_chart' => 'Monthly Sales Trend', 'last_12_months' => 'Last 12 months vs same month last year',
        'this_period' => 'This Period', 'last_year_same_month' => 'Last Year (Same Month)',
        'daily_sales_chart' => 'Daily Net Sales', 'branch_share' => 'Branch Sales Share',
        'zone_mix' => 'Regional Sales Mix (approximate)', 'product_mix' => 'Product Category Mix', 'discount_mix' => 'Order Discount Mix',
        'branch_performance' => 'Branch Performance', 'branch_col' => 'Branch',
        'top_products' => 'Top Products', 'product_col' => 'Product', 'net_sales' => 'Net Sales', 'units' => 'Units', 'disc_pct' => 'Disc %',
        'daily_control' => 'Daily Control', 'operator_followup' => 'For branch operations follow-up',
        'tip_daily_control' => 'Snapshot of today\'s operational status before you drill into the tables below.',
        'tip_attention' => 'Compares each branch\'s latest-day sales to its own same-weekday average over the prior 4 weeks, to catch branches with an unusual drop or no sales reported yet.',
        'tip_stall' => 'Products that sold steadily but went quiet for the last 3 days — usually means the branch is out of stock. Worth checking before a customer asks.',
        'tip_disc_anomaly' => 'Flags branches whose latest-day discount rate is clearly above their own normal pattern, to help catch unusual POS discounting.',
        'tip_heatmap' => 'Shows which weekday each branch sells best, for building staff schedules around actual demand.',
        'today_sales' => 'Today Sales',
        'discounted_orders' => 'Discounted Orders', 'of_total_orders' => 'of total orders',
        'active_branches' => 'Active Branches', 'of_total_branches' => 'of total branches',
        'attention_title' => 'Branches Needing Attention',
        'attention_subtitle' => 'Latest day vs same-weekday average of the prior 4 weeks',
        'attention_ok' => 'All branches are within their normal range',
        'status_no_sales' => 'No sales yet — contact branch', 'status_below' => 'Below normal',
        'latest_day_col' => 'Latest Day', 'baseline_col' => 'Normal', 'vs_normal_col' => 'vs Normal', 'status_col' => 'Status',
        'stall_title' => 'Possible Stockouts',
        'stall_subtitle' => 'Sold steadily over the prior 14 days but nothing in the last 3 days — check stock at the branch',
        'stall_ok' => 'No products with stalled sales',
        'avg_units_day' => 'Units/Day', 'last_sold_col' => 'Last Sold', 'days_silent_col' => 'Silent (Days)',
        'projection_label' => 'Projected Month-End Sales',
        'projection_note' => 'Current run-rate weighted by weekday pattern (last 8 weeks)',
        'vs_prev_month' => 'vs Last Month (Projected)', 'vs_same_month_ly' => 'vs Same Month Last Year (Projected)',
        'prev_month_actual' => 'Last month actual', 'ly_month_actual' => 'Last year actual',
        'disc_anomaly_title' => 'Unusual Discount Activity',
        'disc_anomaly_subtitle' => 'Branches whose latest-day discount rate is clearly above their own 28-day norm — review POS transactions',
        'disc_anomaly_ok' => 'No unusual discount activity on the latest day',
        'disc_latest_col' => 'Latest Day Disc.', 'disc_norm_col' => '28-Day Norm', 'disc_diff_col' => 'Diff', 'gross_col' => 'Gross Sales',
        'heatmap_title' => 'Average Sales by Weekday per Branch',
        'heatmap_subtitle' => 'Daily average over the last 8 weeks · darker = that branch\'s stronger day (scaled within each branch) · use for staff scheduling',
        'stale_data_prefix' => 'Sales data current through', 'stale_data_suffix' => 'lagging today by', 'stale_data_days' => 'day(s) — check the data import',
        'zone' => 'Region', 'other' => 'Other',
        'cross_filter_active' => 'Filtered by chart:', 'clear_all' => 'Clear all filters',
        'category_label' => 'Category', 'campaign_label' => 'Campaign',
        'click_to_filter' => 'Click to filter the whole page by this value',
        'chart_click_hint' => 'Click to filter',
    ],
];
$ui = $translations[$uiLanguage];

$branchRows = fetch_all($conn, "
    SELECT DISTINCT b.BranchName
    FROM FactSales f
    JOIN DimBranch b ON f.BranchKey = b.BranchKey
    WHERE " . RETAIL_BRANCH_SQL . "
    ORDER BY b.BranchName
");
$branchOptions = ['all' => ui_text($ui, 'all_branches')];
foreach ($branchRows as $row) {
    $branchOptions[(string) $row['BranchName']] = (string) $row['BranchName'];
}
$totalRetailBranches = count($branchOptions) - 1;

$categoryKeyMap = [
    'SKINCARE' => 'skincare',
    'PERFUME' => 'perfume',
    'HOME & LIFESTYLE' => 'home_lifestyle',
    'BAG' => 'bag',
];

$filterValues = [
    'lang' => $uiLanguage,
    'date_range' => request_value('date_range', 'mtd', ['today', 'mtd', 'ytd']),
    'channel' => 'offline',
    'branch' => request_value('branch', 'all', array_keys($branchOptions)),
    'category' => request_value('category', 'all', ['all', 'SKINCARE', 'PERFUME', 'HOME & LIFESTYLE', 'BAG']),
    'campaign' => request_value('campaign', 'all', ['all', 'discounted', 'no_discount', 'high_discount']),
    'sales_type' => 'all',
    // Zone has no dropdown in the shared filter bar — it's only reachable by clicking a
    // chart segment (cross-filter), so it's tracked here without a $filterOptions entry.
    'zone' => request_value('zone', 'all', ['all', 'North', 'South', 'East', 'Northeast', 'Bangkok & Metro']),
];
$filterOptions = [
    'date_range' => ['today' => ui_text($ui, 'today'), 'mtd' => ui_text($ui, 'mtd'), 'ytd' => ui_text($ui, 'ytd')],
    'channel' => ['offline' => ui_text($ui, 'offline')],
    'branch_label' => ui_text($ui, 'branch'),
    'branch' => $branchOptions,
    'category' => [
        'all' => ui_text($ui, 'all_categories'),
        'SKINCARE' => ui_text($ui, 'skincare'),
        'PERFUME' => ui_text($ui, 'perfume'),
        'HOME & LIFESTYLE' => ui_text($ui, 'home_lifestyle'),
        'BAG' => ui_text($ui, 'bag'),
    ],
    'campaign' => [
        'all' => ui_text($ui, 'all_campaigns'),
        'discounted' => ui_text($ui, 'discounted'),
        'no_discount' => ui_text($ui, 'no_discount'),
        'high_discount' => ui_text($ui, 'high_discount'),
    ],
    // Header's hardcoded fake "Updated" timestamp was a false freshness signal — replace
    // it with the real max DateKey this page actually queried against.
    'header_labels' => [
        'updated' => $uiLanguage === 'th' ? 'ข้อมูลล่าสุดถึง' : 'Data through',
        'updated_at' => $maxDate->format($uiLanguage === 'th' ? 'j M Y' : 'M j, Y'),
    ],
];

$maxDateKeyPlusOne = (int) (clone $maxDate)->modify('+1 day')->format('Ymd');
$currentYearStartKey = (int) $maxDate->format('Y0101');
$mtdStartKey = (int) $mtdStart->format('Ymd');

if ($filterValues['date_range'] === 'today') {
    $periodStartKey = $maxDateKeyInt;
    $periodEndKey = $maxDateKeyPlusOne;
} elseif ($filterValues['date_range'] === 'ytd') {
    $periodStartKey = $currentYearStartKey;
    $periodEndKey = $maxDateKeyPlusOne;
} else {
    $periodStartKey = $mtdStartKey;
    $periodEndKey = $maxDateKeyPlusOne;
}

/**
 * YoY baseline = the exact same-length date range shifted back exactly one year
 * (e.g. "today" -> same calendar day last year; "MTD so far" -> the same N days
 * last year; YTD -> Jan 1-to-date last year). NOT a full prior month/year prorated
 * by an elapsed-day fraction — that approach was tried and produced the wrong sign
 * on the growth badges, because sales are not evenly distributed within a month
 * (confirmed elsewhere in this file: strong weekday/weekend and pay-day clustering).
 * A short elapsed window (e.g. 1-2 days into the month) prorated from a full month
 * can land far from the real same-day-last-year figure, in either direction.
 */
$periodStartDate = DateTime::createFromFormat('Ymd', (string) $periodStartKey);
$periodEndDate = DateTime::createFromFormat('Ymd', (string) $periodEndKey);
$targetStartKey = (int) (clone $periodStartDate)->modify('-1 year')->format('Ymd');
$targetEndKey = (int) (clone $periodEndDate)->modify('-1 year')->format('Ymd');
$periodLabel = $periodStartDate->format('M j') . ' - ' . (clone $periodEndDate)->modify('-1 day')->format('M j, Y');

$filterConditions = [RETAIL_BRANCH_SQL, SELLABLE_PRODUCT_SQL];
$filterParams = [];
if ($filterValues['branch'] !== 'all') {
    append_filter($filterConditions, $filterParams, 'b.BranchName = ?', $filterValues['branch']);
}
if ($filterValues['category'] !== 'all') {
    append_filter($filterConditions, $filterParams, 'p.ProductType = ?', $filterValues['category']);
}
if ($filterValues['campaign'] === 'discounted') {
    append_filter($filterConditions, $filterParams, 'f.TotalDiscount > 0');
} elseif ($filterValues['campaign'] === 'no_discount') {
    append_filter($filterConditions, $filterParams, 'f.TotalDiscount = 0');
} elseif ($filterValues['campaign'] === 'high_discount') {
    append_filter($filterConditions, $filterParams, 'f.AmountBeforeDiscount > 0 AND (f.TotalDiscount * 100.0 / f.AmountBeforeDiscount) >= 20');
}
if ($filterValues['zone'] !== 'all') {
    append_filter($filterConditions, $filterParams, zone_case_sql() . ' = ?', $filterValues['zone']);
}
$filterSql = implode(' AND ', $filterConditions);

$summaryRows = fetch_all($conn, "
    SELECT 'mtd' AS period,
        COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units,
        SUM(f.NetTotal) AS netSales, SUM(f.AmountBeforeDiscount) AS grossSales, SUM(f.TotalDiscount) AS discount
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql}

    UNION ALL

    SELECT 'prev_mtd' AS period,
        COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units,
        SUM(f.NetTotal) AS netSales, SUM(f.AmountBeforeDiscount) AS grossSales, SUM(f.TotalDiscount) AS discount
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql};
", params_for_periods([
    [$periodStartKey, $periodEndKey],
    [$targetStartKey, $targetEndKey],
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

// "prev*" is the exact same-length period one year ago (see $targetStartKey/$targetEndKey
// above) — used only to drive the YoY growth % badges.
$prevNetSales = $targetBaseNetSales;
$prevOrders = $targetBaseOrders;
$prevUnits = $targetBaseUnits;
$mtdAov = $mtdOrders > 0 ? $mtdNetSales / $mtdOrders : 0;
$prevAov = $targetBaseOrders > 0 ? $targetBaseNetSales / $targetBaseOrders : 0;
$mtdUpt = $mtdOrders > 0 ? $mtdUnits / $mtdOrders : 0;
$prevUpt = $targetBaseOrders > 0 ? $targetBaseUnits / $targetBaseOrders : 0;
$uptGrowth = pct_change($mtdUpt, $prevUpt);

$hasData = $mtdOrders > 0;

$dailyTrend = fetch_all($conn, "
    SELECT f.DateKey, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units,
        SUM(f.NetTotal) AS netSales, SUM(f.TotalDiscount) AS discount
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql}
    GROUP BY f.DateKey
    ORDER BY f.DateKey;
", repeated_params([$periodStartKey, $periodEndKey], $filterParams));

$todaySales = 0.0;
if (!empty($dailyTrend)) {
    $lastDailyRow = $dailyTrend[count($dailyTrend) - 1];
    $todaySales = n($lastDailyRow['netSales'] ?? 0);
}

/**
 * Rolling 12-month window (ending at the current/partial month) vs the same 12 months
 * one year earlier, aligned by calendar month. Needs DimDate (Year/Month) — the only
 * query on this page that joins it, since everything else filters by raw DateKey range.
 */
$monthlyTrendWindowStart = (clone $mtdStart)->modify('-11 months');
$monthlyTrendPriorStart = (clone $monthlyTrendWindowStart)->modify('-12 months');
$monthlyTrendStartKey = (int) $monthlyTrendPriorStart->format('Ymd');

$monthlyTrendRows = fetch_all($conn, "
    SELECT d.Year, d.Month, SUM(f.NetTotal) AS netSales
    FROM FactSales f
    JOIN DimBranch b ON f.BranchKey = b.BranchKey
    JOIN DimProduct p ON f.ProductKey = p.ProductKey
    JOIN DimDate d ON f.DateKey = d.DateKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql}
    GROUP BY d.Year, d.Month
    ORDER BY d.Year, d.Month;
", repeated_params([$monthlyTrendStartKey, $maxDateKeyPlusOne], $filterParams));

$monthlyTrendByKey = [];
foreach ($monthlyTrendRows as $row) {
    $monthlyTrendByKey[(int) $row['Year'] * 100 + (int) $row['Month']] = n($row['netSales']);
}

$monthAbbrTh = ['ม.ค.', 'ก.พ.', 'มี.ค.', 'เม.ย.', 'พ.ค.', 'มิ.ย.', 'ก.ค.', 'ส.ค.', 'ก.ย.', 'ต.ค.', 'พ.ย.', 'ธ.ค.'];
$monthlyTrendLabels = [];
$monthlyTrendCurrent = [];
$monthlyTrendPrior = [];
for ($i = 0; $i < 12; $i++) {
    $cursor = (clone $monthlyTrendWindowStart)->modify("+{$i} months");
    $priorCursor = (clone $cursor)->modify('-1 year');
    $currentKey = (int) $cursor->format('Y') * 100 + (int) $cursor->format('n');
    $priorKey = (int) $priorCursor->format('Y') * 100 + (int) $priorCursor->format('n');
    $monthlyTrendLabels[] = $uiLanguage === 'th' ? $monthAbbrTh[(int) $cursor->format('n') - 1] : $cursor->format('M');
    $monthlyTrendCurrent[] = $monthlyTrendByKey[$currentKey] ?? 0.0;
    $monthlyTrendPrior[] = $monthlyTrendByKey[$priorKey] ?? 0.0;
}
$monthlyTrendTotalCurrent = array_sum($monthlyTrendCurrent);
$monthlyTrendTotalPrior = array_sum($monthlyTrendPrior);
$monthlyTrendYoyGrowth = pct_change($monthlyTrendTotalCurrent, $monthlyTrendTotalPrior);
$monthlyTrendSummaryText = ui_text($ui, 'last_12_months') . ' · '
    . ($uiLanguage === 'th' ? 'รวม ' : 'Total ') . money($monthlyTrendTotalCurrent)
    . ' (' . ($monthlyTrendYoyGrowth >= 0 ? '+' : '') . number_format($monthlyTrendYoyGrowth, 1) . '%)';

$branches = fetch_all($conn, "
    SELECT b.BranchName, COUNT(DISTINCT f.SourceDocNo) AS orders, SUM(f.Quantity) AS units,
        SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql}
    GROUP BY b.BranchName
    ORDER BY netSales DESC;
", repeated_params([$periodStartKey, $periodEndKey], $filterParams));

$zoneMix = fetch_all($conn, "
    SELECT " . zone_case_sql() . " AS zone, SUM(f.NetTotal) AS netSales, COUNT(DISTINCT f.SourceDocNo) AS orders
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql}
    GROUP BY " . zone_case_sql() . "
    ORDER BY netSales DESC;
", repeated_params([$periodStartKey, $periodEndKey], $filterParams));

$productMix = fetch_all($conn, "
    SELECT p.ProductType AS category, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql}
    GROUP BY p.ProductType
    ORDER BY netSales DESC;
", repeated_params([$periodStartKey, $periodEndKey], $filterParams));

$discountMix = fetch_all($conn, "
    WITH order_disc AS (
        SELECT f.SourceDocNo, SUM(f.TotalDiscount) AS totalDisc, SUM(f.AmountBeforeDiscount) AS totalGross
        FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
        WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql}
        GROUP BY f.SourceDocNo
    )
    SELECT
        CASE
            WHEN totalGross > 0 AND (totalDisc * 100.0 / totalGross) >= 20 THEN 'high_discount'
            WHEN totalDisc > 0 THEN 'discounted'
            ELSE 'no_discount'
        END AS bucket,
        COUNT(*) AS orders
    FROM order_disc
    GROUP BY CASE
            WHEN totalGross > 0 AND (totalDisc * 100.0 / totalGross) >= 20 THEN 'high_discount'
            WHEN totalDisc > 0 THEN 'discounted'
            ELSE 'no_discount'
        END;
", repeated_params([$periodStartKey, $periodEndKey], $filterParams));

$topProducts = fetch_all($conn, "
    SELECT TOP 15 p.ProductCode, p.ProductName, SUM(f.Quantity) AS units, SUM(f.NetTotal) AS netSales,
        SUM(f.TotalDiscount) AS discount,
        CASE WHEN SUM(f.AmountBeforeDiscount) > 0 THEN SUM(f.TotalDiscount) * 100.0 / SUM(f.AmountBeforeDiscount) ELSE 0 END AS discountRate
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql}
    GROUP BY p.ProductCode, p.ProductName
    ORDER BY netSales DESC;
", repeated_params([$periodStartKey, $periodEndKey], $filterParams));

$discountedOrdersRow = fetch_one($conn, "
    WITH order_disc AS (
        SELECT f.SourceDocNo, SUM(f.TotalDiscount) AS totalDisc
        FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
        WHERE f.DateKey >= ? AND f.DateKey < ? AND {$filterSql}
        GROUP BY f.SourceDocNo
    )
    SELECT COUNT(*) AS totalOrders, SUM(CASE WHEN totalDisc > 0 THEN 1 ELSE 0 END) AS discountedOrders
    FROM order_disc;
", repeated_params([$periodStartKey, $periodEndKey], $filterParams));
$discountedOrdersCount = (int) n($discountedOrdersRow['discountedOrders'] ?? 0);
$totalOrdersForDiscount = max(1, (int) n($discountedOrdersRow['totalOrders'] ?? 0));

/**
 * Branch attention list: latest data day vs that branch's own norm, where norm =
 * average of the SAME weekday over the prior 4 weeks (sales cluster hard by weekday —
 * Saturday runs ~2x Wednesday — so an all-days average would flag every branch every
 * Monday). Divided by the number of baseline days the branch actually traded, so a
 * branch opened 2 weeks ago is compared only against the weeks it existed.
 * Ignores the date_range filter on purpose: this is a daily-control signal, always
 * anchored to the latest day. Thresholds tuned on live data (2026-07-05): <70% flagged
 * 14 of 27 branches (noise), <60% + zero-sales yields a short actionable list.
 * Date keys are internally computed ints, inlined because binding them as ?s would
 * interleave with the SELECT-clause CASE placeholders ahead of the WHERE params.
 */
$attentionBaselineKeys = [];
for ($w = 1; $w <= 4; $w++) {
    $attentionBaselineKeys[] = (int) (clone $maxDate)->modify("-{$w} weeks")->format('Ymd');
}
$attentionKeysSql = implode(',', array_merge([$maxDateKeyInt], $attentionBaselineKeys));
$attentionThresholdPct = 60.0;
$attentionRows = fetch_all($conn, "
    SELECT b.BranchName,
        SUM(CASE WHEN f.DateKey = {$maxDateKeyInt} THEN f.NetTotal ELSE 0 END) AS latestSales,
        SUM(CASE WHEN f.DateKey <> {$maxDateKeyInt} THEN f.NetTotal ELSE 0 END) * 1.0
            / NULLIF(COUNT(DISTINCT CASE WHEN f.DateKey <> {$maxDateKeyInt} THEN f.DateKey END), 0) AS baselineAvg
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey IN ({$attentionKeysSql}) AND {$filterSql}
    GROUP BY b.BranchName;
", $filterParams);

$attentionBranches = [];
foreach ($attentionRows as $row) {
    $latestSales = n($row['latestSales']);
    $baseline = n($row['baselineAvg'] ?? 0);
    if ($baseline <= 0) {
        continue;
    }
    $ratio = $latestSales / $baseline * 100.0;
    if ($latestSales > 0 && $ratio >= $attentionThresholdPct) {
        continue;
    }
    $attentionBranches[] = [
        'branch' => (string) $row['BranchName'],
        'sales' => $latestSales,
        'baseline' => $baseline,
        'ratio' => $ratio,
        'status' => $latestSales <= 0 ? 'no_sales' : 'below',
    ];
}
usort($attentionBranches, fn($a, $b) => $a['ratio'] <=> $b['ratio']);

/**
 * Stockout proxy — there is no inventory table, so infer from sales rhythm: a SKU that
 * sold on >= 8 of the 14 days before the gap window but moved zero units in the last
 * 3 data days has likely run out at that branch (or been pulled from display).
 * >= 10/14 was too strict on live data (1 hit), >= 6/14 too loose (46); 8 gave ~15.
 * Quantity > 0 guards both checks because FINISH GOOD rows with zero quantity exist.
 */
$stallGapStartKey = (int) (clone $maxDate)->modify('-2 days')->format('Ymd');
$stallBaseStartKey = (int) (clone $maxDate)->modify('-16 days')->format('Ymd');
$stallMinSoldDays = 8;
$stallRows = fetch_all($conn, "
    SELECT TOP 20 b.BranchName, p.ProductCode, p.ProductName,
        COUNT(DISTINCT CASE WHEN f.DateKey < {$stallGapStartKey} AND f.Quantity > 0 THEN f.DateKey END) AS soldDays,
        SUM(CASE WHEN f.DateKey < {$stallGapStartKey} THEN f.Quantity ELSE 0 END) AS baseUnits,
        MAX(CASE WHEN f.Quantity > 0 THEN f.DateKey END) AS lastSoldKey
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$stallBaseStartKey} AND f.DateKey < {$maxDateKeyPlusOne} AND {$filterSql}
    GROUP BY b.BranchName, p.ProductCode, p.ProductName
    HAVING COUNT(DISTINCT CASE WHEN f.DateKey < {$stallGapStartKey} AND f.Quantity > 0 THEN f.DateKey END) >= {$stallMinSoldDays}
       AND SUM(CASE WHEN f.DateKey >= {$stallGapStartKey} THEN f.Quantity ELSE 0 END) = 0
    ORDER BY SUM(CASE WHEN f.DateKey < {$stallGapStartKey} THEN f.Quantity ELSE 0 END) DESC;
", $filterParams);

$stallItems = [];
foreach ($stallRows as $row) {
    $lastSold = DateTime::createFromFormat('Ymd', (string) (int) $row['lastSoldKey']);
    $stallItems[] = [
        'branch' => (string) $row['BranchName'],
        'code' => (string) $row['ProductCode'],
        'name' => (string) $row['ProductName'],
        'avgPerDay' => n($row['baseUnits']) / 14.0,
        'lastSoldLabel' => $lastSold ? $lastSold->format('j M') : '-',
        'daysSilent' => $lastSold ? (int) $lastSold->diff($maxDate)->days : 0,
    ];
}

/**
 * Branch x weekday heatmap over exactly 8 weeks (56 days ending on the latest data
 * day), so every weekday occurs exactly 8 times and a plain /8 gives the per-day
 * average. Also feeds the month-end projection: company-level weekday averages are
 * summed over the remaining calendar days, because sales cluster hard by weekday
 * (Sun ~฿897K vs Wed ~฿622K on live data) and a flat days-elapsed run-rate would
 * systematically over- or under-project depending on which weekdays remain.
 */
$heatmapStartKey = (int) (clone $maxDate)->modify('-55 days')->format('Ymd');
$heatmapRows = fetch_all($conn, "
    SELECT b.BranchName, d.DayName, SUM(f.NetTotal) AS net
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    JOIN DimDate d ON f.DateKey = d.DateKey
    WHERE f.DateKey >= {$heatmapStartKey} AND f.DateKey < {$maxDateKeyPlusOne} AND {$filterSql}
    GROUP BY b.BranchName, d.DayName;
", $filterParams);

$weekdayOrder = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'];
$weekdayAbbr = $uiLanguage === 'th'
    ? ['จ.', 'อ.', 'พ.', 'พฤ.', 'ศ.', 'ส.', 'อา.']
    : ['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

$heatmapByBranch = [];
$companyWeekdayAvg = array_fill_keys($weekdayOrder, 0.0);
foreach ($heatmapRows as $row) {
    $avg = n($row['net']) / 8.0;
    $heatmapByBranch[(string) $row['BranchName']][(string) $row['DayName']] = $avg;
    $companyWeekdayAvg[(string) $row['DayName']] = ($companyWeekdayAvg[(string) $row['DayName']] ?? 0.0) + $avg;
}
uasort($heatmapByBranch, fn($a, $b) => array_sum($b) <=> array_sum($a));

/**
 * Month-end projection: MTD actual + expected sales for each remaining calendar day
 * of the current month, using the weekday averages above. Compared against the prior
 * full month and the same month last year (both actuals). Anchored to the latest data
 * month regardless of the date_range dropdown — it answers "where will THIS month land".
 */
$prevMonthStartKey = (int) (clone $mtdStart)->modify('-1 month')->format('Ymd');
$lyMonthStartKey = (int) (clone $mtdStart)->modify('-1 year')->format('Ymd');
$lyMonthEndKey = (int) (clone $mtdStart)->modify('-1 year')->modify('+1 month')->format('Ymd');
$projectionRow = fetch_one($conn, "
    SELECT
        SUM(CASE WHEN f.DateKey >= {$mtdStartKey} AND f.DateKey < {$maxDateKeyPlusOne} THEN f.NetTotal ELSE 0 END) AS mtdNet,
        SUM(CASE WHEN f.DateKey >= {$prevMonthStartKey} AND f.DateKey < {$mtdStartKey} THEN f.NetTotal ELSE 0 END) AS prevMonthNet,
        SUM(CASE WHEN f.DateKey >= {$lyMonthStartKey} AND f.DateKey < {$lyMonthEndKey} THEN f.NetTotal ELSE 0 END) AS lyMonthNet
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE ((f.DateKey >= {$lyMonthStartKey} AND f.DateKey < {$lyMonthEndKey})
        OR (f.DateKey >= {$prevMonthStartKey} AND f.DateKey < {$maxDateKeyPlusOne})) AND {$filterSql};
", $filterParams);

$projMtdNet = n($projectionRow['mtdNet'] ?? 0);
$projPrevMonthNet = n($projectionRow['prevMonthNet'] ?? 0);
$projLyMonthNet = n($projectionRow['lyMonthNet'] ?? 0);
$daysInMonth = (int) $maxDate->format('t');
$remainingExpected = 0.0;
for ($d = (int) $maxDate->format('j') + 1; $d <= $daysInMonth; $d++) {
    $dow = date('l', mktime(12, 0, 0, (int) $maxDate->format('n'), $d, (int) $maxDate->format('Y')));
    $remainingExpected += $companyWeekdayAvg[$dow] ?? 0.0;
}
$projectedMonthEnd = $projMtdNet + $remainingExpected;
$projVsPrevMonth = pct_change($projectedMonthEnd, $projPrevMonthNet);
$projVsLastYear = pct_change($projectedMonthEnd, $projLyMonthNet);

/**
 * Discount anomaly: latest-day discount rate per branch vs that branch's own 28-day
 * norm. Thresholds tuned on live data (2026-07-05: worst offenders were +4.6pp and
 * +4.2pp over norm, everything else negative or <1pp): flag when the latest day is
 * >= 4 percentage points above norm AND the absolute rate is >= 8% AND gross is at
 * least ฿5,000 (tiny days produce meaningless percentages). Expected to be empty on
 * most days — that's the point of an anomaly list.
 */
$discNormStartKey = (int) (clone $maxDate)->modify('-28 days')->format('Ymd');
$discAnomalyRows = fetch_all($conn, "
    SELECT b.BranchName,
        SUM(CASE WHEN f.DateKey = {$maxDateKeyInt} THEN f.TotalDiscount ELSE 0 END) AS dLatest,
        SUM(CASE WHEN f.DateKey = {$maxDateKeyInt} THEN f.AmountBeforeDiscount ELSE 0 END) AS gLatest,
        SUM(CASE WHEN f.DateKey < {$maxDateKeyInt} THEN f.TotalDiscount ELSE 0 END) AS dNorm,
        SUM(CASE WHEN f.DateKey < {$maxDateKeyInt} THEN f.AmountBeforeDiscount ELSE 0 END) AS gNorm
    FROM FactSales f JOIN DimBranch b ON f.BranchKey = b.BranchKey JOIN DimProduct p ON f.ProductKey = p.ProductKey
    WHERE f.DateKey >= {$discNormStartKey} AND f.DateKey < {$maxDateKeyPlusOne} AND {$filterSql}
    GROUP BY b.BranchName;
", $filterParams);

$discountAnomalies = [];
foreach ($discAnomalyRows as $row) {
    $gLatest = n($row['gLatest']);
    $gNorm = n($row['gNorm']);
    if ($gLatest < 5000 || $gNorm <= 0) {
        continue;
    }
    $latestPct = n($row['dLatest']) * 100.0 / $gLatest;
    $normPct = n($row['dNorm']) * 100.0 / $gNorm;
    if ($latestPct - $normPct < 4.0 || $latestPct < 8.0) {
        continue;
    }
    $discountAnomalies[] = [
        'branch' => (string) $row['BranchName'],
        'latestPct' => $latestPct,
        'normPct' => $normPct,
        'diffPp' => $latestPct - $normPct,
        'gross' => $gLatest,
    ];
}
usort($discountAnomalies, fn($a, $b) => $b['diffPp'] <=> $a['diffPp']);

// Import-lag warning: the whole page silently shifts its date window to MAX(DateKey),
// so a stalled import would otherwise just look like a normal (stale) dashboard.
$dataLagDays = (int) $maxDate->diff(new DateTime('today'))->days;

$salesGrowth = pct_change($mtdNetSales, $prevNetSales);
$orderGrowth = pct_change($mtdOrders, $prevOrders);
$unitGrowth = pct_change($mtdUnits, $prevUnits);
$aovGrowth = pct_change($mtdAov, $prevAov);

$activeBranchCount = count($branches);
$topBranch = $branches[0] ?? null;
$topProduct = $topProducts[0] ?? null;

// Hero title must reflect the selected date_range (Today/MTD/YTD) — a static "MTD"
// label here would misdescribe the number an executive reads first.
$periodWord = ui_text($ui, $filterValues['date_range']);
$heroTitleDynamic = $uiLanguage === 'th' ? "ยอดขายสุทธิหน้าร้าน{$periodWord}" : "{$periodWord} Offline Net Sales";

$branchChartTop = array_slice($branches, 0, 10);
$viewAllBranchesLabel = $uiLanguage === 'th' ? 'ดูทั้งหมด ' . count($branches) . ' สาขา' : 'View all ' . count($branches) . ' branches';
$viewAllProductsLabel = $uiLanguage === 'th' ? 'ดูสินค้าทั้งหมด ' . count($topProducts) . ' รายการ' : 'View all ' . count($topProducts) . ' products';

$activeCrossFilters = [];
if ($filterValues['branch'] !== 'all') {
    $activeCrossFilters[] = ['param' => 'branch', 'label' => ui_text($ui, 'branch'), 'value' => $filterValues['branch']];
}
if ($filterValues['category'] !== 'all') {
    $activeCrossFilters[] = ['param' => 'category', 'label' => ui_text($ui, 'category_label'), 'value' => ui_text($ui, $categoryKeyMap[$filterValues['category']] ?? 'other')];
}
if ($filterValues['campaign'] !== 'all') {
    $activeCrossFilters[] = ['param' => 'campaign', 'label' => ui_text($ui, 'campaign_label'), 'value' => ui_text($ui, $filterValues['campaign'])];
}
if ($filterValues['zone'] !== 'all') {
    $activeCrossFilters[] = ['param' => 'zone', 'label' => ui_text($ui, 'zone'), 'value' => $filterValues['zone']];
}

$pageTitle = ui_text($ui, 'page_title');
$pageSubtitle = ui_text($ui, 'page_subtitle');
$accentColor = '#1a1a2e';
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .offline-hero {
        position: relative;
        overflow: hidden;
        isolation: isolate;
        background:
            radial-gradient(circle at 105% -10%, rgba(122,139,255,0.25) 0%, rgba(122,139,255,0) 42%),
            radial-gradient(circle at -5% 115%, rgba(58,79,140,0.30) 0%, rgba(58,79,140,0) 48%),
            linear-gradient(160deg, #101322 0%, #1a1a2e 100%);
        color: #fff;
        padding: 34px 36px;
        border-radius: 12px;
        margin-bottom: 28px;
        box-shadow: 0 10px 28px rgba(12,18,32,0.28);
    }
    .offline-hero::before {
        content: '';
        position: absolute; inset: 0;
        background-image: radial-gradient(rgba(255,255,255,0.06) 1px, transparent 1px);
        background-size: 16px 16px;
        -webkit-mask-image: linear-gradient(160deg, rgba(0,0,0,0.9), rgba(0,0,0,0) 70%);
        mask-image: linear-gradient(160deg, rgba(0,0,0,0.9), rgba(0,0,0,0) 70%);
        z-index: 0;
    }
    .offline-hero::after {
        content: '';
        position: absolute; right: -34px; bottom: -34px; width: 200px; height: 200px;
        background-image: url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 24 24' fill='none' stroke='%237a8bff' stroke-width='1'%3E%3Cpath d='M3 21h18M5 21V10l4-4 4 4 4-4v15'/%3E%3C/svg%3E");
        background-repeat: no-repeat; background-size: contain;
        opacity: 0.1; z-index: 0; pointer-events: none;
    }
    .offline-hero-top { position: relative; z-index: 1; display: flex; justify-content: space-between; gap: 28px; align-items: flex-start; }
    .no-data-banner { background: #fff; border: 1px solid #E5E7EB; border-radius: 8px; padding: 18px 20px; margin-bottom: 24px; display: flex; align-items: center; gap: 14px; }
    .no-data-banner .icon { width: 36px; height: 36px; flex: 0 0 auto; border-radius: 999px; background: #F3F4F6; color: #6B7280; display: flex; align-items: center; justify-content: center; font-size: 15px; }
    .no-data-banner .title { color: #111827; font-size: 14px; font-weight: 600; }
    .no-data-banner .body { color: #6B7280; font-size: 13px; margin-top: 2px; }
    .offline-hero-title { position: relative; padding-left: 16px; font-size: 13px; color: rgba(255,255,255,0.72); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
    .offline-hero-title::before {
        content: ''; position: absolute; left: 0; top: 50%; transform: translateY(-50%);
        width: 8px; height: 8px; border-radius: 999px; background: #34d399;
        box-shadow: 0 0 0 0 rgba(52,211,153,0.55);
        animation: hero-live-pulse 2.2s ease-out infinite;
    }
    @keyframes hero-live-pulse {
        0% { box-shadow: 0 0 0 0 rgba(52,211,153,0.55); }
        70% { box-shadow: 0 0 0 7px rgba(52,211,153,0); }
        100% { box-shadow: 0 0 0 0 rgba(52,211,153,0); }
    }
    @media (prefers-reduced-motion: reduce) {
        .offline-hero-title::before { animation: none; }
    }
    .offline-hero-value { font-size: 54px; line-height: 1.05; font-weight: 300; letter-spacing: -0.02em; margin-top: 10px; font-variant-numeric: tabular-nums; }
    .offline-hero-value::before { content: '฿'; font-size: 0.5em; font-weight: 500; color: rgba(255,255,255,0.55); margin-right: 6px; }
    .offline-hero-trend { margin-top: 10px; font-size: 13px; }
    .offline-hero-meta { position: relative; z-index: 1; text-align: right; color: rgba(255,255,255,0.78); font-size: 13px; padding-left: 24px; border-left: 1px solid rgba(255,255,255,0.14); }
    .offline-hero-meta .hero-chip { display: inline-block; margin-top: 6px; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,0.08); font-size: 11px; }
    .change.positive { color: #10B981; }
    .change.negative { color: #EF4444; }
    .section-title { display: flex; justify-content: space-between; align-items: center; margin: 6px 0 14px; }
    .section-title h2 { margin: 0; font-size: 16px; font-weight: 600; color: #111827; }
    .section-title span { font-size: 12px; color: #6B7280; }
    .metric-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .metric-grid .kpi-card .value { font-variant-numeric: tabular-nums; }
    .metric-grid .kpi-card .label { padding-bottom: 10px; border-bottom: 1px solid #F3F4F6; }
    .metric-grid .kpi-card .target-line { color: #6B7280; font-size: 12px; line-height: 1.45; margin-top: 8px; }
    /* These cards have a hover tooltip but no click action — override the shared .kpi-card
       pointer cursor (from header.php) without touching the hover-reveal tooltip mechanics. */
    .metric-grid .kpi-card { cursor: default; }
    .analysis-grid { display: grid; grid-template-columns: 1.35fr 1fr; gap: 20px; margin-bottom: 24px; }
    .three-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
    .ops-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
    .ops-card { background: #fff; border-radius: 8px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
    .ops-card .label { color: #6B7280; font-size: 12px; font-weight: 600; text-transform: uppercase; }
    .ops-card .value { color: #111827; font-size: 32px; font-weight: 300; margin: 8px 0 4px; }
    .ops-card .note { color: #9CA3AF; font-size: 12px; }
    .chart-box { height: 300px; }
    .chart-box.small { height: 240px; }
    .table-note { color: #9CA3AF; font-size: 11px; margin-top: -10px; margin-bottom: 14px; }
    .cross-filter-bar { display: flex; flex-wrap: wrap; align-items: center; gap: 8px; margin-bottom: 20px; }
    .cross-filter-label { font-size: 12px; color: #6B7280; font-weight: 600; margin-right: 2px; }
    .cross-filter-chip { display: inline-flex; align-items: center; gap: 6px; background: #EEF2FF; color: #3730A3; border: 1px solid #C7D2FE; border-radius: 999px; padding: 5px 8px 5px 12px; font-size: 12px; font-weight: 500; text-decoration: none; }
    .cross-filter-chip:hover { background: #E0E7FF; }
    .cross-filter-chip .x { font-size: 13px; line-height: 1; opacity: 0.7; }
    .cross-filter-clear { font-size: 12px; color: #6B7280; text-decoration: underline; }
    .cross-filter-clear:hover { color: #111827; }
    .table-card a.badge { text-decoration: none; cursor: pointer; }
    .table-card a.badge:hover { opacity: 0.75; }
    .table-card details { margin-top: 4px; }
    .table-card summary { cursor: pointer; font-size: 12px; color: #6B7280; font-weight: 500; padding: 6px 0; list-style: none; }
    .table-card summary::-webkit-details-marker { display: none; }
    .table-card summary::before { content: '\25B8  '; }
    .table-card details[open] summary::before { content: '\25BE  '; }
    .table-card details table { margin-top: 10px; }
    .no-data-banner.stale .icon { background: #FEF3C7; color: #92400E; }
    .ops-card .value.positive { color: #10B981; }
    .ops-card .value.negative { color: #EF4444; }
    .heatmap-scroll { overflow-x: auto; }
    .heatmap-table { width: 100%; border-collapse: collapse; }
    .heatmap-table th { font-size: 11px; color: #6B7280; font-weight: 600; text-align: right; padding: 6px 8px; border-bottom: 1px solid #E5E7EB; }
    .heatmap-table th:first-child { text-align: left; }
    .heatmap-table td { font-size: 11px; text-align: right; padding: 7px 8px; white-space: nowrap; }
    .heatmap-table td.hm-branch { text-align: left; font-size: 12px; }
    .status-pill { display: inline-block; padding: 3px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; white-space: nowrap; }
    .status-pill.critical { background: #FEE2E2; color: #B91C1C; }
    .status-pill.warn { background: #FEF3C7; color: #92400E; }
    .ok-state { display: flex; align-items: center; gap: 10px; padding: 22px 4px; color: #6B7280; font-size: 13px; }
    .ok-state .dot { width: 10px; height: 10px; border-radius: 999px; background: #10B981; flex: 0 0 auto; }
    .chart-card h3 { cursor: default; }
    .chart-card .hint { font-size: 10px; color: #9CA3AF; font-weight: 400; text-transform: none; margin-left: 6px; }
    .section-title h2, .table-card h3 { display: flex; align-items: center; }
    .info-tip { position: relative; display: inline-flex; align-items: center; margin-left: 7px; }
    .info-tip .tip-icon { width: 15px; height: 15px; border-radius: 50%; background: #E5E7EB; color: #6B7280; font-size: 10px; font-weight: 700; display: flex; align-items: center; justify-content: center; cursor: help; }
    .info-tip .tip-box {
        position: absolute; bottom: 100%; left: 50%; transform: translateX(-50%) translateY(-8px);
        background: #1a1a2e; color: #fff; padding: 8px 12px; border-radius: 6px; font-size: 11px;
        line-height: 1.4; font-weight: 400; text-transform: none; letter-spacing: normal;
        white-space: normal; width: 240px; opacity: 0; visibility: hidden;
        transition: opacity 0.15s ease, visibility 0.15s ease; z-index: 1000; pointer-events: none;
    }
    .info-tip .tip-box::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 6px solid transparent; border-top-color: #1a1a2e; }
    .info-tip:hover .tip-box { opacity: 1; visibility: visible; }
    @media (max-width: 1100px) {
        .metric-grid, .ops-grid { grid-template-columns: repeat(2, 1fr); }
        .analysis-grid, .three-grid { grid-template-columns: 1fr; }
    }
    @media (max-width: 640px) {
        .offline-hero-top { flex-direction: column; }
        .offline-hero-meta { text-align: left; }
        .metric-grid, .ops-grid { grid-template-columns: 1fr; }
        .offline-hero-value { font-size: 34px; }
    }
</style>

<div class="offline-hero">
    <div class="offline-hero-top">
        <div>
            <div class="offline-hero-title"><?php echo htmlspecialchars($heroTitleDynamic); ?></div>
            <div class="offline-hero-value count-up" data-count-to="<?php echo (float) $mtdNetSales; ?>">0</div>
            <div class="offline-hero-trend"><?php echo trend_label($salesGrowth, $uiLanguage); ?></div>
        </div>
        <div class="offline-hero-meta">
            <div><?php echo htmlspecialchars($periodLabel); ?></div>
            <div><?php echo htmlspecialchars(ui_text($ui, 'source_factsales')); ?></div>
        </div>
    </div>
</div>

<?php if ($dataLagDays >= 2): ?>
<div class="no-data-banner stale">
    <div class="icon">!</div>
    <div>
        <div class="title"><?php echo htmlspecialchars(ui_text($ui, 'stale_data_prefix')); ?> <?php echo htmlspecialchars($maxDate->format('j M Y')); ?></div>
        <div class="body"><?php echo htmlspecialchars(ui_text($ui, 'stale_data_suffix')); ?> <?php echo number_format($dataLagDays); ?> <?php echo htmlspecialchars(ui_text($ui, 'stale_data_days')); ?></div>
    </div>
</div>
<?php endif; ?>

<?php if (!$hasData): ?>
<div class="no-data-banner">
    <div class="icon">!</div>
    <div>
        <div class="title"><?php echo htmlspecialchars(ui_text($ui, 'no_data_title')); ?></div>
        <div class="body"><?php echo htmlspecialchars(ui_text($ui, 'status_summary_no_data')); ?></div>
    </div>
</div>
<?php endif; ?>

<?php if (!empty($activeCrossFilters)): ?>
<div class="cross-filter-bar">
    <span class="cross-filter-label"><?php echo htmlspecialchars(ui_text($ui, 'cross_filter_active')); ?></span>
    <?php foreach ($activeCrossFilters as $cf): ?>
        <a class="cross-filter-chip" href="<?php echo htmlspecialchars(url_with([$cf['param'] => null])); ?>">
            <?php echo htmlspecialchars($cf['label']); ?>: <?php echo htmlspecialchars($cf['value']); ?> <span class="x">&times;</span>
        </a>
    <?php endforeach; ?>
    <?php if (count($activeCrossFilters) > 1): ?>
        <a class="cross-filter-clear" href="<?php echo htmlspecialchars(url_without(['branch', 'category', 'campaign', 'zone'])); ?>"><?php echo htmlspecialchars(ui_text($ui, 'clear_all')); ?></a>
    <?php endif; ?>
</div>
<?php endif; ?>

<div class="metric-grid">
    <div class="kpi-card">
        <div class="tooltip"><?php echo htmlspecialchars(ui_text($ui, 'tooltip_orders')); ?></div>
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'orders')); ?></div>
        <div class="value count-up" data-count-to="<?php echo (float) $mtdOrders; ?>">0</div>
        <?php echo trend_label($orderGrowth, $uiLanguage); ?>
    </div>
    <div class="kpi-card">
        <div class="tooltip"><?php echo htmlspecialchars(ui_text($ui, 'tooltip_units')); ?></div>
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'units_sold')); ?></div>
        <div class="value count-up" data-count-to="<?php echo (float) $mtdUnits; ?>">0</div>
        <?php echo trend_label($unitGrowth, $uiLanguage); ?>
    </div>
    <div class="kpi-card">
        <div class="tooltip"><?php echo htmlspecialchars(ui_text($ui, 'tooltip_aov')); ?></div>
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'aov')); ?></div>
        <div class="value count-up" data-count-to="<?php echo (float) $mtdAov; ?>" data-prefix="฿">0</div>
        <?php echo trend_label($aovGrowth, $uiLanguage); ?>
    </div>
    <div class="kpi-card">
        <div class="tooltip"><?php echo htmlspecialchars(ui_text($ui, 'tooltip_upt')); ?></div>
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'upt')); ?></div>
        <div class="value count-up" data-count-to="<?php echo (float) $mtdUpt; ?>" data-decimals="2">0</div>
        <?php echo trend_label($uptGrowth, $uiLanguage); ?>
        <div class="target-line"><?php echo htmlspecialchars(ui_text($ui, 'top_branch')); ?> <?php echo htmlspecialchars($topBranch['BranchName'] ?? '-'); ?> | <?php echo htmlspecialchars(ui_text($ui, 'top_product')); ?> <?php echo htmlspecialchars($topProduct['ProductName'] ?? '-'); ?></div>
    </div>
</div>

<div class="section-title">
    <h2><?php echo htmlspecialchars(ui_text($ui, 'monthly_trend_chart')); ?></h2>
    <span><?php echo htmlspecialchars($monthlyTrendSummaryText); ?></span>
</div>
<div class="chart-card" style="margin-bottom: 24px;">
    <div class="chart-box"><canvas id="monthlyTrendChart"></canvas></div>
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
        <h3><?php echo htmlspecialchars(ui_text($ui, 'branch_share')); ?> <span class="hint">(<?php echo htmlspecialchars(ui_text($ui, 'chart_click_hint')); ?>)</span></h3>
        <div class="chart-box"><canvas id="branchShareChart"></canvas></div>
    </div>
</div>

<div class="three-grid">
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'zone_mix')); ?> <span class="hint">(<?php echo htmlspecialchars(ui_text($ui, 'chart_click_hint')); ?>)</span></h3>
        <div class="chart-box small"><canvas id="zoneChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'product_mix')); ?> <span class="hint">(<?php echo htmlspecialchars(ui_text($ui, 'chart_click_hint')); ?>)</span></h3>
        <div class="chart-box small"><canvas id="productMixChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'discount_mix')); ?> <span class="hint">(<?php echo htmlspecialchars(ui_text($ui, 'chart_click_hint')); ?>)</span></h3>
        <div class="chart-box small"><canvas id="discountChart"></canvas></div>
    </div>
</div>

<div class="split-grid max-[900px]:grid-cols-1">
    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'branch_performance')); ?> <span class="hint">(<?php echo htmlspecialchars(ui_text($ui, 'chart_click_hint')); ?>)</span></h3>
        <div class="table-note"><?php echo htmlspecialchars(ui_text($ui, 'top_branch')); ?> <?php echo htmlspecialchars($topBranch['BranchName'] ?? '-'); ?> · <?php echo htmlspecialchars(ui_text($ui, 'top_product')); ?> <?php echo htmlspecialchars($topProduct['ProductName'] ?? '-'); ?></div>
        <div class="chart-box"><canvas id="branchPerfChart"></canvas></div>
        <details>
            <summary><?php echo htmlspecialchars($viewAllBranchesLabel); ?></summary>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                <tr>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'branch_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'net_sales')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'orders')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'aov')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($branches as $row): ?>
                    <?php $orders = n($row['orders']); ?>
                    <?php $branchActual = n($row['netSales']); ?>
                    <tr>
                        <td><a class="badge offline" href="<?php echo htmlspecialchars(url_with(['branch' => $row['BranchName']])); ?>" title="<?php echo htmlspecialchars(ui_text($ui, 'click_to_filter')); ?>"><?php echo htmlspecialchars($row['BranchName']); ?></a></td>
                        <td><?php echo money($branchActual); ?></td>
                        <td><?php echo number_format($orders, 0); ?></td>
                        <td><?php echo money($orders > 0 ? $branchActual / $orders : 0); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </details>
    </div>

    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'top_products')); ?></h3>
        <div class="chart-box"><canvas id="topProductsChart"></canvas></div>
        <details>
            <summary><?php echo htmlspecialchars($viewAllProductsLabel); ?></summary>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                <tr>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'product_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'net_sales')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'units')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'disc_pct')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($topProducts as $row): ?>
                    <tr>
                        <td>
                            <div class="product-meta">
                                <div class="sku"><?php echo htmlspecialchars($row['ProductCode']); ?></div>
                                <div class="name"><?php echo htmlspecialchars(mb_strimwidth($row['ProductName'], 0, 52, '...')); ?></div>
                            </div>
                        </td>
                        <td><?php echo money(n($row['netSales'])); ?></td>
                        <td><?php echo number_format(n($row['units']), 0); ?></td>
                        <td><?php echo number_format(n($row['discountRate']), 1); ?>%</td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        </details>
    </div>
</div>

<div class="section-title">
    <h2><?php echo htmlspecialchars(ui_text($ui, 'daily_control')); ?><?php echo info_tip($ui, 'tip_daily_control'); ?></h2>
    <span><?php echo htmlspecialchars(ui_text($ui, 'operator_followup')); ?></span>
</div>
<div class="ops-grid">
    <div class="ops-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'today_sales')); ?></div>
        <div class="value"><?php echo money($todaySales); ?></div>
    </div>
    <div class="ops-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'discounted_orders')); ?></div>
        <div class="value"><?php echo number_format($discountedOrdersCount); ?></div>
        <div class="note"><?php echo number_format(($discountedOrdersCount / $totalOrdersForDiscount) * 100, 1); ?>% <?php echo htmlspecialchars(ui_text($ui, 'of_total_orders')); ?></div>
    </div>
    <div class="ops-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'active_branches')); ?></div>
        <div class="value"><?php echo number_format($activeBranchCount); ?></div>
        <div class="note"><?php echo htmlspecialchars(ui_text($ui, 'of_total_branches')); ?> <?php echo number_format($totalRetailBranches); ?></div>
    </div>
    <div class="ops-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'projection_label')); ?></div>
        <div class="value"><?php echo money($projectedMonthEnd); ?></div>
        <div class="note"><?php echo htmlspecialchars(ui_text($ui, 'projection_note')); ?></div>
    </div>
    <div class="ops-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'vs_prev_month')); ?></div>
        <div class="value <?php echo $projVsPrevMonth >= 0 ? 'positive' : 'negative'; ?>"><?php echo ($projVsPrevMonth >= 0 ? '+' : '') . number_format($projVsPrevMonth, 1); ?>%</div>
        <div class="note"><?php echo htmlspecialchars(ui_text($ui, 'prev_month_actual')); ?> <?php echo money($projPrevMonthNet); ?></div>
    </div>
    <div class="ops-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'vs_same_month_ly')); ?></div>
        <div class="value <?php echo $projVsLastYear >= 0 ? 'positive' : 'negative'; ?>"><?php echo ($projVsLastYear >= 0 ? '+' : '') . number_format($projVsLastYear, 1); ?>%</div>
        <div class="note"><?php echo htmlspecialchars(ui_text($ui, 'ly_month_actual')); ?> <?php echo money($projLyMonthNet); ?></div>
    </div>
</div>

<div class="split-grid max-[900px]:grid-cols-1">
    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'attention_title')); ?><?php echo info_tip($ui, 'tip_attention'); ?></h3>
        <div class="table-note"><?php echo htmlspecialchars(ui_text($ui, 'attention_subtitle')); ?> · <?php echo htmlspecialchars($maxDate->format('j M Y')); ?></div>
        <?php if (empty($attentionBranches)): ?>
            <div class="ok-state"><span class="dot"></span><?php echo htmlspecialchars(ui_text($ui, 'attention_ok')); ?></div>
        <?php else: ?>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                <tr>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'branch_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'latest_day_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'baseline_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'vs_normal_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'status_col')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($attentionBranches as $row): ?>
                    <tr>
                        <td><a class="badge offline" href="<?php echo htmlspecialchars(url_with(['branch' => $row['branch']])); ?>" title="<?php echo htmlspecialchars(ui_text($ui, 'click_to_filter')); ?>"><?php echo htmlspecialchars($row['branch']); ?></a></td>
                        <td><?php echo money($row['sales']); ?></td>
                        <td><?php echo money($row['baseline']); ?></td>
                        <td><?php echo number_format($row['ratio'], 0); ?>%</td>
                        <td><span class="status-pill <?php echo $row['status'] === 'no_sales' ? 'critical' : 'warn'; ?>"><?php echo htmlspecialchars(ui_text($ui, $row['status'] === 'no_sales' ? 'status_no_sales' : 'status_below')); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>

    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'stall_title')); ?><?php echo info_tip($ui, 'tip_stall'); ?></h3>
        <div class="table-note"><?php echo htmlspecialchars(ui_text($ui, 'stall_subtitle')); ?></div>
        <?php if (empty($stallItems)): ?>
            <div class="ok-state"><span class="dot"></span><?php echo htmlspecialchars(ui_text($ui, 'stall_ok')); ?></div>
        <?php else: ?>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                <tr>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'branch_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'product_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'avg_units_day')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'last_sold_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'days_silent_col')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($stallItems as $row): ?>
                    <tr>
                        <td><a class="badge offline" href="<?php echo htmlspecialchars(url_with(['branch' => $row['branch']])); ?>" title="<?php echo htmlspecialchars(ui_text($ui, 'click_to_filter')); ?>"><?php echo htmlspecialchars($row['branch']); ?></a></td>
                        <td>
                            <div class="product-meta">
                                <div class="sku"><?php echo htmlspecialchars($row['code']); ?></div>
                                <div class="name"><?php echo htmlspecialchars(mb_strimwidth($row['name'], 0, 44, '...')); ?></div>
                            </div>
                        </td>
                        <td><?php echo number_format($row['avgPerDay'], 1); ?></td>
                        <td><?php echo htmlspecialchars($row['lastSoldLabel']); ?></td>
                        <td><?php echo number_format($row['daysSilent']); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>

<div class="table-card" style="margin-bottom: 24px;">
    <h3><?php echo htmlspecialchars(ui_text($ui, 'disc_anomaly_title')); ?><?php echo info_tip($ui, 'tip_disc_anomaly'); ?></h3>
    <div class="table-note"><?php echo htmlspecialchars(ui_text($ui, 'disc_anomaly_subtitle')); ?> · <?php echo htmlspecialchars($maxDate->format('j M Y')); ?></div>
    <?php if (empty($discountAnomalies)): ?>
        <div class="ok-state"><span class="dot"></span><?php echo htmlspecialchars(ui_text($ui, 'disc_anomaly_ok')); ?></div>
    <?php else: ?>
        <div class="max-[640px]:overflow-x-auto"><table>
            <thead>
            <tr>
                <th><?php echo htmlspecialchars(ui_text($ui, 'branch_col')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'disc_latest_col')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'disc_norm_col')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'disc_diff_col')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'gross_col')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($discountAnomalies as $row): ?>
                <tr>
                    <td><a class="badge offline" href="<?php echo htmlspecialchars(url_with(['branch' => $row['branch']])); ?>" title="<?php echo htmlspecialchars(ui_text($ui, 'click_to_filter')); ?>"><?php echo htmlspecialchars($row['branch']); ?></a></td>
                    <td><?php echo number_format($row['latestPct'], 1); ?>%</td>
                    <td><?php echo number_format($row['normPct'], 1); ?>%</td>
                    <td><span class="status-pill warn">+<?php echo number_format($row['diffPp'], 1); ?> pp</span></td>
                    <td><?php echo money($row['gross']); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<div class="table-card" style="margin-bottom: 24px;">
    <h3><?php echo htmlspecialchars(ui_text($ui, 'heatmap_title')); ?><?php echo info_tip($ui, 'tip_heatmap'); ?></h3>
    <div class="table-note"><?php echo htmlspecialchars(ui_text($ui, 'heatmap_subtitle')); ?></div>
    <div class="heatmap-scroll">
        <div class="max-[640px]:overflow-x-auto"><table class="heatmap-table">
            <thead>
            <tr>
                <th><?php echo htmlspecialchars(ui_text($ui, 'branch_col')); ?></th>
                <?php foreach ($weekdayAbbr as $abbr): ?>
                    <th><?php echo htmlspecialchars($abbr); ?></th>
                <?php endforeach; ?>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($heatmapByBranch as $branchName => $days): ?>
                <?php $rowMax = max(array_merge([0.0], array_values($days))); ?>
                <tr>
                    <td class="hm-branch"><a class="badge offline" href="<?php echo htmlspecialchars(url_with(['branch' => $branchName])); ?>" title="<?php echo htmlspecialchars(ui_text($ui, 'click_to_filter')); ?>"><?php echo htmlspecialchars($branchName); ?></a></td>
                    <?php foreach ($weekdayOrder as $dayName): ?>
                        <?php
                        $cellValue = $days[$dayName] ?? 0.0;
                        $alpha = $rowMax > 0 ? 0.04 + 0.56 * ($cellValue / $rowMax) : 0.04;
                        $textColor = $alpha > 0.38 ? '#ffffff' : '#111827';
                        $cellLabel = $cellValue >= 1000 ? number_format($cellValue / 1000, 0) . 'K' : number_format($cellValue, 0);
                        ?>
                        <td style="background: rgba(26,26,46,<?php echo number_format($alpha, 2); ?>); color: <?php echo $textColor; ?>;"><?php echo htmlspecialchars($cellLabel); ?></td>
                    <?php endforeach; ?>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>

<script>
const chartColors = ['#1a1a2e', '#c9a227', '#10B981', '#3B82F6', '#EF4444', '#F59E0B', '#6B7280'];
const numberFormat = new Intl.NumberFormat('<?php echo $uiLanguage === 'th' ? 'th-TH' : 'en-US'; ?>');

// Count-up animation for hero + KPI card values on page load
document.querySelectorAll('.count-up').forEach(function (el) {
    const target = parseFloat(el.dataset.countTo) || 0;
    const decimals = parseInt(el.dataset.decimals, 10) || 0;
    const prefix = el.dataset.prefix || '';
    const suffix = el.dataset.suffix || '';
    const duration = 1200;
    const startTime = performance.now();
    const fmt = new Intl.NumberFormat('th-TH', { minimumFractionDigits: decimals, maximumFractionDigits: decimals });

    function tick(now) {
        const progress = Math.min((now - startTime) / duration, 1);
        const eased = 1 - Math.pow(1 - progress, 3);
        el.textContent = prefix + fmt.format(target * eased) + suffix;
        if (progress < 1) {
            requestAnimationFrame(tick);
        }
    }
    requestAnimationFrame(tick);
});

window.updateDashboardData = function () {
    document.querySelector('.filter-bar').submit();
};

// Offline is single-channel and this page has no sales_type dimension in FactSales —
// those two filter-bar dropdowns are permanently fixed to one option. Remove them from
// the DOM (rather than leaving fake, unchangeable controls) without touching the shared
// filter bar markup in header.php, which other pages still use with real options.
['channelFilter', 'salesTypeFilter'].forEach(function (id) {
    var el = document.getElementById(id);
    if (!el) return;
    var wrap = el.closest('.filter-item');
    if (!wrap) return;
    var prev = wrap.previousElementSibling;
    if (prev && prev.classList.contains('separator')) prev.remove();
    wrap.remove();
});

function shortNumber(value) {
    if (Math.abs(value) >= 1000000) return (value / 1000000).toFixed(1) + 'M';
    if (Math.abs(value) >= 1000) return (value / 1000).toFixed(0) + 'K';
    return value;
}

// Money-specific formatters — every axis/tooltip showing Net Sales or Discount amounts
// uses these instead of the bare shortNumber/numberFormat, so a 7-digit figure never
// reads as ambiguous (units? cents?) on a real-money dashboard.
function shortMoney(value) {
    return '฿' + shortNumber(value);
}

function fmtMoney(value) {
    return '฿' + numberFormat.format(value);
}

// Cross-filter: clicking a chart segment sets that param on the URL and reloads —
// every query on this page reads the same $_GET filters, so one click re-filters
// the whole dashboard (hero, KPIs, trend, tables) with real SQL, not an approximation.
function crossFilter(param, value) {
    var url = new URL(window.location.href);
    url.searchParams.set(param, value);
    window.location.href = url.toString();
}

function pointerOnHover(evt, elements) {
    evt.native.target.style.cursor = elements.length ? 'pointer' : 'default';
}

new Chart(document.getElementById('dailySalesChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map(fn($k) => date_key_to_iso((int) $k), array_column($dailyTrend, 'DateKey'))); ?>,
        datasets: [
            {
                label: <?php echo json_encode(ui_text($ui, 'net_sales')); ?>,
                data: <?php echo json_encode(array_map('n', array_column($dailyTrend, 'netSales'))); ?>,
                backgroundColor: '#1a1a2e',
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
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + fmtMoney(ctx.raw) } }
        },
        scales: {
            x: { stacked: true, grid: { display: false }, ticks: { color: '#6B7280', maxRotation: 45 } },
            y: { stacked: false, ticks: { callback: shortMoney, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } }
        }
    }
});

new Chart(document.getElementById('monthlyTrendChart'), {
    type: 'line',
    data: {
        labels: <?php echo json_encode($monthlyTrendLabels); ?>,
        datasets: [
            {
                label: <?php echo json_encode(ui_text($ui, 'this_period')); ?>,
                data: <?php echo json_encode(array_map('round', $monthlyTrendCurrent)); ?>,
                borderColor: '#1a1a2e',
                backgroundColor: '#1a1a2e',
                borderWidth: 2,
                pointRadius: 3,
                tension: 0.3
            },
            {
                label: <?php echo json_encode(ui_text($ui, 'last_year_same_month')); ?>,
                data: <?php echo json_encode(array_map('round', $monthlyTrendPrior)); ?>,
                borderColor: '#9CA3AF',
                backgroundColor: '#9CA3AF',
                borderDash: [6, 4],
                borderWidth: 2,
                pointRadius: 3,
                tension: 0.3
            }
        ]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        interaction: { mode: 'index', intersect: false },
        plugins: {
            legend: { position: 'top', align: 'end', labels: { usePointStyle: true } },
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + fmtMoney(ctx.raw) } }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#6B7280' } },
            y: { ticks: { callback: shortMoney, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } }
        }
    }
});

<?php
$branchChartRows = array_slice($branches, 0, 5);
$otherBranchTotal = array_sum(array_map('n', array_column(array_slice($branches, 5), 'netSales')));
$branchChartLabels = array_column($branchChartRows, 'BranchName');
$branchChartData = array_map('n', array_column($branchChartRows, 'netSales'));
if ($otherBranchTotal > 0) {
    $branchChartLabels[] = ui_text($ui, 'other');
    $branchChartData[] = $otherBranchTotal;
}
?>
const branchShareChart = new Chart(document.getElementById('branchShareChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode($branchChartLabels); ?>,
        datasets: [{
            data: <?php echo json_encode($branchChartData); ?>,
            backgroundColor: chartColors,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '65%',
        plugins: {
            legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } },
            tooltip: { callbacks: { label: ctx => ctx.label + ': ' + fmtMoney(ctx.raw) } }
        },
        onHover: pointerOnHover,
        onClick: (evt, elements) => {
            if (!elements.length) return;
            const label = branchShareChart.data.labels[elements[0].index];
            if (label === <?php echo json_encode(ui_text($ui, 'other')); ?>) return;
            crossFilter('branch', label);
        }
    }
});

const zoneChart = new Chart(document.getElementById('zoneChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($zoneMix, 'zone')); ?>,
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'net_sales')); ?>,
            data: <?php echo json_encode(array_map('n', array_column($zoneMix, 'netSales'))); ?>,
            backgroundColor: '#1a1a2e',
            borderWidth: 0,
            borderRadius: 4
        }]
    },
    options: {
        indexAxis: 'y',
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + fmtMoney(ctx.raw) } }
        },
        scales: {
            x: { ticks: { callback: shortMoney, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } },
            y: { grid: { display: false }, ticks: { color: '#111827' } }
        },
        onHover: pointerOnHover,
        onClick: (evt, elements) => {
            if (!elements.length) return;
            crossFilter('zone', zoneChart.data.labels[elements[0].index]);
        }
    }
});

const productMixCategoryKeys = <?php echo json_encode(array_column($productMix, 'category')); ?>;
const productMixChart = new Chart(document.getElementById('productMixChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map(function ($key) use ($ui, $categoryKeyMap) {
            return ui_text($ui, $categoryKeyMap[$key] ?? 'other');
        }, array_column($productMix, 'category'))); ?>,
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'net_sales')); ?>,
            data: <?php echo json_encode(array_map('n', array_column($productMix, 'netSales'))); ?>,
            backgroundColor: chartColors,
            borderWidth: 0,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + fmtMoney(ctx.raw) } }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#6B7280', maxRotation: 35 } },
            y: { ticks: { callback: shortMoney, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } }
        },
        onHover: pointerOnHover,
        onClick: (evt, elements) => {
            if (!elements.length) return;
            const key = productMixCategoryKeys[elements[0].index];
            if (!key || key === 'N/A') return;
            crossFilter('category', key);
        }
    }
});

const discountBucketKeys = <?php echo json_encode(array_column($discountMix, 'bucket')); ?>;
const discountChart = new Chart(document.getElementById('discountChart'), {
    type: 'doughnut',
    data: {
        labels: <?php echo json_encode(array_map(fn($k) => ui_text($ui, $k), array_column($discountMix, 'bucket'))); ?>,
        datasets: [{
            data: <?php echo json_encode(array_map('n', array_column($discountMix, 'orders'))); ?>,
            backgroundColor: chartColors,
            borderWidth: 0
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        cutout: '62%',
        plugins: { legend: { position: 'bottom', labels: { usePointStyle: true, boxWidth: 8 } } },
        onHover: pointerOnHover,
        onClick: (evt, elements) => {
            if (!elements.length) return;
            crossFilter('campaign', discountBucketKeys[elements[0].index]);
        }
    }
});

const branchOrdersData = <?php echo json_encode(array_map('n', array_column($branchChartTop, 'orders'))); ?>;
const branchAovData = <?php echo json_encode(array_map(fn($r) => n($r['orders']) > 0 ? n($r['netSales']) / n($r['orders']) : 0, $branchChartTop)); ?>;
const branchPerfChart = new Chart(document.getElementById('branchPerfChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($branchChartTop, 'BranchName')); ?>,
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'net_sales')); ?>,
            data: <?php echo json_encode(array_map('n', array_column($branchChartTop, 'netSales'))); ?>,
            backgroundColor: '#1a1a2e',
            borderWidth: 0,
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
                callbacks: {
                    label: ctx => <?php echo json_encode(ui_text($ui, 'net_sales')); ?> + ': ' + fmtMoney(ctx.raw),
                    afterLabel: ctx => [
                        <?php echo json_encode(ui_text($ui, 'orders')); ?> + ': ' + numberFormat.format(branchOrdersData[ctx.dataIndex]),
                        <?php echo json_encode(ui_text($ui, 'aov')); ?> + ': ' + fmtMoney(Math.round(branchAovData[ctx.dataIndex]))
                    ]
                }
            }
        },
        scales: {
            x: { ticks: { callback: shortMoney, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } },
            y: { grid: { display: false }, ticks: { color: '#111827' } }
        },
        onHover: pointerOnHover,
        onClick: (evt, elements) => {
            if (!elements.length) return;
            crossFilter('branch', branchPerfChart.data.labels[elements[0].index]);
        }
    }
});

const topProductsChartRows = <?php echo json_encode(array_slice($topProducts, 0, 8)); ?>;
const topProductsChart = new Chart(document.getElementById('topProductsChart'), {
    type: 'bar',
    data: {
        labels: topProductsChartRows.map(p => p.ProductName.length > 28 ? p.ProductName.slice(0, 28) + '...' : p.ProductName),
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'net_sales')); ?>,
            data: topProductsChartRows.map(p => Number(p.netSales) || 0),
            backgroundColor: '#1a1a2e',
            borderWidth: 0,
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
                callbacks: {
                    title: ctxArr => topProductsChartRows[ctxArr[0].dataIndex].ProductName,
                    label: ctx => <?php echo json_encode(ui_text($ui, 'net_sales')); ?> + ': ' + fmtMoney(ctx.raw),
                    afterLabel: ctx => {
                        const row = topProductsChartRows[ctx.dataIndex];
                        return [
                            <?php echo json_encode(ui_text($ui, 'units')); ?> + ': ' + numberFormat.format(Number(row.units) || 0),
                            <?php echo json_encode(ui_text($ui, 'disc_pct')); ?> + ': ' + (Number(row.discountRate) || 0).toFixed(1) + '%'
                        ];
                    }
                }
            }
        },
        scales: {
            x: { ticks: { callback: shortMoney, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } },
            y: { grid: { display: false }, ticks: { color: '#111827' } }
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
