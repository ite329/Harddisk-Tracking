<?php

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/print_history_common.php';

require_login();
require_permission('branch_label.view');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode((string)$raw, true);
if (!is_array($data)) {
    $data = $_POST;
}

$csrfToken = (string)($data['csrf_token'] ?? '');
if (empty($_SESSION['csrf_branch_label_print']) || !hash_equals((string)$_SESSION['csrf_branch_label_print'], $csrfToken)) {
    http_response_code(419);
    echo json_encode(['success' => false, 'message' => 'Session หมดอายุ กรุณาเปิดหน้าพิมพ์ใหม่'], JSON_UNESCAPED_UNICODE);
    exit;
}

$clean = static function ($value, int $maxLength = 255): string {
    $value = trim((string)($value ?? ''));
    return mb_substr($value, 0, $maxLength, 'UTF-8');
};

$mainBranchCode = $clean($data['main_branch_code'] ?? '', 30);
$branchCode = $clean($data['branch_code'] ?? '', 30);
$branchName = $clean($data['branch_name'] ?? '', 255);
$shippingAddress = $clean($data['shipping_address'] ?? '', 4000);
$assetName = $clean($data['asset_name'] ?? '', 150);
$printOrientation = $clean($data['print_orientation'] ?? 'portrait', 20);
$printSource = $clean($data['print_source'] ?? 'direct_branch', 30);

if (!in_array($printOrientation, ['portrait', 'landscape'], true)) {
    $printOrientation = 'portrait';
}
if (!in_array($printSource, ['direct_branch', 'main_branch_group'], true)) {
    $printSource = 'direct_branch';
}

if ($mainBranchCode === '' || $branchName === '') {
    http_response_code(422);
    echo json_encode(['success' => false, 'message' => 'ข้อมูลสาขาสำหรับบันทึกประวัติไม่ครบถ้วน'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    branchLabelEnsurePrintHistoryTable($pdo);
    [$printedByName, $printedByEmployeeCode] = branchLabelCurrentPrintUser();

    $stmt = $pdo->prepare("INSERT INTO `harddisk_db`.`branch_label_print_history`
        (`main_branch_code`, `branch_code`, `branch_name`, `shipping_address`, `asset_name`,
         `print_orientation`, `print_source`, `printed_by_employee_code`, `printed_by_name`,
         `printed_ip`, `user_agent`, `printed_at`)
        VALUES
        (:main_branch_code, :branch_code, :branch_name, :shipping_address, :asset_name,
         :print_orientation, :print_source, :printed_by_employee_code, :printed_by_name,
         :printed_ip, :user_agent, NOW())");

    $stmt->execute([
        ':main_branch_code' => $mainBranchCode,
        ':branch_code' => $branchCode !== '' ? $branchCode : null,
        ':branch_name' => $branchName,
        ':shipping_address' => $shippingAddress !== '' ? $shippingAddress : null,
        ':asset_name' => $assetName !== '' ? $assetName : null,
        ':print_orientation' => $printOrientation,
        ':print_source' => $printSource,
        ':printed_by_employee_code' => $printedByEmployeeCode !== '' ? $printedByEmployeeCode : null,
        ':printed_by_name' => $printedByName,
        ':printed_ip' => branchLabelClientIp() ?: null,
        ':user_agent' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 500, 'UTF-8') ?: null,
    ]);

    echo json_encode(['success' => true, 'history_id' => (int)$pdo->lastInsertId()], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('[branch_labels/log_print] ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'ไม่สามารถบันทึกประวัติการพิมพ์ได้'], JSON_UNESCAPED_UNICODE);
}
