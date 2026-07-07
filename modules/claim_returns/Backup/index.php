<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
if (file_exists(__DIR__ . '/../../includes/functions.php')) {
    require_once __DIR__ . '/../../includes/functions.php';
}

if (function_exists('require_login')) {
    require_login();
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
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

function currentLoginName(): string
{
    $fullName = trim((string)($_SESSION['full_name'] ?? ''));

    if ($fullName === '') {
        $firstName = trim((string)($_SESSION['first_name'] ?? ''));
        $lastName = trim((string)($_SESSION['last_name'] ?? ''));
        $fullName = trim($firstName . ' ' . $lastName);
    }

    $employeeCode = trim((string)($_SESSION['employee_code'] ?? ''));

    if ($fullName !== '' && $employeeCode !== '') {
        return $fullName . ' (' . $employeeCode . ')';
    }

    if ($fullName !== '') {
        return $fullName;
    }

    if ($employeeCode !== '') {
        return $employeeCode;
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $user = $_SESSION['user'];
        $userFullName = trim((string)($user['full_name'] ?? ''));

        if ($userFullName === '') {
            $userFullName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        }

        $userEmployeeCode = trim((string)($user['employee_code'] ?? ''));

        if ($userFullName !== '' && $userEmployeeCode !== '') {
            return $userFullName . ' (' . $userEmployeeCode . ')';
        }

        if ($userFullName !== '') {
            return $userFullName;
        }

        if ($userEmployeeCode !== '') {
            return $userEmployeeCode;
        }
    }

    return 'IT';
}

function cleanText($value): string
{
    return trim((string)($value ?? ''));
}

function formatMainBranchCode($value): string
{
    $value = preg_replace('/[^0-9]/', '', cleanText($value));
    if ($value === '') {
        return '';
    }
    return str_pad(substr($value, 0, 3), 3, '0', STR_PAD_LEFT);
}

function formatThaiDateTime($value): string
{
    $value = cleanText($value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '-';
    }
    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }
    return date('d/m/Y H:i', $timestamp);
}

function claimStatusText($status): string
{
    $status = cleanText($status);
    $map = [
        'received' => 'รับคืนจากสาขาแล้ว',
        'preparing_claim' => 'เตรียมส่งเคลม',
        'sent_claim' => 'ส่งเคลมแล้ว',
        'claim_approved' => 'เคลมผ่าน',
        'claim_rejected' => 'เคลมไม่ผ่าน',
        'returned_stock' => 'กลับเข้าคลัง',
        'scrapped' => 'จำหน่ายทิ้ง',
        'cancelled' => 'ยกเลิก',
    ];
    return $map[$status] ?? ($status !== '' ? $status : '-');
}

function claimStatusBadge($status): string
{
    $status = cleanText($status);
    $class = 'bg-secondary';

    if ($status === 'received') {
        $class = 'bg-info text-dark';
    } elseif ($status === 'preparing_claim') {
        $class = 'bg-warning text-dark';
    } elseif ($status === 'sent_claim') {
        $class = 'bg-primary';
    } elseif ($status === 'claim_approved' || $status === 'returned_stock') {
        $class = 'bg-success';
    } elseif ($status === 'claim_rejected' || $status === 'scrapped') {
        $class = 'bg-danger';
    } elseif ($status === 'cancelled') {
        $class = 'bg-dark';
    }

    return '<span class="badge ' . h($class) . '">' . h(claimStatusText($status)) . '</span>';
}

function jsonResponse(array $payload): void
{
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function generateReturnNo(PDO $pdo): string
{
    $prefix = 'CLM' . date('Ymd');

    $stmt = $pdo->prepare("\n        SELECT return_no\n        FROM harddisk_claim_returns\n        WHERE return_no LIKE :prefix\n        ORDER BY return_no DESC\n        LIMIT 1\n        FOR UPDATE\n    ");
    $stmt->execute([':prefix' => $prefix . '%']);
    $lastNo = (string)$stmt->fetchColumn();

    $running = 1;
    if ($lastNo !== '') {
        $running = ((int)substr($lastNo, -4)) + 1;
    }

    return $prefix . str_pad((string)$running, 4, '0', STR_PAD_LEFT);
}

function ensureClaimReturnTable(PDO $pdo): void
{
    $pdo->exec("\n        CREATE TABLE IF NOT EXISTS harddisk_claim_returns (\n            id INT UNSIGNED NOT NULL AUTO_INCREMENT,\n            return_no VARCHAR(30) NOT NULL,\n            delivery_request_id INT UNSIGNED NULL,\n            request_no VARCHAR(50) NULL,\n            main_branch_code VARCHAR(10) NOT NULL,\n            branch_code VARCHAR(50) NOT NULL,\n            branch_name VARCHAR(255) NOT NULL,\n            hdd_serial VARCHAR(100) NOT NULL,\n            claim_reason VARCHAR(255) NOT NULL,\n            hdd_condition VARCHAR(255) NULL,\n            return_tracking_no VARCHAR(100) NULL,\n            received_by VARCHAR(255) NOT NULL,\n            received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            status VARCHAR(30) NOT NULL DEFAULT 'received',\n            sent_claim_at DATETIME NULL,\n            claim_result VARCHAR(255) NULL,\n            remark TEXT NULL,\n            created_by VARCHAR(255) NULL,\n            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,\n            updated_by VARCHAR(255) NULL,\n            updated_at DATETIME NULL DEFAULT NULL,\n            deleted_at DATETIME NULL DEFAULT NULL,\n            PRIMARY KEY (id),\n            UNIQUE KEY uk_return_no (return_no),\n            KEY idx_branch_code (branch_code),\n            KEY idx_main_branch_code (main_branch_code),\n            KEY idx_hdd_serial (hdd_serial),\n            KEY idx_status (status),\n            KEY idx_received_at (received_at),\n            KEY idx_deleted_at (deleted_at)\n        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci\n    ");
}

function bindParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
ensureClaimReturnTable($pdo);

$branchColumns = getTableColumns($pdo, 'branch_directory');
$requestColumns = getTableColumns($pdo, 'harddisk_delivery_requests');
$shipmentColumns = getTableColumns($pdo, 'harddisk_shipments');
$inventoryColumns = getTableColumns($pdo, 'harddisk_inventory');
$claimColumns = getTableColumns($pdo, 'harddisk_claim_returns');

$ajax = cleanText($_GET['ajax'] ?? '');

if ($ajax === 'branches') {
    $mainBranchCode = formatMainBranchCode($_GET['main_branch_code'] ?? '');

    if ($mainBranchCode === '') {
        jsonResponse(['success' => false, 'message' => 'กรุณาระบุรหัสสาขาใหญ่']);
    }

    if (!tableExists($pdo, 'branch_directory')) {
        jsonResponse(['success' => false, 'message' => 'ไม่พบตาราง branch_directory']);
    }

    $select = [
        hasColumn($branchColumns, 'main_branch_code') ? 'main_branch_code' : "'' AS main_branch_code",
        hasColumn($branchColumns, 'branch_code') ? 'branch_code' : "'' AS branch_code",
        hasColumn($branchColumns, 'branch_name') ? 'branch_name' : "'' AS branch_name",
        hasColumn($branchColumns, 'full_address') ? 'full_address' : "'' AS full_address",
        hasColumn($branchColumns, 'phone') ? 'phone' : "'' AS phone",
        hasColumn($branchColumns, 'landmark') ? 'landmark' : "'' AS landmark",
    ];

    $where = [];
    if (hasColumn($branchColumns, 'is_active')) {
        $where[] = '(is_active = 1 OR is_active IS NULL)';
    }
    $where[] = "LPAD(main_branch_code, 3, '0') = :main_branch_code";

    $stmt = $pdo->prepare("\n        SELECT " . implode(', ', $select) . "\n        FROM branch_directory\n        WHERE " . implode(' AND ', $where) . "\n        ORDER BY branch_code ASC\n    ");
    $stmt->execute([':main_branch_code' => $mainBranchCode]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    jsonResponse(['success' => true, 'data' => $rows, 'total' => count($rows)]);
}

if ($ajax === 'branch_hdds') {
    $branchCode = cleanText($_GET['branch_code'] ?? '');

    if ($branchCode === '') {
        jsonResponse(['success' => false, 'message' => 'กรุณาเลือกสาขาก่อน']);
    }

    $rows = [];

    if (tableExists($pdo, 'harddisk_delivery_requests') && hasColumn($requestColumns, 'branch_code') && hasColumn($requestColumns, 'hdd_serial')) {
        $where = [
            'branch_code = :branch_code',
            "hdd_serial IS NOT NULL",
            "hdd_serial <> ''",
        ];

        if (hasColumn($requestColumns, 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }

        $select = [
            hasColumn($requestColumns, 'id') ? 'id AS delivery_request_id' : 'NULL AS delivery_request_id',
            hasColumn($requestColumns, 'request_no') ? 'request_no' : "'' AS request_no",
            'hdd_serial',
            hasColumn($requestColumns, 'status') ? 'status AS request_status' : "'' AS request_status",
            hasColumn($requestColumns, 'created_at') ? 'created_at' : 'NULL AS created_at',
        ];

        $stmt = $pdo->prepare("\n            SELECT " . implode(', ', $select) . "\n            FROM harddisk_delivery_requests\n            WHERE " . implode(' AND ', $where) . "\n            ORDER BY id DESC\n            LIMIT 80\n        ");
        $stmt->execute([':branch_code' => $branchCode]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    jsonResponse(['success' => true, 'data' => $rows, 'total' => count($rows)]);
}

$errors = [];
$successMessage = '';
$loginName = currentLoginName();


if ($ajax === 'lookup_serial') {
    $hddSerial = strtoupper(cleanText($_GET['hdd_serial'] ?? ''));

    if ($hddSerial === '') {
        jsonResponse(['success' => false, 'message' => 'กรุณาระบุ Serial HDD']);
    }

    if (!preg_match('/^[A-Z0-9]+$/', $hddSerial)) {
        jsonResponse(['success' => false, 'message' => 'Serial HDD ไม่ถูกต้อง']);
    }

    $result = null;

    if (tableExists($pdo, 'harddisk_shipments') && hasColumn($shipmentColumns, 'hdd_serial')) {
        $select = [
            hasColumn($shipmentColumns, 'id') ? 'id' : '0 AS id',
            hasColumn($shipmentColumns, 'delivery_request_id') ? 'delivery_request_id' : 'NULL AS delivery_request_id',
            hasColumn($shipmentColumns, 'request_no') ? 'request_no' : "'' AS request_no",
            hasColumn($shipmentColumns, 'main_branch_code') ? 'main_branch_code' : "'' AS main_branch_code",
            hasColumn($shipmentColumns, 'branch_code') ? 'branch_code' : "'' AS branch_code",
            hasColumn($shipmentColumns, 'branch_name') ? 'branch_name' : "'' AS branch_name",
            hasColumn($shipmentColumns, 'hdd_serial') ? 'hdd_serial' : "'' AS hdd_serial",
            hasColumn($shipmentColumns, 'status') ? 'status' : "'' AS status",
            hasColumn($shipmentColumns, 'created_by') ? 'created_by' : (hasColumn($shipmentColumns, 'requested_by') ? 'requested_by AS created_by' : "'' AS created_by"),
            hasColumn($shipmentColumns, 'received_by') ? 'received_by' : "'' AS received_by",
            hasColumn($shipmentColumns, 'remark') ? 'remark' : "'' AS remark",
        ];

        $dateExpr = "''";
        foreach (['shipped_at', 'shipping_date', 'delivery_date', 'created_at'] as $col) {
            if (hasColumn($shipmentColumns, $col)) {
                $dateExpr = $col;
                break;
            }
        }
        $select[] = $dateExpr . ' AS history_date';

        $where = ['BINARY hdd_serial = :hdd_serial'];
        if (hasColumn($shipmentColumns, 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }

        $orderBy = 'id DESC';
        foreach (['shipped_at', 'shipping_date', 'delivery_date', 'created_at'] as $col) {
            if (hasColumn($shipmentColumns, $col)) {
                $orderBy = $col . ' DESC, id DESC';
                break;
            }
        }

        $stmt = $pdo->prepare("\n            SELECT " . implode(', ', $select) . "\n            FROM harddisk_shipments\n            WHERE " . implode(' AND ', $where) . "\n            ORDER BY {$orderBy}\n            LIMIT 1\n        ");
        $stmt->execute([':hdd_serial' => $hddSerial]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $result = $row;
            $result['source_page'] = 'ประวัติการจัดส่ง Harddisk';
        }
    }

    if ($result === null && tableExists($pdo, 'harddisk_delivery_requests') && hasColumn($requestColumns, 'hdd_serial')) {
        $select = [
            hasColumn($requestColumns, 'id') ? 'id' : '0 AS id',
            hasColumn($requestColumns, 'id') ? 'id AS delivery_request_id' : 'NULL AS delivery_request_id',
            hasColumn($requestColumns, 'request_no') ? 'request_no' : "'' AS request_no",
            hasColumn($requestColumns, 'main_branch_code') ? 'main_branch_code' : "'' AS main_branch_code",
            hasColumn($requestColumns, 'branch_code') ? 'branch_code' : "'' AS branch_code",
            hasColumn($requestColumns, 'branch_name') ? 'branch_name' : "'' AS branch_name",
            hasColumn($requestColumns, 'hdd_serial') ? 'hdd_serial' : "'' AS hdd_serial",
            hasColumn($requestColumns, 'status') ? 'status' : "'' AS status",
            hasColumn($requestColumns, 'requested_by') ? 'requested_by AS created_by' : (hasColumn($requestColumns, 'created_by') ? 'created_by' : "'' AS created_by"),
            "'' AS received_by",
            hasColumn($requestColumns, 'remark') ? 'remark' : "'' AS remark",
        ];

        $dateExpr = "''";
        foreach (['received_date', 'shipped_at', 'created_at'] as $col) {
            if (hasColumn($requestColumns, $col)) {
                $dateExpr = $col;
                break;
            }
        }
        $select[] = $dateExpr . ' AS history_date';

        $where = ['BINARY hdd_serial = :hdd_serial'];
        if (hasColumn($requestColumns, 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }

        $orderBy = 'id DESC';
        foreach (['received_date', 'shipped_at', 'created_at'] as $col) {
            if (hasColumn($requestColumns, $col)) {
                $orderBy = $col . ' DESC, id DESC';
                break;
            }
        }

        $stmt = $pdo->prepare("\n            SELECT " . implode(', ', $select) . "\n            FROM harddisk_delivery_requests\n            WHERE " . implode(' AND ', $where) . "\n            ORDER BY {$orderBy}\n            LIMIT 1\n        ");
        $stmt->execute([':hdd_serial' => $hddSerial]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            $result = $row;
            $result['source_page'] = 'รายการคำขอส่ง HDD';
        }
    }

    if ($result === null) {
        jsonResponse(['success' => false, 'message' => 'ไม่พบ Serial HDD นี้ในประวัติการจัดส่ง Harddisk']);
    }

    jsonResponse([
        'success' => true,
        'data' => [
            'delivery_request_id' => (string)($result['delivery_request_id'] ?? ''),
            'request_no' => (string)($result['request_no'] ?? ''),
            'main_branch_code' => formatMainBranchCode($result['main_branch_code'] ?? ''),
            'branch_code' => (string)($result['branch_code'] ?? ''),
            'branch_name' => (string)($result['branch_name'] ?? ''),
            'hdd_serial' => strtoupper((string)($result['hdd_serial'] ?? '')),
            'status' => (string)($result['status'] ?? ''),
            'history_date' => (string)($result['history_date'] ?? ''),
            'created_by' => (string)($result['created_by'] ?? ''),
            'received_by' => (string)($result['received_by'] ?? ''),
            'remark' => (string)($result['remark'] ?? ''),
            'source_page' => (string)($result['source_page'] ?? 'ประวัติการจัดส่ง Harddisk'),
        ],
    ]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = cleanText($_POST['form_action'] ?? 'create');

    if ($formAction === 'update_status') {
        $claimId = (int)($_POST['claim_id'] ?? 0);
        $newStatus = cleanText($_POST['new_status'] ?? '');
        $claimResult = cleanText($_POST['claim_result'] ?? '');

        $allowedStatuses = [
            'received',
            'preparing_claim',
            'sent_claim',
            'claim_approved',
            'claim_rejected',
            'returned_stock',
            'scrapped',
            'cancelled',
        ];

        if ($claimId <= 0) {
            $errors[] = 'ไม่พบรายการที่ต้องการปรับสถานะ';
        } elseif (!in_array($newStatus, $allowedStatuses, true)) {
            $errors[] = 'สถานะที่เลือกไม่ถูกต้อง';
        } else {
            try {
                $pdo->beginTransaction();

                $stmtOld = $pdo->prepare("\n                    SELECT id, hdd_serial\n                    FROM harddisk_claim_returns\n                    WHERE id = :id\n                      AND deleted_at IS NULL\n                    LIMIT 1\n                    FOR UPDATE\n                ");
                $stmtOld->execute([':id' => $claimId]);
                $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    throw new Exception('ไม่พบรายการรับคืน HDD');
                }

                $updateParts = [
                    'status = :status',
                    'updated_by = :updated_by',
                    'updated_at = NOW()',
                ];
                $paramsUpdate = [
                    ':status' => $newStatus,
                    ':updated_by' => $loginName,
                    ':id' => $claimId,
                ];

                if ($newStatus === 'sent_claim') {
                    $updateParts[] = 'sent_claim_at = IFNULL(sent_claim_at, NOW())';
                }

                if ($claimResult !== '') {
                    $updateParts[] = 'claim_result = :claim_result';
                    $paramsUpdate[':claim_result'] = $claimResult;
                }

                $stmtUpdate = $pdo->prepare("\n                    UPDATE harddisk_claim_returns\n                    SET " . implode(', ', $updateParts) . "\n                    WHERE id = :id\n                      AND deleted_at IS NULL\n                    LIMIT 1\n                ");
                $stmtUpdate->execute($paramsUpdate);

                if (tableExists($pdo, 'harddisk_inventory') && hasColumn($inventoryColumns, 'hdd_serial') && hasColumn($inventoryColumns, 'status')) {
                    $inventoryStatus = null;
                    if ($newStatus === 'returned_stock') {
                        $inventoryStatus = 'available';
                    } elseif (in_array($newStatus, ['received', 'preparing_claim', 'sent_claim', 'claim_approved', 'claim_rejected', 'scrapped'], true)) {
                        $inventoryStatus = 'damaged';
                    }

                    if ($inventoryStatus !== null) {
                        $inventoryUpdate = ['status = :inventory_status'];
                        if (hasColumn($inventoryColumns, 'updated_at')) {
                            $inventoryUpdate[] = 'updated_at = NOW()';
                        }

                        $sqlInventory = "\n                            UPDATE harddisk_inventory\n                            SET " . implode(', ', $inventoryUpdate) . "\n                            WHERE BINARY hdd_serial = :hdd_serial\n                        ";
                        if (hasColumn($inventoryColumns, 'deleted_at')) {
                            $sqlInventory .= ' AND deleted_at IS NULL';
                        }
                        $sqlInventory .= ' LIMIT 1';

                        $stmtInv = $pdo->prepare($sqlInventory);
                        $stmtInv->execute([
                            ':inventory_status' => $inventoryStatus,
                            ':hdd_serial' => $oldRow['hdd_serial'],
                        ]);
                    }
                }

                $pdo->commit();
                header('Location: index.php?status_updated=1');
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'ไม่สามารถปรับสถานะได้: ' . $e->getMessage();
            }
        }
    } else {
        $mainBranchCode = formatMainBranchCode($_POST['main_branch_code'] ?? '');
        $branchCode = cleanText($_POST['branch_code'] ?? '');
        $branchName = cleanText($_POST['branch_name'] ?? '');
        $deliveryRequestId = (int)($_POST['delivery_request_id'] ?? 0);
        $requestNo = cleanText($_POST['request_no'] ?? '');
        $hddSerial = strtoupper(cleanText($_POST['hdd_serial'] ?? ''));
        $actionType = cleanText($_POST['action_type'] ?? 'claim');
        $claimReason = cleanText($_POST['claim_reason'] ?? '');
        $hddCondition = cleanText($_POST['hdd_condition'] ?? '');
        $returnTrackingNo = cleanText($_POST['return_tracking_no'] ?? '');
        $remark = cleanText($_POST['remark'] ?? '');

        if ($mainBranchCode === '') {
            $errors[] = 'กรุณาค้นหาและเลือกสาขา';
        }

        if ($branchCode === '' || $branchName === '') {
            $errors[] = 'กรุณาเลือกสาขาที่รับคืน HDD';
        }

        if ($hddSerial === '') {
            $errors[] = 'กรุณาระบุ Serial HDD ที่รับคืนจากสาขา';
        } elseif (preg_match('/^[A-Za-z0-9]+$/', $hddSerial) !== 1) {
            $errors[] = 'Serial HDD ต้องเป็นตัวอักษรภาษาอังกฤษและตัวเลขเท่านั้น';
        }

        if (!in_array($actionType, ['claim', 'scrap'], true)) {
            $errors[] = 'กรุณาเลือกการดำเนินการของ IT';
        }

        if ($claimReason === '') {
            $errors[] = 'กรุณาเลือกอาการหรือเหตุผลของ HDD';
        }

        if (empty($errors)) {
            try {
                $pdo->beginTransaction();

                $stmtDuplicate = $pdo->prepare("\n                    SELECT return_no, status\n                    FROM harddisk_claim_returns\n                    WHERE BINARY hdd_serial = :hdd_serial\n                      AND deleted_at IS NULL\n                      AND status NOT IN ('claim_approved', 'claim_rejected', 'returned_stock', 'scrapped', 'cancelled')\n                    ORDER BY id DESC\n                    LIMIT 1\n                    FOR UPDATE\n                ");
                $stmtDuplicate->execute([':hdd_serial' => $hddSerial]);
                $duplicate = $stmtDuplicate->fetch(PDO::FETCH_ASSOC);

                if ($duplicate) {
                    throw new Exception('Serial HDD นี้มีรายการรับคืนที่ยังไม่ปิดงานอยู่แล้ว เลขที่ ' . $duplicate['return_no'] . ' สถานะ ' . claimStatusText($duplicate['status']));
                }

                $returnNo = generateReturnNo($pdo);
                $initialStatus = $actionType === 'scrap' ? 'scrapped' : 'preparing_claim';
                $claimReasonText = $actionType === 'scrap' ? 'ตีชำรุด' : 'ส่งเคลม';
                if ($claimReason !== '') {
                    $claimReasonText .= ' - ' . $claimReason;
                }

                $stmt = $pdo->prepare("\n                    INSERT INTO harddisk_claim_returns (\n                        return_no,\n                        delivery_request_id,\n                        request_no,\n                        main_branch_code,\n                        branch_code,\n                        branch_name,\n                        hdd_serial,\n                        claim_reason,\n                        hdd_condition,\n                        return_tracking_no,\n                        received_by,\n                        received_at,\n                        status,\n                        remark,\n                        created_by,\n                        created_at\n                    ) VALUES (\n                        :return_no,\n                        :delivery_request_id,\n                        :request_no,\n                        :main_branch_code,\n                        :branch_code,\n                        :branch_name,\n                        :hdd_serial,\n                        :claim_reason,\n                        :hdd_condition,\n                        :return_tracking_no,\n                        :received_by,\n                        NOW(),\n                        :status,\n                        :remark,\n                        :created_by,\n                        NOW()\n                    )\n                ");

                $stmt->execute([
                    ':return_no' => $returnNo,
                    ':delivery_request_id' => $deliveryRequestId > 0 ? $deliveryRequestId : null,
                    ':request_no' => $requestNo !== '' ? $requestNo : null,
                    ':main_branch_code' => $mainBranchCode,
                    ':branch_code' => $branchCode,
                    ':branch_name' => $branchName,
                    ':hdd_serial' => $hddSerial,
                    ':claim_reason' => $claimReasonText,
                    ':hdd_condition' => $hddCondition !== '' ? $hddCondition : null,
                    ':return_tracking_no' => $returnTrackingNo !== '' ? $returnTrackingNo : null,
                    ':received_by' => $loginName,
                    ':status' => $initialStatus,
                    ':remark' => $remark !== '' ? $remark : null,
                    ':created_by' => $loginName,
                ]);

                if (tableExists($pdo, 'harddisk_inventory') && hasColumn($inventoryColumns, 'hdd_serial') && hasColumn($inventoryColumns, 'status')) {
                    $updateParts = ["status = 'damaged'"];
                    if (hasColumn($inventoryColumns, 'remark')) {
                        $updateParts[] = "remark = CONCAT(IFNULL(remark, ''), CASE WHEN IFNULL(remark, '') = '' THEN '' ELSE '\\n' END, :inventory_remark)";
                    }
                    if (hasColumn($inventoryColumns, 'updated_at')) {
                        $updateParts[] = 'updated_at = NOW()';
                    }

                    $sqlInventory = "\n                        UPDATE harddisk_inventory\n                        SET " . implode(', ', $updateParts) . "\n                        WHERE BINARY hdd_serial = :hdd_serial\n                    ";
                    if (hasColumn($inventoryColumns, 'deleted_at')) {
                        $sqlInventory .= ' AND deleted_at IS NULL';
                    }
                    $sqlInventory .= ' LIMIT 1';

                    $stmtInv = $pdo->prepare($sqlInventory);
                    if (hasColumn($inventoryColumns, 'remark')) {
                        $stmtInv->bindValue(':inventory_remark', 'รับคืนจากสาขาเพื่อส่งเคลม เลขที่ ' . $returnNo);
                    }
                    $stmtInv->bindValue(':hdd_serial', $hddSerial);
                    $stmtInv->execute();
                }

                $pdo->commit();
                header('Location: index.php?saved=1&return_no=' . urlencode($returnNo));
                exit;
            } catch (Throwable $e) {
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                $errors[] = 'ไม่สามารถบันทึกรับคืน HDD ได้: ' . $e->getMessage();
            }
        }
    }
}

$keyword = cleanText($_GET['keyword'] ?? '');
$statusFilter = cleanText($_GET['status'] ?? '');
$dateFrom = cleanText($_GET['date_from'] ?? '');
$dateTo = cleanText($_GET['date_to'] ?? '');
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = (int)($_GET['per_page'] ?? 20);
if (!in_array($perPage, [10, 20, 50, 100], true)) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

$where = ['deleted_at IS NULL'];
$params = [];

if ($statusFilter !== '') {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}

if ($dateFrom !== '') {
    $where[] = 'DATE(received_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== '') {
    $where[] = 'DATE(received_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}

if ($keyword !== '') {
    $normalized = trim(preg_replace('/\s+/', ' ', $keyword));
    $isNumericShort = preg_match('/^\d{1,3}$/', $normalized) === 1;
    $hasSpace = preg_match('/\s/u', $normalized) === 1;

    if ($isNumericShort) {
        $branchPadded = str_pad($normalized, 3, '0', STR_PAD_LEFT);
        $where[] = "(LPAD(main_branch_code, 3, '0') = :kw_branch_padded OR branch_code = :kw_branch_raw OR branch_code = :kw_branch_padded)";
        $params[':kw_branch_padded'] = $branchPadded;
        $params[':kw_branch_raw'] = $normalized;
    } elseif ($hasSpace) {
        $where[] = "(branch_name LIKE :kw OR return_no LIKE :kw OR request_no LIKE :kw)";
        $params[':kw'] = '%' . $normalized . '%';
    } else {
        $where[] = "(return_no LIKE :kw OR request_no LIKE :kw OR branch_name LIKE :kw OR hdd_serial LIKE :kw OR branch_code LIKE :kw)";
        $params[':kw'] = '%' . $normalized . '%';
    }
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmtCount = $pdo->prepare("SELECT COUNT(*) FROM harddisk_claim_returns {$whereSql}");
bindParams($stmtCount, $params);
$stmtCount->execute();
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $perPage));
if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$stmtList = $pdo->prepare("\n    SELECT *\n    FROM harddisk_claim_returns\n    {$whereSql}\n    ORDER BY id DESC\n    LIMIT :limit OFFSET :offset\n");
bindParams($stmtList, $params);
$stmtList->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmtList->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmtList->execute();
$claimRows = $stmtList->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'today' => 0,
    'received' => 0,
    'sent_claim' => 0,
    'closed' => 0,
    'total' => 0,
];

$summary['today'] = (int)$pdo->query("SELECT COUNT(*) FROM harddisk_claim_returns WHERE deleted_at IS NULL AND DATE(received_at) = CURDATE()")->fetchColumn();
$summary['received'] = (int)$pdo->query("SELECT COUNT(*) FROM harddisk_claim_returns WHERE deleted_at IS NULL AND status IN ('received', 'preparing_claim')")->fetchColumn();
$summary['sent_claim'] = (int)$pdo->query("SELECT COUNT(*) FROM harddisk_claim_returns WHERE deleted_at IS NULL AND status = 'sent_claim'")->fetchColumn();
$summary['closed'] = (int)$pdo->query("SELECT COUNT(*) FROM harddisk_claim_returns WHERE deleted_at IS NULL AND status IN ('claim_approved', 'claim_rejected', 'returned_stock', 'scrapped')")->fetchColumn();
$summary['total'] = (int)$pdo->query("SELECT COUNT(*) FROM harddisk_claim_returns WHERE deleted_at IS NULL")->fetchColumn();

$pageTitle = 'รับคืน HDD ส่งเคลมจากสาขา';
require_once __DIR__ . '/../../includes/header.php';

$claimReasons = [
    '' => '-- เลือกสาเหตุ --',
    'HDD เสีย' => 'HDD เสีย',
    'เครื่องบันทึกไม่เห็น HDD' => 'เครื่องบันทึกไม่เห็น HDD',
    'บันทึกภาพไม่ได้' => 'บันทึกภาพไม่ได้',
    'มีเสียงผิดปกติ' => 'มีเสียงผิดปกติ',
    'ตรวจพบ Bad Sector' => 'ตรวจพบ Bad Sector',
    'เปลี่ยนทดแทนของเดิม' => 'เปลี่ยนทดแทนของเดิม',
    'อื่น ๆ' => 'อื่น ๆ',
];

$conditions = [
    '' => '-- เลือกถ้ามี --',
    'สภาพปกติ' => 'สภาพปกติ',
    'มีรอยขีดข่วน' => 'มีรอยขีดข่วน',
    'มีรอยกระแทก' => 'มีรอยกระแทก',
    'กล่อง/อุปกรณ์ไม่ครบ' => 'กล่อง/อุปกรณ์ไม่ครบ',
    'ไม่สามารถตรวจสอบได้' => 'ไม่สามารถตรวจสอบได้',
];

$statuses = [
    '' => 'ทั้งหมด',
    'received' => 'รับคืนจากสาขาแล้ว',
    'preparing_claim' => 'เตรียมส่งเคลม',
    'sent_claim' => 'ส่งเคลมแล้ว',
    'claim_approved' => 'เคลมผ่าน',
    'claim_rejected' => 'เคลมไม่ผ่าน',
    'returned_stock' => 'กลับเข้าคลัง',
    'scrapped' => 'จำหน่ายทิ้ง',
    'cancelled' => 'ยกเลิก',
];
?>

<style>
    body { background: #f3f6fb; }
    .claim-page { padding: 10px 0 16px 0; }
    .claim-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .claim-subtitle { font-size: 13px; color: #64748b; }
    .claim-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .claim-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; }
    .claim-card .card-body { padding: 12px; }
    .hero-card { border: 0; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #ffffff; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22); }
    .hero-card .card-body { padding: 12px 16px; }
    .kpi-card { border: 0; border-radius: 15px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07); height: 100%; }
    .kpi-card .card-body { padding: 12px 14px; }
    .kpi-label { color: #64748b; font-size: 12px; margin-bottom: 4px; }
    .kpi-value { font-size: 28px; font-weight: 900; color: #0f172a; line-height: 1; }
    .kpi-note { color: #94a3b8; font-size: 11px; margin-top: 5px; }
    .form-label { font-size: 13px; font-weight: 800; color: #334155; margin-bottom: 4px; }
    .form-control, .form-select { font-size: 13px; border-radius: 10px; }
    .btn { border-radius: 10px; }
    .btn-sm { font-size: 12px; padding: 4px 8px; }
    .step-box { border: 1px solid #e2e8f0; border-radius: 14px; padding: 12px; background: #f8fafc; height: 100%; }
    .step-title { font-size: 13px; font-weight: 900; color: #0f172a; margin-bottom: 10px; display: flex; align-items: center; gap: 6px; }
    .step-badge { width: 22px; height: 22px; border-radius: 8px; background: #2563eb; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
    .workflow-note { font-size: 12px; color: #64748b; line-height: 1.45; }
    .lookup-state { min-height: 64px; }
    .lookup-grid { display: grid; grid-template-columns: repeat(2, minmax(0, 1fr)); gap: 8px 12px; }
    .lookup-item { font-size: 12px; }
    .lookup-item.full { grid-column: 1 / -1; }
    .lookup-label { display: block; font-weight: 700; color: #64748b; margin-bottom: 2px; }
    .lookup-value { display: block; color: #0f172a; font-weight: 700; }
    .table-scroll { max-height: 430px; overflow: auto; }
    .table-claim th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 12px; white-space: nowrap; padding: 7px 8px; }
    .table-claim td { font-size: 12px; vertical-align: middle; padding: 7px 8px; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-weight: 900; color: #7c2d12; white-space: nowrap; }
    .branch-code { font-weight: 900; color: #1d4ed8; white-space: nowrap; }
    .text-ellipsis { max-width: 230px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .selected-branch-box { background: #eef6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 10px 12px; font-size: 13px; }
    .action-card { cursor: pointer; transition: all .18s ease; min-height: 112px; }
    .action-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,.08); }
    .action-icon { font-size: 18px; line-height: 1; }
    .detail-panel { background: #ffffff; border: 1px dashed #dbeafe; border-radius: 14px; padding: 12px; }
    .help-box { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 8px 10px; font-size: 12px; }
    .status-form { min-width: 190px; }
    .page-section { margin-bottom: 12px; }
    @media (max-width: 1366px) {
        .claim-page { padding-top: 8px; }
        .claim-title { font-size: 20px; }
        .claim-card .card-body { padding: 10px; }
        .kpi-card .card-body { padding: 10px 12px; }
        .kpi-value { font-size: 25px; }
        .table-scroll { max-height: 385px; }
        .table-claim th, .table-claim td { font-size: 11.5px; padding: 6px 7px; }
        .form-control, .form-select { font-size: 12px; }
    }
</style>

<div class="container-fluid claim-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="claim-title">รับคืน HDD ส่งเคลมจากสาขา</h3>
            <div class="claim-subtitle">บันทึกรับคืน HDD จากสาขา ติดตามสถานะส่งเคลม และอัปเดตสถานะคลังอัตโนมัติ</div>
        </div>
        <div class="d-flex gap-2">
            <a href="../dashboard/index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
            <a href="../inventory/index.php" class="btn btn-outline-primary btn-sm">คลัง HDD</a>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success py-2 mb-2">
            บันทึกรับคืน HDD ส่งเคลมเรียบร้อยแล้ว เลขที่รับคืน: <strong><?php echo h($_GET['return_no'] ?? ''); ?></strong>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['status_updated'])): ?>
        <div class="alert alert-success py-2 mb-2">
            อัปเดตสถานะรายการรับคืน HDD เรียบร้อยแล้ว
        </div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger py-2 mb-2">
            <strong>ไม่สามารถดำเนินการได้</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card hero-card mb-2">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold">Workflow: รับคืนจากสาขา → เตรียมส่งเคลม → ส่งเคลม → ปิดงาน</div>
                <div class="small opacity-75">เมื่อบันทึกรับคืน ระบบจะปรับสถานะ HDD ในคลังเป็น “ชำรุด” เพื่อกันนำไปใช้งานซ้ำ</div>
            </div>
            <div class="small">ผู้ใช้งานปัจจุบัน: <strong><?php echo h($loginName); ?></strong></div>
        </div>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">รับคืนวันนี้</div><div class="kpi-value"><?php echo number_format($summary['today']); ?></div><div class="kpi-note">รายการที่รับเข้าวันนี้</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">รอส่งเคลม</div><div class="kpi-value"><?php echo number_format($summary['received']); ?></div><div class="kpi-note">รับคืนแล้ว / เตรียมส่งเคลม</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">ส่งเคลมแล้ว</div><div class="kpi-value"><?php echo number_format($summary['sent_claim']); ?></div><div class="kpi-note">อยู่ระหว่างติดตามผลเคลม</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">ปิดงานแล้ว</div><div class="kpi-value"><?php echo number_format($summary['closed']); ?></div><div class="kpi-note">ทั้งหมด <?php echo number_format($summary['total']); ?> รายการ</div></div></div></div>
    </div>

    <div class="page-section">
        <div class="card claim-card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>บันทึกรับคืน HDD จากสาขา</span>
                <span class="small text-muted">สแกน Serial → ตรวจสอบสาขา → เลือกส่งเคลมหรือตีชำรุด → บันทึก</span>
            </div>
            <div class="card-body">
                <form method="post" id="claimForm" autocomplete="off">
                    <input type="hidden" name="form_action" value="create">
                    <input type="hidden" name="main_branch_code" id="main_branch_code" value="<?php echo h($_POST['main_branch_code'] ?? ''); ?>">
                    <input type="hidden" name="branch_code" id="branch_code" value="<?php echo h($_POST['branch_code'] ?? ''); ?>">
                    <input type="hidden" name="branch_name" id="branch_name" value="<?php echo h($_POST['branch_name'] ?? ''); ?>">
                    <input type="hidden" name="delivery_request_id" id="delivery_request_id" value="<?php echo h($_POST['delivery_request_id'] ?? ''); ?>">
                    <input type="hidden" name="request_no" id="request_no" value="<?php echo h($_POST['request_no'] ?? ''); ?>">

                    <div class="row g-3 align-items-stretch mb-3">
                        <div class="col-xl-4 col-lg-6">
                            <div class="step-box">
                                <div class="step-title"><span class="step-badge">1</span> ยิงบาร์โค้ด HDD</div>
                                <label class="form-label">Serial HDD <span class="text-danger">*</span></label>
                                <div class="input-group mb-2">
                                    <input type="text" name="hdd_serial" id="hdd_serial" class="form-control form-control-lg serial-text" value="<?php echo h($_POST['hdd_serial'] ?? ''); ?>" placeholder="ยิงบาร์โค้ด หรือกรอก Serial HDD" required>
                                    <button type="button" id="btnLookupSerial" class="btn btn-primary px-4">ตรวจสอบ</button>
                                </div>
                                <div class="workflow-note">ระบบจะอ้างอิงข้อมูลจากหน้า <strong>ประวัติการจัดส่ง Harddisk</strong> เพื่อตรวจสอบว่า HDD ตัวนี้เคยถูกส่งไปยังสาขาใด</div>
                            </div>
                        </div>

                        <div class="col-xl-4 col-lg-6">
                            <div class="step-box">
                                <div class="step-title"><span class="step-badge">2</span> ผลการตรวจสอบจากประวัติการจัดส่ง</div>
                                <div id="lookupAlert" class="alert alert-secondary lookup-state mb-2 d-flex align-items-center">กรุณายิงบาร์โค้ด HDD ก่อน ระบบจะแสดงข้อมูลสาขาและประวัติการจัดส่งล่าสุดให้อัตโนมัติ</div>
                                <div id="lookupResultBox" class="selected-branch-box d-none">
                                    <div class="lookup-grid">
                                        <div class="lookup-item"><span class="lookup-label">อ้างอิงจาก</span><span class="lookup-value" id="show_source_page">-</span></div>
                                        <div class="lookup-item"><span class="lookup-label">เลขที่คำขอ</span><span class="lookup-value" id="show_request_no">-</span></div>
                                        <div class="lookup-item"><span class="lookup-label">รหัสสาขาใหญ่</span><span class="lookup-value" id="show_main_branch_code">-</span></div>
                                        <div class="lookup-item"><span class="lookup-label">Cost Center</span><span class="lookup-value branch-code" id="show_branch_code">-</span></div>
                                        <div class="lookup-item full"><span class="lookup-label">ชื่อสาขา</span><span class="lookup-value" id="show_branch_name">-</span></div>
                                        <div class="lookup-item"><span class="lookup-label">วันจัดส่งล่าสุด</span><span class="lookup-value" id="show_history_date">-</span></div>
                                        <div class="lookup-item"><span class="lookup-label">สถานะล่าสุด</span><span class="lookup-value" id="show_history_status">-</span></div>
                                        <div class="lookup-item full"><span class="lookup-label">ผู้บันทึก/ผู้จัดส่ง</span><span class="lookup-value" id="show_created_by">-</span></div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        <div class="col-xl-4 col-lg-12">
                            <div class="step-box">
                                <div class="step-title"><span class="step-badge">3</span> เลือกการดำเนินการของ IT</div>
                                <div class="row g-2">
                                    <div class="col-md-6 col-lg-6 col-xl-12">
                                        <label class="w-100 mb-0">
                                            <input type="radio" name="action_type" value="claim" class="d-none action-radio" checked>
                                            <div class="selected-branch-box action-card" data-action="claim">
                                                <div class="d-flex align-items-start gap-2">
                                                    <div class="action-icon">📦</div>
                                                    <div>
                                                        <div class="fw-bold text-primary mb-1">ส่งเคลม</div>
                                                        <div class="small text-muted">รับคืนจากสาขาแล้ว และเตรียมส่งต่อผู้จำหน่าย/ผู้ขาย</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                    <div class="col-md-6 col-lg-6 col-xl-12">
                                        <label class="w-100 mb-0">
                                            <input type="radio" name="action_type" value="scrap" class="d-none action-radio">
                                            <div class="selected-branch-box action-card" data-action="scrap">
                                                <div class="d-flex align-items-start gap-2">
                                                    <div class="action-icon">🗑️</div>
                                                    <div>
                                                        <div class="fw-bold text-danger mb-1">ตีชำรุด</div>
                                                        <div class="small text-muted">ไม่ส่งเคลม และปิดรายการเป็น HDD ชำรุด/จำหน่ายทิ้งทันที</div>
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="detail-panel">
                        <div class="step-title mb-2"><span class="step-badge">4</span> รายละเอียดเพิ่มเติมและยืนยันการบันทึก</div>
                        <div class="row g-3 align-items-start">
                            <div class="col-xl-3 col-md-6">
                                <label class="form-label">อาการ / เหตุผลของ HDD <span class="text-danger">*</span></label>
                                <select name="claim_reason" class="form-select" required>
                                    <?php $selectedReason = cleanText($_POST['claim_reason'] ?? ''); ?>
                                    <?php foreach ($claimReasons as $value => $label): ?>
                                        <option value="<?php echo h($value); ?>" <?php echo $selectedReason === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <label class="form-label">สภาพ HDD</label>
                                <select name="hdd_condition" class="form-select">
                                    <?php $selectedCondition = cleanText($_POST['hdd_condition'] ?? ''); ?>
                                    <?php foreach ($conditions as $value => $label): ?>
                                        <option value="<?php echo h($value); ?>" <?php echo $selectedCondition === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <label class="form-label">เลขพัสดุส่งคืนจากสาขา</label>
                                <input type="text" name="return_tracking_no" class="form-control" value="<?php echo h($_POST['return_tracking_no'] ?? ''); ?>" placeholder="ถ้ามี">
                            </div>
                            <div class="col-xl-3 col-md-6">
                                <label class="form-label">สถานะที่จะบันทึก</label>
                                <div class="help-box h-100 d-flex align-items-center" id="actionHelpBox">เลือก <strong>ส่งเคลม</strong> ระบบจะบันทึกสถานะเริ่มต้นเป็น “เตรียมส่งเคลม”</div>
                            </div>
                            <div class="col-12">
                                <label class="form-label">หมายเหตุ</label>
                                <textarea name="remark" class="form-control" rows="3" placeholder="รายละเอียดเพิ่มเติม เช่น อาการเสีย เลข Ticket หรือข้อมูลจากสาขา"><?php echo h($_POST['remark'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-lg-8">
                                <div class="help-box">เมื่อบันทึกสำเร็จ ระบบจะอ้างอิง User ที่ Login อยู่เป็นผู้รับคืน และปรับสถานะ HDD ในคลังเป็น “ชำรุด” อัตโนมัติ เพื่อป้องกันการนำกลับไปใช้งานซ้ำ</div>
                            </div>
                            <div class="col-lg-4 d-flex justify-content-lg-end align-items-center gap-2 flex-wrap">
                                <button type="reset" class="btn btn-outline-secondary">ล้างข้อมูล</button>
                                <button type="submit" class="btn btn-success px-4" id="btnSubmitClaim" disabled>บันทึกรับคืน HDD</button>
                            </div>
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>

    <div class="page-section">
        <div class="card claim-card">
            <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                <span>รายการรับคืน HDD ส่งเคลม</span>
                <span class="small text-muted">ทั้งหมด <?php echo number_format($totalRows); ?> รายการ</span>
            </div>
            <div class="card-body">
                <form method="get" class="row g-2 align-items-end mb-2">
                    <div class="col-xl-2 col-md-3">
                        <label class="form-label">วันที่เริ่มต้น</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                    </div>
                    <div class="col-xl-2 col-md-3">
                        <label class="form-label">วันที่สิ้นสุด</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                    </div>
                    <div class="col-xl-2 col-md-2">
                        <label class="form-label">สถานะ</label>
                        <select name="status" class="form-select">
                            <?php foreach ($statuses as $value => $label): ?>
                                <option value="<?php echo h($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-1 col-md-2">
                        <label class="form-label">จำนวน</label>
                        <select name="per_page" class="form-select">
                            <?php foreach ([10, 20, 50, 100] as $n): ?>
                                <option value="<?php echo $n; ?>" <?php echo $perPage === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-xl-3 col-md-6">
                        <label class="form-label">Keyword</label>
                        <input type="text" name="keyword" class="form-control" value="<?php echo h($keyword); ?>" placeholder="เลขที่รับคืน, คำขอ, สาขา, Serial">
                    </div>
                    <div class="col-xl-2 col-md-6 d-flex gap-2">
                        <button type="submit" class="btn btn-primary flex-fill">ค้นหา</button>
                        <a href="index.php" class="btn btn-outline-secondary">ล้างค่า</a>
                    </div>
                </form>

                <div class="d-flex justify-content-between align-items-center mb-2 small text-muted flex-wrap gap-1">
                    <div>หน้า <?php echo number_format($page); ?> / <?php echo number_format($totalPages); ?></div>
                    <div>แสดง <?php echo number_format(count($claimRows)); ?> รายการ</div>
                </div>

                <div class="table-responsive table-scroll">
                    <table class="table table-hover table-bordered align-middle mb-0 table-claim">
                        <thead>
                            <tr>
                                <th>เลขที่รับคืน</th>
                                <th>สาขา</th>
                                <th>Serial HDD</th>
                                <th>สาเหตุ/สภาพ</th>
                                <th>สถานะ</th>
                                <th>ผู้รับคืน</th>
                                <th>วันที่รับคืน</th>
                                <th style="width:230px;">ปรับสถานะ</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($claimRows)): ?>
                                <tr>
                                    <td colspan="8" class="text-center text-muted py-4">ไม่พบข้อมูลรับคืน HDD ส่งเคลม</td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($claimRows as $row): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo h($row['return_no']); ?></strong><br>
                                            <span class="text-muted">คำขอ: <?php echo h($row['request_no'] ?: '-'); ?></span>
                                        </td>
                                        <td>
                                            <span class="branch-code"><?php echo h($row['main_branch_code']); ?></span> / <?php echo h($row['branch_code']); ?><br>
                                            <div class="text-ellipsis" title="<?php echo h($row['branch_name']); ?>"><?php echo h($row['branch_name']); ?></div>
                                        </td>
                                        <td><span class="serial-text"><?php echo h($row['hdd_serial']); ?></span></td>
                                        <td>
                                            <div class="text-ellipsis" title="<?php echo h($row['claim_reason']); ?>"><?php echo h($row['claim_reason']); ?></div>
                                            <?php if (!empty($row['hdd_condition'])): ?>
                                                <div class="small text-muted text-ellipsis" title="<?php echo h($row['hdd_condition']); ?>"><?php echo h($row['hdd_condition']); ?></div>
                                            <?php endif; ?>
                                            <?php if (!empty($row['return_tracking_no'])): ?>
                                                <div class="small text-muted">พัสดุ: <?php echo h($row['return_tracking_no']); ?></div>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo claimStatusBadge($row['status']); ?></td>
                                        <td><div class="text-ellipsis" title="<?php echo h($row['received_by']); ?>"><?php echo h($row['received_by']); ?></div></td>
                                        <td><?php echo h(formatThaiDateTime($row['received_at'])); ?></td>
                                        <td>
                                            <form method="post" class="status-form" onsubmit="return confirm('ยืนยันการปรับสถานะรายการนี้หรือไม่?');">
                                                <input type="hidden" name="form_action" value="update_status">
                                                <input type="hidden" name="claim_id" value="<?php echo (int)$row['id']; ?>">
                                                <div class="input-group input-group-sm">
                                                    <select name="new_status" class="form-select">
                                                        <?php foreach ($statuses as $value => $label): ?>
                                                            <?php if ($value === '') { continue; } ?>
                                                            <option value="<?php echo h($value); ?>" <?php echo $row['status'] === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                                        <?php endforeach; ?>
                                                    </select>
                                                    <button type="submit" class="btn btn-outline-primary">บันทึก</button>
                                                </div>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($totalPages > 1): ?>
                    <nav class="mt-2">
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php $queryBase = $_GET; $queryBase['page'] = max(1, $page - 1); ?>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo h(http_build_query($queryBase)); ?>">ก่อนหน้า</a></li>
                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($i = $startPage; $i <= $endPage; $i++):
                                $queryBase['page'] = $i;
                            ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="?<?php echo h(http_build_query($queryBase)); ?>"><?php echo $i; ?></a></li>
                            <?php endfor; ?>
                            <?php $queryBase['page'] = min($totalPages, $page + 1); ?>
                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="?<?php echo h(http_build_query($queryBase)); ?>">ถัดไป</a></li>
                        </ul>
                    </nav>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const hddSerialInput = document.getElementById('hdd_serial');
    const btnLookupSerial = document.getElementById('btnLookupSerial');
    const claimForm = document.getElementById('claimForm');
    const btnSubmitClaim = document.getElementById('btnSubmitClaim');
    const lookupAlert = document.getElementById('lookupAlert');
    const lookupResultBox = document.getElementById('lookupResultBox');
    const actionHelpBox = document.getElementById('actionHelpBox');

    const mainBranchCodeInput = document.getElementById('main_branch_code');
    const branchCodeInput = document.getElementById('branch_code');
    const branchNameInput = document.getElementById('branch_name');
    const deliveryRequestIdInput = document.getElementById('delivery_request_id');
    const requestNoInput = document.getElementById('request_no');

    const actionCards = document.querySelectorAll('.action-card');
    const actionRadios = document.querySelectorAll('.action-radio');

    function clean(value) {
        return String(value || '').trim();
    }

    function resetLookup() {
        mainBranchCodeInput.value = '';
        branchCodeInput.value = '';
        branchNameInput.value = '';
        deliveryRequestIdInput.value = '';
        requestNoInput.value = '';
        document.getElementById('show_source_page').textContent = '-';
        document.getElementById('show_request_no').textContent = '-';
        document.getElementById('show_main_branch_code').textContent = '-';
        document.getElementById('show_branch_code').textContent = '-';
        document.getElementById('show_branch_name').textContent = '-';
        document.getElementById('show_history_date').textContent = '-';
        document.getElementById('show_history_status').textContent = '-';
        document.getElementById('show_created_by').textContent = '-';
        lookupResultBox.classList.add('d-none');
        btnSubmitClaim.disabled = true;
    }

    function updateActionCards() {
        actionCards.forEach(card => card.style.outline = 'none');
        actionRadios.forEach(radio => {
            if (radio.checked) {
                const card = radio.closest('label').querySelector('.action-card');
                if (card) {
                    card.style.outline = radio.value === 'scrap' ? '2px solid #dc2626' : '2px solid #2563eb';
                    card.style.background = radio.value === 'scrap' ? '#fef2f2' : '#eff6ff';
                }
            }
        });

        const action = document.querySelector('input[name="action_type"]:checked')?.value || 'claim';
        if (action === 'scrap') {
            actionHelpBox.innerHTML = 'เลือก <strong>ตีชำรุด</strong> ระบบจะบันทึกสถานะเป็น “จำหน่ายทิ้ง” ทันที';
        } else {
            actionHelpBox.innerHTML = 'เลือก <strong>ส่งเคลม</strong> ระบบจะบันทึกสถานะเริ่มต้นเป็น “เตรียมส่งเคลม”';
        }
    }

    function lookupSerial() {
        const serial = clean(hddSerialInput.value).toUpperCase().replace(/[^A-Z0-9]/g, '');
        hddSerialInput.value = serial;
        resetLookup();

        if (serial === '') {
            lookupAlert.className = 'alert alert-warning mb-2';
            lookupAlert.textContent = 'กรุณายิงบาร์โค้ดหรือกรอก Serial HDD ก่อน';
            return;
        }

        lookupAlert.className = 'alert alert-info mb-2';
        lookupAlert.textContent = 'กำลังตรวจสอบข้อมูลจากประวัติการจัดส่ง Harddisk...';

        fetch('index.php?ajax=lookup_serial&hdd_serial=' + encodeURIComponent(serial))
            .then(response => response.json())
            .then(result => {
                if (!result.success || !result.data) {
                    lookupAlert.className = 'alert alert-danger mb-2';
                    lookupAlert.textContent = result.message || 'ไม่พบข้อมูล Serial HDD นี้ในประวัติการจัดส่ง';
                    return;
                }

                const item = result.data;
                mainBranchCodeInput.value = clean(item.main_branch_code);
                branchCodeInput.value = clean(item.branch_code);
                branchNameInput.value = clean(item.branch_name);
                deliveryRequestIdInput.value = clean(item.delivery_request_id);
                requestNoInput.value = clean(item.request_no);

                document.getElementById('show_source_page').textContent = item.source_page || '-';
                document.getElementById('show_request_no').textContent = item.request_no || '-';
                document.getElementById('show_main_branch_code').textContent = item.main_branch_code || '-';
                document.getElementById('show_branch_code').textContent = item.branch_code || '-';
                document.getElementById('show_branch_name').textContent = item.branch_name || '-';
                document.getElementById('show_history_date').textContent = item.history_date || '-';
                document.getElementById('show_history_status').textContent = item.status || '-';
                document.getElementById('show_created_by').textContent = item.created_by || item.received_by || '-';
                lookupResultBox.classList.remove('d-none');

                lookupAlert.className = 'alert alert-success mb-2';
                lookupAlert.textContent = 'ตรวจสอบสำเร็จ: พบว่า HDD นี้เคยจัดส่งให้สาขา ' + (item.branch_name || '-') + ' (' + (item.branch_code || '-') + ')';
                btnSubmitClaim.disabled = false;
            })
            .catch(() => {
                lookupAlert.className = 'alert alert-danger mb-2';
                lookupAlert.textContent = 'เกิดข้อผิดพลาดในการตรวจสอบข้อมูล Serial HDD';
            });
    }

    btnLookupSerial.addEventListener('click', lookupSerial);

    hddSerialInput.addEventListener('input', function () {
        this.value = this.value.toUpperCase().replace(/[^A-Z0-9]/g, '');
    });

    hddSerialInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            lookupSerial();
        }
    });

    actionRadios.forEach(radio => {
        radio.addEventListener('change', updateActionCards);
    });
    updateActionCards();

    claimForm.addEventListener('submit', function (event) {
        if (clean(mainBranchCodeInput.value) === '' || clean(branchCodeInput.value) === '' || clean(branchNameInput.value) === '') {
            event.preventDefault();
            alert('กรุณาตรวจสอบ Serial HDD จากประวัติการจัดส่งก่อนบันทึก');
            return;
        }

        if (clean(hddSerialInput.value) === '') {
            event.preventDefault();
            alert('กรุณาระบุ Serial HDD ที่รับคืน');
            hddSerialInput.focus();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
