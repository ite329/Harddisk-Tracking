<?php
declare(strict_types=1);

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/auth.php';

require_login();

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
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

function hasColumn(array $columns, string $columnName): bool
{
    return in_array($columnName, $columns, true);
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

function requestStatusText($status): string
{
    $status = trim((string)($status ?? ''));

    $map = [
        'pending_scan' => 'รอยิงบาร์โค้ด',
        'pending' => 'รอดำเนินการ',
        'matched' => 'รอยืนยันจัดส่ง',
        'reserved' => 'จับคู่ HDD แล้ว',
        'shipped' => 'จัดส่งแล้ว',
        'received' => 'สาขาได้รับแล้ว',
        'installed' => 'ติดตั้งแล้ว',
        'cancelled' => 'ยกเลิก',
        'rejected' => 'ไม่อนุมัติ',
        'completed' => 'เสร็จสิ้น',
    ];

    return $map[$status] ?? ($status !== '' ? $status : '-');
}

function requestStatusBadge($status): string
{
    $status = trim((string)($status ?? ''));

    $class = 'bg-secondary';

    if ($status === 'pending_scan' || $status === 'pending') {
        $class = 'bg-warning text-dark';
    } elseif ($status === 'matched' || $status === 'reserved') {
        $class = 'bg-info text-dark';
    } elseif ($status === 'shipped') {
        $class = 'bg-primary';
    } elseif ($status === 'received' || $status === 'installed' || $status === 'completed') {
        $class = 'bg-success';
    } elseif ($status === 'cancelled') {
        $class = 'bg-secondary';
    } elseif ($status === 'rejected') {
        $class = 'bg-danger';
    }

    return '<span class="badge ' . h($class) . '">' . h(requestStatusText($status)) . '</span>';
}

function inventoryStatusText($status): string
{
    $status = trim((string)($status ?? ''));

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

function bindParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
}

function getCount(PDO $pdo, string $sql, array $params = []): int
{
    $stmt = $pdo->prepare($sql);
    bindParams($stmt, $params);
    $stmt->execute();

    return (int)$stmt->fetchColumn();
}

$requestColumns = getTableColumns($pdo, 'harddisk_delivery_requests');
$inventoryColumns = getTableColumns($pdo, 'harddisk_inventory');
$shipmentColumns = getTableColumns($pdo, 'harddisk_shipments');

$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$mainBranchCode = trim((string)($_GET['main_branch_code'] ?? ''));
$keyword = trim((string)($_GET['keyword'] ?? ''));

if ($mainBranchCode !== '') {
    $mainBranchCode = preg_replace('/[^0-9]/', '', $mainBranchCode);
    if ($mainBranchCode !== '' && strlen($mainBranchCode) < 3) {
        $mainBranchCode = str_pad($mainBranchCode, 3, '0', STR_PAD_LEFT);
    }
}

$requestWhere = [];
$requestParams = [];

if (hasColumn($requestColumns, 'deleted_at')) {
    $requestWhere[] = 'r.deleted_at IS NULL';
}

$dateColumn = hasColumn($requestColumns, 'created_at') ? 'created_at' : 'id';

if ($dateFrom !== '' && hasColumn($requestColumns, 'created_at')) {
    $requestWhere[] = 'DATE(r.created_at) >= :date_from';
    $requestParams[':date_from'] = $dateFrom;
}

if ($dateTo !== '' && hasColumn($requestColumns, 'created_at')) {
    $requestWhere[] = 'DATE(r.created_at) <= :date_to';
    $requestParams[':date_to'] = $dateTo;
}

if ($statusFilter !== '' && hasColumn($requestColumns, 'status')) {
    $requestWhere[] = 'r.status = :status';
    $requestParams[':status'] = $statusFilter;
}

if ($mainBranchCode !== '' && hasColumn($requestColumns, 'main_branch_code')) {
    $requestWhere[] = "LPAD(r.main_branch_code, 3, '0') = :main_branch_code";
    $requestParams[':main_branch_code'] = $mainBranchCode;
}

if ($keyword !== '') {
    $searchParts = [];
    $searchColumns = [
        'request_no',
        'main_branch_code',
        'branch_code',
        'branch_name',
        'hdd_serial',
        'request_reason',
        'remark',
        'created_by',
        'requested_by'
    ];

    $i = 0;
    foreach ($searchColumns as $column) {
        if (hasColumn($requestColumns, $column)) {
            $paramName = ':keyword_' . $i;
            $searchParts[] = 'r.' . $column . ' LIKE ' . $paramName;
            $requestParams[$paramName] = '%' . $keyword . '%';
            $i++;
        }
    }

    if (!empty($searchParts)) {
        $requestWhere[] = '(' . implode(' OR ', $searchParts) . ')';
    }
}

$requestWhereSql = '';
if (!empty($requestWhere)) {
    $requestWhereSql = 'WHERE ' . implode(' AND ', $requestWhere);
}

if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    $exportColumns = [];

    foreach ([
        'request_no',
        'main_branch_code',
        'branch_code',
        'branch_name',
        'hdd_serial',
        'request_reason',
        'status',
        'created_by',
        'requested_by',
        'created_at',
        'remark'
    ] as $column) {
        if (hasColumn($requestColumns, $column)) {
            $exportColumns[] = 'r.' . $column;
        }
    }

    if (empty($exportColumns)) {
        $exportColumns[] = 'r.id';
    }

    $stmtExport = $pdo->prepare("
        SELECT " . implode(', ', $exportColumns) . "
        FROM harddisk_delivery_requests r
        {$requestWhereSql}
        ORDER BY r.id DESC
    ");
    bindParams($stmtExport, $requestParams);
    $stmtExport->execute();

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename=hdd_report_' . date('Ymd_His') . '.csv');

    echo "\xEF\xBB\xBF";

    $out = fopen('php://output', 'w');

    $headerMap = [
        'request_no' => 'เลขที่คำขอ',
        'main_branch_code' => 'รหัสสาขาใหญ่',
        'branch_code' => 'Cost Center',
        'branch_name' => 'ชื่อสาขา',
        'hdd_serial' => 'Serial HDD',
        'request_reason' => 'สาเหตุ',
        'status' => 'สถานะ',
        'created_by' => 'ผู้บันทึก',
        'requested_by' => 'ผู้บันทึก',
        'created_at' => 'วันที่บันทึก',
        'remark' => 'หมายเหตุ',
        'id' => 'ID',
    ];

    $firstRow = true;

    while ($row = $stmtExport->fetch(PDO::FETCH_ASSOC)) {
        if ($firstRow) {
            $headers = [];
            foreach (array_keys($row) as $key) {
                $headers[] = $headerMap[$key] ?? $key;
            }
            fputcsv($out, $headers);
            $firstRow = false;
        }

        if (isset($row['status'])) {
            $row['status'] = requestStatusText($row['status']);
        }

        fputcsv($out, $row);
    }

    fclose($out);
    exit;
}

$totalRequests = 0;
$pendingScan = 0;
$waitingConfirm = 0;
$completedRequests = 0;
$availableStock = 0;
$reservedStock = 0;
$totalInventory = 0;
$totalShipments = 0;
$thisMonthShipments = 0;

$requestStatusRows = [];
$inventoryStatusRows = [];
$shipmentMonthRows = [];
$latestRequests = [];

try {
    if (tableExists($pdo, 'harddisk_delivery_requests')) {
        $totalRequests = getCount($pdo, "
            SELECT COUNT(*)
            FROM harddisk_delivery_requests r
            {$requestWhereSql}
        ", $requestParams);

        if (hasColumn($requestColumns, 'status')) {
            $pendingScan = getCount($pdo, "
                SELECT COUNT(*)
                FROM harddisk_delivery_requests r
                {$requestWhereSql}
                " . ($requestWhereSql === '' ? 'WHERE' : 'AND') . " r.status IN ('pending_scan', 'pending')
            ", $requestParams);

            $waitingConfirm = getCount($pdo, "
                SELECT COUNT(*)
                FROM harddisk_delivery_requests r
                {$requestWhereSql}
                " . ($requestWhereSql === '' ? 'WHERE' : 'AND') . " r.status IN ('matched', 'reserved')
            ", $requestParams);

            $completedRequests = getCount($pdo, "
                SELECT COUNT(*)
                FROM harddisk_delivery_requests r
                {$requestWhereSql}
                " . ($requestWhereSql === '' ? 'WHERE' : 'AND') . " r.status IN ('shipped', 'received', 'installed', 'completed')
            ", $requestParams);

            $stmtStatus = $pdo->prepare("
                SELECT r.status, COUNT(*) AS total
                FROM harddisk_delivery_requests r
                {$requestWhereSql}
                GROUP BY r.status
                ORDER BY total DESC
            ");
            bindParams($stmtStatus, $requestParams);
            $stmtStatus->execute();
            $requestStatusRows = $stmtStatus->fetchAll(PDO::FETCH_ASSOC);
        }

        $selectRequestColumns = [];

        foreach ([
            'id',
            'request_no',
            'main_branch_code',
            'branch_code',
            'branch_name',
            'hdd_serial',
            'request_reason',
            'status',
            'created_by',
            'requested_by',
            'created_at',
            'remark'
        ] as $column) {
            if (hasColumn($requestColumns, $column)) {
                $selectRequestColumns[] = 'r.' . $column;
            }
        }

        if (empty($selectRequestColumns)) {
            $selectRequestColumns[] = 'r.id';
        }

        $stmtLatest = $pdo->prepare("
            SELECT " . implode(', ', $selectRequestColumns) . "
            FROM harddisk_delivery_requests r
            {$requestWhereSql}
            ORDER BY r.id DESC
            LIMIT 50
        ");
        bindParams($stmtLatest, $requestParams);
        $stmtLatest->execute();
        $latestRequests = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);
    }

    if (tableExists($pdo, 'harddisk_inventory')) {
        $inventoryWhere = hasColumn($inventoryColumns, 'deleted_at') ? 'WHERE deleted_at IS NULL' : '';

        $totalInventory = getCount($pdo, "
            SELECT COUNT(*)
            FROM harddisk_inventory
            {$inventoryWhere}
        ");

        if (hasColumn($inventoryColumns, 'status')) {
            $availableStock = getCount($pdo, "
                SELECT COUNT(*)
                FROM harddisk_inventory
                {$inventoryWhere}
                " . ($inventoryWhere === '' ? 'WHERE' : 'AND') . " status = 'available'
            ");

            $reservedStock = getCount($pdo, "
                SELECT COUNT(*)
                FROM harddisk_inventory
                {$inventoryWhere}
                " . ($inventoryWhere === '' ? 'WHERE' : 'AND') . " status = 'reserved'
            ");

            $stmtInv = $pdo->query("
                SELECT status, COUNT(*) AS total
                FROM harddisk_inventory
                {$inventoryWhere}
                GROUP BY status
                ORDER BY total DESC
            ");
            $inventoryStatusRows = $stmtInv->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (tableExists($pdo, 'harddisk_shipments')) {
        $shipmentWhere = hasColumn($shipmentColumns, 'deleted_at') ? 'WHERE deleted_at IS NULL' : '';

        $totalShipments = getCount($pdo, "
            SELECT COUNT(*)
            FROM harddisk_shipments
            {$shipmentWhere}
        ");

        if (hasColumn($shipmentColumns, 'shipped_at')) {
            $thisMonthShipments = getCount($pdo, "
                SELECT COUNT(*)
                FROM harddisk_shipments
                {$shipmentWhere}
                " . ($shipmentWhere === '' ? 'WHERE' : 'AND') . " DATE_FORMAT(shipped_at, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            ");

            $stmtMonth = $pdo->query("
                SELECT DATE_FORMAT(shipped_at, '%Y-%m') AS ym, COUNT(*) AS total
                FROM harddisk_shipments
                {$shipmentWhere}
                " . ($shipmentWhere === '' ? 'WHERE' : 'AND') . " shipped_at IS NOT NULL
                GROUP BY DATE_FORMAT(shipped_at, '%Y-%m')
                ORDER BY ym DESC
                LIMIT 12
            ");
            $shipmentMonthRows = $stmtMonth->fetchAll(PDO::FETCH_ASSOC);
        } elseif (hasColumn($shipmentColumns, 'shipped_date')) {
            $thisMonthShipments = getCount($pdo, "
                SELECT COUNT(*)
                FROM harddisk_shipments
                {$shipmentWhere}
                " . ($shipmentWhere === '' ? 'WHERE' : 'AND') . " DATE_FORMAT(shipped_date, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')
            ");

            $stmtMonth = $pdo->query("
                SELECT DATE_FORMAT(shipped_date, '%Y-%m') AS ym, COUNT(*) AS total
                FROM harddisk_shipments
                {$shipmentWhere}
                " . ($shipmentWhere === '' ? 'WHERE' : 'AND') . " shipped_date IS NOT NULL
                GROUP BY DATE_FORMAT(shipped_date, '%Y-%m')
                ORDER BY ym DESC
                LIMIT 12
            ");
            $shipmentMonthRows = $stmtMonth->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $reportError = $e->getMessage();
}

$pageTitle = 'รายงาน';
require_once __DIR__ . '/../../includes/header.php';

$exportParams = $_GET;
$exportParams['export'] = 'csv';
?>

<style>
    body {
        background: #f3f6fb;
    }

    .report-page {
        padding: 8px 0 12px 0;
    }

    .report-title {
        font-weight: 800;
        color: #0f172a;
        font-size: 22px;
        line-height: 1.15;
        margin: 0;
    }

    .report-subtitle {
        color: #64748b;
        font-size: 13px;
        line-height: 1.25;
    }

    .report-top-actions .btn {
        font-size: 12px;
        padding: 6px 10px;
        border-radius: 9px;
        font-weight: 700;
        white-space: nowrap;
    }



    .hero-card {
        border: 0;
        border-radius: 16px;
        background: linear-gradient(135deg, #0ea5e9, #2563eb);
        color: #ffffff;
        box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22);
        overflow: hidden;
    }

    .hero-card .card-body {
        padding: 12px 16px;
    }

    .hero-card .hero-title {
        font-size: 15px;
        font-weight: 900;
        line-height: 1.25;
    }

    .hero-card .hero-desc {
        font-size: 12px;
        opacity: 0.82;
        margin-top: 2px;
    }

    .workflow-pill {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 6px 10px;
        border-radius: 999px;
        background: rgba(255, 255, 255, 0.16);
        color: #ffffff;
        font-size: 12px;
        font-weight: 800;
        white-space: nowrap;
    }

    .step-title {
        font-size: 13px;
        font-weight: 900;
        color: #0f172a;
        display: flex;
        align-items: center;
        gap: 6px;
    }

    .step-badge {
        width: 22px;
        height: 22px;
        border-radius: 8px;
        background: #2563eb;
        color: #ffffff;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 900;
    }

    .filter-card {
        border: 0;
        border-radius: 14px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07);
        overflow: hidden;
    }

    .filter-card .card-body {
        padding: 10px 12px;
    }

    .filter-card .form-label {
        font-size: 12px;
        font-weight: 700;
        color: #475569;
        margin-bottom: 4px;
    }

    .filter-card .form-control,
    .filter-card .form-select {
        font-size: 13px;
        min-height: 32px;
        padding: 4px 8px;
        border-radius: 8px;
    }

    .filter-card .btn {
        font-size: 13px;
        min-height: 32px;
        border-radius: 8px;
        padding: 4px 10px;
        font-weight: 700;
    }

    .kpi-card {
        border: 0;
        border-radius: 14px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07);
        overflow: hidden;
        height: 100%;
    }

    .kpi-card .card-body {
        padding: 11px 13px;
    }

    .kpi-label {
        color: #64748b;
        font-size: 12px;
        margin-bottom: 5px;
        white-space: nowrap;
    }

    .kpi-value {
        font-size: 28px;
        font-weight: 900;
        color: #0f172a;
        line-height: 1;
    }

    .kpi-note {
        font-size: 11px;
        color: #64748b;
        margin-top: 5px;
        line-height: 1.2;
    }

    .report-card {
        border: 0;
        border-radius: 14px;
        box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07);
        overflow: hidden;
    }

    .report-card .card-header {
        background: #ffffff;
        font-weight: 800;
        border-bottom: 1px solid #e5e7eb;
        padding: 8px 11px;
        font-size: 13px;
        min-height: 38px;
    }

    .report-card .card-body {
        padding: 11px 12px;
    }

    .summary-scroll {
        max-height: 205px;
        overflow: auto;
        padding-right: 2px;
    }

    .status-row {
        display: flex;
        justify-content: space-between;
        gap: 8px;
        padding: 6px 0;
        border-bottom: 1px solid #f1f5f9;
        font-size: 12px;
    }

    .status-row:last-child {
        border-bottom: 0;
    }

    .status-row .badge {
        font-size: 11px;
    }

    .status-count {
        font-weight: 900;
        color: #0f172a;
        font-size: 13px;
    }

    .mini-bar {
        height: 6px;
        background: #e5e7eb;
        border-radius: 999px;
        overflow: hidden;
        margin-top: 5px;
    }

    .mini-bar-fill {
        height: 100%;
        background: #2563eb;
        border-radius: 999px;
    }

    .report-table-scroll {
        max-height: 330px;
        overflow: auto;
    }

    .report-table {
        font-size: 12px;
    }

    .report-table th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f8fafc;
        white-space: nowrap;
        color: #334155;
        font-size: 12px;
        padding: 7px 8px;
    }

    .report-table td {
        vertical-align: middle;
        font-size: 12px;
        padding: 7px 8px;
    }

    .report-table .badge {
        font-size: 11px;
    }

    .serial-text {
        font-family: Consolas, Monaco, monospace;
        font-weight: 800;
        color: #7c2d12;
        white-space: nowrap;
    }

    .branch-name-cell,
    .reason-cell,
    .user-cell {
        max-width: 180px;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }

    @media (max-width: 1366px) {
        .container-fluid {
            padding-left: 14px;
            padding-right: 14px;
        }

        .report-page {
            padding-top: 6px;
        }

        .report-title {
            font-size: 20px;
        }

        .report-subtitle {
            font-size: 12px;
        }

        .kpi-card .card-body {
            padding: 9px 11px;
        }

        .kpi-value {
            font-size: 24px;
        }

        .kpi-note {
            font-size: 10.5px;
        }

        .summary-scroll {
            max-height: 180px;
        }

        .report-table-scroll {
            max-height: 285px;
        }

        .report-table th,
        .report-table td {
            padding: 6px 7px;
            font-size: 11.5px;
        }

        .branch-name-cell {
            max-width: 160px;
        }

        .reason-cell,
        .user-cell {
            max-width: 140px;
        }
    }

    @media (max-width: 991px) {
        .summary-scroll,
        .report-table-scroll {
            max-height: none;
        }

        .branch-name-cell,
        .reason-cell,
        .user-cell {
            max-width: 260px;
        }
    }

    @media print {
        body {
            background: #ffffff;
        }

        .filter-card,
        .report-top-actions {
            display: none !important;
        }

        .report-page {
            padding: 0;
        }

        .report-card,
        .kpi-card {
            box-shadow: none;
            border: 1px solid #e5e7eb;
        }

        .summary-scroll,
        .report-table-scroll {
            max-height: none;
            overflow: visible;
        }

        .report-table th {
            position: static;
        }
    }
</style>

<div class="container-fluid report-page">

    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="report-title mb-1">รายงานระบบจัดส่ง Harddisk</h3>
            <div class="report-subtitle">
                สรุปภาพรวมคำขอส่ง HDD, คลัง HDD และประวัติการจัดส่ง
            </div>
        </div>

        <div class="d-flex gap-2 report-top-actions">
            <a href="?<?php echo h(http_build_query($exportParams)); ?>" class="btn btn-success btn-sm">
                Export CSV
            </a>
            <button type="button" onclick="window.print();" class="btn btn-outline-secondary btn-sm">
                พิมพ์รายงาน
            </button>
        </div>
    </div>

    <?php if (!empty($reportError)): ?>
        <div class="alert alert-danger py-2 mb-2">
            <strong>เกิดข้อผิดพลาด:</strong> <?php echo h($reportError); ?>
        </div>
    <?php endif; ?>

    <div class="card hero-card mb-2">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="hero-title"></div>
                <div class="hero-desc">ดูภาพรวมคำขอส่ง HDD, สถานะคลัง, ยอดจัดส่ง และ Export ข้อมูลได้ในหน้าเดียว</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="workflow-pill">📝 คำขอ <?php echo number_format($totalRequests); ?></span>
                <span class="workflow-pill">💽 Stock <?php echo number_format($availableStock); ?></span>
                <span class="workflow-pill">🚚 ส่งเดือนนี้ <?php echo number_format($thisMonthShipments); ?></span>
            </div>
        </div>
    </div>

    <div class="card report-card filter-card mb-2">
        <div class="card-header">
            <div class="step-title"><span class="step-badge">1</span> ค้นหาและกรองข้อมูลรายงาน</div>
        </div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label">วันที่เริ่มต้น</label>
                    <input type="date" name="date_from" class="form-control form-control-sm" value="<?php echo h($dateFrom); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">วันที่สิ้นสุด</label>
                    <input type="date" name="date_to" class="form-control form-control-sm" value="<?php echo h($dateTo); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">รหัสสาขาใหญ่</label>
                    <input type="text"
                           name="main_branch_code"
                           class="form-control form-control-sm"
                           maxlength="3"
                           placeholder="เช่น 017"
                           value="<?php echo h($mainBranchCode); ?>">
                </div>

                <div class="col-md-2">
                    <label class="form-label">สถานะคำขอ</label>
                    <select name="status" class="form-select form-select-sm">
                        <?php
                        $statusOptions = [
                            '' => 'ทั้งหมด',
                            'pending_scan' => 'รอยิงบาร์โค้ด',
                            'pending' => 'รอดำเนินการ',
                            'matched' => 'รอยืนยันจัดส่ง',
                            'reserved' => 'จับคู่ HDD แล้ว',
                            'shipped' => 'จัดส่งแล้ว',
                            'received' => 'สาขาได้รับแล้ว',
                            'installed' => 'ติดตั้งแล้ว',
                            'completed' => 'เสร็จสิ้น',
                            'cancelled' => 'ยกเลิก',
                            'rejected' => 'ไม่อนุมัติ',
                        ];

                        foreach ($statusOptions as $value => $label):
                        ?>
                            <option value="<?php echo h($value); ?>" <?php echo $statusFilter === $value ? 'selected' : ''; ?>>
                                <?php echo h($label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="col-md-3">
                    <label class="form-label">ค้นหา</label>
                    <input type="text"
                           name="keyword"
                           class="form-control form-control-sm"
                           placeholder="เลขที่คำขอ, Cost Center, สาขา, Serial"
                           value="<?php echo h($keyword); ?>">
                </div>

                <div class="col-md-1 d-grid">
                    <button type="submit" class="btn btn-primary btn-sm">
                        ค้นหา
                    </button>
                </div>

                <div class="col-md-12">
                    <a href="index.php" class="btn btn-outline-secondary btn-sm">
                        ล้างตัวกรอง
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card">
                <div class="card-body">
                    <div class="kpi-label">คำขอทั้งหมด</div>
                    <div class="kpi-value"><?php echo number_format($totalRequests); ?></div>
                    <div class="kpi-note">ตามเงื่อนไขตัวกรองปัจจุบัน</div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card">
                <div class="card-body">
                    <div class="kpi-label">รอยิงบาร์โค้ด</div>
                    <div class="kpi-value"><?php echo number_format($pendingScan); ?></div>
                    <div class="kpi-note">รอจับคู่ HDD กับสาขา</div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card">
                <div class="card-body">
                    <div class="kpi-label">รอยืนยันจัดส่ง</div>
                    <div class="kpi-value"><?php echo number_format($waitingConfirm); ?></div>
                    <div class="kpi-note">จับคู่ HDD แล้ว รอขั้นตอนจัดส่ง</div>
                </div>
            </div>
        </div>

        <div class="col-xl-3 col-md-6">
            <div class="card kpi-card">
                <div class="card-body">
                    <div class="kpi-label">Stock พร้อมใช้งาน</div>
                    <div class="kpi-value"><?php echo number_format($availableStock); ?></div>
                    <div class="kpi-note">จากคลัง Harddisk ทั้งหมด <?php echo number_format($totalInventory); ?> ลูก</div>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-lg-4">
            <div class="card report-card h-100">
                <div class="card-header">🧾 สรุปคำขอตามสถานะ</div>
                <div class="card-body summary-scroll">
                    <?php if (empty($requestStatusRows)): ?>
                        <div class="text-muted">ไม่พบข้อมูล</div>
                    <?php else: ?>
                        <?php foreach ($requestStatusRows as $row): ?>
                            <?php
                            $count = (int)$row['total'];
                            $percent = $totalRequests > 0 ? min(100, round(($count / $totalRequests) * 100)) : 0;
                            ?>
                            <div class="status-row">
                                <div class="flex-grow-1">
                                    <?php echo requestStatusBadge($row['status'] ?? ''); ?>
                                    <div class="mini-bar">
                                        <div class="mini-bar-fill" style="width: <?php echo (int)$percent; ?>%;"></div>
                                    </div>
                                </div>
                                <div class="status-count"><?php echo number_format($count); ?></div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card report-card h-100">
                <div class="card-header">💽 สรุป HDD ในคลัง</div>
                <div class="card-body summary-scroll">
                    <?php if (empty($inventoryStatusRows)): ?>
                        <div class="text-muted">ไม่พบข้อมูล</div>
                    <?php else: ?>
                        <?php foreach ($inventoryStatusRows as $row): ?>
                            <?php
                            $count = (int)$row['total'];
                            $percent = $totalInventory > 0 ? min(100, round(($count / $totalInventory) * 100)) : 0;
                            ?>
                            <div class="status-row">
                                <div class="flex-grow-1">
                                    <strong><?php echo h(inventoryStatusText($row['status'] ?? '')); ?></strong>
                                    <div class="mini-bar">
                                        <div class="mini-bar-fill" style="width: <?php echo (int)$percent; ?>%;"></div>
                                    </div>
                                </div>
                                <div class="status-count"><?php echo number_format($count); ?></div>
                            </div>
                        <?php endforeach; ?>

                        <div class="mt-3 small text-muted">
                            จองไว้: <?php echo number_format($reservedStock); ?> ลูก
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card report-card h-100">
                <div class="card-header">🚚 ยอดจัดส่งรายเดือน</div>
                <div class="card-body summary-scroll">
                    <?php if (empty($shipmentMonthRows)): ?>
                        <div class="text-muted">ไม่พบข้อมูล</div>
                    <?php else: ?>
                        <?php
                        $maxMonth = 1;
                        foreach ($shipmentMonthRows as $row) {
                            $maxMonth = max($maxMonth, (int)$row['total']);
                        }
                        ?>
                        <?php foreach ($shipmentMonthRows as $row): ?>
                            <?php
                            $count = (int)$row['total'];
                            $percent = $maxMonth > 0 ? min(100, round(($count / $maxMonth) * 100)) : 0;
                            ?>
                            <div class="status-row">
                                <div class="flex-grow-1">
                                    <strong><?php echo h($row['ym'] ?? '-'); ?></strong>
                                    <div class="mini-bar">
                                        <div class="mini-bar-fill" style="width: <?php echo (int)$percent; ?>%;"></div>
                                    </div>
                                </div>
                                <div class="status-count"><?php echo number_format($count); ?></div>
                            </div>
                        <?php endforeach; ?>

                        <div class="mt-3 small text-muted">
                            เดือนนี้จัดส่งแล้ว <?php echo number_format($thisMonthShipments); ?> รายการ /
                            สะสมทั้งหมด <?php echo number_format($totalShipments); ?> รายการ
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="card report-card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div>📋 รายละเอียดคำขอส่ง HDD ล่าสุด</div>
            <div class="text-muted small">แสดงสูงสุด 50 รายการ</div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive report-table-scroll">
                <table class="table table-hover table-bordered align-middle mb-0 report-table">
                    <thead>
                        <tr>
                            <th style="width:70px;">ลำดับ</th>
                            <th>เลขที่คำขอ</th>
                            <th>รหัสสาขาใหญ่</th>
                            <th>Cost Center</th>
                            <th>ชื่อสาขา</th>
                            <th>Serial HDD</th>
                            <th>สถานะ</th>
                            <th>สาเหตุ</th>
                            <th>ผู้บันทึก</th>
                            <th>วันที่บันทึก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($latestRequests)): ?>
                            <tr>
                                <td colspan="10" class="text-center text-muted py-4">
                                    ไม่พบข้อมูลคำขอส่ง HDD
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($latestRequests as $index => $row): ?>
                                <?php
                                $createdBy = $row['created_by'] ?? ($row['requested_by'] ?? '-');
                                ?>
                                <tr>
                                    <td><?php echo number_format($index + 1); ?></td>
                                    <td><strong><?php echo h($row['request_no'] ?? '-'); ?></strong></td>
                                    <td><?php echo h(formatMainBranchCode($row['main_branch_code'] ?? '')); ?></td>
                                    <td><strong class="text-primary"><?php echo h($row['branch_code'] ?? '-'); ?></strong></td>
                                    <td><div class="branch-name-cell" title="<?php echo h($row['branch_name'] ?? '-'); ?>"><?php echo h($row['branch_name'] ?? '-'); ?></div></td>
                                    <td>
                                        <?php if (!empty($row['hdd_serial'])): ?>
                                            <span class="serial-text"><?php echo h($row['hdd_serial']); ?></span>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo requestStatusBadge($row['status'] ?? ''); ?></td>
                                    <td><div class="reason-cell" title="<?php echo h($row['request_reason'] ?? '-'); ?>"><?php echo h($row['request_reason'] ?? '-'); ?></div></td>
                                    <td><div class="user-cell" title="<?php echo h($createdBy); ?>"><?php echo h($createdBy); ?></div></td>
                                    <td><?php echo h(formatDateTimeThai($row['created_at'] ?? '')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>