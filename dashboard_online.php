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

// Mock data for online sales dashboard (ของจริงจะมาแทนตรงนี้ทีหลัง)
$mockData = [
    'totalSales' => 2450000,
    'orders' => 3850,
    'avgOrderValue' => 636,
    'growth' => 15.5,
    'platforms' => [
        ['name' => 'Shopee', 'sales' => 980000, 'orders' => 1450, 'aov' => 676, 'growth' => 18.2, 'share' => 40.0],
        ['name' => 'TikTok', 'sales' => 720000, 'orders' => 1100, 'aov' => 655, 'growth' => 25.5, 'share' => 29.4],
        ['name' => 'Website', 'sales' => 450000, 'orders' => 680, 'aov' => 662, 'growth' => 12.1, 'share' => 18.4],
        ['name' => 'Lazada', 'sales' => 300000, 'orders' => 620, 'aov' => 484, 'growth' => 8.5, 'share' => 12.2]
    ],
    'paymentChannels' => [
        ['name' => 'COD', 'sales' => 850000, 'orders' => 1350, 'percentage' => 34.7],
        ['name' => 'PayLater', 'sales' => 680000, 'orders' => 980, 'percentage' => 27.8],
        ['name' => 'QR PromptPay', 'sales' => 520000, 'orders' => 820, 'percentage' => 21.2],
        ['name' => 'Credit Card', 'sales' => 280000, 'orders' => 450, 'percentage' => 11.4],
        ['name' => 'Mobile Banking', 'sales' => 120000, 'orders' => 250, 'percentage' => 4.9]
    ],
    'topProducts' => [
        ['name' => 'Promise Parfum 100 ml', 'sales' => 185000, 'orders' => 280, 'aov' => 661],
        ['name' => 'Laong Nan Parfum 50 ml', 'sales' => 145000, 'orders' => 220, 'aov' => 659],
        ['name' => 'The Legacy Parfum 50 ml', 'sales' => 128000, 'orders' => 195, 'aov' => 656],
        ['name' => 'Forever Love Body Oil 180 ml', 'sales' => 98000, 'orders' => 150, 'aov' => 653],
        ['name' => 'Mango Vanilla Perfume Sachet', 'sales' => 87000, 'orders' => 135, 'aov' => 644]
    ],
    'monthlyTrend' => [
        ['month' => 'Jan', 'sales' => 1800000, 'orders' => 2850],
        ['month' => 'Feb', 'sales' => 1950000, 'orders' => 3080],
        ['month' => 'Mar', 'sales' => 2100000, 'orders' => 3300],
        ['month' => 'Apr', 'sales' => 2200000, 'orders' => 3450],
        ['month' => 'May', 'sales' => 2350000, 'orders' => 3650],
        ['month' => 'Jun', 'sales' => 2450000, 'orders' => 3850]
    ]
];

// ----- ตัวแปรสำหรับ header.php -----
$pageTitle    = 'Online Sales Dashboard';
$pageSubtitle = 'E-commerce Performance Overview';
$accentColor  = '#c9a227';
require_once __DIR__ . '/includes/header.php';

// หาค่าสูงสุดจากข้อมูลจริง แทนตัวหาร hardcode เดิม (กันกราฟทะลุกรอบ)
$maxMonthlySales = max(array_column($mockData['monthlyTrend'], 'sales'));
?>
        <!-- KPI Cards -->
        <div class="kpi-grid">
            <div class="kpi-card">
                <div class="label">Total Revenue</div>
                <div class="value"><?php echo number_format($mockData['totalSales']); ?></div>
                <div class="change positive">▲ <?php echo $mockData['growth']; ?>% vs last month</div>
            </div>
            <div class="kpi-card">
                <div class="label">Total Orders</div>
                <div class="value"><?php echo number_format($mockData['orders']); ?></div>
                <div class="change positive">▲ 12.8% vs last month</div>
            </div>
            <div class="kpi-card">
                <div class="label">Avg Order Value</div>
                <div class="value"><?php echo number_format($mockData['avgOrderValue']); ?></div>
                <div class="change positive">▲ 2.1% vs last month</div>
            </div>
            <div class="kpi-card">
                <div class="label">Growth Rate</div>
                <div class="value"><?php echo $mockData['growth']; ?>%</div>
                <div class="change positive">▲ 3.2% vs last month</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Monthly Sales Trend</h3>
                <div style="height: 200px; display: flex; align-items: flex-end; gap: 30px; padding: 20px 0;">
                    <?php foreach ($mockData['monthlyTrend'] as $trend): ?>
                    <div style="flex: 1; text-align: center;">
                        <div style="background: #c9a227; border-radius: 4px 4px 0 0; height: <?php echo ($trend['sales'] / $maxMonthlySales) * 150; ?>px;"></div>
                        <div style="margin-top: 10px; font-size: 12px; color: #666;"><?php echo $trend['month']; ?></div>
                        <div style="font-size: 11px; color: #999;"><?php echo number_format($trend['sales']/1000); ?>K</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="chart-card">
                <h3>Platform Share</h3>
                <div style="padding: 20px 0;">
                    <?php foreach ($mockData['platforms'] as $platform): ?>
                    <div class="simple-bar">
                        <div class="name"><?php echo htmlspecialchars($platform['name']); ?></div>
                        <div class="bar-area">
                            <div class="bar-container">
                                <div class="bar" style="width: <?php echo $platform['share']; ?>%;"></div>
                            </div>
                        </div>
                        <div class="value"><?php echo $platform['share']; ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Platform Performance -->
        <div class="table-card">
            <h3>Platform Performance</h3>
            <table>
                <thead>
                    <tr>
                        <th>Platform</th>
                        <th>Sales</th>
                        <th>Orders</th>
                        <th>Avg Order</th>
                        <th>Growth</th>
                        <th>Share</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mockData['platforms'] as $platform): ?>
                    <tr>
                        <td><span class="badge platform"><?php echo htmlspecialchars($platform['name']); ?></span></td>
                        <td><?php echo number_format($platform['sales']); ?></td>
                        <td><?php echo number_format($platform['orders']); ?></td>
                        <td><?php echo number_format($platform['aov']); ?></td>
                        <td style="color: #10b981;">▲ <?php echo $platform['growth']; ?>%</td>
                        <td><?php echo $platform['share']; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Payment Channels -->
        <div class="table-card">
            <h3>Payment Channel Distribution</h3>
            <table>
                <thead>
                    <tr>
                        <th>Payment Method</th>
                        <th>Sales</th>
                        <th>Orders</th>
                        <th>Percentage</th>
                        <th>Performance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $maxPercentage = max(array_column($mockData['paymentChannels'], 'percentage'));
                    foreach ($mockData['paymentChannels'] as $channel):
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($channel['name']); ?></td>
                        <td><?php echo number_format($channel['sales']); ?></td>
                        <td><?php echo number_format($channel['orders']); ?></td>
                        <td><?php echo $channel['percentage']; ?>%</td>
                        <td>
                            <div class="bar-container" style="width: 100px;">
                                <div class="bar" style="width: <?php echo ($channel['percentage'] / $maxPercentage) * 100; ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Products -->
        <div class="table-card">
            <h3>Top Selling Products (Online)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Sales</th>
                        <th>Orders</th>
                        <th>Avg Order</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mockData['topProducts'] as $product): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><?php echo number_format($product['sales']); ?></td>
                        <td><?php echo number_format($product['orders']); ?></td>
                        <td><?php echo number_format($product['aov']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>