<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

session_start();

// Load helpers
require_once __DIR__ . '/helpers.php';
initPerformanceSettings();

// Database connection
try {
    require_once __DIR__ . '/../database.php';
    if (!$conn) {
        throw new Exception("ไม่สามารถเชื่อมต่อฐานข้อมูลได้: " . print_r(sqlsrv_errors(), true));
    }
} catch (Exception $e) {
    die("<h1>Database Connection Error</h1><pre>" . $e->getMessage() . "</pre>");
}

$message = '';
$messageType = '';
$stats = ['total' => 0, 'success' => 0, 'skipped' => 0, 'error' => 0];
$errors = [];

// --- Process Upload ---

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    $batchId = 'JST_' . date('Ymd_His');
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmpName = $file['tmp_name'];
        
        // อ่านและแปลง Encoding
        $content = file_get_contents($tmpName);
        $content = convertToUtf8($content);
        file_put_contents($tmpName, $content);
        
        $handle = fopen($tmpName, 'r');
        
        if ($handle !== false) {
            // อ่าน Header
            $header = fgetcsv($handle);
            
            if ($header === false) {
                $message = "ไฟล์ว่างเปล่าหรืออ่านไม่ได้";
                $messageType = 'error';
            } else {
                $columnMap = createColumnMap($header);
                $requiredCols = ['orderId', 'sale_date', 'net_sales'];
                $missingCols = checkRequiredColumns($columnMap, $requiredCols);
                if (!empty($missingCols)) {
                    $message = "ไม่พบ Column ที่จำเป็นในไฟล์ CSV: " . implode(', ', $missingCols) 
                             . "<br><small>Columns ที่พบ: " . htmlspecialchars(implode(', ', array_keys($columnMap))) . "</small>";
                    $messageType = 'error';
                }
                
                if (empty($message)) {
                    $beginTransaction = sqlsrv_begin_transaction($conn);
                    if ($beginTransaction === false) {
                        $message = "❌ ไม่สามารถเริ่ม Transaction ได้: " . (sqlsrv_errors()[0]['message'] ?? 'Unknown');
                        $messageType = 'error';
                    } else {
                        try {
                            $lineNum = 1;
                            $batchSize = 100; // ประมวลผลทีละ 100 รายการ
                            $batchParams = [];
                            
                            while (($data = fgetcsv($handle)) !== false) {
                                $lineNum++;
                                $stats['total']++;
                                
                                // ข้ามแถวว่าง
                                if (empty($data[0])) continue;
                                
                                $orderId = trim($data[$columnMap['orderId']] ?? '');
                                if (empty($orderId)) {
                                    $stats['error']++;
                                    $errors[] = "Line $lineNum: orderId ว่างเปล่า";
                                    continue;
                                }
                                
                                $dupCheck = checkDuplicate($conn, 'jst_sale_detail', 'orderId', $orderId);
                                if (isset($dupCheck['error'])) {
                                    $stats['error']++;
                                    $errors[] = "Line $lineNum (check dup): " . $dupCheck['error'];
                                    continue;
                                }
                                if ($dupCheck['exists']) {
                                    $stats['skipped']++;
                                    continue;
                                }
                                
                                // เตรียมข้อมูล
                                $params = [
                                    $orderId,
                                    cleanString($data[$columnMap['orderNumber']] ?? ''),
                                    cleanString($data[$columnMap['platformOrderId']] ?? ''),
                                    convertDate($data[$columnMap['sale_date']] ?? ''),
                                    convertDateTime($data[$columnMap['order_datetime']] ?? ''),
                                    cleanString($data[$columnMap['status']] ?? ''),
                                    cleanString($data[$columnMap['statusDisplay']] ?? ''),
                                    cleanString($data[$columnMap['platform']] ?? ''),
                                    cleanNumber($data[$columnMap['subtotal']] ?? 0),
                                    cleanNumber($data[$columnMap['net_sales']] ?? 0),
                                    cleanNumber($data[$columnMap['shop_discount']] ?? 0),
                                    cleanNumber($data[$columnMap['total_discount']] ?? 0),
                                    cleanNumber($data[$columnMap['freight']] ?? 0),
                                    cleanString($data[$columnMap['payment_channel']] ?? ''),
                                    cleanString($data[$columnMap['buyer_name']] ?? ''),
                                    $batchId
                                ];
                                
                                $sql = "INSERT INTO jst_sale_detail 
                                        (orderId, orderNumber, platformOrderId, sale_date, order_datetime, 
                                         status, statusDisplay, platform, subtotal, net_sales, 
                                         shop_discount, total_discount, freight, payment_channel, buyer_name, import_batch)
                                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                                        
                                $stmt = sqlsrv_query($conn, $sql, $params);
                                
                                if ($stmt === false) {
                                    $stats['error']++;
                                    $errs = sqlsrv_errors();
                                    $errors[] = "Line $lineNum: " . ($errs[0]['message'] ?? 'Unknown Error');
                                } else {
                                    $stats['success']++;
                                    sqlsrv_free_stmt($stmt);
                                }
                                
                                // Flush output เพื่อแสดง progress
                                if ($stats['total'] % $batchSize === 0) {
                                    ob_flush();
                                    flush();
                                }
                            }
                            
                            // [FIX] Commit เสมอ แต่แจ้งเตือนถ้ามี error บางแถว
                            // ไม่ rollback ทั้งหมด เพราะจะทำให้ข้อมูลที่ถูกต้องหาย
                            sqlsrv_commit($conn);
                            if ($stats['error'] == 0) {
                                $message = "✅ อัปโหลดสำเร็จ! Batch: $batchId (รวม {$stats['success']} รายการ" 
                                         . ($stats['skipped'] > 0 ? ", ข้ามซ้ำ {$stats['skipped']} รายการ" : '') . ")";
                                $messageType = 'success';
                            } else {
                                $message = "⚠️ อัปโหลดเสร็จแต่พบข้อผิดพลาด {$stats['error']} รายการ "
                                         . "(บันทึกสำเร็จ {$stats['success']} รายการ"
                                         . ($stats['skipped'] > 0 ? ", ข้ามซ้ำ {$stats['skipped']} รายการ" : '') . ")";
                                $messageType = 'error';
                            }
                            
                        } catch (Exception $e) {
                            sqlsrv_rollback($conn);
                            $message = "❌ System Error: " . $e->getMessage();
                            $messageType = 'error';
                        }
                    }
                }
            }
            fclose($handle);
        }
    } else {
        $message = "❌ ไฟล์อัปโหลดไม่สมบูรณ์ (Error Code: " . $file['error'] . ")";
        $messageType = 'error';
    }
}

// ดึงข้อมูลสรุป
$summaryData = [];
$sumSql = "SELECT TOP 5 platform, COUNT(*) as total, SUM(net_sales) as sales FROM jst_sale_detail GROUP BY platform ORDER BY sales DESC";
$sumStmt = sqlsrv_query($conn, $sumSql);
if ($sumStmt) {
    while ($row = sqlsrv_fetch_array($sumStmt, SQLSRV_FETCH_ASSOC)) {
        $summaryData[] = $row;
    }
    sqlsrv_free_stmt($sumStmt);
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Import JST Sale Detail</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 20px; margin: 0; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 1px solid #e5e5e5; padding-bottom: 15px; margin-top: 0; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        input[type="file"] { display: block; width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; }
        button { background: #333; color: white; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #555; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; border: 1px solid #e5e5e5; text-align: left; }
        th { background: #f9f9f9; font-weight: 500; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; }
        .stat-box { flex: 1; padding: 20px; background: #f9f9f9; border-radius: 4px; text-align: center; }
        .stat-box h3 { margin: 0; font-size: 28px; color: #333; }
        .stat-box p { margin: 5px 0 0; color: #666; font-size: 13px; }
        .nav-links { display: flex; gap: 10px; margin-bottom: 20px; }
        .nav-links a { padding: 8px 16px; background: #f5f5f5; color: #333; text-decoration: none; border-radius: 4px; font-size: 13px; }
        .nav-links a:hover { background: #e5e5e5; }
        .nav-links a.active { background: #333; color: white; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="import.jst.php" class="active">JST Sale</a>
            <a href="import_ada.php">POS Sale</a>
        </div>
        <h1>Import JST Sale Detail</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($stats['total'] > 0): ?>
        <div class="stats">
            <div class="stat-box"><h3><?php echo number_format($stats['total']); ?></h3><p>ทั้งหมด</p></div>
            <div class="stat-box"><h3><?php echo number_format($stats['success']); ?></h3><p>สำเร็จ</p></div>
            <div class="stat-box"><h3><?php echo number_format($stats['skipped']); ?></h3><p>ข้าม (ซ้ำ)</p></div>
            <div class="stat-box"><h3><?php echo number_format($stats['error']); ?></h3><p>ผิดพลาด</p></div>
        </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
        <div class="alert alert-error">
            <strong>รายละเอียดข้อผิดพลาด (สูงสุด 10 รายการ):</strong>
            <ul style="margin: 10px 0 0 20px; padding: 0;">
            <?php foreach (array_slice($errors, 0, 10) as $err): ?>
                <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
            <?php if (count($errors) > 10): ?>
                <li>... และอีก <?php echo count($errors) - 10; ?> รายการ</li>
            <?php endif; ?>
            </ul>
        </div>
        <?php endif; ?>
        
        <div class="form-group">
            <form method="POST" enctype="multipart/form-data">
                <label>เลือกไฟล์ JST_sale_detail.csv:</label>
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit">อัปโหลดข้อมูล</button>
            </form>
        </div>
        
        <?php if (!empty($summaryData)): ?>
        <h3>สรุปยอดขายล่าสุด (Top Platforms)</h3>
        <table>
            <thead>
                <tr>
                    <th>Platform</th>
                    <th>จำนวนออเดอร์</th>
                    <th>ยอดรวมสุทธิ</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($summaryData as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['platform']); ?></td>
                    <td><?php echo number_format($row['total']); ?></td>
                    <td><?php echo number_format($row['sales'], 2); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</body>
</html>