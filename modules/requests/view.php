<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';

$pageTitle = 'รายละเอียดคำขอส่ง HDD';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($id <= 0) {
    header('Location: index.php');
    exit;
}

/*
|--------------------------------------------------------------------------
| Helper functions
|--------------------------------------------------------------------------
*/
function requestStatusBadge($status)
{
    switch ($status) {
        case 'pending_scan':
            return '<span class="badge bg-warning text-dark">รอยิงบาร์โค้ด</span>';
        case 'matched':
            return '<span class="badge bg-info text-dark">รอยืนยันจัดส่ง</span>';
        case 'shipped':
            return '<span class="badge bg-primary">จัดส่งแล้ว</span>';
        case 'received':
            return '<span class="badge bg-success">สาขาได้รับแล้ว</span>';
        case 'cancelled':
            return '<span class="badge bg-danger">ยกเลิก</span>';
        default:
            return '<span class="badge bg-secondary">ไม่ทราบสถานะ</span>';
    }
}

function formatDateTimeThai($value)
{
    if (empty($value)) {
        return '-';
    }

    $timestamp = strtotime($value);

    if (!$timestamp) {
        return '-';
    }

    return date('d/m/Y H:i', $timestamp);
}

function formatBranchCode($branchCode)
{
    $branchCode = trim((string)$branchCode);

    if ($branchCode === '') {
        return '-';
    }

    if (ctype_digit($branchCode)) {
        return str_pad($branchCode, 3, '0', STR_PAD_LEFT);
    }

    return $branchCode;
}

function timelineIconClass($type)
{
    switch ($type) {
        case 'success':
            return 'bg-success';
        case 'warning':
            return 'bg-warning';
        case 'info':
            return 'bg-info';
        case 'primary':
            return 'bg-primary';
        case 'danger':
            return 'bg-danger';
        default:
            return 'bg-secondary';
    }
}

/*
|--------------------------------------------------------------------------
| Fetch request detail
|--------------------------------------------------------------------------
*/
$sql = "
    SELECT
        id,
        request_no,
        main_branch_code,
        branch_code,
        branch_name,
        request_reason,
        status,
        requested_by,
        requested_at,
        matched_by,
        matched_at,
        created_at,
        updated_at
    FROM harddisk_delivery_requests
    WHERE id = :id
      AND deleted_at IS NULL
    LIMIT 1
";

$stmt = $pdo->prepare($sql);
$stmt->execute([
    ':id' => $id
]);

$request = $stmt->fetch();

if (!$request) {
    header('Location: index.php?not_found=1');
    exit;
}

/*
|--------------------------------------------------------------------------
| Fetch HDD items
| หมายเหตุ: ตาราง harddisk_request_items ไม่มี created_by
|--------------------------------------------------------------------------
*/
$itemSql = "
    SELECT
        id,
        request_id,
        hdd_serial,
        created_at
    FROM harddisk_request_items
    WHERE request_id = :request_id
    ORDER BY id ASC
";

$itemStmt = $pdo->prepare($itemSql);
$itemStmt->execute([
    ':request_id' => $id
]);

$items = $itemStmt->fetchAll();

/*
|--------------------------------------------------------------------------
| Fetch shipment detail
|--------------------------------------------------------------------------
*/
$shipmentSql = "
    SELECT
        id,
        request_id,
        delivery_request_no,
        branch_code,
        branch_name,
        hdd_serial,
        status,
        created_by,
        created_at,
        shipped_at,
        updated_at
    FROM harddisk_shipments
    WHERE deleted_at IS NULL
      AND (
            request_id = :request_id
            OR delivery_request_no = :delivery_request_no
      )
    ORDER BY 
        CASE 
            WHEN shipped_at IS NOT NULL THEN shipped_at
            ELSE created_at
        END DESC
    LIMIT 1
";

$shipmentStmt = $pdo->prepare($shipmentSql);
$shipmentStmt->execute([
    ':request_id' => $id,
    ':delivery_request_no' => $request['request_no'] ?? ''
]);

$shipment = $shipmentStmt->fetch();

/*
|--------------------------------------------------------------------------
| Build Timeline
|--------------------------------------------------------------------------
*/
$timeline = [];

$timeline[] = [
    'title' => 'บันทึกคำขอส่ง HDD',
    'description' => 'มีการบันทึกคำขอส่ง Harddisk เข้าระบบ',
    'datetime' => $request['requested_at'] ?? $request['created_at'] ?? null,
    'user' => $request['requested_by'] ?? '-',
    'type' => 'success'
];

if (!empty($request['matched_at'] ?? null)) {
    $timeline[] = [
        'title' => 'จับคู่ Serial HDD แล้ว',
        'description' => 'มีการยิงบาร์โค้ดและจับคู่ Harddisk กับคำขอแล้ว',
        'datetime' => $request['matched_at'] ?? null,
        'user' => $request['matched_by'] ?? '-',
        'type' => 'info'
    ];
}

if (!empty($shipment['shipped_at'] ?? null) || (($request['status'] ?? '') === 'shipped')) {
    $timeline[] = [
        'title' => 'จัดส่ง Harddisk แล้ว',
        'description' => 'มีการยืนยันการจัดส่ง Harddisk แล้ว',
        'datetime' => $shipment['shipped_at'] ?? $shipment['created_at'] ?? null,
        'user' => $shipment['created_by'] ?? '-',
        'type' => 'primary'
    ];
}

if (($request['status'] ?? '') === 'received') {
    $timeline[] = [
        'title' => 'สาขาได้รับ Harddisk แล้ว',
        'description' => 'สถานะคำขอถูกระบุว่าสาขาได้รับ Harddisk แล้ว',
        'datetime' => $request['updated_at'] ?? null,
        'user' => '-',
        'type' => 'success'
    ];
}

if (($request['status'] ?? '') === 'cancelled') {
    $timeline[] = [
        'title' => 'ยกเลิกคำขอ',
        'description' => 'รายการคำขอนี้ถูกยกเลิกแล้ว',
        'datetime' => $request['updated_at'] ?? null,
        'user' => '-',
        'type' => 'danger'
    ];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid py-4">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h4 class="mb-1">รายละเอียดคำขอส่ง HDD</h4>
            <div class="text-muted">
                เลขที่คำขอ: <?php echo e($request['request_no'] ?? '-'); ?>
            </div>
        </div>

        <a href="index.php" class="btn btn-outline-secondary">
            กลับหน้ารายการ
        </a>
    </div>

    <div class="row g-3">

        <div class="col-lg-8">

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white">
                    ข้อมูลคำขอ
                </div>

                <div class="card-body">

                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">เลขที่คำขอ</div>
                        <div class="col-md-8 fw-semibold">
                            <?php echo e($request['request_no'] ?? '-'); ?>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">สถานะ</div>
                        <div class="col-md-8">
                            <?php echo requestStatusBadge($request['status'] ?? ''); ?>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">รหัสสาขา</div>
                        <div class="col-md-8 fw-semibold text-primary">
                            <?php echo e(formatBranchCode($request['branch_code'] ?? '')); ?>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">ชื่อสาขา</div>
                        <div class="col-md-8 fw-semibold">
                            <?php echo e($request['branch_name'] ?? '-'); ?>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">สาเหตุการส่ง HDD</div>
                        <div class="col-md-8">
                            <?php echo nl2br(e($request['request_reason'] ?? '-')); ?>
                        </div>
                    </div>

                    <div class="row mb-3">
                        <div class="col-md-4 text-muted">ผู้บันทึกคำขอ</div>
                        <div class="col-md-8">
                            <?php echo e($request['requested_by'] ?? '-'); ?>
                        </div>
                    </div>

                    <div class="row">
                        <div class="col-md-4 text-muted">วันที่บันทึกคำขอ</div>
                        <div class="col-md-8">
                            <?php echo e(formatDateTimeThai($request['requested_at'] ?? $request['created_at'] ?? null)); ?>
                        </div>
                    </div>

                </div>
            </div>

            <div class="card shadow-sm border-0 mb-3">
                <div class="card-header bg-white">
                    รายการ Harddisk ที่จับคู่
                </div>

                <div class="card-body p-0">

                    <div class="table-responsive">
                        <table class="table table-bordered table-hover align-middle mb-0">
                            <thead class="table-light">
                                <tr>
                                    <th width="60">#</th>
                                    <th>Serial HDD</th>
                                    <th>ผู้จับคู่</th>
                                    <th>วันที่จับคู่</th>
                                </tr>
                            </thead>

                            <tbody>
                                <?php if (count($items) === 0): ?>
                                    <tr>
                                        <td colspan="4" class="text-center text-muted py-4">
                                            ยังไม่มีการจับคู่ Serial HDD
                                        </td>
                                    </tr>
                                <?php endif; ?>

                                <?php foreach ($items as $index => $item): ?>
                                    <tr>
                                        <td><?php echo $index + 1; ?></td>

                                        <td>
                                            <code><?php echo e($item['hdd_serial'] ?? '-'); ?></code>
                                        </td>

                                        <td>
                                            <?php echo e($request['matched_by'] ?? '-'); ?>
                                        </td>

                                        <td>
                                            <?php echo e(formatDateTimeThai($item['created_at'] ?? $request['matched_at'] ?? null)); ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>

                        </table>
                    </div>

                </div>
            </div>

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    ข้อมูลการจัดส่ง
                </div>

                <div class="card-body">

                    <?php if (!$shipment): ?>

                        <div class="text-muted">
                            ยังไม่พบข้อมูลการจัดส่งของรายการนี้
                        </div>

                    <?php else: ?>

                        <div class="row mb-3">
                            <div class="col-md-4 text-muted">สถานะจัดส่ง</div>
                            <div class="col-md-8">
                                <?php echo requestStatusBadge($shipment['status'] ?? ''); ?>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4 text-muted">Serial HDD</div>
                            <div class="col-md-8">
                                <code><?php echo e($shipment['hdd_serial'] ?? '-'); ?></code>
                            </div>
                        </div>

                        <div class="row mb-3">
                            <div class="col-md-4 text-muted">ผู้บันทึกจัดส่ง</div>
                            <div class="col-md-8">
                                <?php echo e($shipment['created_by'] ?? '-'); ?>
                            </div>
                        </div>

                        <div class="row">
                            <div class="col-md-4 text-muted">วันที่จัดส่ง</div>
                            <div class="col-md-8">
                                <?php echo e(formatDateTimeThai($shipment['shipped_at'] ?? $shipment['created_at'] ?? null)); ?>
                            </div>
                        </div>

                    <?php endif; ?>

                </div>
            </div>

        </div>

        <div class="col-lg-4">

            <div class="card shadow-sm border-0">
                <div class="card-header bg-white">
                    Timeline
                </div>

                <div class="card-body">

                    <?php foreach ($timeline as $index => $item): ?>
                        <div class="d-flex mb-4">

                            <div class="me-3">
                                <div class="rounded-circle <?php echo timelineIconClass($item['type'] ?? ''); ?>"
                                     style="width: 14px; height: 14px; margin-top: 5px;">
                                </div>

                                <?php if ($index < count($timeline) - 1): ?>
                                    <div style="width: 2px; min-height: 55px; background: #dee2e6; margin-left: 6px; margin-top: 5px;"></div>
                                <?php endif; ?>
                            </div>

                            <div>
                                <div class="fw-semibold">
                                    <?php echo e($item['title'] ?? '-'); ?>
                                </div>

                                <div class="text-muted small mb-1">
                                    <?php echo e($item['description'] ?? '-'); ?>
                                </div>

                                <div class="small">
                                    วันที่: <?php echo e(formatDateTimeThai($item['datetime'] ?? null)); ?>
                                </div>

                                <div class="small">
                                    ผู้ดำเนินการ: <?php echo e($item['user'] ?? '-'); ?>
                                </div>
                            </div>

                        </div>
                    <?php endforeach; ?>

                </div>
            </div>

        </div>

    </div>

</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>