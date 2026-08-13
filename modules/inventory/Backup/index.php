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

function cleanText($value): string
{
    return trim((string)($value ?? ''));
}

function displayNameWithoutEmployeeCode($value): string
{
    $value = cleanText($value);
    if ($value === '') {
        return '-';
    }

    $value = preg_replace('/\s*\([A-Za-z0-9._-]+\)\s*$/u', '', $value);
    return trim((string)$value) !== '' ? trim((string)$value) : '-';
}

function currentLoginName(): string
{
    $fullName = cleanText($_SESSION['full_name'] ?? '');

    if ($fullName === '') {
        $firstName = cleanText($_SESSION['first_name'] ?? '');
        $lastName = cleanText($_SESSION['last_name'] ?? '');
        $fullName = trim($firstName . ' ' . $lastName);
    }

    $employeeCode = cleanText($_SESSION['employee_code'] ?? '');

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
        $userFullName = cleanText($user['full_name'] ?? '');

        if ($userFullName === '') {
            $userFullName = trim(cleanText($user['first_name'] ?? '') . ' ' . cleanText($user['last_name'] ?? ''));
        }

        $userEmployeeCode = cleanText($user['employee_code'] ?? '');

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

function currentEmployeeCode(): string
{
    if (!empty($_SESSION['employee_code'])) {
        return cleanText($_SESSION['employee_code']);
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['employee_code'])) {
        return cleanText($_SESSION['user']['employee_code']);
    }

    return '';
}

if (!function_exists('currentUserRoleForInventory')) {
    function currentUserRoleForInventory(): string
    {
        if (!empty($_SESSION['role'])) {
            return strtolower(cleanText($_SESSION['role']));
        }

        if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['role'])) {
            return strtolower(cleanText($_SESSION['user']['role']));
        }

        return '';
    }
}

if (!function_exists('canManageHddInventory')) {
    function canManageHddInventory(): bool
    {
        $employeeCode = currentEmployeeCode();
        $role = currentUserRoleForInventory();

        if ($employeeCode === '14329') {
            return true;
        }

        if (in_array($role, ['admin', 'administrator', 'super_admin'], true)) {
            return true;
        }

        return false;
    }
}

function isEnglishBarcode(string $value): bool
{
    return preg_match('/^[A-Za-z0-9]+$/', $value) === 1;
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

function formatThaiDate($value): string
{
    $value = cleanText($value);

    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y', $timestamp);
}

function inventoryStatusText($status): string
{
    $status = strtolower(cleanText($status));

    $map = [
        'available' => 'พร้อมใช้งาน',
        'reserved' => 'จองไว้',
        'shipped' => 'จัดส่งแล้ว',
        'used' => 'ใช้งานแล้ว',
        'damaged' => 'ชำรุด',
        'cancelled' => 'ยกเลิก',
    ];

    return $map[$status] ?? ($status !== '' ? $status : '-');
}

function inventoryStatusBadge($status): string
{
    $status = strtolower(cleanText($status));

    $class = 'inventory-status-secondary';

    if ($status === 'available') {
        $class = 'inventory-status-available';
    } elseif ($status === 'reserved') {
        $class = 'inventory-status-reserved';
    } elseif ($status === 'shipped') {
        $class = 'inventory-status-shipped';
    } elseif ($status === 'used') {
        $class = 'inventory-status-used';
    } elseif ($status === 'damaged') {
        $class = 'inventory-status-damaged';
    } elseif ($status === 'cancelled') {
        $class = 'inventory-status-cancelled';
    }

    return '<span class="inventory-status-badge ' . h($class) . '">' . h(inventoryStatusText($status)) . '</span>';
}

function buildInventoryKeywordWhere(string $keyword, array $columns, array &$params): array
{
    $keyword = cleanText($keyword);
    if ($keyword === '') {
        return [];
    }

    $where = [];
    $likeColumns = [
        'hdd_serial',
        'status',
        'scanned_by',
        'received_from',
        'remark',
        'created_by',
    ];

    $i = 0;
    foreach ($likeColumns as $column) {
        if (!hasColumn($columns, $column)) {
            continue;
        }
        $param = ':keyword_' . $i;
        $where[] = $column . ' LIKE ' . $param;
        $params[$param] = '%' . $keyword . '%';
        $i++;
    }

    if (empty($where)) {
        return [];
    }

    return ['(' . implode(' OR ', $where) . ')'];
}

function getCountByStatus(PDO $pdo, array $columns, string $status): int
{
    $where = [];
    $params = [':status' => $status];

    if (hasColumn($columns, 'deleted_at')) {
        $where[] = 'deleted_at IS NULL';
    }

    $where[] = 'status = :status';

    $stmt = $pdo->prepare('SELECT COUNT(*) FROM harddisk_inventory WHERE ' . implode(' AND ', $where));
    $stmt->execute($params);
    return (int)$stmt->fetchColumn();
}

function bindParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
}

$pageTitle = 'คลัง Harddisk';
$loginName = currentLoginName();
$employeeCode = currentEmployeeCode();
$canManageInventory = canManageHddInventory();
$errors = [];
$successMessage = '';

if (!isset($pdo) || !$pdo instanceof PDO) {
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    

<div class="container-fluid py-4">
        <div class="alert alert-danger">
            <strong>ไม่พบการเชื่อมต่อฐานข้อมูล</strong><br>
            กรุณาตรวจสอบไฟล์ <code>config/database.php</code>
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

if (!tableExists($pdo, 'harddisk_inventory')) {
    require_once __DIR__ . '/../../includes/header.php';
    ?>
    <div class="container-fluid py-4">
        <div class="alert alert-danger">
            <strong>ไม่พบตาราง harddisk_inventory</strong><br>
            กรุณาตรวจสอบฐานข้อมูลก่อนใช้งานหน้า “คลัง Harddisk”
        </div>
    </div>
    <?php
    require_once __DIR__ . '/../../includes/footer.php';
    exit;
}

$inventoryColumns = getTableColumns($pdo, 'harddisk_inventory');

$receivedFromOptions = [
    'IT Stock' => 'IT Stock',
    'เคลม' => 'เคลม',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $formAction = cleanText($_POST['form_action'] ?? 'create');

    if ($formAction === 'create') {
        $hddSerial = strtoupper(cleanText($_POST['hdd_serial'] ?? ''));
        $receivedFrom = cleanText($_POST['received_from'] ?? 'IT Stock');
        $remark = cleanText($_POST['remark'] ?? '');

        if ($hddSerial === '') {
            $errors[] = 'กรุณายิงบาร์โค้ดหรือกรอก Serial HDD';
        } elseif (!isEnglishBarcode($hddSerial)) {
            $errors[] = 'Serial HDD ต้องเป็นตัวอักษรภาษาอังกฤษและตัวเลขเท่านั้น ห้ามมีช่องว่างหรืออักขระพิเศษ';
        }

        if ($receivedFrom === '' || !array_key_exists($receivedFrom, $receivedFromOptions)) {
            $receivedFrom = 'IT Stock';
        }

        if (empty($errors)) {
            try {
                $duplicateWhere = ['BINARY hdd_serial = :hdd_serial'];
                if (hasColumn($inventoryColumns, 'deleted_at')) {
                    $duplicateWhere[] = 'deleted_at IS NULL';
                }

                $stmtCheck = $pdo->prepare('SELECT id FROM harddisk_inventory WHERE ' . implode(' AND ', $duplicateWhere) . ' LIMIT 1');
                $stmtCheck->execute([':hdd_serial' => $hddSerial]);

                if ($stmtCheck->fetchColumn()) {
                    $errors[] = 'Serial HDD นี้มีอยู่ในคลังแล้ว ไม่สามารถบันทึกซ้ำได้';
                } else {
                    $insertColumns = [];
                    $insertValues = [];
                    $insertParams = [];

                    $insertColumns[] = 'hdd_serial';
                    $insertValues[] = ':hdd_serial';
                    $insertParams[':hdd_serial'] = $hddSerial;

                    if (hasColumn($inventoryColumns, 'status')) {
                        $insertColumns[] = 'status';
                        $insertValues[] = ':status';
                        $insertParams[':status'] = 'available';
                    }

                    if (hasColumn($inventoryColumns, 'scanned_by')) {
                        $insertColumns[] = 'scanned_by';
                        $insertValues[] = ':scanned_by';
                        $insertParams[':scanned_by'] = $loginName;
                    }

                    if (hasColumn($inventoryColumns, 'scanned_at')) {
                        $insertColumns[] = 'scanned_at';
                        $insertValues[] = 'NOW()';
                    }

                    if (hasColumn($inventoryColumns, 'received_from')) {
                        $insertColumns[] = 'received_from';
                        $insertValues[] = ':received_from';
                        $insertParams[':received_from'] = $receivedFrom;
                    }

                    if (hasColumn($inventoryColumns, 'received_at')) {
                        $insertColumns[] = 'received_at';
                        $insertValues[] = 'NOW()';
                    }

                    if (hasColumn($inventoryColumns, 'remark')) {
                        $insertColumns[] = 'remark';
                        $insertValues[] = ':remark';
                        $insertParams[':remark'] = $remark !== '' ? $remark : null;
                    }

                    if (hasColumn($inventoryColumns, 'created_by')) {
                        $insertColumns[] = 'created_by';
                        $insertValues[] = ':created_by';
                        $insertParams[':created_by'] = $loginName;
                    }

                    if (hasColumn($inventoryColumns, 'created_at')) {
                        $insertColumns[] = 'created_at';
                        $insertValues[] = 'NOW()';
                    }

                    $sqlInsert = 'INSERT INTO harddisk_inventory (' . implode(', ', $insertColumns) . ') VALUES (' . implode(', ', $insertValues) . ')';
                    $stmtInsert = $pdo->prepare($sqlInsert);
                    $stmtInsert->execute($insertParams);

                    header('Location: index.php?saved=1&serial=' . urlencode($hddSerial));
                    exit;
                }
            } catch (Throwable $e) {
                $errors[] = 'ไม่สามารถบันทึก HDD เข้าคลังได้: ' . $e->getMessage();
            }
        }
    } elseif ($formAction === 'edit') {
        if (!$canManageInventory) {
            $errors[] = 'ไม่มีสิทธิ์แก้ไขข้อมูล HDD ในคลัง';
        } else {
            $inventoryId = max(0, (int)($_POST['inventory_id'] ?? 0));
            $status = strtolower(cleanText($_POST['status'] ?? ''));
            $receivedFrom = cleanText($_POST['received_from'] ?? 'IT Stock');
            $remark = cleanText($_POST['remark'] ?? '');

            $allowedStatuses = ['available', 'reserved', 'shipped', 'used', 'damaged', 'cancelled'];

            if ($inventoryId <= 0) {
                $errors[] = 'ไม่พบรหัสรายการ Harddisk';
            }
            if (!in_array($status, $allowedStatuses, true)) {
                $errors[] = 'สถานะ Harddisk ไม่ถูกต้อง';
            }
            if (!array_key_exists($receivedFrom, $receivedFromOptions)) {
                $receivedFrom = 'IT Stock';
            }

            if (empty($errors)) {
                try {
                    $setParts = [];
                    $editParams = [':id' => $inventoryId];

                    if (hasColumn($inventoryColumns, 'status')) {
                        $setParts[] = 'status = :status';
                        $editParams[':status'] = $status;
                    }
                    if (hasColumn($inventoryColumns, 'received_from')) {
                        $setParts[] = 'received_from = :received_from';
                        $editParams[':received_from'] = $receivedFrom;
                    }
                    if (hasColumn($inventoryColumns, 'remark')) {
                        $setParts[] = 'remark = :remark';
                        $editParams[':remark'] = $remark !== '' ? $remark : null;
                    }
                    if (hasColumn($inventoryColumns, 'updated_at')) {
                        $setParts[] = 'updated_at = NOW()';
                    }
                    if (hasColumn($inventoryColumns, 'updated_by')) {
                        $setParts[] = 'updated_by = :updated_by';
                        $editParams[':updated_by'] = $loginName;
                    }

                    if (empty($setParts)) {
                        throw new RuntimeException('ไม่พบคอลัมน์ที่สามารถแก้ไขได้');
                    }

                    $stmtEdit = $pdo->prepare('UPDATE harddisk_inventory SET ' . implode(', ', $setParts) . ' WHERE id = :id');
                    $stmtEdit->execute($editParams);

                    header('Location: index.php?updated=1');
                    exit;
                } catch (Throwable $e) {
                    $errors[] = 'ไม่สามารถแก้ไขข้อมูล HDD ได้: ' . $e->getMessage();
                }
            }
        }
    }
}

$keyword = cleanText($_GET['keyword'] ?? '');
$statusFilter = cleanText($_GET['status'] ?? '');
$dateFrom = cleanText($_GET['date_from'] ?? '');
$dateTo = cleanText($_GET['date_to'] ?? '');
$perPage = (int)($_GET['per_page'] ?? 20);
$allowedPerPage = [10, 20, 50, 100];
if (!in_array($perPage, $allowedPerPage, true)) {
    $perPage = 20;
}

$page = (int)($_GET['page'] ?? 1);
$page = max(1, $page);
$offset = ($page - 1) * $perPage;

$where = [];
$params = [];

if (hasColumn($inventoryColumns, 'deleted_at')) {
    $where[] = 'deleted_at IS NULL';
}

if ($statusFilter !== '' && hasColumn($inventoryColumns, 'status')) {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
}

if ($dateFrom !== '' && hasColumn($inventoryColumns, 'created_at')) {
    $where[] = 'DATE(created_at) >= :date_from';
    $params[':date_from'] = $dateFrom;
}

if ($dateTo !== '' && hasColumn($inventoryColumns, 'created_at')) {
    $where[] = 'DATE(created_at) <= :date_to';
    $params[':date_to'] = $dateTo;
}

$keywordWhere = buildInventoryKeywordWhere($keyword, $inventoryColumns, $params);
foreach ($keywordWhere as $condition) {
    $where[] = $condition;
}

$whereSql = '';
if (!empty($where)) {
    $whereSql = 'WHERE ' . implode(' AND ', $where);
}

$summary = [
    'total' => 0,
    'available' => 0,
    'reserved' => 0,
    'damaged' => 0,
    'today' => 0,
];

$inventoryRows = [];
$totalRows = 0;
$totalPages = 1;
$statusSummaryRows = [];

try {
    $baseWhere = [];
    if (hasColumn($inventoryColumns, 'deleted_at')) {
        $baseWhere[] = 'deleted_at IS NULL';
    }
    $baseWhereSql = !empty($baseWhere) ? 'WHERE ' . implode(' AND ', $baseWhere) : '';

    $stmtTotalInv = $pdo->query('SELECT COUNT(*) FROM harddisk_inventory ' . $baseWhereSql);
    $summary['total'] = (int)$stmtTotalInv->fetchColumn();

    if (hasColumn($inventoryColumns, 'status')) {
        $summary['available'] = getCountByStatus($pdo, $inventoryColumns, 'available');
        $summary['reserved'] = getCountByStatus($pdo, $inventoryColumns, 'reserved');
        $summary['damaged'] = getCountByStatus($pdo, $inventoryColumns, 'damaged');

        $stmtStatusSummary = $pdo->query('SELECT status, COUNT(*) AS total FROM harddisk_inventory ' . $baseWhereSql . ' GROUP BY status ORDER BY total DESC');
        $statusSummaryRows = $stmtStatusSummary->fetchAll(PDO::FETCH_ASSOC);
    }

    if (hasColumn($inventoryColumns, 'created_at')) {
        $todayWhere = $baseWhere;
        $todayWhere[] = 'DATE(created_at) = CURDATE()';
        $stmtToday = $pdo->query('SELECT COUNT(*) FROM harddisk_inventory WHERE ' . implode(' AND ', $todayWhere));
        $summary['today'] = (int)$stmtToday->fetchColumn();
    }

    $stmtCount = $pdo->prepare('SELECT COUNT(*) FROM harddisk_inventory ' . $whereSql);
    bindParams($stmtCount, $params);
    $stmtCount->execute();
    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $selectColumns = [];
    foreach (['id', 'hdd_serial', 'status', 'scanned_by', 'scanned_at', 'received_from', 'received_at', 'remark', 'created_by', 'created_at', 'updated_at'] as $column) {
        if (hasColumn($inventoryColumns, $column)) {
            $selectColumns[] = $column;
        }
    }

    if (empty($selectColumns)) {
        $selectColumns[] = 'id';
    }

    $orderColumn = hasColumn($inventoryColumns, 'created_at') ? 'created_at' : 'id';

    $stmtRows = $pdo->prepare("
        SELECT " . implode(', ', $selectColumns) . "
        FROM harddisk_inventory
        " . $whereSql . "
        ORDER BY " . $orderColumn . " DESC, id DESC
        LIMIT :limit OFFSET :offset
    ");

    bindParams($stmtRows, $params);
    $stmtRows->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtRows->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtRows->execute();
    $inventoryRows = $stmtRows->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errors[] = 'ไม่สามารถดึงข้อมูลคลัง HDD ได้: ' . $e->getMessage();
}

$statusOptions = [
    '' => 'ทั้งหมด',
    'available' => 'พร้อมใช้งาน',
    'reserved' => 'จองไว้',
    'shipped' => 'จัดส่งแล้ว',
    'used' => 'ใช้งานแล้ว',
    'damaged' => 'ชำรุด',
    'cancelled' => 'ยกเลิก',
];

$hasActiveSearch = ($keyword !== '' || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== '');

/*
|--------------------------------------------------------------------------
| Shipment Report Period Standard: Inventory Export
|--------------------------------------------------------------------------
| ส่งออกข้อมูลทั้งหมดตามคำค้นหา สถานะ และช่วงวัน/เดือน/ปี โดยไม่จำกัดหน้า
*/
$inventoryExportType = strtolower(cleanText($_GET['export'] ?? ''));
if (in_array($inventoryExportType, ['csv', 'excel', 'pdf'], true)) {
    require_login();
    require_permission('inventory.view');

    $exportSelectColumns = [];
    foreach (['id', 'hdd_serial', 'status', 'scanned_by', 'scanned_at', 'received_from', 'received_at', 'remark', 'created_by', 'created_at'] as $column) {
        if (hasColumn($inventoryColumns, $column)) {
            $exportSelectColumns[] = $column;
        }
    }
    if (empty($exportSelectColumns)) {
        $exportSelectColumns[] = 'id';
    }

    $exportOrderColumn = hasColumn($inventoryColumns, 'created_at') ? 'created_at' : 'id';
    $stmtExport = $pdo->prepare(
        'SELECT ' . implode(', ', $exportSelectColumns) .
        ' FROM harddisk_inventory ' . $whereSql .
        ' ORDER BY ' . $exportOrderColumn . ' DESC, id DESC'
    );
    bindParams($stmtExport, $params);
    $stmtExport->execute();
    $exportRows = $stmtExport->fetchAll(PDO::FETCH_ASSOC);

    $reportRows = [];
    foreach ($exportRows as $index => $row) {
        $receivedDate = $row['scanned_at'] ?? ($row['received_at'] ?? ($row['created_at'] ?? ''));
        $reportRows[] = [
            'ลำดับ' => $index + 1,
            'Serial HDD' => cleanText($row['hdd_serial'] ?? '') ?: '-',
            'สถานะ' => inventoryStatusText($row['status'] ?? ''),
            'รับมาจาก' => cleanText($row['received_from'] ?? '') ?: '-',
            'ผู้สแกน/ผู้บันทึก' => displayNameWithoutEmployeeCode($row['scanned_by'] ?? ($row['created_by'] ?? '-')),
            'วันที่รับเข้า' => formatThaiDateTime($receivedDate),
            'หมายเหตุ' => cleanText($row['remark'] ?? '') ?: '-',
        ];
    }

    $reportDateFrom = cleanText($_GET['date_from'] ?? '');
    $reportDateTo = cleanText($_GET['date_to'] ?? '');
    $reportPeriodText = 'ทุกช่วงเวลา';
    if ($reportDateFrom !== '' && $reportDateTo !== '') {
        $reportPeriodText = formatThaiDate($reportDateFrom) . ' ถึง ' . formatThaiDate($reportDateTo);
    } elseif ($reportDateFrom !== '') {
        $reportPeriodText = 'ตั้งแต่ ' . formatThaiDate($reportDateFrom);
    } elseif ($reportDateTo !== '') {
        $reportPeriodText = 'ถึง ' . formatThaiDate($reportDateTo);
    }

    $fileBase = 'harddisk_inventory_' . date('Ymd_His');
    $headers = ['ลำดับ', 'Serial HDD', 'สถานะ', 'รับมาจาก', 'ผู้สแกน/ผู้บันทึก', 'วันที่รับเข้า', 'หมายเหตุ'];

    if ($inventoryExportType === 'csv') {
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

    if ($inventoryExportType === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.xls"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo "\xEF\xBB\xBF";
        ?><!doctype html><html lang="th"><head><meta charset="utf-8"><style>
        body{font-family:Tahoma,Arial,sans-serif;font-size:11pt}table{border-collapse:collapse;width:100%}th,td{border:1px solid #777;padding:5px;vertical-align:top}th{background:#dbeafe;color:#0f172a;font-weight:bold}.text{mso-number-format:"\\@"}
        </style></head><body>
        <h2>รายงานคลัง Harddisk</h2>
        <div>ช่วงรายงาน: <?php echo h($reportPeriodText); ?> | วันที่ออกรายงาน: <?php echo h(date('d/m/Y H:i')); ?> | จำนวน <?php echo number_format(count($reportRows)); ?> รายการ</div><br>
        <table><thead><tr><?php foreach ($headers as $column): ?><th><?php echo h($column); ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($reportRows as $reportRow): ?><tr><?php foreach ($reportRow as $key => $value): ?><td class="<?php echo $key === 'Serial HDD' ? 'text' : ''; ?>"><?php echo h((string)$value); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></body></html><?php
        exit;
    }

    ?><!doctype html><html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายงานคลัง Harddisk</title><style>
    @page{size:A4 landscape;margin:10mm}*{box-sizing:border-box}body{font-family:Tahoma,Arial,sans-serif;color:#111827;margin:0;font-size:10px}.toolbar{display:flex;justify-content:space-between;align-items:center;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:10px}.toolbar button,.toolbar a{border:0;border-radius:6px;padding:7px 12px;text-decoration:none;font-weight:700;cursor:pointer}.print-btn{background:#2563eb;color:#fff}.back-btn{background:#e5e7eb;color:#111827}h1{font-size:18px;margin:0 0 4px}.meta{color:#475569;margin-bottom:8px}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #94a3b8;padding:4px 5px;vertical-align:top;overflow-wrap:anywhere}th{background:#dbeafe;font-weight:800;text-align:left}th:nth-child(1),td:nth-child(1){width:5%;text-align:center}th:nth-child(2),td:nth-child(2){width:18%}th:nth-child(3),td:nth-child(3){width:12%}th:nth-child(4),td:nth-child(4){width:13%}th:nth-child(5),td:nth-child(5){width:18%}th:nth-child(6),td:nth-child(6){width:15%}th:nth-child(7),td:nth-child(7){width:19%}@media print{.toolbar{display:none}body{font-size:9px}thead{display:table-header-group}tr{page-break-inside:avoid}}
    </style></head><body>
    <div class="toolbar"><div><strong>ตัวอย่างรายงาน PDF</strong><div>กด “พิมพ์ / บันทึก PDF” แล้วเลือก Save as PDF</div></div><div><a class="back-btn" href="index.php">กลับ</a> <button class="print-btn" type="button" onclick="window.print()">พิมพ์ / บันทึก PDF</button></div></div>
    <h1>รายงานคลัง Harddisk</h1>
    <div class="meta">ช่วงรายงาน: <?php echo h($reportPeriodText); ?> | วันที่ออกรายงาน: <?php echo h(date('d/m/Y H:i')); ?> | จำนวน <?php echo number_format(count($reportRows)); ?> รายการ</div>
    <table><thead><tr><?php foreach ($headers as $column): ?><th><?php echo h($column); ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php if (!$reportRows): ?><tr><td colspan="7" style="text-align:center;padding:20px">ไม่พบข้อมูลตามเงื่อนไขที่เลือก</td></tr><?php endif; ?>
    <?php foreach ($reportRows as $reportRow): ?><tr><?php foreach ($reportRow as $value): ?><td><?php echo h((string)$value); ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table><script>window.addEventListener('load',function(){setTimeout(function(){window.print();},300);});</script></body></html><?php
    exit;
}

require_once __DIR__ . '/../../includes/header.php';

require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_permission('inventory.edit');
} else {
    require_permission('inventory.view');
}

?>

<style>
    body { background: #f3f6fb; }
    .inventory-page { padding: 0; }
    .inventory-title { font-size: clamp(20px, 1.25vw, 26px); font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .inventory-subtitle { font-size: clamp(12px, .8vw, 14px); color: #64748b; }
    .inventory-toolbar { gap: 8px; }
    .inventory-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .inventory-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 12px 16px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .inventory-card .card-body { padding: 14px; }
    .hero-card { border: 0; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #ffffff; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22); }
    .hero-card .card-body { padding: 12px 16px; }
    .kpi-card { border: 0; border-radius: 15px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07); height: 100%; }
    .kpi-card .card-body { padding: 13px 15px; }
    .kpi-label { color: #64748b; font-size: 12px; margin-bottom: 4px; }
    .kpi-value { font-size: clamp(25px, 1.75vw, 34px); font-weight: 900; color: #0f172a; line-height: 1; }
    .kpi-note { color: #94a3b8; font-size: 11px; margin-top: 5px; }
    .form-label { font-size: 13px; font-weight: 800; color: #334155; margin-bottom: 4px; }
    .form-control, .form-select { font-size: 13px; border-radius: 10px; }
    .btn { border-radius: 10px; }
    .btn-sm { font-size: 12px; padding: 4px 8px; }
    .step-box { border: 1px solid #e2e8f0; border-radius: 14px; padding: 11px; background: #f8fafc; }
    .step-title { font-size: 13px; font-weight: 900; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
    .step-badge { width: 22px; height: 22px; border-radius: 8px; background: #2563eb; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
    .table-scroll { max-height: clamp(360px, calc(100vh - 390px), 680px); overflow: auto; }
    .table-inventory { min-width: 980px; }
    .table-inventory th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 12px; white-space: nowrap; padding: 8px 9px; }
    .table-inventory td { font-size: 12px; vertical-align: middle; padding: 8px 9px; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-size: 14px; font-weight: 900; color: #7c2d12; white-space: nowrap; }
    .inventory-date-cell { white-space: nowrap; overflow: visible; text-overflow: clip; }
    .inventory-status-cell { text-align: center; white-space: nowrap; }
    .inventory-status-badge {
        display: inline-block;
        padding: 0;
        border: 0;
        border-radius: 0;
        background: transparent;
        box-shadow: none;
        font-size: 11px;
        font-weight: 900;
        line-height: 1.2;
        letter-spacing: .01em;
        white-space: nowrap;
    }
    .inventory-status-available { color: #15803d; }
    .inventory-status-reserved { color: #d97706; }
    .inventory-status-shipped { color: #1d4ed8; }
    .inventory-status-used { color: #0e7490; }
    .inventory-status-damaged { color: #dc2626; }
    .inventory-status-cancelled,
    .inventory-status-secondary { color: #475569; }
    .text-ellipsis { max-width: 230px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .help-box { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 8px 10px; font-size: 12px; }
    .action-buttons { display: flex; gap: 6px; flex-wrap: nowrap; align-items: center; }
    .action-buttons form { display: inline; margin: 0; }
    .scan-status { min-height: 39px; display: flex; align-items: center; }
    .status-list { max-height: clamp(180px, 23vh, 280px); overflow: auto; }
    .status-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
    .status-row:last-child { border-bottom: 0; }
    .status-count { font-weight: 900; color: #0f172a; }
    .mini-bar { height: 6px; background: #e5e7eb; border-radius: 999px; overflow: hidden; margin-top: 5px; }
    .mini-bar-fill { height: 100%; background: #2563eb; border-radius: 999px; }
    .search-menu-card {
        border: 1px solid #dbeafe;
        border-radius: 14px;
        background: linear-gradient(135deg, #ffffff, #eff6ff);
        padding: 12px;
        margin-bottom: 10px;
    }
    .search-menu-title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 8px;
        margin-bottom: 8px;
    }
    .search-menu-title strong { font-size: 14px; color: #0f172a; }
    .search-input-lg {
        height: 42px;
        font-family: Consolas, Monaco, monospace;
        font-weight: 800;
        color: #7c2d12;
        text-transform: uppercase;
    }
    .quick-filter-wrap {
        display: flex;
        flex-wrap: wrap;
        gap: 6px;
    }
    .quick-filter {
        border: 1px solid #cbd5e1;
        background: #ffffff;
        color: #334155;
        border-radius: 999px;
        padding: 5px 10px;
        font-size: 12px;
        font-weight: 800;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 4px;
    }
    .quick-filter:hover { background: #eff6ff; color: #1d4ed8; border-color: #93c5fd; }
    .quick-filter.active { background: #2563eb; border-color: #2563eb; color: #ffffff; }
    .search-result-note {
        border-radius: 10px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 7px 9px;
        color: #475569;
        font-size: 12px;
    }
    .inventory-search-row{
        display:grid!important;
        grid-template-columns:minmax(260px,2.8fr) minmax(105px,.9fr) minmax(135px,1.15fr) minmax(135px,1.15fr) minmax(70px,.55fr) minmax(90px,.72fr) minmax(100px,.78fr) minmax(105px,.82fr);
        gap:8px;
        align-items:end;
        width:100%;
        margin:0!important;
    }
    .inventory-search-row > .inventory-search-field{min-width:0;width:auto!important;padding:0!important}
    .inventory-search-row .form-label{white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
    .search-menu-card .input-group { min-width: 0; }
    .search-menu-card .btn,
    .search-menu-card .form-select,
    .search-menu-card .form-control { width: 100%; }
    .search-action-col .btn { min-width: 86px; white-space: nowrap; }

    .search-result-col .search-result-note,
    .search-clear-col .inventory-clear-search{
        min-height:38px;
        height:38px;
        display:flex;
        align-items:center;
        justify-content:center;
        text-align:center;
        white-space:nowrap;
        width:100%;
    }
    .search-result-col .search-result-note{font-size:.7rem;padding:4px 6px;line-height:1.15}
    .search-clear-col .inventory-clear-search{font-size:.72rem;font-weight:800;padding:.4rem .45rem}
    .inventory-quick-filter-row{width:100%;align-items:center;display:flex;flex-wrap:wrap;gap:6px;margin-top:10px!important;padding-top:8px;border-top:1px solid #eef2f7}


    @media (min-width: 1600px) {
        .inventory-card .card-body { padding: 16px; }
        .table-scroll { max-height: clamp(460px, calc(100vh - 405px), 760px); }
        .table-inventory th, .table-inventory td { font-size: 12.5px; padding: 9px 10px; }
        .text-ellipsis { max-width: 310px; }
    }

    @media (max-width: 1366px) {
        .search-menu-card { overflow: hidden; }
        .inventory-search-row{grid-template-columns:minmax(220px,2.5fr) minmax(90px,.85fr) minmax(120px,1.05fr) minmax(120px,1.05fr) minmax(62px,.5fr) minmax(78px,.68fr) minmax(88px,.72fr) minmax(94px,.78fr);gap:6px}
        .inventory-search-row .form-label{font-size:.66rem}
        .search-result-col .search-result-note,
        .search-clear-col .inventory-clear-search{min-height:34px;height:34px;font-size:.64rem}
        .search-action-col .btn { min-width: 0; }
        .inventory-title { font-size: 20px; }
        .inventory-subtitle { font-size: 12px; }
        .inventory-card .card-header { padding: 10px 12px; }
        .inventory-card .card-body { padding: 10px; }
        .hero-card .card-body { padding: 10px 12px; }
        .kpi-card .card-body { padding: 10px 12px; }
        .kpi-value { font-size: 25px; }
        .step-box { padding: 9px; }
        .search-menu-card { padding: 10px; }
        .table-scroll { max-height: clamp(320px, calc(100vh - 365px), 520px); }
        .table-inventory { min-width: 930px; }
        .table-inventory th, .table-inventory td { font-size: 11.5px; padding: 6px 7px; }
        .inventory-status-badge { font-size: 10.5px; white-space: nowrap; }
        .form-control, .form-select { font-size: 12px; }
        .form-label { font-size: 12px; }
        .quick-filter { font-size: 11.5px; padding: 4px 8px; }
    }

    @media (max-width: 1199.98px) {
        .inventory-search-row{grid-template-columns:repeat(4,minmax(0,1fr))}
        .inventory-search-keyword{grid-column:1/-1}
        .inventory-search-result,.inventory-search-clear{grid-column:auto}
        .inventory-main-left,
        .inventory-main-right { width: 100%; }
        .table-scroll { max-height: 520px; }
    }

    @media (max-width: 767.98px) {
        .inventory-search-row{grid-template-columns:repeat(2,minmax(0,1fr))}
        .inventory-search-keyword{grid-column:1/-1}
        .inventory-search-result,.inventory-search-clear{grid-column:auto}
        .search-result-col,.search-clear-col{width:auto}
        .inventory-quick-filter-row{margin-top:8px!important}
        .inventory-header { align-items: flex-start !important; }
        .inventory-toolbar { width: 100%; }
        .inventory-toolbar .btn { flex: 1 1 auto; }
        .hero-card .card-body { align-items: flex-start !important; }
        .search-menu-title { align-items: flex-start; flex-direction: column; }
        .table-scroll { max-height: 480px; }
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
    @media(max-width:1366px){.unified-hero{padding:15px 17px}.unified-hero h1{font-size:1.15rem}.unified-hero p{font-size:.75rem}.unified-search-card .card-body{padding:10px 12px}.unified-search-card .form-control,.unified-search-card .form-select,.unified-search-card .btn{min-height:34px;font-size:.7rem}}
    @media(max-width:767.98px){.unified-hero{padding:14px}.unified-hero-actions{width:100%;justify-content:flex-start}.unified-hero .btn{flex:1 1 auto}.unified-edit-frame{height:78vh}}


    /* Registry-style inventory layout */
    .inventory-page{width:100%;max-width:none;margin:0;padding:0 0 18px}
    .inventory-layout{display:block}
    .inventory-main-right{width:100%}
    .inventory-card{border:1px solid #e2e8f0;border-radius:16px;box-shadow:0 6px 20px rgba(15,23,42,.06)}
    .inventory-card .card-header{padding:11px 14px;background:#fbfdff;color:#17324d}
    .inventory-card .card-body{padding:12px 14px}
    .inventory-add-btn{
        position:relative;
        overflow:hidden;
        color:#0f4c81!important;
        font-weight:800!important;
        padding:.5rem .85rem!important;
        border-color:rgba(255,255,255,.72)!important;
        box-shadow:0 0 0 0 rgba(255,255,255,.52);
        animation:hddInventoryAddPulse 1.8s ease-in-out infinite;
        transform-origin:center;
        will-change:transform,box-shadow,filter;
    }
    .inventory-add-btn::after{
        content:"";
        position:absolute;
        top:-60%;
        left:-45%;
        width:34%;
        height:220%;
        background:linear-gradient(90deg,transparent,rgba(255,255,255,.88),transparent);
        transform:rotate(18deg);
        animation:hddInventoryAddShine 2.6s ease-in-out infinite;
        pointer-events:none;
    }
    .inventory-add-btn:hover,.inventory-add-btn:focus{
        transform:translateY(-1px) scale(1.035);
        filter:brightness(1.05);
        box-shadow:0 8px 20px rgba(0,188,212,.28)!important;
    }
    @keyframes hddInventoryAddPulse{
        0%,100%{transform:scale(1);box-shadow:0 0 0 0 rgba(255,255,255,.42)}
        50%{transform:scale(1.045);box-shadow:0 0 0 7px rgba(255,255,255,0)}
    }
    @keyframes hddInventoryAddShine{
        0%,28%{left:-45%;opacity:0}
        40%{opacity:1}
        58%{left:118%;opacity:0}
        100%{left:118%;opacity:0}
    }
    @media(prefers-reduced-motion:reduce){
        .inventory-add-btn,.inventory-add-btn::after{animation:none!important}
    }
    .search-menu-card{background:#fff;border:1px solid #e2e8f0;border-radius:14px;padding:12px;margin-bottom:12px}
    .search-menu-title{margin-bottom:10px}
    .quick-filter{padding:5px 10px}
    .table-scroll{max-height:calc(100vh - 340px);min-height:340px;border:1px solid #e2e8f0;border-radius:12px}
    .table-inventory{min-width:100%;width:100%;table-layout:fixed}
    .table-inventory th,.table-inventory td{font-size:.74rem;padding:.48rem .45rem}
    .table-inventory th:nth-child(1){width:4%}
    .table-inventory th:nth-child(2){width:18%}
    .table-inventory th:nth-child(3){width:11%}
    .table-inventory th:nth-child(4){width:12%}
    .table-inventory th:nth-child(5){width:18%}
    .table-inventory th:nth-child(6){width:15%}
    .table-inventory th:nth-child(7){width:17%;text-align:center}
    .table-inventory th:nth-child(8){width:5%;text-align:center}
    .inventory-edit-modal .modal-dialog{max-width:650px}
    .inventory-edit-modal .modal-content{border:0;border-radius:14px;overflow:hidden;box-shadow:0 22px 60px rgba(15,23,42,.24)}
    .inventory-edit-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff);padding:.58rem .8rem;border-bottom:1px solid #dbe5ee}
    .inventory-edit-modal .modal-title{font-size:1rem}
    .inventory-edit-modal .modal-header .small{font-size:.72rem;margin-top:1px!important}
    .inventory-edit-modal .modal-body{background:#f8fafc;padding:.48rem}
    .inventory-edit-table-wrap{background:#fff;border:1px solid #dbe5ee;border-radius:9px;overflow:hidden}
    .inventory-edit-table{width:100%;margin:0;table-layout:fixed}
    .inventory-edit-table th,.inventory-edit-table td{padding:.28rem .42rem;border-color:#dbe5ee;vertical-align:middle;font-size:.78rem;line-height:1.18}
    .inventory-edit-table th{width:29%;background:#f1f5f9;color:#475569;font-weight:800;white-space:nowrap}
    .inventory-edit-table td{background:#fff;color:#0f172a}
    .inventory-edit-table tr:nth-child(even) td{background:#f8fafc}
    .inventory-edit-readonly{background:#eef2f7!important;color:#475569!important;cursor:not-allowed}
    .inventory-edit-table .form-control,.inventory-edit-table .form-select{min-height:31px;height:31px;font-size:.78rem;border-radius:7px;padding:.2rem .45rem}
    .inventory-edit-table textarea.form-control{height:54px;min-height:54px;resize:vertical}
    .inventory-edit-table .form-text{font-size:.68rem;line-height:1.15;margin-top:2px}
    .inventory-edit-modal .modal-footer{padding:.38rem .5rem;background:#fff;border-top:1px solid #e2e8f0}
    .inventory-edit-modal .modal-footer .btn{font-size:.76rem;padding:.3rem .7rem;min-width:92px}
    @media(max-width:1366px){.inventory-edit-modal .modal-dialog{max-width:600px}.inventory-edit-table th,.inventory-edit-table td{padding:.25rem .38rem;font-size:.74rem}.inventory-edit-table .form-control,.inventory-edit-table .form-select{min-height:30px;height:30px;font-size:.74rem}}
    @media(max-width:767.98px){.inventory-edit-modal .modal-dialog{margin:.5rem}.inventory-edit-table{table-layout:auto}.inventory-edit-table th{width:36%;white-space:normal}.inventory-edit-table th,.inventory-edit-table td{font-size:.72rem}}

    .inventory-report-table{font-size:.78rem}.inventory-report-table th{width:190px;background:#f1f5f9;color:#475569;font-weight:800;white-space:nowrap}.inventory-report-table th,.inventory-report-table td{padding:.5rem .65rem}.inventory-report-table .form-control,.inventory-report-table .form-select{min-height:36px;font-size:.76rem}
    @media(max-width:767.98px){.inventory-report-table th{width:135px;white-space:normal}.inventory-report-table th,.inventory-report-table td{padding:.42rem .5rem}}

    .inventory-create-modal .modal-dialog{max-width:620px}
    .inventory-create-modal .modal-content{border:0;border-radius:18px;overflow:hidden;box-shadow:0 22px 60px rgba(15,23,42,.24)}
    .inventory-create-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff);padding:13px 16px}
    .inventory-create-modal .modal-body{background:#f8fafc;padding:16px}
    .inventory-serial-input{font-family:Consolas,Monaco,monospace;font-weight:900;text-transform:uppercase;letter-spacing:.5px}
    .inventory-auto-note{background:#eff6ff;border:1px solid #bfdbfe;border-radius:10px;padding:9px 11px;color:#1e3a8a;font-size:.78rem}
    @media(max-width:1366px){.inventory-page{padding:0 0 18px}.table-scroll{max-height:calc(100vh - 315px)}.table-inventory th,.table-inventory td{font-size:.68rem;padding:.4rem .32rem}.unified-hero{margin-bottom:10px}.search-menu-card{padding:10px}}
    @media(max-width:1100px){.table-inventory{min-width:980px}.table-scroll{overflow:auto}}


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

<div class="container-fluid inventory-page">
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
                <?php if ($myHddRequestCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($myHddRequestCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('shipment.view')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/shipments/index.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('history'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">ประวัติการจัดส่ง</span><span class="hdd-module-menu-note">ดูรายการจัดส่งย้อนหลัง</span></span>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('inventory.view')): ?>
            <a class="hdd-module-menu-item active hdd-active-menu-blink" href="<?php echo $baseUrl; ?>/modules/inventory/index.php" aria-current="page">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('warehouse'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">คลัง Harddisk</span><span class="hdd-module-menu-note">ตรวจสอบสต็อกพร้อมใช้งาน</span></span>
                <?php if ($availableInventoryCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($availableInventoryCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('claim.view')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/claim_returns/index.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('return'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">รับคืน / ส่งเคลม</span><span class="hdd-module-menu-note">จัดการคืนและเคลม HDD</span></span>
                <?php if ($claimReturnPendingCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($claimReturnPendingCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
        </nav>

    <div class="unified-hero d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div><h1>คลัง Harddisk</h1>
        <!-- <p>บันทึก HDD เข้าคลัง ติดตามสถานะ และค้นหารายการ Harddisk ได้จากหน้าเดียว</p> -->
    </div>
        <div class="unified-hero-actions">
            <div class="unified-total">ข้อมูลทั้งหมด <strong><?php echo number_format($summary['total']); ?> เครื่อง</strong></div>
            <div class="unified-total">พร้อมใช้งาน <strong><?php echo number_format($summary['available']); ?></strong></div>
            <button type="button" class="btn btn-light" data-bs-toggle="modal" data-bs-target="#inventoryReportModal"><i class="bi bi-file-earmark-bar-graph me-1"></i>ออกรายงาน</button>
            <button type="button" class="btn btn-light hdd-primary-action-btn" data-bs-toggle="modal" data-bs-target="#inventoryCreateModal">+ เพิ่ม Harddisk</button>
        </div>
    </div>

    <?php if (isset($_GET['saved'])): ?>
        <div class="alert alert-success py-2 mb-2">
            บันทึก HDD เข้าคลังเรียบร้อยแล้ว Serial: <strong><?php echo h($_GET['serial'] ?? ''); ?></strong>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['updated'])): ?>
        <div class="alert alert-success py-2 mb-2">
            แก้ไขข้อมูล HDD ในคลังเรียบร้อยแล้ว
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['deleted'])): ?>
        <div class="alert alert-success py-2 mb-2">
            ลบรายการ HDD ออกจากคลังเรียบร้อยแล้ว
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'permission_denied'): ?>
        <div class="alert alert-danger py-2 mb-2">
            ไม่มีสิทธิ์ดำเนินการกับรายการ HDD ในคลัง
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['error']) && $_GET['error'] === 'delete_failed'): ?>
        <div class="alert alert-danger py-2 mb-2">
            ไม่สามารถลบรายการ HDD ได้ กรุณาลองใหม่อีกครั้ง
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
                <div class="small opacity-75">เมื่อยิงบาร์โค้ดเข้าคลัง ระบบจะบันทึกผู้สแกนจาก User ที่ Login อยู่ และตั้งสถานะเป็น “พร้อมใช้งาน”</div>
            </div>
            <div class="small">ผู้ใช้งานปัจจุบัน: <strong><?php echo h($loginName); ?></strong></div>
        </div>
    </div>

    <div class="inventory-layout">
        <div class="inventory-create-source d-none">
            <div class="card inventory-card h-100">
                <div class="card-header">บันทึก HDD เข้าคลัง</div>
                <div class="card-body">
                    <form method="post" id="inventoryForm" autocomplete="off">
                        <input type="hidden" name="form_action" value="create">

                        <div class="step-box mb-2">
                            <div class="step-title"><span class="step-badge">1</span> ยิงบาร์โค้ด / กรอก Serial HDD</div>
                            <label class="form-label">Serial HDD <span class="text-danger">*</span></label>
                            <input
                                type="text"
                                name="hdd_serial"
                                id="hdd_serial"
                                class="form-control form-control-lg"
                                value="<?php echo h($_POST['hdd_serial'] ?? ''); ?>"
                                placeholder="ยิงบาร์โค้ด HDD เช่น WWD567GB"
                                autocomplete="off"
                                spellcheck="false"
                                pattern="[A-Za-z0-9]+"
                                required
                                autofocus
                            >
                            <div class="form-text">ระบบรับเฉพาะตัวอักษรภาษาอังกฤษและตัวเลขเท่านั้น</div>
                            <div class="invalid-feedback">Serial HDD ต้องเป็นภาษาอังกฤษและตัวเลขเท่านั้น</div>
                            <div id="scanStatus" class="alert alert-info py-2 mb-0 mt-2 scan-status">รอยิงบาร์โค้ด...</div>
                        </div>

                        <div class="step-box mb-2">
                            <div class="step-title"><span class="step-badge">2</span> รายละเอียดรับเข้าคลัง</div>
                            <div class="mb-2">
                                <label class="form-label">รับมาจาก</label>
                                <select name="received_from" class="form-select">
                                    <?php
                                    $selectedReceivedFrom = cleanText($_POST['received_from'] ?? 'IT Stock');
                                    if ($selectedReceivedFrom === '' || !array_key_exists($selectedReceivedFrom, $receivedFromOptions)) {
                                        $selectedReceivedFrom = 'IT Stock';
                                    }
                                    foreach ($receivedFromOptions as $value => $label):
                                    ?>
                                        <option value="<?php echo h($value); ?>" <?php echo $selectedReceivedFrom === $value ? 'selected' : ''; ?>>
                                            <?php echo h($label); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                                <div class="form-text">เลือกแหล่งที่มาของ HDD ที่รับเข้าคลัง</div>
                            </div>
                            <div>
                                <label class="form-label">หมายเหตุ</label>
                                <textarea name="remark" class="form-control" rows="3" placeholder="รายละเอียดเพิ่มเติมถ้ามี"><?php echo h($_POST['remark'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="help-box mb-2">
                            ระบบจะบันทึกผู้สแกน/ผู้บันทึกเป็น <strong><?php echo h($loginName); ?></strong> และตั้งสถานะ HDD เป็น “พร้อมใช้งาน” อัตโนมัติ
                        </div>

                        <div class="d-grid">
                            <button type="submit" class="btn btn-success">บันทึก HDD เข้าคลัง</button>
                        </div>
                    </form>

                    <?php if (!empty($statusSummaryRows)): ?>
                        <div class="step-box mt-2">
                            <div class="step-title"><span class="step-badge">3</span> สรุปสถานะในคลัง</div>
                            <div class="status-list">
                                <?php foreach ($statusSummaryRows as $row): ?>
                                    <?php
                                    $count = (int)($row['total'] ?? 0);
                                    $percent = $summary['total'] > 0 ? min(100, round(($count / $summary['total']) * 100)) : 0;
                                    ?>
                                    <div class="status-row">
                                        <div class="flex-grow-1">
                                            <?php echo inventoryStatusBadge($row['status'] ?? ''); ?>
                                            <div class="mini-bar"><div class="mini-bar-fill" style="width: <?php echo (int)$percent; ?>%;"></div></div>
                                        </div>
                                        <div class="status-count"><?php echo number_format($count); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="inventory-main-right">
            <div class="card inventory-card">
                <!-- <div class="card-header">
                    <span>รายการ Harddisk ในคลัง</span>
                    <span class="text-muted small">ทั้งหมด <?php echo number_format($totalRows); ?> รายการ</span>
                </div> -->
                <div class="card-body">
                    <div class="card inventory-card unified-search-card hdd-search-card mb-2">
                        <div class="card-body">
                        <form method="get" id="inventorySearchForm" autocomplete="off">
                            <!-- <div class="search-menu-title">
                                <strong>เมนูค้นหาข้อมูล Harddisk</strong>
                                <?php if ($hasActiveSearch): ?>
                                    <span class="badge bg-primary">กำลังกรองข้อมูล</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">แสดงทั้งหมด</span>
                                <?php endif; ?>
                            </div> -->

                            <div class="shipment-filter-row hdd-unified-search-row hdd-fields-7">
                                <div class="shipment-filter-keyword hdd-search-keyword">
                                    <label for="inventorySearchInput" class="form-label">ช่องค้นหา</label>
                                    <input
                                        type="text"
                                        name="keyword"
                                        id="inventorySearchInput"
                                        class="form-control"
                                        value="<?php echo h($keyword); ?>"
                                        placeholder="Serial HDD, ผู้บันทึก หรือแหล่งที่มา"
                                    >
                                </div>
                                <div >
                                    <label class="form-label">วันที่เริ่มต้น</label>
                                    <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                                </div>
                                <div >
                                    <label class="form-label">วันที่สิ้นสุด</label>
                                    <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                                </div>
                                <div >
                                    <label class="form-label">สถานะ</label>
                                    <select name="status" class="form-select">
                                        <?php foreach ($statusOptions as $value => $label): ?>
                                            <option value="<?php echo h($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div >
                                    <label class="form-label">แสดง</label>
                                    <select name="per_page" class="form-select">
                                        <?php foreach ($allowedPerPage as $n): ?>
                                            <option value="<?php echo (int)$n; ?>" <?php echo $perPage === $n ? 'selected' : ''; ?>><?php echo (int)$n; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="shipment-filter-action">
                                    <label class="form-label">&nbsp;</label>
                                    <button type="submit" class="btn btn-primary w-100">ค้นหา</button>
                                </div>
                                <div class="shipment-filter-action">
                                    <label class="form-label">&nbsp;</label>
                                    <a href="index.php" class="btn btn-outline-secondary w-100">ล้างค่า</a>
                                </div>
                            </div>

                            <!-- <div class="quick-filter-wrap inventory-quick-filter-row mt-2">
                                <a href="index.php" class="quick-filter <?php echo $statusFilter === '' ? 'active' : ''; ?>">ทั้งหมด</a>
                                <a href="?status=available&per_page=<?php echo (int)$perPage; ?>" class="quick-filter <?php echo $statusFilter === 'available' ? 'active' : ''; ?>">พร้อมใช้งาน</a>
                                <a href="?status=reserved&per_page=<?php echo (int)$perPage; ?>" class="quick-filter <?php echo $statusFilter === 'reserved' ? 'active' : ''; ?>">จองไว้</a>
                                <a href="?status=shipped&per_page=<?php echo (int)$perPage; ?>" class="quick-filter <?php echo $statusFilter === 'shipped' ? 'active' : ''; ?>">จัดส่งแล้ว</a>
                                <a href="?status=damaged&per_page=<?php echo (int)$perPage; ?>" class="quick-filter <?php echo $statusFilter === 'damaged' ? 'active' : ''; ?>">ชำรุด</a>
                            </div> -->
                        </form>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center mb-2 small text-muted">
                        <div>หน้า <?php echo number_format($page); ?> / <?php echo number_format($totalPages); ?></div>
                        <div>แสดง <?php echo number_format(count($inventoryRows)); ?> รายการ</div>
                    </div>

                    <div class="table-responsive table-scroll">
                        <table class="table table-hover table-bordered align-middle mb-0 table-inventory">
                            <thead>
                                <tr>
                                    <th style="width:60px;">ลำดับ</th>
                                    <th>Serial HDD</th>
                                    <th>สถานะ</th>
                                    <th>รับมาจาก</th>
                                    <th>ผู้สแกน/ผู้บันทึก</th>
                                    <th>วันที่รับเข้า</th>
                                    <th>หมายเหตุ</th>
                                    <?php if ($canManageInventory): ?>
                                        <th style="width:150px;">จัดการ</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($inventoryRows)): ?>
                                    <tr>
                                        <td colspan="<?php echo $canManageInventory ? 8 : 7; ?>" class="text-center text-muted py-4">ไม่พบข้อมูล Harddisk ในคลัง</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inventoryRows as $index => $row): ?>
                                        <?php
                                        $runningNo = $offset + $index + 1;
                                        $displayUser = displayNameWithoutEmployeeCode($row['scanned_by'] ?? ($row['created_by'] ?? '-'));
                                        ?>
                                        <tr>
                                            <td class="text-center"><?php echo number_format($runningNo); ?></td>
                                            <td><span class="serial-text"><?php echo h($row['hdd_serial'] ?? '-'); ?></span></td>
                                            <td class="inventory-status-cell"><?php echo inventoryStatusBadge($row['status'] ?? ''); ?></td>
                                            <td><div class="text-ellipsis" title="<?php echo h($row['received_from'] ?? '-'); ?>"><?php echo h($row['received_from'] ?? '-'); ?></div></td>
                                            <td><div class="text-ellipsis" title="<?php echo h($displayUser); ?>"><?php echo h($displayUser ?: '-'); ?></div></td>
                                            <td class="inventory-date-cell"><?php echo h(formatThaiDateTime($row['scanned_at'] ?? ($row['received_at'] ?? ($row['created_at'] ?? '')))); ?></td>
                                            <td><div class="text-ellipsis" title="<?php echo h($row['remark'] ?? '-'); ?>"><?php echo h(($row['remark'] ?? '') !== '' ? $row['remark'] : '-'); ?></div></td>
                                            <?php if ($canManageInventory): ?>
                                                <td class="text-nowrap">
                                                    <div class="action-buttons">
                                                        <button type="button" class="btn btn-sm btn-outline-primary js-edit-inventory"
                                                            data-bs-toggle="modal" data-bs-target="#inventoryEditModal"
                                                            data-id="<?php echo (int)($row['id'] ?? 0); ?>"
                                                            data-serial="<?php echo h($row['hdd_serial'] ?? ''); ?>"
                                                            data-status="<?php echo h($row['status'] ?? 'available'); ?>"
                                                            data-received-from="<?php echo h($row['received_from'] ?? 'IT Stock'); ?>"
                                                            data-remark="<?php echo h($row['remark'] ?? ''); ?>"
                                                            data-scanned-by="<?php echo h($displayUser ?: '-'); ?>"
                                                            data-scanned-at="<?php echo h(formatThaiDateTime($row['scanned_at'] ?? '')); ?>">แก้ไข</button>
                                                        <form method="post" action="delete.php" data-confirm-message="ยืนยันการลบ HDD Serial <?php echo h($row['hdd_serial'] ?? ''); ?> ออกจากคลังหรือไม่?">
                                                            <input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                                            <button type="submit" class="btn btn-sm btn-outline-danger">ลบ</button>
                                                        </form>
                                                    </div>
                                                </td>
                                            <?php endif; ?>
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

<div class="modal fade unified-action-modal" id="inventoryReportModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div><h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-bar-graph me-1 text-primary"></i>ออกรายงานคลัง Harddisk</h5><div class="small text-muted mt-1">เลือกช่วงวัน เดือน หรือปี และรูปแบบไฟล์</div></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <div class="table-responsive border rounded-3 bg-white">
          <table class="table table-bordered align-middle mb-0 inventory-report-table">
            <tbody>
              <tr><th>ช่วงรายงาน <span class="text-danger">*</span></th><td><select class="form-select" id="inventory_report_period_type"><option value="day">ช่วงวัน</option><option value="month">ช่วงเดือน</option><option value="year">ช่วงปี</option></select></td></tr>
              <tr id="inventory_report_day_from_row"><th>ตั้งแต่วันที่ <span class="text-danger">*</span></th><td><input type="date" class="form-control" id="inventory_report_day_from" value="<?php echo h(date('Y-m-d')); ?>"></td></tr>
              <tr id="inventory_report_day_to_row"><th>ถึงวันที่ <span class="text-danger">*</span></th><td><input type="date" class="form-control" id="inventory_report_day_to" value="<?php echo h(date('Y-m-d')); ?>"></td></tr>
              <tr id="inventory_report_month_from_row" class="d-none"><th>ตั้งแต่เดือน <span class="text-danger">*</span></th><td><input type="month" class="form-control" id="inventory_report_month_from" value="<?php echo h(date('Y-m')); ?>"></td></tr>
              <tr id="inventory_report_month_to_row" class="d-none"><th>ถึงเดือน <span class="text-danger">*</span></th><td><input type="month" class="form-control" id="inventory_report_month_to" value="<?php echo h(date('Y-m')); ?>"></td></tr>
              <tr id="inventory_report_year_from_row" class="d-none"><th>ตั้งแต่ปี ค.ศ. <span class="text-danger">*</span></th><td><input type="number" class="form-control" id="inventory_report_year_from" min="2000" max="2100" value="<?php echo h(date('Y')); ?>"></td></tr>
              <tr id="inventory_report_year_to_row" class="d-none"><th>ถึงปี ค.ศ. <span class="text-danger">*</span></th><td><input type="number" class="form-control" id="inventory_report_year_to" min="2000" max="2100" value="<?php echo h(date('Y')); ?>"></td></tr>
              <tr><th>รูปแบบรายงาน <span class="text-danger">*</span></th><td><select class="form-select" id="inventory_report_format"><option value="excel">Excel</option><option value="pdf">PDF</option><option value="csv">CSV</option></select></td></tr>
            </tbody>
          </table>
        </div>
        <div class="alert alert-info py-2 mt-2 mb-0 small">ระบบจะใช้คำค้นหาและสถานะปัจจุบันร่วมกับช่วงเวลาที่เลือก และส่งออกข้อมูลทั้งหมดโดยไม่จำกัดหน้า</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button><button type="button" class="btn btn-primary" id="inventory_report_submit"><i class="bi bi-download me-1"></i>ออกรายงาน</button></div>
    </div>
  </div>
</div>

<div class="modal fade inventory-create-modal" id="inventoryCreateModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div><h5 class="modal-title fw-bold">เพิ่ม Harddisk เข้าคลัง</h5><div class="small text-muted mt-1">ยิง Serial HDD แล้วระบบบันทึกเข้าคลังอัตโนมัติ</div></div>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
      </div>
      <div class="modal-body">
        <form method="post" id="inventoryModalForm" autocomplete="off">
          <input type="hidden" name="form_action" value="create">
          <div class="mb-3">
            <label class="form-label">Serial HDD <span class="text-danger">*</span></label>
            <input type="text" name="hdd_serial" id="modal_hdd_serial" class="form-control form-control-lg inventory-serial-input" placeholder="ยิงบาร์โค้ด HDD เช่น WWD567GB" pattern="[A-Za-z0-9]+" required>
            <div class="form-text">รองรับเฉพาะตัวอักษรภาษาอังกฤษและตัวเลข ระบบจะบันทึกอัตโนมัติหลังยิง Serial</div>
          </div>
          <div class="row g-2">
            <div class="col-md-5"><label class="form-label">รับมาจาก</label><select name="received_from" class="form-select"><?php foreach ($receivedFromOptions as $value => $label): ?><option value="<?php echo h($value); ?>"><?php echo h($label); ?></option><?php endforeach; ?></select></div>
            <div class="col-md-7"><label class="form-label">หมายเหตุ</label><input type="text" name="remark" class="form-control" placeholder="รายละเอียดเพิ่มเติมถ้ามี"></div>
          </div>
          <div class="inventory-auto-note mt-3">ระบบจะบันทึกผู้สแกนเป็น <strong><?php echo h($loginName); ?></strong> และตั้งสถานะเป็น “พร้อมใช้งาน” อัตโนมัติ</div>
        </form>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" form="inventoryModalForm" class="btn btn-success px-4">บันทึกเข้าคลัง</button></div>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const inventoryReportPeriodType = document.getElementById('inventory_report_period_type');
    const inventoryReportRows = {
        day: [document.getElementById('inventory_report_day_from_row'), document.getElementById('inventory_report_day_to_row')],
        month: [document.getElementById('inventory_report_month_from_row'), document.getElementById('inventory_report_month_to_row')],
        year: [document.getElementById('inventory_report_year_from_row'), document.getElementById('inventory_report_year_to_row')]
    };
    function updateInventoryReportFields() {
        const selectedType = inventoryReportPeriodType ? inventoryReportPeriodType.value : 'day';
        Object.keys(inventoryReportRows).forEach(function(type){
            inventoryReportRows[type].forEach(function(row){ if(row) row.classList.toggle('d-none', type !== selectedType); });
        });
    }
    if (inventoryReportPeriodType) {
        inventoryReportPeriodType.addEventListener('change', updateInventoryReportFields);
        updateInventoryReportFields();
    }
    const inventoryReportSubmit = document.getElementById('inventory_report_submit');
    if (inventoryReportSubmit) inventoryReportSubmit.addEventListener('click', function(){
        const type = inventoryReportPeriodType ? inventoryReportPeriodType.value : 'day';
        const format = document.getElementById('inventory_report_format')?.value || 'excel';
        let dateFrom = '';
        let dateTo = '';
        if (type === 'day') {
            dateFrom = document.getElementById('inventory_report_day_from')?.value || '';
            dateTo = document.getElementById('inventory_report_day_to')?.value || '';
            if (!dateFrom || !dateTo) { alert('กรุณาเลือกวันที่เริ่มต้นและวันที่สิ้นสุด'); return; }
        } else if (type === 'month') {
            const monthFrom = document.getElementById('inventory_report_month_from')?.value || '';
            const monthTo = document.getElementById('inventory_report_month_to')?.value || '';
            if (!/^\d{4}-\d{2}$/.test(monthFrom) || !/^\d{4}-\d{2}$/.test(monthTo)) { alert('กรุณาเลือกเดือนเริ่มต้นและเดือนสิ้นสุด'); return; }
            dateFrom = monthFrom + '-01';
            const parts = monthTo.split('-');
            const lastDay = new Date(Number(parts[0]), Number(parts[1]), 0).getDate();
            dateTo = monthTo + '-' + String(lastDay).padStart(2, '0');
        } else {
            const yearFrom = String(document.getElementById('inventory_report_year_from')?.value || '').trim();
            const yearTo = String(document.getElementById('inventory_report_year_to')?.value || '').trim();
            if (!/^\d{4}$/.test(yearFrom) || !/^\d{4}$/.test(yearTo)) { alert('กรุณาระบุปีเริ่มต้นและปีสิ้นสุดเป็นปี ค.ศ. 4 หลัก'); return; }
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

    const savedSuccessModal = document.getElementById('inventorySaveSuccessModal');
    <?php if (isset($_GET['saved'])): ?>
    if (savedSuccessModal && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(savedSuccessModal).show();
    }
    <?php endif; ?>
    const inventoryEditForm = document.getElementById('inventoryEditForm');
    document.querySelectorAll('.js-edit-inventory').forEach(function(button){
        button.addEventListener('click', function(){
            if (!inventoryEditForm) return;
            inventoryEditForm.reset();
            document.getElementById('edit_inventory_id').value = button.dataset.id || '';
            document.getElementById('edit_inventory_serial').value = button.dataset.serial || '-';
            document.getElementById('edit_inventory_status').value = button.dataset.status || 'available';
            document.getElementById('edit_inventory_received_from').value = button.dataset.receivedFrom || 'IT Stock';
            document.getElementById('edit_inventory_scanned_by').value = button.dataset.scannedBy || '-';
            document.getElementById('edit_inventory_scanned_at').value = button.dataset.scannedAt || '-';
            document.getElementById('edit_inventory_remark').value = button.dataset.remark || '';
            const subtitle = document.getElementById('inventoryEditSubtitle');
            if (subtitle) subtitle.textContent = 'Serial: ' + (button.dataset.serial || '-');
        });
    });

    const modalSerialInput = document.getElementById('modal_hdd_serial');
    const createModal = document.getElementById('inventoryCreateModal');
    const inventoryModalForm = document.getElementById('inventoryModalForm');
    let modalSubmitTimer = null;
    let modalIsSubmitting = false;

    function cleanModalSerial() {
        if (!modalSerialInput) return '';
        modalSerialInput.value = modalSerialInput.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        return modalSerialInput.value.trim();
    }

    function submitModalSerial() {
        if (!modalSerialInput || !inventoryModalForm || modalIsSubmitting) return;

        const serial = cleanModalSerial();
        if (serial === '' || !/^[A-Z0-9]+$/.test(serial)) {
            modalSerialInput.focus();
            return;
        }

        modalIsSubmitting = true;
        modalSerialInput.readOnly = true;

        if (typeof inventoryModalForm.requestSubmit === 'function') {
            inventoryModalForm.requestSubmit();
        } else {
            inventoryModalForm.submit();
        }
    }

    if (modalSerialInput) {
        modalSerialInput.addEventListener('input', function () {
            cleanModalSerial();

            if (modalSubmitTimer) {
                clearTimeout(modalSubmitTimer);
            }

            modalSubmitTimer = setTimeout(function () {
                submitModalSerial();
            }, 650);
        });

        modalSerialInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                if (modalSubmitTimer) {
                    clearTimeout(modalSubmitTimer);
                }
                submitModalSerial();
            }
        });

        modalSerialInput.addEventListener('paste', function () {
            setTimeout(function () {
                cleanModalSerial();
                if (modalSubmitTimer) {
                    clearTimeout(modalSubmitTimer);
                }
                modalSubmitTimer = setTimeout(function () {
                    submitModalSerial();
                }, 650);
            }, 0);
        });
    }

    if (inventoryModalForm) {
        inventoryModalForm.addEventListener('submit', function (event) {
            const serial = cleanModalSerial();
            if (serial === '' || !/^[A-Z0-9]+$/.test(serial)) {
                event.preventDefault();
                modalIsSubmitting = false;
                if (modalSerialInput) {
                    modalSerialInput.readOnly = false;
                    modalSerialInput.focus();
                }
                alert('Serial HDD ต้องเป็นตัวอักษรภาษาอังกฤษและตัวเลขเท่านั้น');
            }
        });
    }

    if (createModal) {
        createModal.addEventListener('shown.bs.modal', function () {
            modalIsSubmitting = false;
            if (modalSubmitTimer) {
                clearTimeout(modalSubmitTimer);
            }
            if (modalSerialInput) {
                modalSerialInput.readOnly = false;
                modalSerialInput.value = '';
                modalSerialInput.focus();
            }
        });

        createModal.addEventListener('hidden.bs.modal', function () {
            modalIsSubmitting = false;
            if (modalSubmitTimer) {
                clearTimeout(modalSubmitTimer);
            }
            if (modalSerialInput) {
                modalSerialInput.readOnly = false;
                modalSerialInput.value = '';
            }
        });
    }

    const barcodeInput = document.getElementById('hdd_serial');
    const inventoryForm = document.getElementById('inventoryForm');
    const scanStatus = document.getElementById('scanStatus');

    if (!barcodeInput || !inventoryForm) {
        return;
    }

    let submitTimer = null;
    let isSubmitting = false;

    function setScanStatus(message, type) {
        if (!scanStatus) {
            return;
        }
        scanStatus.className = 'alert mb-0 mt-2 py-2 scan-status alert-' + type;
        scanStatus.textContent = message;
    }

    function validateBarcodeInput(requireValue) {
        const value = barcodeInput.value.trim();
        const pattern = requireValue ? /^[A-Za-z0-9]+$/ : /^[A-Za-z0-9]*$/;

        if (!pattern.test(value)) {
            barcodeInput.classList.add('is-invalid');
            barcodeInput.setCustomValidity('Serial HDD ต้องเป็นภาษาอังกฤษและตัวเลขเท่านั้น');
            setScanStatus('Serial HDD ไม่ถูกต้อง', 'danger');
            return false;
        }

        barcodeInput.classList.remove('is-invalid');
        barcodeInput.setCustomValidity('');
        setScanStatus(value === '' ? 'รอยิงบาร์โค้ด...' : 'พร้อมบันทึก...', 'info');
        return true;
    }

    function submitBarcode() {
        const value = barcodeInput.value.trim();

        if (isSubmitting || value === '') {
            return;
        }

        if (!validateBarcodeInput(true)) {
            barcodeInput.focus();
            return;
        }

        isSubmitting = true;
        setScanStatus('กำลังบันทึกเข้าคลัง...', 'warning');

        if (typeof inventoryForm.requestSubmit === 'function') {
            inventoryForm.requestSubmit();
        } else {
            inventoryForm.submit();
        }
    }

    barcodeInput.addEventListener('input', function () {
        this.value = this.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
        validateBarcodeInput(false);

        if (submitTimer) {
            clearTimeout(submitTimer);
        }

        submitTimer = setTimeout(function () {
            submitBarcode();
        }, 650);
    });

    barcodeInput.addEventListener('keydown', function (event) {
        if (event.key === 'Enter') {
            event.preventDefault();
            if (submitTimer) {
                clearTimeout(submitTimer);
            }
            submitBarcode();
        }
    });

    barcodeInput.addEventListener('paste', function () {
        setTimeout(function () {
            barcodeInput.value = barcodeInput.value.replace(/[^A-Za-z0-9]/g, '').toUpperCase();
            validateBarcodeInput(false);
            if (submitTimer) {
                clearTimeout(submitTimer);
            }
            submitTimer = setTimeout(function () {
                submitBarcode();
            }, 650);
        }, 0);
    });

    inventoryForm.addEventListener('submit', function (event) {
        if (!validateBarcodeInput(true)) {
            event.preventDefault();
            isSubmitting = false;
            barcodeInput.focus();
            alert('Serial HDD ต้องเป็นตัวอักษรภาษาอังกฤษและตัวเลขเท่านั้น');
        }
    });

    barcodeInput.focus();
    barcodeInput.select();
});
</script>


<div class="modal fade" id="inventorySaveSuccessModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content border-0 shadow">
      <div class="modal-header bg-success text-white">
        <h5 class="modal-title fw-bold">บันทึกข้อมูลสำเร็จ</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body text-center py-4">
        <div class="fs-5 fw-bold text-success mb-2">บันทึก HDD เข้าคลังเรียบร้อยแล้ว</div>
        <div>Serial : <strong id="inventorySavedSerial"><?php echo h($_GET['serial'] ?? ''); ?></strong></div>
      </div>
      <div class="modal-footer justify-content-center">
        <button type="button" class="btn btn-success px-4" data-bs-dismiss="modal">ตกลง</button>
      </div>
    </div>
  </div>
</div>

<div class="modal fade inventory-edit-modal" id="inventoryEditModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
    <form method="post" class="modal-content" id="inventoryEditForm" autocomplete="off">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-bold">แก้ไขข้อมูล Harddisk</h5>
          <div class="small text-muted mt-1" id="inventoryEditSubtitle">-</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="form_action" value="edit">
        <input type="hidden" name="inventory_id" id="edit_inventory_id" value="">
        <div class="table-responsive inventory-edit-table-wrap">
          <table class="table table-bordered inventory-edit-table mb-0">
            <tbody>
              <tr>
                <th>Serial HDD</th>
                <td>
                  <input type="text" id="edit_inventory_serial" class="form-control inventory-edit-readonly inventory-serial-input" readonly>
                  <div class="form-text">ไม่สามารถแก้ไข Serial HDD ได้</div>
                </td>
              </tr>
              <tr>
                <th>สถานะ <span class="text-danger">*</span></th>
                <td>
                  <select name="status" id="edit_inventory_status" class="form-select" required>
                    <?php foreach ($statusOptions as $value => $label): if ($value === '') continue; ?>
                      <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
              <tr>
                <th>รับมาจาก</th>
                <td>
                  <select name="received_from" id="edit_inventory_received_from" class="form-select">
                    <?php foreach ($receivedFromOptions as $value => $label): ?>
                      <option value="<?php echo h($value); ?>"><?php echo h($label); ?></option>
                    <?php endforeach; ?>
                  </select>
                </td>
              </tr>
              <tr>
                <th>ผู้สแกน/ผู้บันทึก</th>
                <td><input type="text" id="edit_inventory_scanned_by" class="form-control inventory-edit-readonly" readonly></td>
              </tr>
              <tr>
                <th>วันที่รับเข้า</th>
                <td><input type="text" id="edit_inventory_scanned_at" class="form-control inventory-edit-readonly" readonly></td>
              </tr>
              <tr>
                <th>หมายเหตุ</th>
                <td><textarea name="remark" id="edit_inventory_remark" class="form-control" rows="2" placeholder="รายละเอียดเพิ่มเติม"></textarea></td>
              </tr>
            </tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="submit" class="btn btn-primary px-4">บันทึกการแก้ไข</button>
      </div>
    </form>
  </div>
</div>

<div class="modal fade unified-action-modal" id="unifiedConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><div><h5 class="modal-title fw-bold">ยืนยันการดำเนินการ</h5><div class="small text-muted mt-1">กรุณาตรวจสอบข้อมูลก่อนดำเนินการ</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="alert alert-warning mb-0" id="unifiedConfirmMessage">ยืนยันการดำเนินการนี้หรือไม่?</div></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button type="button" class="btn btn-danger" id="unifiedConfirmSubmit">ยืนยัน</button></div>
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
/* Search menu copied from Shipment History */
.shipment-filter-row{
    display:grid;
    grid-template-columns:minmax(260px,1fr) repeat(3,minmax(125px,150px)) minmax(72px,90px) minmax(86px,100px) minmax(86px,100px);
    gap:8px;
    align-items:end;
    width:100%;
    max-width:100%;
}
.shipment-filter-row>div{min-width:0;max-width:100%}
.shipment-filter-row .form-control,
.shipment-filter-row .form-select,
.shipment-filter-row .btn{width:100%;max-width:100%;height:38px;min-height:38px}
.shipment-filter-action .form-label{display:block}
@media(max-width:1366px){
    .shipment-filter-row{
        grid-template-columns:minmax(210px,1.55fr) minmax(112px,.78fr) minmax(112px,.78fr) minmax(105px,.72fr) minmax(64px,.42fr) minmax(74px,.5fr) minmax(74px,.5fr);
        gap:5px;
    }
    .shipment-filter-keyword{grid-column:auto}
    .shipment-filter-row .form-control,
    .shipment-filter-row .form-select,
    .shipment-filter-row .btn{height:34px;min-height:34px;font-size:.68rem;padding-left:.42rem;padding-right:.42rem}
    .shipment-filter-row .form-label{font-size:.64rem;margin-bottom:3px;white-space:nowrap}
}
@media(max-width:1100px){
    .shipment-filter-row{
        grid-template-columns:minmax(180px,1.45fr) minmax(105px,.78fr) minmax(105px,.78fr) minmax(96px,.68fr) minmax(58px,.4fr) minmax(70px,.48fr) minmax(70px,.48fr);
        gap:4px;
    }
    .shipment-filter-row .form-control,
    .shipment-filter-row .form-select,
    .shipment-filter-row .btn{font-size:.64rem;padding-left:.32rem;padding-right:.32rem}
    .shipment-filter-row .form-label{font-size:.6rem}
}
@media(max-width:767.98px){
    .shipment-filter-row{
        grid-template-columns:minmax(170px,1.35fr) minmax(100px,.75fr) minmax(100px,.75fr) minmax(90px,.65fr) minmax(54px,.38fr) minmax(68px,.46fr) minmax(68px,.46fr);
        min-width:760px;
    }
    .unified-search-card .card-body{overflow-x:auto}
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
.inventory-search-row.hdd-fields-7{grid-template-columns:minmax(260px,1fr) repeat(3,minmax(125px,150px)) minmax(72px,90px) minmax(86px,100px) minmax(86px,100px)!important}
.inventory-search-row .inventory-search-field{min-width:0!important;max-width:100%!important}
.inventory-search-row .form-control,.inventory-search-row .form-select,.inventory-search-row .btn{width:100%!important;max-width:100%!important;height:38px!important;min-height:38px!important}
@media(max-width:1366px){.inventory-search-row.hdd-fields-7{grid-template-columns:minmax(210px,1.55fr) minmax(112px,.78fr) minmax(112px,.78fr) minmax(105px,.72fr) minmax(64px,.42fr) minmax(74px,.5fr) minmax(74px,.5fr)!important;gap:5px!important}.inventory-search-row .form-control,.inventory-search-row .form-select,.inventory-search-row .btn{height:34px!important;min-height:34px!important;font-size:.68rem!important;padding-left:.42rem!important;padding-right:.42rem!important}.inventory-search-row .form-label{font-size:.64rem!important;margin-bottom:3px!important}}
@media(max-width:767.98px){.inventory-search-row.hdd-fields-7{min-width:760px}.search-menu-card{overflow-x:auto}}
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

