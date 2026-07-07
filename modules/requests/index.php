<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/permissions.php';

$pageTitle = 'รายการคำขอส่ง HDD';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function getPdoConnection(): PDO
{
    $possibleFiles = [
        __DIR__ . '/../../config/database.php',
        __DIR__ . '/../../config/db.php',
        __DIR__ . '/../../includes/database.php',
        __DIR__ . '/../../includes/db.php',
    ];

    foreach ($possibleFiles as $file) {
        if (!file_exists($file)) {
            continue;
        }

        require_once $file;

        if (isset($pdo) && $pdo instanceof PDO) {
            return $pdo;
        }

        if (isset($conn) && $conn instanceof PDO) {
            return $conn;
        }

        if (isset($db) && $db instanceof PDO) {
            return $db;
        }

        if (function_exists('getConnection')) {
            $connection = getConnection();
            if ($connection instanceof PDO) {
                return $connection;
            }
        }

        if (function_exists('getPdo')) {
            $connection = getPdo();
            if ($connection instanceof PDO) {
                return $connection;
            }
        }
    }

    throw new Exception('ไม่พบไฟล์เชื่อมต่อฐานข้อมูล PDO');
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

function formatMainBranchCode($value): string
{
    $value = trim((string)($value ?? ''));

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

function formatDateTimeThai($value): string
{
    $value = trim((string)($value ?? ''));

    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y H:i', $timestamp);
}

function statusBadge(string $status): string
{
    $status = trim($status);

    $map = [
        'pending_scan' => [
            'text' => 'รอยิงบาร์โค้ด',
            'class' => 'bg-warning text-dark'
        ],
        'pending' => [
            'text' => 'รอดำเนินการ',
            'class' => 'bg-warning text-dark'
        ],
        'approved' => [
            'text' => 'อนุมัติแล้ว',
            'class' => 'bg-info text-dark'
        ],
        'matched' => [
            'text' => 'รอยืนยันจัดส่ง',
            'class' => 'bg-info text-dark'
        ],
        'reserved' => [
            'text' => 'จับคู่ HDD แล้ว',
            'class' => 'bg-primary'
        ],
        'shipped' => [
            'text' => 'จัดส่งแล้ว',
            'class' => 'bg-success'
        ],
        'received' => [
            'text' => 'รับแล้ว',
            'class' => 'bg-success'
        ],
        'installed' => [
            'text' => 'ติดตั้งแล้ว',
            'class' => 'bg-success'
        ],
        'cancelled' => [
            'text' => 'ยกเลิก',
            'class' => 'bg-secondary'
        ],
        'rejected' => [
            'text' => 'ไม่อนุมัติ',
            'class' => 'bg-danger'
        ],
        'completed' => [
            'text' => 'เสร็จสิ้น',
            'class' => 'bg-success'
        ],
    ];

    $item = $map[$status] ?? [
        'text' => $status !== '' ? $status : '-',
        'class' => 'bg-secondary'
    ];

    return '<span class="badge ' . h($item['class']) . '">' . h($item['text']) . '</span>';
}

function displayRecorder(array $row): string
{
    $fullName = trim((string)($row['user_full_name'] ?? ''));

    if ($fullName === '') {
        $firstName = trim((string)($row['user_first_name'] ?? ''));
        $lastName = trim((string)($row['user_last_name'] ?? ''));

        if ($firstName !== '' || $lastName !== '') {
            $fullName = trim($firstName . ' ' . $lastName);
        }
    }

    if ($fullName === '') {
        foreach (['user_name', 'user_username', 'user_login_name'] as $key) {
            if (!empty($row[$key])) {
                $fullName = trim((string)$row[$key]);
                break;
            }
        }
    }

    $employeeCode = trim((string)($row['user_employee_code'] ?? ''));

    if ($fullName !== '' && $employeeCode !== '') {
        return $fullName . ' (' . $employeeCode . ')';
    }

    if ($fullName !== '') {
        return $fullName;
    }

    if ($employeeCode !== '') {
        return $employeeCode;
    }

    foreach (['created_by', 'requested_by', 'updated_by'] as $key) {
        if (!empty($row[$key])) {
            return trim((string)$row[$key]);
        }
    }

    return '-';
}

function buildUsersJoin(array $requestColumns, array $userColumns): string
{
    if (empty($userColumns)) {
        return '';
    }

    $requestUserColumns = [];

    foreach (['created_by', 'requested_by', 'updated_by'] as $column) {
        if (hasColumn($requestColumns, $column)) {
            $requestUserColumns[] = $column;
        }
    }

    if (empty($requestUserColumns)) {
        return '';
    }

    $conditions = [];

    foreach ($requestUserColumns as $requestUserColumn) {
        if (hasColumn($userColumns, 'id')) {
            $conditions[] = "CAST(r.`{$requestUserColumn}` AS CHAR) = CAST(u.`id` AS CHAR)";
        }

        if (hasColumn($userColumns, 'employee_code')) {
            $conditions[] = "r.`{$requestUserColumn}` = u.`employee_code`";
        }

        if (hasColumn($userColumns, 'username')) {
            $conditions[] = "r.`{$requestUserColumn}` = u.`username`";
        }

        if (hasColumn($userColumns, 'user_name')) {
            $conditions[] = "r.`{$requestUserColumn}` = u.`user_name`";
        }

        if (hasColumn($userColumns, 'login_name')) {
            $conditions[] = "r.`{$requestUserColumn}` = u.`login_name`";
        }
    }

    if (empty($conditions)) {
        return '';
    }

    $joinSql = 'LEFT JOIN users u ON (' . implode(' OR ', $conditions) . ')';

    if (hasColumn($userColumns, 'deleted_at')) {
        $joinSql .= ' AND u.deleted_at IS NULL';
    }

    return $joinSql;
}

$errorMessage = '';
$rows = [];
$totalRows = 0;
$totalPages = 1;
$offset = 0;
$page = 1;
$usersJoinEnabled = false;
$canDeleteRequest = function_exists('can_delete_hdd_request') ? can_delete_hdd_request() : false;
$canEditRequest = function_exists('can_edit_hdd_request') ? can_edit_hdd_request() : false;

try {
    $pdo = getPdoConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $columns = getTableColumns($pdo, 'harddisk_delivery_requests');
    $userColumns = getTableColumns($pdo, 'users');

    $keyword = trim((string)($_GET['keyword'] ?? ''));
    $statusFilter = trim((string)($_GET['status'] ?? ''));
    $dateFromFilter = trim((string)($_GET['date_from'] ?? ''));
    $dateToFilter = trim((string)($_GET['date_to'] ?? ''));

    $page = (int)($_GET['page'] ?? 1);
    if ($page < 1) {
        $page = 1;
    }

    $limit = 20;
    $offset = ($page - 1) * $limit;

    $where = [];
    $params = [];

    if (hasColumn($columns, 'deleted_at')) {
        $where[] = 'r.deleted_at IS NULL';
    }

    if ($statusFilter !== '' && hasColumn($columns, 'status')) {
        $where[] = 'r.status = :status';
        $params[':status'] = $statusFilter;
    }

    if ($keyword !== '') {
        $searchParts = [];
        $keywordLike = '%' . $keyword . '%';

        /*
        |--------------------------------------------------------------------------
        | Keyword Search ใช้เงื่อนไขเดียวกับหน้า "ประวัติการจัดส่ง Harddisk"
        |--------------------------------------------------------------------------
        | ค้นหาได้จาก:
        | - เลขที่คำขอ
        | - รหัสสาขา
        | - ชื่อสาขา
        | - Serial HDD
        |
        | หลักการ:
        | 1) ถ้าเป็นตัวเลขล้วนไม่เกิน 3 หลัก เช่น 3, 003, 110, 240
        |    ให้ถือว่าเป็น "รหัสสาขา" และค้นหาแบบตรงตัวเท่านั้น
        |    ไม่ค้นใน Serial HDD เพื่อไม่ให้ Serial เช่น WWD240RX ดึงรหัสสาขาอื่นติดมาด้วย
        |
        | 2) ถ้าเป็นตัวเลขล้วนมากกว่า 3 หลัก
        |    ให้ค้นจากเลขที่คำขอ และ Serial HDD
        |
        | 3) ถ้าเป็นข้อความหรือข้อความผสมตัวเลข เช่น "เพชรเกษม 110 กรุงเทพฯ"
        |    ให้ค้นจากชื่อสาขา / เลขที่คำขอ / Serial HDD ด้วยข้อความเต็ม
        |    ไม่เอาเลข 110 ไปเทียบรหัสสาขา
        */
        $keywordIsNumberOnly = preg_match('/^\d+$/', $keyword) === 1;
        $keywordIsShortBranchCode = $keywordIsNumberOnly && strlen($keyword) <= 3;

        if ($keywordIsShortBranchCode) {
            $normalizedBranchKeyword = str_pad($keyword, 3, '0', STR_PAD_LEFT);

            if (hasColumn($columns, 'main_branch_code')) {
                $searchParts[] = "LPAD(r.main_branch_code, 3, '0') = :keyword_main_branch_exact";
                $params[':keyword_main_branch_exact'] = $normalizedBranchKeyword;
            }

            if (hasColumn($columns, 'branch_code')) {
                $searchParts[] = "LPAD(r.branch_code, 3, '0') = :keyword_branch_code_exact";
                $params[':keyword_branch_code_exact'] = $normalizedBranchKeyword;
            }
        } else {
            foreach ([
                'request_no',
                'branch_name',
                'hdd_serial',
            ] as $index => $column) {
                if (!hasColumn($columns, $column)) {
                    continue;
                }

                $paramName = ':keyword_text_' . $index;
                $searchParts[] = 'r.`' . $column . '` LIKE ' . $paramName;
                $params[$paramName] = $keywordLike;
            }
        }

        if (!empty($searchParts)) {
            $where[] = '(' . implode(' OR ', $searchParts) . ')';
        }
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    $countStmt = $pdo->prepare("\n        SELECT COUNT(*) AS total\n        FROM harddisk_delivery_requests r\n        {$whereSql}\n    ");
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();

    $totalPages = max(1, (int)ceil($totalRows / $limit));

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $limit;
    }

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
        'created_by',
        'requested_by',
        'updated_by',
        'created_at',
        'updated_at',
        'remark'
    ] as $column) {
        if (hasColumn($columns, $column)) {
            $selectColumns[] = 'r.`' . $column . '` AS `' . $column . '`';
        }
    }

    if (empty($selectColumns)) {
        $selectColumns[] = 'r.*';
    }

    $userJoinSql = buildUsersJoin($columns, $userColumns);
    $usersJoinEnabled = $userJoinSql !== '';

    if ($usersJoinEnabled) {
        foreach ([
            'id' => 'user_id',
            'employee_code' => 'user_employee_code',
            'first_name' => 'user_first_name',
            'last_name' => 'user_last_name',
            'full_name' => 'user_full_name',
            'name' => 'user_name',
            'username' => 'user_username',
            'user_name' => 'user_user_name',
            'login_name' => 'user_login_name',
        ] as $column => $alias) {
            if (hasColumn($userColumns, $column)) {
                $selectColumns[] = 'u.`' . $column . '` AS `' . $alias . '`';
            }
        }
    }

    $orderBy = hasColumn($columns, 'id') ? 'r.id DESC' : 'r.created_at DESC';

    $sql = "\n        SELECT " . implode(', ', $selectColumns) . "\n        FROM harddisk_delivery_requests r\n        {$userJoinSql}\n        {$whereSql}\n        ORDER BY {$orderBy}\n        LIMIT :limit OFFSET :offset\n    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }

    $stmt->bindValue(':limit', (int)$limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
    $stmt->execute();

    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

$hasHeader = file_exists(__DIR__ . '/../../includes/header.php');
$hasFooter = file_exists(__DIR__ . '/../../includes/footer.php');

if ($hasHeader) {
    include __DIR__ . '/../../includes/header.php';
} else {
    ?>
    <!doctype html>
    <html lang="th">
    <head>
        <meta charset="utf-8">
        <title><?php echo h($pageTitle); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <?php
}
?>

<style>
    body { background: #f3f6fb; }
    .request-page { padding: 10px 0 16px 0; }
    .request-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .request-subtitle { font-size: 13px; color: #64748b; }
    .request-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .request-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; }
    .request-card .card-body { padding: 12px; }
    .hero-card { border: 0; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #ffffff; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22); }
    .hero-card .card-body { padding: 12px 16px; }
    .hero-title { font-size: 18px; font-weight: 900; margin-bottom: 2px; }
    .hero-desc { font-size: 13px; opacity: .95; }
    .workflow { display: flex; flex-wrap: wrap; gap: 8px; justify-content: flex-end; align-items: center; }
    .workflow-item { background: rgba(255,255,255,.14); border: 1px solid rgba(255,255,255,.24); border-radius: 999px; padding: 6px 10px; font-size: 12px; font-weight: 800; white-space: nowrap; }
    .kpi-card { border: 0; border-radius: 15px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07); height: 100%; }
    .kpi-card .card-body { padding: 12px 14px; }
    .kpi-label { color: #64748b; font-size: 12px; margin-bottom: 5px; }
    .kpi-value { font-size: 27px; font-weight: 900; color: #0f172a; line-height: 1; }
    .kpi-note { margin-top: 5px; font-size: 11px; color: #64748b; line-height: 1.2; }
    .step-box { border: 1px solid #e2e8f0; border-radius: 14px; padding: 10px; background: #f8fafc; }
    .step-title { font-size: 13px; font-weight: 900; color: #0f172a; margin-bottom: 8px; display: flex; align-items: center; gap: 6px; }
    .step-badge { width: 22px; height: 22px; border-radius: 8px; background: #2563eb; color: #ffffff; display: inline-flex; align-items: center; justify-content: center; font-size: 12px; }
    .table-wrap { max-height: 520px; overflow: auto; }
    .table-request th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 12px; white-space: nowrap; padding: 7px 8px; color: #334155; }
    .table-request td { font-size: 12px; vertical-align: middle; padding: 7px 8px; }
    .table-request .badge { font-size: 11px; }
    .request-no { font-weight: 900; color: #0f766e; white-space: nowrap; }
    .branch-code { font-weight: 900; color: #1d4ed8; white-space: nowrap; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-weight: 900; color: #7c2d12; white-space: nowrap; }
    .recorder-name { font-weight: 800; color: #0f172a; line-height: 1.15; }
    .muted-mini { font-size: 11px; color: #64748b; line-height: 1.2; }
    .branch-name-cell { min-width: 210px; max-width: 280px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .reason-cell, .remark-cell { min-width: 170px; max-width: 250px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .action-cell { min-width: 128px; }
    .filter-input { font-size: 13px; }
    .btn-compact { font-size: 12px; padding: 6px 10px; border-radius: 10px; font-weight: 800; }
    .alert { border-radius: 14px; }
    .pagination .page-link { font-size: 12px; border-radius: 9px; margin: 0 2px; }

    @media (max-width: 1366px) {
        .request-page { padding-top: 8px; }
        .request-title { font-size: 20px; }
        .request-card .card-body { padding: 10px; }
        .hero-card .card-body { padding: 10px 14px; }
        .kpi-card .card-body { padding: 10px 12px; }
        .kpi-value { font-size: 24px; }
        .table-wrap { max-height: 460px; }
        .table-request th, .table-request td { font-size: 11.5px; padding: 6px 7px; }
        .branch-name-cell { max-width: 220px; }
        .reason-cell, .remark-cell { max-width: 190px; }
    }

    @media (max-width: 991px) {
        .workflow { justify-content: flex-start; }
        .table-wrap { max-height: none; }
        .branch-name-cell { max-width: 260px; }
    }
</style>

<div class="container-fluid request-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="request-title">รายการคำขอส่ง HDD</h3>
            <div class="request-subtitle">
                ติดตามคำขอส่ง Harddisk ตรวจสอบสถานะ จัดการรายการ และดูผู้ใช้งานที่บันทึกข้อมูล
            </div>
        </div>

        <div class="d-flex flex-wrap gap-2">
            <a href="create.php" class="btn btn-primary btn-compact">+ บันทึกคำขอ</a>
            <a href="assign_hdd.php" class="btn btn-outline-success btn-compact">ยิงบาร์โค้ด HDD</a>
        </div>
    </div>

    <div class="card hero-card mb-2">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="hero-title"></div>
                <div class="hero-desc">ค้นหารายการ ตรวจสอบผู้บันทึก จับคู่ HDD และดำเนินการจัดส่งได้จากหน้ารายการนี้</div>
            </div>
            <div class="workflow">
                <div class="workflow-item">1) บันทึกคำขอ</div>
                <div class="workflow-item">2) ยิงบาร์โค้ด</div>
                <div class="workflow-item">3) รอยืนยันจัดส่ง</div>
                <div class="workflow-item">4) จัดส่งแล้ว</div>
            </div>
        </div>
    </div>

    <?php if ($errorMessage !== ''): ?>
        <div class="alert alert-danger py-2 mb-2">
            <strong>เกิดข้อผิดพลาด:</strong> <?php echo h($errorMessage); ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_GET['delete_success'])): ?>
        <div class="alert alert-success py-2 mb-2">ลบรายการเรียบร้อยแล้ว</div>
    <?php endif; ?>

    <?php if (isset($_GET['delete_error'])): ?>
        <div class="alert alert-danger py-2 mb-2">ไม่สามารถลบรายการได้ กรุณาตรวจสอบสิทธิ์หรือรายการที่ต้องการลบอีกครั้ง</div>
    <?php endif; ?>

    <?php if (isset($_GET['edit_success']) || isset($_GET['updated'])): ?>
        <div class="alert alert-success py-2 mb-2">แก้ไขรายการเรียบร้อยแล้ว</div>
    <?php endif; ?>

    <?php if (isset($_GET['edit_error'])): ?>
        <div class="alert alert-danger py-2 mb-2">ไม่สามารถแก้ไขรายการได้ กรุณาตรวจสอบสิทธิ์หรือข้อมูลที่ต้องการแก้ไขอีกครั้ง</div>
    <?php endif; ?>

    <div class="row g-2 mb-2">
        <div class="col-lg-3 col-md-6">
            <div class="card kpi-card"><div class="card-body">
                <div class="kpi-label">รายการตามตัวกรอง</div>
                <div class="kpi-value"><?php echo number_format($totalRows); ?></div>
                <div class="kpi-note">จำนวนคำขอส่ง HDD ที่ค้นพบ</div>
            </div></div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card kpi-card"><div class="card-body">
                <div class="kpi-label">สถานะที่เลือก</div>
                <div class="kpi-value" style="font-size:20px; line-height:1.15;">
                    <?php echo $statusFilter !== '' ? h(strip_tags(statusBadge($statusFilter))) : 'ทั้งหมด'; ?>
                </div>
                <div class="kpi-note">ตัวกรองสถานะปัจจุบัน</div>
            </div></div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card kpi-card"><div class="card-body">
                <div class="kpi-label">หน้าปัจจุบัน</div>
                <div class="kpi-value"><?php echo number_format($page); ?></div>
                <div class="kpi-note">จากทั้งหมด <?php echo number_format($totalPages); ?> หน้า</div>
            </div></div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card kpi-card"><div class="card-body">
                <div class="kpi-label">จำนวนที่แสดง</div>
                <div class="kpi-value"><?php echo number_format(count($rows)); ?></div>
                <div class="kpi-note">รายการบนหน้านี้</div>
            </div></div>
        </div>
    </div>

    <div class="card request-card mb-2">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span>🔎 ค้นหาและกรองข้อมูล</span>
            <span class="muted-mini">ค้นหาได้จากเลขที่คำขอ, รหัสสาขา, ชื่อสาขา, Serial HDD และกรองรายคอลัมน์ในหัวตาราง</span>
        </div>
        <div class="card-body">
            <form method="get" id="requestFilterForm" class="row g-2 align-items-end">
                <div class="col-lg-4 col-md-6">
                    <div class="step-box h-100">
                        <div class="step-title"><span class="step-badge">1</span> Keyword</div>
                        <input
                            type="text"
                            name="keyword"
                            class="form-control filter-input"
                            value="<?php echo h($_GET['keyword'] ?? ''); ?>"
                            placeholder="เลขที่คำขอ, รหัสสาขา, ชื่อสาขา, Serial HDD"
                        >
                    </div>
                </div>

                <div class="col-lg-2 col-md-6">
                    <div class="step-box h-100">
                        <div class="step-title"><span class="step-badge">2</span> สถานะ</div>
                        <select name="status" class="form-select filter-input">
                            <?php
                            $currentStatus = trim((string)($_GET['status'] ?? ''));
                            $statusOptions = [
                                '' => 'ทั้งหมด',
                                'pending_scan' => 'รอยิงบาร์โค้ด',
                                'pending' => 'รอดำเนินการ',
                                'approved' => 'อนุมัติแล้ว',
                                'matched' => 'รอยืนยันจัดส่ง',
                                'reserved' => 'จับคู่ HDD แล้ว',
                                'shipped' => 'จัดส่งแล้ว',
                                'received' => 'รับแล้ว',
                                'installed' => 'ติดตั้งแล้ว',
                                'cancelled' => 'ยกเลิก',
                                'rejected' => 'ไม่อนุมัติ',
                                'completed' => 'เสร็จสิ้น',
                            ];
                            foreach ($statusOptions as $value => $label):
                            ?>
                                <option value="<?php echo h($value); ?>" <?php echo $currentStatus === $value ? 'selected' : ''; ?>>
                                    <?php echo h($label); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-lg-3 col-md-4">
                    <div class="step-box h-100">
                        <div class="step-title"><span class="step-badge">3</span> วันที่บันทึก</div>
                        <div class="row g-1">
                            <div class="col-6">
                                <input type="date" name="date_from" class="form-control filter-input" value="<?php echo h($_GET['date_from'] ?? ''); ?>" title="วันที่เริ่มต้น">
                            </div>
                            <div class="col-6">
                                <input type="date" name="date_to" class="form-control filter-input" value="<?php echo h($_GET['date_to'] ?? ''); ?>" title="วันที่สิ้นสุด">
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-lg-3 col-md-4">
                    <div class="step-box h-100">
                        <div class="step-title"><span class="step-badge">4</span> ดำเนินการ</div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-dark btn-compact flex-fill">ค้นหา</button>
                            <a href="index.php" class="btn btn-outline-secondary btn-compact flex-fill">ล้างค่า</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card request-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>📋 รายการคำขอส่ง HDD</div>
            <div class="muted-mini">
                ทั้งหมด <strong><?php echo number_format($totalRows); ?></strong> รายการ · หน้า <?php echo number_format($page); ?> / <?php echo number_format($totalPages); ?>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive table-wrap">
                <table class="table table-hover table-bordered align-middle table-request mb-0">
                    <thead>
                        <tr>
                            <th style="width:60px;">ลำดับ</th>
                            <th>เลขที่คำขอ</th>
                            <th>รหัสสาขาใหญ่</th>
                            <th>Cost Center</th>
                            <th>ชื่อสาขา</th>
                            <th>Serial HDD</th>
                            <th>สถานะ</th>
                            <th>เหตุผล</th>
                            <th>ผู้ใช้งานที่บันทึก</th>
                            <th>วันที่บันทึก</th>
                            <th>หมายเหตุ</th>
                            <?php if ($canEditRequest || $canDeleteRequest): ?>
                                <th class="text-center">จัดการ</th>
                            <?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($rows)): ?>
                            <tr>
                                <td colspan="<?php echo ($canEditRequest || $canDeleteRequest) ? 12 : 11; ?>" class="text-center text-muted py-4">
                                    ไม่พบข้อมูลคำขอส่ง HDD
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($rows as $index => $row): ?>
                                <tr>
                                    <td><?php echo number_format($offset + $index + 1); ?></td>
                                    <td><span class="request-no"><?php echo h($row['request_no'] ?? '-'); ?></span></td>
                                    <td><?php echo h(formatMainBranchCode($row['main_branch_code'] ?? '')); ?></td>
                                    <td><span class="branch-code"><?php echo h($row['branch_code'] ?? '-'); ?></span></td>
                                    <td>
                                        <div class="branch-name-cell" title="<?php echo h($row['branch_name'] ?? '-'); ?>">
                                            <?php echo h($row['branch_name'] ?? '-'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <?php if (!empty($row['hdd_serial'])): ?>
                                            <span class="serial-text"><?php echo h($row['hdd_serial']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">ยังไม่จับคู่</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo statusBadge((string)($row['status'] ?? '')); ?></td>
                                    <td>
                                        <div class="reason-cell" title="<?php echo h($row['request_reason'] ?? '-'); ?>">
                                            <?php echo h($row['request_reason'] ?? '-'); ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="recorder-name"><?php echo h(displayRecorder($row)); ?></div>
                                        <?php if (!empty($row['created_by'])): ?>
                                            <div class="muted-mini">อ้างอิง: <?php echo h($row['created_by']); ?></div>
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><?php echo h(formatDateTimeThai($row['created_at'] ?? '')); ?></td>
                                    <td>
                                        <div class="remark-cell" title="<?php echo h($row['remark'] ?? '-'); ?>">
                                            <?php echo h($row['remark'] ?? '-'); ?>
                                        </div>
                                    </td>

                                    <?php if ($canEditRequest || $canDeleteRequest): ?>
                                        <td class="text-center text-nowrap action-cell">
                                            <?php if ($canEditRequest): ?>
                                                <a href="edit.php?id=<?php echo (int)($row['id'] ?? 0); ?>" class="btn btn-sm btn-outline-primary btn-compact">แก้ไข</a>
                                            <?php endif; ?>

                                            <?php if ($canDeleteRequest): ?>
                                                <form action="delete.php" method="post" class="d-inline" onsubmit="return confirm('ยืนยันการลบรายการนี้หรือไม่?');">
                                                    <input type="hidden" name="id" value="<?php echo (int)($row['id'] ?? 0); ?>">
                                                    <button type="submit" class="btn btn-sm btn-outline-danger btn-compact">ลบ</button>
                                                </form>
                                            <?php endif; ?>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($totalPages > 1): ?>
                <div class="p-2 border-top bg-white">
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center mb-0">
                            <?php
                            $queryParams = $_GET;
                            $prevPage = max(1, $page - 1);
                            $nextPage = min($totalPages, $page + 1);
                            $queryParams['page'] = $prevPage;
                            $prevUrl = 'index.php?' . http_build_query($queryParams);
                            $queryParams['page'] = $nextPage;
                            $nextUrl = 'index.php?' . http_build_query($queryParams);
                            ?>
                            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo h($prevUrl); ?>">ก่อนหน้า</a>
                            </li>

                            <?php
                            $startPage = max(1, $page - 2);
                            $endPage = min($totalPages, $page + 2);
                            for ($i = $startPage; $i <= $endPage; $i++):
                                $queryParams['page'] = $i;
                                $pageUrl = 'index.php?' . http_build_query($queryParams);
                            ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="<?php echo h($pageUrl); ?>"><?php echo number_format($i); ?></a>
                                </li>
                            <?php endfor; ?>

                            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                                <a class="page-link" href="<?php echo h($nextUrl); ?>">ถัดไป</a>
                            </li>
                        </ul>
                    </nav>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php
if ($hasFooter) {
    include __DIR__ . '/../../includes/footer.php';
} else {
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
}
?>
