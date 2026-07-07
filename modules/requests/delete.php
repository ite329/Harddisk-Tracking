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

if (!function_exists('can_delete_hdd_request') || !can_delete_hdd_request()) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์ลบข้อมูล');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([
        ':table_name' => $tableName
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    if (!tableExists($pdo, $tableName)) {
        return [];
    }

    $stmt = $pdo->prepare("\n        SELECT COLUMN_NAME\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([
        ':table_name' => $tableName
    ]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    header('Location: index.php?delete_error=invalid_id');
    exit;
}

try {
    $requestColumns = getTableColumns($pdo, 'harddisk_delivery_requests');

    if (empty($requestColumns)) {
        throw new Exception('ไม่พบตาราง harddisk_delivery_requests');
    }

    $pdo->beginTransaction();

    $selectColumns = ['id'];
    foreach (['hdd_inventory_id', 'hdd_serial', 'status'] as $column) {
        if (hasColumn($requestColumns, $column)) {
            $selectColumns[] = $column;
        }
    }

    $stmtRequest = $pdo->prepare("\n        SELECT " . implode(', ', $selectColumns) . "\n        FROM harddisk_delivery_requests\n        WHERE id = :id\n        LIMIT 1\n    ");
    $stmtRequest->execute([
        ':id' => $id
    ]);
    $request = $stmtRequest->fetch(PDO::FETCH_ASSOC);

    if (!$request) {
        throw new Exception('ไม่พบรายการที่ต้องการลบ');
    }

    /*
     * ถ้ารายการยังอยู่ขั้นตอนก่อนจัดส่ง และมีการจอง HDD ไว้
     * ให้คืนสถานะ HDD กลับเป็น available เพื่อไม่ให้ Stock ค้าง reserved
     */
    if (tableExists($pdo, 'harddisk_inventory')) {
        $inventoryColumns = getTableColumns($pdo, 'harddisk_inventory');
        $canReleaseInventory = hasColumn($inventoryColumns, 'status');
        $requestStatus = strtolower(trim((string)($request['status'] ?? '')));
        $isBeforeShipping = in_array($requestStatus, ['', 'pending_scan', 'pending', 'matched', 'reserved'], true);

        if ($canReleaseInventory && $isBeforeShipping) {
            if (!empty($request['hdd_inventory_id']) && hasColumn($inventoryColumns, 'id')) {
                $updateFields = ["status = 'available'"];

                if (hasColumn($inventoryColumns, 'updated_at')) {
                    $updateFields[] = 'updated_at = NOW()';
                }

                $stmtRelease = $pdo->prepare("\n                    UPDATE harddisk_inventory\n                    SET " . implode(', ', $updateFields) . "\n                    WHERE id = :inventory_id\n                    LIMIT 1\n                ");
                $stmtRelease->execute([
                    ':inventory_id' => (int)$request['hdd_inventory_id']
                ]);
            } elseif (!empty($request['hdd_serial']) && hasColumn($inventoryColumns, 'hdd_serial')) {
                $updateFields = ["status = 'available'"];

                if (hasColumn($inventoryColumns, 'updated_at')) {
                    $updateFields[] = 'updated_at = NOW()';
                }

                $stmtRelease = $pdo->prepare("\n                    UPDATE harddisk_inventory\n                    SET " . implode(', ', $updateFields) . "\n                    WHERE hdd_serial = :hdd_serial\n                    LIMIT 1\n                ");
                $stmtRelease->execute([
                    ':hdd_serial' => $request['hdd_serial']
                ]);
            }
        }
    }

    /*
     * ลบ/ซ่อนประวัติการจัดส่งที่ผูกกับคำขอ ถ้าตารางมีอยู่
     */
    if (tableExists($pdo, 'harddisk_shipments')) {
        $shipmentColumns = getTableColumns($pdo, 'harddisk_shipments');
        $shipmentWhere = [];
        $shipmentParams = [];

        if (hasColumn($shipmentColumns, 'request_id')) {
            $shipmentWhere[] = 'request_id = :request_id';
            $shipmentParams[':request_id'] = $id;
        }

        if (hasColumn($shipmentColumns, 'delivery_request_id')) {
            $shipmentWhere[] = 'delivery_request_id = :delivery_request_id';
            $shipmentParams[':delivery_request_id'] = $id;
        }

        if (!empty($shipmentWhere)) {
            if (hasColumn($shipmentColumns, 'deleted_at')) {
                $stmtShipment = $pdo->prepare("\n                    UPDATE harddisk_shipments\n                    SET deleted_at = NOW()\n                    WHERE " . implode(' OR ', $shipmentWhere) . "\n                ");
            } else {
                $stmtShipment = $pdo->prepare("\n                    DELETE FROM harddisk_shipments\n                    WHERE " . implode(' OR ', $shipmentWhere) . "\n                ");
            }
            $stmtShipment->execute($shipmentParams);
        }
    }

    if (tableExists($pdo, 'harddisk_request_items')) {
        $stmtItems = $pdo->prepare("\n            DELETE FROM harddisk_request_items\n            WHERE request_id = :request_id\n        ");
        $stmtItems->execute([
            ':request_id' => $id
        ]);
    }

    if (hasColumn($requestColumns, 'deleted_at')) {
        $updateFields = ['deleted_at = NOW()'];

        if (hasColumn($requestColumns, 'updated_at')) {
            $updateFields[] = 'updated_at = NOW()';
        }

        $stmtDelete = $pdo->prepare("\n            UPDATE harddisk_delivery_requests\n            SET " . implode(', ', $updateFields) . "\n            WHERE id = :id\n            LIMIT 1\n        ");
    } else {
        $stmtDelete = $pdo->prepare("\n            DELETE FROM harddisk_delivery_requests\n            WHERE id = :id\n            LIMIT 1\n        ");
    }

    $stmtDelete->execute([
        ':id' => $id
    ]);

    $pdo->commit();

    header('Location: index.php?delete_success=1');
    exit;
} catch (Throwable $e) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Location: index.php?delete_error=1');
    exit;
}
