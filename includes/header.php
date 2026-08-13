<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$appTimezone = 'Asia/Bangkok';
date_default_timezone_set($appTimezone);

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}

$functionsFile = __DIR__ . '/functions.php';
if (file_exists($functionsFile)) {
    require_once $functionsFile;
}

$permissionsFile = __DIR__ . '/permissions.php';
if (file_exists($permissionsFile)) {
    require_once $permissionsFile;
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/
$currentUserDisplayName = '-';

if (function_exists('get_current_user_full_name') && isset($pdo) && $pdo instanceof PDO) {
    $currentUserDisplayName = trim((string)get_current_user_full_name($pdo));
}

if ($currentUserDisplayName === '') {
    $currentUserDisplayName = trim((string)($_SESSION['full_name'] ?? ''));
}

if ($currentUserDisplayName === '') {
    $currentUserDisplayName = trim((string)($_SESSION['employee_code'] ?? ''));
}

if ($currentUserDisplayName === '') {
    $currentUserDisplayName = '-';
}

/*
|--------------------------------------------------------------------------
| Header Helpers
|--------------------------------------------------------------------------
*/
if (!function_exists('headerTableExists')) {
    function headerTableExists(PDO $pdo, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM INFORMATION_SCHEMA.TABLES\n                WHERE TABLE_SCHEMA = DATABASE()\n                  AND TABLE_NAME = :table_name\n            ");
            $stmt->execute([':table_name' => $tableName]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('headerColumnExists')) {
    function headerColumnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        try {
            $stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM INFORMATION_SCHEMA.COLUMNS\n                WHERE TABLE_SCHEMA = DATABASE()\n                  AND TABLE_NAME = :table_name\n                  AND COLUMN_NAME = :column_name\n            ");
            $stmt->execute([
                ':table_name' => $tableName,
                ':column_name' => $columnName,
            ]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}


if (!function_exists('headerGetCurrentUserProfile')) {
    function headerGetCurrentUserProfile(PDO $pdo, string $fallbackName): array
    {
        $employeeCode = trim((string)($_SESSION['employee_code'] ?? $_SESSION['emp_code'] ?? $_SESSION['employee_id'] ?? ''));
        if ($employeeCode === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            $employeeCode = trim((string)($_SESSION['user']['employee_code'] ?? $_SESSION['user']['emp_code'] ?? $_SESSION['user']['employee_id'] ?? ''));
        }

        $profile = [
            'employee_code' => $employeeCode !== '' ? $employeeCode : '-',
            'full_name' => $fallbackName !== '' ? $fallbackName : '-',
            'position' => '',
        ];

        if (!headerTableExists($pdo, 'users') || !headerColumnExists($pdo, 'users', 'employee_code') || $employeeCode === '') {
            return $profile;
        }

        try {
            $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
            $columnsStmt->execute();
            $columns = [];
            foreach ($columnsStmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
                $columns[strtolower((string)$column)] = (string)$column;
            }

            $select = ['employee_code'];
            foreach (['first_name', 'last_name', 'full_name', 'name', 'position', 'position_name', 'job_title', 'employee_position', 'title'] as $candidate) {
                if (isset($columns[$candidate])) {
                    $select[] = '`' . str_replace('`', '``', $columns[$candidate]) . '`';
                }
            }

            $stmt = $pdo->prepare('SELECT ' . implode(', ', array_unique($select)) . ' FROM `users` WHERE `employee_code` = :employee_code LIMIT 1');
            $stmt->execute([':employee_code' => $employeeCode]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!is_array($user)) {
                return $profile;
            }

            $firstName = trim((string)($user['first_name'] ?? ''));
            $lastName = trim((string)($user['last_name'] ?? ''));
            $resolvedName = trim($firstName . ' ' . $lastName);
            if ($resolvedName === '') {
                foreach (['full_name', 'name'] as $field) {
                    $value = trim((string)($user[$field] ?? ''));
                    if ($value !== '') {
                        $resolvedName = $value;
                        break;
                    }
                }
            }
            if ($resolvedName !== '') {
                $profile['full_name'] = $resolvedName;
            }

            foreach (['position', 'position_name', 'job_title', 'employee_position', 'title'] as $field) {
                $value = trim((string)($user[$field] ?? ''));
                if ($value !== '') {
                    $profile['position'] = $value;
                    break;
                }
            }
        } catch (Throwable $e) {
            // Keep session-based profile data if optional profile lookup fails.
        }

        return $profile;
    }
}

if (!function_exists('headerBuildDeletedWhere')) {
    function headerBuildDeletedWhere(PDO $pdo, string $tableName, array &$where): void
    {
        if (headerColumnExists($pdo, $tableName, 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }
    }
}

if (!function_exists('headerCountDeliveryRequests')) {
    function headerCountDeliveryRequests(PDO $pdo, array $statuses, $requestedBy = null): int
    {
        $tableName = 'harddisk_delivery_requests';
        if (!headerTableExists($pdo, $tableName)) {
            return 0;
        }

        $where = [];
        headerBuildDeletedWhere($pdo, $tableName, $where);

        if (!empty($statuses) && headerColumnExists($pdo, $tableName, 'status')) {
            $quotedStatuses = array_map(static function ($status) use ($pdo) {
                return $pdo->quote($status);
            }, $statuses);
            $where[] = 'status IN (' . implode(',', $quotedStatuses) . ')';
        }

        $params = [];
        if (headerColumnExists($pdo, $tableName, 'requested_by')) {
            if (is_array($requestedBy)) {
                $requestedBy = array_values(array_filter(array_map('trim', array_map('strval', $requestedBy)), static function ($value) {
                    return $value !== '' && $value !== '-';
                }));
                if (!empty($requestedBy)) {
                    $where[] = headerAddInCondition($params, $requestedBy, 'TRIM(requested_by)', 'header_requested_by');
                }
            } elseif ($requestedBy !== null && $requestedBy !== '' && $requestedBy !== '-') {
                $where[] = 'TRIM(requested_by) = :requested_by';
                $params[':requested_by'] = $requestedBy;
            }
        }

        $sql = 'SELECT COUNT(*) FROM ' . $tableName;
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('headerCountInventoryByStatus')) {
    function headerCountInventoryByStatus(PDO $pdo, string $status): int
    {
        $tableName = 'harddisk_inventory';
        if (!headerTableExists($pdo, $tableName) || !headerColumnExists($pdo, $tableName, 'status')) {
            return 0;
        }

        $where = ['status = :status'];
        headerBuildDeletedWhere($pdo, $tableName, $where);

        try {
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM ' . $tableName . ' WHERE ' . implode(' AND ', $where));
            $stmt->execute([':status' => $status]);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('headerCountPendingDrumRequests')) {
    function headerCountPendingDrumRequests(PDO $pdo): int
    {
        $tableName = 'drum_withdrawals';
        if (!headerTableExists($pdo, $tableName)) {
            return 0;
        }

        $where = [];
        headerBuildDeletedWhere($pdo, $tableName, $where);

        if (headerColumnExists($pdo, $tableName, 'delivery_status')) {
            $where[] = "COALESCE(delivery_status, 'pending') = 'pending'";
        }

        $countExpression = headerColumnExists($pdo, $tableName, 'request_no')
            ? "COUNT(DISTINCT NULLIF(TRIM(request_no), ''))"
            : 'COUNT(*)';

        $sql = 'SELECT ' . $countExpression . ' FROM ' . $tableName;
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        try {
            return (int)$pdo->query($sql)->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('headerCountShipmentsToday')) {
    function headerCountShipmentsToday(PDO $pdo): int
    {
        $tableName = 'harddisk_shipments';
        if (!headerTableExists($pdo, $tableName)) {
            return 0;
        }

        $dateColumn = null;
        foreach (['shipped_at', 'shipped_date', 'delivery_date', 'created_at'] as $column) {
            if (headerColumnExists($pdo, $tableName, $column)) {
                $dateColumn = $column;
                break;
            }
        }

        if ($dateColumn === null) {
            return 0;
        }

        $where = ["DATE({$dateColumn}) = CURDATE()"];
        headerBuildDeletedWhere($pdo, $tableName, $where);

        if (headerColumnExists($pdo, $tableName, 'status')) {
            $where[] = "status = 'shipped'";
        }

        try {
            $stmt = $pdo->query('SELECT COUNT(*) FROM ' . $tableName . ' WHERE ' . implode(' AND ', $where));
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (!function_exists('headerCurrentUserIdentifiers')) {
    function headerCurrentUserIdentifiers(string $currentUserDisplayName): array
    {
        $identifiers = [];
        $add = static function ($value) use (&$identifiers): void {
            $value = trim((string)($value ?? ''));
            if ($value !== '' && $value !== '-' && !in_array($value, $identifiers, true)) {
                $identifiers[] = $value;
            }
        };

        $add($currentUserDisplayName);

        $fullName = trim((string)($_SESSION['full_name'] ?? ''));
        if ($fullName === '') {
            $fullName = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
        }
        $employeeCode = trim((string)($_SESSION['employee_code'] ?? ''));

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
}

if (!function_exists('headerAddInCondition')) {
    function headerAddInCondition(array &$params, array $values, string $column, string $prefix): string
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
}

if (!function_exists('headerBuildClaimOwnerWhere')) {
    function headerBuildClaimOwnerWhere(PDO $pdo, string $tableName, array $identifiers, array &$params): string
    {
        if (empty($identifiers)) {
            return '1 = 1';
        }

        $conditions = [];

        foreach (['created_by', 'updated_by', 'received_by'] as $column) {
            if (headerColumnExists($pdo, $tableName, $column)) {
                $conditions[] = headerAddInCondition($params, $identifiers, $column, 'claim_' . $column);
            }
        }

        if (
            $tableName === 'harddisk_claim_returns'
            && headerColumnExists($pdo, $tableName, 'delivery_request_id')
            && headerTableExists($pdo, 'harddisk_delivery_requests')
            && headerColumnExists($pdo, 'harddisk_delivery_requests', 'id')
        ) {
            $requestUserConditions = [];

            if (headerColumnExists($pdo, 'harddisk_delivery_requests', 'requested_by')) {
                $requestUserConditions[] = headerAddInCondition($params, $identifiers, 'requested_by', 'claim_req_requested_by');
            }

            if (headerColumnExists($pdo, 'harddisk_delivery_requests', 'created_by')) {
                $requestUserConditions[] = headerAddInCondition($params, $identifiers, 'created_by', 'claim_req_created_by');
            }

            if (!empty($requestUserConditions)) {
                $requestWhere = '(' . implode(' OR ', $requestUserConditions) . ')';
                if (headerColumnExists($pdo, 'harddisk_delivery_requests', 'deleted_at')) {
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
}

if (!function_exists('headerCountPendingClaimReturns')) {
    function headerCountPendingClaimReturns(PDO $pdo, string $tableName, array $userIdentifiers = []): int
    {
        if (!headerTableExists($pdo, $tableName)) {
            return 0;
        }

        $where = [];
        headerBuildDeletedWhere($pdo, $tableName, $where);

        if (headerColumnExists($pdo, $tableName, 'status')) {
            $where[] = "status IN ('waiting_return', 'received', 'preparing_claim', 'sent_claim')";
        }

        $params = [];
        $ownerWhere = headerBuildClaimOwnerWhere($pdo, $tableName, $userIdentifiers, $params);
        if ($ownerWhere !== '1 = 1') {
            $where[] = $ownerWhere;
        }

        $sql = 'SELECT COUNT(*) FROM ' . $tableName;
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}


if (!function_exists('headerGetUserRoleValue')) {
    function headerGetUserRoleValue(): string
    {
        $role = trim((string)($_SESSION['role'] ?? ''));
        if ($role === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            $role = trim((string)($_SESSION['user']['role'] ?? ''));
        }
        return $role !== '' ? $role : 'user';
    }
}

if (!function_exists('headerGetSessionValueByKeys')) {
    function headerGetSessionValueByKeys(array $keys): string
    {
        foreach ($keys as $key) {
            $value = trim((string)($_SESSION[$key] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            foreach ($keys as $key) {
                $value = trim((string)($_SESSION['user'][$key] ?? ''));
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }
}

if (!function_exists('headerEnsureOnlineUsersTable')) {
    function headerEnsureOnlineUsersTable(PDO $pdo): void
    {
        static $done = false;
        if ($done) {
            return;
        }

        $sql = "CREATE TABLE IF NOT EXISTS admin_online_users (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            session_id VARCHAR(128) NOT NULL,
            employee_code VARCHAR(50) DEFAULT NULL,
            username VARCHAR(120) DEFAULT NULL,
            full_name VARCHAR(255) DEFAULT NULL,
            role VARCHAR(80) DEFAULT NULL,
            ip_address VARCHAR(80) DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            current_url VARCHAR(500) DEFAULT NULL,
            first_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            last_seen_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_admin_online_users_session (session_id),
            KEY idx_admin_online_users_last_seen (last_seen_at),
            KEY idx_admin_online_users_employee (employee_code),
            KEY idx_admin_online_users_role (role)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        $pdo->exec($sql);
        $done = true;
    }
}

if (!function_exists('headerTrackOnlineUser')) {
    function headerTrackOnlineUser(PDO $pdo, string $currentUserDisplayName): void
    {
        try {
            $sessionId = session_id();
            if ($sessionId === '') {
                return;
            }

            $employeeCode = headerGetSessionValueByKeys(['employee_code', 'emp_code', 'employee_id', 'user_id']);
            $username = headerGetSessionValueByKeys(['username', 'login_name', 'email']);
            $role = headerGetUserRoleValue();
            $fullName = trim((string)$currentUserDisplayName);
            if ($fullName === '-' || $fullName === '') {
                $fullName = headerGetSessionValueByKeys(['full_name', 'name']);
            }

            if ($employeeCode === '' && $username === '' && ($fullName === '' || $fullName === '-')) {
                return;
            }

            headerEnsureOnlineUsersTable($pdo);

            $currentUrl = substr((string)($_SERVER['REQUEST_URI'] ?? ''), 0, 500);
            $userAgent = substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);
            $ipAddress = substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 80);

            $stmt = $pdo->prepare("INSERT INTO admin_online_users
                (session_id, employee_code, username, full_name, role, ip_address, user_agent, current_url, first_seen_at, last_seen_at)
                VALUES
                (:session_id, :employee_code, :username, :full_name, :role, :ip_address, :user_agent, :current_url, NOW(), NOW())
                ON DUPLICATE KEY UPDATE
                    employee_code = VALUES(employee_code),
                    username = VALUES(username),
                    full_name = VALUES(full_name),
                    role = VALUES(role),
                    ip_address = VALUES(ip_address),
                    user_agent = VALUES(user_agent),
                    current_url = VALUES(current_url),
                    last_seen_at = NOW()");
            $stmt->execute([
                ':session_id' => $sessionId,
                ':employee_code' => $employeeCode !== '' ? $employeeCode : null,
                ':username' => $username !== '' ? $username : null,
                ':full_name' => $fullName !== '' ? $fullName : null,
                ':role' => $role !== '' ? $role : null,
                ':ip_address' => $ipAddress !== '' ? $ipAddress : null,
                ':user_agent' => $userAgent !== '' ? $userAgent : null,
                ':current_url' => $currentUrl !== '' ? $currentUrl : null,
            ]);

            // Keep table compact. Older rows are kept for 1 day only.
            $pdo->exec("DELETE FROM admin_online_users WHERE last_seen_at < (NOW() - INTERVAL 1 DAY)");
        } catch (Throwable $e) {
            // Do not break normal pages if online tracking fails.
        }
    }
}

/*
|--------------------------------------------------------------------------
| Badge Counts
|--------------------------------------------------------------------------
*/
$dashboardAlertCount = 0;
$hddAlertCount = 0;
$myHddRequestCount = 0;
$pendingScanCount = 0;
$pendingShipmentConfirmCount = 0;
$shipmentTodayCount = 0;
$availableInventoryCount = 0;
$claimReturnPendingCount = 0;
$pendingDrumRequestCount = 0;

if (isset($pdo) && $pdo instanceof PDO) {
    $headerCurrentUserIdentifiers = headerCurrentUserIdentifiers($currentUserDisplayName);
    $myHddRequestCount = headerCountDeliveryRequests($pdo, [], $headerCurrentUserIdentifiers);
    $pendingScanCount = headerCountDeliveryRequests($pdo, ['pending_scan', 'pending']);
    $pendingShipmentConfirmCount = headerCountDeliveryRequests(
        $pdo,
        ['matched', 'reserved', 'pending_delivery', 'pending_ship', 'waiting_ship'],
        $headerCurrentUserIdentifiers
    );
    $shipmentTodayCount = headerCountShipmentsToday($pdo);
    $availableInventoryCount = headerCountInventoryByStatus($pdo, 'available');
    $claimReturnPendingCount = headerCountPendingClaimReturns($pdo, 'harddisk_claim_returns', $headerCurrentUserIdentifiers);
    $pendingDrumRequestCount = headerCountPendingDrumRequests($pdo);

    // Backward compatibility for old table name. Count it only when the main table is not found.
    if ($claimReturnPendingCount === 0 && headerTableExists($pdo, 'hdd_claim_returns') && !headerTableExists($pdo, 'harddisk_claim_returns')) {
        $claimReturnPendingCount = headerCountPendingClaimReturns($pdo, 'hdd_claim_returns', $headerCurrentUserIdentifiers);
    }

    // HDD main-menu badge: count only the HDD workflow pages. Drum is shown separately.
    $hddAlertCount = $pendingScanCount + $pendingShipmentConfirmCount + $claimReturnPendingCount;

    // Top bell: combine HDD alerts and the separately listed Drum alert.
    $dashboardAlertCount = $hddAlertCount + $pendingDrumRequestCount;
    headerTrackOnlineUser($pdo, $currentUserDisplayName);
}

/*
|--------------------------------------------------------------------------
| Active Menu Helper
|--------------------------------------------------------------------------
*/
$currentPath = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '');

if (!function_exists('isActiveMenu')) {
    function isActiveMenu(string $keyword): string
    {
        global $currentPath;
        return strpos($currentPath, $keyword) !== false ? 'active' : '';
    }
}

$pageTitle = $pageTitle ?? 'Harddisk Delivery System';
if (defined('BASE_URL')) {
    $baseUrl = rtrim((string)BASE_URL, '/');
} else {
    $headerScriptPath = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $baseUrl = '';
    foreach (['/modules/', '/public/'] as $headerMarker) {
        $headerPos = strpos($headerScriptPath, $headerMarker);
        if ($headerPos !== false) {
            $baseUrl = substr($headerScriptPath, 0, $headerPos);
            break;
        }
    }
    if ($baseUrl === '') {
        $baseUrl = '/harddisk_delivery_web';
    }
    $baseUrl = rtrim($baseUrl, '/');
}

$headerCanUserManageMenu = function_exists('can') && can('user.manage');
$headerCanPermissionManageMenu = function_exists('can') && can('permission.manage');
$headerCanBranchImportMenu = function_exists('can') && can('admin.branch_import');
$headerCanAssetImportMenu = function_exists('can') && can('admin.asset_import');
$headerCanOnlineUsersMenu = function_exists('can') && can('admin.online_users');
$headerCanAdminGroupMenu = $headerCanUserManageMenu || $headerCanPermissionManageMenu || $headerCanBranchImportMenu || $headerCanAssetImportMenu || $headerCanOnlineUsersMenu;

$headerUserProfile = (isset($pdo) && $pdo instanceof PDO)
    ? headerGetCurrentUserProfile($pdo, $currentUserDisplayName)
    : ['employee_code' => trim((string)($_SESSION['employee_code'] ?? '-')), 'full_name' => $currentUserDisplayName, 'position' => ''];
$headerUserPosition = trim((string)($headerUserProfile['position'] ?? ''));
if ($headerUserPosition === '') {
    $headerUserPosition = $headerCanAdminGroupMenu ? 'ผู้ดูแลระบบ' : 'ผู้ใช้งานระบบ';
}

$headerMenuPermissions = [
    'assets' => can('asset.view'),
    'servers' => can('server.view'),
    'it_systems' => can('it_system.view'),
    'license_software' => can('license_software.view'),
    'notebooks' => can('notebook.view'),
    'branch_labels' => can('branch_label.view'),
    'delete_computers' => can('delete_computer.view'),
    'keyboard_mouse' => can('keyboard_mouse.view'),
    'wcs_repair_quotes' => can('wcs_quote.view'),
    'delivery_logs' => can('delivery_log.view') || can('wcs_quote.view'),
    'drum_requests' => can('drum_request.view') || can('delivery_log.view') || can('wcs_quote.view'),
    'computer_external' => can('computer_external.view') || can('asset.view'),
    'request_create' => can('request.create'),
    'request_view' => can('request.view'),
    'shipment_manage' => can('shipment.manage'),
    'shipment_view' => can('shipment.view'),
    'inventory_view' => can('inventory.view'),
    'claim_view' => can('claim.view'),
];

$headerRepairGroupVisible = !empty(array_filter([
    $headerMenuPermissions['assets'], $headerMenuPermissions['servers'], $headerMenuPermissions['it_systems'],
    $headerMenuPermissions['license_software'], $headerMenuPermissions['notebooks'], $headerMenuPermissions['branch_labels'],
    $headerMenuPermissions['delete_computers'], $headerMenuPermissions['computer_external'],
    $headerMenuPermissions['keyboard_mouse'], $headerMenuPermissions['wcs_repair_quotes'], $headerMenuPermissions['delivery_logs'],
    $headerMenuPermissions['drum_requests']
]));
$headerHarddiskGroupVisible = !empty(array_filter([
    $headerMenuPermissions['request_create'], $headerMenuPermissions['request_view'], $headerMenuPermissions['shipment_manage'],
    $headerMenuPermissions['shipment_view'], $headerMenuPermissions['inventory_view'], $headerMenuPermissions['claim_view']
]));

$headerRepairGroupActive = (
    strpos($currentPath, '/modules/assets/') !== false
    || strpos($currentPath, '/modules/servers/') !== false
    || strpos($currentPath, '/modules/it_systems/') !== false
    || strpos($currentPath, '/modules/license_software/') !== false
    || strpos($currentPath, '/modules/notebooks/') !== false
    || strpos($currentPath, '/modules/branch_labels/') !== false
    || strpos($currentPath, '/modules/delete_computers/') !== false
    || strpos($currentPath, '/modules/serial_computer/') !== false
    || strpos($currentPath, '/modules/keyboard_mouse/') !== false
    || strpos($currentPath, '/modules/wcs_repair_quotes/') !== false
    || strpos($currentPath, '/modules/delivery_logs/') !== false
    || strpos($currentPath, '/modules/drum_requests/') !== false
    || strpos($currentPath, '/modules/new_branch_opening/') !== false
);
$headerHarddiskGroupActive = (
    strpos($currentPath, '/modules/dashboard/') !== false
    || strpos($currentPath, '/modules/requests/') !== false
    || strpos($currentPath, '/modules/shipments/') !== false
    || strpos($currentPath, '/modules/inventory/') !== false
    || strpos($currentPath, '/modules/claim_returns/') !== false
);
$headerAdminGroupActive = (
    strpos($currentPath, '/modules/admin/branch_import/') !== false
    || strpos($currentPath, '/modules/admin/asset_import/') !== false
    || strpos($currentPath, '/modules/admin/online_users/') !== false
    || strpos($currentPath, '/modules/admin/users/') !== false
    || strpos($currentPath, '/modules/admin/permissions/') !== false
);

if (!$headerRepairGroupActive && !$headerHarddiskGroupActive && !$headerAdminGroupActive) {
    $headerHarddiskGroupActive = true;
}
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?></title>

    <link href="<?php echo $baseUrl; ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/sb-admin-2/css/sb-admin-2.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/app.css?v=<?php echo (string)@filemtime(__DIR__ . '/../assets/css/app.css'); ?>" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/hdd-sb-admin-custom.css?v=<?php echo (string)@filemtime(__DIR__ . '/../assets/css/hdd-sb-admin-custom.css'); ?>" rel="stylesheet">

    <link href="<?php echo $baseUrl; ?>/assets/css/hdd-menu-redesign.css?v=<?php echo (string)@filemtime(__DIR__ . '/../assets/css/hdd-menu-redesign.css'); ?>" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/hdd-user-topbar-redesign.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/hdd-global-cyan-page-headers.css?v=<?php echo (string)@filemtime(__DIR__ . '/../assets/css/hdd-global-cyan-page-headers.css'); ?>" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/hdd-global-background.css?v=<?php echo (string)@filemtime(__DIR__ . '/../assets/css/hdd-global-background.css'); ?>" rel="stylesheet">

    <style>
        .hdd-notification-dropdown{position:relative;margin-right:10px;flex:0 0 auto}
        .hdd-notification-button{position:relative;width:46px;height:46px;border:1px solid #dbe3ec;border-radius:14px;background:#fff;color:#2563eb;display:flex;align-items:center;justify-content:center;box-shadow:0 4px 14px rgba(15,23,42,.08);transition:.2s}
        .hdd-notification-button:hover,.hdd-notification-button:focus{background:#eff6ff;border-color:#93c5fd;color:#1d4ed8}
        .hdd-notification-button svg{width:23px;height:23px}
        .hdd-notification-button.has-alert svg{
            transform-origin:50% 12%;
            animation:hddNotificationBellShake 1.8s linear infinite;
            will-change:transform;
        }
        @keyframes hddNotificationBellShake{
            0%,72%,100%{transform:rotate(0deg)}
            76%{transform:rotate(13deg)}
            80%{transform:rotate(-11deg)}
            84%{transform:rotate(9deg)}
            88%{transform:rotate(-7deg)}
            92%{transform:rotate(4deg)}
            96%{transform:rotate(-2deg)}
        }
        @media(prefers-reduced-motion:reduce){.hdd-notification-button.has-alert svg{animation:none}}
        .hdd-notification-count{position:absolute;top:-7px;right:-7px;min-width:23px;height:23px;padding:0 6px;border-radius:999px;background:#ef4444;color:#fff;border:2px solid #fff;font-size:.65rem;font-weight:900;display:flex;align-items:center;justify-content:center;line-height:1}
        .hdd-notification-menu{width:360px!important;min-width:360px!important;max-width:calc(100vw - 24px);padding:0!important;border:1px solid #dbe3ec;border-radius:14px;box-shadow:0 18px 45px rgba(15,23,42,.2);overflow:hidden;background:#fff}
        .hdd-notification-head{display:flex;align-items:center;justify-content:space-between;gap:14px;padding:11px 14px;background:#f8fafc;border-bottom:1px solid #e2e8f0;line-height:1.2}
        .hdd-notification-head strong{font-size:.88rem;color:#0f172a;white-space:nowrap}.hdd-notification-head span{font-size:.74rem;color:#64748b;font-weight:800;white-space:nowrap}
        .hdd-notification-item{display:grid!important;grid-template-columns:40px minmax(0,1fr) auto;align-items:center;column-gap:11px;width:100%;padding:10px 13px!important;color:#0f172a;text-decoration:none;border-bottom:1px solid #eef2f7;background:#fff;white-space:normal!important}
        .hdd-notification-item:last-child{border-bottom:0}.hdd-notification-item:hover{background:#f8fbff;color:#0f4c81;text-decoration:none}
        .hdd-notification-item-icon{width:40px;height:40px;border-radius:11px;display:flex;align-items:center;justify-content:center;flex:0 0 40px}.hdd-notification-item-icon svg{width:19px;height:19px;transform-origin:center;animation:hddNotificationItemIconShake 2.2s linear infinite;will-change:transform}
        @keyframes hddNotificationItemIconShake{
            0%,68%,100%{transform:translateX(0) rotate(0deg)}
            72%{transform:translateX(-1px) rotate(-7deg)}
            76%{transform:translateX(1px) rotate(7deg)}
            80%{transform:translateX(-1px) rotate(-5deg)}
            84%{transform:translateX(1px) rotate(5deg)}
            88%{transform:translateX(0) rotate(-2deg)}
            92%{transform:translateX(0) rotate(0deg)}
        }
        .hdd-notification-item:nth-of-type(2) .hdd-notification-item-icon svg{animation-delay:.18s}
        .hdd-notification-item:nth-of-type(3) .hdd-notification-item-icon svg{animation-delay:.36s}
        @media(prefers-reduced-motion:reduce){.hdd-notification-item-icon svg{animation:none}}
        .hdd-notification-item-icon.warning{background:#fff7d6;color:#d97706}.hdd-notification-item-icon.danger{background:#fee2e2;color:#dc2626}
        .hdd-notification-item-text{display:flex;flex-direction:column;justify-content:center;min-width:0;line-height:1.25}.hdd-notification-item-text strong{display:block;font-size:.79rem;font-weight:900;color:#0f172a;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.hdd-notification-item-text small{display:block;font-size:.68rem;color:#64748b;margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
        .hdd-notification-item-count{min-width:31px;height:27px;border-radius:999px;background:#eff6ff;color:#1d4ed8;font-size:.73rem;font-weight:900;display:flex;align-items:center;justify-content:center;padding:0 8px;line-height:1;white-space:nowrap}
        .hdd-notification-empty{padding:24px;text-align:center;color:#64748b;font-size:.76rem}
        @media(max-width:1366px){.hdd-notification-button{width:40px;height:40px;border-radius:12px}.hdd-notification-button svg{width:20px;height:20px}.hdd-notification-menu{width:340px!important;min-width:340px!important}}
        .hdd-nav-main-link{margin-top:4px}
        .hdd-nav-main-link.active{background:linear-gradient(135deg,rgba(37,99,235,.18),rgba(14,165,233,.12));color:#fff}
        @media(max-width:575.98px){.hdd-notification-menu{width:calc(100vw - 20px)!important;min-width:0!important}.hdd-notification-item{grid-template-columns:38px minmax(0,1fr) auto;padding:9px 11px!important}.hdd-notification-item-icon{width:38px;height:38px;flex-basis:38px}}


        .hdd-sidebar-user-card{width:100%;border:0;border-bottom:1px solid #dbe5ee;background:linear-gradient(135deg,#ecfeff 0%,#f0fdfa 55%,#ffffff 100%);padding:12px 10px 10px;display:flex;flex-direction:column;align-items:center;text-align:center;cursor:pointer;color:#0f172a;transition:background .18s ease,box-shadow .18s ease}
        .hdd-sidebar-user-card:hover,.hdd-sidebar-user-card:focus{background:linear-gradient(135deg,#cffafe 0%,#ecfeff 58%,#ffffff 100%);box-shadow:inset 0 -3px 0 #00bcd4;outline:0}
        .hdd-sidebar-user-avatar{width:58px;height:58px;border-radius:50%;object-fit:cover;background:#fff;border:2px solid #00bcd4;box-shadow:0 5px 14px rgba(0,188,212,.2);padding:2px}
        .hdd-sidebar-user-name{display:block;margin-top:7px;font-size:.76rem;line-height:1.3;font-weight:900;color:#0f172a;max-width:100%;overflow-wrap:anywhere}
        .hdd-sidebar-user-position{display:block;margin-top:2px;font-size:.66rem;line-height:1.25;color:#64748b;max-width:100%;overflow-wrap:anywhere}
        .hdd-sidebar-user-hint{display:block;margin-top:4px;font-size:.6rem;color:#0891b2;font-weight:800}
        .hdd-user-modal .modal-dialog{max-width:520px}
        .hdd-user-modal .modal-content{border:0;border-radius:18px;overflow:hidden;box-shadow:0 24px 65px rgba(15,23,42,.24)}
        .hdd-user-modal .modal-header{background:linear-gradient(135deg,#0097a7,#00bcd4 58%,#26c6da);color:#fff;border:0;padding:15px 18px}
        .hdd-user-modal .modal-title{font-size:1rem;font-weight:900}
        .hdd-user-modal .btn-close{filter:brightness(0) invert(1);opacity:.9}
        .hdd-user-modal .modal-body{padding:20px;background:#f8fafc}
        .hdd-user-modal-profile{display:flex;align-items:center;gap:16px;padding:16px;border:1px solid #dbe5ee;border-radius:15px;background:#fff}
        .hdd-user-modal-avatar{width:84px;height:84px;border-radius:50%;object-fit:cover;border:3px solid #00bcd4;padding:3px;background:#fff;flex:0 0 84px}
        .hdd-user-modal-name{font-size:1.02rem;font-weight:900;color:#0f172a;line-height:1.35}
        .hdd-user-modal-position{font-size:.82rem;color:#0891b2;font-weight:800;margin-top:3px}
        .hdd-user-detail-list{margin-top:14px;border:1px solid #dbe5ee;border-radius:14px;overflow:hidden;background:#fff}
        .hdd-user-detail-row{display:grid;grid-template-columns:130px minmax(0,1fr);gap:12px;padding:11px 14px;border-bottom:1px solid #eef2f7;align-items:center}
        .hdd-user-detail-row:last-child{border-bottom:0}.hdd-user-detail-label{font-size:.76rem;font-weight:900;color:#64748b}.hdd-user-detail-value{font-size:.84rem;font-weight:800;color:#0f172a;overflow-wrap:anywhere}
        body > .hdd-user-modal{z-index:2147483000!important}body > .modal-backdrop{z-index:2147482990!important}
        @media(max-width:1366px){.hdd-sidebar-user-card{padding:9px 8px 8px}.hdd-sidebar-user-avatar{width:50px;height:50px}.hdd-sidebar-user-name{font-size:.7rem}.hdd-sidebar-user-position{font-size:.61rem}}
        @media(max-width:767.98px){.hdd-sidebar-user-avatar{width:54px;height:54px}.hdd-user-modal .modal-dialog{margin:.75rem}.hdd-user-modal-profile{align-items:flex-start}.hdd-user-detail-row{grid-template-columns:105px minmax(0,1fr)}}

        .hdd-top-announcement{flex:1 1 auto;min-width:0;margin-right:14px;overflow:hidden;border:1px solid #b2ebf2;border-radius:12px;background:linear-gradient(135deg,#ecfeff 0%,#f0fdfa 58%,#ffffff 100%);box-shadow:0 4px 14px rgba(0,188,212,.10);position:relative}
        .hdd-top-announcement::before{content:"";position:absolute;left:0;top:0;bottom:0;width:5px;background:linear-gradient(180deg,#0097a7,#00bcd4,#26c6da);z-index:2}
        .hdd-top-announcement-track{display:flex;align-items:center;width:max-content;min-height:42px;padding-left:100%;animation:hddAnnouncementMarquee 42s linear infinite;will-change:transform}
        .hdd-top-announcement:hover .hdd-top-announcement-track{animation-play-state:paused}
        .hdd-top-announcement-text{display:inline-flex;align-items:center;gap:20px;padding:0 28px 0 20px;white-space:nowrap;font-size:.78rem;font-weight:800;color:#0f4c5c;line-height:1.35}
        .hdd-top-announcement-title{color:#dc2626;font-weight:900}
        .hdd-top-announcement-item{color:#334155}
        @keyframes hddAnnouncementMarquee{from{transform:translateX(0)}to{transform:translateX(-100%)}}
        @media(max-width:1366px){.hdd-top-announcement{margin-right:10px}.hdd-top-announcement-track{min-height:38px;animation-duration:46s}.hdd-top-announcement-text{font-size:.71rem;gap:16px;padding-right:22px}}
        @media(max-width:767.98px){.hdd-top-announcement{display:none}}
        @media(prefers-reduced-motion:reduce){.hdd-top-announcement-track{animation:none;padding-left:16px}.hdd-top-announcement{overflow:auto}}

        /* Keep notification and logout controls inside the topbar viewport. */
        .hdd-topbar{display:flex!important;align-items:center;gap:0;min-width:0;padding-left:16px!important;padding-right:16px!important;overflow:visible}
        .hdd-top-announcement{width:0;max-width:none}
        .hdd-top-user-area{display:flex!important;align-items:center;justify-content:flex-end;flex:0 0 auto;min-width:max-content;margin-left:0!important;padding-right:0!important}
        .hdd-notification-dropdown{margin-right:8px}
        .hdd-logout-redesign{display:inline-flex!important;align-items:center;justify-content:center;flex:0 0 42px;width:42px;min-width:42px;max-width:42px;height:42px;margin:0!important;position:relative;right:auto!important;transform:none!important;box-sizing:border-box}
        @media(max-width:1366px){.hdd-topbar{padding-left:12px!important;padding-right:12px!important}.hdd-logout-redesign{flex-basis:38px;width:38px;min-width:38px;max-width:38px;height:38px}.hdd-notification-dropdown{margin-right:6px}}
        @media(max-width:767.98px){.hdd-topbar{padding-left:8px!important;padding-right:8px!important}.hdd-top-user-area{margin-left:auto!important}}

        /* Sidebar menu text: use regular weight while preserving active colors and layout. */
        .hdd-sidebar .hdd-menu-text { font-weight: 400 !important; }
        .hdd-sidebar .hdd-nav-link.active .hdd-menu-text { font-weight: 400 !important; }
    </style>
    <!-- Global font: Sarabun (same family as Serial Computer) -->
    <link href="<?php echo $baseUrl; ?>/assets/css/hdd-sarabun-font.css?v=<?php echo (string)@filemtime(__DIR__ . '/../assets/css/hdd-sarabun-font.css'); ?>-sarabun" rel="stylesheet">
</head>
<body id="page-top" class="hdd-sb-admin hdd-modern-sidebar">
<?php
if (!function_exists('hddSidebarIcon')) {
    function hddSidebarIcon(string $name): string
    {
        $icons = [
            'brand' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="2.5" width="16" height="19" rx="3"></rect><circle cx="12" cy="9" r="3"></circle><path d="M8 18h8"></path></svg>',
            'repair' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M9 3h6l1 2h3a1 1 0 0 1 1 1v13a2 2 0 0 1-2 2H6a2 2 0 0 1-2-2V6a1 1 0 0 1 1-1h3l1-2Z"></path><path d="M9 11l2 2 4-4"></path><path d="M9 17h6"></path></svg>',
            'search' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><circle cx="11" cy="11" r="6.5"></circle><path d="m16 16 4.5 4.5"></path></svg>',
            'server' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="4" width="16" height="6" rx="2"></rect><rect x="4" y="14" width="16" height="6" rx="2"></rect><path d="M8 7h.01M8 17h.01"></path><path d="M12 7h4M12 17h4"></path></svg>',
            'it_system' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M3 9h18"></path><path d="M8 14h3M14 14h2M8 17h8"></path><circle cx="7" cy="6.5" r=".5" fill="currentColor" stroke="none"></circle></svg>',
            'license_software' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="16" rx="2"></rect><path d="M7 8h10M7 12h6"></path><circle cx="16.5" cy="15.5" r="2.5"></circle><path d="m18.2 17.3 1.8 1.8"></path></svg>',
            'windows' => '<svg viewBox="0 0 24 24" fill="currentColor"><path d="M3 5.2 10.9 4v7.3H3V5.2Zm8.9-1.4L21 2.5v8.8h-9.1V3.8ZM3 12.7h7.9V20L3 18.8v-6.1Zm8.9 0H21v8.8l-9.1-1.3v-7.5Z"></path></svg>',
            'branch' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V7l7-4 7 4v14"></path><path d="M9 21v-6h6v6"></path><path d="M8 10h.01M12 10h.01M16 10h.01"></path></svg>',
            'new_branch' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 21h18"></path><path d="M5 21V8l7-4 7 4v13"></path><path d="M9 21v-5h6v5"></path><path d="M17 3v6"></path><path d="M14 6h6"></path></svg>',
            'computer' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="12" rx="2"></rect><path d="M8 20h8"></path><path d="M12 16v4"></path></svg>',
            'desktop' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="2.5" y="4" width="11" height="12" rx="1.8"></rect><rect x="15.5" y="6" width="6" height="14" rx="1.8"></rect><path d="M8 20h3"></path><path d="M18.5 17h.01"></path></svg>',
            'keyboard_mouse' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="8" width="13" height="8" rx="2"></rect><rect x="17" y="6" width="5" height="12" rx="2.2"></rect><path d="M4.5 11h.01M7 11h.01M9.5 11h.01M12 11h.01M4.5 13.5h5.5M11.5 13.5h1.5"></path></svg>',
            'quotation' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M6 3h9l3 3v15H6z"></path><path d="M15 3v4h4"></path><path d="M9 11h6M9 15h6M9 19h4"></path></svg>',
            'delivery' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4-9 4-9-4Z"></path><path d="M3 7v10l9 4 9-4V7"></path><path d="M12 11v10"></path><path d="M7 9v4"></path></svg>',
            'harddisk' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 16V7a2 2 0 0 1 2-2h10l6 6v5a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2Z"></path><path d="M15 5v6h6"></path><circle cx="8" cy="15" r="1"></circle><circle cx="13" cy="15" r="1"></circle></svg>',
            'dashboard' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3a9 9 0 1 0 9 9"></path><path d="M12 3v9h9"></path></svg>',
            'request' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M14 3H7a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h10a2 2 0 0 0 2-2V8z"></path><path d="M14 3v5h5"></path><path d="M12 11v6"></path><path d="M9 14h6"></path></svg>',
            'barcode' => '<svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="4" width="1.8" height="16" rx=".4"></rect><rect x="6" y="4" width="1" height="16" rx=".3"></rect><rect x="8.5" y="4" width="2" height="16" rx=".4"></rect><rect x="12.2" y="4" width="1" height="16" rx=".3"></rect><rect x="14.4" y="4" width="1.8" height="16" rx=".4"></rect><rect x="17.2" y="4" width="1" height="16" rx=".3"></rect><rect x="19.4" y="4" width="1.6" height="16" rx=".4"></rect></svg>',
            'confirm' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"></rect><path d="M8 7h8"></path><path d="M9 12l2 2 4-4"></path></svg>',
            'list' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M8 6h12"></path><path d="M8 12h12"></path><path d="M8 18h12"></path><path d="M4 6h.01M4 12h.01M4 18h.01"></path></svg>',
            'history' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 4v5h5"></path><path d="M12 7v5l3 2"></path></svg>',
            'warehouse' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m3 10 9-6 9 6"></path><path d="M5 10v10h14V10"></path><path d="M9 20v-5h6v5"></path></svg>',
            'return' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M9 7H4v5"></path><path d="M20 17h-5v-5"></path><path d="M4 12a8 8 0 0 1 13.6-5.7L20 8"></path><path d="M20 12a8 8 0 0 1-13.6 5.7L4 16"></path></svg>',
            'admin' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 3l7 4v5c0 5-3.5 7.5-7 9-3.5-1.5-7-4-7-9V7l7-4Z"></path><circle cx="12" cy="11" r="2.5"></circle><path d="M18 18a5.6 5.6 0 0 0-12 0"></path></svg>',
            'upload' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V6"></path><path d="m8 10 4-4 4 4"></path><path d="M20 16.5v1.5A2 2 0 0 1 18 20H6a2 2 0 0 1-2-2v-1.5"></path></svg>',
            'users' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"></path><circle cx="9" cy="7" r="4"></circle><path d="M22 21v-2a4 4 0 0 0-3-3.87"></path><path d="M16 3.13a4 4 0 0 1 0 7.75"></path></svg>',
            'chevron' => '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="m6 9 6 6 6-6"></path></svg>',
        ];
        return $icons[$name] ?? $icons['search'];
    }
}
?>
<div id="wrapper" class="hdd-layout-wrapper">
    <aside class="hdd-sidebar" id="accordionSidebar" aria-label="Main menu">
        <button type="button" class="hdd-sidebar-user-card" data-bs-toggle="modal" data-bs-target="#hddUserProfileModal" aria-label="เปิดรายละเอียดผู้ใช้งาน">
            <img class="hdd-sidebar-user-avatar" src="<?php echo $baseUrl; ?>/assets/images/user-avatar.png" alt="รูปผู้ใช้งาน">
            <span class="hdd-sidebar-user-name">(<?php echo e($headerUserProfile['employee_code'] ?? '-'); ?>) <?php echo e($headerUserProfile['full_name'] ?? $currentUserDisplayName); ?></span>
            <span class="hdd-sidebar-user-position"><?php echo e($headerUserPosition); ?></span>
            <!-- <span class="hdd-sidebar-user-hint">คลิกเพื่อดูรายละเอียด</span> -->
        </button>

        <nav class="hdd-sidebar-nav">
            <div class="hdd-menu-section">เมนูหลัก</div>
            <?php if (!empty($headerMenuPermissions['assets'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/assets/'); ?>" href="<?php echo $baseUrl; ?>/modules/assets/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('search'); ?></span>
                <span class="hdd-menu-text">ค้นหาข้อมูลทรัพย์สิน</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if ($headerHarddiskGroupVisible): ?>
            <a class="hdd-nav-link hdd-nav-main-link <?php echo $headerHarddiskGroupActive ? 'active' : ''; ?>" href="<?php echo $baseUrl; ?>/modules/requests/create.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('harddisk'); ?></span>
                <span class="hdd-menu-text">เบิก Harddisk</span>
                <?php if ($hddAlertCount > 0): ?>
                    <span class="hdd-count hdd-count-warning hdd-count-blink" title="มีรายการในกระบวนการเบิก Harddisk ที่ต้องดำเนินการ <?php echo number_format($hddAlertCount); ?> รายการ"><?php echo number_format($hddAlertCount); ?></span>
                <?php endif; ?>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerMenuPermissions['branch_labels'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/branch_labels/'); ?>" href="<?php echo $baseUrl; ?>/modules/branch_labels/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('branch'); ?></span>
                <span class="hdd-menu-text">ค้นหาและพิมพ์ที่อยู่สาขา</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/new_branch_opening/'); ?>" href="<?php echo $baseUrl; ?>/modules/new_branch_opening/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('new_branch'); ?></span>
                <span class="hdd-menu-text">สาขาเปิดใหม่</span>
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>

            <?php if (!empty($headerMenuPermissions['delete_computers'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/delete_computers/'); ?>" href="<?php echo $baseUrl; ?>/modules/delete_computers/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('computer'); ?></span>
                <span class="hdd-menu-text">ลบชื่อเครื่อง Join Domain</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerMenuPermissions['computer_external'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/serial_computer/'); ?>" href="<?php echo $baseUrl; ?>/modules/serial_computer/show_sncom.php" target="_blank" rel="noopener noreferrer">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('desktop'); ?></span>
                <span class="hdd-menu-text">Serial Computer</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerMenuPermissions['keyboard_mouse'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/keyboard_mouse/'); ?>" href="<?php echo $baseUrl; ?>/modules/keyboard_mouse/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('keyboard_mouse'); ?></span>
                <span class="hdd-menu-text">Keyboard &amp; Mouse</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerMenuPermissions['servers'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/servers/'); ?>" href="<?php echo $baseUrl; ?>/modules/servers/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('server'); ?></span>
                <span class="hdd-menu-text">ข้อมูล Server</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerMenuPermissions['it_systems'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/it_systems/'); ?>" href="<?php echo $baseUrl; ?>/modules/it_systems/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('it_system'); ?></span>
                <span class="hdd-menu-text">ข้อมูลระบบไอทีสารสนเทศ</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerMenuPermissions['license_software'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/license_software/'); ?>" href="<?php echo $baseUrl; ?>/modules/license_software/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('license_software'); ?></span>
                <span class="hdd-menu-text">ข้อมูล License Software</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerMenuPermissions['notebooks'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/notebooks/'); ?>" href="<?php echo $baseUrl; ?>/modules/notebooks/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('windows'); ?></span>
                <span class="hdd-menu-text">ข้อมูล License Notebook</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <!-- <?php if (!empty($headerMenuPermissions['wcs_repair_quotes'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/wcs_repair_quotes/'); ?>" href="<?php echo $baseUrl; ?>/modules/wcs_repair_quotes/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('quotation'); ?></span>
                <span class="hdd-menu-text">ใบเสนอราคาซ่อม WCS</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?> -->

            <?php if (!empty($headerMenuPermissions['delivery_logs'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/delivery_logs/'); ?>" href="<?php echo $baseUrl; ?>/modules/delivery_logs/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('delivery'); ?></span>
                <span class="hdd-menu-text">บันทึกรายการส่งของ</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerMenuPermissions['drum_requests'])): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/drum_requests/'); ?>" href="<?php echo $baseUrl; ?>/modules/drum_requests/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('delivery'); ?></span>
                <span class="hdd-menu-text">บันทึกการเบิก Drum</span>
                <?php if ($pendingDrumRequestCount > 0): ?>
                    <span class="hdd-count hdd-count-warning hdd-count-blink" title="มีรายการ Drum รอจัดส่ง <?php echo number_format($pendingDrumRequestCount); ?> รายการ"><?php echo number_format($pendingDrumRequestCount); ?></span>
                <?php endif; ?>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if ($headerCanAdminGroupMenu): ?>
            <div class="hdd-menu-section">จัดการ / ตั้งค่า</div>
            <?php endif; ?>

            <?php if (!empty($headerCanBranchImportMenu)): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/admin/branch_import/'); ?>" href="<?php echo $baseUrl; ?>/modules/admin/branch_import/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('branch'); ?></span>
                <span class="hdd-menu-text">อัปเดตข้อมูลสาขา</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerCanAssetImportMenu)): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/admin/asset_import/'); ?>" href="<?php echo $baseUrl; ?>/modules/admin/asset_import/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('upload'); ?></span>
                <span class="hdd-menu-text">อัปโหลดข้อมูลทรัพย์สิน</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerCanUserManageMenu)): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/admin/users/'); ?>" href="<?php echo $baseUrl; ?>/modules/admin/users/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('users'); ?></span>
                <span class="hdd-menu-text">จัดการข้อมูล User</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerCanPermissionManageMenu)): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/admin/permissions/'); ?>" href="<?php echo $baseUrl; ?>/modules/admin/permissions/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('admin'); ?></span>
                <span class="hdd-menu-text">จัดการสิทธิ์ส่วนกลาง</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>

            <?php if (!empty($headerCanOnlineUsersMenu)): ?>
            <a class="hdd-nav-link <?php echo isActiveMenu('/modules/admin/online_users/'); ?>" href="<?php echo $baseUrl; ?>/modules/admin/online_users/index.php">
                <span class="hdd-menu-icon"><?php echo hddSidebarIcon('users'); ?></span>
                <span class="hdd-menu-text">ผู้ใช้งานออนไลน์</span>
            
                <span class="hdd-menu-chevron" aria-hidden="true"><?php echo hddSidebarIcon('chevron'); ?></span></a>
            <?php endif; ?>
        </nav>

        <button class="hdd-sidebar-toggle" id="sidebarToggle" type="button" aria-label="ย่อหรือขยายเมนู">
            <span></span>
        </button>
    </aside>

    <div class="modal fade hdd-user-modal" id="hddUserProfileModal" tabindex="-1" aria-labelledby="hddUserProfileModalLabel" aria-hidden="true">
        <div class="modal-dialog modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title" id="hddUserProfileModalLabel">รายละเอียดผู้ใช้งาน</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
                </div>
                <div class="modal-body">
                    <div class="hdd-user-modal-profile">
                        <img class="hdd-user-modal-avatar" src="<?php echo $baseUrl; ?>/assets/images/user-avatar.png" alt="รูปผู้ใช้งาน">
                        <div>
                            <div class="hdd-user-modal-name"><?php echo e($headerUserProfile['full_name'] ?? $currentUserDisplayName); ?></div>
                            <div class="hdd-user-modal-position"><?php echo e($headerUserPosition); ?></div>
                        </div>
                    </div>
                    <div class="hdd-user-detail-list">
                        <div class="hdd-user-detail-row"><div class="hdd-user-detail-label">รหัสพนักงาน</div><div class="hdd-user-detail-value"><?php echo e($headerUserProfile['employee_code'] ?? '-'); ?></div></div>
                        <div class="hdd-user-detail-row"><div class="hdd-user-detail-label">ชื่อ-นามสกุล</div><div class="hdd-user-detail-value"><?php echo e($headerUserProfile['full_name'] ?? $currentUserDisplayName); ?></div></div>
                        <div class="hdd-user-detail-row"><div class="hdd-user-detail-label">ตำแหน่ง</div><div class="hdd-user-detail-value"><?php echo e($headerUserPosition); ?></div></div>
                    </div>
                </div>
                <div class="modal-footer py-2">
                    <button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิด</button>
                </div>
            </div>
        </div>
    </div>

    <script>
    document.addEventListener('DOMContentLoaded', function () {
        var userModal = document.getElementById('hddUserProfileModal');
        if (userModal && userModal.parentElement !== document.body) {
            document.body.appendChild(userModal);
        }
    });
    </script>

    <div id="content-wrapper" class="hdd-content-wrapper d-flex flex-column">
        <div id="content">
            <nav class="navbar navbar-expand navbar-light bg-white topbar mb-4 static-top shadow-sm hdd-topbar">
                <button id="sidebarToggleTop" class="btn btn-link d-md-none rounded-circle me-2" type="button" aria-label="Toggle sidebar mobile">
                    ☰
                </button>

                <div class="hdd-top-announcement" role="region" aria-label="ประกาศเกณฑ์การซ่อมเครื่องปริ้นเตอร์" title="เลื่อนเมาส์ค้างเพื่อหยุดข้อความ">
                    <div class="hdd-top-announcement-track">
                        <div class="hdd-top-announcement-text">
                            <span class="hdd-top-announcement-title">ประกาศ !! แจ้งเกณฑ์การช่อมเครื่องปริ้นเตอร์</span>
                            <span class="hdd-top-announcement-item">1. เครื่องปริ้นอายุการใช้งานเกิน 5 ปี กรณีเครื่องเสียไม่ต้องซ่อม ให้สาขาส่งมาที่ สนญ</span>
                            <span class="hdd-top-announcement-item">2. เครื่องปริ้นอายุการใช้งานไม่เกิน 5 ปี กรณีเครื่องเสีย อนุมัติค่าซ่อมรวมค่าขนส่งงบประมาณไม่เกิน 2,600.-</span>
                            <span class="hdd-top-announcement-item">3. เครื่องคอมฯ เครื่องปริ้นเตอร์ ที่เสีย ตีชำรุด จะยังไม่ขายให้เก็บรวบรวมไว้ สนญ. รอจัดประมูล</span>
                        </div>
                    </div>
                </div>

                <div class="hdd-top-user-area ms-auto">
                    <div class="dropdown hdd-notification-dropdown d-none d-lg-block">
                        <button class="hdd-notification-button<?php echo $dashboardAlertCount > 0 ? ' has-alert' : ''; ?>" type="button" id="hddNotificationDropdown" data-bs-toggle="dropdown" aria-expanded="false" title="การแจ้งเตือน">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 7-3 9h18c0-2-3-2-3-9"></path><path d="M10 21h4"></path></svg>
                            <?php if ($dashboardAlertCount > 0): ?>
                                <span class="hdd-notification-count"><?php echo number_format($dashboardAlertCount); ?></span>
                            <?php endif; ?>
                        </button>
                        <div class="dropdown-menu dropdown-menu-end hdd-notification-menu" aria-labelledby="hddNotificationDropdown">
                            <div class="hdd-notification-head">
                                <strong>การแจ้งเตือน</strong>
                                <span><?php echo number_format($dashboardAlertCount); ?> รายการ</span>
                            </div>
                            <?php if ($dashboardAlertCount > 0): ?>
                                <?php if ($pendingScanCount > 0): ?>
                                    <a class="hdd-notification-item" href="<?php echo $baseUrl; ?>/modules/requests/create.php">
                                        <span class="hdd-notification-item-icon warning"><svg viewBox="0 0 24 24" fill="currentColor"><rect x="3" y="4" width="1.8" height="16" rx=".4"></rect><rect x="6" y="4" width="1" height="16" rx=".3"></rect><rect x="8.5" y="4" width="2" height="16" rx=".4"></rect><rect x="12.2" y="4" width="1" height="16" rx=".3"></rect><rect x="14.4" y="4" width="1.8" height="16" rx=".4"></rect><rect x="17.2" y="4" width="1" height="16" rx=".3"></rect><rect x="19.4" y="4" width="1.6" height="16" rx=".4"></rect></svg></span>
                                        <span class="hdd-notification-item-text"><strong>รอยิงบาร์โค้ด</strong><small>มีรายการรอดำเนินการ</small></span>
                                        <span class="hdd-notification-item-count"><?php echo number_format($pendingScanCount); ?></span>
                                    </a>
                                <?php endif; ?>
                                <?php if ($pendingShipmentConfirmCount > 0): ?>
                                    <a class="hdd-notification-item" href="<?php echo $baseUrl; ?>/modules/requests/matched.php">
                                        <span class="hdd-notification-item-icon danger"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><rect x="4" y="3" width="16" height="18" rx="2"></rect><path d="M8 7h8"></path><path d="M9 12l2 2 4-4"></path></svg></span>
                                        <span class="hdd-notification-item-text"><strong>รอยืนยันจัดส่ง</strong><small>มีรายการรอการยืนยัน</small></span>
                                        <span class="hdd-notification-item-count"><?php echo number_format($pendingShipmentConfirmCount); ?></span>
                                    </a>
                                <?php endif; ?>
                                <?php if ($claimReturnPendingCount > 0): ?>
                                    <a class="hdd-notification-item" href="<?php echo $baseUrl; ?>/modules/claim_returns/index.php">
                                        <span class="hdd-notification-item-icon warning"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M9 7H4v5"></path><path d="M20 17h-5v-5"></path><path d="M4 12a8 8 0 0 1 13.6-5.7L20 8"></path><path d="M20 12a8 8 0 0 1-13.6 5.7L4 16"></path></svg></span>
                                        <span class="hdd-notification-item-text"><strong>รอรับคืน/ส่งเคลม</strong><small>มีรายการที่ต้องจัดการ</small></span>
                                        <span class="hdd-notification-item-count"><?php echo number_format($claimReturnPendingCount); ?></span>
                                    </a>
                                <?php endif; ?>
                                <?php if ($pendingDrumRequestCount > 0): ?>
                                    <a class="hdd-notification-item" href="<?php echo $baseUrl; ?>/modules/drum_requests/index.php">
                                        <span class="hdd-notification-item-icon warning"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4-9 4-9-4Z"></path><path d="M3 7v10l9 4 9-4V7"></path><path d="M12 11v10"></path></svg></span>
                                        <span class="hdd-notification-item-text"><strong>รายการเบิก Drum รอจัดส่ง</strong><small>มีรายการรอพิมพ์ใบปะหน้าและยืนยันจัดส่ง</small></span>
                                        <span class="hdd-notification-item-count"><?php echo number_format($pendingDrumRequestCount); ?></span>
                                    </a>
                                <?php endif; ?>
                            <?php else: ?>
                                <div class="hdd-notification-empty">ไม่มีรายการแจ้งเตือน</div>
                            <?php endif; ?>
                        </div>
                    </div>

                    <a href="<?php echo $baseUrl; ?>/public/logout.php" class="hdd-logout-redesign">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M10 17l5-5-5-5"></path><path d="M15 12H3"></path><path d="M21 5v14a2 2 0 0 1-2 2h-6"></path><path d="M13 3h6a2 2 0 0 1 2 2"></path></svg>
                        <!-- <span>ออกจากระบบ</span> -->
                    </a>
                </div>
            </nav>


            <main class="container-fluid hdd-content-area hdd-global-page-width hdd-global-page-height">
