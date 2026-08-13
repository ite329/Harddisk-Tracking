<?php
if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
if (function_exists('require_login')) require_login();
header('Content-Type: application/json; charset=UTF-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}
$csrf = (string)($_POST['csrf_token'] ?? '');
if (empty($_SESSION['csrf_drum']) || !hash_equals((string)$_SESSION['csrf_drum'], $csrf)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Session หมดอายุ กรุณาเปิดใบปะหน้าใหม่'], JSON_UNESCAPED_UNICODE);
    exit;
}
$requestNo = trim((string)($_POST['request_no'] ?? ''));
if ($requestNo === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'ไม่พบเลขที่รายการ'], JSON_UNESCAPED_UNICODE);
    exit;
}
try {
    $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
    $columnsStmt->execute();
    $columns = array_map('strtolower', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));
    if (!in_array('delivery_status', $columns, true) || !in_array('shipped_at', $columns, true)) {
        throw new RuntimeException('กรุณารันไฟล์ database/add_drum_delivery_status.sql ก่อนใช้งาน');
    }
    $hasDeletedAt = in_array('deleted_at', $columns, true);
    $sql = "UPDATE harddisk_db.drum_withdrawals
            SET delivery_status = 'shipped', shipped_at = COALESCE(shipped_at, NOW())
            WHERE request_no = :request_no
              AND COALESCE(delivery_status, 'pending') = 'pending'";
    if ($hasDeletedAt) $sql .= ' AND deleted_at IS NULL';
    $stmt = $pdo->prepare($sql);
    $stmt->execute([':request_no' => $requestNo]);
    if ($stmt->rowCount() <= 0) {
        http_response_code(409);
        throw new RuntimeException('รายการนี้พิมพ์ใบปะหน้าและยืนยันจัดส่งแล้ว ไม่สามารถยืนยันซ้ำได้');
    }

    echo json_encode(['success' => true, 'message' => 'เปลี่ยนสถานะเป็นจัดส่งแล้ว'], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[drum_withdrawals/mark_shipped] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => $e instanceof RuntimeException ? $e->getMessage() : 'ไม่สามารถยืนยันการจัดส่งได้'], JSON_UNESCAPED_UNICODE);
}
