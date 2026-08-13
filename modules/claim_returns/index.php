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


function currentLoginIdentifiers(): array
{
    $identifiers = [];
    $add = function ($value) use (&$identifiers): void {
        $value = trim((string)($value ?? ''));
        if ($value !== '' && !in_array($value, $identifiers, true)) {
            $identifiers[] = $value;
        }
    };

    $fullName = trim((string)($_SESSION['full_name'] ?? ''));
    if ($fullName === '') {
        $fullName = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
    }
    $employeeCode = trim((string)($_SESSION['employee_code'] ?? ''));

    $add(currentLoginName());
    $add($fullName);
    $add($employeeCode);
    if ($fullName !== '' && $employeeCode !== '') {
        $add($fullName . ' (' . $employeeCode . ')');
    }

    foreach (['username', 'user_id', 'employee_id', 'emp_code'] as $key) {
        $add($_SESSION[$key] ?? '');
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $user = $_SESSION['user'];
        $userFullName = trim((string)($user['full_name'] ?? ''));
        if ($userFullName === '') {
            $userFullName = trim((string)($user['first_name'] ?? '') . ' ' . (string)($user['last_name'] ?? ''));
        }
        $userEmployeeCode = trim((string)($user['employee_code'] ?? ''));

        $add($userFullName);
        $add($userEmployeeCode);
        if ($userFullName !== '' && $userEmployeeCode !== '') {
            $add($userFullName . ' (' . $userEmployeeCode . ')');
        }

        foreach (['username', 'user_id', 'employee_id', 'emp_code', 'code'] as $key) {
            $add($user[$key] ?? '');
        }
    }

    return $identifiers;
}

function claimReturnAddInCondition(array &$params, array $values, string $column, string $prefix): string
{
    $placeholders = [];
    foreach (array_values($values) as $index => $value) {
        $placeholder = ':' . $prefix . '_' . $index;
        $placeholders[] = $placeholder;
        $params[$placeholder] = $value;
    }

    if (empty($placeholders)) {
        return '1 = 0';
    }

    return $column . ' IN (' . implode(', ', $placeholders) . ')';
}

function buildClaimOwnerWhereSql(array $identifiers, array $claimColumns, array $requestColumns, array &$params): string
{
    if (empty($identifiers)) {
        return '1 = 1';
    }

    $conditions = [];

    if (hasColumn($claimColumns, 'created_by')) {
        $conditions[] = claimReturnAddInCondition($params, $identifiers, 'created_by', 'owner_created_by');
    }

    if (hasColumn($claimColumns, 'updated_by')) {
        $conditions[] = claimReturnAddInCondition($params, $identifiers, 'updated_by', 'owner_updated_by');
    }

    if (hasColumn($claimColumns, 'received_by')) {
        $conditions[] = claimReturnAddInCondition($params, $identifiers, 'received_by', 'owner_received_by');
    }

    if (hasColumn($claimColumns, 'delivery_request_id') && hasColumn($requestColumns, 'id')) {
        $requestUserConditions = [];
        if (hasColumn($requestColumns, 'requested_by')) {
            $requestUserConditions[] = claimReturnAddInCondition($params, $identifiers, 'requested_by', 'owner_req_requested_by');
        }
        if (hasColumn($requestColumns, 'created_by')) {
            $requestUserConditions[] = claimReturnAddInCondition($params, $identifiers, 'created_by', 'owner_req_created_by');
        }

        if (!empty($requestUserConditions)) {
            $requestWhere = '(' . implode(' OR ', $requestUserConditions) . ')';
            if (hasColumn($requestColumns, 'deleted_at')) {
                $requestWhere .= ' AND deleted_at IS NULL';
            }
            $conditions[] = 'delivery_request_id IN (SELECT id FROM harddisk_delivery_requests WHERE ' . $requestWhere . ')';
        }
    }

    if (empty($conditions)) {
        return '1 = 1';
    }

    return '(' . implode(' OR ', $conditions) . ')';
}

function countClaimsForCurrentUser(PDO $pdo, string $ownerWhereSql, array $ownerParams, string $extraWhere = ''): int
{
    $sql = "SELECT COUNT(*) FROM harddisk_claim_returns WHERE deleted_at IS NULL";
    if ($ownerWhereSql !== '1 = 1') {
        $sql .= ' AND ' . $ownerWhereSql;
    }
    if ($extraWhere !== '') {
        $sql .= ' AND ' . $extraWhere;
    }

    $stmt = $pdo->prepare($sql);
    bindParams($stmt, $ownerParams);
    $stmt->execute();
    return (int)$stmt->fetchColumn();
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
        'waiting_return' => 'รอรับคืนจากสาขา',
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

    $class = 'claim-status-default';
    $label = claimStatusText($status);

    if ($status === 'waiting_return') {
        $class = 'claim-status-waiting-return claim-status-alert';
        $label = 'รอรับคืนจากสาขา';
    } elseif ($status === 'received') {
        $class = 'claim-status-received claim-status-alert';
        $label = 'รับคืนแล้ว';
    } elseif ($status === 'preparing_claim') {
        $class = 'claim-status-preparing claim-status-alert';
        $label = 'เตรียมส่งเคลม';
    } elseif ($status === 'sent_claim') {
        $class = 'claim-status-sent claim-status-alert';
        $label = 'ส่งเคลมแล้ว';
    } elseif ($status === 'claim_approved') {
        $class = 'claim-status-success';
        $label = 'เคลมผ่าน';
    } elseif ($status === 'returned_stock') {
        $class = 'claim-status-success';
        $label = 'กลับเข้าคลัง';
    } elseif ($status === 'claim_rejected') {
        $class = 'claim-status-danger';
        $label = 'เคลมไม่ผ่าน';
    } elseif ($status === 'scrapped') {
        $class = 'claim-status-danger';
        $label = 'ตีชำรุด';
    } elseif ($status === 'cancelled') {
        $class = 'claim-status-cancelled';
        $label = 'ยกเลิก';
    }

    $icon = strpos($class, 'claim-status-alert') !== false
        ? '<span class="claim-status-alert-icon" aria-hidden="true">●</span>'
        : '';

    return '<span class="claim-status-badge ' . h($class) . '">' . $icon . '<span>' . h($label) . '</span></span>';
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
$loginIdentifiers = currentLoginIdentifiers();
$ownerParamsBase = [];
$ownerWhereSql = buildClaimOwnerWhereSql($loginIdentifiers, $claimColumns, $requestColumns, $ownerParamsBase);


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
            hasColumn($shipmentColumns, 'delivery_request_id')
                ? 'delivery_request_id'
                : (hasColumn($shipmentColumns, 'request_id') ? 'request_id AS delivery_request_id' : 'NULL AS delivery_request_id'),
            hasColumn($shipmentColumns, 'request_no')
                ? 'request_no'
                : (hasColumn($shipmentColumns, 'delivery_request_no') ? 'delivery_request_no AS request_no' : "'' AS request_no"),
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
        foreach (['shipped_at', 'shipped_date', 'shipping_date', 'delivery_date', 'created_at'] as $col) {
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
        foreach (['shipped_at', 'shipped_date', 'shipping_date', 'delivery_date', 'created_at'] as $col) {
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
            'waiting_return',
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

                $sqlOld = "\n                    SELECT id, hdd_serial\n                    FROM harddisk_claim_returns\n                    WHERE id = :id\n                      AND deleted_at IS NULL";
                if ($ownerWhereSql !== '1 = 1') {
                    $sqlOld .= "\n                      AND " . $ownerWhereSql;
                }
                $sqlOld .= "\n                    LIMIT 1\n                    FOR UPDATE\n                ";

                $stmtOld = $pdo->prepare($sqlOld);
                $paramsOld = array_merge([':id' => $claimId], $ownerParamsBase);
                $stmtOld->execute($paramsOld);
                $oldRow = $stmtOld->fetch(PDO::FETCH_ASSOC);

                if (!$oldRow) {
                    throw new Exception('ไม่พบรายการรับคืน HDD ของผู้ใช้งานปัจจุบัน');
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

                $sqlDuplicate = "\n                    SELECT id, return_no, status\n                    FROM harddisk_claim_returns\n                    WHERE BINARY hdd_serial = :hdd_serial\n                      AND deleted_at IS NULL\n                      AND status NOT IN ('claim_approved', 'claim_rejected', 'returned_stock', 'scrapped', 'cancelled')";
                if ($ownerWhereSql !== '1 = 1') {
                    $sqlDuplicate .= "\n                      AND " . $ownerWhereSql;
                }
                $sqlDuplicate .= "\n                    ORDER BY id DESC\n                    LIMIT 1\n                    FOR UPDATE\n                ";

                $stmtDuplicate = $pdo->prepare($sqlDuplicate);
                $duplicateParams = array_merge([':hdd_serial' => $hddSerial], $ownerParamsBase);
                $stmtDuplicate->execute($duplicateParams);
                $duplicate = $stmtDuplicate->fetch(PDO::FETCH_ASSOC);

                if (!$duplicate && $ownerWhereSql !== '1 = 1') {
                    $sqlOtherDuplicate = "\n                        SELECT id, return_no, status, created_by\n                        FROM harddisk_claim_returns\n                        WHERE BINARY hdd_serial = :hdd_serial\n                          AND deleted_at IS NULL\n                          AND status NOT IN ('claim_approved', 'claim_rejected', 'returned_stock', 'scrapped', 'cancelled')\n                          AND NOT " . $ownerWhereSql . "\n                        ORDER BY id DESC\n                        LIMIT 1\n                        FOR UPDATE\n                    ";
                    $stmtOtherDuplicate = $pdo->prepare($sqlOtherDuplicate);
                    $otherDuplicateParams = array_merge([':hdd_serial' => $hddSerial], $ownerParamsBase);
                    $stmtOtherDuplicate->execute($otherDuplicateParams);
                    $otherDuplicate = $stmtOtherDuplicate->fetch(PDO::FETCH_ASSOC);
                    if ($otherDuplicate) {
                        throw new Exception('Serial HDD นี้มีรายการรับคืนที่ยังไม่ปิดงานของผู้ใช้งานอื่น เลขที่ ' . $otherDuplicate['return_no'] . ' สถานะ ' . claimStatusText($otherDuplicate['status']));
                    }
                }

                $initialStatus = $actionType === 'scrap' ? 'scrapped' : 'preparing_claim';
                $claimReasonText = $actionType === 'scrap' ? 'ตีชำรุด' : 'ส่งเคลม';
                if ($claimReason !== '') {
                    $claimReasonText .= ' - ' . $claimReason;
                }

                if ($duplicate && cleanText($duplicate['status'] ?? '') !== 'waiting_return') {
                    throw new Exception('Serial HDD นี้มีรายการรับคืนที่ยังไม่ปิดงานอยู่แล้ว เลขที่ ' . $duplicate['return_no'] . ' สถานะ ' . claimStatusText($duplicate['status']));
                }

                if ($duplicate && cleanText($duplicate['status'] ?? '') === 'waiting_return') {
                    $returnNo = (string)$duplicate['return_no'];

                    $stmt = $pdo->prepare("
                        UPDATE harddisk_claim_returns
                        SET delivery_request_id = :delivery_request_id,
                            request_no = :request_no,
                            main_branch_code = :main_branch_code,
                            branch_code = :branch_code,
                            branch_name = :branch_name,
                            claim_reason = :claim_reason,
                            hdd_condition = :hdd_condition,
                            return_tracking_no = :return_tracking_no,
                            received_by = :received_by,
                            received_at = NOW(),
                            status = :status,
                            remark = :remark,
                            updated_by = :updated_by,
                            updated_at = NOW()
                        WHERE id = :id
                          AND deleted_at IS NULL
                        LIMIT 1
                    ");

                    $stmt->execute([
                        ':delivery_request_id' => $deliveryRequestId > 0 ? $deliveryRequestId : null,
                        ':request_no' => $requestNo !== '' ? $requestNo : null,
                        ':main_branch_code' => $mainBranchCode,
                        ':branch_code' => $branchCode,
                        ':branch_name' => $branchName,
                        ':claim_reason' => $claimReasonText,
                        ':hdd_condition' => $hddCondition !== '' ? $hddCondition : null,
                        ':return_tracking_no' => $returnTrackingNo !== '' ? $returnTrackingNo : null,
                        ':received_by' => $loginName,
                        ':status' => $initialStatus,
                        ':remark' => $remark !== '' ? $remark : null,
                        ':updated_by' => $loginName,
                        ':id' => (int)$duplicate['id'],
                    ]);
                } else {
                    $returnNo = generateReturnNo($pdo);

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
                }

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

if ($ownerWhereSql !== '1 = 1') {
    $where[] = $ownerWhereSql;
    $params = array_merge($params, $ownerParamsBase);
}

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

/*
|--------------------------------------------------------------------------
| Report Export: CSV / Excel / PDF print view
|--------------------------------------------------------------------------
| ใช้เงื่อนไขค้นหาเดียวกับหน้าจอ และส่งออกข้อมูลทั้งหมดตามตัวกรอง
| โดยไม่จำกัดเฉพาะข้อมูลในหน้าปัจจุบัน
*/
$exportType = strtolower(cleanText($_GET['export'] ?? ''));
if (in_array($exportType, ['csv', 'excel', 'pdf'], true)) {
    require_login();
    require_permission('claim.view');

    $stmtExport = $pdo->prepare("\n        SELECT\n            return_no, request_no, main_branch_code, branch_code, branch_name,\n            hdd_serial, claim_reason, hdd_condition, return_tracking_no,\n            status, received_by, received_at, claim_result, remark\n        FROM harddisk_claim_returns\n        {$whereSql}\n        ORDER BY id DESC\n    ");
    bindParams($stmtExport, $params);
    $stmtExport->execute();
    $exportRows = $stmtExport->fetchAll(PDO::FETCH_ASSOC);

    $reportRows = [];
    foreach ($exportRows as $index => $row) {
        $reportRows[] = [
            'ลำดับ' => $index + 1,
            'เลขที่รับคืน' => cleanText($row['return_no'] ?? '') ?: '-',
            'เลขที่คำขอ' => cleanText($row['request_no'] ?? '') ?: '-',
            'รหัสสาขาใหญ่' => ($code = formatMainBranchCode($row['main_branch_code'] ?? '')) !== '' ? $code : '-',
            'Cost Center' => cleanText($row['branch_code'] ?? '') ?: '-',
            'ชื่อสาขา' => cleanText($row['branch_name'] ?? '') ?: '-',
            'Serial HDD' => cleanText($row['hdd_serial'] ?? '') ?: '-',
            'สาเหตุ' => cleanText($row['claim_reason'] ?? '') ?: '-',
            'สภาพ HDD' => cleanText($row['hdd_condition'] ?? '') ?: '-',
            'เลขพัสดุส่งคืน' => cleanText($row['return_tracking_no'] ?? '') ?: '-',
            'สถานะ' => claimStatusText($row['status'] ?? ''),
            'ผู้รับคืน' => cleanText($row['received_by'] ?? '') ?: '-',
            'วันที่บันทึกข้อมูล' => formatThaiDateTime($row['received_at'] ?? ''),
            'ผลการเคลม' => cleanText($row['claim_result'] ?? '') ?: '-',
            'หมายเหตุ' => cleanText($row['remark'] ?? '') ?: '-',
        ];
    }

    $headers = ['ลำดับ', 'เลขที่รับคืน', 'เลขที่คำขอ', 'รหัสสาขาใหญ่', 'Cost Center', 'ชื่อสาขา', 'Serial HDD', 'สาเหตุ', 'สภาพ HDD', 'เลขพัสดุส่งคืน', 'สถานะ', 'ผู้รับคืน', 'วันที่บันทึกข้อมูล', 'ผลการเคลม', 'หมายเหตุ'];
    $fileBase = 'claim_return_report_' . date('Ymd_His');

    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers);
        foreach ($reportRows as $reportRow) {
            fputcsv($output, array_values($reportRow));
        }
        fclose($output);
        exit;
    }

    if ($exportType === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.xls"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo "\xEF\xBB\xBF";
        ?><!doctype html><html lang="th"><head><meta charset="utf-8"><style>
        body{font-family:Tahoma,Arial,sans-serif;font-size:10pt}table{border-collapse:collapse;width:100%}th,td{border:1px solid #777;padding:5px;vertical-align:top}th{background:#dbeafe;color:#0f172a;font-weight:bold}.text{mso-number-format:"\\@"}
        </style></head><body>
        <h2>รายงานรับคืน HDD ส่งเคลมจากสาขา</h2>
        <div>วันที่ออกรายงาน: <?= h(date('d/m/Y H:i')) ?> | จำนวน <?= number_format(count($reportRows)) ?> รายการ</div><br>
        <table><thead><tr><?php foreach ($headers as $column): ?><th><?= h($column) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($reportRows as $reportRow): ?><tr><?php foreach ($reportRow as $key => $value): ?><td class="<?= in_array($key, ['เลขที่รับคืน','เลขที่คำขอ','รหัสสาขาใหญ่','Cost Center','Serial HDD','เลขพัสดุส่งคืน'], true) ? 'text' : '' ?>"><?= h((string)$value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></body></html><?php
        exit;
    }

    ?><!doctype html>
    <html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายงานรับคืน HDD ส่งเคลมจากสาขา</title>
    <style>
    @page{size:A4 landscape;margin:7mm}*{box-sizing:border-box}body{font-family:"Noto Sans Thai",Tahoma,Arial,sans-serif;color:#111827;margin:0;font-size:8px}.report-toolbar{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:10px}.report-toolbar button,.report-toolbar a{border:0;border-radius:6px;padding:7px 12px;text-decoration:none;font-weight:700;cursor:pointer}.print-btn{background:#2563eb;color:#fff}.back-btn{background:#e5e7eb;color:#111827}h1{font-size:17px;margin:0 0 3px}.meta{color:#475569;margin-bottom:8px}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #94a3b8;padding:3px 4px;vertical-align:top;overflow-wrap:anywhere}th{background:#dbeafe;font-weight:800;text-align:left}th:nth-child(1),td:nth-child(1){width:3%;text-align:center}@media print{.report-toolbar{display:none}thead{display:table-header-group}tr{page-break-inside:avoid}}
    </style></head><body>
    <div class="report-toolbar"><div><strong>ตัวอย่างรายงาน PDF</strong><div>กด “พิมพ์ / บันทึก PDF” แล้วเลือก Save as PDF</div></div><div><a class="back-btn" href="index.php">กลับ</a> <button class="print-btn" type="button" onclick="window.print()">พิมพ์ / บันทึก PDF</button></div></div>
    <h1>รายงานรับคืน HDD ส่งเคลมจากสาขา</h1>
    <div class="meta">วันที่ออกรายงาน: <?= h(date('d/m/Y H:i')) ?> | จำนวน <?= number_format(count($reportRows)) ?> รายการ</div>
    <table><thead><tr><?php foreach ($headers as $column): ?><th><?= h($column) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php if (!$reportRows): ?><tr><td colspan="15" style="text-align:center;padding:20px">ไม่พบข้อมูลตามเงื่อนไขที่เลือก</td></tr><?php endif; ?>
    <?php foreach ($reportRows as $reportRow): ?><tr><?php foreach ($reportRow as $value): ?><td><?= h((string)$value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table>
    <script>window.addEventListener('load',function(){setTimeout(function(){window.print();},300);});</script>
    </body></html><?php
    exit;
}

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
    'waiting_return' => 0,
    'received' => 0,
    'sent_claim' => 0,
    'closed' => 0,
    'total' => 0,
];

$summary['today'] = countClaimsForCurrentUser($pdo, $ownerWhereSql, $ownerParamsBase, "DATE(received_at) = CURDATE() AND status <> 'waiting_return'");
$summary['waiting_return'] = countClaimsForCurrentUser($pdo, $ownerWhereSql, $ownerParamsBase, "status = 'waiting_return'");
$summary['received'] = countClaimsForCurrentUser($pdo, $ownerWhereSql, $ownerParamsBase, "status IN ('received', 'preparing_claim')");
$summary['sent_claim'] = countClaimsForCurrentUser($pdo, $ownerWhereSql, $ownerParamsBase, "status = 'sent_claim'");
$summary['closed'] = countClaimsForCurrentUser($pdo, $ownerWhereSql, $ownerParamsBase, "status IN ('claim_approved', 'claim_rejected', 'returned_stock', 'scrapped')");
$summary['total'] = countClaimsForCurrentUser($pdo, $ownerWhereSql, $ownerParamsBase);

$pageTitle = 'รับคืน HDD ส่งเคลมจากสาขา';
require_once __DIR__ . '/../../includes/header.php';


require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_permission('claim.manage');
} else {
    require_permission('claim.view');
}

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
    'waiting_return' => 'รอรับคืนจากสาขา',
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
    .action-card {
        cursor: pointer;
        transition: all .18s ease;
        min-height: 112px;
        border-width: 2px;
        position: relative;
        overflow: hidden;
    }
    .action-card:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(37,99,235,.08); }
    .action-card.claim-card-option {
        background: #eff6ff;
        border-color: #bfdbfe;
    }
    .action-card.scrap-card-option {
        background: #fff1f2;
        border-color: #fecaca;
    }
    .action-card.claim-card-option.is-selected {
        background: #dbeafe;
        border-color: #2563eb;
        box-shadow: 0 0 0 4px rgba(37, 99, 235, .12);
    }
    .action-card.scrap-card-option.is-selected {
        background: #fee2e2;
        border-color: #dc2626;
        box-shadow: 0 0 0 4px rgba(220, 38, 38, .12);
    }
    .select-badge {
        position: absolute;
        top: 10px;
        right: 10px;
        border-radius: 999px;
        padding: 3px 8px;
        font-size: 11px;
        font-weight: 900;
        background: #e2e8f0;
        color: #475569;
    }
    .action-card.is-selected .select-badge {
        background: #16a34a;
        color: #ffffff;
    }
    .action-icon { font-size: 18px; line-height: 1; }
    .detail-panel { background: #ffffff; border: 1px dashed #dbeafe; border-radius: 14px; padding: 12px; }
    .help-box { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 8px 10px; font-size: 12px; }
    .status-form { min-width: 190px; }
    .claim-status-cell { text-align: center; white-space: nowrap; }
    .claim-status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        gap: 5px;
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        font-size: 11px;
        font-weight: 900;
        line-height: 1.2;
        text-align: center;
        white-space: nowrap;
    }
    .claim-status-alert-icon {
        display: inline-block;
        font-size: 9px;
        line-height: 1;
        animation: claimStatusBlink 1.1s ease-in-out infinite;
    }
    @keyframes claimStatusBlink {
        0%, 100% { opacity: 1; transform: scale(1); }
        50% { opacity: .25; transform: scale(.78); }
    }
    @media (prefers-reduced-motion: reduce) {
        .claim-status-alert-icon { animation: none; }
    }
    .claim-status-waiting-return { color: #d97706; }
    .claim-status-received { color: #0891b2; }
    .claim-status-preparing { color: #ea580c; }
    .claim-status-sent { color: #2563eb; }
    .claim-status-success { color: #15803d; }
    .claim-status-danger { color: #dc2626; }
    .claim-status-cancelled { color: #475569; }
    .claim-status-default { color: #64748b; }
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

    /* Unified page design based on IT system registry */
    .unified-hero{background:linear-gradient(135deg,#0b3c68,#1769aa);border-radius:18px;padding:18px 20px;color:#fff;box-shadow:0 12px 30px rgba(15,76,129,.18);margin-bottom:14px}
    .unified-hero h1{font-size:1.35rem;font-weight:800;margin:0 0 4px;line-height:1.2;color:#fff}
    .unified-hero p{font-size:.86rem;margin:0;opacity:.88}
    .unified-hero-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
    .unified-total{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.26);padding:.45rem .75rem;border-radius:999px;font-size:.78rem;white-space:nowrap}
    .unified-hero .btn{border-radius:10px;font-size:.78rem;font-weight:800;white-space:nowrap;padding:.48rem .72rem}
    .unified-search-card{background:#fff;border:0;border-radius:16px;box-shadow:0 5px 18px rgba(20,46,70,.07);overflow:hidden}
    .unified-search-card .card-header{background:#fff;border-bottom:1px solid #e5ebf0;padding:11px 14px;font-weight:800;color:#17324d}
    .unified-search-card .card-body{padding:13px 14px}
    .unified-search-card .step-box{background:transparent;border:0;padding:0;border-radius:0}
    .unified-search-card .step-title{display:none}
    .unified-search-card .form-label{font-size:.72rem;font-weight:800;color:#5f6f7e;margin-bottom:4px}
    .unified-search-card .form-control,.unified-search-card .form-select{min-height:38px;font-size:.76rem;border-radius:10px}
    .unified-search-card .btn{min-height:38px;border-radius:10px;font-size:.75rem;font-weight:800}
    .unified-action-modal .modal-content{border:0;border-radius:16px;overflow:hidden;box-shadow:0 18px 55px rgba(15,23,42,.24)}
    .unified-action-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff);padding:12px 16px}
    .unified-action-modal .modal-body{padding:16px;background:#f8fafc}
    .unified-edit-frame{width:100%;height:min(72vh,680px);border:0;border-radius:10px;background:#fff}
    .claim-report-table{font-size:.78rem}.claim-report-table th{width:190px;background:#f1f5f9;color:#475569;font-weight:800;white-space:nowrap}.claim-report-table th,.claim-report-table td{padding:.5rem .65rem}.claim-report-table .form-control,.claim-report-table .form-select{min-height:36px;font-size:.76rem}
    @media(max-width:767.98px){.claim-report-table th{width:135px;white-space:normal}.claim-report-table th,.claim-report-table td{padding:.42rem .5rem}}
    @media(max-width:1366px){.unified-hero{padding:15px 17px}.unified-hero h1{font-size:1.15rem}.unified-hero p{font-size:.75rem}.unified-search-card .card-body{padding:10px 12px}.unified-search-card .form-control,.unified-search-card .form-select,.unified-search-card .btn{min-height:34px;font-size:.7rem}}
    @media(max-width:767.98px){.unified-hero{padding:14px}.unified-hero-actions{width:100%;justify-content:flex-start}.unified-hero .btn{flex:1 1 auto}.unified-edit-frame{height:78vh}}

    /* Registry-style claim return page */
    .claim-page{width:100%;max-width:none;margin:0;padding:0 0 20px}
    .claim-registry-hero{
        background:linear-gradient(135deg,#0b3c68,#1769aa);
        border-radius:18px;
        padding:20px 22px;
        color:#fff;
        box-shadow:0 12px 30px rgba(15,76,129,.18);
        margin-bottom:14px;
    }
    .claim-registry-hero h1{font-size:1.35rem;font-weight:800;margin:0 0 5px;color:#fff}
    .claim-registry-hero p{font-size:.86rem;margin:0;opacity:.88}
    .claim-registry-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
    .claim-registry-total{
        display:inline-flex;align-items:center;gap:6px;
        background:rgba(255,255,255,.15);
        border:1px solid rgba(255,255,255,.26);
        padding:.45rem .75rem;border-radius:999px;
        font-size:.78rem;white-space:nowrap
    }
    .claim-registry-hero .btn{border-radius:10px;font-size:.78rem;font-weight:800;white-space:nowrap}
    .claim-add-btn{
        position:relative;
        overflow:hidden;
        isolation:isolate;
        color:#0f4c81!important;
        padding:.5rem .85rem!important;
        box-shadow:0 8px 22px rgba(0,188,212,.28);
        transform-origin:center;
        animation:claimAddButtonPulse 1.8s ease-in-out infinite;
        transition:transform .18s ease,box-shadow .18s ease,filter .18s ease;
    }
    .claim-add-btn::before{
        content:"";
        position:absolute;
        top:-70%;
        left:-55%;
        width:38%;
        height:240%;
        background:linear-gradient(90deg,transparent,rgba(255,255,255,.85),transparent);
        transform:rotate(18deg);
        animation:claimAddButtonShine 2.4s ease-in-out infinite;
        pointer-events:none;
        z-index:-1;
    }
    .claim-add-btn:hover,
    .claim-add-btn:focus{
        transform:translateY(-2px) scale(1.02);
        box-shadow:0 12px 28px rgba(0,188,212,.38);
        filter:brightness(1.04);
    }
    .claim-add-btn:active{
        transform:translateY(0) scale(.99);
    }
    @keyframes claimAddButtonPulse{
        0%,100%{transform:scale(1);box-shadow:0 8px 22px rgba(0,188,212,.24)}
        50%{transform:scale(1.035);box-shadow:0 0 0 5px rgba(0,188,212,.14),0 12px 28px rgba(0,188,212,.34)}
    }
    @keyframes claimAddButtonShine{
        0%,20%{left:-55%;opacity:0}
        35%{opacity:1}
        58%,100%{left:125%;opacity:0}
    }
    @media(prefers-reduced-motion:reduce){
        .claim-add-btn,
        .claim-add-btn::before{animation:none!important}
        .claim-add-btn{transition:none}
    }
    .claim-summary-strip{
        display:grid;
        grid-template-columns:repeat(4,minmax(0,1fr));
        gap:8px;
        margin-bottom:12px;
    }
    .claim-summary-item{
        background:#fff;border:1px solid #e2e8f0;border-radius:12px;
        padding:10px 12px;display:flex;align-items:center;justify-content:space-between;
        box-shadow:0 4px 14px rgba(15,23,42,.05)
    }
    .claim-summary-label{font-size:.72rem;font-weight:800;color:#64748b}
    .claim-summary-value{font-size:1.15rem;font-weight:900;color:#0f4c81}
    .claim-registry-card{
        background:#fff;border:0;border-radius:16px;
        box-shadow:0 5px 18px rgba(20,46,70,.07);
        overflow:hidden;
    }
    .claim-registry-card .card-header{
        background:#fff;border-bottom:1px solid #e5ebf0;
        padding:11px 14px;font-weight:800;color:#17324d;
        display:flex;align-items:center;justify-content:space-between;gap:10px;
    }
    .claim-registry-card .card-body{padding:13px 14px}
    .claim-search-form{
        background:#fff;border-radius:14px;
        border:1px solid #e2e8f0;
        padding:12px;margin-bottom:12px;
    }
    .claim-search-grid{
        display:grid;
        grid-template-columns:minmax(220px,2fr) repeat(3,minmax(120px,1fr)) 82px 90px 105px 88px;
        gap:8px;align-items:end;
    }
    .claim-search-grid .form-label{font-size:.72rem;font-weight:800;color:#5f6f7e;margin-bottom:4px}
    .claim-search-grid .form-control,
    .claim-search-grid .form-select,
    .claim-search-grid .btn{min-height:38px;font-size:.76rem;border-radius:10px}
    .claim-search-result{
        min-height:38px;border:1px solid #e2e8f0;border-radius:10px;background:#f8fafc;
        display:flex;align-items:center;justify-content:center;text-align:center;
        font-size:.7rem;color:#475569;padding:4px 6px
    }
    .claim-table-wrap{border:1px solid #e2e8f0;border-radius:12px;overflow-y:auto;overflow-x:hidden;max-height:calc(100vh - 330px);min-height:340px}
    .table-claim{width:100%;min-width:0;table-layout:fixed}
    .table-claim th{background:#f7f9fb;color:#52616f;font-size:.66rem;padding:.48rem .25rem;text-align:center}
    .table-claim td{font-size:.69rem;padding:.42rem .28rem;overflow:hidden}
    .table-claim th:nth-child(1){width:13%}
    .table-claim th:nth-child(2){width:14%}
    .table-claim th:nth-child(3){width:11%}
    .table-claim th:nth-child(4){width:17%}
    .table-claim th:nth-child(5){width:12%}
    .table-claim th:nth-child(6){width:11%}
    .table-claim th:nth-child(7){width:12%}
    .table-claim th:nth-child(8){width:10%}
    .table-claim .text-ellipsis{max-width:100%}
    .table-claim .status-form{min-width:0;width:100%}
    .table-claim .status-form .input-group{flex-wrap:nowrap}
    .table-claim .status-form .form-select{min-width:0;padding-left:.35rem;padding-right:1.35rem;font-size:.64rem}
    .table-claim .status-form .btn{padding:.25rem .4rem;font-size:.64rem;white-space:nowrap}
    .claim-create-modal .modal-dialog{max-width:1180px;width:calc(100% - 28px)}
    .claim-create-modal .modal-content{border:0;border-radius:18px;overflow:hidden;box-shadow:0 22px 60px rgba(15,23,42,.24)}
    .claim-create-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff);padding:12px 16px}
    .claim-create-modal .modal-body{background:#f8fafc;padding:14px;max-height:calc(100vh - 150px);overflow:auto}
    .claim-create-modal .claim-card{box-shadow:none;border:0;border-radius:0}
    .claim-create-modal .claim-card>.card-header{display:none}
    .claim-create-modal .claim-card>.card-body{padding:0;background:transparent}
    .claim-create-modal .step-box{padding:10px}
    .claim-create-modal .detail-panel{padding:10px}
    .claim-create-modal .action-card{min-height:96px}
    .claim-create-modal textarea.form-control{min-height:60px}
    @media(max-width:1366px){
        .claim-page{padding:0 0 20px}
        .claim-registry-hero{padding:15px 17px}
        .claim-registry-hero h1{font-size:1.15rem}
        .claim-registry-hero p{font-size:.75rem}
        .claim-search-grid{grid-template-columns:minmax(190px,2fr) repeat(3,minmax(105px,1fr)) 68px 78px 92px 78px;gap:6px}
        .claim-search-grid .form-control,.claim-search-grid .form-select,.claim-search-grid .btn{min-height:34px;font-size:.68rem}
        .claim-search-result{min-height:34px;font-size:.62rem}
        .claim-table-wrap{max-height:calc(100vh - 300px)}
        .table-claim th,.table-claim td{font-size:.62rem;padding:.36rem .22rem}
        .claim-status-badge{font-size:.64rem;gap:4px}
        .claim-status-alert-icon{font-size:8px}
        .claim-create-modal .modal-dialog{width:calc(100% - 16px);margin:.4rem auto}
        .claim-create-modal .modal-body{max-height:calc(100vh - 115px);padding:10px}
    }
    @media(max-width:1100px){
        .claim-table-wrap{overflow-x:auto}
        .table-claim{min-width:920px}
    }
    @media(max-width:899.98px){
        .claim-summary-strip{grid-template-columns:repeat(2,minmax(0,1fr))}
        .claim-search-grid{grid-template-columns:repeat(2,minmax(0,1fr))}
        .claim-search-keyword{grid-column:1/-1}
    }
    @media(max-width:767.98px){
        .claim-registry-actions{width:100%;justify-content:flex-start}
        .claim-registry-hero .btn{flex:1 1 auto}
        .claim-summary-strip{grid-template-columns:1fr 1fr}
        .claim-search-grid{grid-template-columns:1fr}
        .claim-search-keyword{grid-column:auto}
        .claim-create-modal .modal-dialog{width:auto;margin:.35rem}
    }


    /* Shared Harddisk Delivery module navigation */
    .hdd-module-menu {
        display:grid;
        grid-template-columns:repeat(6,minmax(0,1fr));
        gap:9px;
        margin:0 0 14px;
    }
    .hdd-module-menu-item {
        position:relative; min-width:0; min-height:78px; display:flex;
        align-items:center; gap:10px; padding:11px 12px;
        border:1px solid #dbe5ee; border-radius:14px; background:#fff;
        color:#334155; text-decoration:none;
        box-shadow:0 5px 16px rgba(15,23,42,.055);
        transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease,background .16s ease;
        overflow:hidden;
    }
    .hdd-module-menu-item:hover { color:#0f4c81; text-decoration:none; border-color:#93c5fd; box-shadow:0 9px 22px rgba(37,99,235,.12); transform:translateY(-1px); }
    .hdd-module-menu-item.active { color:#fff; border-color:#00acc1; background:linear-gradient(135deg,#0097a7 0%,#00bcd4 58%,#26c6da 100%); box-shadow:0 10px 24px rgba(0,188,212,.28); }
    .hdd-module-menu-icon { width:38px; height:38px; flex:0 0 38px; display:flex; align-items:center; justify-content:center; border-radius:11px; background:#e0f7fa; color:#00acc1; }
    .hdd-module-menu-item.active .hdd-module-menu-icon { background:rgba(255,255,255,.20); color:#fff; }
    .hdd-module-menu-icon svg { width:20px; height:20px; }
    .hdd-module-menu-item.active .hdd-module-menu-icon { background:rgba(255,255,255,.16); color:#fff; }
    .hdd-module-menu-content { min-width:0; }
    .hdd-module-menu-title { display:block; font-size:.76rem; line-height:1.25; font-weight:900; white-space:normal; }
    .hdd-module-menu-note { display:block; margin-top:3px; font-size:.63rem; line-height:1.2; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .hdd-module-menu-item.active .hdd-module-menu-note { color:rgba(255,255,255,.8); }
    .hdd-module-menu-count { position:absolute; top:7px; right:7px; min-width:22px; height:22px; padding:0 6px; border-radius:999px; display:flex; align-items:center; justify-content:center; background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; font-size:.63rem; font-weight:900; line-height:1; }
    .hdd-module-menu-item.active .hdd-module-menu-count { background:#fff; color:#00838f; border-color:rgba(255,255,255,.6); }
    @media(max-width:1366px){
        .hdd-module-menu{gap:7px}
        .hdd-module-menu-item{min-height:70px;padding:9px 8px;gap:7px}
        .hdd-module-menu-icon{width:32px;height:32px;flex-basis:32px;border-radius:9px}
        .hdd-module-menu-icon svg{width:17px;height:17px}
        .hdd-module-menu-title{font-size:.68rem}
        .hdd-module-menu-note{font-size:.57rem}
    }
    @media(max-width:1100px){.hdd-module-menu{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media(max-width:700px){.hdd-module-menu{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:420px){.hdd-module-menu{grid-template-columns:1fr}}


    /* Blink only the active menu of the current page */
    .hdd-module-menu-item.active.hdd-active-menu-blink {
        /* animation: hddActiveMenuBlink 1.4s ease-out infinite; */
        transform-origin: center;
        will-change: transform, box-shadow, filter;
    }
    @keyframes hddActiveMenuBlink {
        0%, 100% {
            transform: scale(1);
            box-shadow: 0 10px 24px rgba(0,188,212,.28);
            filter: brightness(1);
        }
        50% {
            transform: scale(1.025);
            box-shadow: 0 0 0 4px rgba(0,188,212,.22), 0 14px 30px rgba(0,151,167,.38);
            filter: brightness(1.18);
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .hdd-module-menu-item.active.hdd-active-menu-blink {
            animation: none;
        }
    }
</style>


<link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hdd-primary-action-button.css">

<div class="container-fluid claim-page">
    <nav class="hdd-module-menu" aria-label="เมนูระบบจัดส่งฮาร์ดดิส">
            <?php if (function_exists('can') && can('request.create')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/requests/create.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('request'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">บันทึกคำขอส่ง Harddisk</span><span class="hdd-module-menu-note">สร้างคำขอและยิงบาร์โค้ด</span></span>
                <?php if ($pendingScanCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($pendingScanCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('shipment.manage')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/requests/matched.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('confirm'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">รอยืนยันจัดส่ง</span><span class="hdd-module-menu-note">ตรวจสอบและยืนยันการส่ง</span></span>
                <?php if ($pendingShipmentConfirmCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($pendingShipmentConfirmCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('request.view')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/requests/index.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('list'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">รายการเบิก</span><span class="hdd-module-menu-note">ติดตามคำขอทั้งหมด</span></span>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('shipment.view')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/shipments/index.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('history'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">ประวัติการจัดส่ง</span><span class="hdd-module-menu-note">ดูรายการจัดส่งย้อนหลัง</span></span>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('inventory.view')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/inventory/index.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('warehouse'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">คลัง Harddisk</span><span class="hdd-module-menu-note">ตรวจสอบสต็อกพร้อมใช้งาน</span></span>
                <?php if ($availableInventoryCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($availableInventoryCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('claim.view')): ?>
            <a class="hdd-module-menu-item active hdd-active-menu-blink" href="<?php echo $baseUrl; ?>/modules/claim_returns/index.php" aria-current="page">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('return'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">รับคืน / ส่งเคลม</span><span class="hdd-module-menu-note">จัดการคืนและเคลม HDD</span></span>
                <?php if ($claimReturnPendingCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($claimReturnPendingCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
        </nav>

    <div class="claim-registry-hero d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div>
            <h1>รับคืน HDD ส่งเคลมจากสาขา</h1>
            <!-- <p>บันทึกรับคืน HDD จากสาขา ติดตามสถานะส่งเคลม และอัปเดตสถานะคลังอัตโนมัติ</p> -->
        </div>
        <div class="claim-registry-actions">
            <div class="claim-registry-total">ข้อมูลทั้งหมด <strong><?php echo number_format($summary['total']); ?> รายการ</strong></div>
            <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#claimReportModal">
                <i class="bi bi-file-earmark-bar-graph me-1"></i>ออกรายงาน
            </button>
            <!-- <a href="../inventory/index.php" class="btn btn-outline-light">คลัง HDD</a> -->
            <button type="button" class="btn btn-light claim-add-btn hdd-primary-action-btn" data-bs-toggle="modal" data-bs-target="#claimCreateModal">+ บันทึกรับคืน HDD</button>
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

    <div class="card hero-card mb-2 d-none">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold"></div>
                <div class="small opacity-75">เมื่อบันทึกรับคืน ระบบจะปรับสถานะ HDD ในคลังเป็น “ชำรุด” เพื่อกันนำไปใช้งานซ้ำ</div>
            </div>
            <div class="small">ผู้ใช้งานปัจจุบัน: <strong><?php echo h($loginName); ?></strong></div>
        </div>
    </div>

    <!-- <div class="claim-summary-strip">
        <div class="claim-summary-item"><span class="claim-summary-label">รอรับคืนจากสาขา</span><strong class="claim-summary-value"><?php echo number_format($summary['waiting_return']); ?></strong></div>
        <div class="claim-summary-item"><span class="claim-summary-label">รอส่งเคลม</span><strong class="claim-summary-value"><?php echo number_format($summary['received']); ?></strong></div>
        <div class="claim-summary-item"><span class="claim-summary-label">ส่งเคลมแล้ว</span><strong class="claim-summary-value"><?php echo number_format($summary['sent_claim']); ?></strong></div>
        <div class="claim-summary-item"><span class="claim-summary-label">ปิดงานแล้ว</span><strong class="claim-summary-value"><?php echo number_format($summary['closed']); ?></strong></div>
    </div> -->

    <div class="modal fade unified-action-modal" id="claimReportModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
        <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-bar-graph me-1 text-primary"></i>ออกรายงานรับคืน HDD ส่งเคลม</h5>
                        <div class="small text-muted mt-1">เลือกช่วงเวลาและรูปแบบไฟล์ที่ต้องการ</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <div class="table-responsive border rounded-3 bg-white">
                        <table class="table table-bordered align-middle mb-0 claim-report-table">
                            <tbody>
                                <tr><th>ช่วงรายงาน <span class="text-danger">*</span></th><td><select class="form-select" id="claim_report_period_type"><option value="day">ช่วงวัน</option><option value="month">ช่วงเดือน</option><option value="year">ช่วงปี</option></select></td></tr>
                                <tr id="claim_report_day_from_row"><th>ตั้งแต่วันที่ <span class="text-danger">*</span></th><td><input type="date" class="form-control" id="claim_report_day_from" value="<?= h(date('Y-m-d')) ?>"></td></tr>
                                <tr id="claim_report_day_to_row"><th>ถึงวันที่ <span class="text-danger">*</span></th><td><input type="date" class="form-control" id="claim_report_day_to" value="<?= h(date('Y-m-d')) ?>"></td></tr>
                                <tr id="claim_report_month_from_row" class="d-none"><th>ตั้งแต่เดือน <span class="text-danger">*</span></th><td><input type="month" class="form-control" id="claim_report_month_from" value="<?= h(date('Y-m')) ?>"></td></tr>
                                <tr id="claim_report_month_to_row" class="d-none"><th>ถึงเดือน <span class="text-danger">*</span></th><td><input type="month" class="form-control" id="claim_report_month_to" value="<?= h(date('Y-m')) ?>"></td></tr>
                                <tr id="claim_report_year_from_row" class="d-none"><th>ตั้งแต่ปี ค.ศ. <span class="text-danger">*</span></th><td><input type="number" class="form-control" id="claim_report_year_from" min="2000" max="2100" value="<?= h(date('Y')) ?>"></td></tr>
                                <tr id="claim_report_year_to_row" class="d-none"><th>ถึงปี ค.ศ. <span class="text-danger">*</span></th><td><input type="number" class="form-control" id="claim_report_year_to" min="2000" max="2100" value="<?= h(date('Y')) ?>"></td></tr>
                                <tr><th>รูปแบบรายงาน <span class="text-danger">*</span></th><td><select class="form-select" id="claim_report_format"><option value="excel">Excel</option><option value="pdf">PDF</option><option value="csv">CSV</option></select></td></tr>
                            </tbody>
                        </table>
                    </div>
                    <div class="alert alert-info py-2 mt-2 mb-0 small">ระบบจะใช้คำค้นหา สถานะ และสิทธิ์การมองเห็นข้อมูลของผู้ใช้งานปัจจุบันร่วมกับช่วงวันที่ที่เลือก</div>
                </div>
                <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button><button type="button" class="btn btn-primary" id="claim_report_submit"><i class="bi bi-download me-1"></i>ออกรายงาน</button></div>
            </div>
        </div>
    </div>

    <div class="modal fade claim-create-modal" id="claimCreateModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
        <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
            <div class="modal-content">
                <div class="modal-header">
                    <div>
                        <h5 class="modal-title fw-bold">บันทึกรับคืน HDD จากสาขา</h5>
                        <div class="small text-muted mt-1">ยิงบาร์โค้ด ตรวจสอบสาขา เลือกการดำเนินการ และบันทึกในหน้าเดียว</div>
                    </div>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
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
                                            <div class="selected-branch-box action-card claim-card-option" data-action="claim">
                                                <span class="select-badge">ยังไม่เลือก</span>
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
                                            <div class="selected-branch-box action-card scrap-card-option" data-action="scrap">
                                                <span class="select-badge">ยังไม่เลือก</span>
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


                </div>
            </div>
        </div>
    </div>

    <div class="page-section">
        <div class="card claim-registry-card">
            <!-- <div class="card-header">
                <span>รายการรับคืน HDD ส่งเคลม</span>
                <span class="small text-muted">ทั้งหมด <?php echo number_format($totalRows); ?> รายการ</span>
            </div> -->
            <div class="card-body">
                <div class="card claim-card unified-search-card hdd-search-card mb-2 no-print">
                    <div class="card-body">
                        <form method="get" class="claim-search-grid hdd-unified-search-row hdd-fields-7">
                    <div class="claim-search-keyword hdd-search-keyword">
                        <label class="form-label">ช่องค้นหา</label>
                        <input type="text" name="keyword" class="form-control" value="<?php echo h($keyword); ?>" placeholder="เลขที่รับคืน, เลขที่คำขอ, รหัสสาขา, ชื่อสาขา หรือ Serial HDD">
                    </div>
                    <div>
                        <label class="form-label">วันที่เริ่มต้น</label>
                        <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                    </div>
                    <div>
                        <label class="form-label">วันที่สิ้นสุด</label>
                        <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                    </div>
                    <div>
                        <label class="form-label">สถานะ</label>
                        <select name="status" class="form-select">
                            <?php foreach ($statuses as $value => $label): ?>
                                <option value="<?php echo h($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label class="form-label">แสดง</label>
                        <select name="per_page" class="form-select">
                            <?php foreach ([10, 20, 50, 100] as $n): ?>
                                <option value="<?php echo $n; ?>" <?php echo $perPage === $n ? 'selected' : ''; ?>><?php echo $n; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="claim-search-action">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">ค้นหา</button>
                    </div>
                    <div class="claim-search-action">
                        <label class="form-label">&nbsp;</label>
                        <a href="index.php" class="btn btn-outline-secondary w-100">ล้างค่า</a>
                    </div>
                </form>
                    </div>
                </div>

                <div class="d-flex justify-content-between align-items-center mb-2 small text-muted flex-wrap gap-1">
                    <div>หน้า <?php echo number_format($page); ?> / <?php echo number_format($totalPages); ?></div>
                    <div>แสดง <?php echo number_format(count($claimRows)); ?> รายการของคุณ</div>
                </div>

                <div class="table-responsive claim-table-wrap">
                    <table class="table table-hover table-bordered align-middle mb-0 table-claim">
                        <thead>
                            <tr>
                                <th>เลขที่รับคืน</th>
                                <th>สาขา</th>
                                <th>Serial HDD</th>
                                <th>สาเหตุ/สภาพ</th>
                                <th>สถานะ</th>
                                <th>ผู้รับคืน</th>
                                <th>วันที่บันทึกข้อมูล</th>
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
                                        <td class="claim-status-cell"><?php echo claimStatusBadge($row['status']); ?></td>
                                        <td><div class="text-ellipsis" title="<?php echo h($row['received_by']); ?>"><?php echo h($row['received_by']); ?></div></td>
                                        <td><?php echo h(formatThaiDateTime($row['received_at'])); ?></td>
                                        <td>
                                            <form method="post" class="status-form" data-confirm-message="ยืนยันการปรับสถานะรายการนี้หรือไม่?">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
    const reportPeriodType = document.getElementById('claim_report_period_type');
    const reportRows = {
        day: [document.getElementById('claim_report_day_from_row'), document.getElementById('claim_report_day_to_row')],
        month: [document.getElementById('claim_report_month_from_row'), document.getElementById('claim_report_month_to_row')],
        year: [document.getElementById('claim_report_year_from_row'), document.getElementById('claim_report_year_to_row')]
    };
    function updateClaimReportFields() {
        const type = reportPeriodType ? reportPeriodType.value : 'day';
        Object.keys(reportRows).forEach(function(key){
            reportRows[key].forEach(function(row){ if (row) row.classList.toggle('d-none', key !== type); });
        });
    }
    if (reportPeriodType) {
        reportPeriodType.addEventListener('change', updateClaimReportFields);
        updateClaimReportFields();
    }
    const reportSubmit = document.getElementById('claim_report_submit');
    if (reportSubmit) reportSubmit.addEventListener('click', function(){
        const type = reportPeriodType ? reportPeriodType.value : 'day';
        const format = document.getElementById('claim_report_format')?.value || 'excel';
        let dateFrom = '';
        let dateTo = '';
        if (type === 'day') {
            dateFrom = document.getElementById('claim_report_day_from')?.value || '';
            dateTo = document.getElementById('claim_report_day_to')?.value || '';
            if (!dateFrom || !dateTo) { alert('กรุณาเลือกวันที่เริ่มต้นและวันที่สิ้นสุด'); return; }
        } else if (type === 'month') {
            const monthFrom = document.getElementById('claim_report_month_from')?.value || '';
            const monthTo = document.getElementById('claim_report_month_to')?.value || '';
            if (!/^\d{4}-\d{2}$/.test(monthFrom) || !/^\d{4}-\d{2}$/.test(monthTo)) { alert('กรุณาเลือกเดือนเริ่มต้นและเดือนสิ้นสุด'); return; }
            dateFrom = monthFrom + '-01';
            const toParts = monthTo.split('-');
            const lastDay = new Date(Number(toParts[0]), Number(toParts[1]), 0).getDate();
            dateTo = monthTo + '-' + String(lastDay).padStart(2, '0');
        } else {
            const yearFrom = String(document.getElementById('claim_report_year_from')?.value || '').trim();
            const yearTo = String(document.getElementById('claim_report_year_to')?.value || '').trim();
            if (!/^\d{4}$/.test(yearFrom) || !/^\d{4}$/.test(yearTo)) { alert('กรุณาระบุปี ค.ศ. เริ่มต้นและสิ้นสุด 4 หลัก'); return; }
            dateFrom = yearFrom + '-01-01';
            dateTo = yearTo + '-12-31';
        }
        if (dateFrom > dateTo) { alert('ช่วงเวลาเริ่มต้นต้องไม่มากกว่าช่วงเวลาสิ้นสุด'); return; }
        const params = new URLSearchParams(window.location.search);
        params.delete('page');
        params.set('date_from', dateFrom);
        params.set('date_to', dateTo);
        params.set('export', format);
        const url = 'index.php?' + params.toString();
        if (format === 'pdf') window.open(url, '_blank', 'noopener');
        else window.location.href = url;
    });
    <?php if (!empty($errors) && $_SERVER['REQUEST_METHOD'] === 'POST' && cleanText($_POST['form_action'] ?? 'create') !== 'update_status'): ?>
    const claimCreateModalElement = document.getElementById('claimCreateModal');
    if (claimCreateModalElement && window.bootstrap) {
        bootstrap.Modal.getOrCreateInstance(claimCreateModalElement).show();
    }
    <?php endif; ?>

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

    let successAudioContext = null;

    function playScanSuccessSound() {
        try {
            const AudioContextClass = window.AudioContext || window.webkitAudioContext;
            if (!AudioContextClass) {
                return;
            }

            if (!successAudioContext) {
                successAudioContext = new AudioContextClass();
            }

            if (successAudioContext.state === 'suspended') {
                successAudioContext.resume();
            }

            const now = successAudioContext.currentTime;
            const notes = [880, 1175];

            notes.forEach(function (frequency, index) {
                const startAt = now + (index * 0.12);
                const oscillator = successAudioContext.createOscillator();
                const gain = successAudioContext.createGain();

                oscillator.type = 'sine';
                oscillator.frequency.setValueAtTime(frequency, startAt);

                gain.gain.setValueAtTime(0.0001, startAt);
                gain.gain.exponentialRampToValueAtTime(0.18, startAt + 0.015);
                gain.gain.exponentialRampToValueAtTime(0.0001, startAt + 0.11);

                oscillator.connect(gain);
                gain.connect(successAudioContext.destination);
                oscillator.start(startAt);
                oscillator.stop(startAt + 0.12);
            });
        } catch (error) {
            // ไม่ต้องแจ้ง error หาก Browser ไม่อนุญาตให้เล่นเสียง
        }
    }

    function getSelectedActionValue() {
        return document.querySelector('input[name="action_type"]:checked')?.value || 'claim';
    }

    function getSelectedActionText() {
        return getSelectedActionValue() === 'scrap' ? 'ตีชำรุด' : 'ส่งเคลม';
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
        actionCards.forEach(function (card) {
            card.classList.remove('is-selected');
            const badge = card.querySelector('.select-badge');
            if (badge) {
                badge.textContent = 'ยังไม่เลือก';
            }
        });

        actionRadios.forEach(function (radio) {
            if (radio.checked) {
                const card = radio.closest('label').querySelector('.action-card');
                if (card) {
                    card.classList.add('is-selected');
                    const badge = card.querySelector('.select-badge');
                    if (badge) {
                        badge.textContent = 'เลือกอยู่';
                    }
                }
            }
        });

        const action = getSelectedActionValue();
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
                playScanSuccessSound();
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
            return;
        }

        const selectedActionText = getSelectedActionText();
        const confirmMessage =
            'ยืนยันการบันทึกรับคืน HDD ใช่หรือไม่?\n\n' +
            'Serial HDD: ' + clean(hddSerialInput.value) + '\n' +
            'สาขา: ' + (clean(branchNameInput.value) || '-') + ' (' + (clean(branchCodeInput.value) || '-') + ')\n' +
            'เลขที่คำขอ: ' + (clean(requestNoInput.value) || '-') + '\n' +
            'การดำเนินการของ IT: ' + selectedActionText + '\n\n' +
            'กรุณาตรวจสอบให้ถูกต้องก่อนกดยืนยัน';

        if (!confirm(confirmMessage)) {
            event.preventDefault();
        }
    });
});
</script>


<div class="modal fade unified-action-modal" id="unifiedConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><div><h5 class="modal-title fw-bold">ยืนยันการดำเนินการ</h5><div class="small text-muted mt-1">กรุณาตรวจสอบข้อมูลก่อนดำเนินการ</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="alert alert-warning mb-0" id="unifiedConfirmMessage">ยืนยันการดำเนินการนี้หรือไม่?</div></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button type="button" class="btn btn-danger" id="unifiedConfirmSubmit">ยืนยัน</button></div>
    </div>
  </div>
</div>
<div class="modal fade unified-action-modal" id="unifiedEditModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><div><h5 class="modal-title fw-bold">แก้ไขข้อมูล</h5><div class="small text-muted mt-1">แก้ไขข้อมูลโดยไม่ออกจากหน้ารายการ</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><iframe id="unifiedEditFrame" class="unified-edit-frame" title="แก้ไขข้อมูล"></iframe></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button></div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
  let pendingForm=null;
  const confirmEl=document.getElementById('unifiedConfirmModal');
  const confirmMsg=document.getElementById('unifiedConfirmMessage');
  const confirmBtn=document.getElementById('unifiedConfirmSubmit');
  document.querySelectorAll('form[data-confirm-message]').forEach(function(form){
    form.addEventListener('submit',function(ev){
      if(form.dataset.confirmed==='1') return;
      ev.preventDefault(); pendingForm=form;
      if(confirmMsg) confirmMsg.textContent=form.dataset.confirmMessage||'ยืนยันการดำเนินการนี้หรือไม่?';
      if(confirmEl&&window.bootstrap) bootstrap.Modal.getOrCreateInstance(confirmEl).show();
    });
  });
  if(confirmBtn) confirmBtn.addEventListener('click',function(){
    if(!pendingForm) return;
    pendingForm.dataset.confirmed='1';
    if(confirmEl&&window.bootstrap) bootstrap.Modal.getOrCreateInstance(confirmEl).hide();
    pendingForm.requestSubmit ? pendingForm.requestSubmit() : pendingForm.submit();
  });
  document.querySelectorAll('a[data-edit-popup]').forEach(function(link){
    link.addEventListener('click',function(ev){
      ev.preventDefault();
      const frame=document.getElementById('unifiedEditFrame');
      const modal=document.getElementById('unifiedEditModal');
      if(frame) frame.src=link.href;
      if(modal&&window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
    });
  });
  const editModal=document.getElementById('unifiedEditModal');
  if(editModal) editModal.addEventListener('hidden.bs.modal',function(){
    const frame=document.getElementById('unifiedEditFrame'); if(frame) frame.src='';
    if(editModal.dataset.refreshOnClose==='1') location.reload();
  });
});
</script>




<style>
/* Content-height tables: height follows the actual number of rows */
.scan-main-panel .scan-queue,
.matched-card .table-scroll,
.request-card .table-wrap,
.shipment-card .table-scroll,
.inventory-card .table-scroll,
.claim-card .claim-table-wrap,
.claim-table-wrap,
.js-footer-fit-table,
.hdd-footer-fit-table,
.hdd-footer-anchor-table {
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow-y: visible !important;
}

.scan-main-panel .scan-queue,
.matched-card .table-scroll,
.request-card .table-wrap,
.shipment-card .table-scroll,
.inventory-card .table-scroll,
.claim-card .claim-table-wrap,
.claim-table-wrap {
    overflow-x: auto !important;
}

/* Sticky headers are disabled because the table no longer has an internal vertical scroll area. */
.scan-main-panel .scan-queue th,
.matched-card .table-scroll th,
.request-card .table-wrap th,
.shipment-card .table-scroll th,
.inventory-card .table-scroll th,
.claim-card .claim-table-wrap th,
.claim-table-wrap th {
    position: static !important;
}
</style>

<style>
/* Search menu matched to Shipment History */
.claim-registry-card .card-body{padding:12px}
.claim-registry-card .unified-search-card{background:#fff;border:0;border-radius:16px;box-shadow:0 5px 18px rgba(20,46,70,.07);overflow:hidden}
.claim-registry-card .unified-search-card .card-body{padding:13px 14px}
.claim-search-grid{
    display:grid;
    grid-template-columns:minmax(260px,1fr) minmax(125px,150px) minmax(125px,150px) minmax(125px,150px) minmax(72px,90px) minmax(86px,100px) minmax(86px,100px);
    gap:8px;
    align-items:end;
    width:100%;
    max-width:100%;
}
.claim-search-grid>div{min-width:0;max-width:100%}
.claim-search-grid .form-control,
.claim-search-grid .form-select,
.claim-search-grid .btn{width:100%;max-width:100%;height:38px;min-height:38px;border-radius:10px;font-size:.76rem}
.claim-search-grid .form-label{display:block;font-size:.72rem;font-weight:800;color:#5f6f7e;margin-bottom:4px;white-space:nowrap}
.claim-search-form{border:0;padding:0;margin:0;background:transparent}
@media(max-width:1366px){
    .claim-registry-card .unified-search-card .card-body{padding:10px 12px}
    .claim-search-grid{grid-template-columns:minmax(210px,1.55fr) minmax(112px,.78fr) minmax(112px,.78fr) minmax(105px,.72fr) minmax(64px,.42fr) minmax(74px,.5fr) minmax(74px,.5fr);gap:5px}
    .claim-search-grid .form-control,
    .claim-search-grid .form-select,
    .claim-search-grid .btn{height:34px;min-height:34px;font-size:.68rem;padding-left:.42rem;padding-right:.42rem}
    .claim-search-grid .form-label{font-size:.64rem;margin-bottom:3px}
}
@media(max-width:767.98px){
    .claim-registry-card .unified-search-card .card-body{overflow-x:auto}
    .claim-search-grid{min-width:760px}
}
</style>


<!-- HDD_GLOBAL_MODAL_LAYER_FIX_V2 -->
<style>
html body > .modal { position: fixed !important; z-index: 2147483000 !important; }
html body > .modal.show { display: block !important; }
html body > .modal-backdrop { position: fixed !important; z-index: 2147482990 !important; }
html body.modal-open { overflow: hidden !important; }
</style>
<script>
(function () {
    'use strict';
    function moveModalToBody(modal) {
        if (modal && modal.classList && modal.classList.contains('modal') && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        }
    }
    function normalizeAllModals() { document.querySelectorAll('.modal').forEach(moveModalToBody); }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', normalizeAllModals);
    } else {
        normalizeAllModals();
    }
    document.addEventListener('show.bs.modal', function (event) { moveModalToBody(event.target); }, true);
    document.addEventListener('shown.bs.modal', function (event) {
        moveModalToBody(event.target);
        if (event.target) event.target.style.zIndex = '2147483000';
        document.querySelectorAll('body > .modal-backdrop').forEach(function (backdrop) {
            backdrop.style.zIndex = '2147482990';
        });
    }, true);
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<style>

/* Unified single-row search menu copied from Shipment History */
.hdd-search-card{background:#fff;border:0;border-radius:16px;box-shadow:0 5px 18px rgba(20,46,70,.07);overflow:hidden}
.hdd-search-card .card-body{padding:13px 14px}
.hdd-unified-search-row{display:grid;align-items:end;gap:8px;width:100%;max-width:100%}
.hdd-unified-search-row>div{min-width:0;max-width:100%}
.hdd-unified-search-row .form-label{display:block;font-size:.72rem;font-weight:800;color:#5f6f7e;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hdd-unified-search-row .form-control,.hdd-unified-search-row .form-select,.hdd-unified-search-row .btn{width:100%;max-width:100%;height:38px;min-height:38px;border-radius:10px;font-size:.76rem}
.hdd-unified-search-row .hdd-search-keyword{min-width:0}
.hdd-unified-search-row.hdd-fields-7{grid-template-columns:minmax(260px,1fr) repeat(3,minmax(125px,150px)) minmax(72px,90px) minmax(86px,100px) minmax(86px,100px)}
.hdd-unified-search-row.hdd-fields-6{grid-template-columns:minmax(260px,1.35fr) minmax(120px,.72fr) minmax(240px,1.15fr) minmax(82px,.48fr) minmax(82px,.48fr)}
.hdd-unified-search-row.hdd-fields-5{grid-template-columns:minmax(300px,1fr) minmax(140px,.55fr) minmax(110px,.42fr) minmax(90px,.34fr) minmax(90px,.34fr)}
.hdd-unified-search-row.hdd-fields-8{grid-template-columns:minmax(240px,1.45fr) minmax(115px,.7fr) minmax(115px,.7fr) minmax(115px,.7fr) minmax(65px,.38fr) minmax(80px,.48fr) minmax(92px,.52fr) minmax(80px,.48fr)}
.hdd-search-date-pair{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:5px}
.hdd-search-actions{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:5px}
@media(max-width:1366px){
 .hdd-search-card .card-body{padding:10px 12px}
 .hdd-unified-search-row{gap:5px}
 .hdd-unified-search-row .form-control,.hdd-unified-search-row .form-select,.hdd-unified-search-row .btn{height:34px;min-height:34px;font-size:.68rem;padding-left:.42rem;padding-right:.42rem}
 .hdd-unified-search-row .form-label{font-size:.64rem;margin-bottom:3px}
 .hdd-unified-search-row.hdd-fields-7{grid-template-columns:minmax(210px,1.55fr) minmax(112px,.78fr) minmax(112px,.78fr) minmax(105px,.72fr) minmax(64px,.42fr) minmax(74px,.5fr) minmax(74px,.5fr)}
 .hdd-unified-search-row.hdd-fields-6{grid-template-columns:minmax(210px,1.35fr) minmax(105px,.62fr) minmax(215px,1.18fr) minmax(78px,.42fr) minmax(78px,.42fr)}
 .hdd-unified-search-row.hdd-fields-5{grid-template-columns:minmax(250px,1.6fr) minmax(120px,.72fr) minmax(88px,.48fr) minmax(76px,.42fr) minmax(76px,.42fr)}
 .hdd-unified-search-row.hdd-fields-8{grid-template-columns:minmax(200px,1.4fr) minmax(100px,.66fr) minmax(100px,.66fr) minmax(100px,.66fr) minmax(58px,.36fr) minmax(72px,.44fr) minmax(84px,.48fr) minmax(72px,.44fr)}
}
@media(max-width:767.98px){.hdd-search-card .card-body{overflow-x:auto}.hdd-unified-search-row{min-width:760px}}

</style>

<style>
/* Search menu aligned with Shipment History */
.claim-search-grid.hdd-fields-7{grid-template-columns:minmax(260px,1fr) repeat(3,minmax(125px,150px)) minmax(72px,90px) minmax(86px,100px) minmax(86px,100px)!important;gap:8px!important}
@media(max-width:1366px){.claim-search-grid.hdd-fields-7{grid-template-columns:minmax(210px,1.55fr) minmax(112px,.78fr) minmax(112px,.78fr) minmax(105px,.72fr) minmax(64px,.42fr) minmax(74px,.5fr) minmax(74px,.5fr)!important;gap:5px!important}}
@media(max-width:767.98px){.claim-search-grid.hdd-fields-7{min-width:760px}}
</style>
<style id="hdd-six-page-status-style">
/* HDD 6 pages: unified rectangular status colors */
.scan-status-badge,
.duplicate-status-badge.status-pending-scan,
.hdd-status-badge.hdd-status-pending_scan,
.hdd-status-badge.hdd-status-pending {
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    min-width:92px!important;padding:5px 9px!important;border:1px solid #dc2626!important;
    border-radius:4px!important;background:#dc2626!important;color:#fff!important;
    box-shadow:none!important;font-weight:800!important;line-height:1.15!important;white-space:nowrap!important;
}
.status-pill,
.duplicate-status-badge.status-matched,
.hdd-status-badge.hdd-status-matched {
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    min-width:92px!important;padding:5px 9px!important;border:1px solid #0d6efd!important;
    border-radius:4px!important;background:#0d6efd!important;color:#fff!important;
    box-shadow:none!important;font-weight:800!important;line-height:1.15!important;white-space:nowrap!important;
}
.duplicate-status-badge.status-shipped,
.hdd-status-badge.hdd-status-shipped,
.shipment-status-badge.shipment-status-shipped,
.inventory-status-badge.inventory-status-shipped {
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    min-width:92px!important;padding:5px 9px!important;border:1px solid #198754!important;
    border-radius:4px!important;background:#198754!important;color:#fff!important;
    box-shadow:none!important;font-weight:800!important;line-height:1.15!important;white-space:nowrap!important;
}
@media(max-width:1366px){
    .scan-status-badge,.duplicate-status-badge.status-pending-scan,.duplicate-status-badge.status-matched,
    .duplicate-status-badge.status-shipped,.status-pill,.hdd-status-badge.hdd-status-pending_scan,
    .hdd-status-badge.hdd-status-pending,.hdd-status-badge.hdd-status-matched,.hdd-status-badge.hdd-status-shipped,
    .shipment-status-badge.shipment-status-shipped,.inventory-status-badge.inventory-status-shipped{
        min-width:84px!important;padding:4px 7px!important;
    }
}
</style>

