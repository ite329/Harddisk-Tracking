<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

if (function_exists('require_login')) {
    require_login();
}

$isSuperAdmin = false;
if (function_exists('is_super_admin_employee') && is_super_admin_employee()) {
    $isSuperAdmin = true;
} elseif (function_exists('current_user_role') && current_user_role() === 'super_admin') {
    $isSuperAdmin = true;
}

if (!$isSuperAdmin) {
    http_response_code(403);
    $_SESSION['drum_restore_error'] = 'ไม่มีสิทธิ์ย้อนคืนสถานะ รายการนี้อนุญาตเฉพาะ Super Admin เท่านั้น';
    header('Location: history.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $_SESSION['drum_restore_error'] = 'รูปแบบคำขอไม่ถูกต้อง';
    header('Location: history.php');
    exit;
}

$csrfToken = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_drum_restore']) || !hash_equals((string)$_SESSION['csrf_drum_restore'], $csrfToken)) {
    http_response_code(419);
    $_SESSION['drum_restore_error'] = 'Session หมดอายุ กรุณาลองใหม่อีกครั้ง';
    header('Location: history.php');
    exit;
}

$requestNo = trim((string)($_POST['request_no'] ?? ''));
if ($requestNo === '') {
    $_SESSION['drum_restore_error'] = 'ไม่พบเลขที่รายการที่ต้องการย้อนคืนสถานะ';
    header('Location: history.php');
    exit;
}

try {
    $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
    $columnsStmt->execute();
    $columns = array_map('strtolower', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));

    if (!in_array('delivery_status', $columns, true) || !in_array('shipped_at', $columns, true)) {
        throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์สถานะที่จำเป็น');
    }

    $hasDeletedAt = in_array('deleted_at', $columns, true);
    $sql = "UPDATE harddisk_db.drum_withdrawals
            SET delivery_status = 'pending', shipped_at = NULL
            WHERE request_no = :request_no
              AND delivery_status = 'shipped'";
    if ($hasDeletedAt) {
        $sql .= ' AND deleted_at IS NULL';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':request_no' => $requestNo]);

    if ($stmt->rowCount() <= 0) {
        throw new RuntimeException('ไม่พบรายการสถานะจัดส่งแล้วที่สามารถย้อนคืนได้');
    }

    $_SESSION['drum_restore_success'] = 'ย้อนคืนสถานะรายการ ' . $requestNo . ' เป็น “รอยืนยันจัดส่ง” เรียบร้อยแล้ว';
} catch (Throwable $e) {
    error_log('[drum_withdrawals/restore_status] ' . $e->getMessage());
    $_SESSION['drum_restore_error'] = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'ไม่สามารถย้อนคืนสถานะรายการได้';
}

header('Location: history.php');
exit;
