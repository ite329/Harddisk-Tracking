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

    $class = 'bg-secondary';

    if ($status === 'available') {
        $class = 'bg-success';
    } elseif ($status === 'reserved') {
        $class = 'bg-warning text-dark';
    } elseif ($status === 'shipped') {
        $class = 'bg-primary';
    } elseif ($status === 'used') {
        $class = 'bg-info text-dark';
    } elseif ($status === 'damaged') {
        $class = 'bg-danger';
    } elseif ($status === 'cancelled') {
        $class = 'bg-secondary';
    }

    return '<span class="badge ' . h($class) . '">' . h(inventoryStatusText($status)) . '</span>';
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

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    body { background: #f3f6fb; }
    .inventory-page { padding: 10px 0 16px 0; }
    .inventory-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .inventory-subtitle { font-size: 13px; color: #64748b; }
    .inventory-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .inventory-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; display: flex; align-items: center; justify-content: space-between; gap: 10px; }
    .inventory-card .card-body { padding: 12px; }
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
    .table-inventory th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 12px; white-space: nowrap; padding: 7px 8px; }
    .table-inventory td { font-size: 12px; vertical-align: middle; padding: 7px 8px; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-weight: 900; color: #7c2d12; white-space: nowrap; }
    .text-ellipsis { max-width: 230px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .help-box { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 8px 10px; font-size: 12px; }
    .action-buttons { display: flex; gap: 6px; flex-wrap: nowrap; align-items: center; }
    .action-buttons form { display: inline; margin: 0; }
    .scan-status { min-height: 39px; display: flex; align-items: center; }
    .status-list { max-height: 230px; overflow: auto; }
    .status-row { display: flex; justify-content: space-between; align-items: center; gap: 10px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
    .status-row:last-child { border-bottom: 0; }
    .status-count { font-weight: 900; color: #0f172a; }
    .mini-bar { height: 6px; background: #e5e7eb; border-radius: 999px; overflow: hidden; margin-top: 5px; }
    .mini-bar-fill { height: 100%; background: #2563eb; border-radius: 999px; }
    .search-menu-card {
        border: 1px solid #dbeafe;
        border-radius: 14px;
        background: linear-gradient(135deg, #ffffff, #eff6ff);
        padding: 10px;
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
    @media (max-width: 1366px) {
        .inventory-page { padding-top: 8px; }
        .inventory-title { font-size: 20px; }
        .inventory-card .card-body { padding: 10px; }
        .kpi-card .card-body { padding: 10px 12px; }
        .kpi-value { font-size: 25px; }
        .table-scroll { max-height: 385px; }
        .table-inventory th, .table-inventory td { font-size: 11.5px; padding: 6px 7px; }
        .form-control, .form-select { font-size: 12px; }
    }
</style>

<div class="container-fluid inventory-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="inventory-title">คลัง Harddisk</h3>
            <div class="inventory-subtitle">บันทึก HDD เข้าคลัง ติดตามสถานะ และค้นหารายการ Harddisk ได้จากหน้าเดียว</div>
        </div>
        <div class="d-flex gap-2">
            <a href="../dashboard/index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
            <a href="../claim_returns/index.php" class="btn btn-outline-primary btn-sm">รับคืน HDD ส่งเคลม</a>
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

    <div class="card hero-card mb-2">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold"></div>
                <div class="small opacity-75">เมื่อยิงบาร์โค้ดเข้าคลัง ระบบจะบันทึกผู้สแกนจาก User ที่ Login อยู่ และตั้งสถานะเป็น “พร้อมใช้งาน”</div>
            </div>
            <div class="small">ผู้ใช้งานปัจจุบัน: <strong><?php echo h($loginName); ?></strong></div>
        </div>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">พร้อมใช้งาน</div><div class="kpi-value"><?php echo number_format($summary['available']); ?></div><div class="kpi-note">HDD ที่พร้อมจับคู่กับสาขา</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">จองไว้</div><div class="kpi-value"><?php echo number_format($summary['reserved']); ?></div><div class="kpi-note">จับคู่แล้ว / รอจัดส่ง</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">ชำรุด / ส่งเคลม</div><div class="kpi-value"><?php echo number_format($summary['damaged']); ?></div><div class="kpi-note">รับคืนจากสาขาเพื่อส่งเคลม</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">ทั้งหมดในคลัง</div><div class="kpi-value"><?php echo number_format($summary['total']); ?></div><div class="kpi-note">เพิ่มวันนี้ <?php echo number_format($summary['today']); ?> รายการ</div></div></div></div>
    </div>

    <div class="row g-2">
        <div class="col-xl-4 col-lg-5">
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

        <div class="col-xl-8 col-lg-7">
            <div class="card inventory-card h-100">
                <div class="card-header">
                    <span>รายการ Harddisk ในคลัง</span>
                    <span class="text-muted small">ทั้งหมด <?php echo number_format($totalRows); ?> รายการ</span>
                </div>
                <div class="card-body">
                    <div class="search-menu-card">
                        <form method="get" id="inventorySearchForm" autocomplete="off">
                            <div class="search-menu-title">
                                <strong>เมนูค้นหาข้อมูล Harddisk</strong>
                                <?php if ($hasActiveSearch): ?>
                                    <span class="badge bg-primary">กำลังกรองข้อมูล</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">แสดงทั้งหมด</span>
                                <?php endif; ?>
                            </div>

                            <div class="row g-2 align-items-end">
                                <div class="col-xl-4 col-lg-5 col-md-12">
                                    <label class="form-label">ค้นหาด่วนจาก Serial HDD / ผู้บันทึก / รับมาจาก / หมายเหตุ</label>
                                    <div class="input-group">
                                        <span class="input-group-text">🔎</span>
                                        <input
                                            type="text"
                                            name="keyword"
                                            id="inventorySearchInput"
                                            class="form-control search-input-lg"
                                            value="<?php echo h($keyword); ?>"
                                            placeholder="พิมพ์หรือสแกน Serial HDD เช่น WWD2FN47"
                                        >
                                    </div>
                                </div>
                                <div class="col-xl-2 col-lg-3 col-md-4">
                                    <label class="form-label">สถานะ</label>
                                    <select name="status" class="form-select">
                                        <?php foreach ($statusOptions as $value => $label): ?>
                                            <option value="<?php echo h($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xl-2 col-lg-2 col-md-4">
                                    <label class="form-label">วันที่เริ่มต้น</label>
                                    <input type="date" name="date_from" class="form-control" value="<?php echo h($dateFrom); ?>">
                                </div>
                                <div class="col-xl-2 col-lg-2 col-md-4">
                                    <label class="form-label">วันที่สิ้นสุด</label>
                                    <input type="date" name="date_to" class="form-control" value="<?php echo h($dateTo); ?>">
                                </div>
                                <div class="col-xl-1 col-lg-2 col-md-4">
                                    <label class="form-label">จำนวน</label>
                                    <select name="per_page" class="form-select">
                                        <?php foreach ($allowedPerPage as $n): ?>
                                            <option value="<?php echo (int)$n; ?>" <?php echo $perPage === $n ? 'selected' : ''; ?>><?php echo (int)$n; ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-xl-1 col-lg-2 col-md-8 d-grid">
                                    <button type="submit" class="btn btn-primary">ค้นหา</button>
                                </div>
                            </div>

                            <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2">
                                <div class="quick-filter-wrap">
                                    <a href="index.php" class="quick-filter <?php echo $statusFilter === '' ? 'active' : ''; ?>">ทั้งหมด</a>
                                    <a href="?status=available&per_page=<?php echo (int)$perPage; ?>" class="quick-filter <?php echo $statusFilter === 'available' ? 'active' : ''; ?>">พร้อมใช้งาน</a>
                                    <a href="?status=reserved&per_page=<?php echo (int)$perPage; ?>" class="quick-filter <?php echo $statusFilter === 'reserved' ? 'active' : ''; ?>">จองไว้</a>
                                    <a href="?status=shipped&per_page=<?php echo (int)$perPage; ?>" class="quick-filter <?php echo $statusFilter === 'shipped' ? 'active' : ''; ?>">จัดส่งแล้ว</a>
                                    <a href="?status=damaged&per_page=<?php echo (int)$perPage; ?>" class="quick-filter <?php echo $statusFilter === 'damaged' ? 'active' : ''; ?>">ชำรุด</a>
                                </div>
                                <div class="d-flex align-items-center gap-2 flex-wrap">
                                    <div class="search-result-note">ผลลัพธ์ <?php echo number_format($totalRows); ?> รายการ</div>
                                    <a href="index.php" class="btn btn-outline-secondary btn-sm">ล้างการค้นหา</a>
                                </div>
                            </div>
                        </form>
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
                                    <th>วันที่รับเข้า</th>
                                    <th>ผู้สแกน/ผู้บันทึก</th>
                                    <th>วันที่สแกน</th>
                                    <th>หมายเหตุ</th>
                                    <?php if ($canManageInventory): ?>
                                        <th style="width:150px;">จัดการ</th>
                                    <?php endif; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($inventoryRows)): ?>
                                    <tr>
                                        <td colspan="<?php echo $canManageInventory ? 9 : 8; ?>" class="text-center text-muted py-4">ไม่พบข้อมูล Harddisk ในคลัง</td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($inventoryRows as $index => $row): ?>
                                        <?php
                                        $runningNo = $offset + $index + 1;
                                        $displayUser = $row['scanned_by'] ?? ($row['created_by'] ?? '-');
                                        ?>
                                        <tr>
                                            <td class="text-center"><?php echo number_format($runningNo); ?></td>
                                            <td><span class="serial-text"><?php echo h($row['hdd_serial'] ?? '-'); ?></span></td>
                                            <td><?php echo inventoryStatusBadge($row['status'] ?? ''); ?></td>
                                            <td><div class="text-ellipsis" title="<?php echo h($row['received_from'] ?? '-'); ?>"><?php echo h($row['received_from'] ?? '-'); ?></div></td>
                                            <td><?php echo h(formatThaiDateTime($row['received_at'] ?? ($row['created_at'] ?? ''))); ?></td>
                                            <td><div class="text-ellipsis" title="<?php echo h($displayUser); ?>"><?php echo h($displayUser ?: '-'); ?></div></td>
                                            <td><?php echo h(formatThaiDateTime($row['scanned_at'] ?? '')); ?></td>
                                            <td><div class="text-ellipsis" title="<?php echo h($row['remark'] ?? '-'); ?>"><?php echo h(($row['remark'] ?? '') !== '' ? $row['remark'] : '-'); ?></div></td>
                                            <?php if ($canManageInventory): ?>
                                                <td class="text-nowrap">
                                                    <div class="action-buttons">
                                                        <a href="edit.php?id=<?php echo (int)($row['id'] ?? 0); ?>" class="btn btn-sm btn-outline-primary">
                                                            แก้ไข
                                                        </a>
                                                        <form method="post" action="delete.php" onsubmit="return confirm('ยืนยันการลบ HDD Serial <?php echo h($row['hdd_serial'] ?? ''); ?> ออกจากคลังหรือไม่?');">
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

<script>
document.addEventListener('DOMContentLoaded', function () {
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
