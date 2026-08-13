<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../../config/database.php';

$code = trim((string)($_GET['main_branch_code'] ?? $_GET['branch_code'] ?? ''));
if ($code === '' || !preg_match('/^[0-9A-Za-z_-]{1,30}$/', $code)) {
    echo json_encode(['success' => false, 'message' => 'กรุณาระบุรหัสสาขา'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $stmt = $pdo->prepare("SELECT main_branch_code, branch_name, branch_name_2 FROM harddisk_db.branch_directory WHERE main_branch_code = :code LIMIT 1");
    $stmt->execute([':code' => $code]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        echo json_encode(['success' => false, 'message' => 'ไม่พบข้อมูลสาขา'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $name = trim((string)($row['branch_name'] ?? ''));
    if ($name === '') $name = trim((string)($row['branch_name_2'] ?? ''));
    echo json_encode(['success' => true, 'data' => ['main_branch_code' => $row['main_branch_code'], 'branch_name' => $name]], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถค้นหาข้อมูลสาขาได้'], JSON_UNESCAPED_UNICODE);
}
