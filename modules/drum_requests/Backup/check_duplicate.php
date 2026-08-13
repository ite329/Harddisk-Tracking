<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) session_start();
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

require_login();
header('Content-Type: application/json; charset=utf-8');

try {
    $branchCode = trim((string)($_GET['branch_code'] ?? ''));
    $drums = $_GET['drum_codes'] ?? [];
    $drums = array_values(array_unique(array_filter(array_map('trim', is_array($drums) ? $drums : []))));
    $allowedDrums = ['Drum-DR-3455', 'Drum-DR-3608'];
    $drums = array_values(array_intersect($drums, $allowedDrums));

    if ($branchCode === '' || !$drums) {
        echo json_encode(['success' => true, 'data' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
    $columnsStmt->execute();
    $columns = array_map('strtolower', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));
    foreach (['request_no', 'main_branch_code', 'branch_name', 'drum_code', 'delivery_status', 'created_at'] as $requiredColumn) {
        if (!in_array($requiredColumn, $columns, true)) {
            throw new RuntimeException('ตาราง drum_withdrawals ขาดคอลัมน์ ' . $requiredColumn);
        }
    }

    $hasBranchCodeColumn = in_array('branch_code', $columns, true);
    $placeholders = implode(',', array_fill(0, count($drums), '?'));
    $deletedCondition = in_array('deleted_at', $columns, true) ? ' AND dw.deleted_at IS NULL' : '';
    $orderById = in_array('id', $columns, true) ? ', dw.id DESC' : '';

    if ($hasBranchCodeColumn) {
        $branchCondition = 'TRIM(CAST(dw.branch_code AS CHAR)) = ?';
    } else {
        $branchCondition = "EXISTS (
            SELECT 1
            FROM harddisk_db.branch_directory bd
            WHERE TRIM(CAST(bd.branch_code AS CHAR)) = ?
              AND LPAD(TRIM(CAST(bd.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(dw.main_branch_code AS CHAR)), 3, '0')
              AND (TRIM(COALESCE(bd.branch_name, '')) = TRIM(COALESCE(dw.branch_name, ''))
                   OR TRIM(COALESCE(bd.branch_name_2, '')) = TRIM(COALESCE(dw.branch_name, '')))
        )";
    }

    $sql = "SELECT dw.drum_code, dw.request_no, dw.main_branch_code, dw.branch_name, dw.recorded_by, COALESCE(dw.delivery_status, 'pending') AS delivery_status, dw.created_at
        FROM harddisk_db.drum_withdrawals dw
        WHERE {$branchCondition}
          AND COALESCE(dw.delivery_status, 'pending') IN ('pending', 'shipped')
          AND dw.drum_code IN ({$placeholders})
          {$deletedCondition}
        ORDER BY dw.created_at DESC{$orderById}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute(array_merge([$branchCode], $drums));

    $latestByDrum = [];
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $drumCode = trim((string)($row['drum_code'] ?? ''));
        if ($drumCode === '' || isset($latestByDrum[$drumCode])) continue;
        $timestamp = strtotime((string)($row['created_at'] ?? ''));
        $latestByDrum[$drumCode] = [
            'drum_code' => $drumCode,
            'request_no' => (string)($row['request_no'] ?? ''),
            'main_branch_code' => (string)($row['main_branch_code'] ?? ''),
            'branch_name' => (string)($row['branch_name'] ?? ''),
            'recorded_by' => (string)($row['recorded_by'] ?? ''),
            'delivery_status' => (string)($row['delivery_status'] ?? 'pending'),
            'status_label' => (($row['delivery_status'] ?? 'pending') === 'shipped' ? 'จัดส่งแล้ว' : 'รอจัดส่ง'),
            'recorded_date' => $timestamp ? date('d/m/Y', $timestamp) : '-',
            'shipped_date' => $timestamp ? date('d/m/Y', $timestamp) : '-',
        ];
    }

    echo json_encode(['success' => true, 'data' => array_values($latestByDrum)], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
} catch (Throwable $e) {
    error_log('[drum_withdrawals/check_duplicate] ' . $e->getMessage());
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => $e->getMessage(), 'data' => []], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
}
