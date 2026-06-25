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

$message = '';
$messageType = '';

// ประมวลผลเมื่อมีการอัปโหลดไฟล์
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];
    $fileType = $_POST['file_type'] ?? 'jst';
    
    if ($file['error'] === UPLOAD_ERR_OK) {
        $tmpName = $file['tmp_name'];
        $handle = fopen($tmpName, 'r');
        
        if ($handle !== false) {
            // ข้าม header row
            $header = fgetcsv($handle);
            
            $successCount = 0;
            $errorCount = 0;
            $errors = [];
            
            // เริ่ม transaction
            sqlsrv_begin_transaction($conn);
            
            try {
                if ($fileType === 'jst') {
                    // อัปโหลด JST_sale_detail
                    $sql = "INSERT INTO jst_sale_detail 
                            (orderId, orderNumber, platformOrderId, sale_date, order_datetime, 
                             status, statusDisplay, platform, subtotal, net_sales, 
                             shop_discount, total_discount, freight, payment_channel, buyer_name, created_at)
                            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, GETDATE())";
                    
                    while (($data = fgetcsv($handle)) !== false) {
                        if (count($data) >= 15) {
                            $params = array(
                                $data[0],
                                cleanString($data[1]),
                                cleanString($data[2]),
                                convertDate($data[3]),
                                convertDateTime($data[4]),
                                cleanString($data[5]),
                                cleanString($data[6] ?? null),
                                cleanString($data[7]),
                                cleanNumber($data[8]),
                                cleanNumber($data[9]),
                                cleanNumber($data[10]),
                                cleanNumber($data[11]),
                                cleanNumber($data[12]),
                                cleanString($data[13]),
                                cleanString($data[14] ?? null),
                                null
                            );
                            
                            $stmt = sqlsrv_query($conn, $sql, $params);
                            if ($stmt === false) {
                                $errorCount++;
                                $errors[] = "Row " . ($successCount + $errorCount + 1) . ": " . 
                                           print_r(sqlsrv_errors(), true);
                            } else {
                                $successCount++;
                            }
                        }
                    }
                } else {
                    // อัปโหลด Pos-sale-detail (ปรับตามโครงสร้างจริง)
                    $sql = "INSERT INTO pos_sale_detail (column1, column2) VALUES (?, ?)";
                    
                    while (($data = fgetcsv($handle)) !== false) {
                        // เพิ่มโค้ดสำหรับ Pos-sale-detail ตามโครงสร้างไฟล์จริง
                        $successCount++;
                    }
                }
                
                // Commit transaction
                if ($errorCount === 0) {
                    sqlsrv_commit($conn);
                    $message = "อัปโหลดสำเร็จ! จำนวน {$successCount} รายการ";
                    $messageType = 'success';
                } else {
                    sqlsrv_rollback($conn);
                    $message = "เกิดข้อผิดพลาด! ยกเลิกการอัปโหลดทั้งหมด";
                    $messageType = 'error';
                }
                
            } catch (Exception $e) {
                sqlsrv_rollback($conn);
                $message = "เกิดข้อผิดพลาด: " . $e->getMessage();
                $messageType = 'error';
            }
            
            fclose($handle);
        }
    } else {
        $message = "เกิดข้อผิดพลาดในการอัปโหลดไฟล์";
        $messageType = 'error';
    }
}

// ดึงข้อมูลล่าสุดเพื่อแสดง
$recentData = array();
$query = "SELECT TOP 10 * FROM jst_sale_detail ORDER BY created_at DESC";
$stmt = sqlsrv_query($conn, $query);
if ($stmt) {
    while ($row = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC)) {
        $recentData[] = $row;
    }
}
?>

<!DOCTYPE html>
<html lang="th">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>อัปโหลด CSV ไปยัง SQL Server</title>
    <style>
        * { box-sizing: border-box; }
        body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; background: #f5f5f5; padding: 20px; margin: 0; }
        .container { max-width: 900px; margin: 0 auto; background: white; padding: 30px; border-radius: 8px; box-shadow: 0 1px 3px rgba(0,0,0,0.1); }
        h1 { color: #333; border-bottom: 1px solid #e5e5e5; padding-bottom: 15px; margin-top: 0; }
        .form-group { margin-bottom: 20px; }
        label { display: block; margin-bottom: 8px; font-weight: 500; color: #333; }
        input[type="file"], select { width: 100%; padding: 10px; border: 1px solid #ddd; border-radius: 4px; font-size: 14px; }
        button { background: #333; color: white; border: none; padding: 12px 25px; border-radius: 4px; cursor: pointer; font-size: 14px; }
        button:hover { background: #555; }
        .alert { padding: 15px; margin-bottom: 20px; border-radius: 4px; }
        .alert-success { background: #e8f5e9; color: #2e7d32; border: 1px solid #c8e6c9; }
        .alert-error { background: #ffebee; color: #c62828; border: 1px solid #ffcdd2; }
        table { width: 100%; border-collapse: collapse; margin-top: 20px; }
        th, td { padding: 12px; border: 1px solid #e5e5e5; text-align: left; }
        th { background: #f9f9f9; font-weight: 500; }
        tr:hover { background: #f9f9f9; }
        .info-box { background: #fff3cd; border: 1px solid #ffc107; padding: 15px; border-radius: 4px; margin-bottom: 20px; }
        .nav-links { display: flex; gap: 10px; margin-bottom: 20px; }
        .nav-links a { padding: 8px 16px; background: #f5f5f5; color: #333; text-decoration: none; border-radius: 4px; font-size: 13px; }
        .nav-links a:hover { background: #e5e5e5; }
    </style>
</head>
<body>
    <div class="container">
        <div class="nav-links">
            <a href="index.php">หน้าหลัก</a>
            <a href="scripts/import.jst.php">JST Sale</a>
            <a href="scripts/import_ada.php">POS Sale</a>
        </div>
        <h1>อัปโหลดไฟล์ CSV ไปยัง SQL Server</h1>
        
        <?php if ($message): ?>
            <div class="alert alert-<?php echo $messageType; ?>">
                <?php echo htmlspecialchars($message); ?>
            </div>
        <?php endif; ?>
        
        <div class="info-box">
            <strong>หมายเหตุ:</strong> ไฟล์ CSV ต้องมีรูปแบบตามตัวอย่างใน JST_sale_detail.csv
        </div>
        
        <form method="POST" enctype="multipart/form-data">
            <div class="form-group">
                <label for="file_type">ประเภทไฟล์:</label>
                <select name="file_type" id="file_type" required>
                    <option value="jst">JST Sale Detail</option>
                    <option value="pos">POS Sale Detail</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="csv_file">เลือกไฟล์ CSV:</label>
                <input type="file" name="csv_file" id="csv_file" accept=".csv" required>
            </div>
            
            <button type="submit">อัปโหลดข้อมูล</button>
        </form>
    </div>
    
    <?php if (!empty($recentData)): ?>
    <div class="container">
        <h2>📋 ข้อมูลล่าสุด (10 รายการ)</h2>
        <table>
            <thead>
                <tr>
                    <th>Order ID</th>
                    <th>Order Number</th>
                    <th>Platform</th>
                    <th>Sale Date</th>
                    <th>Net Sales</th>
                    <th>Payment Channel</th>
                    <th>Buyer Name</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recentData as $row): ?>
                <tr>
                    <td><?php echo htmlspecialchars($row['orderId']); ?></td>
                    <td><?php echo htmlspecialchars($row['orderNumber']); ?></td>
                    <td><?php echo htmlspecialchars($row['platform']); ?></td>
                    <td><?php echo htmlspecialchars($row['sale_date']); ?></td>
                    <td><?php echo number_format($row['net_sales'], 2); ?></td>
                    <td><?php echo htmlspecialchars($row['payment_channel']); ?></td>
                    <td><?php echo htmlspecialchars($row['buyer_name']); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
    
    <?php sqlsrv_close($conn); ?>
</body>
</html>