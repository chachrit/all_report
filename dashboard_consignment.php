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

// Mock data for consignment dashboard (ของจริงจะมาแทนตรงนี้ทีหลัง)
$mockData = [
    'totalRevenue' => 12500000,
    'totalOrders' => 2850,
    'sellThroughRate' => 72.5,
    'growthRate' => 18.3,
    'partners' => [
        ['name' => 'King Power', 'revenue' => 3800000, 'orders' => 850, 'aov' => 4471, 'growth' => 22.5, 'contribution' => 30.4],
        ['name' => 'Beautrium', 'revenue' => 2800000, 'orders' => 720, 'aov' => 3889, 'growth' => 15.8, 'contribution' => 22.4],
        ['name' => 'Eveandboy', 'revenue' => 2200000, 'orders' => 580, 'aov' => 3793, 'growth' => 12.3, 'contribution' => 17.6],
        ['name' => 'Watsons', 'revenue' => 1800000, 'orders' => 450, 'aov' => 4000, 'growth' => 18.5, 'contribution' => 14.4],
        ['name' => 'Boots', 'revenue' => 1200000, 'orders' => 180, 'aov' => 6667, 'growth' => 25.2, 'contribution' => 9.6],
        ['name' => 'Tsuruha', 'revenue' => 700000, 'orders' => 70, 'aov' => 10000, 'growth' => 8.5, 'contribution' => 5.6]
    ],
    'branches' => [
        ['partner' => 'King Power', 'branch' => 'King Power Suvarnabhumi', 'revenue' => 1800000, 'orders' => 420, 'growth' => 24.5, 'contribution' => 14.4],
        ['partner' => 'King Power', 'branch' => 'King Power Don Mueang', 'revenue' => 1200000, 'orders' => 280, 'growth' => 19.8, 'contribution' => 9.6],
        ['partner' => 'Beautrium', 'branch' => 'Beautrium Central World', 'revenue' => 1500000, 'orders' => 380, 'growth' => 16.2, 'contribution' => 12.0],
        ['partner' => 'Eveandboy', 'branch' => 'Eveandboy Siam Paragon', 'revenue' => 1100000, 'orders' => 290, 'growth' => 13.5, 'contribution' => 8.8],
        ['partner' => 'Watsons', 'branch' => 'Watsons Central Ladprao', 'revenue' => 850000, 'orders' => 210, 'growth' => 20.1, 'contribution' => 6.8],
        ['partner' => 'Boots', 'branch' => 'Boots Emporium', 'revenue' => 750000, 'orders' => 110, 'growth' => 26.8, 'contribution' => 6.0],
        ['partner' => 'Tsuruha', 'branch' => 'Tsuruha Central Chiangmai', 'revenue' => 450000, 'orders' => 45, 'growth' => 9.2, 'contribution' => 3.6],
        ['partner' => 'Beautrium', 'branch' => 'Beautrium Terminal 21', 'revenue' => 850000, 'orders' => 220, 'growth' => 14.8, 'contribution' => 6.8],
        ['partner' => 'Eveandboy', 'branch' => 'Eveandboy EmQuartier', 'revenue' => 680000, 'orders' => 175, 'growth' => 11.2, 'contribution' => 5.4],
        ['partner' => 'Watsons', 'branch' => 'Watsons Seacon Square', 'revenue' => 535000, 'orders' => 135, 'growth' => 17.5, 'contribution' => 4.3]
    ],
    'topProducts' => [
        ['product' => 'Promise Parfum 100 ml', 'partner' => 'King Power', 'revenue' => 850000, 'orders' => 120, 'aov' => 7083],
        ['product' => 'Laong Nan Parfum 50 ml', 'partner' => 'Beautrium', 'revenue' => 720000, 'orders' => 180, 'aov' => 4000],
        ['product' => 'The Legacy Parfum 50 ml', 'partner' => 'Eveandboy', 'revenue' => 580000, 'orders' => 145, 'aov' => 4000],
        ['product' => 'Forever Love Body Oil 180 ml', 'partner' => 'Watsons', 'revenue' => 450000, 'orders' => 90, 'aov' => 5000],
        ['product' => 'Mango Vanilla Perfume Sachet', 'partner' => 'Boots', 'revenue' => 380000, 'orders' => 60, 'aov' => 6333],
        ['product' => 'Promise Parfum 50 ml', 'partner' => 'King Power', 'revenue' => 350000, 'orders' => 70, 'aov' => 5000],
        ['product' => 'Laong Nan Parfum 100 ml', 'partner' => 'Beautrium', 'revenue' => 320000, 'orders' => 80, 'aov' => 4000],
        ['product' => 'The Legacy Parfum 100 ml', 'partner' => 'Eveandboy', 'revenue' => 280000, 'orders' => 70, 'aov' => 4000],
        ['product' => 'Forever Love Body Oil 90 ml', 'partner' => 'Watsons', 'revenue' => 250000, 'orders' => 50, 'aov' => 5000],
        ['product' => 'Rose Garden Body Oil 180 ml', 'partner' => 'Tsuruha', 'revenue' => 180000, 'orders' => 18, 'aov' => 10000]
    ],
    'inventory' => [
        ['partner' => 'King Power', 'currentStock' => 1250, 'sellThrough' => 78.5, 'daysOnHand' => 22, 'stockValue' => 8750000],
        ['partner' => 'Beautrium', 'currentStock' => 980, 'sellThrough' => 72.3, 'daysOnHand' => 28, 'stockValue' => 6860000],
        ['partner' => 'Eveandboy', 'currentStock' => 750, 'sellThrough' => 68.9, 'daysOnHand' => 32, 'stockValue' => 5250000],
        ['partner' => 'Watsons', 'currentStock' => 620, 'sellThrough' => 75.2, 'daysOnHand' => 25, 'stockValue' => 4340000],
        ['partner' => 'Boots', 'currentStock' => 380, 'sellThrough' => 82.1, 'daysOnHand' => 18, 'stockValue' => 2660000],
        ['partner' => 'Tsuruha', 'currentStock' => 280, 'sellThrough' => 65.4, 'daysOnHand' => 35, 'stockValue' => 1960000]
    ],
    'monthlyTrend' => [
        ['month' => 'Jan', 'revenue' => 8500000, 'orders' => 1950],
        ['month' => 'Feb', 'revenue' => 9200000, 'orders' => 2100],
        ['month' => 'Mar', 'revenue' => 9800000, 'orders' => 2250],
        ['month' => 'Apr', 'revenue' => 10500000, 'orders' => 2400],
        ['month' => 'May', 'revenue' => 11500000, 'orders' => 2650],
        ['month' => 'Jun', 'revenue' => 12500000, 'orders' => 2850]
    ]
];

// ----- ตัวแปรสำหรับ header.php -----
$pageTitle    = 'Consignment Dashboard';
$pageSubtitle = 'Retail Partner Performance Overview';
$accentColor  = '#62307a'; // Journal brand Purple (2612C) — see BRAND_COLORS.md
require_once __DIR__ . '/includes/header.php';
?>
        <!-- KPI Cards -->
        <div class="dash-section" data-section-id="kpi-cards" data-section-label-th="การ์ด KPI" data-section-label-en="KPI Cards">
        <div class="kpi-grid max-[900px]:grid-cols-2 max-[480px]:grid-cols-1">
            <div class="kpi-card">
                <div class="label">Total Revenue</div>
                <div class="value"><?php echo number_format($mockData['totalRevenue']); ?></div>
                <div class="change positive">▲ <?php echo $mockData['growthRate']; ?>% vs last month</div>
            </div>
            <div class="kpi-card">
                <div class="label">Total Orders</div>
                <div class="value"><?php echo number_format($mockData['totalOrders']); ?></div>
                <div class="change positive">▲ 14.2% vs last month</div>
            </div>
            <div class="kpi-card">
                <div class="label">Sell-through Rate</div>
                <div class="value"><?php echo $mockData['sellThroughRate']; ?>%</div>
                <div class="change positive">▲ 3.5% vs last month</div>
            </div>
            <div class="kpi-card">
                <div class="label">Growth Rate</div>
                <div class="value"><?php echo $mockData['growthRate']; ?>%</div>
                <div class="change positive">▲ 2.8% vs last month</div>
            </div>
        </div>
        </div>

        <!-- Charts Section -->
        <div class="dash-section" data-section-id="charts-section" data-section-label-th="กราฟภาพรวม" data-section-label-en="Charts Overview">
        <div class="charts-grid max-[900px]:grid-cols-1">
            <div class="chart-card">
                <h3>Monthly Consignment Revenue Trend</h3>
                <div style="padding: 20px 0;">
                    <canvas id="monthlyTrendChart" style="height: 250px;"></canvas>
                </div>
            </div>

            <div class="chart-card">
                <h3>Partner Contribution</h3>
                <div style="padding: 10px 0; display: flex; align-items: center; gap: 30px;">
                    <div style="position: relative; width: 160px; height: 160px; flex-shrink: 0;">
                        <canvas id="partnerContributionChart"></canvas>
                        <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center; pointer-events: none;">
                            <div style="font-size: 22px; font-weight: 300; color: #111827;"><?php echo number_format($mockData['totalRevenue'] / 1000000, 1); ?>M</div>
                            <div style="font-size: 9px; color: #9CA3AF; text-transform: uppercase; letter-spacing: 0.5px; line-height: 1.2;">Partner<br>Revenue</div>
                        </div>
                    </div>
                    <div style="flex: 1; display: grid; grid-template-columns: repeat(2, 1fr); gap: 12px;">
                        <?php
                        // Fixed brand-derived categorical order (validated colorblind-safe) — see BRAND_COLORS.md.
                        $colors = ['#4b74d8', '#8e792a', '#09899e', '#9b59bc', '#12933f', '#c55123'];
                        foreach ($mockData['partners'] as $index => $partner):
                        ?>
                        <div style="display: flex; align-items: center; gap: 8px;">
                            <div style="width: 10px; height: 10px; border-radius: 2px; background: <?php echo $colors[$index]; ?>;"></div>
                            <div style="flex: 1;">
                                <div style="font-size: 12px; color: #111827; font-weight: 500;"><?php echo htmlspecialchars($partner['name']); ?></div>
                                <div style="font-size: 10px; color: #9CA3AF;"><?php echo number_format($partner['revenue']/1000000, 1); ?>M · <?php echo $partner['contribution']; ?>%</div>
                            </div>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>
        </div>

        <!-- Partner Performance -->
        <div class="dash-section" data-section-id="partner-performance" data-section-label-th="ผลการดำเนินงานพาร์ทเนอร์" data-section-label-en="Partner Performance">
        <div class="table-card">
            <h3>Partner Performance</h3>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                    <tr>
                        <th>Partner</th>
                        <th>Revenue</th>
                        <th>Orders</th>
                        <th>Avg Order Value</th>
                        <th>Growth</th>
                        <th>Contribution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mockData['partners'] as $partner): ?>
                    <tr>
                        <td><span class="badge" style="background: rgba(98, 48, 122, 0.14); color: #62307a;"><?php echo htmlspecialchars($partner['name']); ?></span></td>
                        <td><?php echo number_format($partner['revenue']); ?></td>
                        <td><?php echo number_format($partner['orders']); ?></td>
                        <td><?php echo number_format($partner['aov']); ?></td>
                        <td style="color: #10B981; font-weight: 500;">▲ <?php echo $partner['growth']; ?>%</td>
                        <td><?php echo $partner['contribution']; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        </div>

        <!-- Branch Performance -->
        <div class="dash-section" data-section-id="branch-performance" data-section-label-th="ผลการดำเนินงานสาขา" data-section-label-en="Branch Performance">
        <div class="table-card">
            <h3>Branch Performance (Top 10)</h3>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                    <tr>
                        <th>Branch</th>
                        <th>Partner</th>
                        <th>Revenue</th>
                        <th>Orders</th>
                        <th>Growth</th>
                        <th>Contribution</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mockData['branches'] as $branch): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($branch['branch']); ?></td>
                        <td><span class="badge" style="background: rgba(98, 48, 122, 0.14); color: #62307a;"><?php echo htmlspecialchars($branch['partner']); ?></span></td>
                        <td><?php echo number_format($branch['revenue']); ?></td>
                        <td><?php echo number_format($branch['orders']); ?></td>
                        <td style="color: #10B981; font-weight: 500;">▲ <?php echo $branch['growth']; ?>%</td>
                        <td><?php echo $branch['contribution']; ?>%</td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        </div>

        <!-- Top Selling Products -->
        <div class="dash-section" data-section-id="top-products" data-section-label-th="สินค้าขายดี" data-section-label-en="Top Selling Products">
        <div class="table-card">
            <h3>Top Selling Products (Consignment)</h3>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                    <tr>
                        <th>Product</th>
                        <th>Partner</th>
                        <th>Revenue</th>
                        <th>Orders</th>
                        <th>Avg Order Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mockData['topProducts'] as $product): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($product['product']); ?></td>
                        <td><span class="badge" style="background: rgba(98, 48, 122, 0.14); color: #62307a;"><?php echo htmlspecialchars($product['partner']); ?></span></td>
                        <td><?php echo number_format($product['revenue']); ?></td>
                        <td><?php echo number_format($product['orders']); ?></td>
                        <td><?php echo number_format($product['aov']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        </div>

        <!-- Inventory Status -->
        <div class="dash-section" data-section-id="inventory-status" data-section-label-th="สถานะสินค้าคงคลัง" data-section-label-en="Inventory Status">
        <div class="table-card">
            <h3>Inventory Status</h3>
            <div class="max-[640px]:overflow-x-auto"><table>
                <thead>
                    <tr>
                        <th>Partner</th>
                        <th>Current Stock</th>
                        <th>Sell-through</th>
                        <th>Days on Hand</th>
                        <th>Stock Value</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($mockData['inventory'] as $item): ?>
                    <tr>
                        <td><span class="badge" style="background: rgba(98, 48, 122, 0.14); color: #62307a;"><?php echo htmlspecialchars($item['partner']); ?></span></td>
                        <td><?php echo number_format($item['currentStock']); ?></td>
                        <td><?php echo $item['sellThrough']; ?>%</td>
                        <td><?php echo $item['daysOnHand']; ?> days</td>
                        <td><?php echo number_format($item['stockValue']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table></div>
        </div>
        </div>

<script>
    // Monthly Trend Chart - Dual Axis (Revenue + Orders)
    const monthlyTrendCtx = document.getElementById('monthlyTrendChart').getContext('2d');

    const monthlyTrendData = {
        labels: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'month')); ?>,
        datasets: [
            {
                label: 'Revenue (฿)',
                data: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'revenue')); ?>,
                type: 'bar',
                backgroundColor: '#62307a',
                borderColor: '#62307a',
                borderWidth: 1,
                yAxisID: 'y',
                order: 2
            },
            {
                label: 'Orders',
                data: <?php echo json_encode(array_column($mockData['monthlyTrend'], 'orders')); ?>,
                type: 'line',
                backgroundColor: '#6B7280',
                borderColor: '#6B7280',
                borderWidth: 2,
                pointBackgroundColor: '#6B7280',
                pointBorderColor: '#fff',
                pointBorderWidth: 2,
                pointRadius: 4,
                pointHoverRadius: 6,
                tension: 0.3,
                yAxisID: 'y1',
                order: 1
            }
        ]
    };

    const monthlyTrendChart = new Chart(monthlyTrendCtx, {
        type: 'bar',
        data: monthlyTrendData,
        options: {
            responsive: true,
            maintainAspectRatio: false,
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
                            if (context.dataset.label.includes('Revenue')) {
                                label += new Intl.NumberFormat('th-TH').format(context.raw);
                            } else {
                                label += new Intl.NumberFormat('th-TH').format(context.raw);
                            }
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
                    type: 'linear',
                    display: true,
                    position: 'left',
                    title: {
                        display: true,
                        text: 'Revenue (฿)',
                        font: {
                            size: 11,
                            weight: 500
                        },
                        color: '#62307a'
                    },
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
                y1: {
                    type: 'linear',
                    display: true,
                    position: 'right',
                    title: {
                        display: true,
                        text: 'Orders',
                        font: {
                            size: 11,
                            weight: 500
                        },
                        color: '#6B7280'
                    },
                    ticks: {
                        font: {
                            size: 11,
                            weight: 500
                        },
                        color: '#6B7280'
                    },
                    grid: {
                        drawOnChartArea: false
                    }
                }
            }
        }
    });

    // Partner Contribution Doughnut Chart
    const partnerContributionCtx = document.getElementById('partnerContributionChart').getContext('2d');

    const partnerContributionData = {
        labels: <?php echo json_encode(array_column($mockData['partners'], 'name')); ?>,
        datasets: [{
            data: <?php echo json_encode(array_column($mockData['partners'], 'revenue')); ?>,
            // Same order as the legend swatches above — driven from the one PHP $colors
            // array so the donut and its legend can never drift apart.
            backgroundColor: <?php echo json_encode(array_slice($colors, 0, count($mockData['partners']))); ?>,
            borderWidth: 0,
            hoverOffset: 4
        }]
    };

    const partnerContributionChart = new Chart(partnerContributionCtx, {
        type: 'doughnut',
        data: partnerContributionData,
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
</script>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
