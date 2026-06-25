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

// Mock data for dashboard (จะแทนที่ด้วย query จริงเมื่อมีข้อมูล)
$mockData = [
    'goal' => [
        'annual' => 1000000000, // 1,000 ล้านบาทต่อปี
        'currentYear' => 520000000, // ยอดจริงปีนี้ (6 เดือนแรก) - 520 ล้าน
        'monthlyTarget' => 83333333, // เป้าหมายรายเดือน
        'currentMonth' => 95000000, // ยอดจริงเดือนนี้ - 95 ล้าน
        'projected' => 1040000000, // คาดการณ์ปลายปี - 1,040 ล้าน
        'onTrack' => true
    ],
    'totalSales' => [
        'online' => 58000000,
        'offline' => 37000000,
        'total' => 95000000
    ],
    'growth' => [
        'online' => 28.5,
        'offline' => 12.3,
        'total' => 22.1
    ],
    'orders' => [
    'online' => 12500,
    'offline' => 3200,
    'total' => 15700
    ],
    'unitsSold' => 24800,

'aov' => round(95000000 / 15700),

'alerts' => [
    ['type' => 'positive', 'message' => 'Shopee +45.2% vs last month'],
    ['type' => 'positive', 'message' => 'TikTok +32.5% vs last month'],
    ['type' => 'positive', 'message' => 'Central World +18.1% vs last month'],
    ['type' => 'negative', 'message' => 'Lazada -12.4% vs last month'],
    ['type' => 'negative', 'message' => 'Legacy Parfum -8.3% vs last month']
],
    'platforms' => [
        ['name' => 'Shopee', 'sales' => 28000000, 'orders' => 5200, 'growth' => 32.5],
        ['name' => 'TikTok', 'sales' => 18500000, 'orders' => 3800, 'growth' => 45.2],
        ['name' => 'Website', 'sales' => 8500000, 'orders' => 2100, 'growth' => 18.5],
        ['name' => 'Lazada', 'sales' => 3000000, 'orders' => 1400, 'growth' => 12.8]
    ],
'branches' => [
[
'name'=>'Journal Central World',
'sales'=>14500000,
'orders'=>1200,
'aov'=>12083
],
[
'name'=>'Journal Central Ladprao',
'sales'=>9500000,
'orders'=>850,
'aov'=>11176
],
[
'name'=>'Journal MAYA',
'sales'=>7200000,
'orders'=>620,
'aov'=>11612
],
[
'name'=>'Journal Central Chiangmai',
'sales'=>3800000,
'orders'=>320,
'aov'=>11875
],
[
'name'=>'Journal Central Hatyai',
'sales'=>2000000,
'orders'=>210,
'aov'=>9523
]
],
    'topProducts' => [
        ['name' => 'Promise Parfum 100 ml', 'sales' => 18500000,'unit' => 2450, 'contribution'=>25, 'category' => 'PERFUME'],
        ['name' => 'Laong Nan Parfum 50 ml', 'sales' => 12500000, 'unit' => 1992,'contribution'=>19, 'category' => 'PERFUME'],
        ['name' => 'The Legacy Parfum 50 ml', 'sales' => 9800000, 'unit' => 1400,'contribution'=>15, 'category' => 'PERFUME'],
        ['name' => 'Forever Love Body Oil 180 ml', 'sales' => 7200000,'unit' => 1023, 'contribution'=>14, 'category' => 'SKINCARE'],
        ['name' => 'Mango Vanilla Perfume Sachet', 'sales' => 5500000, 'unit' => 888, 'contribution'=>12, 'category' => 'HOME & LIFESTYLE']
    ],
    'monthlyTrend' => [
        ['month' => 'Jan', 'online' => 42000000, 'offline' => 28000000],
        ['month' => 'Feb', 'online' => 45000000, 'offline' => 30000000],
        ['month' => 'Mar', 'online' => 48000000, 'offline' => 32000000],
        ['month' => 'Apr', 'online' => 52000000, 'offline' => 34000000],
        ['month' => 'May', 'online' => 55000000, 'offline' => 35000000],
        ['month' => 'Jun', 'online' => 58000000, 'offline' => 37000000]
    ]
    
];
$channelContribution = [
    ['name'=>'Shopee','revenue'=>28000000,'percent'=>29.4],
    ['name'=>'TikTok','revenue'=>18500000,'percent'=>19.5],
    ['name'=>'Website','revenue'=>8500000,'percent'=>8.9],
    ['name'=>'Facebook','revenue'=>7200000,'percent'=>7.2],
    ['name'=>'Line OA','revenue'=>4100000,'percent'=>4.1]
];

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
                    <span><?php echo number_format(($mockData['goal']['currentYear'] / $mockData['goal']['annual']) * 100, 1); ?>%</span>
                </div>
                <div class="progress-bar">
                    <div class="progress-fill" style="width: <?php echo ($mockData['goal']['currentYear'] / $mockData['goal']['annual']) * 100; ?>%;"></div>
                </div>
            </div>

<?php
$gap = $mockData['goal']['currentMonth']
     - $mockData['goal']['monthlyTarget'];
$achievement = ($mockData['goal']['currentMonth'] / $mockData['goal']['monthlyTarget']) * 100;
?>

<div class="stats-grid six-items">

    <div class="stat-item">
        <div class="stat-label">Current Year</div>
        <div class="stat-value">
            <?php echo number_format($mockData['goal']['currentYear']); ?>
        </div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Monthly Target</div>
        <div class="stat-value">
            <?php echo number_format($mockData['goal']['monthlyTarget']); ?>
        </div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Current MTD</div>
        <div class="stat-value">
            <?php echo number_format($mockData['goal']['currentMonth']); ?>
        </div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Achievement</div>
        <div class="stat-value" style="color: <?php echo $achievement >= 100 ? '#6ee7b7' : '#fca5a5'; ?>;">
            <?php echo number_format($achievement, 1); ?>%
        </div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Gap</div>
        <div class="stat-value">
            <?php echo number_format($gap); ?>
        </div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Projected</div>
        <div class="stat-value">
            <?php echo number_format($mockData['goal']['projected']); ?>
        </div>
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
            border-left:4px solid <?php echo $alert['type']=='positive' ? '#10b981' : '#ef4444'; ?>;
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

    <div class="kpi-card">
        <div class="label">Revenue</div>
        <div class="value">
            <?php echo number_format($mockData['totalSales']['total']); ?>
        </div>
        <div class="change positive">
            ▲ <?php echo $mockData['growth']['total']; ?>% vs last month
        </div>
    </div>

    <div class="kpi-card">
        <div class="label">Orders</div>
        <div class="value">
            <?php echo number_format($mockData['orders']['total']); ?>
        </div>
        <div class="change positive">
            ▲ 10.2% vs last month
        </div>
    </div>

    <div class="kpi-card">
        <div class="label">Units Sold</div>
        <div class="value">
            <?php echo number_format($mockData['unitsSold']); ?>
        </div>
        <div class="change positive">
            ▲ 15.4% vs last month
        </div>
    </div>

    <div class="kpi-card">
        <div class="label">Average Order Value</div>
        <div class="value">
            <?php echo number_format($mockData['aov']); ?>
        </div>
        <div class="change positive">
            ▲ 8.1% vs last month
        </div>
    </div>

</div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Monthly Revenue Trend</h3>
                <div style="position: relative; height: 200px; display: flex; align-items: flex-end; gap: 30px; padding: 20px 0;">
                    <!-- Total Revenue Line -->
                    <svg style="position: absolute; top: 20px; left: 0; width: 100%; height: 150px; pointer-events: none; z-index: 10;">
                        <polyline
                            fill="none"
                            stroke="#10b981"
                            stroke-width="4"
                            stroke-linecap="round"
                            stroke-linejoin="round"
                            points="<?php
                            $points = [];
                            $totalMonths = count($mockData['monthlyTrend']);
                            foreach ($mockData['monthlyTrend'] as $index => $trend) {
                                $total = $trend['online'] + $trend['offline'];
                                // Calculate x position as percentage (center of each bar group)
                                $x = (($index + 0.5) / $totalMonths) * 100;
                                // Calculate y position as percentage from top
                                $y = 100 - (($total / $maxTrendValue) * 100);
                                $points[] = "$x%,$y%";
                            }
                            echo implode(' ', $points);
                            ?>"
                        />
                        <!-- Add dots at each point -->
                        <?php
                        foreach ($mockData['monthlyTrend'] as $index => $trend) {
                            $total = $trend['online'] + $trend['offline'];
                            $x = (($index + 0.5) / $totalMonths) * 100;
                            $y = 100 - (($total / $maxTrendValue) * 100);
                        ?>
                        <circle cx="<?php echo $x; ?>%" cy="<?php echo $y; ?>%" r="4" fill="#10b981" />
                        <?php } ?>
                    </svg>
                    <?php foreach ($mockData['monthlyTrend'] as $trend): ?>
                    <div style="flex: 1; text-align: center; position: relative;">
                        <div style="display: flex; gap: 4px; height: 150px; align-items: flex-end; justify-content: center;">
                            <div style="width: 20px; background: #c9a227; border-radius: 4px 4px 0 0; height: <?php echo ($trend['online'] / $maxTrendValue) * 150; ?>px;"></div>
                            <div style="width: 20px; background: #1a1a2e; border-radius: 4px 4px 0 0; height: <?php echo ($trend['offline'] / $maxTrendValue) * 150; ?>px;"></div>
                        </div>
                        <div style="margin-top: 10px; font-size: 12px; color: #999;"><?php echo $trend['month']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="display: flex; justify-content: center; gap: 20px; font-size: 12px; color: #999;">
                    <span style="display: flex; align-items: center; gap: 5px;"><div style="width: 12px; height: 12px; background: #c9a227; border-radius: 2px;"></div> Online</span>
                    <span style="display: flex; align-items: center; gap: 5px;"><div style="width: 12px; height: 12px; background: #1a1a2e; border-radius: 2px;"></div> Offline</span>
                    <span style="display: flex; align-items: center; gap: 5px;"><div style="width: 30px; height: 3px; background: #10b981; border-radius: 2px;"></div> Total</span>
                </div>
            </div>


        </div>
        <h3>Channel Contribution</h3>

<div style="padding:10px 0;">

<?php foreach($channelContribution as $row): ?>

<div class="simple-bar">

    <div class="name">
        <?= $row['name'] ?>
    </div>

    <div class="bar-area">
        <div class="bar-container">
            <div
                class="bar"
                style="width:<?= $row['percent'] ?>%;background:#c9a227">
            </div>
        </div>
    </div>

    <div style="display:flex;gap:15px;align-items:center;">
        <div style="font-size:13px;color:#333;font-weight:300;">
            <?= number_format($row['revenue']/1000000,1) ?>M
        </div>
        <div class="value">
            <?= $row['percent'] ?>%
        </div>
    </div>

</div>

<?php endforeach; ?>

</div>

        <!-- Split Tables -->
        <div class="split-grid">
            <div class="table-card">
                <h3>Top Online Platforms</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Platform</th>
                            <th>Sales</th>
                            <th>Orders</th>
                            <th>Contribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $totalPlatformSales = array_sum(array_column($mockData['platforms'], 'sales'));
                        foreach ($mockData['platforms'] as $platform): 
                        $contribution = ($platform['sales'] / $totalPlatformSales) * 100;
                        ?>
                        <tr>
                            <td><span class="badge online"><?php echo htmlspecialchars($platform['name']); ?></span></td>
                            <td><?php echo number_format($platform['sales']); ?></td>
                            <td><?php echo number_format($platform['orders']); ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div class="bar-container" style="width:80px;">
                                        <div class="bar" style="width:<?php echo $contribution; ?>%;background:#c9a227;"></div>
                                    </div>
                                    <span><?php echo number_format($contribution, 1); ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <div class="table-card">
                <h3>Top Branches</h3>
                <table>
                    <thead>
                        <tr>
                            <th>Branch</th>
                            <th>Sales</th>
                            <th>Orders</th>
                            <th>AOV</th>
                            <th>Contribution</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $totalBranchSales = array_sum(array_column($mockData['branches'], 'sales'));
                        foreach ($mockData['branches'] as $branch): 
                        $contribution = ($branch['sales'] / $totalBranchSales) * 100;
                        ?>
                        <tr>
                            <td><span class="badge offline"><?php echo htmlspecialchars($branch['name']); ?></span></td>
                            <td><?php echo number_format($branch['sales']); ?></td>
                            <td><?php echo number_format($branch['orders']); ?></td>
                            <td><?php echo number_format($branch['aov']); ?></td>
                            <td>
                                <div style="display:flex;align-items:center;gap:10px;">
                                    <div class="bar-container" style="width:80px;">
                                        <div class="bar" style="width:<?php echo $contribution; ?>%;background:#1a1a2e;"></div>
                                    </div>
                                    <span><?php echo number_format($contribution, 1); ?>%</span>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Top Products -->
        <div class="table-card">
            <h3>Top Selling Products</h3>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Sales</th>
                        <th>Units</th>
                        <th>Contribution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $maxSales = max(array_column($mockData['topProducts'], 'sales'));
                    foreach ($mockData['topProducts'] as $product):
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><span class="badge" style="background: #f3f4f6; color: #374151;"><?php echo htmlspecialchars($product['category']); ?></span></td>
                        <td><?php echo number_format($product['sales']); ?></td>
                        <td><?php echo number_format($product['unit']); ?></td>
                        <!-- <td><?php //echo number_format($product['units']); ?></td> -->
                         <td>

<div style="display:flex;align-items:center;gap:10px;">

    <div class="bar-container" style="width:120px;">

        <div
            class="bar"
            style="
                width:<?= $product['contribution'] ?>%;
                background:#c9a227;
            ">
        </div>

    </div>

    <span>
        <?= $product['contribution'] ?>%
    </span>

</div>

</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>