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

$pageTitle = 'ยิงบาร์โค้ด HDD เพื่อจับคู่กับสาขา';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getPdoConnection(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
        return $GLOBALS['conn'];
    }

    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        return $GLOBALS['db'];
    }

    if (function_exists('getConnection')) {
        $connection = getConnection();
        if ($connection instanceof PDO) {
            return $connection;
        }
    }

    throw new Exception('ไม่พบการเชื่อมต่อฐานข้อมูล PDO');
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([
        ':table_name' => $tableName
    ]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function cleanValue($value): string
{
    return trim((string)($value ?? ''));
}

function formatMainBranchCode($value): string
{
    $value = cleanValue($value);

    if ($value === '') {
        return '-';
    }

    if (is_numeric($value)) {
        $value = (string)(int)$value;
    }

    if (ctype_digit($value) && strlen($value) < 3) {
        return str_pad($value, 3, '0', STR_PAD_LEFT);
    }

    return $value;
}

function isEnglishBarcode(string $value): bool
{
    return preg_match('/^[A-Za-z0-9]+$/', $value) === 1;
}

function getCurrentUserName(): string
{
    $firstName = cleanValue($_SESSION['first_name'] ?? '');
    $lastName = cleanValue($_SESSION['last_name'] ?? '');
    $fullName = cleanValue($_SESSION['full_name'] ?? ($_SESSION['fullname'] ?? ''));
    $employeeCode = cleanValue($_SESSION['employee_code'] ?? '');

    if ($fullName === '' && ($firstName !== '' || $lastName !== '')) {
        $fullName = trim($firstName . ' ' . $lastName);
    }

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

        $userFirstName = cleanValue($user['first_name'] ?? '');
        $userLastName = cleanValue($user['last_name'] ?? '');
        $userFullName = cleanValue($user['full_name'] ?? ($user['fullname'] ?? ''));
        $userEmployeeCode = cleanValue($user['employee_code'] ?? '');

        if ($userFullName === '' && ($userFirstName !== '' || $userLastName !== '')) {
            $userFullName = trim($userFirstName . ' ' . $userLastName);
        }

        if ($userFullName !== '' && $userEmployeeCode !== '') {
            return $userFullName . ' (' . $userEmployeeCode . ')';
        }

        if ($userFullName !== '') {
            return $userFullName;
        }

        if ($userEmployeeCode !== '') {
            return $userEmployeeCode;
        }

        foreach (['name', 'username', 'user_name', 'login_name', 'user_id'] as $key) {
            if (!empty($user[$key])) {
                return cleanValue($user[$key]);
            }
        }
    }

    foreach (['name', 'username', 'user_name', 'login_name', 'user_id'] as $key) {
        if (!empty($_SESSION[$key])) {
            return cleanValue($_SESSION[$key]);
        }
    }

    return 'IT';
}

function statusBadge($status): string
{
    $status = cleanValue($status);

    $map = [
        'pending_scan' => ['รอยิงบาร์โค้ด', 'bg-warning text-dark'],
        'pending' => ['รอดำเนินการ', 'bg-warning text-dark'],
        'matched' => ['จับคู่ HDD แล้ว', 'bg-info text-dark'],
        'reserved' => ['จอง HDD แล้ว', 'bg-primary'],
        'shipped' => ['จัดส่งแล้ว', 'bg-success'],
        'received' => ['รับแล้ว', 'bg-success'],
        'cancelled' => ['ยกเลิก', 'bg-secondary'],
        'rejected' => ['ไม่อนุมัติ', 'bg-danger'],
    ];

    $item = $map[$status] ?? [$status !== '' ? $status : '-', 'bg-secondary'];

    return '<span class="badge ' . h($item[1]) . '">' . h($item[0]) . '</span>';
}



function formatDateTimeThai($value): string
{
    $value = cleanValue($value);

    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y H:i', $timestamp);
}

function buildAssignQueryString(array $params = []): string
{
    $base = [];

    foreach (['keyword', 'status', 'page'] as $key) {
        if (isset($_GET[$key]) && trim((string)$_GET[$key]) !== '') {
            $base[$key] = trim((string)$_GET[$key]);
        }
    }

    foreach ($params as $key => $value) {
        if ($value === null || $value === '') {
            unset($base[$key]);
        } else {
            $base[$key] = $value;
        }
    }

    return http_build_query($base);
}

function requestStatusText($status): string
{
    $status = cleanValue($status);

    $map = [
        'pending_scan' => 'รอยิงบาร์โค้ด',
        'pending' => 'รอดำเนินการ',
        'matched' => 'จับคู่ HDD แล้ว',
        'reserved' => 'จอง HDD แล้ว',
        'shipped' => 'จัดส่งแล้ว',
        'received' => 'รับแล้ว',
        'cancelled' => 'ยกเลิก',
        'rejected' => 'ไม่อนุมัติ',
    ];

    return $map[$status] ?? ($status !== '' ? $status : '-');
}

function findDuplicateHddRequests(PDO $pdo, array $requestColumns, string $hddSerial, int $inventoryId, int $currentRequestId = 0): array
{
    $selectColumns = [];

    foreach ([
        'id',
        'request_no',
        'main_branch_code',
        'branch_code',
        'branch_name',
        'hdd_inventory_id',
        'hdd_serial',
        'status',
        'matched_by',
        'assigned_by',
        'matched_at',
        'assigned_at',
        'updated_at',
        'created_at'
    ] as $column) {
        if (hasColumn($requestColumns, $column)) {
            $selectColumns[] = $column;
        }
    }

    if (empty($selectColumns) || !hasColumn($requestColumns, 'id')) {
        return [];
    }

    $duplicateWhere = [];
    $params = [];

    if (hasColumn($requestColumns, 'hdd_serial') && $hddSerial !== '') {
        $duplicateWhere[] = 'BINARY hdd_serial = :duplicate_hdd_serial';
        $params[':duplicate_hdd_serial'] = $hddSerial;
    }

    if (hasColumn($requestColumns, 'hdd_inventory_id') && $inventoryId > 0) {
        $duplicateWhere[] = 'hdd_inventory_id = :duplicate_inventory_id';
        $params[':duplicate_inventory_id'] = $inventoryId;
    }

    if (empty($duplicateWhere)) {
        return [];
    }

    $where = ['(' . implode(' OR ', $duplicateWhere) . ')'];

    if ($currentRequestId > 0) {
        $where[] = 'id <> :current_request_id';
        $params[':current_request_id'] = $currentRequestId;
    }

    if (hasColumn($requestColumns, 'deleted_at')) {
        $where[] = 'deleted_at IS NULL';
    }

    if (hasColumn($requestColumns, 'status')) {
        $where[] = "status NOT IN ('cancelled', 'rejected')";
    }

    $orderColumns = [];
    foreach (['matched_at', 'assigned_at', 'updated_at', 'created_at', 'id'] as $column) {
        if (hasColumn($requestColumns, $column)) {
            $orderColumns[] = $column . ' DESC';
        }
    }

    $orderSql = !empty($orderColumns) ? 'ORDER BY ' . implode(', ', $orderColumns) : 'ORDER BY id DESC';

    $stmt = $pdo->prepare("\n        SELECT " . implode(', ', $selectColumns) . "\n        FROM harddisk_delivery_requests\n        WHERE " . implode(' AND ', $where) . "\n        {$orderSql}\n        LIMIT 5\n    ");

    foreach ($params as $key => $value) {
        if ($key === ':duplicate_inventory_id' || $key === ':current_request_id') {
            $stmt->bindValue($key, (int)$value, PDO::PARAM_INT);
        } else {
            $stmt->bindValue($key, $value);
        }
    }

    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function buildDuplicateHddRequestMessage(array $duplicateRows): string
{
    if (empty($duplicateRows)) {
        return 'ยังไม่พบรายการคำขอส่ง HDD ที่ผูกกับ Serial นี้ในระบบ อาจถูกจองจากข้อมูลคลังเดิมหรือรายการอื่น';
    }

    $lines = [];
    $lines[] = 'พบว่า Serial HDD นี้ซ้ำกับรายการคำขอส่ง HDD ดังนี้:';

    foreach ($duplicateRows as $index => $row) {
        $requestNo = cleanValue($row['request_no'] ?? '-');
        $mainBranchCode = formatMainBranchCode($row['main_branch_code'] ?? '');
        $branchCode = cleanValue($row['branch_code'] ?? '-');
        $branchName = cleanValue($row['branch_name'] ?? '-');
        $statusText = requestStatusText($row['status'] ?? '');
        $matchedBy = cleanValue($row['matched_by'] ?? ($row['assigned_by'] ?? ''));
        $matchedAt = cleanValue($row['matched_at'] ?? ($row['assigned_at'] ?? ($row['updated_at'] ?? ($row['created_at'] ?? ''))));

        $line = ($index + 1) . ') เลขที่คำขอ: ' . $requestNo
            . ' | รหัสสาขา: ' . $mainBranchCode
            . ' | Cost Center: ' . $branchCode
            . ' | สาขา: ' . $branchName
            . ' | สถานะ: ' . $statusText;

        if ($matchedBy !== '') {
            $line .= ' | ผู้จับคู่: ' . $matchedBy;
        }

        if ($matchedAt !== '') {
            $line .= ' | วันที่: ' . formatDateTimeThai($matchedAt);
        }

        $lines[] = $line;
    }

    return implode("\n", $lines);
}

$successMessage = '';
$errorMessage = '';

try {
    $pdo = getPdoConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $requestColumns = getTableColumns($pdo, 'harddisk_delivery_requests');
    $inventoryColumns = getTableColumns($pdo, 'harddisk_inventory');

    if (!hasColumn($requestColumns, 'id')) {
        throw new Exception('ตาราง harddisk_delivery_requests ไม่มีคอลัมน์ id');
    }

    if (!hasColumn($inventoryColumns, 'id')) {
        throw new Exception('ตาราง harddisk_inventory ไม่มีคอลัมน์ id');
    }

    if (!hasColumn($inventoryColumns, 'hdd_serial')) {
        throw new Exception('ตาราง harddisk_inventory ไม่มีคอลัมน์ hdd_serial');
    }

    if (!hasColumn($inventoryColumns, 'status')) {
        throw new Exception('ตาราง harddisk_inventory ไม่มีคอลัมน์ status');
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $requestId = (int)($_POST['request_id'] ?? 0);
        $hddSerial = cleanValue($_POST['hdd_serial'] ?? '');

        if ($requestId <= 0) {
            throw new Exception('กรุณาเลือกรายการคำขอที่ต้องการจับคู่ HDD');
        }

        if ($hddSerial === '') {
            throw new Exception('กรุณายิงบาร์โค้ด HDD');
        }

        if (!isEnglishBarcode($hddSerial)) {
            throw new Exception('Serial HDD ต้องเป็นภาษาอังกฤษหรือตัวเลขเท่านั้น ห้ามมีเว้นวรรค ภาษาไทย หรืออักขระพิเศษ');
        }

        if (!hasColumn($requestColumns, 'hdd_inventory_id') || !hasColumn($requestColumns, 'hdd_serial')) {
            throw new Exception('ตาราง harddisk_delivery_requests ต้องมีคอลัมน์ hdd_inventory_id และ hdd_serial ก่อนใช้งานหน้านี้');
        }

        $pdo->beginTransaction();

        $requestSelectColumns = [];

        foreach ([
            'id',
            'request_no',
            'main_branch_code',
            'branch_code',
            'branch_name',
            'hdd_inventory_id',
            'hdd_serial',
            'status'
        ] as $column) {
            if (hasColumn($requestColumns, $column)) {
                $requestSelectColumns[] = $column;
            }
        }

        $requestWhere = [
            'id = :request_id'
        ];

        if (hasColumn($requestColumns, 'deleted_at')) {
            $requestWhere[] = 'deleted_at IS NULL';
        }

        $stmtRequest = $pdo->prepare("
            SELECT " . implode(', ', $requestSelectColumns) . "
            FROM harddisk_delivery_requests
            WHERE " . implode(' AND ', $requestWhere) . "
            LIMIT 1
            FOR UPDATE
        ");
        $stmtRequest->execute([
            ':request_id' => $requestId
        ]);

        $request = $stmtRequest->fetch(PDO::FETCH_ASSOC);

        if (!$request) {
            throw new Exception('ไม่พบรายการคำขอส่ง HDD ที่เลือก');
        }

        if (!empty($request['hdd_inventory_id']) || !empty($request['hdd_serial'])) {
            throw new Exception('รายการคำขอนี้มีการจับคู่ HDD แล้ว ไม่สามารถยิงซ้ำได้');
        }

        if (isset($request['status']) && in_array((string)$request['status'], ['cancelled', 'rejected'], true)) {
            throw new Exception('รายการนี้ถูกยกเลิกหรือไม่อนุมัติแล้ว ไม่สามารถจับคู่ HDD ได้');
        }

        $inventoryWhere = [
            'BINARY hdd_serial = :hdd_serial'
        ];

        if (hasColumn($inventoryColumns, 'deleted_at')) {
            $inventoryWhere[] = 'deleted_at IS NULL';
        }

        $stmtInventory = $pdo->prepare("
            SELECT
                id,
                hdd_serial,
                status
            FROM harddisk_inventory
            WHERE " . implode(' AND ', $inventoryWhere) . "
            LIMIT 1
            FOR UPDATE
        ");
        $stmtInventory->execute([
            ':hdd_serial' => $hddSerial
        ]);

        $inventory = $stmtInventory->fetch(PDO::FETCH_ASSOC);

        if (!$inventory) {
            throw new Exception('ไม่พบ Serial HDD นี้ในคลัง กรุณาตรวจสอบบาร์โค้ด HDD ให้ถูกต้อง');
        }

        if ((string)$inventory['hdd_serial'] !== $hddSerial) {
            throw new Exception('Serial HDD ที่ยิงเข้ามาไม่ตรงกับข้อมูลในคลัง');
        }

        if ((string)$inventory['status'] !== 'available') {
            $duplicateRequests = findDuplicateHddRequests(
                $pdo,
                $requestColumns,
                $hddSerial,
                (int)$inventory['id'],
                $requestId
            );

            $duplicateMessage = buildDuplicateHddRequestMessage($duplicateRequests);

            throw new Exception(
                'Serial HDD นี้ไม่พร้อมใช้งาน สถานะปัจจุบันคือ: ' . $inventory['status'] . "\n" . $duplicateMessage
            );
        }

        $currentUser = getCurrentUserName();

        $requestUpdateFields = [];
        $requestUpdateParams = [
            ':request_id' => $requestId,
            ':hdd_inventory_id' => (int)$inventory['id'],
            ':hdd_serial' => $hddSerial
        ];

        $requestUpdateFields[] = 'hdd_inventory_id = :hdd_inventory_id';
        $requestUpdateFields[] = 'hdd_serial = :hdd_serial';

        if (hasColumn($requestColumns, 'status')) {
            $requestUpdateFields[] = "status = 'matched'";
        }

        if (hasColumn($requestColumns, 'assigned_by')) {
            $requestUpdateFields[] = 'assigned_by = :assigned_by';
            $requestUpdateParams[':assigned_by'] = $currentUser;
        }

        if (hasColumn($requestColumns, 'matched_by')) {
            $requestUpdateFields[] = 'matched_by = :matched_by';
            $requestUpdateParams[':matched_by'] = $currentUser;
        }

        if (hasColumn($requestColumns, 'assigned_at')) {
            $requestUpdateFields[] = 'assigned_at = NOW()';
        }

        if (hasColumn($requestColumns, 'matched_at')) {
            $requestUpdateFields[] = 'matched_at = NOW()';
        }

        if (hasColumn($requestColumns, 'updated_at')) {
            $requestUpdateFields[] = 'updated_at = NOW()';
        }

        $stmtUpdateRequest = $pdo->prepare("
            UPDATE harddisk_delivery_requests
            SET " . implode(', ', $requestUpdateFields) . "
            WHERE id = :request_id
        ");
        $stmtUpdateRequest->execute($requestUpdateParams);

        $inventoryUpdateFields = [
            "status = 'reserved'"
        ];

        $inventoryUpdateParams = [
            ':inventory_id' => (int)$inventory['id']
        ];

        if (hasColumn($inventoryColumns, 'scanned_by')) {
            $inventoryUpdateFields[] = 'scanned_by = :scanned_by';
            $inventoryUpdateParams[':scanned_by'] = $currentUser;
        }

        if (hasColumn($inventoryColumns, 'scanned_at')) {
            $inventoryUpdateFields[] = 'scanned_at = NOW()';
        }

        if (hasColumn($inventoryColumns, 'updated_at')) {
            $inventoryUpdateFields[] = 'updated_at = NOW()';
        }

        $stmtUpdateInventory = $pdo->prepare("
            UPDATE harddisk_inventory
            SET " . implode(', ', $inventoryUpdateFields) . "
            WHERE id = :inventory_id
        ");
        $stmtUpdateInventory->execute($inventoryUpdateParams);

        $pdo->commit();

        header('Location: assign_hdd.php?success=1&print_request_id=' . $requestId);
        exit;
    }

    if (isset($_GET['created'])) {
        $successMessage = 'บันทึกคำขอแล้ว กรุณายิงบาร์โค้ด HDD เพื่อจับคู่กับสาขา';
    }

    if (isset($_GET['success'])) {
        $successMessage = 'จับคู่ HDD กับสาขา และตัด Stock เป็นสถานะ reserved เรียบร้อยแล้ว สามารถปริ้นใบแปะหน้ากล่องได้';
    }

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    $errorMessage = $e->getMessage();
}

$selectedRequestId = (int)($_GET['request_id'] ?? 0);
$successPrintRequestId = (int)($_GET['print_request_id'] ?? 0);
$keyword = cleanValue($_GET['keyword'] ?? '');
$statusFilter = cleanValue($_GET['status'] ?? '');

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);

$perPage = 20;
$offset = ($page - 1) * $perPage;

$pendingRequests = [];
$selectedRequest = null;
$totalRows = 0;
$totalPages = 1;

$statusOptions = [
    'pending_scan',
    'pending',
    'matched',
    'reserved',
    'shipped',
    'received',
    'cancelled',
    'rejected',
];

try {
    if (isset($pdo) && $pdo instanceof PDO) {
        $requestColumns = getTableColumns($pdo, 'harddisk_delivery_requests');

        $selectColumns = [];

        foreach ([
            'id',
            'request_no',
            'main_branch_code',
            'branch_code',
            'branch_name',
            'hdd_inventory_id',
            'hdd_serial',
            'request_reason',
            'status',
            'remark',
            'created_at'
        ] as $column) {
            if (hasColumn($requestColumns, $column)) {
                $selectColumns[] = $column;
            }
        }

        if (empty($selectColumns)) {
            $selectColumns[] = 'id';
        }

        $where = [];
        $params = [];

        if (hasColumn($requestColumns, 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }

        if (hasColumn($requestColumns, 'status')) {
            $where[] = "status NOT IN ('cancelled', 'rejected')";
        }

        if (hasColumn($requestColumns, 'hdd_inventory_id')) {
            $where[] = '(hdd_inventory_id IS NULL OR hdd_inventory_id = 0)';
        }

        if (hasColumn($requestColumns, 'hdd_serial')) {
            $where[] = "(hdd_serial IS NULL OR hdd_serial = '')";
        }

        /*
        |--------------------------------------------------------------------------
        | Keyword Search เงื่อนไขเดียวกับหน้า ประวัติการจัดส่ง Harddisk
        |--------------------------------------------------------------------------
        | รองรับการค้นหา:
        | - เลขที่คำขอ
        | - รหัสสาขา
        | - ชื่อสาขา
        | - Serial HDD
        |
        | Logic สำคัญ:
        | 1) ถ้าค้นหาเป็นตัวเลขล้วนไม่เกิน 3 หลัก เช่น 240
        |    ให้ถือว่าเป็นรหัสสาขา และค้นแบบตรงตัวเท่านั้น
        |    ไม่เอาเลขไปค้นใน Serial HDD
        |
        | 2) ถ้าค้นหาเป็นข้อความผสมตัวเลข เช่น เพชรเกษม 110 กรุงเทพฯ
        |    ให้ค้นจากข้อความเต็ม ไม่แยกเลข 110 ไปเทียบรหัสสาขา
        */
        if ($keyword !== '') {
            $searchColumns = [];
            $keywordLike = '%' . $keyword . '%';

            $keywordIsNumberOnly = preg_match('/^\d+$/', $keyword) === 1;
            $keywordIsShortBranchCode = $keywordIsNumberOnly && strlen($keyword) <= 3;

            if ($keywordIsShortBranchCode) {
                $normalizedBranchKeyword = str_pad($keyword, 3, '0', STR_PAD_LEFT);

                if (hasColumn($requestColumns, 'main_branch_code')) {
                    $searchColumns[] = "LPAD(main_branch_code, 3, '0') = :keyword_main_branch_exact";
                    $params[':keyword_main_branch_exact'] = $normalizedBranchKeyword;
                }

                if (hasColumn($requestColumns, 'branch_code')) {
                    $searchColumns[] = "LPAD(branch_code, 3, '0') = :keyword_branch_code_exact";
                    $params[':keyword_branch_code_exact'] = $normalizedBranchKeyword;
                }
            } else {
                foreach ([
                    'request_no',
                    'branch_name',
                    'hdd_serial',
                ] as $index => $column) {
                    if (hasColumn($requestColumns, $column)) {
                        $paramName = ':keyword_text_' . $index;
                        $searchColumns[] = $column . ' LIKE ' . $paramName;
                        $params[$paramName] = $keywordLike;
                    }
                }
            }

            if (!empty($searchColumns)) {
                $where[] = '(' . implode(' OR ', $searchColumns) . ')';
            }
        }

        if ($statusFilter !== '' && hasColumn($requestColumns, 'status')) {
            $where[] = 'status = :status_filter';
            $params[':status_filter'] = $statusFilter;
        }

        $whereSql = '';
        if (!empty($where)) {
            $whereSql = 'WHERE ' . implode(' AND ', $where);
        }

        $stmtCount = $pdo->prepare("
            SELECT COUNT(*)
            FROM harddisk_delivery_requests
            {$whereSql}
        ");

        foreach ($params as $key => $value) {
            $stmtCount->bindValue($key, $value);
        }

        $stmtCount->execute();
        $totalRows = (int)$stmtCount->fetchColumn();
        $totalPages = max((int)ceil($totalRows / $perPage), 1);

        if ($page > $totalPages) {
            $page = $totalPages;
            $offset = ($page - 1) * $perPage;
        }

        $stmtPending = $pdo->prepare("
            SELECT " . implode(', ', $selectColumns) . "
            FROM harddisk_delivery_requests
            {$whereSql}
            ORDER BY id DESC
            LIMIT :limit OFFSET :offset
        ");

        foreach ($params as $key => $value) {
            $stmtPending->bindValue($key, $value);
        }

        $stmtPending->bindValue(':limit', $perPage, PDO::PARAM_INT);
        $stmtPending->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmtPending->execute();

        $pendingRequests = $stmtPending->fetchAll(PDO::FETCH_ASSOC);

        foreach ($pendingRequests as $row) {
            if ((int)($row['id'] ?? 0) === $selectedRequestId) {
                $selectedRequest = $row;
                break;
            }
        }
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    body { background: #f3f6fb; }
    .assign-page { padding: 10px 0 16px 0; }
    .assign-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .assign-subtitle { font-size: 13px; color: #64748b; }
    .assign-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .assign-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; }
    .assign-card .card-body { padding: 12px; }
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
    .step-box { border: 1px solid #e2e8f0; border-radius: 14px; padding: 10px; background: #f8fafc; }
    .step-title { font-size: 13px; font-weight: 900; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
    .step-badge { width: 22px; height: 22px; border-radius: 8px; background: #2563eb; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
    .table-scroll { max-height: 430px; overflow: auto; }
    .table-assign th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 12px; white-space: nowrap; padding: 7px 8px; }
    .table-assign td { font-size: 12px; vertical-align: middle; padding: 7px 8px; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-weight: 900; color: #7c2d12; white-space: nowrap; }
    .branch-code { font-weight: 900; color: #1d4ed8; white-space: nowrap; }
    .text-ellipsis { max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .selected-request-box { background: #eef6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 9px 10px; font-size: 13px; }
    .help-box { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 8px 10px; font-size: 12px; }
    .scan-input { font-family: Consolas, Monaco, monospace; font-weight: 900; letter-spacing: .5px; text-transform: uppercase; }
    .action-buttons { min-width: 90px; }
    @media (max-width: 1366px) {
        .assign-page { padding-top: 8px; }
        .assign-title { font-size: 20px; }
        .assign-card .card-body { padding: 10px; }
        .kpi-card .card-body { padding: 10px 12px; }
        .kpi-value { font-size: 25px; }
        .table-scroll { max-height: 385px; }
        .table-assign th, .table-assign td { font-size: 11.5px; padding: 6px 7px; }
        .form-control, .form-select { font-size: 12px; }
    }
</style>

<div class="container-fluid assign-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="assign-title">ยิงบาร์โค้ด HDD เพื่อจับคู่กับสาขา</h3>
            <div class="assign-subtitle">เลือกรายการคำขอ ยิง Serial HDD เพื่อตัด Stock และเตรียมใบแปะหน้ากล่อง</div>
        </div>
        <div class="d-flex gap-2">
            <a href="../dashboard/index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
            <a href="create.php" class="btn btn-outline-primary btn-sm">บันทึกคำขอใหม่</a>
            <a href="index.php" class="btn btn-outline-primary btn-sm">รายการคำขอ</a>
        </div>
    </div>

    <?php if ($successMessage !== ''): ?>
        <div class="alert alert-success py-2 mb-2 d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div><?php echo h($successMessage); ?></div>
            <?php if ($successPrintRequestId > 0): ?>
                <a href="print_label.php?request_id=<?php echo (int)$successPrintRequestId; ?>" target="_blank" class="btn btn-sm btn-success">
                    ปริ้นใบแปะหน้ากล่อง
                </a>
            <?php endif; ?>
        </div>
    <?php endif; ?>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger py-2 mb-2">
            <strong>ไม่สามารถดำเนินการได้</strong><br>
            <?php echo nl2br(h($errorMessage)); ?>
        </div>
    <?php endif; ?>

    <div class="card hero-card mb-2">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold"></div>
                <div class="small opacity-75">เมื่อจับคู่สำเร็จ ระบบจะเปลี่ยน HDD ในคลังเป็น “จองไว้ / reserved” เพื่อกันนำไปใช้งานซ้ำ</div>
            </div>
            <div class="small">ผู้ใช้งานปัจจุบัน: <strong><?php echo h(getCurrentUserName()); ?></strong></div>
        </div>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">รอยิงบาร์โค้ด</div><div class="kpi-value"><?php echo number_format($totalRows); ?></div><div class="kpi-note">รายการที่ยังไม่ได้จับคู่ HDD</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">รายการที่เลือก</div><div class="kpi-value"><?php echo $selectedRequest ? '1' : '0'; ?></div><div class="kpi-note">เลือกรายการก่อนยิงบาร์โค้ด</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">หน้าปัจจุบัน</div><div class="kpi-value"><?php echo number_format($page); ?></div><div class="kpi-note">จากทั้งหมด <?php echo number_format($totalPages); ?> หน้า</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">โหมดสแกน</div><div class="kpi-value">AUTO</div><div class="kpi-note">รองรับยิงบาร์โค้ดแบบ Enter / Auto Submit</div></div></div></div>
    </div>

    <div class="row g-2">
        <div class="col-xl-4 col-lg-5">
            <div class="card assign-card h-100">
                <div class="card-header">ยิงบาร์โค้ด HDD จับคู่กับสาขา</div>
                <div class="card-body">
                    <?php if (!$selectedRequest): ?>
                        <div class="step-box mb-2">
                            <div class="step-title"><span class="step-badge">1</span> เลือกรายการคำขอ</div>
                            <div class="help-box mb-0">กรุณาเลือกรายการจากตารางด้านขวาก่อน จากนั้นจึงยิงบาร์โค้ด HDD</div>
                        </div>
                    <?php else: ?>
                        <div class="step-box mb-2">
                            <div class="step-title"><span class="step-badge">1</span> รายการที่เลือก</div>
                            <div class="selected-request-box">
                                <strong>เลขที่คำขอ:</strong> <?php echo h($selectedRequest['request_no'] ?? '-'); ?><br>
                                <strong>รหัสสาขาใหญ่:</strong> <?php echo h(formatMainBranchCode($selectedRequest['main_branch_code'] ?? '')); ?><br>
                                <strong>Cost Center:</strong> <span class="branch-code"><?php echo h($selectedRequest['branch_code'] ?? '-'); ?></span><br>
                                <strong>ชื่อสาขา:</strong> <?php echo h($selectedRequest['branch_name'] ?? '-'); ?><br>
                                <strong>สาเหตุ:</strong> <?php echo h($selectedRequest['request_reason'] ?? '-'); ?>
                            </div>
                        </div>

                        <form method="post" id="scanForm" autocomplete="off">
                            <input type="hidden" name="request_id" value="<?php echo (int)$selectedRequest['id']; ?>">

                            <div class="step-box mb-2">
                                <div class="step-title"><span class="step-badge">2</span> ยิง Serial HDD</div>
                                <label class="form-label">Serial HDD / Barcode</label>
                                <input type="text"
                                       name="hdd_serial"
                                       id="hdd_serial"
                                       class="form-control form-control-lg scan-input"
                                       placeholder="ยิงบาร์โค้ด HDD ที่นี่"
                                       required
                                       autofocus>
                                <div class="form-text">ใช้ได้เฉพาะภาษาอังกฤษและตัวเลขเท่านั้น เช่น ZWD0WDHL, WD12345</div>
                            </div>

                            <div class="step-box mb-2">
                                <div class="step-title"><span class="step-badge">3</span> ยืนยันการจับคู่</div>
                                <button type="submit" class="btn btn-success w-100 btn-lg">จับคู่ HDD กับสาขา</button>
                                <div class="small text-muted mt-2">หลังจับคู่สำเร็จ ระบบจะแสดงปุ่มปริ้นใบแปะหน้ากล่องให้อัตโนมัติ</div>
                            </div>
                        </form>
                    <?php endif; ?>

                    <?php if ($successPrintRequestId > 0): ?>
                        <div class="step-box mt-2">
                            <div class="step-title"><span class="step-badge">✓</span> จับคู่สำเร็จ</div>
                            <a href="print_label.php?request_id=<?php echo (int)$successPrintRequestId; ?>" target="_blank" class="btn btn-success w-100">ปริ้นใบแปะหน้ากล่อง</a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-8 col-lg-7">
            <div class="card assign-card h-100">
                <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
                    <div>รายการคำขอที่รอยิงบาร์โค้ด HDD</div>
                    <div class="small text-muted">ทั้งหมด <?php echo number_format($totalRows); ?> รายการ | หน้า <?php echo number_format($page); ?> / <?php echo number_format($totalPages); ?></div>
                </div>
                <div class="card-body">
                    <form method="get" class="row g-2 align-items-end mb-2">
                        <div class="col-md-5">
                            <label for="keyword" class="form-label">Keyword</label>
                            <input type="text" name="keyword" id="keyword" class="form-control" value="<?php echo h($keyword); ?>" placeholder="เลขที่คำขอ, รหัสสาขา, ชื่อสาขา, Serial HDD">
                        </div>
                        <div class="col-md-3">
                            <label for="status" class="form-label">สถานะ</label>
                            <select name="status" id="status" class="form-select">
                                <option value="">ทั้งหมด</option>
                                <?php foreach ($statusOptions as $statusOption): ?>
                                    <option value="<?php echo h($statusOption); ?>" <?php echo $statusFilter === $statusOption ? 'selected' : ''; ?>><?php echo h(strip_tags(statusBadge($statusOption))); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="col-md-2 d-grid">
                            <button type="submit" class="btn btn-primary">ค้นหา</button>
                        </div>
                        <div class="col-md-2 d-grid">
                            <a href="assign_hdd.php" class="btn btn-outline-secondary">ล้างค่า</a>
                        </div>
                    </form>

                    <div class="table-responsive table-scroll">
                        <table class="table table-hover table-bordered align-middle mb-0 table-assign">
                            <thead>
                                <tr>
                                    <th>จัดการ</th>
                                    <th>เลขที่คำขอ</th>
                                    <th>รหัสสาขา</th>
                                    <th>Cost Center</th>
                                    <th>ชื่อสาขา</th>
                                    <th>สถานะ</th>
                                    <th>วันที่บันทึก</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($pendingRequests)): ?>
                                    <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบรายการคำขอที่รอยิงบาร์โค้ด HDD</td></tr>
                                <?php else: ?>
                                    <?php foreach ($pendingRequests as $row): ?>
                                        <?php
                                        $rowId = (int)($row['id'] ?? 0);
                                        $isSelected = $rowId === $selectedRequestId;
                                        $selectQuery = buildAssignQueryString([
                                            'request_id' => $rowId,
                                            'page' => $page,
                                        ]);
                                        ?>
                                        <tr class="<?php echo $isSelected ? 'table-primary' : ''; ?>">
                                            <td class="action-buttons">
                                                <div class="d-grid gap-1">
                                                    <a href="assign_hdd.php?<?php echo h($selectQuery); ?>" class="btn btn-sm <?php echo $isSelected ? 'btn-primary' : 'btn-outline-primary'; ?>">เลือก</a>
                                                    <a href="print_label.php?request_id=<?php echo (int)$rowId; ?>" target="_blank" class="btn btn-sm btn-outline-dark">ใบปะหน้า</a>
                                                </div>
                                            </td>
                                            <td><strong><?php echo h($row['request_no'] ?? '-'); ?></strong></td>
                                            <td><?php echo h(formatMainBranchCode($row['main_branch_code'] ?? '')); ?></td>
                                            <td><span class="branch-code"><?php echo h($row['branch_code'] ?? '-'); ?></span></td>
                                            <td><div class="text-ellipsis" title="<?php echo h($row['branch_name'] ?? '-'); ?>"><?php echo h($row['branch_name'] ?? '-'); ?></div></td>
                                            <td><?php echo statusBadge($row['status'] ?? ''); ?></td>
                                            <td><?php echo h(formatDateTimeThai($row['created_at'] ?? '')); ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>

                    <?php if ($totalPages > 1): ?>
                        <nav class="mt-2">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <?php
                                $prevPage = max($page - 1, 1);
                                $prevQuery = buildAssignQueryString(['page' => $prevPage, 'request_id' => null]);
                                ?>
                                <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="assign_hdd.php?<?php echo h($prevQuery); ?>">ก่อนหน้า</a></li>
                                <?php
                                $startPage = max($page - 2, 1);
                                $endPage = min($page + 2, $totalPages);
                                for ($i = $startPage; $i <= $endPage; $i++):
                                    $pageQuery = buildAssignQueryString(['page' => $i, 'request_id' => null]);
                                ?>
                                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>"><a class="page-link" href="assign_hdd.php?<?php echo h($pageQuery); ?>"><?php echo h((string)$i); ?></a></li>
                                <?php endfor; ?>
                                <?php
                                $nextPage = min($page + 1, $totalPages);
                                $nextQuery = buildAssignQueryString(['page' => $nextPage, 'request_id' => null]);
                                ?>
                                <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="assign_hdd.php?<?php echo h($nextQuery); ?>">ถัดไป</a></li>
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
    const input = document.getElementById('hdd_serial');
    const form = document.getElementById('scanForm');

    if (!input || !form) {
        return;
    }

    let submitTimer = null;
    let isSubmitting = false;

    function cleanBarcode() {
        input.value = input.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
    }

    function submitScanForm() {
        cleanBarcode();

        if (isSubmitting) {
            return;
        }

        if (input.value.trim() === '') {
            input.focus();
            return;
        }

        isSubmitting = true;

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    input.focus();
    input.select();

    input.addEventListener('input', function () {
        cleanBarcode();

        if (submitTimer) {
            clearTimeout(submitTimer);
        }

        if (input.value.trim().length >= 5) {
            submitTimer = setTimeout(submitScanForm, 600);
        }
    });

    input.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();

            if (submitTimer) {
                clearTimeout(submitTimer);
            }

            submitScanForm();
        }
    });

    form.addEventListener('submit', function (event) {
        cleanBarcode();

        if (input.value.trim() === '') {
            event.preventDefault();
            isSubmitting = false;
            input.focus();
            alert('กรุณายิงบาร์โค้ด HDD');
            return;
        }

        if (!/^[A-Z0-9]+$/.test(input.value.trim())) {
            event.preventDefault();
            isSubmitting = false;
            input.focus();
            alert('Serial HDD ต้องเป็นภาษาอังกฤษหรือตัวเลขเท่านั้น');
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
