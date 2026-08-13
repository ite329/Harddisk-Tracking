<?php
$pageTitle = 'ประวัติการจัดส่ง Drum';
require_once __DIR__ . '/../../includes/header.php';

if (empty($_SESSION['csrf_drum_restore'])) {
    $_SESSION['csrf_drum_restore'] = bin2hex(random_bytes(32));
}

$isDrumSuperAdmin = false;
if (function_exists('is_super_admin_employee') && is_super_admin_employee()) {
    $isDrumSuperAdmin = true;
} elseif (function_exists('current_user_role') && current_user_role() === 'super_admin') {
    $isDrumSuperAdmin = true;
}

$restoreSuccess = $_SESSION['drum_restore_success'] ?? '';
unset($_SESSION['drum_restore_success']);
$restoreError = $_SESSION['drum_restore_error'] ?? '';
unset($_SESSION['drum_restore_error']);

$keyword = trim((string)($_GET['keyword'] ?? ''));
$drumFilter = trim((string)($_GET['drum_code'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$allowedDrumFilters = ['Drum-DR-3455', 'Drum-DR-3608'];
if (!in_array($drumFilter, $allowedDrumFilters, true)) $drumFilter = '';
$perPage = 20;
$page = max(1, (int)($_GET['page'] ?? 1));
$totalRows = 0;
$totalPages = 1;
$pageStart = 0;
$pageEnd = 0;
$historyRows = [];
$pageError = '';

try {
    $tableCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
    $tableCheck->execute();
    if ((int)$tableCheck->fetchColumn() === 0) throw new RuntimeException('ไม่พบตาราง drum_withdrawals');

    $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
    $columnsStmt->execute();
    $columns = array_map('strtolower', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));
    $hasBranchCode = in_array('branch_code', $columns, true);
    $hasDeletedAt = in_array('deleted_at', $columns, true);
    $hasDeliveryStatus = in_array('delivery_status', $columns, true);
    if (!$hasDeliveryStatus) throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ delivery_status กรุณารันไฟล์ database/add_drum_delivery_status.sql');

    $where = [];
    $params = [];
    if ($hasDeletedAt) $where[] = 'dw.deleted_at IS NULL';
    $where[] = "dw.delivery_status = 'shipped'";
    if ($keyword !== '') {
        $like = '%' . $keyword . '%';
        $parts = ['dw.request_no LIKE :kw1','dw.main_branch_code LIKE :kw2','dw.branch_name LIKE :kw3','dw.recorded_by LIKE :kw4','dw.drum_code LIKE :kw5'];
        $params += [':kw1'=>$like,':kw2'=>$like,':kw3'=>$like,':kw4'=>$like,':kw5'=>$like];
        if ($hasBranchCode) { $parts[]='dw.branch_code LIKE :kw6'; $params[':kw6']=$like; }
        $where[] = '(' . implode(' OR ', $parts) . ')';
    }
    if ($drumFilter !== '') { $where[]='dw.drum_code = :drum_code'; $params[':drum_code']=$drumFilter; }
    if ($dateFrom !== '') { $where[]='DATE(dw.created_at) >= :date_from'; $params[':date_from']=$dateFrom; }
    if ($dateTo !== '') { $where[]='DATE(dw.created_at) <= :date_to'; $params[':date_to']=$dateTo; }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';
    $branchSelect = $hasBranchCode ? 'dw.branch_code' : "''";
    $branchGroup = $hasBranchCode ? ', dw.branch_code' : '';
    $sql = "SELECT dw.request_no, dw.main_branch_code, {$branchSelect} AS branch_code, dw.branch_name,
                   GROUP_CONCAT(CONCAT(dw.drum_code, ' x', COALESCE(dw.quantity,1)) ORDER BY dw.drum_code SEPARATOR ', ') AS drum_codes,
                   dw.recorded_by, MIN(dw.created_at) AS created_at, MAX(dw.shipped_at) AS shipped_at, dw.delivery_status
            FROM harddisk_db.drum_withdrawals dw {$whereSql}
            GROUP BY dw.request_no, dw.main_branch_code{$branchGroup}, dw.branch_name, dw.recorded_by, dw.delivery_status
            ORDER BY MIN(dw.created_at) DESC LIMIT 1000";
    $stmt=$pdo->prepare($sql);
    $stmt->execute($params);
    $allHistoryRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $totalRows = count($allHistoryRows);
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;
    $historyRows = array_slice($allHistoryRows, $offset, $perPage);
    if ($totalRows > 0) {
        $pageStart = $offset + 1;
        $pageEnd = min($offset + count($historyRows), $totalRows);
    }
} catch (Throwable $e) {
    error_log('[drum_withdrawals/history] '.$e->getMessage());
    $pageError = $e instanceof RuntimeException ? $e->getMessage() : 'ไม่สามารถโหลดประวัติการจัดส่ง Drum ได้';
}

$exportParams = ['delivery_status' => 'shipped'];
if ($keyword !== '') $exportParams['keyword'] = $keyword;
if ($drumFilter !== '') $exportParams['drum_code'] = $drumFilter;
if ($dateFrom !== '') $exportParams['date_from'] = $dateFrom;
if ($dateTo !== '') $exportParams['date_to'] = $dateTo;
$exportExcelUrl = 'export_excel.php' . ($exportParams ? '?' . http_build_query($exportParams) : '');

$paginationParams = [];
if ($keyword !== '') $paginationParams['keyword'] = $keyword;
if ($drumFilter !== '') $paginationParams['drum_code'] = $drumFilter;
if ($dateFrom !== '') $paginationParams['date_from'] = $dateFrom;
if ($dateTo !== '') $paginationParams['date_to'] = $dateTo;
$buildHistoryPageUrl = static function (int $targetPage) use ($paginationParams): string {
    $params = $paginationParams;
    $params['page'] = max(1, $targetPage);
    return 'history.php?' . http_build_query($params);
};
?>
<style>
.drum-page{font-size:.82rem;padding:0 10px 24px}.drum-module-menu{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:0 0 14px}.drum-module-menu-item{position:relative;min-width:0;min-height:78px;display:flex;align-items:center;gap:10px;padding:11px 12px;border:1px solid #dbe5ee;border-radius:14px;background:#fff;color:#334155;text-decoration:none;box-shadow:0 5px 16px rgba(15,23,42,.055);transition:.16s;overflow:hidden}.drum-module-menu-item:hover,.drum-module-menu-item:focus,.drum-module-menu-item:active{color:#0f4c81;text-decoration:none!important;border-color:#93c5fd;box-shadow:0 9px 22px rgba(37,99,235,.12);transform:translateY(-1px)}.drum-module-menu-title,.drum-module-menu-note{text-decoration:none!important}.drum-module-menu-item:hover .drum-module-menu-title,.drum-module-menu-item:hover .drum-module-menu-note,.drum-module-menu-item:focus .drum-module-menu-title,.drum-module-menu-item:focus .drum-module-menu-note{text-decoration:none!important}.drum-module-menu-item.active{color:#fff;border-color:#00acc1;background:linear-gradient(135deg,#0097a7 0%,#00bcd4 58%,#26c6da 100%);box-shadow:0 10px 24px rgba(0,188,212,.28)}.drum-module-menu-icon{width:38px;height:38px;flex:0 0 38px;display:flex;align-items:center;justify-content:center;border-radius:11px;background:#e0f7fa;color:#00acc1;font-size:1.1rem}.drum-module-menu-icon svg{width:21px;height:21px;display:block;flex:0 0 auto}.drum-module-menu-item.active .drum-module-menu-icon{background:rgba(255,255,255,.18);color:#fff}.drum-module-menu-content{min-width:0}.drum-module-menu-title{display:block;font-size:.78rem;line-height:1.25;font-weight:900}.drum-module-menu-note{display:block;margin-top:3px;font-size:.65rem;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.drum-module-menu-item.active .drum-module-menu-note{color:rgba(255,255,255,.82)}
.drum-hero{background:linear-gradient(135deg,#0f4c81,#1976d2);color:#fff;border-radius:16px;padding:17px 20px;box-shadow:0 12px 28px rgba(15,76,129,.18)}.drum-filter-card,.drum-card{border:0;border-radius:15px;box-shadow:0 8px 24px rgba(15,23,42,.08);overflow:hidden}.drum-filter .form-control,.drum-filter .form-select,.drum-filter .btn{min-height:36px;font-size:.74rem;border-radius:10px}.drum-card .card-header{background:#fff;border-bottom:1px solid #e2e8f0;font-weight:900}.drum-table-wrap{max-height:none;overflow-x:auto;overflow-y:visible}.drum-table{width:100%;margin:0;table-layout:auto}.drum-table thead th{position:sticky;top:0;z-index:2;background:#f1f5f9;white-space:nowrap;font-size:.76rem;text-align:center;vertical-align:middle;padding:.55rem .5rem}.drum-table td{font-size:.78rem;vertical-align:middle;padding:.52rem .5rem}.drum-table th:not(:nth-child(5)),.drum-table td:not(:nth-child(5)){width:1%;white-space:nowrap}.drum-table th:nth-child(5),.drum-table td:nth-child(5){min-width:160px;white-space:normal;overflow-wrap:anywhere}.history-action{font-size:.68rem;padding:.25rem .45rem;white-space:nowrap}.history-action-group{display:flex;justify-content:center;align-items:center;gap:4px;white-space:nowrap}.drum-restore-modal{z-index:2147483000!important}.drum-restore-modal .modal-dialog{max-width:520px}body>.modal-backdrop{z-index:2147482990!important}.drum-status-badge{display:inline-flex;align-items:center;justify-content:center;min-width:86px;padding:.3rem .5rem;border-radius:999px;color:#fff;font-size:.66rem;font-weight:800;white-space:nowrap}.drum-status-shipped{background:#16a34a}
@media(max-width:1366px){.drum-page{padding-left:4px;padding-right:4px}.drum-module-menu{gap:7px}.drum-module-menu-item{min-height:70px;padding:9px 10px;gap:8px}.drum-module-menu-icon{width:32px;height:32px;flex-basis:32px;font-size:.95rem}.drum-module-menu-title{font-size:.7rem}.drum-module-menu-note{font-size:.59rem}.drum-hero{padding:14px 17px}.drum-table thead th{font-size:.66rem;padding:.38rem .24rem}.drum-table td{font-size:.68rem;padding:.36rem .24rem}.history-action{font-size:.6rem;padding:.2rem .3rem}}@media(max-width:700px){.drum-module-menu{grid-template-columns:1fr}}
</style>
<div class="drum-page">
<nav class="drum-module-menu" aria-label="เมนูระบบเบิก Drum">
  <a class="drum-module-menu-item" href="index.php"><span class="drum-module-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4-9 4-9-4Z"></path><path d="M3 7v10l9 4 9-4V7"></path><path d="M12 11v10"></path></svg></span><span class="drum-module-menu-content"><span class="drum-module-menu-title">บันทึกข้อมูลการเบิก Drum</span><span class="drum-module-menu-note">เพิ่ม แก้ไข และจัดการรายการเบิก Drum</span></span></a>
  <a class="drum-module-menu-item active" href="history.php" aria-current="page"><span class="drum-module-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 4v5h5"></path><path d="M12 7v5l3 2"></path></svg></span><span class="drum-module-menu-content"><span class="drum-module-menu-title">ประวัติการจัดส่ง Drum</span><span class="drum-module-menu-note">ค้นหาและตรวจสอบรายการจัดส่งย้อนหลัง</span></span></a>
</nav>
<section class="drum-hero mb-3"><div class="d-flex justify-content-between align-items-center flex-wrap gap-2"><div><h1 class="h5 mb-1 fw-bold">ประวัติการจัดส่ง Drum</h1><div class="small opacity-75">ตรวจสอบรายการเบิกและจัดทำใบปะหน้าจัดส่งย้อนหลัง</div></div></section>
<?php if ($restoreSuccess !== ''): ?><div class="alert alert-success py-2"><?php echo e($restoreSuccess); ?></div><?php endif; ?>
<?php if ($restoreError !== ''): ?><div class="alert alert-danger py-2"><?php echo e($restoreError); ?></div><?php endif; ?>
<?php if ($pageError !== ''): ?><div class="alert alert-danger py-2"><?php echo e($pageError); ?></div><?php endif; ?>
<div class="card drum-filter-card mb-3"><div class="card-body"><form method="get" class="row g-2 drum-filter align-items-end">
<div class="col-lg-4"><label class="form-label small fw-bold">ค้นหา</label><input type="search" name="keyword" class="form-control" value="<?php echo e($keyword); ?>" placeholder="เลขที่รายการ, รหัสสาขา, Cost Center, ชื่อสาขา, ผู้บันทึก"></div>
<div class="col-lg-2"><label class="form-label small fw-bold">ประเภท Drum</label><select name="drum_code" class="form-select"><option value="">ทุกประเภท</option><option value="Drum-DR-3455" <?php echo $drumFilter==='Drum-DR-3455'?'selected':''; ?>>Drum-DR-3455</option><option value="Drum-DR-3608" <?php echo $drumFilter==='Drum-DR-3608'?'selected':''; ?>>Drum-DR-3608</option></select></div>
<div class="col-lg-3"><label class="form-label small fw-bold">ช่วงวันที่</label><div class="input-group"><input type="date" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>"><input type="date" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>"></div></div>
<div class="col-lg-3 d-flex gap-2"><button type="submit" class="btn btn-dark flex-fill"><i class="bi bi-search me-1"></i>ค้นหา</button><a href="history.php" class="btn btn-outline-secondary flex-fill">ล้างค่า</a><a href="<?php echo e($exportExcelUrl); ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a></div>
</form></div></div>
<div class="card drum-card"><div class="card-header d-flex justify-content-between align-items-center"><span><i class="bi bi-clock-history me-1 text-primary"></i>รายการจัดส่ง Drum ย้อนหลัง</span><span class="small text-muted"><?php if ($totalRows > 0): ?><?php echo number_format($pageStart); ?>-<?php echo number_format($pageEnd); ?> จาก <?php echo number_format($totalRows); ?> รายการ | หน้า <?php echo number_format($page); ?>/<?php echo number_format($totalPages); ?><?php else: ?>0 รายการ<?php endif; ?></span></div><div class="card-body p-0"><div class="table-responsive drum-table-wrap"><table class="table table-hover table-bordered align-middle drum-table"><thead><tr><th>ลำดับ</th><th>เลขที่รายการ</th><th>รหัสสาขาใหญ่</th><th>Cost Center</th><th>ชื่อสาขา</th><th>Drum</th><th>ผู้บันทึก</th><th>วันที่บันทึก</th><th>สถานะ</th><th>จัดการ</th></tr></thead><tbody>

<?php if (!$historyRows): ?>
    <tr>
        <td colspan="10" class="text-center text-muted py-5">
            <i class="bi bi-inbox d-block fs-3 mb-2"></i>
            ไม่พบประวัติการจัดส่ง Drum
        </td>
    </tr>
<?php else: ?>
    <?php foreach ($historyRows as $i => $row): ?>
        <tr>
            <td class="text-center">
                <?php echo number_format((($page - 1) * $perPage) + $i + 1); ?>
            </td>

            <td class="fw-bold text-primary">
                <?php echo e($row['request_no']); ?>
            </td>

            <td class="fw-bold text-center">
                <?php echo e($row['main_branch_code']); ?>
            </td>

            <td class="fw-bold text-center text-primary">
                <?php echo e($row['branch_code'] ?: '-'); ?>
            </td>

            <td>
                <?php echo e($row['branch_name']); ?>
            </td>

            <td>
                <?php echo e($row['drum_codes']); ?>
            </td>

            <td>
                <?php echo e($row['recorded_by']); ?>
            </td>

            <td class="text-center">
                <?php echo e(date('d/m/Y', strtotime((string)$row['created_at']))); ?>
            </td>

            <td class="text-center">
                <span class="drum-status-badge drum-status-shipped">
                    จัดส่งแล้ว
                </span>
            </td>

            <td class="text-center">
                <div class="history-action-group">
                    <!-- <a
                        class="btn btn-outline-secondary history-action"
                        href="cover_sheet.php?request_no=<?php echo urlencode((string)$row['request_no']); ?>"
                        target="_blank"
                        rel="noopener"
                    >
                        <i class="bi bi-file-earmark-text"></i>
                        ใบปะหน้า
                    </a> -->

                    <?php if ($isDrumSuperAdmin): ?>
                        <button
                            type="button"
                            class="btn btn-outline-warning history-action js-restore-drum"
                            data-bs-toggle="modal"
                            data-bs-target="#drumRestoreModal"
                            data-request-no="<?php echo e($row['request_no']); ?>"
                        >
                            <i class="bi bi-arrow-counterclockwise"></i>
                            ย้อนคืนสถานะ
                        </button>
                    <?php endif; ?>
                </div>
            </td>
        </tr>
    <?php endforeach; ?>
<?php endif; ?>

</tbody></table></div>
<?php if ($totalPages > 1): ?>
<div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2 border-top bg-white">
  <div class="small text-muted">แสดง <?php echo number_format($pageStart); ?>-<?php echo number_format($pageEnd); ?> จาก <?php echo number_format($totalRows); ?> รายการ</div>
  <nav aria-label="แบ่งหน้าประวัติการจัดส่ง Drum">
    <ul class="pagination pagination-sm mb-0">
      <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo e($buildHistoryPageUrl($page - 1)); ?>" aria-label="ก่อนหน้า">ก่อนหน้า</a>
      </li>
      <?php
        $startPage = max(1, $page - 2);
        $endPage = min($totalPages, $page + 2);
        for ($pageNo = $startPage; $pageNo <= $endPage; $pageNo++):
      ?>
      <li class="page-item <?php echo $pageNo === $page ? 'active' : ''; ?>">
        <a class="page-link" href="<?php echo e($buildHistoryPageUrl($pageNo)); ?>"><?php echo number_format($pageNo); ?></a>
      </li>
      <?php endfor; ?>
      <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>">
        <a class="page-link" href="<?php echo e($buildHistoryPageUrl($page + 1)); ?>" aria-label="ถัดไป">ถัดไป</a>
      </li>
    </ul>
  </nav>
</div>
<?php endif; ?>
</div></div>
</div>

<?php if ($isDrumSuperAdmin): ?>
<div class="modal fade drum-restore-modal" id="drumRestoreModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" action="restore_status.php" class="modal-content">
      <div class="modal-header bg-warning-subtle">
        <h5 class="modal-title fw-bold text-warning-emphasis"><i class="bi bi-arrow-counterclockwise me-1"></i>ยืนยันย้อนคืนสถานะ</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_drum_restore']); ?>">
        <input type="hidden" name="request_no" id="restoreRequestNo">
        <p class="mb-1">ต้องการย้อนคืนรายการเลขที่</p>
        <div class="fs-5 fw-bold text-warning-emphasis" id="restoreRequestNoText">-</div>
        <div class="alert alert-warning py-2 mt-3 mb-0 small">สถานะจะเปลี่ยนจาก <strong>จัดส่งแล้ว</strong> เป็น <strong>รอยืนยันจัดส่ง</strong> และรายการจะกลับไปแสดงหน้า “บันทึกข้อมูลการเบิก Drum”</div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
        <button type="submit" class="btn btn-warning"><i class="bi bi-arrow-counterclockwise me-1"></i>ยืนยันย้อนคืนสถานะ</button>
      </div>
    </form>
  </div>
</div>
<script>
(function () {
    'use strict';

    function moveRestoreModalToBodyEnd() {
        var modal = document.getElementById('drumRestoreModal');
        if (modal && modal.parentElement !== document.body) {
            document.body.appendChild(modal);
        } else if (modal) {
            document.body.appendChild(modal);
        }
    }

    function bindRestoreButtons() {
        document.querySelectorAll('.js-restore-drum').forEach(function (button) {
            button.addEventListener('click', function () {
                var requestNo = button.dataset.requestNo || '';
                var input = document.getElementById('restoreRequestNo');
                var text = document.getElementById('restoreRequestNoText');
                if (input) input.value = requestNo;
                if (text) text.textContent = requestNo || '-';
            });
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () {
            moveRestoreModalToBodyEnd();
            bindRestoreButtons();
        });
    } else {
        moveRestoreModalToBodyEnd();
        bindRestoreButtons();
    }

    document.addEventListener('show.bs.modal', function (event) {
        if (event.target && event.target.id === 'drumRestoreModal') {
            document.body.appendChild(event.target);
        }
    }, true);
})();
</script>
<?php endif; ?>
<?php if (file_exists(__DIR__ . '/../../includes/footer.php')) require_once __DIR__ . '/../../includes/footer.php'; ?>
