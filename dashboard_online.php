<?php
session_start();

require_once __DIR__ . '/scripts/helpers.php';
initPerformanceSettings();
require_once __DIR__ . '/database.php';

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

/**
 * DimOnlineCustomer.province is free text typed by customers at checkout, not a
 * dropdown against Thailand's 77-province list — the same value shows up as e.g.
 * "จังหวัดกรุงเทพมหานคร" / "กรุงเทพฯ" / "กรุงเทพมหานคร" / "Bangkok" / "กรุงเทพ" all at once,
 * and a long tail of postal codes, district names, and non-Thai text. LIKE-matching
 * each of the 77 official provinces' Thai name + common English name against the raw
 * column (verified against the full distinct list) collapses that into ~77 clean
 * buckets covering 99.83% of rows; anything unmatched (foreign-language entries,
 * postal codes, sub-district-only addresses) falls into one "อื่นๆ/ไม่ระบุ" bucket
 * rather than being silently dropped.
 */
function province_group_case(string $column = 'c.province'): string
{
    $map = [
        'กรุงเทพ' => 'กรุงเทพมหานคร', 'Bangkok' => 'กรุงเทพมหานคร',
        'ชลบุรี' => 'ชลบุรี', 'Chon Buri' => 'ชลบุรี',
        'สมุทรปราการ' => 'สมุทรปราการ', 'Samut Prakan' => 'สมุทรปราการ',
        'นนทบุรี' => 'นนทบุรี', 'Nonthaburi' => 'นนทบุรี',
        'ปทุมธานี' => 'ปทุมธานี', 'Pathum Thani' => 'ปทุมธานี',
        'เชียงใหม่' => 'เชียงใหม่', 'Chiang Mai' => 'เชียงใหม่',
        'สงขลา' => 'สงขลา', 'Songkhla' => 'สงขลา',
        'ภูเก็ต' => 'ภูเก็ต', 'Phuket' => 'ภูเก็ต',
        'ระยอง' => 'ระยอง', 'Rayong' => 'ระยอง',
        'นครราชสีมา' => 'นครราชสีมา', 'Nakhon Ratchasima' => 'นครราชสีมา',
        'สุราษฎร์ธานี' => 'สุราษฎร์ธานี', 'Surat Thani' => 'สุราษฎร์ธานี',
        'นครปฐม' => 'นครปฐม', 'Nakhon Pathom' => 'นครปฐม',
        'สมุทรสาคร' => 'สมุทรสาคร', 'Samut Sakhon' => 'สมุทรสาคร',
        'ขอนแก่น' => 'ขอนแก่น', 'Khon Kaen' => 'ขอนแก่น',
        'นครศรีธรรมราช' => 'นครศรีธรรมราช', 'Nakhon Si Thammarat' => 'นครศรีธรรมราช',
        'พระนครศรีอยุธยา' => 'พระนครศรีอยุธยา', 'Phra Nakhon Si Ayutthaya' => 'พระนครศรีอยุธยา',
        'เชียงราย' => 'เชียงราย', 'Chiang Rai' => 'เชียงราย',
        'อุดรธานี' => 'อุดรธานี', 'Udon Thani' => 'อุดรธานี',
        'ฉะเชิงเทรา' => 'ฉะเชิงเทรา', 'Chachoengsao' => 'ฉะเชิงเทรา',
        'อุบลราชธานี' => 'อุบลราชธานี', 'Ubon Ratchathani' => 'อุบลราชธานี',
        'จันทบุรี' => 'จันทบุรี', 'Chanthaburi' => 'จันทบุรี',
        'สระบุรี' => 'สระบุรี', 'Saraburi' => 'สระบุรี',
        'ราชบุรี' => 'ราชบุรี', 'Ratchaburi' => 'ราชบุรี',
        'พิษณุโลก' => 'พิษณุโลก', 'Phitsanulok' => 'พิษณุโลก',
        'กระบี่' => 'กระบี่', 'Krabi' => 'กระบี่',
        'ประจวบคีรีขันธ์' => 'ประจวบคีรีขันธ์', 'Prachuap Khiri Khan' => 'ประจวบคีรีขันธ์',
        'หนองคาย' => 'หนองคาย', 'Nong Khai' => 'หนองคาย',
        'กาญจนบุรี' => 'กาญจนบุรี', 'Kanchanaburi' => 'กาญจนบุรี',
        'นครสวรรค์' => 'นครสวรรค์', 'Nakhon Sawan' => 'นครสวรรค์',
        'พัทลุง' => 'พัทลุง', 'Phatthalung' => 'พัทลุง',
        'บุรีรัมย์' => 'บุรีรัมย์', 'Buri Ram' => 'บุรีรัมย์',
        'สุพรรณบุรี' => 'สุพรรณบุรี', 'Suphan Buri' => 'สุพรรณบุรี',
        'ปราจีนบุรี' => 'ปราจีนบุรี', 'Prachin Buri' => 'ปราจีนบุรี',
        'ลพบุรี' => 'ลพบุรี', 'Lop Buri' => 'ลพบุรี',
        'ชุมพร' => 'ชุมพร', 'Chumphon' => 'ชุมพร',
        'เพชรบุรี' => 'เพชรบุรี', 'Phetchaburi' => 'เพชรบุรี',
        'ตรัง' => 'ตรัง', 'Trang' => 'ตรัง',
        'ลำปาง' => 'ลำปาง', 'Lampang' => 'ลำปาง',
        'เพชรบูรณ์' => 'เพชรบูรณ์', 'Phetchabun' => 'เพชรบูรณ์',
        'สุรินทร์' => 'สุรินทร์', 'Surin' => 'สุรินทร์',
        'สระแก้ว' => 'สระแก้ว', 'Sa Kaeo' => 'สระแก้ว',
        'กำแพงเพชร' => 'กำแพงเพชร', 'Kamphaeng Phet' => 'กำแพงเพชร',
        'ร้อยเอ็ด' => 'ร้อยเอ็ด', 'Roi Et' => 'ร้อยเอ็ด',
        'มหาสารคาม' => 'มหาสารคาม', 'Maha Sarakham' => 'มหาสารคาม',
        'ศรีสะเกษ' => 'ศรีสะเกษ', 'Si Sa Ket' => 'ศรีสะเกษ',
        'สกลนคร' => 'สกลนคร', 'Sakon Nakhon' => 'สกลนคร',
        'ตาก' => 'ตาก', 'Tak' => 'ตาก',
        'พังงา' => 'พังงา', 'Phang Nga' => 'พังงา',
        'ยะลา' => 'ยะลา', 'Yala' => 'ยะลา',
        'ชัยภูมิ' => 'ชัยภูมิ', 'Chaiyaphum' => 'ชัยภูมิ',
        'ลำพูน' => 'ลำพูน', 'Lamphun' => 'ลำพูน',
        'เลย' => 'เลย', 'Loei' => 'เลย',
        'กาฬสินธุ์' => 'กาฬสินธุ์', 'Kalasin' => 'กาฬสินธุ์',
        'ปัตตานี' => 'ปัตตานี', 'Pattani' => 'ปัตตานี',
        'นราธิวาส' => 'นราธิวาส', 'Narathiwat' => 'นราธิวาส',
        'ตราด' => 'ตราด', 'Trat' => 'ตราด',
        'นครพนม' => 'นครพนม', 'Nakhon Phanom' => 'นครพนม',
        'สุโขทัย' => 'สุโขทัย', 'Sukhothai' => 'สุโขทัย',
        'พิจิตร' => 'พิจิตร', 'Phichit' => 'พิจิตร',
        'พะเยา' => 'พะเยา', 'Phayao' => 'พะเยา',
        'สตูล' => 'สตูล', 'Satun' => 'สตูล',
        'นครนายก' => 'นครนายก', 'Nakhon Nayok' => 'นครนายก',
        'อุตรดิตถ์' => 'อุตรดิตถ์', 'Uttaradit' => 'อุตรดิตถ์',
        'แพร่' => 'แพร่', 'Phrae' => 'แพร่',
        'น่าน' => 'น่าน', 'Nan' => 'น่าน',
        'สมุทรสงคราม' => 'สมุทรสงคราม', 'Samut Songkhram' => 'สมุทรสงคราม',
        'ชัยนาท' => 'ชัยนาท', 'Chai Nat' => 'ชัยนาท',
        'อ่างทอง' => 'อ่างทอง', 'Ang Thong' => 'อ่างทอง',
        'หนองบัวลำภู' => 'หนองบัวลำภู', 'Nong Bua Lam Phu' => 'หนองบัวลำภู',
        'อุทัยธานี' => 'อุทัยธานี', 'Uthai Thani' => 'อุทัยธานี',
        'ระนอง' => 'ระนอง', 'Ranong' => 'ระนอง',
        'บึงกาฬ' => 'บึงกาฬ', 'Bueng Kan' => 'บึงกาฬ',
        'ยโสธร' => 'ยโสธร', 'Yasothon' => 'ยโสธร',
        'มุกดาหาร' => 'มุกดาหาร', 'Mukdahan' => 'มุกดาหาร',
        'อำนาจเจริญ' => 'อำนาจเจริญ', 'Amnat Charoen' => 'อำนาจเจริญ',
        'แม่ฮ่องสอน' => 'แม่ฮ่องสอน', 'Mae Hong Son' => 'แม่ฮ่องสอน',
        'สิงห์บุรี' => 'สิงห์บุรี', 'Sing Buri' => 'สิงห์บุรี',
    ];
    // Longest key first — defends against a shorter key accidentally matching inside a
    // longer, unrelated one (verified no such collision exists in this map today, but
    // cheap insurance against a bad addition later).
    uksort($map, fn($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $cases = [];
    foreach ($map as $needle => $canon) {
        $n = str_replace("'", "''", $needle);
        $c = str_replace("'", "''", $canon);
        $cases[] = "WHEN {$column} LIKE '%{$n}%' THEN N'{$c}'";
    }
    return "CASE\n        " . implode("\n        ", $cases) . "\n        ELSE N'อื่นๆ/ไม่ระบุ'\n    END";
}

function ui_text(array $ui, string $key): string
{
    return $ui[$key] ?? $key;
}

function hint_icon(string $text): string
{
    return '<span class="hint-icon">?<span class="hint-bubble">' . htmlspecialchars($text) . '</span></span>';
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

$dateInfo = fetch_one($conn, "
    SELECT
        CAST(MAX(order_datetime) AS date) AS maxDate,
        DATEFROMPARTS(YEAR(MAX(order_datetime)), MONTH(MAX(order_datetime)), 1) AS mtdStart
    FROM fact_online_orders
");

$maxDate = $dateInfo['maxDate'];
$mtdStart = $dateInfo['mtdStart'];
$uiLanguage = request_value('lang', 'th', ['th', 'en']);
$translations = [
    'th' => [
        'page_title' => 'แดชบอร์ดยอดขายออนไลน์',
        'page_subtitle' => 'ติดตามผลการขายจากข้อมูลจริง all_report (fact_online_orders)',
        'all_platforms' => 'ทุกแพลตฟอร์ม',
        'today' => 'วันนี้',
        'mtd' => 'เดือนนี้',
        'ytd' => 'ปีนี้',
        'online' => 'ออนไลน์',
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
        'gift' => 'ของแถม',
        'language' => 'ภาษา',
        'thai' => 'ไทย',
        'english' => 'อังกฤษ',
        'hero_title' => 'ยอดขายสุทธิออนไลน์เดือนนี้',
        'hero_title_today' => 'ยอดขายสุทธิออนไลน์วันนี้',
        'hero_title_mtd' => 'ยอดขายสุทธิออนไลน์เดือนนี้',
        'hero_title_ytd' => 'ยอดขายสุทธิออนไลน์ปีนี้',
        'source_ordersummary' => 'แหล่งข้อมูล: fact_online_orders',
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
        'product_mix' => 'สัดส่วนสินค้า',
        'order_status' => 'สถานะออเดอร์',
        'peak_hours_chart' => 'ยอดออเดอร์ตามช่วงเวลา',
        'tooltip_peak_hours' => 'จำนวนออเดอร์แยกตามชั่วโมง ย้อนหลัง 90 วัน (ไม่ขึ้นกับตัวกรองช่วงเวลาด้านบน) ใช้วางแผนโปรโมชั่น/กำลังคนซัพพอร์ตช่วงเวลาที่คนสั่งเยอะ',
        'weekday_orders_chart' => 'ยอดออเดอร์ตามวันในสัปดาห์',
        'tooltip_weekday_orders' => 'จำนวนออเดอร์แยกตามวันในสัปดาห์ ย้อนหลัง 90 วัน (ไม่ขึ้นกับตัวกรองช่วงเวลาด้านบน)',
        'platform_performance' => 'ผลการดำเนินงานตามแพลตฟอร์ม',
        'product' => 'สินค้า',
        'net_sales' => 'ยอดขายสุทธิ',
        'units' => 'ชิ้น',
        'top_products' => 'สินค้าขายดี',
        'gift_excluded' => 'ไม่รวมรายการของแถม เรียงตามยอดขายสุทธิ%s',
        'daily_control' => 'ควบคุมงานรายวัน',
        'operator_followup' => 'สำหรับทีมปฏิบัติการติดตามต่อ',
        'today_sales' => 'ยอดขายวันนี้',
        'exception_orders' => 'ออเดอร์ที่ต้องติดตาม',
        'cancelled' => 'ยกเลิก',
        'return' => 'คืนสินค้า',
        'pending' => 'รอดำเนินการ',
        'chart_net_sales' => 'ยอดขายสุทธิ',
        'baseline_yoy' => 'เทียบช่วงเดียวกันปีก่อน',
        'baseline_mom' => 'เทียบช่วงเดียวกันเดือนก่อน',
        'baseline_wow' => 'เทียบวันเดียวกันสัปดาห์ก่อน',
        'no_baseline' => 'ยังไม่มีฐานปีก่อนให้เทียบ (ข้อมูลเริ่ม ธ.ค. 2025)',
        'excluded_note' => 'ไม่รวมยกเลิก/คืนสินค้า',
        'excluded_orders' => 'ออเดอร์',
        'lost_revenue' => 'ยอดสูญเสียจากยกเลิก/คืน',
        'cancel_watch_title' => 'เฝ้าระวังอัตรายกเลิก',
        'cancel_watch_subtitle' => 'อัตรายกเลิกล่าสุดเทียบค่าเฉลี่ย 30 วัน แยกตามแพลตฟอร์ม',
        'cancel_watch_ok' => 'ไม่มีแพลตฟอร์มที่อัตรายกเลิกผิดปกติ',
        'latest_col' => 'ล่าสุด',
        'norm_col' => 'ปกติ (30 วัน)',
        'lost_revenue_col' => 'ยอดสูญเสีย',
        'attention_title' => 'แพลตฟอร์มที่ต้องติดตาม',
        'attention_subtitle' => 'ยอดขายวันล่าสุดเทียบค่าเฉลี่ยวันเดียวกันใน 4 สัปดาห์',
        'attention_ok' => 'ทุกแพลตฟอร์มยอดขายอยู่ในเกณฑ์ปกติ',
        'status_no_sales' => 'ไม่มียอดขาย',
        'status_below' => 'ต่ำกว่าปกติ',
        'baseline_col' => 'ค่าเฉลี่ยปกติ',
        'vs_normal_col' => '% ของปกติ',
        'status_col' => 'สถานะ',
        'disc_anomaly_title' => 'ส่วนลดผิดปกติ',
        'disc_anomaly_subtitle' => '% ส่วนลดวันล่าสุดเทียบค่าเฉลี่ย 28 วัน แยกตามแพลตฟอร์ม',
        'disc_anomaly_ok' => 'ไม่พบส่วนลดผิดปกติ',
        'disc_latest_col' => 'ส่วนลดล่าสุด',
        'disc_norm_col' => 'ส่วนลดปกติ',
        'disc_diff_col' => 'ส่วนต่าง',
        'gross_col' => 'ยอดขายก่อนหักส่วนลด',
        'discount_tier_trend' => 'แนวโน้มกลุ่มส่วนลดรายวัน',
        'tooltip_discount_tier_trend' => 'สัดส่วนยอดขายสุทธิแยกตามกลุ่มส่วนลด (ไม่มีส่วนลด / มีส่วนลด / ส่วนลดสูง) ในแต่ละวัน — กราฟนี้แสดงทุกกลุ่มเสมอ ไม่ขึ้นกับตัวกรองแคมเปญด้านบน',
        'projection_label' => 'คาดการณ์สิ้นเดือน',
        'projection_note' => 'คำนวณจากยอดจริง + ค่าเฉลี่ยตามวันในสัปดาห์',
        'vs_prev_month' => 'เทียบเดือนก่อน',
        'vs_same_month_ly' => 'เทียบเดือนเดียวกันปีก่อน',
        'tooltip_cancel_watch' => 'เทียบอัตรายกเลิกออเดอร์ของแต่ละแพลตฟอร์มในวันล่าสุด กับค่าเฉลี่ย 30 วันย้อนหลัง ถ้าสูงผิดปกติจะขึ้นเตือนพร้อมมูลค่ายอดขายที่เสียไป',
        'tooltip_attention' => 'เทียบยอดขายวันล่าสุดของแต่ละแพลตฟอร์ม กับค่าเฉลี่ยยอดขาย "วันเดียวกันของสัปดาห์" (เช่น จันทร์เทียบจันทร์) ย้อนหลัง 4 สัปดาห์ ถ้าต่ำกว่าปกติมากจะขึ้นเตือน',
        'tooltip_disc_anomaly' => 'เทียบ % ส่วนลดวันล่าสุด กับค่าเฉลี่ยส่วนลด 28 วันย้อนหลัง ถ้าส่วนลดพุ่งขึ้นผิดปกติ อาจเป็นสัญญาณตั้งราคาผิดหรือโปรที่ไม่ได้วางแผน',
        'tooltip_projection' => 'คาดการณ์ยอดขายรวมทั้งเดือน = ยอดที่ขายไปแล้ว + ค่าเฉลี่ยยอดขายตามวันในสัปดาห์ของวันที่เหลือ (ให้น้ำหนักเสาร์-อาทิตย์มากกว่าวันธรรมดา)',
        'tooltip_vs_prev_month' => 'ยอดคาดการณ์สิ้นเดือนนี้ เทียบกับยอดขายจริงทั้งเดือนของเดือนก่อนหน้า',
        'tooltip_vs_same_month_ly' => 'ยอดคาดการณ์สิ้นเดือนนี้ เทียบกับยอดขายจริงของเดือนเดียวกันปีที่แล้ว แสดงเฉพาะเมื่อมีข้อมูลปีก่อนที่เชื่อถือได้ (เริ่ม ธ.ค. 2025)',
        'tooltip_lost_revenue' => 'ยอดขายที่หายไปจากออเดอร์ที่ถูกยกเลิกหรือคืนสินค้า ในช่วงเวลาที่เลือก',
        'customer_insights' => 'ข้อมูลเชิงลึกลูกค้า',
        'customer_analysis' => 'สำหรับทีม CRM และการตลาด',
        'segment_revenue_chart' => 'ยอดขายและ AOV ตามระดับลูกค้า',
        'tooltip_segment_revenue' => 'ยอดขายสุทธิและค่าเฉลี่ยต่อออเดอร์ (AOV) แยกตามระดับลูกค้า (New/Regular/VIP) ในช่วงเวลาที่เลือกด้านบน ระดับลูกค้ามาจาก DimOnlineCustomer.customer_segment ซึ่งกำหนดโดยทีม ETL ไม่ใช่ query นี้',
        'repeat_purchase_chart' => 'จำนวนออเดอร์สะสมต่อลูกค้า',
        'tooltip_repeat_purchase' => 'นับจำนวนออเดอร์ทั้งหมด (ตลอดประวัติ ไม่ขึ้นกับตัวกรองช่วงเวลาด้านบน) ที่ลูกค้าแต่ละคนเคยสั่ง ใช้ดูสัดส่วนลูกค้าซื้อครั้งเดียวเทียบกับลูกค้าประจำ',
        'bucket_1' => 'ซื้อ 1 ครั้ง',
        'bucket_2_3' => 'ซื้อ 2-3 ครั้ง',
        'bucket_4_9' => 'ซื้อ 4-9 ครั้ง',
        'bucket_10_plus' => 'ซื้อ 10+ ครั้ง',
        'customers_col' => 'จำนวนลูกค้า',
        'province_revenue_chart' => 'ยอดขาย 10 จังหวัดสูงสุด',
        'tooltip_province_revenue' => 'ยอดขายสุทธิแยกตามจังหวัดของลูกค้า (10 อันดับแรก) ในช่วงเวลาที่เลือกด้านบน จังหวัดมาจากช่องที่ลูกค้ากรอกเองตอนสั่งซื้อ (ไม่ใช่ dropdown) จึงรวมคำสะกด/ภาษาที่ต่างกันเข้าด้วยกันก่อนแสดงผล (ครอบคลุม 99.8% ของข้อมูล ส่วนที่เหลืออ่านไม่ออก/ไม่ใช่จังหวัดจริงถูกจัดเป็นกลุ่ม "อื่นๆ/ไม่ระบุ" แทนการทิ้งไป)',
    ],
    'en' => [
        'page_title' => 'Online Sales Dashboard',
        'page_subtitle' => 'Sales performance from all_report (fact_online_orders)',
        'all_platforms' => 'All Platforms',
        'today' => 'Today',
        'mtd' => 'MTD',
        'ytd' => 'YTD',
        'online' => 'Online',
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
        'gift' => 'Gift',
        'language' => 'Language',
        'thai' => 'Thai',
        'english' => 'English',
        'hero_title' => 'MTD Online Net Sales',
        'hero_title_today' => 'Today Online Net Sales',
        'hero_title_mtd' => 'MTD Online Net Sales',
        'hero_title_ytd' => 'YTD Online Net Sales',
        'source_ordersummary' => 'Source: fact_online_orders',
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
        'product_mix' => 'Product Mix',
        'order_status' => 'Order Status',
        'peak_hours_chart' => 'Orders by Hour of Day',
        'tooltip_peak_hours' => 'Order count by hour, trailing 90 days (independent of the date-range filter above). Use for promo timing / support staffing around peak hours.',
        'weekday_orders_chart' => 'Orders by Day of Week',
        'tooltip_weekday_orders' => 'Order count by weekday, trailing 90 days (independent of the date-range filter above).',
        'platform_performance' => 'Platform Performance',
        'product' => 'Product',
        'net_sales' => 'Net Sales',
        'units' => 'Units',
        'top_products' => 'Top Products',
        'gift_excluded' => 'Gift lines excluded. Ranked by %s net sales.',
        'daily_control' => 'Daily Control',
        'operator_followup' => 'For operator follow-up',
        'today_sales' => 'Today Sales',
        'exception_orders' => 'Exception Orders',
        'cancelled' => 'Cancelled',
        'return' => 'Return',
        'pending' => 'Pending',
        'chart_net_sales' => 'Net Sales',
        'baseline_yoy' => 'vs same period last year',
        'baseline_mom' => 'vs same period last month',
        'baseline_wow' => 'vs same day last week',
        'no_baseline' => 'No prior-year baseline yet (data starts Dec 2025)',
        'excluded_note' => 'Excl. cancelled/returns',
        'excluded_orders' => 'orders',
        'lost_revenue' => 'Lost revenue (cancel/return)',
        'cancel_watch_title' => 'Cancel-Rate Watch',
        'cancel_watch_subtitle' => 'Latest-day cancel rate vs 30-day norm, by platform',
        'cancel_watch_ok' => 'No platform showing an unusual cancel rate',
        'latest_col' => 'Latest',
        'norm_col' => 'Norm (30d)',
        'lost_revenue_col' => 'Lost Revenue',
        'attention_title' => 'Platforms Needing Attention',
        'attention_subtitle' => 'Latest day vs same-weekday 4-week average',
        'attention_ok' => 'All platforms tracking within normal range',
        'status_no_sales' => 'No sales',
        'status_below' => 'Below normal',
        'baseline_col' => 'Normal Avg',
        'vs_normal_col' => '% of Normal',
        'status_col' => 'Status',
        'disc_anomaly_title' => 'Discount Anomaly',
        'disc_anomaly_subtitle' => 'Latest-day discount % vs 28-day norm, by platform',
        'disc_anomaly_ok' => 'No discount anomalies detected',
        'disc_latest_col' => 'Latest Disc %',
        'disc_norm_col' => 'Normal Disc %',
        'disc_diff_col' => 'Diff',
        'gross_col' => 'Gross Sales',
        'discount_tier_trend' => 'Discount-Tier Trend',
        'tooltip_discount_tier_trend' => 'Daily net sales split by discount tier (no discount / discounted / high discount) — always shows all tiers, independent of the Campaign filter above.',
        'projection_label' => 'Month-End Projection',
        'projection_note' => 'Actual so far + weekday-weighted average',
        'vs_prev_month' => 'vs Prev Month',
        'vs_same_month_ly' => 'vs Same Month LY',
        'tooltip_cancel_watch' => 'Compares each platform\'s cancel rate on the latest complete day against its 30-day average. Flags when unusually high, with the revenue value lost.',
        'tooltip_attention' => 'Compares each platform\'s latest-day sales against its own average for the "same weekday" (e.g. Monday vs Monday) over the past 4 weeks. Flags when well below normal.',
        'tooltip_disc_anomaly' => 'Compares each platform\'s discount % on the latest day against its 28-day average. A sudden spike may signal a pricing mistake or an unplanned promotion.',
        'tooltip_projection' => 'Projected full-month total = sales so far + the weekday-weighted average for the remaining days (weekends weighted higher than weekdays).',
        'tooltip_vs_prev_month' => 'This month\'s projected total compared with last month\'s actual full-month sales.',
        'tooltip_vs_same_month_ly' => 'This month\'s projected total compared with the same month last year. Only shown once reliable prior-year data exists (from Dec 2025).',
        'tooltip_lost_revenue' => 'Sales value lost from orders that were cancelled or returned in the selected period.',
        'customer_insights' => 'Customer Insights',
        'customer_analysis' => 'For CRM and marketing teams',
        'segment_revenue_chart' => 'Net Sales & AOV by Customer Segment',
        'tooltip_segment_revenue' => 'Net sales and average order value (AOV) split by customer segment (New/Regular/VIP) for the selected date range above. Segment comes from DimOnlineCustomer.customer_segment, assigned by the ETL, not computed here.',
        'repeat_purchase_chart' => 'Lifetime Orders per Customer',
        'tooltip_repeat_purchase' => 'Counts each customer\'s total order count across their full history (independent of the date-range filter above). Shows the one-time-buyer vs repeat-customer split.',
        'bucket_1' => '1 order',
        'bucket_2_3' => '2-3 orders',
        'bucket_4_9' => '4-9 orders',
        'bucket_10_plus' => '10+ orders',
        'customers_col' => 'Customers',
        'province_revenue_chart' => 'Top 10 Provinces by Net Sales',
        'tooltip_province_revenue' => 'Net sales by customer province (top 10) for the selected date range above. Province is free text typed by the customer at checkout, not a dropdown — variant spellings/languages are collapsed into one bucket per province before charting (covers 99.8% of rows; unreadable/non-province entries land in an "อื่นๆ/ไม่ระบุ" (Other/Unspecified) bucket instead of being dropped).',
    ],
];
$ui = $translations[$uiLanguage];
$shopRows = fetch_all($conn, "
    SELECT platform
    FROM fact_online_orders
    WHERE NULLIF(platform, '') IS NOT NULL
    GROUP BY platform
    ORDER BY platform;
");
$shopOptions = ['all' => ui_text($ui, 'all_platforms')];
foreach ($shopRows as $shopRow) {
    $shopOptions[(string) $shopRow['platform']] = (string) $shopRow['platform'];
}

$filterValues = [
    'lang' => $uiLanguage,
    'date_range' => request_value('date_range', 'mtd', ['today', 'mtd', 'ytd']),
    /**
     * Only one real option — this file only ever reads fact_online_orders, there's no
     * offline data source here to switch to. Used to also allow 'all' ("All Online"),
     * but that value was never read in any SQL WHERE clause: audit proved
     * ?channel=all vs ?channel=online rendered byte-identical HTML except which
     * dropdown option showed selected — a fake choice that did nothing. Restricted to
     * the one real value, same pattern dashboard_offline.php already uses for its own
     * single-channel dropdown.
     */
    'channel' => request_value('channel', 'online', ['online']),
    'branch' => request_value('branch', 'all', array_keys($shopOptions)),
    'category' => request_value('category', 'all', ['all', 'body_oil_sunscreen', 'body_oil', 'parfum', 'body_mist', 'lip_oil', 'hand_cream', 'other']),
    'campaign' => request_value('campaign', 'all', ['all', 'discounted', 'no_discount', 'high_discount']),
    'sales_type' => request_value('sales_type', 'all', ['all', 'Normal', 'Gift']),
];
$filterOptions = [
    'date_range' => ['today' => ui_text($ui, 'today'), 'mtd' => ui_text($ui, 'mtd'), 'ytd' => ui_text($ui, 'ytd')],
    'channel' => ['online' => ui_text($ui, 'online')],
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
 * WHICH shift is chosen depends on data availability: fact_online_orders only has
 * real volume from Dec 2025 (confirmed on the all_report ETL too — Apr-Nov 2025 is a
 * ramp-up period peaking around ฿0.6-2.6M/month vs ~฿20-29M/month from Dec 2025 on —
 * a YoY badge against the ramp-up period would be pure noise). So YoY is used
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

$periodLabel = (new DateTime($periodStart))->format('M j') . ' - ' . (new DateTime($periodEnd))->modify('-1 day')->format('M j, Y');
/**
 * i.product_group is a persisted computed column on fact_online_order_items (see
 * scripts/add_product_group_column.sql) precomputing this exact CASE expression —
 * verified to match product_group_case() row-for-row. Reading the indexed column
 * avoids re-evaluating 6 LIKE patterns per row on every request.
 */
$productGroupSql = 'i.product_group';

/**
 * fact_online_orders (order header) and fact_online_order_items (line items) replace
 * the old flat OrderSummary, which had order fields denormalized onto every line —
 * so every query below now needs both tables. Filters split by which table actually
 * carries that column: platform/date/status/campaign live on the order header;
 * category and the gift/sales-type split are line-item attributes.
 */
$headerConditions = [];
$headerParams = [];
$itemConditions = [];
$itemParams = [];
if ($filterValues['branch'] !== 'all') {
    append_filter($headerConditions, $headerParams, 'o.platform = ?', $filterValues['branch']);
}
if ($filterValues['category'] !== 'all') {
    append_filter($itemConditions, $itemParams, 'i.product_group = ?', $filterValues['category']);
}
// Snapshot before the campaign condition below — the tier-trend chart needs to show
// all three discount tiers regardless of which one the Campaign filter has selected.
$headerConditionsNoCampaign = $headerConditions;
$headerParamsNoCampaign = $headerParams;
/**
 * Campaign (discount-tier) filter must live at the ORDER header level: the item-level
 * discount_per_item column is populated as 0 on every row in this ETL (verified — not
 * a filter-mismatch, the data simply isn't there), so per-line discount % can't be
 * computed. o.discount_amount/o.subtotal are the only reliable discount figures this
 * ETL provides, at order grain.
 */
if ($filterValues['campaign'] === 'discounted') {
    append_filter($headerConditions, $headerParams, 'o.discount_amount > 0');
} elseif ($filterValues['campaign'] === 'no_discount') {
    append_filter($headerConditions, $headerParams, 'o.discount_amount = 0');
} elseif ($filterValues['campaign'] === 'high_discount') {
    append_filter($headerConditions, $headerParams, 'o.subtotal > 0 AND (o.discount_amount * 100.0 / o.subtotal) >= 20');
}
/**
 * There is no itemType column anywhere in this ETL (verified against the full schema),
 * so the old Normal/Combined/Gift split can't be reproduced directly. unit_price = 0 is
 * used as the Gift proxy instead — it lines up with the old system's own definition
 * (gift/sample lines always carried netSale = 0), just derived differently. "Combined"
 * has no equivalent signal at all and has been dropped from the filter. Default
 * behavior (excluding free lines from unit counts, to avoid giveaway-line qty
 * inflation) is preserved.
 */
if ($filterValues['sales_type'] === 'Gift') {
    append_filter($itemConditions, $itemParams, 'i.unit_price = 0');
} else {
    append_filter($itemConditions, $itemParams, 'i.unit_price <> 0');
}
$headerSql = $headerConditions ? ' AND ' . implode(' AND ', $headerConditions) : '';
$itemSql = $itemConditions ? ' AND ' . implode(' AND ', $itemConditions) : '';
$headerSqlNoCampaign = $headerConditionsNoCampaign ? ' AND ' . implode(' AND ', $headerConditionsNoCampaign) : '';

/**
 * Cancelled rows carry full revenue values, so counting them overstates every revenue/
 * volume KPI (same rationale as before). Applied to revenue queries only — the Order
 * Status chart and the Exception Orders card must keep seeing all statuses, that is
 * their whole job. 'Return' is kept in the exclusion list for forward-compatibility
 * even though this data currently has no 'Return' status (confirmed: Delivered,
 * Cancelled, WaitConfirm, Delivering, WaitPay, WaitOuterDeliver, Question).
 */
$validSalesSql = " AND o.order_status NOT IN ('Cancelled', 'Return')";

/**
 * Pre-aggregates each order's item lines (units + revenue) once, filtered by category/
 * gift-line conditions. Joining this (instead of the raw item table) into every
 * order-grain query below avoids fan-out — an order with N item lines would otherwise
 * have its header fields (discount_amount, order_status, etc.) counted N times.
 * SUM(i.line_total) was verified against a sample of orders to exactly equal
 * o.total_amount, so it's a reliable revenue figure even though it doesn't reconcile
 * with o.subtotal (subtotal/unit_price appear to reflect pre-livestream catalog
 * pricing, not the actual transaction amount — see SYSTEM_MAP.md Known Data Risks).
 *
 * IMPORTANT — every query below must LEFT JOIN this (never INNER JOIN), and wrap
 * ia.netSales/ia.units in COALESCE(..., o.total_amount / 0). Audit found 84,442 real
 * orders (25.4% of all valid orders, ฿12.13M) that have an order header in
 * fact_online_orders but ZERO rows in fact_online_order_items — an ETL gap, not a
 * gift/category filter effect. An INNER JOIN silently drops these orders from every
 * KPI/chart/table on the page, including order-header fields (discount_amount,
 * order_status) that have nothing to do with the item-level filter. The drop is
 * concentrated in Nov-Dec 2025 (51-62% of those months' revenue missing) — exactly the
 * window this page later uses as its YoY baseline, so the effect was invisible today but
 * would have produced a false YoY growth spike once the calendar reached Dec 2026.
 */
$itemAggCte = "item_agg AS (
        SELECT i.order_key, SUM(i.quantity) AS units, SUM(i.line_total) AS netSales
        FROM fact_online_order_items i
        WHERE 1=1 {$itemSql}
        GROUP BY i.order_key
    )";

$summaryRows = fetch_all($conn, "
    WITH {$itemAggCte}
    SELECT
        'mtd' AS period,
        COUNT(DISTINCT o.order_key) AS orders,
        SUM(COALESCE(ia.units, 0)) AS units,
        SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales,
        SUM(o.subtotal) AS grossSales,
        SUM(o.discount_amount) AS discount
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$validSalesSql}

    UNION ALL

    SELECT
        'prev_mtd' AS period,
        COUNT(DISTINCT o.order_key) AS orders,
        SUM(COALESCE(ia.units, 0)) AS units,
        SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales,
        SUM(o.subtotal) AS grossSales,
        SUM(o.discount_amount) AS discount
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$validSalesSql};
", array_merge($itemParams, [$periodStart, $periodEnd], $headerParams, [$targetStart, $targetEnd], $headerParams));

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
    WITH {$itemAggCte}
    SELECT
        CONVERT(varchar(10), CAST(o.order_datetime AS date), 120) AS saleDate,
        COUNT(DISTINCT o.order_key) AS orders,
        SUM(COALESCE(ia.units, 0)) AS units,
        SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales,
        SUM(o.discount_amount) AS discount
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$validSalesSql}
    GROUP BY CAST(o.order_datetime AS date)
    ORDER BY CAST(o.order_datetime AS date);
", array_merge($itemParams, [$periodStart, $periodEnd], $headerParams));

$todaySales = 0.0;
if (!empty($dailyTrend)) {
    $lastDailyRow = $dailyTrend[count($dailyTrend) - 1];
    $todaySales = n($lastDailyRow['netSales'] ?? 0);
}

/**
 * Discount-tier bucketing repeats the same CASE the campaign filter uses (:482-487)
 * so the two stay in sync. Uses $headerSqlNoCampaign — this chart's whole point is
 * to show all three tiers regardless of which one the Campaign filter has selected.
 */
$discountTierCase = "CASE
        WHEN o.subtotal > 0 AND (o.discount_amount * 100.0 / o.subtotal) >= 20 THEN 'high_discount'
        WHEN o.discount_amount > 0 THEN 'discounted'
        ELSE 'no_discount'
    END";
$discountTierRows = fetch_all($conn, "
    WITH {$itemAggCte}
    SELECT
        CONVERT(varchar(10), CAST(o.order_datetime AS date), 120) AS saleDate,
        {$discountTierCase} AS bucket,
        COUNT(DISTINCT o.order_key) AS orders,
        SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSqlNoCampaign}{$validSalesSql}
    GROUP BY CAST(o.order_datetime AS date), {$discountTierCase}
    ORDER BY CAST(o.order_datetime AS date);
", array_merge($itemParams, [$periodStart, $periodEnd], $headerParamsNoCampaign));

$discountTierBuckets = ['no_discount', 'discounted', 'high_discount'];
$discountTierTrend = [];
foreach ($discountTierBuckets as $bucket) {
    $discountTierTrend[$bucket] = array_fill_keys(array_column($dailyTrend, 'saleDate'), 0.0);
}
foreach ($discountTierRows as $row) {
    $discountTierTrend[$row['bucket']][$row['saleDate']] = n($row['netSales'] ?? 0);
}

$platforms = fetch_all($conn, "
    WITH {$itemAggCte}
    SELECT
        o.platform AS shopName,
        COUNT(DISTINCT o.order_key) AS orders,
        SUM(COALESCE(ia.units, 0)) AS units,
        SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales,
        SUM(o.subtotal) AS grossSales,
        SUM(o.discount_amount) AS discount
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$validSalesSql}
    GROUP BY o.platform
    ORDER BY netSales DESC;
", array_merge($itemParams, [$periodStart, $periodEnd], $headerParams));

$productMix = fetch_all($conn, "
    SELECT
        {$productGroupSql} AS productGroup,
        SUM(i.quantity) AS units,
        SUM(i.line_total) AS netSales
    FROM fact_online_orders o
    JOIN fact_online_order_items i ON i.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$itemSql}{$validSalesSql}
    GROUP BY {$productGroupSql}
    ORDER BY netSales DESC;
", array_merge([$periodStart, $periodEnd], $headerParams, $itemParams));

/**
 * Per-product discount % (old "Disc %" column) can't be reproduced: discount_per_item
 * is 0 on every row in this ETL. sku is best-effort — DimOnlineProduct.sku_code is
 * ~92% filled (joined via product_key), falling back to the item row's own sku_code
 * (~38% filled) — both can be blank for the same product.
 */
$topProducts = fetch_all($conn, "
    WITH product_sales AS (
        SELECT TOP 15
            i.product_key,
            i.product_name AS productName,
            MAX(NULLIF(i.sku_code, '')) AS itemSku,
            SUM(i.quantity) AS units,
            SUM(i.line_total) AS netSales
        FROM fact_online_orders o
        JOIN fact_online_order_items i ON i.order_key = o.order_key
        WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$itemSql}{$validSalesSql}
        GROUP BY i.product_key, i.product_name
        ORDER BY netSales DESC
    )
    SELECT
        COALESCE(NULLIF(p.sku_code, ''), ps.itemSku) AS sku,
        ps.productName,
        ps.units,
        ps.netSales
    FROM product_sales ps
    LEFT JOIN DimOnlineProduct p ON p.product_key = ps.product_key
    ORDER BY ps.netSales DESC;
", array_merge([$periodStart, $periodEnd], $headerParams, $itemParams));

$statusRows = fetch_all($conn, "
    WITH {$itemAggCte}
    SELECT
        o.order_status AS orderStatus,
        COUNT(DISTINCT o.order_key) AS orders,
        SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}
    GROUP BY o.order_status
    ORDER BY netSales DESC;
", array_merge($itemParams, [$periodStart, $periodEnd], $headerParams));

$statusChartRows = array_values(array_filter($statusRows, function ($row) {
    return in_array($row['orderStatus'], ['Delivered', 'Cancelled'], true);
}));

/**
 * Daily-control alerts below all anchor to the latest COMPLETE day, not $maxDate.
 * The ETL loads orders well before the day is over, so $maxDate itself is a partial day —
 * comparing a half-finished day against a full-day baseline would flag every platform
 * as "underperforming" until the day closes. Independent of the date_range filter,
 * same rationale as offline's daily-control tools anchoring to its latest full day.
 */
$latestCompleteDate = (clone $maxDate)->modify('-1 day');
$latestCompleteDateStr = $latestCompleteDate->format('Y-m-d');
$latestCompleteDateNext = (clone $latestCompleteDate)->modify('+1 day')->format('Y-m-d');

// --- Cancel-Rate Watch: latest complete day vs trailing 30-day norm, by platform ---
$cancelWatchRows = fetch_all($conn, "
    WITH {$itemAggCte},
    latest AS (
        SELECT o.platform AS shopName, COUNT(DISTINCT o.order_key) AS orders_,
            COUNT(DISTINCT CASE WHEN o.order_status = 'Cancelled' THEN o.order_key END) AS cancelled_,
            SUM(CASE WHEN o.order_status = 'Cancelled' THEN COALESCE(ia.netSales, o.total_amount, 0) ELSE 0 END) AS lostNet
        FROM fact_online_orders o
        LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE CAST(o.order_datetime AS date) = ? {$headerSql}
        GROUP BY o.platform
    ),
    norm AS (
        SELECT o.platform AS shopName, COUNT(DISTINCT o.order_key) AS orders_,
            COUNT(DISTINCT CASE WHEN o.order_status = 'Cancelled' THEN o.order_key END) AS cancelled_
        FROM fact_online_orders o
        LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE o.order_datetime >= DATEADD(day, -30, ?) AND o.order_datetime < ? {$headerSql}
        GROUP BY o.platform
    )
    SELECT l.shopName, l.orders_ AS latestOrders, l.cancelled_ AS latestCancelled, l.lostNet,
        n.orders_ AS normOrders, n.cancelled_ AS normCancelled
    FROM latest l LEFT JOIN norm n ON l.shopName = n.shopName;
", array_merge($itemParams, [$latestCompleteDateStr], $headerParams, [$latestCompleteDateStr, $latestCompleteDateStr], $headerParams));

$cancelWatch = [];
foreach ($cancelWatchRows as $row) {
    $latestOrders = (int) $row['latestOrders'];
    if ($latestOrders < 20) {
        continue; // too few orders for a rate to mean anything — avoids noise from tiny channels
    }
    $latestRate = n($row['latestCancelled']) * 100.0 / $latestOrders;
    $normOrders = (int) $row['normOrders'];
    $normRate = $normOrders > 0 ? n($row['normCancelled']) * 100.0 / $normOrders : 0.0;
    $diff = $latestRate - $normRate;
    if ($latestRate >= 15.0 || $diff >= 5.0) {
        $cancelWatch[] = [
            'shopName' => $row['shopName'],
            'latestRate' => $latestRate,
            'normRate' => $normRate,
            'lostNet' => n($row['lostNet']),
            'severity' => $latestRate >= 20.0 ? 'high' : 'medium',
        ];
    }
}
usort($cancelWatch, fn($a, $b) => $b['lostNet'] <=> $a['lostNet']);

// --- Platform Attention List: latest complete day vs same-weekday 4-week average ---
$attentionRows = fetch_all($conn, "
    WITH {$itemAggCte},
    latest AS (
        SELECT o.platform AS shopName, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS net
        FROM fact_online_orders o
        LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE CAST(o.order_datetime AS date) = ? {$headerSql}{$validSalesSql}
        GROUP BY o.platform
    ),
    baseline AS (
        SELECT o.platform AS shopName, SUM(COALESCE(ia.netSales, o.total_amount, 0)) * 1.0 / COUNT(DISTINCT CAST(o.order_datetime AS date)) AS avgNet
        FROM fact_online_orders o
        LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE CAST(o.order_datetime AS date) IN (DATEADD(week, -1, ?), DATEADD(week, -2, ?), DATEADD(week, -3, ?), DATEADD(week, -4, ?)) {$headerSql}{$validSalesSql}
        GROUP BY o.platform
    )
    SELECT b.shopName, ISNULL(l.net, 0) AS latestNet, b.avgNet
    FROM baseline b LEFT JOIN latest l ON b.shopName = l.shopName;
", array_merge(
    $itemParams, [$latestCompleteDateStr], $headerParams,
    [$latestCompleteDateStr, $latestCompleteDateStr, $latestCompleteDateStr, $latestCompleteDateStr], $headerParams
));

$attentionPlatforms = [];
foreach ($attentionRows as $row) {
    $baseline = n($row['avgNet']);
    if ($baseline <= 0) {
        continue;
    }
    $latestNet = n($row['latestNet']);
    if ($latestNet <= 0) {
        $attentionPlatforms[] = ['shopName' => $row['shopName'], 'latestNet' => 0.0, 'baseline' => $baseline, 'ratio' => 0.0, 'status' => 'no_sales'];
        continue;
    }
    $ratio = $latestNet / $baseline * 100.0;
    if ($ratio < 60.0) {
        $attentionPlatforms[] = ['shopName' => $row['shopName'], 'latestNet' => $latestNet, 'baseline' => $baseline, 'ratio' => $ratio, 'status' => 'below'];
    }
}
usort($attentionPlatforms, fn($a, $b) => $a['ratio'] <=> $b['ratio']);

/**
 * Discount Anomaly uses o.discount_amount/o.subtotal (order header) rather than an
 * item join, since item-level discount_per_item is unusable (always 0 in this ETL —
 * see $itemAggCte comment above). The category/gift item filter is still applied, via
 * EXISTS, to decide which orders qualify — just not to slice the discount amount.
 */
$discAnomalyRows = fetch_all($conn, "
    WITH latest AS (
        SELECT o.platform AS shopName, SUM(o.discount_amount) AS disc, SUM(o.subtotal) AS gross
        FROM fact_online_orders o
        WHERE CAST(o.order_datetime AS date) = ? {$headerSql}{$validSalesSql}
          AND EXISTS (SELECT 1 FROM fact_online_order_items i WHERE i.order_key = o.order_key {$itemSql})
        GROUP BY o.platform
    ),
    norm AS (
        SELECT o.platform AS shopName, SUM(o.discount_amount) AS disc, SUM(o.subtotal) AS gross
        FROM fact_online_orders o
        WHERE o.order_datetime >= DATEADD(day, -28, ?) AND o.order_datetime < ? {$headerSql}{$validSalesSql}
          AND EXISTS (SELECT 1 FROM fact_online_order_items i WHERE i.order_key = o.order_key {$itemSql})
        GROUP BY o.platform
    )
    SELECT l.shopName, l.disc AS latestDisc, l.gross AS latestGross, n.disc AS normDisc, n.gross AS normGross
    FROM latest l LEFT JOIN norm n ON l.shopName = n.shopName;
", array_merge([$latestCompleteDateStr], $headerParams, $itemParams, [$latestCompleteDateStr, $latestCompleteDateStr], $headerParams, $itemParams));

$discountAnomalies = [];
foreach ($discAnomalyRows as $row) {
    $latestGross = n($row['latestGross']);
    if ($latestGross < 10000) {
        continue; // materiality guard — small gross makes % swings meaningless
    }
    $latestPct = n($row['latestDisc']) * 100.0 / $latestGross;
    $normGross = n($row['normGross']);
    $normPct = $normGross > 0 ? n($row['normDisc']) * 100.0 / $normGross : 0.0;
    $diff = $latestPct - $normPct;
    if ($diff >= 5.0 && $latestPct >= 15.0) {
        $discountAnomalies[] = ['shopName' => $row['shopName'], 'latestPct' => $latestPct, 'normPct' => $normPct, 'diff' => $diff, 'gross' => $latestGross];
    }
}
usort($discountAnomalies, fn($a, $b) => $b['diff'] <=> $a['diff']);

// --- Month-End Projection: actual so far + weekday-weighted average for remaining days ---
$mtdStartForProj = new DateTime($latestCompleteDate->format('Y-m-01'));
$mtdStartForProjStr = $mtdStartForProj->format('Y-m-d');
$monthEndForProj = new DateTime($latestCompleteDate->format('Y-m-t'));

$projActualRow = fetch_one($conn, "
    WITH {$itemAggCte}
    SELECT SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS net
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$validSalesSql};
", array_merge($itemParams, [$mtdStartForProjStr, $latestCompleteDateNext], $headerParams));
$projActualSoFar = n($projActualRow['net'] ?? 0);

$weekdayAvgRows = fetch_all($conn, "
    WITH {$itemAggCte}
    SELECT DATEPART(weekday, d) AS dow, AVG(net) AS avgNet FROM (
        SELECT CAST(o.order_datetime AS date) AS d, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS net
        FROM fact_online_orders o
        LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE o.order_datetime >= DATEADD(day, -56, ?) AND o.order_datetime < ? {$headerSql}{$validSalesSql}
        GROUP BY CAST(o.order_datetime AS date)
    ) x GROUP BY DATEPART(weekday, d);
", array_merge($itemParams, [$latestCompleteDateNext, $latestCompleteDateNext], $headerParams));
$weekdayAvg = [];
foreach ($weekdayAvgRows as $row) {
    $weekdayAvg[(int) $row['dow']] = n($row['avgNet']);
}

$remainingProjected = 0.0;
$projCursor = (clone $latestCompleteDate)->modify('+1 day');
while ($projCursor <= $monthEndForProj) {
    $dow = ((int) $projCursor->format('w')) + 1; // PHP w: Sun=0..Sat=6 -> SQL DATEPART(weekday): Sun=1..Sat=7
    $remainingProjected += $weekdayAvg[$dow] ?? 0.0;
    $projCursor->modify('+1 day');
}
$projectedMonthEnd = $projActualSoFar + $remainingProjected;

$prevMonthStartForProj = (clone $mtdStartForProj)->modify('-1 month');
$prevMonthRow = fetch_one($conn, "
    WITH {$itemAggCte}
    SELECT SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS net
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$validSalesSql};
", array_merge($itemParams, [$prevMonthStartForProj->format('Y-m-d'), $mtdStartForProjStr], $headerParams));
$prevMonthActual = n($prevMonthRow['net'] ?? 0);
$projVsPrevMonth = pct_change($projectedMonthEnd, $prevMonthActual);

$lyMonthStartForProj = (clone $mtdStartForProj)->modify('-1 year');
$lyMonthHasReliableData = $lyMonthStartForProj->format('Y-m-d') >= RELIABLE_DATA_FROM;
$lyMonthActual = 0.0;
$projVsLastYear = 0.0;
if ($lyMonthHasReliableData) {
    $lyMonthEndForProj = (clone $lyMonthStartForProj)->modify('+1 month');
    $lyMonthRow = fetch_one($conn, "
        WITH {$itemAggCte}
        SELECT SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS net
        FROM fact_online_orders o
        LEFT JOIN item_agg ia ON ia.order_key = o.order_key
        WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$validSalesSql};
    ", array_merge($itemParams, [$lyMonthStartForProj->format('Y-m-d'), $lyMonthEndForProj->format('Y-m-d')], $headerParams));
    $lyMonthActual = n($lyMonthRow['net'] ?? 0);
    $projVsLastYear = pct_change($projectedMonthEnd, $lyMonthActual);
}

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

$topPlatform = $platforms[0] ?? null;
$topProduct = $topProducts[0] ?? null;

/**
 * Peak Order Hours / Day-of-Week — pattern-insight charts (when do customers actually
 * order), used for staffing/promo timing. Deliberately anchored to a fixed trailing
 * 90-day window regardless of the page's date_range filter (a single day/month is too
 * short to reveal an hour-of-day or weekday pattern) — same "daily control" convention
 * as the cancel-rate/discount-anomaly sections above. Still respects the platform
 * (branch) filter via $headerSql.
 */
$peakHoursRows = fetch_all($conn, "
    SELECT DATEPART(hour, o.order_datetime) AS hr, COUNT(DISTINCT o.order_key) AS orders
    FROM fact_online_orders o
    WHERE o.order_datetime >= DATEADD(day, -90, ?) AND o.order_datetime < ?{$headerSql}{$validSalesSql}
    GROUP BY DATEPART(hour, o.order_datetime)
    ORDER BY hr;
", array_merge([$maxDatePlusOne, $maxDatePlusOne], $headerParams));
$peakHoursByHour = array_fill(0, 24, 0);
foreach ($peakHoursRows as $row) {
    $peakHoursByHour[(int) $row['hr']] = (int) n($row['orders']);
}

$weekdayOrderRows = fetch_all($conn, "
    SELECT DATEPART(weekday, o.order_datetime) AS dow, COUNT(DISTINCT o.order_key) AS orders
    FROM fact_online_orders o
    WHERE o.order_datetime >= DATEADD(day, -90, ?) AND o.order_datetime < ?{$headerSql}{$validSalesSql}
    GROUP BY DATEPART(weekday, o.order_datetime)
    ORDER BY dow;
", array_merge([$maxDatePlusOne, $maxDatePlusOne], $headerParams));
$weekdayOrdersByDow = array_fill(1, 7, 0); // SQL Server DATEPART(weekday): Sun=1..Sat=7
foreach ($weekdayOrderRows as $row) {
    $weekdayOrdersByDow[(int) $row['dow']] = (int) n($row['orders']);
}

/**
 * Segment revenue/AOV respects the same period+platform filters as the rest of the
 * page (headerSql/itemAggCte) — unlike Peak Hours/Weekday, this isn't a fixed trailing
 * window, since "which segment drove this month's sales" is meaningful per period.
 * Segment itself (New/Regular/VIP) is assigned upstream by the ETL on
 * DimOnlineCustomer.customer_segment — not derived here.
 */
$segmentRevenueRows = fetch_all($conn, "
    WITH {$itemAggCte}
    SELECT c.customer_segment AS segment, COUNT(DISTINCT o.order_key) AS orders, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    JOIN DimOnlineCustomer c ON c.customer_key = o.customer_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$validSalesSql}
    GROUP BY c.customer_segment
    ORDER BY netSales DESC;
", array_merge($itemParams, [$periodStart, $periodEnd], $headerParams));

$segmentOrder = ['VIP', 'Regular', 'New'];
usort($segmentRevenueRows, fn($a, $b) => array_search($a['segment'], $segmentOrder) <=> array_search($b['segment'], $segmentOrder));

/**
 * Repeat-purchase distribution is deliberately lifetime (no date-range filter) — a
 * customer's total order count only makes sense measured across their whole history,
 * same rationale as Peak Hours/Weekday above using a fixed window instead of the page
 * filter. Still respects the platform filter via $headerSql.
 */
$repeatPurchaseRows = fetch_all($conn, "
    SELECT bucket, COUNT(*) AS customers FROM (
        SELECT o.customer_key,
            CASE WHEN COUNT(*) = 1 THEN '1'
                 WHEN COUNT(*) BETWEEN 2 AND 3 THEN '2_3'
                 WHEN COUNT(*) BETWEEN 4 AND 9 THEN '4_9'
                 ELSE '10_plus' END AS bucket
        FROM fact_online_orders o
        WHERE 1=1 {$headerSql}{$validSalesSql}
        GROUP BY o.customer_key
    ) t
    GROUP BY bucket;
", $headerParams);

$repeatPurchaseBuckets = ['1', '2_3', '4_9', '10_plus'];
$repeatPurchaseCounts = array_fill_keys($repeatPurchaseBuckets, 0);
foreach ($repeatPurchaseRows as $row) {
    $repeatPurchaseCounts[$row['bucket']] = (int) n($row['customers']);
}

/**
 * Top 10 provinces by net sales, same period+platform filters as the rest of the page.
 * province_group_case() collapses the free-text province column into clean buckets —
 * see its docblock for why that's needed before this can be charted at all.
 */
$provinceGroupCase = province_group_case('c.province');
$provinceRevenueRows = fetch_all($conn, "
    WITH {$itemAggCte}
    SELECT TOP 10 {$provinceGroupCase} AS provinceGroup, COUNT(DISTINCT o.order_key) AS orders, SUM(COALESCE(ia.netSales, o.total_amount, 0)) AS netSales
    FROM fact_online_orders o
    LEFT JOIN item_agg ia ON ia.order_key = o.order_key
    JOIN DimOnlineCustomer c ON c.customer_key = o.customer_key
    WHERE o.order_datetime >= ? AND o.order_datetime < ? {$headerSql}{$validSalesSql}
    GROUP BY {$provinceGroupCase}
    ORDER BY netSales DESC;
", array_merge($itemParams, [$periodStart, $periodEnd], $headerParams));

$pageTitle = ui_text($ui, 'page_title');
$pageSubtitle = ui_text($ui, 'page_subtitle');
$accentColor = '#dab937'; // Journal brand Gold (116C) — see BRAND_COLORS.md
require_once __DIR__ . '/includes/header.php';
?>

<style>
    .online-hero {
        position: relative;
        overflow: hidden;
        background: #091113;
        border-left: 4px solid var(--accent);
        color: #fff;
        padding: 34px 36px;
        border-radius: 12px;
        margin-bottom: 28px;
        box-shadow: 0 10px 28px rgba(12,18,32,0.28);
    }
    .online-hero-top { position: relative; z-index: 1; display: flex; justify-content: space-between; gap: 28px; align-items: flex-start; }
    .online-hero-title { position: relative; padding-left: 16px; font-size: 13px; color: rgba(255,255,255,0.72); text-transform: uppercase; letter-spacing: 0.04em; font-weight: 600; }
    .online-hero-title::before {
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
        .online-hero-title::before { animation: none; }
    }
    .online-hero-value { font-size: 54px; line-height: 1.05; font-weight: 300; letter-spacing: -0.02em; margin-top: 10px; font-variant-numeric: tabular-nums; }
    .online-hero-value::before { content: '฿'; font-size: 0.5em; font-weight: 500; color: rgba(255,255,255,0.55); margin-right: 6px; }
    .online-hero-trend { margin-top: 10px; font-size: 13px; }
    .online-hero-meta { position: relative; z-index: 1; text-align: right; color: rgba(255,255,255,0.78); font-size: 13px; padding-left: 24px; border-left: 1px solid rgba(255,255,255,0.14); }
    .online-hero-meta .hero-chip { display: inline-block; margin-top: 6px; padding: 4px 10px; border-radius: 999px; background: rgba(255,255,255,0.08); font-size: 11px; }
    .metric-grid .kpi-card .value { font-variant-numeric: tabular-nums; }
    .metric-grid .kpi-card .label { padding-bottom: 10px; border-bottom: 1px solid #F3F4F6; }
    .metric-grid .kpi-card .target-line { color: #6B7280; font-size: 12px; line-height: 1.45; margin-top: 8px; }
    .change.positive { color: #10B981; }
    .change.negative { color: #EF4444; }
    .change.neutral { color: #9CA3AF; }
    .section-title { display: flex; justify-content: space-between; align-items: center; margin: 36px 0 16px; }
    .section-title:first-of-type { margin-top: 6px; }
    .section-title h2 { display: flex; align-items: center; gap: 10px; margin: 0; font-size: 16px; font-weight: 600; color: #111827; }
    .section-title h2::before { content: ''; width: 4px; height: 16px; border-radius: 2px; background: var(--accent); flex: 0 0 auto; }
    .section-title span { font-size: 12px; color: #6B7280; }
    .metric-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 24px; }
    .analysis-grid { display: grid; grid-template-columns: 1.35fr 1fr; gap: 20px; margin-bottom: 24px; }
    .three-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-bottom: 24px; }
    .ops-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
    .ops-card { background: #fff; border-radius: 8px; padding: 18px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); transition: box-shadow 0.2s ease, transform 0.2s ease; }
    .ops-card:hover { box-shadow: 0 6px 16px rgba(0,0,0,0.1); transform: translateY(-1px); }
    .ops-card .label { color: #6B7280; font-size: 12px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.03em; }
    .ops-card .value { color: #111827; font-size: 32px; font-weight: 300; margin: 8px 0 4px; font-variant-numeric: tabular-nums; }
    .ops-card .note { color: #9CA3AF; font-size: 12px; }
    .chart-card h3, .table-card h3 { padding-bottom: 12px; margin-bottom: 16px; border-bottom: 1px solid #F3F4F6; }
    .chart-card, .table-card { transition: box-shadow 0.2s ease; }
    .chart-card:hover, .table-card:hover { box-shadow: 0 4px 14px rgba(0,0,0,0.09); }
    .table-card table tbody tr { transition: background-color 0.15s ease; }
    .table-card table tbody tr:nth-child(even) { background: #FAFBFC; }
    .table-card table tbody tr:hover { background: #FBF6E7; }
    .chart-box { height: 300px; }
    .chart-box.small { height: 240px; }
    .status-pill { display: inline-block; padding: 4px 10px; border-radius: 12px; font-size: 11px; font-weight: 600; background: #F3F4F6; color: #6B7280; }
    .status-pill.high { background: rgba(239,68,68,0.12); color: #EF4444; }
    .status-pill.medium { background: rgba(245,158,11,0.15); color: #B45309; }
    .status-pill.low { background: rgba(16,185,129,0.12); color: #047857; }
    .ok-state { display: flex; align-items: center; gap: 10px; padding: 22px 4px; color: #6B7280; font-size: 13px; }
    .ok-state .dot { width: 10px; height: 10px; border-radius: 999px; background: #10B981; flex: 0 0 auto; }
    .hint-icon { display: inline-flex; align-items: center; justify-content: center; width: 15px; height: 15px; border-radius: 50%; background: #E5E7EB; color: #6B7280; font-size: 10px; font-weight: 700; margin-left: 6px; cursor: help; position: relative; vertical-align: middle; }
    .hint-icon .hint-bubble { position: absolute; bottom: 130%; left: 50%; transform: translateX(-50%); background: #111827; color: #fff; padding: 8px 10px; border-radius: 6px; font-size: 11px; font-weight: 400; line-height: 1.45; white-space: normal; width: 240px; opacity: 0; visibility: hidden; transition: opacity 0.15s ease, visibility 0.15s ease; z-index: 1000; pointer-events: none; text-align: left; }
    .hint-icon .hint-bubble::after { content: ''; position: absolute; top: 100%; left: 50%; transform: translateX(-50%); border: 5px solid transparent; border-top-color: #111827; }
    .hint-icon:hover .hint-bubble { opacity: 1; visibility: visible; }
    .ops-card { position: relative; }
    .ops-card .hint-icon { position: absolute; top: 18px; right: 18px; }
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

<div class="dash-section" data-section-id="kpi-cards" data-section-label-th="ตัวเลข KPI หลัก" data-section-label-en="Headline KPIs">
<div class="online-hero">
    <div class="online-hero-top">
        <div>
            <div class="online-hero-title"><?php echo htmlspecialchars(ui_text($ui, 'hero_title_' . $filterValues['date_range'])); ?></div>
            <div class="online-hero-value count-up" data-count-to="<?php echo (float) $mtdNetSales; ?>">0</div>
            <div class="online-hero-trend"><?php echo $growthBadge($salesGrowth); ?></div>
        </div>
        <div class="online-hero-meta">
            <div><?php echo htmlspecialchars($periodLabel); ?></div>
            <div><?php echo htmlspecialchars(ui_text($ui, 'source_ordersummary')); ?></div>
            <?php if ($excludedNet > 0 || $excludedOrders > 0): ?>
                <div class="hero-chip"><?php echo htmlspecialchars(ui_text($ui, 'excluded_note')); ?> <?php echo number_format($excludedNet, 0); ?> (<?php echo number_format($excludedOrders); ?> <?php echo htmlspecialchars(ui_text($ui, 'excluded_orders')); ?>)</div>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="metric-grid">
    <div class="kpi-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'orders')); ?></div>
        <div class="value count-up" data-count-to="<?php echo (float) $mtdOrders; ?>">0</div>
        <?php echo $growthBadge($orderGrowth); ?>
    </div>
    <div class="kpi-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'units_sold')); ?></div>
        <div class="value count-up" data-count-to="<?php echo (float) $mtdUnits; ?>">0</div>
        <?php echo $growthBadge($unitGrowth); ?>
    </div>
    <div class="kpi-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'aov')); ?></div>
        <div class="value count-up" data-count-to="<?php echo (float) $mtdAov; ?>">0</div>
        <?php echo $growthBadge($aovGrowth); ?>
    </div>
    <div class="kpi-card">
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'upt')); ?></div>
        <div class="value count-up" data-count-to="<?php echo (float) $mtdUpt; ?>" data-decimals="2">0</div>
        <div class="change positive"><?php echo htmlspecialchars(ui_text($ui, 'units_per_transaction')); ?></div>
        <div class="target-line"><?php echo htmlspecialchars(ui_text($ui, 'top_platform')); ?> <?php echo htmlspecialchars($topPlatform['shopName'] ?? '-'); ?> | <?php echo htmlspecialchars(ui_text($ui, 'top_sku')); ?> <?php echo htmlspecialchars($topProduct['sku'] ?? '-'); ?></div>
    </div>
</div>
</div>

<div class="dash-section" data-section-id="performance-diagnosis" data-section-label-th="วิเคราะห์ผลการดำเนินงาน" data-section-label-en="Performance Diagnosis">
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
</div>

<div class="dash-section" data-section-id="product-mix-grid" data-section-label-th="สัดส่วนสินค้าและสถานะคำสั่งซื้อ" data-section-label-en="Product Mix & Order Status">
<div class="analysis-grid">
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'product_mix')); ?></h3>
        <div class="chart-box small"><canvas id="productMixChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'order_status')); ?></h3>
        <div class="chart-box small"><canvas id="statusChart"></canvas></div>
    </div>
</div>

<div class="analysis-grid">
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'peak_hours_chart')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_peak_hours')); ?></h3>
        <div class="chart-box small"><canvas id="peakHoursChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'weekday_orders_chart')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_weekday_orders')); ?></h3>
        <div class="chart-box small"><canvas id="weekdayOrdersChart"></canvas></div>
    </div>
</div>
</div>

<div class="dash-section" data-section-id="platform-top-products-tables" data-section-label-th="ตารางแพลตฟอร์มและสินค้าขายดี" data-section-label-en="Platform & Top Products Tables">
<div class="split-grid max-[900px]:grid-cols-1">
    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'platform_performance')); ?></h3>
        <div class="max-[640px]:overflow-x-auto"><table>
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
        </table></div>
    </div>

    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'top_products')); ?></h3>
        <div class="table-note"><?php echo htmlspecialchars(sprintf(ui_text($ui, 'gift_excluded'), ui_text($ui, $filterValues['date_range']))); ?></div>
        <div class="max-[640px]:overflow-x-auto"><table>
            <thead>
            <tr>
                <th><?php echo htmlspecialchars(ui_text($ui, 'product')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'net_sales')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'units')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach (array_slice($topProducts, 0, 8) as $row): ?>
                <tr>
                    <td>
                        <div class="product-cell">
                            <?php echo product_image_html(null, (string) $row['sku']); ?>
                            <div class="product-meta">
                                <div class="sku"><?php echo htmlspecialchars($row['sku'] ?? ''); ?></div>
                                <div class="name"><?php echo htmlspecialchars(mb_strimwidth($row['productName'], 0, 52, '...')); ?></div>
                            </div>
                        </div>
                    </td>
                    <td><?php echo number_format(n($row['netSales']), 0); ?></td>
                    <td><?php echo number_format(n($row['units']), 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
</div>
</div>

<div class="dash-section" data-section-id="daily-control" data-section-label-th="ควบคุมงานรายวัน" data-section-label-en="Daily Control">
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
        <?php if ($excludedNet > 0): ?>
            <div class="note"><?php echo htmlspecialchars(ui_text($ui, 'lost_revenue')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_lost_revenue')); ?>: <?php echo number_format($excludedNet, 0); ?></div>
        <?php endif; ?>
    </div>
    <div class="ops-card">
        <?php echo hint_icon(ui_text($ui, 'tooltip_projection')); ?>
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'projection_label')); ?></div>
        <div class="value"><?php echo number_format($projectedMonthEnd, 0); ?></div>
        <div class="note"><?php echo htmlspecialchars(ui_text($ui, 'projection_note')); ?></div>
    </div>
    <div class="ops-card">
        <?php echo hint_icon(ui_text($ui, 'tooltip_vs_prev_month')); ?>
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'vs_prev_month')); ?></div>
        <div class="value"><?php echo number_format($prevMonthActual, 0); ?></div>
        <?php echo trend_label($projVsPrevMonth, $uiLanguage, ui_text($ui, 'vs_prev_month')); ?>
    </div>
    <div class="ops-card">
        <?php echo hint_icon(ui_text($ui, 'tooltip_vs_same_month_ly')); ?>
        <div class="label"><?php echo htmlspecialchars(ui_text($ui, 'vs_same_month_ly')); ?></div>
        <?php if ($lyMonthHasReliableData): ?>
            <div class="value"><?php echo number_format($lyMonthActual, 0); ?></div>
            <?php echo trend_label($projVsLastYear, $uiLanguage, ui_text($ui, 'vs_same_month_ly')); ?>
        <?php else: ?>
            <div class="value">-</div>
            <?php echo neutral_label(ui_text($ui, 'no_baseline')); ?>
        <?php endif; ?>
    </div>
</div>

<div class="split-grid max-[900px]:grid-cols-1">
    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'cancel_watch_title')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_cancel_watch')); ?></h3>
        <div class="table-note"><?php echo htmlspecialchars(ui_text($ui, 'cancel_watch_subtitle')); ?> · <?php echo htmlspecialchars($latestCompleteDate->format('j M Y')); ?></div>
        <?php if (empty($cancelWatch)): ?>
            <div class="ok-state"><span class="dot"></span><?php echo htmlspecialchars(ui_text($ui, 'cancel_watch_ok')); ?></div>
        <?php else: ?>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                <tr>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'platform')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'latest_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'norm_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'lost_revenue_col')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($cancelWatch as $row): ?>
                    <tr>
                        <td><span class="badge online"><?php echo htmlspecialchars($row['shopName']); ?></span></td>
                        <td><span class="status-pill <?php echo $row['severity']; ?>"><?php echo number_format($row['latestRate'], 1); ?>%</span></td>
                        <td><?php echo number_format($row['normRate'], 1); ?>%</td>
                        <td><?php echo number_format($row['lostNet'], 0); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>

    <div class="table-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'attention_title')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_attention')); ?></h3>
        <div class="table-note"><?php echo htmlspecialchars(ui_text($ui, 'attention_subtitle')); ?> · <?php echo htmlspecialchars($latestCompleteDate->format('j M Y')); ?></div>
        <?php if (empty($attentionPlatforms)): ?>
            <div class="ok-state"><span class="dot"></span><?php echo htmlspecialchars(ui_text($ui, 'attention_ok')); ?></div>
        <?php else: ?>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                <tr>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'platform')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'latest_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'baseline_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'vs_normal_col')); ?></th>
                    <th><?php echo htmlspecialchars(ui_text($ui, 'status_col')); ?></th>
                </tr>
                </thead>
                <tbody>
                <?php foreach ($attentionPlatforms as $row): ?>
                    <tr>
                        <td><span class="badge online"><?php echo htmlspecialchars($row['shopName']); ?></span></td>
                        <td><?php echo number_format($row['latestNet'], 0); ?></td>
                        <td><?php echo number_format($row['baseline'], 0); ?></td>
                        <td><?php echo number_format($row['ratio'], 0); ?>%</td>
                        <td><span class="status-pill <?php echo $row['status'] === 'no_sales' ? 'high' : 'medium'; ?>"><?php echo htmlspecialchars(ui_text($ui, $row['status'] === 'no_sales' ? 'status_no_sales' : 'status_below')); ?></span></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table></div>
        <?php endif; ?>
    </div>
</div>
</div>

<div class="dash-section" data-section-id="discount-anomaly" data-section-label-th="ความผิดปกติของส่วนลด" data-section-label-en="Discount Anomaly">
<div class="table-card" style="margin-bottom: 24px;">
    <h3><?php echo htmlspecialchars(ui_text($ui, 'disc_anomaly_title')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_disc_anomaly')); ?></h3>
    <div class="table-note"><?php echo htmlspecialchars(ui_text($ui, 'disc_anomaly_subtitle')); ?> · <?php echo htmlspecialchars($latestCompleteDate->format('j M Y')); ?></div>
    <?php if (empty($discountAnomalies)): ?>
        <div class="ok-state"><span class="dot"></span><?php echo htmlspecialchars(ui_text($ui, 'disc_anomaly_ok')); ?></div>
    <?php else: ?>
        <div class="max-[640px]:overflow-x-auto"><table>
            <thead>
            <tr>
                <th><?php echo htmlspecialchars(ui_text($ui, 'platform')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'disc_latest_col')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'disc_norm_col')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'disc_diff_col')); ?></th>
                <th><?php echo htmlspecialchars(ui_text($ui, 'gross_col')); ?></th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($discountAnomalies as $row): ?>
                <tr>
                    <td><span class="badge online"><?php echo htmlspecialchars($row['shopName']); ?></span></td>
                    <td><span class="status-pill medium"><?php echo number_format($row['latestPct'], 1); ?>%</span></td>
                    <td><?php echo number_format($row['normPct'], 1); ?>%</td>
                    <td>+<?php echo number_format($row['diff'], 1); ?>pp</td>
                    <td><?php echo number_format($row['gross'], 0); ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    <?php endif; ?>
</div>

<div class="chart-card" style="margin-bottom: 24px;">
    <h3><?php echo htmlspecialchars(ui_text($ui, 'discount_tier_trend')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_discount_tier_trend')); ?></h3>
    <div class="chart-box"><canvas id="discountTierTrendChart"></canvas></div>
</div>
</div>

<div class="dash-section" data-section-id="customer-insights" data-section-label-th="ข้อมูลเชิงลึกลูกค้า" data-section-label-en="Customer Insights">
<div class="section-title">
    <h2><?php echo htmlspecialchars(ui_text($ui, 'customer_insights')); ?></h2>
    <span><?php echo htmlspecialchars(ui_text($ui, 'customer_analysis')); ?></span>
</div>
<div class="analysis-grid">
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'segment_revenue_chart')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_segment_revenue')); ?></h3>
        <div class="chart-box"><canvas id="segmentRevenueChart"></canvas></div>
    </div>
    <div class="chart-card">
        <h3><?php echo htmlspecialchars(ui_text($ui, 'repeat_purchase_chart')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_repeat_purchase')); ?></h3>
        <div class="chart-box"><canvas id="repeatPurchaseChart"></canvas></div>
    </div>
</div>
<div class="chart-card" style="margin-top: 20px;">
    <h3><?php echo htmlspecialchars(ui_text($ui, 'province_revenue_chart')); ?><?php echo hint_icon(ui_text($ui, 'tooltip_province_revenue')); ?></h3>
    <div class="chart-box"><canvas id="provinceRevenueChart"></canvas></div>
</div>
</div>

<script>
// Fixed brand-derived categorical order (validated colorblind-safe) — see BRAND_COLORS.md.
// Same order used on every dashboard's multi-category charts, never reordered per chart.
const chartColors = ['#4b74d8', '#8e792a', '#09899e', '#9b59bc', '#12933f', '#c55123', '#bf497e'];
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
                backgroundColor: '#dab937',
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

new Chart(document.getElementById('discountTierTrendChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($dailyTrend, 'saleDate')); ?>,
        datasets: [
            {
                label: <?php echo json_encode(ui_text($ui, 'no_discount')); ?>,
                data: <?php echo json_encode(array_values($discountTierTrend['no_discount'])); ?>,
                backgroundColor: '#10B981',
                borderWidth: 0,
                borderRadius: 4
            },
            {
                label: <?php echo json_encode(ui_text($ui, 'discounted')); ?>,
                data: <?php echo json_encode(array_values($discountTierTrend['discounted'])); ?>,
                backgroundColor: '#F59E0B',
                borderWidth: 0,
                borderRadius: 4
            },
            {
                label: <?php echo json_encode(ui_text($ui, 'high_discount')); ?>,
                data: <?php echo json_encode(array_values($discountTierTrend['high_discount'])); ?>,
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
            y: { stacked: true, ticks: { callback: shortNumber, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } }
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

new Chart(document.getElementById('peakHoursChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_map(fn($h) => sprintf('%02d:00', $h), array_keys($peakHoursByHour))); ?>,
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'orders')); ?>,
            data: <?php echo json_encode(array_values($peakHoursByHour)); ?>,
            backgroundColor: '#dab937',
            borderWidth: 0,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#6B7280', maxRotation: 0, autoSkip: true, maxTicksLimit: 12 } },
            y: { ticks: { callback: shortNumber, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } }
        }
    }
});

new Chart(document.getElementById('weekdayOrdersChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode([
            $uiLanguage === 'th' ? 'อา' : 'Sun', $uiLanguage === 'th' ? 'จ' : 'Mon', $uiLanguage === 'th' ? 'อ' : 'Tue',
            $uiLanguage === 'th' ? 'พ' : 'Wed', $uiLanguage === 'th' ? 'พฤ' : 'Thu', $uiLanguage === 'th' ? 'ศ' : 'Fri', $uiLanguage === 'th' ? 'ส' : 'Sat',
        ]); ?>,
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'orders')); ?>,
            data: <?php echo json_encode(array_values($weekdayOrdersByDow)); ?>,
            backgroundColor: '#091113',
            borderWidth: 0,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#6B7280' } },
            y: { ticks: { callback: shortNumber, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } }
        }
    }
});

new Chart(document.getElementById('segmentRevenueChart'), {
    data: {
        labels: <?php echo json_encode(array_column($segmentRevenueRows, 'segment')); ?>,
        datasets: [
            {
                type: 'bar',
                label: <?php echo json_encode(ui_text($ui, 'chart_net_sales')); ?>,
                data: <?php echo json_encode(array_map('n', array_column($segmentRevenueRows, 'netSales'))); ?>,
                backgroundColor: '#dab937',
                borderWidth: 0,
                borderRadius: 4,
                yAxisID: 'y'
            },
            {
                type: 'line',
                label: <?php echo json_encode(ui_text($ui, 'aov')); ?>,
                data: <?php echo json_encode(array_map(function ($row) {
                    $orders = n($row['orders']);
                    return $orders > 0 ? n($row['netSales']) / $orders : 0;
                }, $segmentRevenueRows)); ?>,
                borderColor: '#4b74d8',
                backgroundColor: '#4b74d8',
                tension: 0.3,
                yAxisID: 'y1'
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
            x: { grid: { display: false }, ticks: { color: '#6B7280' } },
            y: { position: 'left', ticks: { callback: shortNumber, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } },
            y1: { position: 'right', ticks: { callback: shortNumber, color: '#6B7280' }, grid: { display: false } }
        }
    }
});

new Chart(document.getElementById('repeatPurchaseChart'), {
    type: 'bar',
    data: {
        labels: [
            <?php echo json_encode(ui_text($ui, 'bucket_1')); ?>,
            <?php echo json_encode(ui_text($ui, 'bucket_2_3')); ?>,
            <?php echo json_encode(ui_text($ui, 'bucket_4_9')); ?>,
            <?php echo json_encode(ui_text($ui, 'bucket_10_plus')); ?>
        ],
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'customers_col')); ?>,
            data: <?php echo json_encode(array_values($repeatPurchaseCounts)); ?>,
            backgroundColor: ['#9CA3AF', '#8e792a', '#09899e', '#12933f'],
            borderWidth: 0,
            borderRadius: 4
        }]
    },
    options: {
        responsive: true,
        maintainAspectRatio: false,
        plugins: {
            legend: { display: false },
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + numberFormat.format(ctx.raw) } }
        },
        scales: {
            x: { grid: { display: false }, ticks: { color: '#6B7280' } },
            y: { ticks: { callback: shortNumber, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } }
        }
    }
});

new Chart(document.getElementById('provinceRevenueChart'), {
    type: 'bar',
    data: {
        labels: <?php echo json_encode(array_column($provinceRevenueRows, 'provinceGroup')); ?>,
        datasets: [{
            label: <?php echo json_encode(ui_text($ui, 'chart_net_sales')); ?>,
            data: <?php echo json_encode(array_map('n', array_column($provinceRevenueRows, 'netSales'))); ?>,
            backgroundColor: '#dab937',
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
            tooltip: { callbacks: { label: ctx => ctx.dataset.label + ': ' + numberFormat.format(ctx.raw) } }
        },
        scales: {
            x: { ticks: { callback: shortNumber, color: '#6B7280' }, grid: { color: 'rgba(0,0,0,0.05)' } },
            y: { grid: { display: false }, ticks: { color: '#6B7280' } }
        }
    }
});
</script>

<?php require_once __DIR__ . '/includes/footer.php'; ?>
