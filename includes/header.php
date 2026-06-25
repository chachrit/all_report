<?php
/**
 * includes/header.php
 * ตั้งตัวแปรก่อน include:
 *   $pageTitle    (string) เช่น 'Offline Sales Dashboard'
 *   $pageSubtitle (string) เช่น 'Retail Store Performance Overview'
 *   $accentColor  (string) สีหลักของกราฟ/badge เช่น '#1a1a2e' (offline) / '#c9a227' (online)
 * active nav คำนวณเองจากชื่อไฟล์ปัจจุบัน ไม่ต้องไปแก้ทีละไฟล์
 */

$pageTitle    = $pageTitle    ?? 'Dashboard';
$pageSubtitle = $pageSubtitle ?? '';
$accentColor  = $accentColor  ?? '#1a1a2e';

$navItems = [
    'index.php'         => 'Overview',
    'dashboard_online.php'  => 'Online Sales',
    'dashboard_offline.php' => 'Offline Sales',
    // 'index.php'             => 'Data Import',
];
$currentPage = basename($_SERVER['PHP_SELF']);

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
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo htmlspecialchars($pageTitle); ?> - Journal</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f0f2f5; padding: 0; margin: 0; }

        .header { background: #fff; color: #333; padding: 20px 30px; display: flex; justify-content: space-between; align-items: center; border-bottom: 1px solid #e5e5e5; }
        .header h1 { margin: 0; font-size: 24px; font-weight: 400; }
        .header .subtitle { margin: 5px 0 0; opacity: 0.6; font-size: 14px; }
        .header .date { font-size: 12px; opacity: 0.5; }
        .header .updated { font-size: 11px; color: #999; margin-top: 4px; }

        .nav { background: #f9f9f9; padding: 0 30px; border-bottom: 1px solid #e5e5e5; }
        .nav a { display: inline-block; padding: 15px 25px; color: #666; text-decoration: none; font-size: 13px; border-bottom: 2px solid transparent; }
        .nav a:hover, .nav a.active { color: #333; border-bottom-color: #333; }

        .filter-bar { background: #fff; padding: 15px 30px; border-bottom: 1px solid #e5e5e5; display: flex; align-items: center; gap: 20px; }
        .filter-bar .filter-item { display: flex; align-items: center; gap: 8px; }
        .filter-bar .filter-label { font-size: 12px; color: #999; text-transform: uppercase; letter-spacing: 0.5px; }
        .filter-bar .filter-value { font-size: 13px; color: #333; font-weight: 500; padding: 6px 12px; background: #f9f9f9; border-radius: 4px; }
        .filter-bar .separator { color: #d1d5db; font-size: 14px; }
        .filter-bar .dropdown { position: relative; }
        .filter-bar .dropdown select { 
            appearance: none; 
            background: #f9f9f9; 
            border: 1px solid #e5e5e5; 
            border-radius: 4px; 
            padding: 6px 30px 6px 12px; 
            font-size: 13px; 
            color: #333; 
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
            color: #999;
            pointer-events: none;
        }

        .container { max-width: 1400px; margin: 30px auto; padding: 0 20px; }

        .kpi-grid { display: grid; grid-template-columns: repeat(4, 1fr); gap: 20px; margin-bottom: 30px; }
        .kpi-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .kpi-card .label { font-size: 11px; color: #999; margin-bottom: 8px; text-transform: uppercase; letter-spacing: 1px; font-weight: 400; }
        .kpi-card .value { font-size: 42px; font-weight: 300; color: #333; margin-bottom: 5px; letter-spacing: -1px; }
        .kpi-card .change { font-size: 12px; font-weight: 400; }
        .kpi-card .change.positive { color: #10b981; text-transform: uppercase; letter-spacing: 0.5px; }
        .kpi-card .change.negative { color: #ef4444; }

        .split-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 30px; }

        .charts-grid { display: grid; grid-template-columns: 2fr 1fr; gap: 20px; margin-bottom: 30px; }
        .chart-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); }
        .chart-card h3 { margin: 0 0 20px; font-size: 13px; color: #999; font-weight: 300; text-transform: uppercase; letter-spacing: 1px; }

        .table-card { background: white; padding: 25px; border-radius: 8px; box-shadow: 0 2px 8px rgba(0,0,0,0.08); margin-bottom: 30px; }
        .table-card h3 { margin: 0 0 20px; font-size: 13px; color: #999; font-weight: 300; text-transform: uppercase; letter-spacing: 1px; }

        table { width: 100%; border-collapse: collapse; }
        th { text-align: left; padding: 10px; border-bottom: 1px solid #e5e5e5; font-size: 11px; color: #999; text-transform: uppercase; font-weight: 400; letter-spacing: 0.5px; }
        td { padding: 15px 12px; border-bottom: 1px solid #f0f0f0; font-size: 14px; font-weight: 500; }
        tr:hover { background: #f9f9f9; }

        .bar-container { background: #f0f0f0; border-radius: 4px; height: 8px; overflow: hidden; }
        .bar { height: 100%; background: <?php echo htmlspecialchars($accentColor); ?>; border-radius: 4px; }

        .badge { padding: 4px 12px; border-radius: 12px; font-size: 11px; font-weight: 400; background: #f3f4f6; color: #374151; }
        .badge.online { background: rgba(201, 162, 39, 0.15); color: #c9a227; }
        .badge.offline { background: rgba(26, 26, 46, 0.08); color: #1a1a2e; }

        .simple-bar { display: flex; align-items: center; margin-bottom: 12px; }
        .simple-bar .name { width: 120px; font-size: 12px; color: #999; font-weight: 400; }
        .simple-bar .bar-area { flex: 1; margin: 0 15px; }
        .simple-bar .value { width: 80px; text-align: right; font-size: 13px; font-weight: 300; color: #333; }

        .goal-card {
            background: #1a1a2e;
            color: white;
            padding: 30px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 4px 12px rgba(0,0,0,0.08);
            border-left: 4px solid #c9a227;
        }
        .goal-card .goal-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 25px; }
        .goal-card .goal-title { font-size: 14px; opacity: 0.8; text-transform: uppercase; letter-spacing: 1px; margin-bottom: 5px; }
        .goal-card .goal-amount { font-size: 48px; font-weight: 300; letter-spacing: -2px; }
        .goal-card .goal-status { padding: 8px 20px; border-radius: 20px; font-size: 11px; font-weight: 400; text-transform: uppercase; letter-spacing: 0.5px; background: rgba(255,255,255,0.2); color: white; }
        .goal-card .goal-status.on-track { background: rgba(16, 185, 129, 0.25); color: #6ee7b7; }
        .goal-card .goal-status.off-track { background: rgba(239, 68, 68, 0.25); color: #fca5a5; }
        .goal-card .progress-section { margin-bottom: 20px; }
        .goal-card .progress-label { display: flex; justify-content: space-between; margin-bottom: 10px; font-size: 13px; }
        .goal-card .progress-bar { height: 12px; background: rgba(255,255,255,0.2); border-radius: 6px; overflow: hidden; }
        .goal-card .progress-fill { height: 100%; background: #fff; border-radius: 6px; transition: width 0.5s ease; box-shadow: 0 0 10px rgba(255,255,255,0.3); }
        .goal-card .stats-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 20px; margin-top: 25px; padding-top: 25px; border-top: 1px solid rgba(255,255,255,0.1); }
        .goal-card .stats-grid.six-items { grid-template-columns: repeat(3, 1fr); }
        .goal-card .stat-item { text-align: center; }
        .goal-card .stat-label { font-size: 11px; opacity: 0.7; text-transform: uppercase; letter-spacing: 0.5px; margin-bottom: 5px; }
        .goal-card .stat-value { font-size: 24px; font-weight: 300; }
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
            <div class="date"><?php echo date('F j, Y'); ?></div>
            <div class="updated">Updated: 24 Jun 2026 14:35</div>
        </div>
    </div>

    <div class="nav">
        <?php foreach ($navItems as $href => $label): ?>
        <a href="<?php echo $href; ?>" class="<?php echo ($currentPage === $href) ? 'active' : ''; ?>"><?php echo $label; ?></a>
        <?php endforeach; ?>
    </div>

    <div class="filter-bar">
        <div class="filter-item">
            <span class="filter-label">Date Range</span>
            <div class="dropdown">
                <select id="dateRangeFilter" onchange="updateDashboardData()">
                    <option value="today">Today</option>
                    <option value="mtd" selected>MTD</option>
                    <option value="ytd">YTD</option>
                    <option value="custom">Custom</option>
                </select>
            </div>
        </div>
        <span class="separator">|</span>
        <div class="filter-item">
            <span class="filter-label">Channel</span>
            <span class="filter-value">All Channels</span>
        </div>
        <span class="separator">|</span>
        <div class="filter-item">
            <span class="filter-label">Sales Type</span>
            <span class="filter-value">All Sales</span>
        </div>
    </div>

    <script>
        // Mock data for different date ranges
        const mockDataByRange = {
            today: {
                totalSales: 3200000,
                online: 1950000,
                offline: 1250000,
                orders: 520,
                unitsSold: 820,
                aov: 6154
            },
            mtd: {
                totalSales: 95000000,
                online: 58000000,
                offline: 37000000,
                orders: 15700,
                unitsSold: 24800,
                aov: 6051
            },
            ytd: {
                totalSales: 520000000,
                online: 348000000,
                offline: 172000000,
                orders: 75000,
                unitsSold: 125000,
                aov: 6933
            },
            custom: {
                totalSales: 150000000,
                online: 92000000,
                offline: 58000000,
                orders: 25000,
                unitsSold: 38000,
                aov: 6000
            }
        };

        function updateDashboardData() {
            const selectedRange = document.getElementById('dateRangeFilter').value;
            const data = mockDataByRange[selectedRange];

            // Update KPI cards if they exist
            const kpiCards = document.querySelectorAll('.kpi-card');
            if (kpiCards.length >= 4) {
                // Revenue
                const revenueCard = kpiCards[0];
                const revenueValue = revenueCard.querySelector('.value');
                if (revenueValue) {
                    revenueValue.textContent = data.totalSales.toLocaleString();
                }

                // Orders
                const ordersCard = kpiCards[1];
                const ordersValue = ordersCard.querySelector('.value');
                if (ordersValue) {
                    ordersValue.textContent = data.orders.toLocaleString();
                }

                // Units Sold
                const unitsCard = kpiCards[2];
                const unitsValue = unitsCard.querySelector('.value');
                if (unitsValue) {
                    unitsValue.textContent = data.unitsSold.toLocaleString();
                }

                // AOV
                const aovCard = kpiCards[3];
                const aovValue = aovCard.querySelector('.value');
                if (aovValue) {
                    aovValue.textContent = data.aov.toLocaleString();
                }
            }

            // Update goal card if exists
            const goalAmount = document.querySelector('.goal-amount');
            const currentYearValue = document.querySelector('.stat-item:nth-child(1) .stat-value');
            const currentMonthValue = document.querySelector('.stat-item:nth-child(3) .stat-value');
            const progressFill = document.querySelector('.progress-fill');
            const progressLabel = document.querySelector('.progress-label span:last-child');

            if (goalAmount && selectedRange === 'ytd') {
                goalAmount.textContent = '1,000,000,000';
                if (currentYearValue) currentYearValue.textContent = '520,000,000';
                if (progressFill) progressFill.style.width = '52%';
                if (progressLabel) progressLabel.textContent = '52.0%';
            } else if (goalAmount) {
                goalAmount.textContent = data.totalSales.toLocaleString();
                if (currentYearValue) currentYearValue.textContent = data.totalSales.toLocaleString();
                if (progressFill) progressFill.style.width = '100%';
                if (progressLabel) progressLabel.textContent = '100%';
            }
        }
    </script>

    <div class="container">