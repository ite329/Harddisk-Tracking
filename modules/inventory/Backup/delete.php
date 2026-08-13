<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';


require_login();
require_permission('inventory.delete');

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

function redirectInventory(string $query = ''): void
{
    $url = 'index.php';

    if ($query !== '') {
        $url .= '?' . $query;
    }

    header('Location: ' . $url);
    exit;
}

function clearInventoryReference(PDO $pdo, string $tableName, int $inventoryId): void
{
    if (!tableExists($pdo, $tableName)) {
        return;
    }

    $columns = getTableColumns($pdo, $tableName);

    if (!hasColumn($columns, 'hdd_inventory_id')) {
        return;
    }

    $setParts = [
        'hdd_inventory_id = NULL'
    ];

    if (hasColumn($columns, 'updated_at')) {
        $setParts[] = 'updated_at = NOW()';
    }

    if (hasColumn($columns, 'updated_by')) {
        $setParts[] = 'updated_by = :updated_by';
    }

    $sql = "\n        UPDATE {$tableName}\n        SET " . implode(', ', $setParts) . "\n        WHERE hdd_inventory_id = :hdd_inventory_id\n    ";

    $stmt = $pdo->prepare($sql);
    $stmt->bindValue(':hdd_inventory_id', $inventoryId, PDO::PARAM_INT);

    if (hasColumn($columns, 'updated_by')) {
        $stmt->bindValue(':updated_by', currentEmployeeCodeInventory());
    }

    $stmt->execute();
}

if (!canManageHddInventory()) {
    redirectInventory('error=permission_denied');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    redirectInventory();
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    exit('ไม่พบการเชื่อมต่อฐานข้อมูล');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_POST['id'] ?? 0);

if ($id <= 0) {
    redirectInventory('error=invalid_id');
}

try {
    $columns = getTableColumns($pdo, 'harddisk_inventory');

    if (empty($columns)) {
        throw new Exception('ไม่พบตาราง harddisk_inventory');
    }

    $pdo->beginTransaction();

    $stmtCheck = $pdo->prepare("\n        SELECT id, hdd_serial, status\n        FROM harddisk_inventory\n        WHERE id = :id\n        LIMIT 1\n        FOR UPDATE\n    ");
    $stmtCheck->execute([':id' => $id]);

    $inventory = $stmtCheck->fetch(PDO::FETCH_ASSOC);

    if (!$inventory) {
        $pdo->rollBack();
        redirectInventory('error=not_found');
    }

    /*
     * เคลียร์เฉพาะ FK hdd_inventory_id ในตารางอื่นก่อน เพื่อให้ DELETE จริงไม่ติด Foreign Key
     * ยังเก็บประวัติ request/shipment ไว้ตามเดิม โดยค่า hdd_serial ที่เป็นข้อความยังไม่ถูกลบ
     */
    clearInventoryReference($pdo, 'harddisk_delivery_requests', $id);
    clearInventoryReference($pdo, 'harddisk_shipments', $id);
    clearInventoryReference($pdo, 'hdd_claim_returns', $id);
    clearInventoryReference($pdo, 'harddisk_claim_returns', $id);

    /*
     * ลบจริงออกจากฐานข้อมูล
     * ไม่ใช้ deleted_at / ไม่ทำ Soft Delete
     */
    $stmtDelete = $pdo->prepare("\n        DELETE FROM harddisk_inventory\n        WHERE id = :id\n        LIMIT 1\n    ");
    $stmtDelete->execute([':id' => $id]);

    if ($stmtDelete->rowCount() <= 0) {
        $pdo->rollBack();
        redirectInventory('error=delete_failed');
    }

    $pdo->commit();
    redirectInventory('deleted=1');
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    redirectInventory('error=delete_failed');
}
