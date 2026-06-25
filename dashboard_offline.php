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

// Mock data for offline sales dashboard (ของจริงจะมาแทนตรงนี้ทีหลัง)
$mockData = [
    'totalSales' => 1850000,
    'orders' => 1250,
    'avgOrderValue' => 1480,
    'growth' => 8.2,
    'branches' => [
        ['name' => 'Journal Central World', 'sales' => 520000, 'orders' => 380, 'aov' => 1368, 'zone' => 'กรุงเทพ'],
        ['name' => 'Journal Central Ladprao', 'sales' => 380000, 'orders' => 290, 'aov' => 1310, 'zone' => 'กรุงเทพ'],
        ['name' => 'Journal MAYA', 'sales' => 340000, 'orders' => 260, 'aov' => 1308, 'zone' => 'เชียงใหม่'],
        ['name' => 'Journal Central Chiangmai', 'sales' => 310000, 'orders' => 180, 'aov' => 1722, 'zone' => 'เชียงใหม่'],
        ['name' => 'Journal Central Hatyai', 'sales' => 300000, 'orders' => 140, 'aov' => 2143, 'zone' => 'ภาคใต้']
    ],
    'zones' => [
        ['name' => 'กรุงเทพ', 'sales' => 900000, 'orders' => 670, 'branches' => 2, 'percentage' => 48.6],
        ['name' => 'เชียงใหม่', 'sales' => 650000, 'orders' => 440, 'branches' => 2, 'percentage' => 35.1],
        ['name' => 'ภาคใต้', 'sales' => 300000, 'orders' => 140, 'branches' => 1, 'percentage' => 16.3]
    ],
    'customerTypes' => [
        ['name' => 'ลูกค้าสมาชิก', 'sales' => 1200000, 'orders' => 750, 'percentage' => 64.9],
        ['name' => 'ลูกค้าทั่วไป', 'sales' => 650000, 'orders' => 500, 'percentage' => 35.1]
    ],
    'topProducts' => [
        ['name' => 'Promise Parfum 100 ml', 'sales' => 100000, 'orders' => 75, 'category' => 'PERFUME'],
        ['name' => 'Laong Nan Parfum 50 ml', 'sales' => 100000, 'orders' => 70, 'category' => 'PERFUME'],
        ['name' => 'The Legacy Parfum 50 ml', 'sales' => 70000, 'orders' => 55, 'category' => 'PERFUME'],
        ['name' => 'Forever Love Body Oil 180 ml', 'sales' => 77000, 'orders' => 50, 'category' => 'SKINCARE'],
        ['name' => 'Mango Vanilla Perfume Sachet', 'sales' => 69000, 'orders' => 45, 'category' => 'HOME & LIFESTYLE']
    ],
    'monthlyTrend' => [
        ['month' => 'Jan', 'sales' => 1400000, 'orders' => 980],
        ['month' => 'Feb', 'sales' => 1500000, 'orders' => 1050],
        ['month' => 'Mar', 'sales' => 1550000, 'orders' => 1080],
        ['month' => 'Apr', 'sales' => 1600000, 'orders' => 1120],
        ['month' => 'May', 'sales' => 1700000, 'orders' => 1180],
        ['month' => 'Jun', 'sales' => 1850000, 'orders' => 1250]
    ]
];

// ----- ตัวแปรสำหรับ header.php -----
$pageTitle    = 'Offline Sales Dashboard';
$pageSubtitle = 'Retail Store Performance Overview';
$accentColor  = '#1a1a2e';
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
                <div class="change positive">▲ 6.5% vs last month</div>
            </div>
            <div class="kpi-card">
                <div class="label">Avg Order Value</div>
                <div class="value"><?php echo number_format($mockData['avgOrderValue']); ?></div>
                <div class="change positive">▲ 1.8% vs last month</div>
            </div>
            <div class="kpi-card">
                <div class="label">Active Branches</div>
                <div class="value">5</div>
                <div class="change">All branches operational</div>
            </div>
        </div>

        <!-- Charts Section -->
        <div class="charts-grid">
            <div class="chart-card">
                <h3>Monthly Sales Trend</h3>
                <div style="height: 200px; display: flex; align-items: flex-end; gap: 30px; padding: 20px 0;">
                    <?php foreach ($mockData['monthlyTrend'] as $trend): ?>
                    <div style="flex: 1; text-align: center;">
                        <div style="background: #1a1a2e; border-radius: 4px 4px 0 0; height: <?php echo ($trend['sales'] / $maxMonthlySales) * 150; ?>px;"></div>
                        <div style="margin-top: 10px; font-size: 12px; color: #666;"><?php echo $trend['month']; ?></div>
                        <div style="font-size: 11px; color: #999;"><?php echo number_format($trend['sales']/1000); ?>K</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>

            <div class="chart-card">
                <h3>Zone Distribution</h3>
                <div style="padding: 20px 0;">
                    <?php foreach ($mockData['zones'] as $zone): ?>
                    <div class="simple-bar">
                        <div class="name"><?php echo htmlspecialchars($zone['name']); ?></div>
                        <div class="bar-area">
                            <div class="bar-container">
                                <div class="bar" style="width: <?php echo $zone['percentage']; ?>%;"></div>
                            </div>
                        </div>
                        <div class="value"><?php echo $zone['percentage']; ?>%</div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Branch Performance -->
        <div class="table-card">
            <h3>Branch Performance</h3>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Branch</th>
                        <th>Zone</th>
                        <th>Sales</th>
                        <th>Orders</th>
                        <th>Avg Order</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mockData['branches'] as $index => $branch): ?>
                    <tr>
                        <td><span style="display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:50%;background:#1a1a2e;color:white;font-size:11px;font-weight:600;"><?php echo $index + 1; ?></span></td>
                        <td><span class="badge branch"><?php echo htmlspecialchars($branch['name']); ?></span></td>
                        <td><span class="badge zone"><?php echo htmlspecialchars($branch['zone']); ?></span></td>
                        <td><?php echo number_format($branch['sales']); ?></td>
                        <td><?php echo number_format($branch['orders']); ?></td>
                        <td><?php echo number_format($branch['aov']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Customer Types -->
        <div class="table-card">
            <h3>Customer Type Distribution</h3>
            <table>
                <thead>
                    <tr>
                        <th>Customer Type</th>
                        <th>Sales</th>
                        <th>Orders</th>
                        <th>Percentage</th>
                        <th>Performance</th>
                    </tr>
                </thead>
                <tbody>
                    <?php
                    $maxPercentage = max(array_column($mockData['customerTypes'], 'percentage'));
                    foreach ($mockData['customerTypes'] as $type):
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($type['name']); ?></td>
                        <td><?php echo number_format($type['sales']); ?></td>
                        <td><?php echo number_format($type['orders']); ?></td>
                        <td><?php echo $type['percentage']; ?>%</td>
                        <td>
                            <div class="bar-container" style="width: 100px;">
                                <div class="bar" style="width: <?php echo ($type['percentage'] / $maxPercentage) * 100; ?>%;"></div>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <!-- Top Products -->
        <div class="table-card">
            <h3>Top Selling Products (Offline)</h3>
            <table>
                <thead>
                    <tr>
                        <th>Rank</th>
                        <th>Product</th>
                        <th>Category</th>
                        <th>Sales</th>
                        <th>Orders</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mockData['topProducts'] as $index => $product): ?>
                    <tr>
                        <td><span style="display:inline-block;width:24px;height:24px;line-height:24px;text-align:center;border-radius:50%;background:#1a1a2e;color:white;font-size:11px;font-weight:600;"><?php echo $index + 1; ?></span></td>
                        <td><?php echo htmlspecialchars($product['name']); ?></td>
                        <td><span class="badge" style="background: #f3f4f6; color: #374151;"><?php echo htmlspecialchars($product['category']); ?></span></td>
                        <td><?php echo number_format($product['sales']); ?></td>
                        <td><?php echo number_format($product['orders']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
<?php require_once __DIR__ . '/includes/footer.php'; ?>