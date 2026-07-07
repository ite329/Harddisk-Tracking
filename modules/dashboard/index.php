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
?>

<style>
    body { background: #f3f6fb; }
    .dashboard-page { padding: 10px 0 16px 0; }
    .dashboard-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .dashboard-subtitle { font-size: 13px; color: #64748b; }
    .dashboard-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .dashboard-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; }
    .dashboard-card .card-body { padding: 12px; }
    .hero-card { border: 0; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #ffffff; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22); }
    .hero-card .card-body { padding: 12px 16px; }
    .kpi-card { border: 0; border-radius: 15px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07); height: 100%; }
    .kpi-card .card-body { padding: 12px 14px; }
    .kpi-label { color: #64748b; font-size: 12px; margin-bottom: 4px; }
    .kpi-value { font-size: 28px; font-weight: 900; color: #0f172a; line-height: 1; }
    .kpi-note { color: #94a3b8; font-size: 11px; margin-top: 5px; }
    .workflow-pill { display: inline-flex; align-items: center; gap: 6px; padding: 6px 10px; border-radius: 999px; background: rgba(255, 255, 255, 0.16); color: #ffffff; font-size: 12px; font-weight: 800; white-space: nowrap; }
    .quick-btn { border-radius: 10px; font-size: 12px; font-weight: 800; padding: 6px 10px; }
    .table-scroll { max-height: 330px; overflow: auto; }
    .table-scroll-small { max-height: 245px; overflow: auto; }
    .table-dashboard th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 12px; white-space: nowrap; padding: 7px 8px; color: #334155; }
    .table-dashboard td { font-size: 12px; vertical-align: middle; padding: 7px 8px; }
    .table-dashboard .badge { font-size: 11px; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-weight: 900; color: #7c2d12; white-space: nowrap; }
    .branch-code { font-weight: 900; color: #1d4ed8; white-space: nowrap; }
    .text-ellipsis { max-width: 210px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .status-row { display: flex; justify-content: space-between; gap: 8px; padding: 7px 0; border-bottom: 1px solid #f1f5f9; font-size: 12px; }
    .status-row:last-child { border-bottom: 0; }
    .status-count { font-weight: 900; color: #0f172a; font-size: 13px; }
    .mini-bar { height: 6px; background: #e5e7eb; border-radius: 999px; overflow: hidden; margin-top: 5px; }
    .mini-bar-fill { height: 100%; background: #2563eb; border-radius: 999px; }
    @media (max-width: 1366px) {
        .dashboard-page { padding-top: 8px; }
        .dashboard-title { font-size: 20px; }
        .dashboard-card .card-body { padding: 10px; }
        .kpi-card .card-body { padding: 10px 12px; }
        .kpi-value { font-size: 25px; }
        .table-scroll { max-height: 300px; }
        .table-scroll-small { max-height: 215px; }
        .table-dashboard th, .table-dashboard td { font-size: 11.5px; padding: 6px 7px; }
    }
</style>

<div class="container-fluid dashboard-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="dashboard-title">Dashboard ระบบจัดส่ง Harddisk</h3>
            <div class="dashboard-subtitle">ภาพรวมคำขอส่ง HDD, คลัง HDD, รายการรอดำเนินการ และประวัติจัดส่งล่าสุด</div>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a href="../requests/create.php" class="btn btn-primary quick-btn">+ บันทึกคำขอ</a>
            <a href="../requests/assign_hdd.php" class="btn btn-warning quick-btn">ยิงบาร์โค้ด</a>
            <a href="../claim_returns/index.php" class="btn btn-outline-primary quick-btn">รับคืนเคลม</a>
        </div>
    </div>

    <?php if ($dashboardError !== ''): ?>
        <div class="alert alert-danger py-2 mb-2"><strong>เกิดข้อผิดพลาด:</strong> <?php echo h($dashboardError); ?></div>
    <?php endif; ?>

    <div class="card hero-card mb-2">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold"></div>
                <div class="small opacity-75">จัดการงานหลักของระบบ HDD ได้จาก Dashboard พร้อมสรุปสถานะสำคัญแบบทันที</div>
            </div>
            <div class="d-flex flex-wrap gap-2">
                <span class="workflow-pill">📝 คำขอ <?php echo number_format($totalRequests); ?></span>
                <span class="workflow-pill">💽 Stock <?php echo number_format($availableInventory); ?></span>
                <span class="workflow-pill">🚚 เดือนนี้ <?php echo number_format($shipmentThisMonth); ?></span>
            </div>
        </div>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">รอยิงบาร์โค้ด</div><div class="kpi-value"><?php echo number_format($pendingScan); ?></div><div class="kpi-note">รอจับคู่ HDD กับสาขา</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">รอยืนยันจัดส่ง</div><div class="kpi-value"><?php echo number_format($waitingConfirm); ?></div><div class="kpi-note">จับคู่แล้ว รอจัดส่ง</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">HDD พร้อมใช้งาน</div><div class="kpi-value"><?php echo number_format($availableInventory); ?></div><div class="kpi-note">ทั้งหมด <?php echo number_format($totalInventory); ?> ลูก</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">จัดส่งเดือนนี้</div><div class="kpi-value"><?php echo number_format($shipmentThisMonth); ?></div><div class="kpi-note">สะสม <?php echo number_format($totalShipments); ?> รายการ</div></div></div></div>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-xl-8 col-lg-8">
            <div class="card dashboard-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center"><div>🚦 รายการเร่งด่วน / รอดำเนินการ</div><a href="../requests/index.php" class="btn btn-sm btn-outline-primary">ดูทั้งหมด</a></div>
                <div class="card-body p-0">
                    <div class="table-responsive table-scroll">
                        <table class="table table-hover table-bordered align-middle mb-0 table-dashboard">
                            <thead><tr><th>เลขที่คำขอ</th><th>รหัสสาขา</th><th>Cost Center</th><th>ชื่อสาขา</th><th>Serial HDD</th><th>สถานะ</th><th>วันที่บันทึก</th><th>จัดการ</th></tr></thead>
                            <tbody>
                                <?php if (empty($urgentRequests)): ?>
                                    <tr><td colspan="8" class="text-center text-muted py-4">ไม่มีรายการเร่งด่วน</td></tr>
                                <?php else: ?>
                                    <?php foreach ($urgentRequests as $row): ?>
                                        <?php $createdDate = $row['created_at'] ?? ($row['requested_at'] ?? ''); $status = $row['status'] ?? ''; ?>
                                        <tr>
                                            <td><strong><?php echo h($row['request_no'] ?? '-'); ?></strong></td>
                                            <td><?php echo h(dashFormatMainBranchCode($row['main_branch_code'] ?? '')); ?></td>
                                            <td><span class="branch-code"><?php echo h($row['branch_code'] ?? '-'); ?></span></td>
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
        </div>

        <div class="col-xl-4 col-lg-4">
            <div class="card dashboard-card h-100">
                <div class="card-header">🧾 สรุปสถานะคำขอ</div>
                <div class="card-body">
                    <?php if (empty($requestStatusRows)): ?>
                        <div class="text-muted small">ไม่พบข้อมูลสถานะคำขอ</div>
                    <?php else: ?>
                        <?php foreach ($requestStatusRows as $row): ?>
                            <?php $count = (int)($row['total'] ?? 0); $percent = $totalRequests > 0 ? min(100, round(($count / $totalRequests) * 100)) : 0; ?>
                            <div class="status-row"><div class="flex-grow-1"><?php echo dashRequestStatusBadge($row['status'] ?? ''); ?><div class="mini-bar"><div class="mini-bar-fill" style="width: <?php echo (int)$percent; ?>%;"></div></div></div><div class="status-count"><?php echo number_format($count); ?></div></div>
                        <?php endforeach; ?>
                        <div class="small text-muted mt-2">คำขอทั้งหมด: <?php echo number_format($totalRequests); ?> รายการ</div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row g-2">
        <div class="col-xl-4 col-lg-4">
            <div class="card dashboard-card h-100">
                <div class="card-header">💽 สรุปคลัง Harddisk</div>
                <div class="card-body">
                    <?php if (empty($inventoryStatusRows)): ?>
                        <div class="text-muted small">ไม่พบข้อมูลคลัง HDD</div>
                    <?php else: ?>
                        <?php foreach ($inventoryStatusRows as $row): ?>
                            <?php $count = (int)($row['total'] ?? 0); $percent = $totalInventory > 0 ? min(100, round(($count / $totalInventory) * 100)) : 0; ?>
                            <div class="status-row"><div class="flex-grow-1"><?php echo dashInventoryStatusBadge($row['status'] ?? ''); ?><div class="mini-bar"><div class="mini-bar-fill" style="width: <?php echo (int)$percent; ?>%;"></div></div></div><div class="status-count"><?php echo number_format($count); ?></div></div>
                        <?php endforeach; ?>
                        <div class="small text-muted mt-2">จองไว้: <?php echo number_format($reservedInventory); ?> / ชำรุด: <?php echo number_format($damagedInventory); ?></div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-4">
            <div class="card dashboard-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center"><div>📝 รายการคำขอล่าสุด</div><a href="../requests/index.php" class="btn btn-sm btn-outline-primary">เปิด</a></div>
                <div class="card-body p-0"><div class="table-responsive table-scroll-small"><table class="table table-hover table-bordered align-middle mb-0 table-dashboard"><thead><tr><th>เลขที่คำขอ</th><th>Cost Center</th><th>สาขา</th><th>สถานะ</th></tr></thead><tbody>
                    <?php if (empty($latestRequests)): ?><tr><td colspan="4" class="text-center text-muted py-4">ไม่พบรายการคำขอ</td></tr><?php else: ?>
                        <?php foreach ($latestRequests as $row): ?><tr><td><strong><?php echo h($row['request_no'] ?? '-'); ?></strong></td><td><span class="branch-code"><?php echo h($row['branch_code'] ?? '-'); ?></span></td><td><div class="text-ellipsis" title="<?php echo h($row['branch_name'] ?? '-'); ?>"><?php echo h($row['branch_name'] ?? '-'); ?></div></td><td><?php echo dashRequestStatusBadge($row['status'] ?? ''); ?></td></tr><?php endforeach; ?>
                    <?php endif; ?>
                </tbody></table></div></div>
            </div>
        </div>

        <div class="col-xl-4 col-lg-4">
            <div class="card dashboard-card h-100">
                <div class="card-header d-flex justify-content-between align-items-center"><div>🚚 รายการจัดส่งล่าสุด</div><a href="../shipments/index.php" class="btn btn-sm btn-outline-primary">เปิด</a></div>
                <div class="card-body p-0"><div class="table-responsive table-scroll-small"><table class="table table-hover table-bordered align-middle mb-0 table-dashboard"><thead><tr><th>วันที่ส่ง</th><th>Cost Center</th><th>สาขา</th><th>Serial HDD</th></tr></thead><tbody>
                    <?php if (empty($latestShipments)): ?><tr><td colspan="4" class="text-center text-muted py-4">ยังไม่มีประวัติการจัดส่ง</td></tr><?php else: ?>
                        <?php foreach ($latestShipments as $row): ?><?php $shipDate = $row['shipped_at'] ?? ($row['shipped_date'] ?? ($row['created_at'] ?? '')); ?><tr><td><?php echo h(dashFormatDateTimeThai($shipDate)); ?></td><td><span class="branch-code"><?php echo h($row['branch_code'] ?? '-'); ?></span></td><td><div class="text-ellipsis" title="<?php echo h($row['branch_name'] ?? '-'); ?>"><?php echo h($row['branch_name'] ?? '-'); ?></div></td><td><?php echo !empty($row['hdd_serial']) ? '<span class="serial-text">' . h($row['hdd_serial']) . '</span>' : '<span class="text-muted">-</span>'; ?></td></tr><?php endforeach; ?>
                    <?php endif; ?>
                </tbody></table></div></div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
