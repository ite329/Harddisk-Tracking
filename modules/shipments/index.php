<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$basePath = dirname(__DIR__, 2);

$possibleDbFiles = [
    $basePath . '/config/database.php',
    $basePath . '/includes/database.php',
    $basePath . '/includes/db.php',
    $basePath . '/config/db.php',
];

foreach ($possibleDbFiles as $dbFile) {
    if (file_exists($dbFile)) {
        require_once $dbFile;
        break;
    }
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

$pageTitle = 'ประวัติการจัดส่ง Harddisk';

if (!isset($pdo) || !$pdo instanceof PDO) {
    require_once $basePath . '/includes/header.php';
    ?>
    <div class="container-fluid py-4">
        <div class="alert alert-danger">
            <strong>ไม่พบการเชื่อมต่อฐานข้อมูล</strong><br>
            กรุณาตรวจสอบไฟล์เชื่อมต่อฐานข้อมูล เช่น <code>config/database.php</code>
        </div>
    </div>
    <?php
    require_once $basePath . '/includes/footer.php';
    exit;
}

function shipmentStatusText(?string $status): string
{
    $status = strtolower(trim((string)$status));

    $map = [
        'pending'   => 'รอดำเนินการ',
        'sent'      => 'จัดส่งแล้ว',
        'shipped'   => 'จัดส่งแล้ว',
        'received'  => 'ได้รับแล้ว',
        'installed' => 'ติดตั้งแล้ว',
        'completed' => 'เสร็จสิ้น',
        'cancelled' => 'ยกเลิก',
        'cancel'    => 'ยกเลิก',
    ];

    return $map[$status] ?? ($status !== '' ? $status : '-');
}

function shipmentStatusBadge(?string $status): string
{
    $status = strtolower(trim((string)$status));
    $class = 'bg-secondary';

    if (in_array($status, ['sent', 'shipped'], true)) {
        $class = 'bg-primary';
    } elseif (in_array($status, ['received', 'installed', 'completed'], true)) {
        $class = 'bg-success';
    } elseif ($status === 'pending') {
        $class = 'bg-warning text-dark';
    } elseif (in_array($status, ['cancelled', 'cancel'], true)) {
        $class = 'bg-danger';
    }

    return '<span class="badge ' . h($class) . '">' . h(shipmentStatusText($status)) . '</span>';
}

function formatDateThai(?string $date, bool $showTime = false): string
{
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return '-';
    }

    return $showTime ? date('d/m/Y H:i', $timestamp) : date('d/m/Y', $timestamp);
}

function formatMainBranchCode($value): string
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return '';
    }

    if (is_numeric($value)) {
        $value = (string)(int)$value;
    }

    if (ctype_digit($value) && strlen($value) < 3) {
        return str_pad($value, 3, '0', STR_PAD_LEFT);
    }

    return $value;
}

function shortText($value, int $length = 80): string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $length
            ? mb_substr($text, 0, $length, 'UTF-8') . '...'
            : $text;
    }

    return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
}

function bindValues(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
}

try {
    $stmtColumns = $pdo->query("SHOW COLUMNS FROM `harddisk_shipments`");
    $shipmentColumns = $stmtColumns->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    require_once $basePath . '/includes/header.php';
    ?>
    <div class="container-fluid py-4">
        <div class="alert alert-danger">
            <strong>ไม่พบตาราง harddisk_shipments</strong><br>
            รายละเอียด: <?= h($e->getMessage()) ?>
        </div>
    </div>
    <?php
    require_once $basePath . '/includes/footer.php';
    exit;
}

$has = static function (string $column) use ($shipmentColumns): bool {
    return in_array($column, $shipmentColumns, true);
};

$requestColumns = [];
$hasRequestTable = false;

try {
    $stmtRequestColumns = $pdo->query("SHOW COLUMNS FROM `harddisk_delivery_requests`");
    $requestColumns = $stmtRequestColumns->fetchAll(PDO::FETCH_COLUMN);
    $hasRequestTable = true;
} catch (Throwable $e) {
    $requestColumns = [];
    $hasRequestTable = false;
}

$hasRequestColumn = static function (string $column) use ($requestColumns): bool {
    return in_array($column, $requestColumns, true);
};

$keyword = trim((string)($_GET['keyword'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);

$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if (!in_array($perPage, [10, 20, 50, 100], true)) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

$selectFields = [];
$selectFields[] = $has('id') ? '`id`' : '0 AS `id`';
$selectFields[] = $has('request_id') ? '`request_id`' : 'NULL AS `request_id`';

if ($has('delivery_request_no')) {
    $selectFields[] = '`delivery_request_no` AS `delivery_request_no`';
} elseif ($has('request_no')) {
    $selectFields[] = '`request_no` AS `delivery_request_no`';
} elseif ($has('request_id') && $hasRequestTable && $hasRequestColumn('id') && $hasRequestColumn('request_no')) {
    $selectFields[] = '(
        SELECT r.`request_no`
        FROM `harddisk_delivery_requests` r
        WHERE r.`id` = `harddisk_shipments`.`request_id`
        LIMIT 1
    ) AS `delivery_request_no`';
} else {
    $selectFields[] = 'NULL AS `delivery_request_no`';
}

$selectFields[] = $has('seq_no') ? '`seq_no`' : 'NULL AS `seq_no`';
$selectFields[] = $has('main_branch_code') ? '`main_branch_code`' : 'NULL AS `main_branch_code`';
$selectFields[] = $has('branch_code') ? '`branch_code`' : 'NULL AS `branch_code`';
$selectFields[] = $has('branch_name') ? '`branch_name`' : 'NULL AS `branch_name`';
$selectFields[] = $has('hdd_serial') ? '`hdd_serial`' : 'NULL AS `hdd_serial`';
$selectFields[] = $has('reported_by') ? '`reported_by`' : 'NULL AS `reported_by`';
$selectFields[] = $has('created_by') ? '`created_by`' : 'NULL AS `created_by`';
$selectFields[] = $has('remark') ? '`remark`' : 'NULL AS `remark`';

if ($has('status')) {
    $selectFields[] = '`status` AS `display_status`';
} elseif ($has('shipment_status')) {
    $selectFields[] = '`shipment_status` AS `display_status`';
} else {
    $selectFields[] = "'shipped' AS `display_status`";
}

if ($has('shipped_at')) {
    $selectFields[] = '`shipped_at` AS `display_shipped_at`';
    $dateColumn = 'shipped_at';
} elseif ($has('shipped_date')) {
    $selectFields[] = '`shipped_date` AS `display_shipped_at`';
    $dateColumn = 'shipped_date';
} elseif ($has('created_at')) {
    $selectFields[] = '`created_at` AS `display_shipped_at`';
    $dateColumn = 'created_at';
} else {
    $selectFields[] = 'NULL AS `display_shipped_at`';
    $dateColumn = null;
}

$selectFields[] = $has('shipped_date') ? '`shipped_date`' : 'NULL AS `shipped_date`';
$selectFields[] = $has('created_at') ? '`created_at`' : 'NULL AS `created_at`';

$where = [];
$params = [];

if ($has('deleted_at')) {
    $where[] = '`deleted_at` IS NULL';
}

if ($dateColumn !== null && $dateFrom !== '') {
    $where[] = "DATE(`{$dateColumn}`) >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if ($dateColumn !== null && $dateTo !== '') {
    $where[] = "DATE(`{$dateColumn}`) <= :date_to";
    $params[':date_to'] = $dateTo;
}

if ($keyword !== '') {
    $searchColumns = [];
    $keywordLike = '%' . $keyword . '%';

    $keywordIsNumberOnly = preg_match('/^\d+$/', $keyword) === 1;
    $keywordIsShortBranchCode = $keywordIsNumberOnly && strlen($keyword) <= 3;

    if ($keywordIsShortBranchCode) {
        $normalizedBranchKeyword = str_pad($keyword, 3, '0', STR_PAD_LEFT);

        if ($has('main_branch_code')) {
            $searchColumns[] = "LPAD(`main_branch_code`, 3, '0') = :keyword_main_branch_exact";
            $params[':keyword_main_branch_exact'] = $normalizedBranchKeyword;
        }

        if ($has('branch_code')) {
            $searchColumns[] = "LPAD(`branch_code`, 3, '0') = :keyword_branch_code_exact";
            $params[':keyword_branch_code_exact'] = $normalizedBranchKeyword;
        }

        if ($has('request_id') && $hasRequestTable && $hasRequestColumn('id')) {
            $requestSearchParts = [];

            if ($hasRequestColumn('main_branch_code')) {
                $requestSearchParts[] = "LPAD(r.`main_branch_code`, 3, '0') = :keyword_request_main_branch_exact";
                $params[':keyword_request_main_branch_exact'] = $normalizedBranchKeyword;
            }

            if ($hasRequestColumn('branch_code')) {
                $requestSearchParts[] = "LPAD(r.`branch_code`, 3, '0') = :keyword_request_branch_code_exact";
                $params[':keyword_request_branch_code_exact'] = $normalizedBranchKeyword;
            }

            if (!empty($requestSearchParts)) {
                $searchColumns[] = "EXISTS (
                    SELECT 1
                    FROM `harddisk_delivery_requests` r
                    WHERE r.`id` = `harddisk_shipments`.`request_id`
                      AND (" . implode(' OR ', $requestSearchParts) . ")
                )";
            }
        }
    } else {
        foreach (['delivery_request_no', 'request_no', 'branch_name', 'hdd_serial'] as $index => $column) {
            if ($has($column)) {
                $paramName = ':keyword_text_' . $index;
                $searchColumns[] = "`{$column}` LIKE {$paramName}";
                $params[$paramName] = $keywordLike;
            }
        }

        if ($has('request_id') && $hasRequestTable && $hasRequestColumn('id')) {
            $requestSearchParts = [];

            foreach (['request_no', 'branch_name', 'hdd_serial'] as $index => $column) {
                if ($hasRequestColumn($column)) {
                    $paramName = ':keyword_request_text_' . $index;
                    $requestSearchParts[] = "r.`{$column}` LIKE {$paramName}";
                    $params[$paramName] = $keywordLike;
                }
            }

            if (!empty($requestSearchParts)) {
                $searchColumns[] = "EXISTS (
                    SELECT 1
                    FROM `harddisk_delivery_requests` r
                    WHERE r.`id` = `harddisk_shipments`.`request_id`
                      AND (" . implode(' OR ', $requestSearchParts) . ")
                )";
            }
        }
    }

    if (!empty($searchColumns)) {
        $where[] = '(' . implode(' OR ', $searchColumns) . ')';
    }
}

$statusColumn = null;
if ($has('status')) {
    $statusColumn = 'status';
} elseif ($has('shipment_status')) {
    $statusColumn = 'shipment_status';
}

if ($statusFilter !== '' && $statusColumn !== null) {
    $where[] = "`{$statusColumn}` = :status";
    $params[':status'] = $statusFilter;
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

if ($dateColumn !== null) {
    $orderSql = "ORDER BY `{$dateColumn}` DESC, `id` DESC";
} else {
    $orderSql = 'ORDER BY `id` DESC';
}

try {
    $countSql = "
        SELECT COUNT(*) AS total
        FROM `harddisk_shipments`
        {$whereSql}
    ";
    $stmtCount = $pdo->prepare($countSql);
    bindValues($stmtCount, $params);
    $stmtCount->execute();

    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max((int)ceil($totalRows / $perPage), 1);

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $dataSql = "
        SELECT
            " . implode(",\n            ", $selectFields) . "
        FROM `harddisk_shipments`
        {$whereSql}
        {$orderSql}
        LIMIT :limit OFFSET :offset
    ";

    $stmtData = $pdo->prepare($dataSql);
    bindValues($stmtData, $params);
    $stmtData->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtData->execute();
    $shipments = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    $summarySql = "
        SELECT display_status, COUNT(*) AS total
        FROM (
            SELECT
                " . ($has('status') ? '`status`' : ($has('shipment_status') ? '`shipment_status`' : "'shipped'")) . " AS display_status
            FROM `harddisk_shipments`
            {$whereSql}
        ) x
        GROUP BY display_status
        ORDER BY total DESC
    ";
    $stmtSummary = $pdo->prepare($summarySql);
    bindValues($stmtSummary, $params);
    $stmtSummary->execute();
    $summaryRows = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);

    $thisMonthWhere = [];
    $thisMonthParams = [];
    if ($has('deleted_at')) {
        $thisMonthWhere[] = '`deleted_at` IS NULL';
    }
    if ($dateColumn !== null) {
        $thisMonthWhere[] = "DATE_FORMAT(`{$dateColumn}`, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    }
    $thisMonthSqlWhere = !empty($thisMonthWhere) ? 'WHERE ' . implode(' AND ', $thisMonthWhere) : '';
    $stmtThisMonth = $pdo->prepare("SELECT COUNT(*) FROM `harddisk_shipments` {$thisMonthSqlWhere}");
    bindValues($stmtThisMonth, $thisMonthParams);
    $stmtThisMonth->execute();
    $thisMonthTotal = (int)$stmtThisMonth->fetchColumn();
} catch (Throwable $e) {
    require_once $basePath . '/includes/header.php';
    ?>
    <div class="container-fluid py-4">
        <div class="alert alert-danger">
            <strong>เกิดข้อผิดพลาดในการดึงข้อมูลประวัติการจัดส่ง</strong><br>
            รายละเอียด: <?= h($e->getMessage()) ?>
        </div>
    </div>
    <?php
    require_once $basePath . '/includes/footer.php';
    exit;
}

$statusOptions = [];
if ($statusColumn !== null) {
    try {
        $statusSql = "
            SELECT DISTINCT `{$statusColumn}` AS status_name
            FROM `harddisk_shipments`
            WHERE `{$statusColumn}` IS NOT NULL
              AND `{$statusColumn}` <> ''
            ORDER BY `{$statusColumn}` ASC
        ";
        $stmtStatus = $pdo->query($statusSql);
        $statusOptions = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $statusOptions = [];
    }
}


$summaryMap = [];
foreach ($summaryRows as $row) {
    $statusKey = strtolower(trim((string)($row['display_status'] ?? '')));
    if ($statusKey === '') {
        $statusKey = 'unknown';
    }
    if (!isset($summaryMap[$statusKey])) {
        $summaryMap[$statusKey] = 0;
    }
    $summaryMap[$statusKey] += (int)($row['total'] ?? 0);
}

$summaryShipped = 0;
foreach (['sent', 'shipped'] as $statusKey) {
    $summaryShipped += (int)($summaryMap[$statusKey] ?? 0);
}

$summaryCompleted = 0;
foreach (['received', 'installed', 'completed'] as $statusKey) {
    $summaryCompleted += (int)($summaryMap[$statusKey] ?? 0);
}

$summaryPending = (int)($summaryMap['pending'] ?? 0);

$exportParams = $_GET;
$exportParams['export'] = 'csv';
unset($exportParams['page']);

require_once $basePath . '/includes/header.php';
?>

<style>
    body { background: #f3f6fb; }
    .shipment-page { padding: 10px 0 16px 0; }
    .shipment-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .shipment-subtitle { font-size: 13px; color: #64748b; }
    .shipment-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .shipment-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; }
    .shipment-card .card-body { padding: 12px; }
    .hero-card { border: 0; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #ffffff; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22); }
    .hero-card .card-body { padding: 12px 16px; }
    .kpi-card { border: 0; border-radius: 15px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07); height: 100%; }
    .kpi-card .card-body { padding: 12px 14px; }
    .kpi-label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
    .kpi-value { font-size: 28px; font-weight: 900; color: #0f172a; line-height: 1; }
    .kpi-note { font-size: 11px; color: #64748b; margin-top: 5px; }
    .form-label { font-size: 12px; color: #475569; font-weight: 800; margin-bottom: 4px; }
    .form-control, .form-select, .btn { font-size: 13px; border-radius: 10px; }
    .filter-pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 5px 10px; background: #e0f2fe; color: #075985; font-size: 12px; font-weight: 800; }
    .table-scroll { max-height: calc(100vh - 365px); min-height: 330px; overflow: auto; }
    .table-shipment th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 12px; white-space: nowrap; padding: 7px 8px; color: #334155; }
    .table-shipment td { font-size: 12px; vertical-align: middle; padding: 7px 8px; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-weight: 900; color: #7c2d12; white-space: nowrap; }
    .branch-code { font-weight: 900; color: #1d4ed8; white-space: nowrap; }
    .text-ellipsis { max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .remark-cell { max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .status-mini-row { display: flex; justify-content: space-between; gap: 8px; border-bottom: 1px solid #f1f5f9; padding: 6px 0; font-size: 12px; }
    .status-mini-row:last-child { border-bottom: 0; }
    .pagination .page-link { font-size: 12px; padding: 4px 9px; }
    @media (max-width: 1366px) {
        .shipment-page { padding-top: 8px; }
        .shipment-title { font-size: 20px; }
        .shipment-card .card-body { padding: 10px; }
        .kpi-card .card-body { padding: 10px 12px; }
        .kpi-value { font-size: 25px; }
        .table-scroll { max-height: calc(100vh - 345px); min-height: 305px; }
        .table-shipment th, .table-shipment td { font-size: 11.5px; padding: 6px 7px; }
        .form-control, .form-select { font-size: 12px; }
    }
    @media print {
        .no-print, .pagination, .btn { display: none !important; }
        body { background: #ffffff; }
        .table-scroll { max-height: none; overflow: visible; }
        .shipment-card, .kpi-card, .hero-card { box-shadow: none; border: 1px solid #e5e7eb; }
    }
</style>

<div class="container-fluid shipment-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="shipment-title">ประวัติการจัดส่ง Harddisk</h3>
            <div class="shipment-subtitle">ค้นหาและตรวจสอบรายการจัดส่ง HDD ให้สาขา พร้อมรูปแบบหน้าจอเดียวกับหน้า รับคืน HDD ส่งเคลมจากสาขา</div>
        </div>
        <div class="d-flex gap-2 no-print">
            <a href="import.php" class="btn btn-success btn-sm">⬆️ อัปโหลดไฟล์</a>
            <button type="button" onclick="window.print();" class="btn btn-outline-secondary btn-sm">🖨️ พิมพ์</button>
            <a href="../dashboard/index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
        </div>
    </div>

    <div class="card hero-card mb-2 no-print">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold"></div>
                <div class="small opacity-75">หน้านี้ใช้ติดตามรายการที่จัดส่ง HDD ไปยังสาขาแล้ว พร้อมค้นหาด้วยเลขที่คำขอ รหัสสาขา ชื่อสาขา และ Serial HDD</div>
            </div>
            <div class="small">ทั้งหมดตามตัวกรอง: <strong><?php echo number_format($totalRows); ?></strong> รายการ</div>
        </div>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">รายการตามตัวกรอง</div><div class="kpi-value"><?php echo number_format($totalRows); ?></div><div class="kpi-note">รายการที่พบจากเงื่อนไขค้นหา</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">จัดส่งเดือนนี้</div><div class="kpi-value"><?php echo number_format($thisMonthTotal); ?></div><div class="kpi-note">นับจากวันที่จัดส่ง</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">จัดส่งแล้ว</div><div class="kpi-value"><?php echo number_format($summaryShipped); ?></div><div class="kpi-note">สถานะ shipped / sent</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">ปลายทางรับแล้ว</div><div class="kpi-value"><?php echo number_format($summaryCompleted); ?></div><div class="kpi-note">received / installed / completed</div></div></div></div>
    </div>

    <div class="card shipment-card mb-2 no-print">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>ค้นหาและกรองประวัติการจัดส่ง</div>
            <?php if ($keyword !== '' || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                <span class="filter-pill">🔎 กำลังกรองข้อมูล</span>
            <?php endif; ?>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-xl-4 col-lg-4 col-md-6">
                    <label for="keyword" class="form-label">ค้นหา Keyword</label>
                    <input
                        type="text"
                        name="keyword"
                        id="keyword"
                        class="form-control"
                        value="<?= h($keyword) ?>"
                        placeholder="เลขที่คำขอ, รหัสสาขา, ชื่อสาขา, Serial HDD"
                    >
                    <div class="form-text small">
                        ตัวเลข 1-3 หลักจะค้นเป็นรหัสสาขาเท่านั้น เช่น 240
                    </div>
                </div>

                <div class="col-xl-2 col-lg-2 col-md-3">
                    <label for="date_from" class="form-label">วันที่เริ่มต้น</label>
                    <input type="date" name="date_from" id="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                </div>

                <div class="col-xl-2 col-lg-2 col-md-3">
                    <label for="date_to" class="form-label">วันที่สิ้นสุด</label>
                    <input type="date" name="date_to" id="date_to" class="form-control" value="<?= h($dateTo) ?>">
                </div>

                <div class="col-xl-2 col-lg-2 col-md-4">
                    <label for="status" class="form-label">สถานะ</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($statusOptions as $statusOption): ?>
                            <option value="<?= h($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                                <?= h(shipmentStatusText($statusOption)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-xl-1 col-lg-1 col-md-4">
                    <label for="per_page" class="form-label">แสดง</label>
                    <select name="per_page" id="per_page" class="form-select">
                        <?php foreach ([10, 20, 50, 100] as $option): ?>
                            <option value="<?= (int)$option ?>" <?= $perPage === $option ? 'selected' : '' ?>>
                                <?= (int)$option ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-xl-1 col-lg-1 col-md-4 d-grid">
                    <button type="submit" class="btn btn-primary">ค้นหา</button>
                </div>

                <div class="col-12 d-flex flex-wrap gap-2">
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">ล้างค่า</a>
                    
                </div>
            </form>
        </div>
    </div>

    <div class="card shipment-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>รายการจัดส่งทั้งหมด</strong>
                <span class="text-muted small"><?= number_format($totalRows) ?> รายการ</span>
            </div>

            <div class="text-muted small">
                หน้า <?= number_format($page) ?> / <?= number_format($totalPages) ?>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive table-scroll">
                <table class="table table-hover table-bordered align-middle mb-0 table-shipment">
                    <thead>
                        <tr>
                            <th style="width:60px;" class="text-center">ลำดับ</th>
                            <th style="width:135px;">เลขที่คำขอ</th>
                            <th style="width:95px;">รหัสสาขา</th>
                            <th style="width:120px;">Cost Center</th>
                            <th>ชื่อสาขา</th>
                            <th style="width:145px;">Serial HDD</th>
                            <th style="width:120px;">สถานะ</th>
                            <th style="width:105px;">วันที่ส่ง</th>
                            <th style="width:145px;">ผู้แจ้ง/ผู้บันทึก</th>
                            <th style="width:210px;">หมายเหตุ</th>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($shipments)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    ไม่พบข้อมูลประวัติการจัดส่ง
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($shipments as $index => $row): ?>
                                <?php
                                $runningNo = $offset + $index + 1;
                                $displayDate = $row['display_shipped_at'] ?? $row['shipped_date'] ?? null;
                                $requestNo = trim((string)($row['delivery_request_no'] ?? ''));
                                $mainBranchCode = trim((string)($row['main_branch_code'] ?? ''));
                                $branchCode = trim((string)($row['branch_code'] ?? ''));
                                $branchName = trim((string)($row['branch_name'] ?? ''));
                                $hddSerial = trim((string)($row['hdd_serial'] ?? ''));
                                $status = trim((string)($row['display_status'] ?? ''));
                                $reporter = trim((string)($row['reported_by'] ?? ''));

                                if ($reporter === '' && !empty($row['created_by'])) {
                                    $reporter = trim((string)$row['created_by']);
                                }

                                $remark = trim((string)($row['remark'] ?? ''));
                                ?>
                                <tr>
                                    <td class="text-center"><?= h((string)$runningNo) ?></td>
                                    <td class="text-nowrap"><strong><?= $requestNo !== '' ? h($requestNo) : '-' ?></strong></td>
                                    <td class="text-nowrap"><span class="branch-code"><?= $mainBranchCode !== '' ? h(formatMainBranchCode($mainBranchCode)) : '-' ?></span></td>
                                    <td class="text-nowrap"><?= $branchCode !== '' ? h($branchCode) : '-' ?></td>
                                    <td>
                                        <div class="text-ellipsis" title="<?= h($branchName) ?>">
                                            <?= $branchName !== '' ? h($branchName) : '-' ?>
                                        </div>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= $hddSerial !== '' ? '<span class="serial-text">' . h($hddSerial) . '</span>' : '-' ?>
                                    </td>
                                    <td class="text-nowrap"><?= shipmentStatusBadge($status) ?></td>
                                    <td class="text-nowrap"><?= h(formatDateThai($displayDate)) ?></td>
                                    <td>
                                        <div class="text-ellipsis" title="<?= h($reporter) ?>">
                                            <?= $reporter !== '' ? h($reporter) : '-' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="remark-cell" title="<?= h($remark) ?>">
                                            <?= $remark !== '' ? h(shortText($remark, 100)) : '-' ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white no-print">
                <nav aria-label="Shipment pagination">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <?php $queryBase = $_GET; ?>

                        <?php
                        $prevPage = max($page - 1, 1);
                        $queryBase['page'] = $prevPage;
                        ?>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>">ก่อนหน้า</a>
                        </li>

                        <?php
                        $startPage = max($page - 2, 1);
                        $endPage = min($page + 2, $totalPages);
                        ?>

                        <?php if ($startPage > 1): ?>
                            <?php $queryBase['page'] = 1; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php $queryBase['page'] = $i; ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>"><?= h((string)$i) ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <?php $queryBase['page'] = $totalPages; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>"><?= h((string)$totalPages) ?></a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $nextPage = min($page + 1, $totalPages);
                        $queryBase['page'] = $nextPage;
                        ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>">ถัดไป</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once $basePath . '/includes/footer.php'; ?>
