<?php
declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();

$pageTitle = 'รายการรอยืนยันจัดส่ง';

$currentUserName = get_current_user_full_name($pdo);

if ($currentUserName === '') {
    die('ไม่พบชื่อผู้ Login กรุณา Logout แล้ว Login ใหม่อีกครั้ง');
}

if (!function_exists('e')) {
    function e($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function formatDateTimeForMatchedPage($value): string
{
    if (empty($value)) {
        return '-';
    }

    $timestamp = strtotime((string)$value);

    if (!$timestamp) {
        return '-';
    }

    return date('d/m/Y H:i', $timestamp);
}

function formatBranchCodeForMatchedPage($branchCode): string
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

function buildPageUrlForMatchedPage(array $override = []): string
{
    $query = $_GET;

    foreach ($override as $key => $value) {
        if ($value === null) {
            unset($query[$key]);
        } else {
            $query[$key] = $value;
        }
    }

    $queryString = http_build_query($query);

    return $queryString === '' ? 'matched.php' : 'matched.php?' . $queryString;
}

function bindMatchedParams(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
}

$keyword = trim((string)($_GET['keyword'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$perPage = (int)($_GET['per_page'] ?? 20);

$allowedPerPages = [10, 20, 50, 100];
if (!in_array($perPage, $allowedPerPages, true)) {
    $perPage = 20;
}

$page = (int)($_GET['page'] ?? 1);
$page = max($page, 1);
$offset = ($page - 1) * $perPage;

$where = [
    'r.deleted_at IS NULL',
    "r.status = 'matched'",

    /*
     * แสดงรายการตามผู้บันทึกคำขอส่ง HDD เข้ามา
     * ไม่อ้างอิงผู้ยิงบาร์โค้ด / ผู้จับคู่ HDD แล้ว
     */
    'TRIM(r.requested_by) = :current_user_name'
];

$params = [
    ':current_user_name' => $currentUserName
];

/*
|--------------------------------------------------------------------------
| เงื่อนไขค้นหาให้เหมือนหน้า ประวัติการจัดส่ง Harddisk
|--------------------------------------------------------------------------
| - เลขที่คำขอ
| - รหัสสาขา
| - ชื่อสาขา
| - Serial HDD
|
| Logic สำคัญ:
| 1) ถ้าค้นหาเป็นตัวเลขล้วนไม่เกิน 3 หลัก เช่น 240
|    ให้ถือว่าเป็นรหัสสาขาเท่านั้น ไม่เอาไปค้น Serial HDD
|
| 2) ถ้าค้นหาเป็นข้อความผสมตัวเลข เช่น เพชรเกษม 110 กรุงเทพฯ
|    ให้ค้นจากข้อความเต็ม ไม่แยกเลข 110 ไปเทียบรหัสสาขา
|
| 3) ถ้าค้นหา Serial เช่น WWD240
|    ให้ค้นจาก Serial HDD ได้ตามปกติ
*/
if ($keyword !== '') {
    $keyword = preg_replace('/\s+/u', ' ', $keyword);
    $isNumberOnly = preg_match('/^\d+$/', $keyword) === 1;

    if ($isNumberOnly && strlen($keyword) <= 3) {
        $paddedBranchCode = str_pad($keyword, 3, '0', STR_PAD_LEFT);

        $where[] = "(
            LPAD(TRIM(r.main_branch_code), 3, '0') = :keyword_branch_code
            OR LPAD(TRIM(r.branch_code), 3, '0') = :keyword_branch_code
            OR TRIM(r.branch_code) = :keyword_branch_code_raw
        )";

        $params[':keyword_branch_code'] = $paddedBranchCode;
        $params[':keyword_branch_code_raw'] = $keyword;
    } else {
        $where[] = "(
            r.request_no LIKE :keyword_like
            OR r.branch_name LIKE :keyword_like
            OR bd.branch_name LIKE :keyword_like
            OR i.hdd_serial LIKE :keyword_like
        )";

        $params[':keyword_like'] = '%' . $keyword . '%';
    }
}

/*
|--------------------------------------------------------------------------
| หน้า matched.php แสดงเฉพาะรอยืนยันจัดส่งอยู่แล้ว
|--------------------------------------------------------------------------
| ใส่ select สถานะไว้ให้หน้าตาเหมือนหน้าประวัติการจัดส่ง Harddisk
| แต่ข้อมูลในหน้านี้จะคงไว้เฉพาะ status = matched เท่านั้น
*/
if ($statusFilter !== '' && $statusFilter !== 'matched') {
    $where[] = '1 = 0';
}

$whereSql = 'WHERE ' . implode(' AND ', $where);

$fromSql = "
    FROM harddisk_delivery_requests r
    LEFT JOIN harddisk_request_items i
        ON i.request_id = r.id
        AND i.scan_status = 'matched'
    LEFT JOIN branch_directory bd
        ON bd.branch_code = r.branch_code
        AND bd.is_active = 1
";

$countSql = "
    SELECT COUNT(*)
    {$fromSql}
    {$whereSql}
";

$stmtCount = $pdo->prepare($countSql);
bindMatchedParams($stmtCount, $params);
$stmtCount->execute();
$totalRows = (int)$stmtCount->fetchColumn();
$totalPages = max((int)ceil($totalRows / $perPage), 1);

if ($page > $totalPages) {
    $page = $totalPages;
    $offset = ($page - 1) * $perPage;
}

$sql = "
    SELECT
        r.id,
        r.request_no,
        r.main_branch_code,
        r.branch_code,
        r.branch_name AS request_branch_name,
        bd.branch_name AS directory_branch_name,
        r.request_reason,
        r.status,
        r.requested_by,
        r.requested_at,
        r.matched_by,
        r.matched_at,
        i.hdd_serial
    {$fromSql}
    {$whereSql}
    ORDER BY r.matched_at DESC, r.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $pdo->prepare($sql);
bindMatchedParams($stmt, $params);
$stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();

$requests = $stmt->fetchAll(PDO::FETCH_ASSOC);

$summary = [
    'total' => $totalRows,
    'today' => 0,
    'with_serial' => 0,
    'this_page' => count($requests),
];

try {
    $stmtToday = $pdo->prepare("
        SELECT COUNT(*)
        {$fromSql}
        {$whereSql}
          AND DATE(r.matched_at) = CURDATE()
    ");
    bindMatchedParams($stmtToday, $params);
    $stmtToday->execute();
    $summary['today'] = (int)$stmtToday->fetchColumn();

    $stmtSerial = $pdo->prepare("
        SELECT COUNT(*)
        {$fromSql}
        {$whereSql}
          AND i.hdd_serial IS NOT NULL
          AND TRIM(i.hdd_serial) <> ''
    ");
    bindMatchedParams($stmtSerial, $params);
    $stmtSerial->execute();
    $summary['with_serial'] = (int)$stmtSerial->fetchColumn();
} catch (Throwable $e) {
    // Summary card เป็นข้อมูลประกอบ หากคำนวณไม่ได้ ไม่ให้กระทบการแสดงหน้าหลัก
}

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    body { background: #f3f6fb; }
    .matched-page { padding: 10px 0 16px 0; }
    .matched-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .matched-subtitle { font-size: 13px; color: #64748b; }
    .matched-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .matched-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; }
    .matched-card .card-body { padding: 12px; }
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
    .table-matched th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 12px; white-space: nowrap; padding: 7px 8px; color: #334155; }
    .table-matched td { font-size: 12px; vertical-align: middle; padding: 7px 8px; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-weight: 900; color: #7c2d12; white-space: nowrap; }
    .branch-code { font-weight: 900; color: #1d4ed8; white-space: nowrap; }
    .text-ellipsis { max-width: 270px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .owner-box { background: #eef6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 8px 10px; font-size: 12px; color: #1e3a8a; }
    .action-stack { min-width: 135px; }
    .status-pill { border-radius: 999px; padding: 4px 9px; font-size: 11px; font-weight: 800; background: #dbeafe; color: #1e3a8a; display: inline-flex; align-items: center; gap: 5px; }
    .help-box { background: #fff7ed; border: 1px solid #fed7aa; color: #9a3412; border-radius: 12px; padding: 8px 10px; font-size: 12px; }
    @media (max-width: 1366px) {
        .matched-page { padding-top: 8px; }
        .matched-title { font-size: 20px; }
        .matched-card .card-body { padding: 10px; }
        .kpi-card .card-body { padding: 10px 12px; }
        .kpi-value { font-size: 25px; }
        .table-scroll { max-height: 385px; }
        .table-matched th, .table-matched td { font-size: 11.5px; padding: 6px 7px; }
        .form-control, .form-select { font-size: 12px; }
        .text-ellipsis { max-width: 230px; }
    }
</style>

<div class="container-fluid matched-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="matched-title">รายการรอยืนยันจัดส่ง</h3>
            <div class="matched-subtitle">รายการที่จับคู่ HDD แล้ว รอเจ้าหน้าที่กดยืนยันจัดส่งและสร้างประวัติการจัดส่ง</div>
        </div>
        <div class="d-flex gap-2">
            <a href="assign_hdd.php" class="btn btn-outline-secondary btn-sm">ยิงบาร์โค้ด HDD</a>
            <a href="../shipments/index.php" class="btn btn-outline-primary btn-sm">ประวัติการจัดส่ง</a>
        </div>
    </div>

    <?php if (isset($_GET['success'])): ?>
        <div class="alert alert-success py-2 mb-2">ยืนยันจัดส่งเรียบร้อยแล้ว</div>
    <?php endif; ?>

    <?php if (isset($_GET['match_success'])): ?>
        <div class="alert alert-success py-2 mb-2">จับคู่ Serial HDD เรียบร้อยแล้ว กรุณายืนยันการจัดส่ง</div>
    <?php endif; ?>

    <?php if (isset($_GET['delete_success'])): ?>
        <div class="alert alert-success py-2 mb-2">ลบรายการเรียบร้อยแล้ว</div>
    <?php endif; ?>

    <?php if (isset($_GET['delete_error'])): ?>
        <div class="alert alert-danger py-2 mb-2">ไม่สามารถลบรายการได้ กรุณาลองใหม่อีกครั้ง</div>
    <?php endif; ?>

    <?php if (isset($_GET['error'])): ?>
        <div class="alert alert-danger py-2 mb-2">
            <?php
            $error = $_GET['error'];
            if ($error === 'not_found') {
                echo 'ไม่พบรายการที่ต้องการยืนยันจัดส่ง';
            } elseif ($error === 'already_shipped') {
                echo 'รายการนี้ถูกจัดส่งแล้ว';
            } else {
                echo 'เกิดข้อผิดพลาดในการดำเนินการ';
            }
            ?>
        </div>
    <?php endif; ?>

    <div class="card hero-card mb-2">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold"></div>
                <div class="small opacity-75">รายการในหน้านี้อ้างอิงจากผู้บันทึกคำขอส่ง HDD และจะแสดงเฉพาะสถานะ “รอยืนยันจัดส่ง”</div>
            </div>
            <div class="small">ผู้บันทึกคำขอ: <strong><?php echo e($currentUserName); ?></strong></div>
        </div>
    </div>

    <div class="row g-2 mb-2">
        <div class="col-lg-3 col-md-6">
            <div class="card kpi-card"><div class="card-body"><div class="kpi-label">รอยืนยันจัดส่งทั้งหมด</div><div class="kpi-value"><?php echo number_format($summary['total']); ?></div><div class="kpi-note">ตามเงื่อนไขค้นหาปัจจุบัน</div></div></div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card kpi-card"><div class="card-body"><div class="kpi-label">จับคู่วันนี้</div><div class="kpi-value"><?php echo number_format($summary['today']); ?></div><div class="kpi-note">รายการที่จับคู่ HDD วันนี้</div></div></div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card kpi-card"><div class="card-body"><div class="kpi-label">มี Serial HDD แล้ว</div><div class="kpi-value"><?php echo number_format($summary['with_serial']); ?></div><div class="kpi-note">พร้อมยืนยันจัดส่ง</div></div></div>
        </div>
        <div class="col-lg-3 col-md-6">
            <div class="card kpi-card"><div class="card-body"><div class="kpi-label">แสดงในหน้านี้</div><div class="kpi-value"><?php echo number_format($summary['this_page']); ?></div><div class="kpi-note">หน้า <?php echo number_format($page); ?> / <?php echo number_format($totalPages); ?></div></div></div>
        </div>
    </div>

    <div class="card matched-card mb-2">
        <div class="card-header">ค้นหาและตัวกรองรายการ</div>
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end" autocomplete="off">
                <div class="col-xl-5 col-lg-5 col-md-12">
                    <div class="step-box h-100">
                        <div class="step-title"><span class="step-badge">1</span> ค้นหา Keyword</div>
                        <label for="keyword" class="form-label">เลขที่คำขอ / รหัสสาขา / ชื่อสาขา / Serial HDD</label>
                        <input type="text"
                               name="keyword"
                               id="keyword"
                               class="form-control"
                               value="<?php echo e($keyword); ?>"
                               placeholder="เช่น REQ-0001, 240, เพชรเกษม 110, WWD240">
                    </div>
                </div>

                <div class="col-xl-2 col-lg-2 col-md-4">
                    <div class="step-box h-100">
                        <div class="step-title"><span class="step-badge">2</span> สถานะ</div>
                        <label for="status" class="form-label">สถานะ</label>
                        <select name="status" id="status" class="form-select">
                            <option value="" <?php echo $statusFilter === '' ? 'selected' : ''; ?>>ทั้งหมด</option>
                            <option value="matched" <?php echo $statusFilter === 'matched' ? 'selected' : ''; ?>>รอยืนยันจัดส่ง</option>
                        </select>
                    </div>
                </div>

                <div class="col-xl-2 col-lg-2 col-md-4">
                    <div class="step-box h-100">
                        <div class="step-title"><span class="step-badge">3</span> จำนวน</div>
                        <label for="per_page" class="form-label">รายการต่อหน้า</label>
                        <select name="per_page" id="per_page" class="form-select">
                            <?php foreach ($allowedPerPages as $option): ?>
                                <option value="<?php echo (int)$option; ?>" <?php echo $perPage === $option ? 'selected' : ''; ?>><?php echo number_format($option); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="col-xl-3 col-lg-3 col-md-4">
                    <div class="step-box h-100">
                        <div class="step-title"><span class="step-badge">4</span> ดำเนินการ</div>
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-primary">ค้นหา</button>
                            <a href="matched.php" class="btn btn-outline-secondary">ล้างค่า</a>
                        </div>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <div class="card matched-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                รายการรอยืนยันจัดส่งทั้งหมด <?php echo number_format($totalRows); ?> รายการ
                <span class="status-pill ms-1">รอยืนยันจัดส่ง</span>
            </div>
            <div class="text-muted small">หน้า <?php echo number_format($page); ?> / <?php echo number_format($totalPages); ?></div>
        </div>

        <div class="table-responsive table-scroll">
            <table class="table table-bordered table-hover align-middle mb-0 table-matched">
                <thead>
                    <tr>
                        <th width="55">#</th>
                        <th width="145">เลขที่คำขอ</th>
                        <th width="95">รหัสสาขา</th>
                        <th width="120">Cost Center</th>
                        <th>สาขา</th>
                        <th width="145">Serial HDD</th>
                        <th width="160">ผู้บันทึกคำขอ</th>
                        <th width="140">วันที่บันทึกคำขอ</th>
                        <th width="145">ดำเนินการ</th>
                    </tr>
                </thead>

                <tbody>
                    <?php if (count($requests) === 0): ?>
                        <tr>
                            <td colspan="9" class="text-center text-muted py-4">
                                ไม่พบรายการรอยืนยันจัดส่งของผู้บันทึกคำขอนี้
                            </td>
                        </tr>
                    <?php endif; ?>

                    <?php foreach ($requests as $index => $row): ?>
                        <?php
                        $branchName = $row['directory_branch_name'] ?? $row['request_branch_name'] ?? '-';
                        $mainBranchCode = formatBranchCodeForMatchedPage($row['main_branch_code'] ?? '');
                        $costCenter = trim((string)($row['branch_code'] ?? ''));
                        $serial = trim((string)($row['hdd_serial'] ?? ''));
                        ?>
                        <tr>
                            <td><?php echo number_format($offset + $index + 1); ?></td>

                            <td><strong><?php echo e($row['request_no'] ?? '-'); ?></strong></td>

                            <td><span class="branch-code"><?php echo e($mainBranchCode); ?></span></td>

                            <td><span class="branch-code"><?php echo e($costCenter !== '' ? $costCenter : '-'); ?></span></td>

                            <td>
                                <div class="fw-semibold text-ellipsis" title="<?php echo e($branchName); ?>">
                                    <?php echo e($branchName); ?>
                                </div>
                                <?php if (!empty($row['request_reason'])): ?>
                                    <div class="text-muted small text-ellipsis" title="<?php echo e($row['request_reason']); ?>">
                                        <?php echo e($row['request_reason']); ?>
                                    </div>
                                <?php endif; ?>
                            </td>

                            <td>
                                <?php if ($serial !== ''): ?>
                                    <span class="serial-text"><?php echo e($serial); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">-</span>
                                <?php endif; ?>
                            </td>

                            <td>
                                <div class="owner-box">
                                    <?php echo e($row['requested_by'] ?? '-'); ?>
                                </div>
                            </td>

                            <td><span class="text-nowrap"><?php echo e(formatDateTimeForMatchedPage($row['requested_at'] ?? null)); ?></span></td>

                            <td>
                                <div class="action-stack d-grid gap-1">
                                    <form method="post"
                                          action="../shipments/ship.php"
                                          onsubmit="return confirm('ยืนยันการจัดส่งรายการนี้หรือไม่?');">
                                        <?php echo csrf_field(); ?>

                                        <input type="hidden" name="request_id" value="<?php echo e($row['id']); ?>">

                                        <button type="submit" class="btn btn-primary btn-sm w-100">ยืนยันจัดส่ง</button>
                                    </form>

                                    <?php if (can_delete_records($pdo)): ?>
                                        <form method="post"
                                              action="delete_matched.php"
                                              onsubmit="return confirm('ยืนยันการลบรายการรอยืนยันจัดส่งนี้ออกจากฐานข้อมูลถาวรหรือไม่?');">
                                            <?php echo csrf_field(); ?>

                                            <input type="hidden" name="id" value="<?php echo e($row['id']); ?>">

                                            <button type="submit" class="btn btn-outline-danger btn-sm w-100">ลบรายการ</button>
                                        </form>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white">
                <nav aria-label="Matched pagination">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo e(buildPageUrlForMatchedPage(['page' => max(1, $page - 1)])); ?>">ก่อนหน้า</a>
                        </li>

                        <?php
                        $startPage = max(1, $page - 2);
                        $endPage = min($totalPages, $page + 2);
                        ?>

                        <?php if ($startPage > 1): ?>
                            <li class="page-item"><a class="page-link" href="<?php echo e(buildPageUrlForMatchedPage(['page' => 1])); ?>">1</a></li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="<?php echo e(buildPageUrlForMatchedPage(['page' => $i])); ?>"><?php echo number_format($i); ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <li class="page-item"><a class="page-link" href="<?php echo e(buildPageUrlForMatchedPage(['page' => $totalPages])); ?>"><?php echo number_format($totalPages); ?></a></li>
                        <?php endif; ?>

                        <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
                            <a class="page-link" href="<?php echo e(buildPageUrlForMatchedPage(['page' => min($totalPages, $page + 1)])); ?>">ถัดไป</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
