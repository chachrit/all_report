<?php
/**
 * includes/header.php
 * ตั้งตัวแปรก่อน include:
 *   $pageTitle    (string) เช่น 'Offline Sales Dashboard'
 *   $pageSubtitle (string) เช่น 'Retail Store Performance Overview'
 *   $accentColor  (string) สีหลักของกราฟ/badge เช่น '#1a1a2e' (offline) / '#c9a227' (online)
 * active nav คำนวณเองจากชื่อไฟล์ปัจจุบัน ไม่ต้องไปแก้ทีละไฟล์
 */

$navItems = [
    'index.php'         => 'Overview',
    'dashboard_online.php'  => 'Online Sales',
    'dashboard_offline.php' => 'Offline Sales',
    'dashboard_consignment.php' => 'Consignment',
    // 'index.php'             => 'Data Import',
];
$currentPage = basename($_SERVER['PHP_SELF']);
$accentColor  = $accentColor  ?? '#1a1a2e';
$filterValues = $filterValues ?? [];
$filterOptions = $filterOptions ?? [];

if (!function_exists('header_request_value')) {
    function header_request_value(string $key, string $default, array $allowed): string
    {
        $value = isset($_GET[$key]) ? (string) $_GET[$key] : $default;
        return in_array($value, $allowed, true) ? $value : $default;
    }
}

if (!function_exists('header_ui_text')) {
    function header_ui_text(array $labels, string $key): string
    {
        return $labels[$key] ?? $key;
    }
}

if (!function_exists('header_display_date')) {
    function header_display_date(string $language): string
    {
        if ($language !== 'th') {
            return date('F j, Y');
        }

        $thaiMonths = [
            1 => 'มกราคม',
            'กุมภาพันธ์',
            'มีนาคม',
            'เมษายน',
            'พฤษภาคม',
            'มิถุนายน',
            'กรกฎาคม',
            'สิงหาคม',
            'กันยายน',
            'ตุลาคม',
            'พฤศจิกายน',
            'ธันวาคม',
        ];

        return date('j') . ' ' . $thaiMonths[(int) date('n')] . ' ' . ((int) date('Y') + 543);
    }
}

if (!function_exists('header_url_with_lang')) {
    function header_url_with_lang(string $href, string $language): string
    {
        return $language === 'th' ? $href : $href . '?lang=' . rawurlencode($language);
    }
}

$uiLanguage = $filterValues['lang'] ?? header_request_value('lang', 'th', ['th', 'en']);
$filterValues['lang'] = $uiLanguage;

$headerText = [
    'th' => [
        'dashboard' => 'แดชบอร์ด',
        'overview_title' => 'ภาพรวมธุรกิจ',
        'overview_subtitle' => 'Business Performance Overview',
        'online_title' => 'แดชบอร์ดยอดขายออนไลน์',
        'online_subtitle' => 'ติดตามผลการขายจากข้อมูลจริง production_jst_api',
        'offline_title' => 'แดชบอร์ดยอดขายหน้าร้าน',
        'offline_subtitle' => 'ภาพรวมผลการดำเนินงานสาขา',
        'consignment_title' => 'แดชบอร์ดฝากขาย',
        'consignment_subtitle' => 'ภาพรวมผลการดำเนินงานพาร์ทเนอร์',
        'updated' => 'อัปเดต',
        'overview' => 'ภาพรวม',
        'online_sales' => 'ยอดขายออนไลน์',
        'offline_sales' => 'ยอดขายหน้าร้าน',
        'consignment' => 'ฝากขาย',
        'date_range' => 'ช่วงเวลา',
        'today' => 'วันนี้',
        'mtd' => 'เดือนนี้',
        'ytd' => 'ปีนี้',
        'channel' => 'ช่องทาง',
        'all_channels' => 'ทุกช่องทาง',
        'online' => 'ออนไลน์',
        'offline' => 'หน้าร้าน',
        'branch' => 'สาขา',
        'all_branches' => 'ทุกสาขา',
        'category' => 'หมวดหมู่',
        'all_categories' => 'ทุกหมวดหมู่',
        'campaign' => 'แคมเปญ',
        'all_campaigns' => 'ทุกแคมเปญ',
        'sales_type' => 'ประเภทการขาย',
        'all_sales' => 'ทุกประเภทการขาย',
        'language' => 'ภาษา',
        'thai' => 'ไทย',
        'english' => 'อังกฤษ',
    ],
    'en' => [
        'dashboard' => 'Dashboard',
        'overview_title' => 'Business Overview',
        'overview_subtitle' => 'Business Performance Overview',
        'online_title' => 'Online Sales Dashboard',
        'online_subtitle' => 'Sales performance from production_jst_api',
        'offline_title' => 'Offline Sales Dashboard',
        'offline_subtitle' => 'Retail Store Performance Overview',
        'consignment_title' => 'Consignment Dashboard',
        'consignment_subtitle' => 'Retail Partner Performance Overview',
        'updated' => 'Updated',
        'overview' => 'Overview',
        'online_sales' => 'Online Sales',
        'offline_sales' => 'Offline Sales',
        'consignment' => 'Consignment',
        'date_range' => 'Date Range',
        'today' => 'Today',
        'mtd' => 'MTD',
        'ytd' => 'YTD',
        'channel' => 'Channel',
        'all_channels' => 'All Channels',
        'online' => 'Online',
        'offline' => 'Offline',
        'branch' => 'Branch',
        'all_branches' => 'All Branches',
        'category' => 'Category',
        'all_categories' => 'All Categories',
        'campaign' => 'Campaign',
        'all_campaigns' => 'All Campaigns',
        'sales_type' => 'Sales Type',
        'all_sales' => 'All Sales',
        'language' => 'Language',
        'thai' => 'Thai',
        'english' => 'English',
    ],
];
$headerUi = $headerText[$uiLanguage];

$pageDefaults = [
    'index.php' => ['title' => 'overview_title', 'subtitle' => 'overview_subtitle'],
    'dashboard_online.php' => ['title' => 'online_title', 'subtitle' => 'online_subtitle'],
    'dashboard_offline.php' => ['title' => 'offline_title', 'subtitle' => 'offline_subtitle'],
    'dashboard_consignment.php' => ['title' => 'consignment_title', 'subtitle' => 'consignment_subtitle'],
];
$pageDefault = $pageDefaults[$currentPage] ?? ['title' => 'dashboard', 'subtitle' => ''];
$defaultEnglishTitle = $headerText['en'][$pageDefault['title']] ?? null;
$defaultThaiTitle = $headerText['th'][$pageDefault['title']] ?? null;
$defaultEnglishSubtitle = $pageDefault['subtitle'] ? ($headerText['en'][$pageDefault['subtitle']] ?? null) : null;
$defaultThaiSubtitle = $pageDefault['subtitle'] ? ($headerText['th'][$pageDefault['subtitle']] ?? null) : null;
$pageTitleAliases = [
    'index.php' => ['Executive Dashboard', 'Business Overview', 'ภาพรวมธุรกิจ'],
    'dashboard_online.php' => ['Online Sales Dashboard', 'แดชบอร์ดยอดขายออนไลน์'],
    'dashboard_offline.php' => ['Offline Sales Dashboard', 'แดชบอร์ดยอดขายหน้าร้าน'],
    'dashboard_consignment.php' => ['Consignment Dashboard', 'แดชบอร์ดฝากขาย'],
];
$pageSubtitleAliases = [
    'index.php' => ['Journal Sales Performance Overview', 'Business Performance Overview'],
    'dashboard_online.php' => ['Sales performance from production_jst_api', 'ติดตามผลการขายจากข้อมูลจริง production_jst_api'],
    'dashboard_offline.php' => ['Retail Store Performance Overview', 'ภาพรวมผลการดำเนินงานสาขา'],
    'dashboard_consignment.php' => ['Retail Partner Performance Overview', 'ภาพรวมผลการดำเนินงานพาร์ทเนอร์'],
];
$knownPageTitles = array_unique(array_merge(array_filter([$defaultEnglishTitle, $defaultThaiTitle]), $pageTitleAliases[$currentPage] ?? []));
$knownPageSubtitles = array_unique(array_merge(array_filter([$defaultEnglishSubtitle, $defaultThaiSubtitle]), $pageSubtitleAliases[$currentPage] ?? []));

if (!isset($pageTitle) || in_array($pageTitle, $knownPageTitles, true)) {
    $pageTitle = header_ui_text($headerUi, $pageDefault['title']);
}
if (!isset($pageSubtitle) || in_array($pageSubtitle, $knownPageSubtitles, true)) {
    $pageSubtitle = $pageDefault['subtitle'] ? header_ui_text($headerUi, $pageDefault['subtitle']) : '';
}

$defaultFilterOptions = [
    'language_enabled' => true,
    'language_label' => header_ui_text($headerUi, 'language'),
    'language' => ['th' => header_ui_text($headerUi, 'thai'), 'en' => header_ui_text($headerUi, 'english')],
    'header_labels' => ['date' => header_display_date($uiLanguage), 'updated' => header_ui_text($headerUi, 'updated')],
    'nav_labels' => [
        'index.php' => header_ui_text($headerUi, 'overview'),
        'dashboard_online.php' => header_ui_text($headerUi, 'online_sales'),
        'dashboard_offline.php' => header_ui_text($headerUi, 'offline_sales'),
        'dashboard_consignment.php' => header_ui_text($headerUi, 'consignment'),
    ],
    'date_label' => header_ui_text($headerUi, 'date_range'),
    'channel_label' => header_ui_text($headerUi, 'channel'),
    'branch_label' => header_ui_text($headerUi, 'branch'),
    'category_label' => header_ui_text($headerUi, 'category'),
    'campaign_label' => header_ui_text($headerUi, 'campaign'),
    'sales_type_label' => header_ui_text($headerUi, 'sales_type'),
    'date_range' => ['today' => header_ui_text($headerUi, 'today'), 'mtd' => header_ui_text($headerUi, 'mtd'), 'ytd' => header_ui_text($headerUi, 'ytd')],
    'channel' => ['all' => header_ui_text($headerUi, 'all_channels'), 'online' => header_ui_text($headerUi, 'online'), 'offline' => header_ui_text($headerUi, 'offline')],
    'branch' => ['all' => header_ui_text($headerUi, 'all_branches')],
    'category' => ['all' => header_ui_text($headerUi, 'all_categories')],
    'campaign' => ['all' => header_ui_text($headerUi, 'all_campaigns')],
    'sales_type' => ['all' => header_ui_text($headerUi, 'all_sales')],
];
$filterOptions = array_replace($defaultFilterOptions, $filterOptions);
$filterOptions['header_labels'] = array_replace($defaultFilterOptions['header_labels'], $filterOptions['header_labels'] ?? []);
$filterOptions['nav_labels'] = array_replace($defaultFilterOptions['nav_labels'], $filterOptions['nav_labels'] ?? []);
$headerLabels = $filterOptions['header_labels'];

/**
 * render_change() — มาตรฐานเดียวสำหรับแสดง comparison ใต้ KPI ทุกตัว
 * รองรับทั้งบวก (▲ เขียว) และลบ (▼ แดง) อัตโนมัติ
 */
if (!function_exists('render_change')) {
    function render_change(float $percent, string $label = 'vs last month'): string
    {
        $isPositive = $percent >= 0;
        $cls   = $isPositive ? 'positive' : 'negative';
        $arrow = $isPositive ? '▲' : '▼';
        return sprintf(
            '<div class="change %s">%s %s%% %s</div>',
            $cls, $arrow, number_format(abs($percent), 1), htmlspecialchars($label)
        );
    }
}
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars($uiLanguage); ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Journal</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js"></script>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.2/css/all.min.css">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Prompt:wght@300;400;500;600;700&display=swap" rel="stylesheet">
    <style>
        :root {
            /* Color Hierarchy */
            --text-primary: #111827;
            --text-secondary: #6B7280;
            --text-muted: #9CA3AF;
            --color-positive: #10B981;
            --color-negative: #EF4444;

            /* Theme Colors (unchanged) */
            --color-gold: #c9a227;
            --color-navy: #1a1a2e;
            --color-green: #10b981;
            --color-purple: #667eea;
            --color-gray: #a0aec0;
        }

        * { box-sizing: border-box; }
        body { font-family: 'Prompt', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; padding: 0; margin: 0; color: var(--text-primary); }

        .header { background: #fff; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e5e5; }
        .header h1 { margin: 0; font-size: 24px; font-weight: 500; color: var(--text-primary); }
        .header .subtitle { margin: 5px 0 0; color: var(--text-secondary); font-size: 14px; }
        .header .date { font-size: 12px; color: var(--text-muted); }
        .header .updated { font-size: 11px; color: var(--text-muted); margin-top: 4px; }

        .nav { background: #f9f9f9; padding: 0 30px; border-bottom: 1px solid #e5e5e5; }
        .nav a { display: inline-block; padding: 15px 25px; color: var(--text-secondary); text-decoration: none; font-size: 13px; border-bottom: 2px solid transparent; }
        .nav a:hover, .nav a.active { color: var(--text-primary); border-bottom-color: var(--text-primary); }

        .filter-bar { background: #fff; padding: 15px 30px; border-bottom: 1px solid #e5e5e5; display: flex; align-items: center; gap: 20px; position: sticky; top: 0; z-index: 100; box-shadow: 0 2px 8px rgba(0,0,0,0.05); flex-wrap: wrap; }
        .filter-bar .filter-item { display: flex; align-items: center; gap: 8px; }
        .filter-bar .filter-label { font-size: 12px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500; }
        .filter-bar .filter-value { font-size: 13px; color: var(--text-primary); font-weight: 500; padding: 6px 12px; background: #f9f9f9; border-radius: 4px; }
        .filter-bar .separator { color: #d1d5db; font-size: 14px; }
        .filter-bar .dropdown { position: relative; }
        .filter-bar .dropdown select {
            appearance: none;
            background: #f9f9f9;
            border: 1px solid #e5e5e5;
            border-radius: 4px;
            padding: 6px 30px 6px 12px;
            font-size: 13px;
            color: var(--text-primary);
            font-weight: 500;
            cursor: pointer;
            min-width: 120px;
        }
        .filter-bar .dropdown::after {
            content: '▼';
            position: absolute;
            right: 10px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 10px;
            color: var(--text-muted);
            pointer-events: none;
        }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); cursor: pointer; transition: transform 0.2s ease, box-shadow 0.2s ease; position: relative; }
        .kpi-card:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.12); }
        .kpi-card .label { font-size: 11px; color: var(--text-secondary); margin-bottom: 12px; text-transform: uppercase; letter-spacing: 0.5px; font-weight: 500; }
        .kpi-card .value { font-size: 42px; font-weight: 300; color: var(--text-primary); margin-bottom: 8px; letter-spacing: -1px; }
        .kpi-card .change { font-size: 12px; font-weight: 500; }
        .kpi-card .change.positive { color: var(--color-positive); }
        .kpi-card .change.negative { color: var(--color-negative); }
        .kpi-card .tooltip {
            position: absolute;
            bottom: 100%;
            left: 50%;
            transform: translateX(-50%) translateY(-8px);
            background: var(--color-navy);
            color: white;
            padding: 8px 12px;
            border-radius: 6px;
            font-size: 11px;
            white-space: nowrap;
            opacity: 0;
            visibility: hidden;
            transition: opacity 0.2s ease, visibility 0.2s ease;
            z-index: 1000;
            pointer-events: none;
        }
        .kpi-card .tooltip::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border: 6px solid transparent;
            border-top-color: var(--color-navy);
        }
        .kpi-card:hover .tooltip {
            opacity: 1;
            visibility: visible;
        }

        .split-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }

        .charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px; }
        .chart-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .chart-card h3 { margin: 0 0 20px; font-size: 14px; color: var(--text-primary); font-weight: 600; letter-spacing: 0.25px; }

        .table-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 30px; }
        .table-card h3 { margin: 0 0 20px; font-size: 14px; color: var(--text-primary); font-weight: 600; letter-spacing: 0.25px; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 12px 16px; border-bottom: 1px solid #e5e5e5; font-size: 12px; color: var(--text-primary); text-transform: uppercase; font-weight: 600; letter-spacing: 0.5px; }
        td { padding: 16px; border-bottom: 1px solid #f0f0f0; font-size: 14px; font-weight: 500; color: var(--text-primary); }
        tr:hover { background: #f9f9f9; }

        .bar-container { background: #f0f0f0; border-radius: 4px; height: 8px; overflow: hidden; }
        .bar { height: 100%; background: <?php echo htmlspecialchars($accentColor); ?>; border-radius: 4px; }

        .badge { padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 500; background: #f3f4f6; color: var(--text-secondary); }
        .badge.online { background: rgba(201, 162, 39, 0.15); color: #c9a227; }
        .badge.offline { background: rgba(26, 26, 46, 0.08); color: #1a1a2e; }

        .simple-bar { display: flex; align-items: center; margin-bottom: 12px; }
        .simple-bar .name { width: 120px; font-size: 12px; color: var(--text-secondary); font-weight: 500; }
        .simple-bar .bar-area { flex: 1; margin: 0 15px; }
        .simple-bar .value { width: 80px; text-align: right; font-size: 13px; font-weight: 500; color: var(--text-primary); }

        .goal-card {
            background: var(--color-navy);
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-left: 4px solid var(--color-gold);
        }
        .goal-card .goal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .goal-card .goal-title { font-size: 14px; color: rgba(255,255,255,0.8); text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; font-weight: 500; }
        .goal-card .goal-amount { font-size: 48px; font-weight: 300; letter-spacing: -2px; color: white; }
        .goal-card .goal-status { padding: 8px 20px; border-radius: 20px; font-size: 11px; font-weight: 500; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(255,255,255,0.2); color: white; }
        .goal-card .goal-status.on-track { background: rgba(16, 185, 129, 0.25); color: #6ee7b7; }
        .goal-card .goal-status.off-track { background: rgba(239, 68, 68, 0.25); color: #fca5a5; }
        .goal-card .progress-section { margin-bottom: 20px; }
        .goal-card .progress-label { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 13px; color: rgba(255,255,255,0.9); font-weight: 500; }
        .goal-card .progress-bar { height: 12px; background: rgba(255,255,255,0.2); border-radius: 6px; overflow: hidden; }
        .goal-card .progress-fill { height: 100%; background: #fff; border-radius: 6px; transition: width 0.5s ease; box-shadow: 0 0 10px rgba(255,255,255,0.3); }
        .goal-card .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 25px; padding-top: 25px; border-top: 1px solid rgba(255,255,255,0.1); }
        .goal-card .stats-grid.six-items { grid-template-columns: repeat(3, 1fr); }
        .goal-card .stats-grid.six-items-compact {
            display: grid;
            grid-template-columns: repeat(6, 1fr);
            gap: 12px;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        @media (max-width: 1024px) {
            .goal-card .stats-grid.six-items-compact {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        @media (max-width: 640px) {
            .goal-card .stats-grid.six-items-compact {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        .goal-card .stat-item { text-align: center; }
        .goal-card .stat-label { font-size: 11px; color: rgba(255,255,255,0.7); text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; font-weight: 500; }
        .goal-card .stat-value { font-size: 24px; font-weight: 300; color: white; }
    </style>
</head>
<body>
    <div class="header">
        <div>
            <h1><?php echo htmlspecialchars($pageTitle); ?></h1>
            <?php if ($pageSubtitle): ?>
            <div class="subtitle"><?php echo htmlspecialchars($pageSubtitle); ?></div>
            <?php endif; ?>
        </div>
        <div style="text-align: right;">
            <div class="date"><?php echo htmlspecialchars($headerLabels['date'] ?? date('F j, Y')); ?></div>
            <?php if (!empty($headerLabels['updated_at'])): ?>
            <div class="updated"><?php echo htmlspecialchars($headerLabels['updated'] ?? 'Updated'); ?>: <?php echo htmlspecialchars($headerLabels['updated_at']); ?></div>
            <?php endif; ?>
        </div>
    </div>

    <div class="nav">
        <?php foreach ($navItems as $href => $label): ?>
        <a href="<?php echo htmlspecialchars(header_url_with_lang($href, $uiLanguage)); ?>" class="<?php echo ($currentPage === $href) ? 'active' : ''; ?>"><?php echo htmlspecialchars($filterOptions['nav_labels'][$href] ?? $label); ?></a>
        <?php endforeach; ?>
    </div>

    <form class="filter-bar" method="get" action="<?php echo htmlspecialchars($currentPage); ?>">
        <div class="filter-item">
            <span class="filter-label"><?php echo htmlspecialchars($filterOptions['date_label'] ?? 'Date Range'); ?></span>
            <div class="dropdown">
                <select id="dateRangeFilter" name="date_range" onchange="updateDashboardData()">
                    <?php foreach (($filterOptions['date_range'] ?? ['today' => 'Today', 'mtd' => 'MTD', 'ytd' => 'YTD']) as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($filterValues['date_range'] ?? 'mtd') === (string) $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <span class="separator">|</span>
        <div class="filter-item">
            <span class="filter-label"><?php echo htmlspecialchars($filterOptions['channel_label'] ?? 'Channel'); ?></span>
            <div class="dropdown">
                <select id="channelFilter" name="channel" onchange="updateDashboardData()">
                    <?php foreach (($filterOptions['channel'] ?? ['all' => 'All Channels', 'online' => 'Online', 'offline' => 'Offline']) as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($filterValues['channel'] ?? 'all') === (string) $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <span class="separator">|</span>
        <div class="filter-item">
            <span class="filter-label"><?php echo htmlspecialchars($filterOptions['branch_label'] ?? 'Branch'); ?></span>
            <div class="dropdown">
                <select id="branchFilter" name="branch" onchange="updateDashboardData()">
                    <?php foreach (($filterOptions['branch'] ?? ['all' => 'All Branches']) as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($filterValues['branch'] ?? 'all') === (string) $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <span class="separator">|</span>
        <div class="filter-item">
            <span class="filter-label"><?php echo htmlspecialchars($filterOptions['category_label'] ?? 'Category'); ?></span>
            <div class="dropdown">
                <select id="categoryFilter" name="category" onchange="updateDashboardData()">
                    <?php foreach (($filterOptions['category'] ?? ['all' => 'All Categories']) as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($filterValues['category'] ?? 'all') === (string) $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <span class="separator">|</span>
        <div class="filter-item">
            <span class="filter-label"><?php echo htmlspecialchars($filterOptions['campaign_label'] ?? 'Campaign'); ?></span>
            <div class="dropdown">
                <select id="campaignFilter" name="campaign" onchange="updateDashboardData()">
                    <?php foreach (($filterOptions['campaign'] ?? ['all' => 'All Campaigns']) as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($filterValues['campaign'] ?? 'all') === (string) $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <span class="separator">|</span>
        <div class="filter-item">
            <span class="filter-label"><?php echo htmlspecialchars($filterOptions['sales_type_label'] ?? 'Sales Type'); ?></span>
            <div class="dropdown">
                <select id="salesTypeFilter" name="sales_type" onchange="updateDashboardData()">
                    <?php foreach (($filterOptions['sales_type'] ?? ['all' => 'All Sales']) as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($filterValues['sales_type'] ?? 'all') === (string) $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php if (!empty($filterOptions['language_enabled'])): ?>
        <span class="separator">|</span>
        <div class="filter-item">
            <span class="filter-label"><?php echo htmlspecialchars($filterOptions['language_label'] ?? 'Language'); ?></span>
            <div class="dropdown">
                <select id="languageFilter" name="lang" onchange="updateDashboardData()">
                    <?php foreach (($filterOptions['language'] ?? ['th' => 'ไทย', 'en' => 'English']) as $value => $label): ?>
                    <option value="<?php echo htmlspecialchars($value); ?>" <?php echo (($filterValues['lang'] ?? 'th') === (string) $value) ? 'selected' : ''; ?>><?php echo htmlspecialchars($label); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <?php endif; ?>
    </form>

    <script>
        function scrollToSection(sectionId) {
            const element = document.getElementById(sectionId);
            if (element) {
                element.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }

        function updateDashboardData() {
            document.querySelector('.filter-bar').submit();
        }
    </script>

    <div class="container">
