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
        'consignment' => 18500000,
        'total' => 113500000
    ],
    'monthlyTargets' => [
        'online' => 55000000,
        'offline' => 40000000,
        'consignment' => 20000000,
        'total' => 115000000
    ],
    'regionalSales' => [
        'Bangkok' => 52000000,
        'Central' => 28000000,
        'North' => 15000000,
        'Northeast' => 8500000,
        'South' => 10000000
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
    'upt' => round(24800 / 15700, 2), // Units per Transaction = unitsSold / orders

'aov' => round(95000000 / 15700),

'alerts' => [
    ['type' => 'positive', 'message' => 'Shopee +45.2% vs last month'],
    ['type' => 'positive', 'message' => 'TikTok +32.5% vs last month'],
    ['type' => 'positive', 'message' => 'Central World +18.1% vs last month'],
    ['type' => 'negative', 'message' => 'Lazada -12.4% vs last month'],
    ['type' => 'negative', 'message' => 'Legacy Parfum -8.3% vs last month']
],
    'platforms' => [
        ['name' => 'Shopee', 'sales' => 28000000, 'orders' => 5200, 'growth' => 32.5, 'channel' => 'Shopee'],
        ['name' => 'TikTok', 'sales' => 18500000, 'orders' => 3800, 'growth' => 45.2, 'channel' => 'TikTok'],
        ['name' => 'Website', 'sales' => 8500000, 'orders' => 2100, 'growth' => 18.5, 'channel' => 'Website'],
        ['name' => 'Lazada', 'sales' => 3000000, 'orders' => 1400, 'growth' => 12.8, 'channel' => 'Facebook']
    ],
'branches' => [
[
'name'=>'Journal Central World',
'sales'=>14500000,
'orders'=>1200,
'aov'=>12083,
'type'=>'offline',
'region'=>'Bangkok',
'lat'=>13.7246,
'lng'=>100.5433,
'channels'=>['Shopee'=>5000000,'TikTok'=>4000000,'Website'=>2000000,'Facebook'=>2500000,'Line OA'=>1000000]
],
[
'name'=>'Journal Central Ladprao',
'sales'=>9500000,
'orders'=>850,
'aov'=>11176,
'type'=>'offline',
'region'=>'Bangkok',
'lat'=>13.8050,
'lng'=>100.6329,
'channels'=>['Shopee'=>3500000,'TikTok'=>2500000,'Website'=>1500000,'Facebook'=>1500000,'Line OA'=>500000]
],
[
'name'=>'Journal MAYA',
'sales'=>7200000,
'orders'=>620,
'aov'=>11612,
'type'=>'offline',
'region'=>'Chiang Mai',
'lat'=>18.7883,
'lng'=>98.9853,
'channels'=>['Shopee'=>2500000,'TikTok'=>2000000,'Website'=>1200000,'Facebook'=>1000000,'Line OA'=>500000]
],
[
'name'=>'Journal Central Chiangmai',
'sales'=>3800000,
'orders'=>320,
'aov'=>11875,
'type'=>'offline',
'region'=>'Chiang Mai',
'lat'=>18.7930,
'lng'=>98.9870,
'channels'=>['Shopee'=>1200000,'TikTok'=>1000000,'Website'=>800000,'Facebook'=>500000,'Line OA'=>300000]
],
[
'name'=>'Journal Central Hatyai',
'sales'=>2000000,
'orders'=>210,
'aov'=>9523,
'type'=>'offline',
'region'=>'Songkhla',
'lat'=>7.0085,
'lng'=>100.4747,
'channels'=>['Shopee'=>600000,'TikTok'=>500000,'Website'=>400000,'Facebook'=>300000,'Line OA'=>200000]
],
[
'name'=>'King Power',
'sales'=>12000000,
'orders'=>950,
'aov'=>12631,
'type'=>'consignment',
'region'=>'Bangkok',
'lat'=>13.6900,
'lng'=>100.7501,
'channels'=>['Shopee'=>4000000,'TikTok'=>3000000,'Website'=>2000000,'Facebook'=>2000000,'Line OA'=>1000000]
],
[
'name'=>'Sephora',
'sales'=>8500000,
'orders'=>680,
'aov'=>12500,
'type'=>'consignment',
'region'=>'Bangkok',
'lat'=>13.7290,
'lng'=>100.5640,
'channels'=>['Shopee'=>3000000,'TikTok'=>2000000,'Website'=>1500000,'Facebook'=>1500000,'Line OA'=>500000]
]
],
    'topProducts' => [
        ['name' => 'Promise Parfum 100 ml', 'sales' => 18500000,'unit' => 2450, 'contribution'=>25, 'category' => 'PERFUME', 'channels' => ['Shopee' => 8500000, 'TikTok' => 6000000, 'Website' => 2500000, 'Facebook' => 1000000, 'Line OA' => 500000]],
        ['name' => 'Laong Nan Parfum 50 ml', 'sales' => 12500000, 'unit' => 1992,'contribution'=>19, 'category' => 'PERFUME', 'channels' => ['Shopee' => 5000000, 'TikTok' => 4000000, 'Website' => 2000000, 'Facebook' => 1000000, 'Line OA' => 500000]],
        ['name' => 'The Legacy Parfum 50 ml', 'sales' => 9800000, 'unit' => 1400,'contribution'=>15, 'category' => 'PERFUME', 'channels' => ['Shopee' => 4000000, 'TikTok' => 3000000, 'Website' => 1500000, 'Facebook' => 800000, 'Line OA' => 500000]],
        ['name' => 'Forever Love Body Oil 180 ml', 'sales' => 7200000,'unit' => 1023, 'contribution'=>14, 'category' => 'SKINCARE', 'channels' => ['Shopee' => 3000000, 'TikTok' => 2000000, 'Website' => 1000000, 'Facebook' => 800000, 'Line OA' => 400000]],
        ['name' => 'Mango Vanilla Perfume Sachet', 'sales' => 5500000, 'unit' => 888, 'contribution'=>12, 'category' => 'HOME & LIFESTYLE', 'channels' => ['Shopee' => 2500000, 'TikTok' => 1500000, 'Website' => 800000, 'Facebook' => 400000, 'Line OA' => 300000]]
    ],
    'monthlyTrend' => [
        ['month' => 'Jan', 'online' => 42000000, 'offline' => 28000000, 'consignment' => 15000000, 'channels' => ['Shopee' => 18000000, 'TikTok' => 12000000, 'Website' => 6000000, 'Facebook' => 4000000, 'Line OA' => 2000000]],
        ['month' => 'Feb', 'online' => 45000000, 'offline' => 30000000, 'consignment' => 16000000, 'channels' => ['Shopee' => 19000000, 'TikTok' => 13000000, 'Website' => 6500000, 'Facebook' => 4500000, 'Line OA' => 2000000]],
        ['month' => 'Mar', 'online' => 48000000, 'offline' => 32000000, 'consignment' => 17000000, 'channels' => ['Shopee' => 20000000, 'TikTok' => 14000000, 'Website' => 7000000, 'Facebook' => 5000000, 'Line OA' => 2000000]],
        ['month' => 'Apr', 'online' => 52000000, 'offline' => 34000000, 'consignment' => 18000000, 'channels' => ['Shopee' => 22000000, 'TikTok' => 15000000, 'Website' => 7500000, 'Facebook' => 5500000, 'Line OA' => 2000000]],
        ['month' => 'May', 'online' => 55000000, 'offline' => 35000000, 'consignment' => 18500000, 'channels' => ['Shopee' => 23000000, 'TikTok' => 16000000, 'Website' => 8000000, 'Facebook' => 6000000, 'Line OA' => 2000000]],
        ['month' => 'Jun', 'online' => 58000000, 'offline' => 37000000, 'consignment' => 19000000, 'channels' => ['Shopee' => 24000000, 'TikTok' => 17000000, 'Website' => 8500000, 'Facebook' => 6500000, 'Line OA' => 2000000]],
        ['month' => 'Jul', 'online' => 60000000, 'offline' => 38000000, 'consignment' => 19500000, 'channels' => ['Shopee' => 25000000, 'TikTok' => 18000000, 'Website' => 9000000, 'Facebook' => 6000000, 'Line OA' => 2000000]],
        ['month' => 'Aug', 'online' => 57000000, 'offline' => 36000000, 'consignment' => 18000000, 'channels' => ['Shopee' => 24000000, 'TikTok' => 17000000, 'Website' => 8500000, 'Facebook' => 5500000, 'Line OA' => 2000000]],
        ['month' => 'Sep', 'online' => 55000000, 'offline' => 34000000, 'consignment' => 17500000, 'channels' => ['Shopee' => 23000000, 'TikTok' => 16000000, 'Website' => 8000000, 'Facebook' => 5000000, 'Line OA' => 2000000]],
        ['month' => 'Oct', 'online' => 53000000, 'offline' => 32000000, 'consignment' => 17000000, 'channels' => ['Shopee' => 22000000, 'TikTok' => 15000000, 'Website' => 7500000, 'Facebook' => 4500000, 'Line OA' => 2000000]],
        ['month' => 'Nov', 'online' => 56000000, 'offline' => 35000000, 'consignment' => 18000000, 'channels' => ['Shopee' => 23000000, 'TikTok' => 16000000, 'Website' => 8000000, 'Facebook' => 5000000, 'Line OA' => 2000000]],
        ['month' => 'Dec', 'online' => 62000000, 'offline' => 40000000, 'consignment' => 20000000, 'channels' => ['Shopee' => 26000000, 'TikTok' => 18000000, 'Website' => 9000000, 'Facebook' => 6000000, 'Line OA' => 3000000]]
    ]
    
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
                <div style="display: flex; align-items: center; gap: 25px;">
                    <div style="position: relative; width: 130px; height: 130px; flex-shrink: 0;">
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
        <div class="stat-value">
            <?php echo number_format($mockData['goal']['currentYear']); ?>
        </div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Achievement</div>
        <div class="stat-value" style="color: <?php echo $achievement >= 100 ? '#6ee7b7' : '#fca5a5'; ?>;">
            <?php echo number_format($achievement, 1); ?>%
        </div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Monthly Target</div>
        <div class="stat-value">
            <?php echo number_format($mockData['goal']['monthlyTarget']); ?>
        </div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Gap</div>
        <div class="stat-value">
            <?php echo number_format($gap); ?>
        </div>
    </div>

    <div class="stat-item">
        <div class="stat-label">Current MTD</div>
        <div class="stat-value">
            <?php echo number_format($mockData['goal']['currentMonth']); ?>
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

        <!-- Monthly Target Progress -->
        <div class="chart-card">
            <h3 style="margin-bottom: 20px;">Monthly Target Progress</h3>
            <div style="display: flex; flex-direction: column; gap: 20px;">
                <?php
                $channels = [
                    ['name' => 'Online', 'current' => $mockData['totalSales']['online'], 'target' => $mockData['monthlyTargets']['online'], 'color' => '#c9a227'],
                    ['name' => 'Offline', 'current' => $mockData['totalSales']['offline'], 'target' => $mockData['monthlyTargets']['offline'], 'color' => '#1a1a2e'],
                    ['name' => 'Consignment', 'current' => $mockData['totalSales']['consignment'], 'target' => $mockData['monthlyTargets']['consignment'], 'color' => '#e67e22']
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
        <div style="display: grid; grid-template-columns: 3fr 2fr; gap: 20px; margin-bottom: 20px;">
            <!-- Monthly Revenue Trend (60%) -->
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Monthly Revenue Trend</h3>
                    <a href="#" style="font-size: 11px; color: #c9a227; text-decoration: none; font-weight: 500;">View Details →</a>
                </div>
                <div style="padding: 20px 0;">
                    <canvas id="monthlyRevenueChart" style="height: 320px;"></canvas>
                </div>
            </div>

            <!-- Sales Channel Distribution (40%) -->
            <div class="chart-card">
                <h3 id="channel-contribution">Sales Channel Distribution</h3>

                <!-- Layer 1: Donut Chart -->
                <div style="padding: 20px 0; display: flex; align-items: center; justify-content: center; gap: 40px;">
                    <div style="position: relative; width: 180px; height: 180px; flex-shrink: 0;">
                        <canvas id="channelContributionChart"></canvas>
                        <!-- Center Text -->
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none;">
                            <div style="font-size: 24px; font-weight: 600; color: #111827;"><?php echo number_format($mockData['totalSales']['total'] / 1000000, 1); ?>M</div>
                            <div style="font-size: 10px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.5px;">Total Sales</div>
                        </div>
                    </div>

                    <!-- Legend -->
                    <div style="flex: 1; display: flex; flex-direction: column; gap: 10px;">
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
                <div style="margin-top: 25px; padding-top: 20px; border-top: 1px solid #f0f0f0; display: grid; grid-template-columns: 1fr 1fr; gap: 25px;">
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
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px; margin-bottom: 20px;">
            <!-- Top 5 Selling Products (50%) -->
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Top 5 Selling Products</h3>
                    <a href="#" style="font-size: 11px; color: #c9a227; text-decoration: none; font-weight: 500;">View In-Depth →</a>
                </div>
                <div style="padding: 10px 0;">
                    <canvas id="topProductsChart" style="height: 280px;"></canvas>
                </div>
            </div>

            <!-- Geographic Sales Distribution (50%) -->
            <div class="chart-card">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                    <h3 style="margin: 0;">Geographic Sales Distribution</h3>
                    <a href="dashboard_offline.php" style="font-size: 11px; color: #c9a227; text-decoration: none; font-weight: 500;">View Details →</a>
                </div>
                <div style="padding: 10px 0;">
                    <canvas id="geoChoroplethChart" style="height: 280px;"></canvas>
                </div>
            </div>
        </div>

<script>
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

    // Top Products Horizontal Bar Chart
    const topProductsCtx = document.getElementById('topProductsChart').getContext('2d');
    const top5Products = <?php echo json_encode(array_slice($mockData['topProducts'], 0, 5)); ?>;

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
        topProducts: <?php echo json_encode($mockData['topProducts']); ?>,
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
        const filteredProducts = originalData.topProducts
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
            originalData.topProducts.forEach(product => {
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
