<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

if (function_exists('require_login')) {
    require_login();
}

function cleanText($value): string
{
    return trim((string)($value ?? ''));
}

function currentEmployeeCodeInventory(): string
{
    if (!empty($_SESSION['employee_code'])) {
        return cleanText($_SESSION['employee_code']);
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['employee_code'])) {
        return cleanText($_SESSION['user']['employee_code']);
    }

    return '';
}

function currentUserRoleInventory(): string
{
    if (!empty($_SESSION['role'])) {
        return strtolower(cleanText($_SESSION['role']));
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['role'])) {
        return strtolower(cleanText($_SESSION['user']['role']));
    }

    return '';
}

function canManageHddInventory(): bool
{
    $employeeCode = currentEmployeeCodeInventory();
    $role = currentUserRoleInventory();

    if ($employeeCode === '14329') {
        return true;
    }

    return in_array($role, ['admin', 'administrator', 'super_admin'], true);
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

function redirectEdit(int $id, string $query): void
{
    header('Location: edit.php?id=' . $id . '&' . $query);
    exit;
}

if (!canManageHddInventory()) {
    header('Location: index.php?error=permission_denied');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    exit('ไม่พบการเชื่อมต่อฐานข้อมูล');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_POST['id'] ?? 0);
$hddSerial = strtoupper(cleanText($_POST['hdd_serial'] ?? ''));
$status = cleanText($_POST['status'] ?? 'available');
$receivedFrom = cleanText($_POST['received_from'] ?? 'IT Stock');
$remark = cleanText($_POST['remark'] ?? '');

if ($id <= 0) {
    header('Location: index.php?error=invalid_id');
    exit;
}

if ($hddSerial === '' || preg_match('/^[A-Za-z0-9]+$/', $hddSerial) !== 1) {
    redirectEdit($id, 'error=invalid_serial');
}

$statusOptions = ['available', 'reserved', 'shipped', 'used', 'damaged', 'cancelled'];
if (!in_array($status, $statusOptions, true)) {
    $status = 'available';
}

$receivedFromOptions = ['IT Stock', 'เคลม'];
if (!in_array($receivedFrom, $receivedFromOptions, true)) {
    $receivedFrom = 'IT Stock';
}

try {
    $columns = getTableColumns($pdo, 'harddisk_inventory');

    if (empty($columns)) {
        throw new Exception('ไม่พบตาราง harddisk_inventory');
    }

    $whereCurrent = ['id = :id'];
    if (hasColumn($columns, 'deleted_at')) {
        $whereCurrent[] = 'deleted_at IS NULL';
    }

    $stmtCurrent = $pdo->prepare('SELECT id FROM harddisk_inventory WHERE ' . implode(' AND ', $whereCurrent) . ' LIMIT 1');
    $stmtCurrent->execute([':id' => $id]);

    if (!$stmtCurrent->fetchColumn()) {
        header('Location: index.php?error=not_found');
        exit;
    }

    if (hasColumn($columns, 'hdd_serial')) {
        $duplicateWhere = ['BINARY hdd_serial = :hdd_serial', 'id <> :id'];
        if (hasColumn($columns, 'deleted_at')) {
            $duplicateWhere[] = 'deleted_at IS NULL';
        }

        $stmtDuplicate = $pdo->prepare('SELECT id FROM harddisk_inventory WHERE ' . implode(' AND ', $duplicateWhere) . ' LIMIT 1');
        $stmtDuplicate->execute([
            ':hdd_serial' => $hddSerial,
            ':id' => $id,
        ]);

        if ($stmtDuplicate->fetchColumn()) {
            redirectEdit($id, 'error=duplicate_serial');
        }
    }

    $updates = [];
    $params = [':id' => $id];

    if (hasColumn($columns, 'hdd_serial')) {
        $updates[] = 'hdd_serial = :hdd_serial';
        $params[':hdd_serial'] = $hddSerial;
    }

    if (hasColumn($columns, 'status')) {
        $updates[] = 'status = :status';
        $params[':status'] = $status;
    }

    if (hasColumn($columns, 'received_from')) {
        $updates[] = 'received_from = :received_from';
        $params[':received_from'] = $receivedFrom;
    }

    if (hasColumn($columns, 'remark')) {
        $updates[] = 'remark = :remark';
        $params[':remark'] = $remark !== '' ? $remark : null;
    }

    if (hasColumn($columns, 'updated_by')) {
        $updates[] = 'updated_by = :updated_by';
        $params[':updated_by'] = currentEmployeeCodeInventory();
    }

    if (hasColumn($columns, 'updated_at')) {
        $updates[] = 'updated_at = NOW()';
    }

    if (empty($updates)) {
        header('Location: index.php?updated=1');
        exit;
    }

    $stmtUpdate = $pdo->prepare('UPDATE harddisk_inventory SET ' . implode(', ', $updates) . ' WHERE id = :id LIMIT 1');
    $stmtUpdate->execute($params);

    header('Location: index.php?updated=1');
    exit;
} catch (Throwable $e) {
    redirectEdit($id, 'error=update_failed');
}
