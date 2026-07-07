<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';

if (function_exists('require_login')) {
    require_login();
}

if (!function_exists('can_edit_hdd_request') || !can_edit_hdd_request()) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์แก้ไขข้อมูล');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([':table_name' => $tableName]);

    return (int)$stmt->fetchColumn() > 0;
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    if (!tableExists($pdo, $tableName)) {
        return [];
    }

    $stmt = $pdo->prepare("\n        SELECT COLUMN_NAME\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([':table_name' => $tableName]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function cleanPostValue(string $key): string
{
    return trim((string)($_POST[$key] ?? ''));
}

function redirectEdit(int $id, string $query): void
{
    header('Location: edit.php?id=' . $id . '&' . $query);
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    header('Location: index.php?edit_error=invalid_id');
    exit;
}

try {
    $columns = getTableColumns($pdo, 'harddisk_delivery_requests');

    if (empty($columns)) {
        throw new Exception('ไม่พบตาราง harddisk_delivery_requests');
    }

    $requestWhere = 'id = :id';
    if (hasColumn($columns, 'deleted_at')) {
        $requestWhere .= ' AND deleted_at IS NULL';
    }

    $stmtOld = $pdo->prepare("\n        SELECT *\n        FROM harddisk_delivery_requests\n        WHERE {$requestWhere}\n        LIMIT 1\n    ");
    $stmtOld->execute([':id' => $id]);
    $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);

    if (!$oldRow) {
        throw new Exception('ไม่พบรายการคำขอส่ง HDD');
    }

    $mainBranchCode = cleanPostValue('main_branch_code');
    $mainBranchCode = preg_replace('/[^0-9]/', '', $mainBranchCode);
    if ($mainBranchCode !== '' && strlen($mainBranchCode) < 3) {
        $mainBranchCode = str_pad($mainBranchCode, 3, '0', STR_PAD_LEFT);
    }

    $branchCode = cleanPostValue('branch_code');
    $branchName = cleanPostValue('branch_name');
    $hddSerial = strtoupper(cleanPostValue('hdd_serial'));
    $hddInventoryId = isset($_POST['hdd_inventory_id']) && $_POST['hdd_inventory_id'] !== '' ? (int)$_POST['hdd_inventory_id'] : null;
    $requestReason = cleanPostValue('request_reason');
    $status = cleanPostValue('status');
    $remark = cleanPostValue('remark');

    if ($branchCode === '') {
        throw new Exception('กรุณาเลือกสาขา');
    }

    if ($branchName === '') {
        throw new Exception('ไม่พบชื่อสาขา');
    }

    if ($hddSerial !== '' && preg_match('/^[A-Z0-9]+$/', $hddSerial) !== 1) {
        throw new Exception('Serial HDD ต้องเป็นตัวอักษรอังกฤษหรือตัวเลขเท่านั้น');
    }

    if ($requestReason === '') {
        throw new Exception('กรุณาเลือกสาเหตุที่ต้องส่ง HDD');
    }

    $inventoryColumns = getTableColumns($pdo, 'harddisk_inventory');

    if ($hddSerial !== '' && !empty($inventoryColumns) && hasColumn($inventoryColumns, 'hdd_serial')) {
        $invWhere = 'BINARY hdd_serial = :hdd_serial';
        if (hasColumn($inventoryColumns, 'deleted_at')) {
            $invWhere .= ' AND deleted_at IS NULL';
        }

        $stmtInv = $pdo->prepare("\n            SELECT *\n            FROM harddisk_inventory\n            WHERE {$invWhere}\n            LIMIT 1\n        ");
        $stmtInv->execute([':hdd_serial' => $hddSerial]);
        $inventory = $stmtInv->fetch(PDO::FETCH_ASSOC);

        if (!$inventory) {
            throw new Exception('ไม่พบ Serial HDD นี้ในคลัง Harddisk');
        }

        if ($hddInventoryId !== null && (int)($inventory['id'] ?? 0) !== $hddInventoryId) {
            throw new Exception('Serial HDD ไม่ตรงกับรายการในคลัง Harddisk');
        }

        $hddInventoryId = (int)($inventory['id'] ?? 0);
    }

    $allowedUpdateColumns = [
        'main_branch_code' => $mainBranchCode,
        'branch_code' => $branchCode,
        'branch_name' => $branchName,
        'hdd_inventory_id' => $hddInventoryId,
        'hdd_serial' => $hddSerial,
        'request_reason' => $requestReason,
        'status' => $status,
        'remark' => $remark,
    ];

    $updateFields = [];
    $params = [':id' => $id];

    foreach ($allowedUpdateColumns as $column => $value) {
        if (!hasColumn($columns, $column)) {
            continue;
        }

        $paramName = ':' . $column;
        $updateFields[] = '`' . $column . '` = ' . $paramName;

        if ($value === '' || $value === null) {
            $params[$paramName] = null;
        } else {
            $params[$paramName] = $value;
        }
    }

    if (hasColumn($columns, 'updated_at')) {
        $updateFields[] = 'updated_at = NOW()';
    }

    if (hasColumn($columns, 'updated_by')) {
        $updatedBy = function_exists('current_employee_code') ? current_employee_code() : '';
        $updateFields[] = 'updated_by = :updated_by';
        $params[':updated_by'] = $updatedBy !== '' ? $updatedBy : null;
    }

    if (empty($updateFields)) {
        throw new Exception('ไม่มีข้อมูลสำหรับแก้ไข');
    }

    $pdo->beginTransaction();

    $stmtUpdate = $pdo->prepare("\n        UPDATE harddisk_delivery_requests\n        SET " . implode(', ', $updateFields) . "\n        WHERE {$requestWhere}\n        LIMIT 1\n    ");
    $stmtUpdate->execute($params);

    $pdo->commit();

    header('Location: index.php?updated=1');
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    redirectEdit($id, 'edit_error=1');
}
