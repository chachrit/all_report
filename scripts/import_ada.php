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

// Column mapping for POS CSV (Thai headers)
$columnMapping = [
    'เลขที่เอกสาร'       => 'order_id',
    'วันที่เอกสาร'       => 'order_date',
    'ลูกค้า'             => 'customer_name',
    'มูลค่าสุทธิ'        => 'net_amount',
    'ช่องทางการขาย'      => 'payment_method',
    'สาขา'              => 'branch',
    'รหัสสินค้า'          => 'product_code',
    'รายชื่อสินค้า'        => 'product_name',
    'จำนวน'             => 'quantity',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    $batchId = 'POS_' . date('Ymd_His');
    
    // [FIX] เพิ่ม else สำหรับ upload error
    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmpName = $file['tmp_name'];
        
        // [FIX] ใช้ convertToUtf8 ที่มี BOM removal + Windows-874
        $content = file_get_contents($tmpName);
        $content = convertToUtf8($content);
        file_put_contents($tmpName, $content);
        
        $handle = fopen($tmpName, 'r');
        
        if ($handle !== false) {
            $header = fgetcsv($handle);
            
            if ($header === false) {
                $message = "ไฟล์ว่างเปล่าหรืออ่านไม่ได้";
                $messageType = 'error';
            } else {
                $columnMap = createColumnMap($header);
                $detectedColumns = array_keys($columnMap);
                $requiredCols = array_keys($columnMapping);
                $missingCols = checkRequiredColumns($columnMap, $requiredCols);
                if (!empty($missingCols)) {
                    $message = "ไม่พบ Column ที่จำเป็นในไฟล์ CSV: " . implode(', ', $missingCols)
                             . "<br><small>Columns ที่พบ: " . htmlspecialchars(implode(', ', $detectedColumns)) . "</small>";
                    $messageType = 'error';
                }
                
                if (empty($message)) {
                    $sql = "INSERT INTO pos_sale_detail 
                            (order_id, order_date, customer_name, net_amount, 
                             payment_method, branch, product_code, product_name, quantity, import_batch)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
                    
                    $beginTransaction = sqlsrv_begin_transaction($conn);
                    if ($beginTransaction === false) {
                        $message = "❌ ไม่สามารถเริ่ม Transaction ได้: " . (sqlsrv_errors()[0]['message'] ?? 'Unknown');
                        $messageType = 'error';
                    } else {
                        try {
                            $lineNumber = 1;
                            $batchSize = 100; // ประมวลผลทีละ 100 รายการ
                            
                            while (($data = fgetcsv($handle)) !== false) {
                                $lineNumber++;
                                $stats['total']++;
                                
                                // [FIX] ใช้ $columnMap แทน hardcoded index
                                // อ่านค่าจาก column ที่ map แล้ว ไม่ใช้ $data[0], $data[1]...
                                $rawOrderId = $data[$columnMap['เลขที่เอกสาร']] ?? '';
                                $orderId = trim($rawOrderId);
                                
                                if (empty($orderId)) {
                                    $stats['error']++;
                                    $errors[] = "บรรทัด {$lineNumber}: order_id ว่างเปล่า";
                                    continue;
                                }
                                
                                $dupCheck = checkDuplicate($conn, 'pos_sale_detail', 'order_id', $orderId);
                                if (isset($dupCheck['error'])) {
                                    $stats['error']++;
                                    $errors[] = "บรรทัด {$lineNumber} (check dup): " . $dupCheck['error'];
                                    continue;
                                }
                                if ($dupCheck['exists']) {
                                    $stats['skipped']++;
                                    continue;
                                }
                                
                                $orderDate = convertDateTime($data[$columnMap['วันที่เอกสาร']] ?? '');
                                $customerName = cleanString($data[$columnMap['ลูกค้า']] ?? '');
                                $netAmount = cleanNumber($data[$columnMap['มูลค่าสุทธิ']] ?? 0);
                                $paymentMethod = cleanString($data[$columnMap['ช่องทางการขาย']] ?? '');
                                $branch = cleanString($data[$columnMap['สาขา']] ?? '');
                                $productCode = cleanString($data[$columnMap['รหัสสินค้า']] ?? '');
                                $productName = cleanString($data[$columnMap['รายชื่อสินค้า']] ?? '');
                                $quantity = cleanNumber($data[$columnMap['จำนวน']] ?? 0);
                                
                                $params = [
                                    $orderId, $orderDate, $customerName, $netAmount,
                                    $paymentMethod, $branch, $productCode, $productName, $quantity, $batchId
                                ];
                                
                                $stmt = sqlsrv_query($conn, $sql, $params);
                                if ($stmt === false) {
                                    $stats['error']++;
                                    $errs = sqlsrv_errors();
                                    $errors[] = "บรรทัด {$lineNumber}: " . ($errs[0]['message'] ?? 'Unknown error');
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
                            
                            // [FIX] Commit เสมอ ไม่ rollback ทั้งหมด
                            sqlsrv_commit($conn);
                            if ($stats['error'] === 0) {
                                $message = "✅ อัปโหลดสำเร็จ! Batch: {$batchId} (รวม {$stats['success']} รายการ"
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
                            $message = "❌ Exception: " . $e->getMessage();
                            $messageType = 'error';
                        }
                    }
                }
            }
            
            fclose($handle);
        }
    } else {
        // [FIX] เพิ่มการแจ้ง upload error
        $uploadErrors = [
            UPLOAD_ERR_INI_SIZE   => 'ไฟล์ใหญ่เกินกำหนด (upload_max_filesize ใน php.ini)',
            UPLOAD_ERR_FORM_SIZE  => 'ไฟล์ใหญ่เกินกำหนด (MAX_FILE_SIZE ใน form)',
            UPLOAD_ERR_PARTIAL    => 'ไฟล์อัปโหลดไม่สมบูรณ์',
            UPLOAD_ERR_NO_FILE    => 'ไม่ได้เลือกไฟล์',
            UPLOAD_ERR_NO_TMP_DIR => 'ไม่พบโฟลเดอร์ชั่วคราว',
            UPLOAD_ERR_CANT_WRITE => 'ไม่สามารถเขียนไฟล์ลงดิสก์',
            UPLOAD_ERR_EXTENSION  => 'ส่วนขยาย PHP บล็อกการอัปโหลด',
        ];
        $errCode = $file['error'];
        $errMsg = $uploadErrors[$errCode] ?? "Error Code: {$errCode}";
        $message = "❌ ไฟล์อัปโหลดไม่สมบูรณ์: {$errMsg}";
        $messageType = 'error';
    }
}
?>
<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <title>Import POS Sale Detail</title>
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
        .nav-links { display: flex; gap: 10px; margin-bottom: 20px; }
        .nav-links a { padding: 8px 16px; background: #f5f5f5; color: #333; text-decoration: none; border-radius: 4px; font-size: 13px; }
        .nav-links a:hover { background: #e5e5e5; }
        .nav-links a.active { background: #333; color: white; }
        .info-box { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .columns-list { background: #f9f9f9; padding: 15px; border-radius: 4px; font-family: monospace; font-size: 13px; }
        .stats { display: flex; gap: 15px; margin-bottom: 20px; }
        .stat-box { flex: 1; padding: 20px; background: #f9f9f9; border-radius: 4px; text-align: center; }
        .stat-box h3 { margin: 0; font-size: 28px; color: #333; }
        .stat-box p { margin: 5px 0 0; color: #666; font-size: 13px; }
        .card { background: #f9f9f9; padding: 20px; border-radius: 4px; margin-bottom: 20px; }
        .card h2 { margin-top: 0; font-size: 18px; color: #333; }
        .columns-list div { margin: 5px 0; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="import.jst.php">JST Sale</a>
            <a href="import_ada.php" class="active">POS Sale</a>
        </div>
        <h1>Import POS Sale Detail</h1>
        
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

        <?php if (!empty($detectedColumns)): ?>
        <div class="info-box">
            <strong>Columns ที่ตรวจพบใน CSV:</strong>
            <div class="columns-list">
                <?php echo htmlspecialchars(implode(', ', $detectedColumns)); ?>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="form-group">
            <form method="POST" enctype="multipart/form-data">
                <label>เลือกไฟล์ Pos-sale-detail.csv:</label>
                <input type="file" name="csv_file" accept=".csv" required>
                <button type="submit">อัปโหลดข้อมูล</button>
            </form>
        </div>
        
        <?php if (!empty($errors)): ?>
        <div class="card">
            <h2>ข้อผิดพลาด (สูงสุด 10 รายการ)</h2>
            <div class="columns-list">
                <?php foreach (array_slice($errors, 0, 10) as $err): ?>
                    <div><?php echo htmlspecialchars($err); ?></div>
                <?php endforeach; ?>
                <?php if (count($errors) > 10): ?>
                    <div>... และอีก <?php echo count($errors) - 10; ?> รายการ</div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
</body>
</html>