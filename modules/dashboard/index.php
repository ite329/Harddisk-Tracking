<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

if (function_exists('require_login')) {
    require_login();
}

$pageTitle = 'Dashboard ระบบจัดส่ง Harddisk';

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function dashTableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([':table_name' => $tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function dashGetTableColumns(PDO $pdo, string $tableName): array
{
    if (!dashTableExists($pdo, $tableName)) {
        return [];
    }

    $stmt = $pdo->prepare("\n        SELECT COLUMN_NAME\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([':table_name' => $tableName]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function dashHasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function dashCountRows(PDO $pdo, string $tableName, array $columns, array $where = [], array $params = []): int
{
    if (!dashTableExists($pdo, $tableName)) {
        return 0;
    }

    if (dashHasColumn($columns, 'deleted_at')) {
        $where[] = 'deleted_at IS NULL';
    }

    $whereSql = '';
    if (!empty($where)) {
        $whereSql = 'WHERE ' . implode(' AND ', $where);
    }

    $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$tableName} {$whereSql}");
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->execute();

    return (int)$stmt->fetchColumn();
}

function dashFormatMainBranchCode($value): string
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

function dashFormatDateTimeThai($value): string
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

function dashRequestStatusText($status): string
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
        'completed' => 'เสร็จสิ้น',
        'cancelled' => 'ยกเลิก',
        'rejected' => 'ไม่อนุมัติ',
    ];
    return $map[$status] ?? ($status !== '' ? $status : '-');
}

function dashRequestStatusBadge($status): string
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
    } elseif ($status === 'rejected') {
        $class = 'bg-danger';
    }
    return '<span class="badge ' . h($class) . '">' . h(dashRequestStatusText($status)) . '</span>';
}

function dashInventoryStatusText($status): string
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

function dashInventoryStatusBadge($status): string
{
    $status = trim((string)($status ?? ''));
    $class = 'bg-secondary';
    if ($status === 'available') {
        $class = 'bg-success';
    } elseif ($status === 'reserved') {
        $class = 'bg-warning text-dark';
    } elseif ($status === 'damaged') {
        $class = 'bg-danger';
    } elseif ($status === 'shipped') {
        $class = 'bg-primary';
    } elseif ($status === 'used') {
        $class = 'bg-info text-dark';
    }
    return '<span class="badge ' . h($class) . '">' . h(dashInventoryStatusText($status)) . '</span>';
}

$requestColumns = dashGetTableColumns($pdo, 'harddisk_delivery_requests');
$inventoryColumns = dashGetTableColumns($pdo, 'harddisk_inventory');
$shipmentColumns = dashGetTableColumns($pdo, 'harddisk_shipments');

$totalRequests = 0;
$pendingScan = 0;
$waitingConfirm = 0;
$shippedRequests = 0;
$completedRequests = 0;
$totalInventory = 0;
$availableInventory = 0;
$reservedInventory = 0;
$damagedInventory = 0;
$totalShipments = 0;
$shipmentThisMonth = 0;
$requestStatusRows = [];
$inventoryStatusRows = [];
$urgentRequests = [];
$latestRequests = [];
$latestShipments = [];
$dashboardError = '';

try {
    if (dashTableExists($pdo, 'harddisk_delivery_requests')) {
        $totalRequests = dashCountRows($pdo, 'harddisk_delivery_requests', $requestColumns);

        if (dashHasColumn($requestColumns, 'status')) {
            $pendingScan = dashCountRows($pdo, 'harddisk_delivery_requests', $requestColumns, ["status IN ('pending_scan', 'pending')"]);
            $waitingConfirm = dashCountRows($pdo, 'harddisk_delivery_requests', $requestColumns, ["status IN ('matched', 'reserved')"]);
            $shippedRequests = dashCountRows($pdo, 'harddisk_delivery_requests', $requestColumns, ["status = 'shipped'"]);
            $completedRequests = dashCountRows($pdo, 'harddisk_delivery_requests', $requestColumns, ["status IN ('received', 'installed', 'completed')"]);

            $where = dashHasColumn($requestColumns, 'deleted_at') ? 'WHERE deleted_at IS NULL' : '';
            $stmt = $pdo->query("\n                SELECT status, COUNT(*) AS total\n                FROM harddisk_delivery_requests\n                {$where}\n                GROUP BY status\n                ORDER BY total DESC\n            ");
            $requestStatusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $selectColumns = [];
        foreach (['id', 'request_no', 'main_branch_code', 'branch_code', 'branch_name', 'hdd_serial', 'request_reason', 'status', 'created_at', 'requested_at', 'remark'] as $column) {
            if (dashHasColumn($requestColumns, $column)) {
                $selectColumns[] = $column;
            }
        }
        if (empty($selectColumns)) {
            $selectColumns[] = 'id';
        }

        $baseWhere = [];
        if (dashHasColumn($requestColumns, 'deleted_at')) {
            $baseWhere[] = 'deleted_at IS NULL';
        }

        if (dashHasColumn($requestColumns, 'status')) {
            $urgentWhere = $baseWhere;
            $urgentWhere[] = "status IN ('pending_scan', 'pending', 'matched', 'reserved')";
            $urgentWhereSql = !empty($urgentWhere) ? 'WHERE ' . implode(' AND ', $urgentWhere) : '';
            $orderColumn = dashHasColumn($requestColumns, 'created_at') ? 'created_at' : 'id';
            $stmt = $pdo->prepare("\n                SELECT " . implode(', ', $selectColumns) . "\n                FROM harddisk_delivery_requests\n                {$urgentWhereSql}\n                ORDER BY {$orderColumn} ASC, id ASC\n                LIMIT 8\n            ");
            $stmt->execute();
            $urgentRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $baseWhereSql = !empty($baseWhere) ? 'WHERE ' . implode(' AND ', $baseWhere) : '';
        $stmt = $pdo->prepare("\n            SELECT " . implode(', ', $selectColumns) . "\n            FROM harddisk_delivery_requests\n            {$baseWhereSql}\n            ORDER BY id DESC\n            LIMIT 8\n        ");
        $stmt->execute();
        $latestRequests = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    if (dashTableExists($pdo, 'harddisk_inventory')) {
        $totalInventory = dashCountRows($pdo, 'harddisk_inventory', $inventoryColumns);
        if (dashHasColumn($inventoryColumns, 'status')) {
            $availableInventory = dashCountRows($pdo, 'harddisk_inventory', $inventoryColumns, ["status = 'available'"]);
            $reservedInventory = dashCountRows($pdo, 'harddisk_inventory', $inventoryColumns, ["status = 'reserved'"]);
            $damagedInventory = dashCountRows($pdo, 'harddisk_inventory', $inventoryColumns, ["status = 'damaged'"]);
            $where = dashHasColumn($inventoryColumns, 'deleted_at') ? 'WHERE deleted_at IS NULL' : '';
            $stmt = $pdo->query("\n                SELECT status, COUNT(*) AS total\n                FROM harddisk_inventory\n                {$where}\n                GROUP BY status\n                ORDER BY total DESC\n            ");
            $inventoryStatusRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    }

    if (dashTableExists($pdo, 'harddisk_shipments')) {
        $totalShipments = dashCountRows($pdo, 'harddisk_shipments', $shipmentColumns);
        $shipmentDateColumn = null;
        foreach (['shipped_at', 'shipped_date', 'created_at'] as $column) {
            if (dashHasColumn($shipmentColumns, $column)) {
                $shipmentDateColumn = $column;
                break;
            }
        }
        if ($shipmentDateColumn !== null) {
            $where = [];
            if (dashHasColumn($shipmentColumns, 'deleted_at')) {
                $where[] = 'deleted_at IS NULL';
            }
            $where[] = "DATE_FORMAT({$shipmentDateColumn}, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
            $shipmentThisMonth = dashCountRows($pdo, 'harddisk_shipments', $shipmentColumns, $where);
        }

        $selectColumns = [];
        foreach (['id', 'delivery_request_no', 'request_no', 'main_branch_code', 'branch_code', 'branch_name', 'hdd_serial', 'status', 'shipment_status', 'shipped_at', 'shipped_date', 'created_at', 'reported_by', 'created_by'] as $column) {
            if (dashHasColumn($shipmentColumns, $column)) {
                $selectColumns[] = $column;
            }
        }
        if (empty($selectColumns)) {
            $selectColumns[] = 'id';
        }

        $where = [];
        if (dashHasColumn($shipmentColumns, 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }
        $whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';
        $orderParts = [];
        foreach (['shipped_at', 'shipped_date', 'created_at'] as $column) {
            if (dashHasColumn($shipmentColumns, $column)) {
                $orderParts[] = $column . ' DESC';
            }
        }
        $orderParts[] = 'id DESC';
        $stmt = $pdo->prepare("\n            SELECT " . implode(', ', $selectColumns) . "\n            FROM harddisk_shipments\n            {$whereSql}\n            ORDER BY " . implode(', ', $orderParts) . "\n            LIMIT 8\n        ");
        $stmt->execute();
        $latestShipments = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    $dashboardError = $e->getMessage();
}

require_once __DIR__ . '/../../includes/header.php';

require_login();
require_permission('dashboard.view');

?>


<style>
    body {
        background: #f5f8fc;
    }
    .hdd-dashboard {
        padding: 12px 0 18px 0;
    }
    .hdd-hero {
        border: 0;
        border-radius: 22px;
        overflow: hidden;
        background:
            radial-gradient(circle at top right, rgba(255,255,255,.26), transparent 34%),
            linear-gradient(135deg, #0284c7 0%, #2563eb 56%, #1d4ed8 100%);
        color: #ffffff;
        box-shadow: 0 16px 34px rgba(37, 99, 235, .24);
    }
    .hdd-hero .card-body {
        padding: 18px 20px;
    }
    .hdd-title {
        font-size: 24px;
        font-weight: 900;
        line-height: 1.18;
        margin: 0;
    }
    .hdd-subtitle {
        font-size: 13px;
        opacity: .86;
        margin-top: 4px;
    }
    .hero-stat {
        min-width: 124px;
        border-radius: 16px;
        background: rgba(255,255,255,.16);
        border: 1px solid rgba(255,255,255,.22);
        padding: 10px 12px;
        backdrop-filter: blur(4px);
    }
    .hero-stat .label {
        font-size: 11px;
        opacity: .82;
    }
    .hero-stat .value {
        font-size: 22px;
        font-weight: 900;
        line-height: 1.1;
    }
    .quick-action {
        border-radius: 14px;
        font-weight: 800;
        font-size: 12px;
        padding: 9px 12px;
        white-space: nowrap;
    }
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 10px;
        align-items: stretch;
    }
    .kpi-tile {
        border: 1px solid #e2e8f0;
        background: #ffffff;
        border-radius: 16px;
        box-shadow: 0 7px 18px rgba(15, 23, 42, .055);
        padding: 10px 12px;
        min-height: 92px;
        height: 100%;
        position: relative;
        overflow: hidden;
    }
    .kpi-tile::after {
        content: "";
        width: 62px;
        height: 62px;
        border-radius: 999px;
        position: absolute;
        right: -28px;
        top: -28px;
        background: #eff6ff;
    }
    .kpi-icon {
        width: 30px;
        height: 30px;
        border-radius: 10px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        font-size: 12px;
        font-weight: 900;
        color: #1d4ed8;
        background: #dbeafe;
        margin-bottom: 7px;
        position: relative;
        z-index: 1;
    }
    .kpi-label {
        color: #64748b;
        font-size: 11px;
        font-weight: 800;
        line-height: 1.2;
        position: relative;
        z-index: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .kpi-value {
        color: #0f172a;
        font-size: 24px;
        line-height: 1;
        font-weight: 900;
        margin-top: 4px;
        position: relative;
        z-index: 1;
    }
    .kpi-note {
        color: #94a3b8;
        font-size: 10.5px;
        margin-top: 6px;
        line-height: 1.2;
        position: relative;
        z-index: 1;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .dash-card {
        border: 1px solid #e2e8f0;
        border-radius: 20px;
        background: #ffffff;
        box-shadow: 0 10px 25px rgba(15, 23, 42, .055);
        overflow: hidden;
    }
    .dash-card .card-header {
        background: #ffffff;
        border-bottom: 1px solid #eef2f7;
        padding: 13px 16px;
        font-weight: 900;
        color: #0f172a;
    }
    .dash-card .card-body {
        padding: 14px 16px;
    }
    .section-title {
        display: flex;
        align-items: center;
        gap: 8px;
        font-weight: 900;
    }
    .section-dot {
        width: 9px;
        height: 9px;
        border-radius: 999px;
        background: #2563eb;
        box-shadow: 0 0 0 4px #dbeafe;
    }
    .table-dash {
        min-width: 900px;
    }
    .table-dash th {
        position: sticky;
        top: 0;
        z-index: 2;
        background: #f8fafc;
        color: #334155;
        font-size: 12px;
        white-space: nowrap;
        padding: 8px 9px;
    }
    .table-dash td {
        font-size: 12px;
        vertical-align: middle;
        padding: 8px 9px;
    }
    .table-dash .badge {
        font-size: 11px;
        border-radius: 999px;
        padding: 6px 8px;
    }
    .table-area-lg {
        max-height: 410px;
        overflow: auto;
    }
    .table-area-sm {
        max-height: 285px;
        overflow: auto;
    }
    .table-area-stack-main {
        max-height: 390px;
        overflow: auto;
    }
    .table-area-stack {
        max-height: 320px;
        overflow: auto;
    }
    .table-dash-wide {
        width: 100%;
        min-width: 0;
        table-layout: fixed;
    }
    .table-dash-urgent col.col-request-no { width: 18%; }
    .table-dash-urgent col.col-main-branch { width: 9%; }
    .table-dash-urgent col.col-branch-name { width: 24%; }
    .table-dash-urgent col.col-serial { width: 13%; }
    .table-dash-urgent col.col-status { width: 13%; }
    .table-dash-urgent col.col-date { width: 14%; }
    .table-dash-urgent col.col-action { width: 9%; }

    .table-dash-requests col.col-request-no { width: 18%; }
    .table-dash-requests col.col-main-branch { width: 9%; }
    .table-dash-requests col.col-branch-name { width: 24%; }
    .table-dash-requests col.col-serial { width: 13%; }
    .table-dash-requests col.col-status { width: 13%; }
    .table-dash-requests col.col-date { width: 14%; }
    .table-dash-requests col.col-reason { width: 9%; }

    .table-dash-shipments col.col-ship-date { width: 14%; }
    .table-dash-shipments col.col-request-no { width: 16%; }
    .table-dash-shipments col.col-main-branch { width: 9%; }
    .table-dash-shipments col.col-branch-name { width: 24%; }
    .table-dash-shipments col.col-serial { width: 13%; }
    .table-dash-shipments col.col-status { width: 11%; }
    .table-dash-shipments col.col-user { width: 13%; }
    .branch-code {
        color: #1d4ed8;
        font-weight: 900;
        white-space: nowrap;
    }
    .serial-text {
        color: #7c2d12;
        font-family: Consolas, Monaco, monospace;
        font-weight: 900;
        white-space: nowrap;
    }
    .text-ellipsis {
        max-width: 100%;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .status-list {
        display: grid;
        gap: 10px;
    }
    .status-item {
        border: 1px solid #eef2f7;
        border-radius: 15px;
        padding: 10px 12px;
        background: #fbfdff;
    }
    .status-line {
        display: flex;
        align-items: center;
        justify-content: space-between;
        gap: 10px;
        margin-bottom: 8px;
    }
    .status-count {
        color: #0f172a;
        font-weight: 900;
        font-size: 15px;
    }
    .mini-bar {
        height: 8px;
        background: #e5e7eb;
        border-radius: 999px;
        overflow: hidden;
    }
    .mini-bar-fill {
        height: 100%;
        background: linear-gradient(90deg, #0ea5e9, #2563eb);
        border-radius: 999px;
    }
    .mini-metric {
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-radius: 14px;
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        padding: 10px 12px;
        margin-bottom: 9px;
    }
    .mini-metric .label {
        font-size: 12px;
        color: #64748b;
        font-weight: 800;
    }
    .mini-metric .value {
        font-size: 18px;
        font-weight: 900;
        color: #0f172a;
    }
    @media (max-width: 1366px) {
        .hdd-dashboard { padding-top: 8px; }
        .hdd-title { font-size: 22px; }
        .hdd-hero .card-body { padding: 15px 16px; }
        .kpi-grid { gap: 8px; }
        .kpi-tile { min-height: 86px; padding: 9px 10px; border-radius: 14px; }
        .kpi-icon { width: 28px; height: 28px; margin-bottom: 6px; }
        .kpi-label { font-size: 10.5px; }
        .kpi-value { font-size: 22px; }
        .kpi-note { font-size: 10px; }
        .table-area-lg { max-height: 365px; }
        .table-area-sm { max-height: 245px; }
        .table-dash th, .table-dash td { font-size: 11.5px; padding: 7px 8px; }
    }
    @media (max-width: 1100px) {
        .kpi-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
    }
    @media (max-width: 768px) {
        .kpi-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        .hero-stat { min-width: 100px; }
    }
    @media (max-width: 480px) {
        .kpi-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="container-fluid hdd-dashboard">
    <div class="card hdd-hero mb-3">
        <div class="card-body">
            <div class="row g-3 align-items-center">
                <div class="col-xl-5 col-lg-5">
                    <h3 class="hdd-title">Dashboard ระบบจัดส่ง Harddisk</h3>
                    <div class="hdd-subtitle">ศูนย์รวมคำขอส่ง HDD, การยิงบาร์โค้ด, คลังอุปกรณ์ และรายการรอดำเนินการ</div>
                </div>
                <div class="col-xl-4 col-lg-4">
                    <div class="d-flex flex-wrap gap-2 justify-content-lg-center">
                        <div class="hero-stat"><div class="label">คำขอทั้งหมด</div><div class="value"><?php echo number_format($totalRequests); ?></div></div>
                        <div class="hero-stat"><div class="label">Stock พร้อมใช้</div><div class="value"><?php echo number_format($availableInventory); ?></div></div>
                        <div class="hero-stat"><div class="label">จัดส่งเดือนนี้</div><div class="value"><?php echo number_format($shipmentThisMonth); ?></div></div>
                    </div>
                </div>
                <div class="col-xl-3 col-lg-3">
                    <div class="d-flex flex-wrap gap-2 justify-content-lg-end">
                        <a href="../requests/create.php" class="btn btn-light quick-action">+ บันทึกคำขอ</a>
                        <a href="../requests/assign_hdd.php" class="btn btn-warning quick-action">ยิงบาร์โค้ด</a>
                        <a href="../claim_returns/index.php" class="btn btn-outline-light quick-action">รับคืนเคลม</a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <?php if ($dashboardError !== ''): ?>
        <div class="alert alert-danger py-2 mb-3"><strong>เกิดข้อผิดพลาด:</strong> <?php echo h($dashboardError); ?></div>
    <?php endif; ?>

    <div class="kpi-grid mb-3">
        <div class="kpi-tile"><div class="kpi-icon">BC</div><div class="kpi-label">รอยิงบาร์โค้ด</div><div class="kpi-value"><?php echo number_format($pendingScan); ?></div><div class="kpi-note">รอจับคู่ HDD กับสาขา</div></div>
        <div class="kpi-tile"><div class="kpi-icon">CF</div><div class="kpi-label">รอยืนยันจัดส่ง</div><div class="kpi-value"><?php echo number_format($waitingConfirm); ?></div><div class="kpi-note">จับคู่แล้ว รอจัดส่ง</div></div>
        <div class="kpi-tile"><div class="kpi-icon">AV</div><div class="kpi-label">HDD พร้อมใช้งาน</div><div class="kpi-value"><?php echo number_format($availableInventory); ?></div><div class="kpi-note">จากทั้งหมด <?php echo number_format($totalInventory); ?> ลูก</div></div>
        <div class="kpi-tile"><div class="kpi-icon">RS</div><div class="kpi-label">HDD จองไว้</div><div class="kpi-value"><?php echo number_format($reservedInventory); ?></div><div class="kpi-note">รอส่งออกจากคลัง</div></div>
        <div class="kpi-tile"><div class="kpi-icon">DM</div><div class="kpi-label">HDD ชำรุด</div><div class="kpi-value"><?php echo number_format($damagedInventory); ?></div><div class="kpi-note">รอรับคืน/ส่งเคลม</div></div>
        <div class="kpi-tile"><div class="kpi-icon">SH</div><div class="kpi-label">จัดส่งเดือนนี้</div><div class="kpi-value"><?php echo number_format($shipmentThisMonth); ?></div><div class="kpi-note">สะสม <?php echo number_format($totalShipments); ?> รายการ</div></div>
    </div>

    <div class="card dash-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="section-title"><span class="section-dot"></span> งานที่ต้องดำเนินการก่อน</div>
            <a href="../requests/index.php" class="btn btn-sm btn-outline-primary">ดูรายการทั้งหมด</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive table-area-stack-main">
                <table class="table table-hover table-bordered align-middle mb-0 table-dash table-dash-wide table-dash-urgent">
                    <colgroup>
                        <col class="col-request-no">
                        <col class="col-main-branch">
                        <col class="col-branch-name">
                        <col class="col-serial">
                        <col class="col-status">
                        <col class="col-date">
                        <col class="col-action">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>เลขที่คำขอ</th>
                            <th>รหัสสาขา</th>
                            <th>ชื่อสาขา</th>
                            <th>Serial HDD</th>
                            <th>สถานะ</th>
                            <th>วันที่บันทึก</th>
                            <th>จัดการ</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($urgentRequests)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">ไม่มีรายการเร่งด่วน</td></tr>
                        <?php else: ?>
                            <?php foreach ($urgentRequests as $row): ?>
                                <?php $createdDate = $row['created_at'] ?? ($row['requested_at'] ?? ''); $status = $row['status'] ?? ''; ?>
                                <tr>
                                    <td><strong><?php echo h($row['request_no'] ?? '-'); ?></strong></td>
                                    <td><?php echo h(dashFormatMainBranchCode($row['main_branch_code'] ?? '')); ?></td>
                                    <td><div class="text-ellipsis" title="<?php echo h($row['branch_name'] ?? '-'); ?>"><?php echo h($row['branch_name'] ?? '-'); ?></div></td>
                                    <td><?php echo !empty($row['hdd_serial']) ? '<span class="serial-text">' . h($row['hdd_serial']) . '</span>' : '<span class="text-muted">ยังไม่จับคู่</span>'; ?></td>
                                    <td><?php echo dashRequestStatusBadge($status); ?></td>
                                    <td><?php echo h(dashFormatDateTimeThai($createdDate)); ?></td>
                                    <td>
                                        <?php if ($status === 'pending_scan' || $status === 'pending'): ?>
                                            <a href="../requests/assign_hdd.php?request_id=<?php echo (int)($row['id'] ?? 0); ?>" class="btn btn-sm btn-warning">ยิง HDD</a>
                                        <?php else: ?>
                                            <a href="../requests/print_label.php?request_id=<?php echo (int)($row['id'] ?? 0); ?>" target="_blank" class="btn btn-sm btn-outline-dark">ปริ้น</a>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card dash-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="section-title"><span class="section-dot"></span> คำขอล่าสุด</div>
            <a href="../requests/index.php" class="btn btn-sm btn-outline-primary">เปิดรายการคำขอ</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive table-area-stack">
                <table class="table table-hover table-bordered align-middle mb-0 table-dash table-dash-wide table-dash-requests">
                    <colgroup>
                        <col class="col-request-no">
                        <col class="col-main-branch">
                        <col class="col-branch-name">
                        <col class="col-serial">
                        <col class="col-status">
                        <col class="col-date">
                        <col class="col-reason">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>เลขที่คำขอ</th>
                            <th>รหัสสาขา</th>
                            <th>ชื่อสาขา</th>
                            <th>Serial HDD</th>
                            <th>สถานะ</th>
                            <th>วันที่บันทึก</th>
                            <th>เหตุผล</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($latestRequests)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">ไม่พบรายการคำขอ</td></tr>
                        <?php else: ?>
                            <?php foreach ($latestRequests as $row): ?>
                                <?php $createdDate = $row['created_at'] ?? ($row['requested_at'] ?? ''); ?>
                                <tr>
                                    <td><strong><?php echo h($row['request_no'] ?? '-'); ?></strong></td>
                                    <td><?php echo h(dashFormatMainBranchCode($row['main_branch_code'] ?? '')); ?></td>
                                    <td><div class="text-ellipsis" title="<?php echo h($row['branch_name'] ?? '-'); ?>"><?php echo h($row['branch_name'] ?? '-'); ?></div></td>
                                    <td><?php echo !empty($row['hdd_serial']) ? '<span class="serial-text">' . h($row['hdd_serial']) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                                    <td><?php echo dashRequestStatusBadge($row['status'] ?? ''); ?></td>
                                    <td><?php echo h(dashFormatDateTimeThai($createdDate)); ?></td>
                                    <td><div class="text-ellipsis" title="<?php echo h($row['request_reason'] ?? ($row['remark'] ?? '-')); ?>"><?php echo h($row['request_reason'] ?? ($row['remark'] ?? '-')); ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="card dash-card mb-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <div class="section-title"><span class="section-dot"></span> จัดส่งล่าสุด</div>
            <a href="../shipments/index.php" class="btn btn-sm btn-outline-primary">เปิดประวัติการจัดส่ง</a>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive table-area-stack">
                <table class="table table-hover table-bordered align-middle mb-0 table-dash table-dash-wide table-dash-shipments">
                    <colgroup>
                        <col class="col-ship-date">
                        <col class="col-request-no">
                        <col class="col-main-branch">
                        <col class="col-branch-name">
                        <col class="col-serial">
                        <col class="col-status">
                        <col class="col-user">
                    </colgroup>
                    <thead>
                        <tr>
                            <th>วันที่ส่ง</th>
                            <th>เลขที่คำขอ</th>
                            <th>รหัสสาขา</th>
                            <th>ชื่อสาขา</th>
                            <th>Serial HDD</th>
                            <th>สถานะ</th>
                            <th>ผู้แจ้ง/ผู้บันทึก</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($latestShipments)): ?>
                            <tr><td colspan="7" class="text-center text-muted py-4">ยังไม่มีประวัติการจัดส่ง</td></tr>
                        <?php else: ?>
                            <?php foreach ($latestShipments as $row): ?>
                                <?php
                                $shipDate = $row['shipped_at'] ?? ($row['shipped_date'] ?? ($row['created_at'] ?? ''));
                                $shipRequestNo = $row['request_no'] ?? ($row['delivery_request_no'] ?? '-');
                                $shipStatus = $row['shipment_status'] ?? ($row['status'] ?? 'shipped');
                                $shipUser = $row['reported_by'] ?? ($row['created_by'] ?? '-');
                                ?>
                                <tr>
                                    <td><?php echo h(dashFormatDateTimeThai($shipDate)); ?></td>
                                    <td><strong><?php echo h($shipRequestNo); ?></strong></td>
                                    <td><?php echo h(dashFormatMainBranchCode($row['main_branch_code'] ?? '')); ?></td>
                                    <td><div class="text-ellipsis" title="<?php echo h($row['branch_name'] ?? '-'); ?>"><?php echo h($row['branch_name'] ?? '-'); ?></div></td>
                                    <td><?php echo !empty($row['hdd_serial']) ? '<span class="serial-text">' . h($row['hdd_serial']) . '</span>' : '<span class="text-muted">-</span>'; ?></td>
                                    <td><?php echo dashRequestStatusBadge($shipStatus); ?></td>
                                    <td><div class="text-ellipsis" title="<?php echo h($shipUser); ?>"><?php echo h($shipUser); ?></div></td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8 col-lg-8">
            <div class="card dash-card h-100">
                <div class="card-header"><div class="section-title"><span class="section-dot"></span> สรุปสถานะคำขอ</div></div>
                <div class="card-body">
                    <?php if (empty($requestStatusRows)): ?>
                        <div class="text-muted small">ไม่พบข้อมูลสถานะคำขอ</div>
                    <?php else: ?>
                        <div class="status-list">
                            <?php foreach ($requestStatusRows as $row): ?>
                                <?php $count = (int)($row['total'] ?? 0); $percent = $totalRequests > 0 ? min(100, round(($count / $totalRequests) * 100)) : 0; ?>
                                <div class="status-item">
                                    <div class="status-line"><div><?php echo dashRequestStatusBadge($row['status'] ?? ''); ?></div><div class="status-count"><?php echo number_format($count); ?></div></div>
                                    <div class="mini-bar"><div class="mini-bar-fill" style="width: <?php echo (int)$percent; ?>%;"></div></div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="col-xl-4 col-lg-4">
            <div class="card dash-card h-100">
                <div class="card-header"><div class="section-title"><span class="section-dot"></span> สรุปคลังแบบเร็ว</div></div>
                <div class="card-body">
                    <div class="mini-metric"><span class="label">พร้อมใช้งาน</span><span class="value text-success"><?php echo number_format($availableInventory); ?></span></div>
                    <div class="mini-metric"><span class="label">จองไว้</span><span class="value text-warning"><?php echo number_format($reservedInventory); ?></span></div>
                    <div class="mini-metric"><span class="label">ชำรุด</span><span class="value text-danger"><?php echo number_format($damagedInventory); ?></span></div>
                    <a href="../inventory/index.php" class="btn btn-outline-primary w-100 quick-action">เปิดคลัง Harddisk</a>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
