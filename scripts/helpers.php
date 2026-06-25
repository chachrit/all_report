<?php
// helpers.php - Shared helper functions for CSV import

// เพิ่ม timeout และ memory limit สำหรับไฟล์ใหญ่
function initPerformanceSettings() {
    ini_set('max_execution_time', 300);
    ini_set('memory_limit', '512M');
    ini_set('upload_max_filesize', '50M');
    ini_set('post_max_size', '50M');
}

// แปลง Encoding และลบ BOM
function convertToUtf8($content) {
    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $content = substr($content, 3);
    }
    
    $encodings = ['UTF-8', 'ISO-8859-1', 'ASCII'];
    $encoding = mb_detect_encoding($content, $encodings, true);
    
    if ($encoding && $encoding !== 'UTF-8') {
        return mb_convert_encoding($content, 'UTF-8', $encoding);
    }
    return $content;
}

// แปลงวันที่
function convertDate($dateStr) {
    if (empty($dateStr)) return null;
    $dateStr = trim($dateStr);
    $date = DateTime::createFromFormat('m/d/Y', $dateStr);
    if ($date) return $date->format('Y-m-d');
    return date('Y-m-d', strtotime($dateStr));
}

// แปลง datetime
function convertDateTime($dateTimeStr) {
    if (empty($dateTimeStr)) return null;
    $dateTimeStr = trim($dateTimeStr);
    $formats = ['m/d/y g:i A', 'n/j/y g:i A', 'm/d/Y g:i A'];
    
    foreach ($formats as $format) {
        $date = DateTime::createFromFormat($format, $dateTimeStr);
        if ($date !== false) {
            return $date->format('Y-m-d H:i:s');
        }
    }
    return date('Y-m-d H:i:s', strtotime($dateTimeStr));
}

// แปลงตัวเลข
function cleanNumber($val) {
    if (empty($val) || strtoupper(trim($val)) === 'NULL') return 0;
    $val = str_replace(',', '', trim($val));
    return is_numeric($val) ? floatval($val) : 0;
}

// แปลง string
function cleanString($val) {
    if (empty($val) || strtoupper(trim($val)) === 'NULL') return null;
    return trim($val);
}

// แปลง null
function convertNull($val) {
    if (empty($val) || strtoupper(trim($val)) === 'NULL') return null;
    return trim($val);
}

// สร้าง column map จาก header
function createColumnMap($header) {
    $columnMap = [];
    foreach ($header as $index => $colName) {
        $columnMap[trim($colName)] = $index;
    }
    return $columnMap;
}

// ตรวจสอบ column ที่จำเป็น
function checkRequiredColumns($columnMap, $requiredCols) {
    $missingCols = [];
    foreach ($requiredCols as $col) {
        if (!isset($columnMap[$col])) {
            $missingCols[] = $col;
        }
    }
    return $missingCols;
}

// ตรวจสอบ duplicate
function checkDuplicate($conn, $table, $idField, $idValue) {
    $checkSql = "SELECT COUNT(*) as cnt FROM $table WHERE $idField = ?";
    $checkStmt = sqlsrv_query($conn, $checkSql, [$idValue]);
    if ($checkStmt === false) {
        return ['error' => sqlsrv_errors()[0]['message'] ?? 'Unknown Error'];
    }
    $checkRow = sqlsrv_fetch_array($checkStmt, SQLSRV_FETCH_ASSOC);
    sqlsrv_free_stmt($checkStmt);
    return ['exists' => $checkRow && $checkRow['cnt'] > 0];
}

// Bulk insert for better performance
function bulkInsert($conn, $sql, $paramsArray) {
    foreach ($paramsArray as $params) {
        $stmt = sqlsrv_query($conn, $sql, $params);
        if ($stmt === false) {
            return false;
        }
        sqlsrv_free_stmt($stmt);
    }
    return true;
}
