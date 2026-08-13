<?php
$pageTitle = 'Preview Import ข้อมูลสาขา';

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/permissions.php';
require_once __DIR__ . '/includes/import_helpers.php';

branchImportRequireAccess();

$batchId = (int)($_GET['batch_id'] ?? 0);
$batch = $batchId > 0 ? branchImportGetBatch($pdo, $batchId) : null;
if (!$batch) {
    http_response_code(404);
    exit('ไม่พบ Batch การอัปโหลด');
}

$actionFilter = trim((string)($_GET['action_type'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 100;
$offset = ($page - 1) * $limit;

$where = ['batch_id = :batch_id'];
$params = [':batch_id' => $batchId];
if (in_array($actionFilter, ['insert', 'update', 'unchanged', 'error'], true)) {
    $where[] = 'action_type = :action_type';
    $params[':action_type'] = $actionFilter;
}

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM branch_import_rows WHERE ' . implode(' AND ', $where));
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

$sql = 'SELECT * FROM branch_import_rows WHERE ' . implode(' AND ', $where) . ' ORDER BY row_no ASC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

$canImport = ((int)$batch['new_rows'] + (int)$batch['updated_rows']) > 0 && $batch['status'] === 'validated';

require_once __DIR__ . '/../../../includes/header.php';
?>

<style>
    .branch-preview-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .04);
    }
    .branch-kpi-grid {
        display: grid;
        grid-template-columns: repeat(6, minmax(0, 1fr));
        gap: 1rem;
        align-items: stretch;
    }
    .branch-kpi-item {
        min-width: 0;
    }
    .branch-kpi {
        border-radius: 14px;
        border: 1px solid #dbeafe;
        background: #f8fafc;
        padding: 14px;
        height: 100%;
        min-height: 104px;
        display: flex;
        flex-direction: column;
        justify-content: center;
        overflow: hidden;
    }
    .branch-kpi .kpi-title,
    .branch-kpi .kpi-subtitle {
        display: block;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
    }
    .branch-kpi .value {
        font-size: 1.6rem;
        line-height: 1.1;
        font-weight: 900;
        color: #0f172a;
    }
    .branch-kpi-link {
        color: inherit;
        cursor: pointer;
        transition: .15s ease-in-out;
    }
    .branch-kpi-link:hover {
        border-color: #34d399;
        background: #ecfdf5;
        transform: translateY(-1px);
    }
    .branch-kpi-link.active {
        border-color: #10b981;
        background: #ecfdf5;
        box-shadow: inset 0 0 0 1px rgba(16, 185, 129, .28);
    }
    .branch-detail-table th {
        width: 190px;
        white-space: nowrap;
        background: #f8fafc;
    }
    .branch-detail-table td {
        word-break: break-word;
    }
    .branch-preview-table {
        min-width: 1180px;
    }
    .branch-preview-table th,
    .branch-preview-table td {
        white-space: nowrap;
        vertical-align: middle;
        font-size: .88rem;
    }
    .text-wrap-col {
        white-space: normal !important;
        min-width: 260px;
        max-width: 360px;
    }

    @media (max-width: 1399.98px) {
        .branch-kpi-grid {
            grid-template-columns: repeat(3, minmax(0, 1fr));
        }
    }
    @media (max-width: 767.98px) {
        .branch-kpi-grid {
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
    }
    @media (max-width: 479.98px) {
        .branch-kpi-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div>
        <h1 class="h4 mb-1 fw-bold">Preview Import ข้อมูลสาขา</h1>
        <div class="text-muted">ตรวจสอบผลก่อนเขียนข้อมูลลง <strong>branch_directory</strong></div>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-outline-secondary btn-sm">อัปโหลดไฟล์ใหม่</a>
        <a href="history.php" class="btn btn-outline-primary btn-sm">ประวัติ</a>
    </div>
</div>

<?php if (!empty($_GET['created'])): ?>
    <div class="alert alert-info">ตรวจสอบไฟล์เรียบร้อยแล้ว กรุณาตรวจรายการด้านล่างก่อนกดยืนยัน Import</div>
<?php endif; ?>

<?php if (!empty($_GET['imported'])): ?>
    <div class="alert alert-success">Import ข้อมูลลง branch_directory เรียบร้อยแล้ว</div>
<?php endif; ?>

<?php if (!empty($_GET['cancelled'])): ?>
    <div class="alert alert-warning">ยกเลิกการ Import เรียบร้อยแล้ว Batch นี้จะไม่ถูกนำเข้าฐานข้อมูล</div>
<?php endif; ?>

<?php if (!empty($_GET['error'])): ?>
    <div class="alert alert-danger"><?php echo branchImportE((string)$_GET['error']); ?></div>
<?php endif; ?>

<div class="branch-preview-card p-3 mb-3">
    <div class="branch-kpi-grid">
        <div class="branch-kpi-item">
            <div class="branch-kpi">
                <div class="small text-muted kpi-title">Batch</div>
                <div class="fw-bold text-primary"><?php echo branchImportE($batch['batch_no']); ?></div>
                <div class="small text-muted mt-1 kpi-subtitle"><?php echo branchImportE($batch['original_filename']); ?></div>
            </div>
        </div>
        <div class="branch-kpi-item">
            <a class="branch-kpi branch-kpi-link text-decoration-none d-block <?php echo $actionFilter === '' ? 'active' : ''; ?>" href="preview.php?batch_id=<?php echo $batchId; ?>" title="คลิกเพื่อดูรายการทั้งหมด">
                <div class="small text-muted kpi-title">ทั้งหมด</div>
                <div class="value"><?php echo number_format((int)$batch['total_rows']); ?></div>
                <div class="small text-muted mt-1 kpi-subtitle">คลิกเพื่อดูรายการ</div>
            </a>
        </div>
        <div class="branch-kpi-item">
            <a class="branch-kpi branch-kpi-link text-decoration-none d-block <?php echo $actionFilter === 'insert' ? 'active' : ''; ?>" href="preview.php?batch_id=<?php echo $batchId; ?>&action_type=insert" title="คลิกเพื่อดูรายการเพิ่มใหม่">
                <div class="small text-muted kpi-title">เพิ่มใหม่</div>
                <div class="value text-success"><?php echo number_format((int)$batch['new_rows']); ?></div>
                <div class="small text-success mt-1 kpi-subtitle">คลิกเพื่อดูรายการ</div>
            </a>
        </div>
        <div class="branch-kpi-item">
            <a class="branch-kpi branch-kpi-link text-decoration-none d-block <?php echo $actionFilter === 'update' ? 'active' : ''; ?>" href="preview.php?batch_id=<?php echo $batchId; ?>&action_type=update" title="คลิกเพื่อดูรายการอัปเดต">
                <div class="small text-muted kpi-title">อัปเดต</div>
                <div class="value text-primary"><?php echo number_format((int)$batch['updated_rows']); ?></div>
                <div class="small text-primary mt-1 kpi-subtitle">คลิกเพื่อดูรายการ</div>
            </a>
        </div>
        <div class="branch-kpi-item">
            <a class="branch-kpi branch-kpi-link text-decoration-none d-block <?php echo $actionFilter === 'unchanged' ? 'active' : ''; ?>" href="preview.php?batch_id=<?php echo $batchId; ?>&action_type=unchanged" title="คลิกเพื่อดูรายการเดิม">
                <div class="small text-muted kpi-title">เดิม</div>
                <div class="value text-secondary"><?php echo number_format((int)$batch['unchanged_rows']); ?></div>
            </a>
        </div>
        <div class="branch-kpi-item">
            <a class="branch-kpi branch-kpi-link text-decoration-none d-block <?php echo $actionFilter === 'error' ? 'active' : ''; ?>" href="preview.php?batch_id=<?php echo $batchId; ?>&action_type=error" title="คลิกเพื่อดูรายการ Error">
                <div class="small text-muted kpi-title">Error</div>
                <div class="value text-danger"><?php echo number_format((int)$batch['error_rows']); ?></div>
                <div class="small text-danger mt-1 kpi-subtitle">คลิกเพื่อดูรายการ</div>
            </a>
        </div>
    </div>
</div>

<div class="branch-preview-card p-3 mb-3">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            <span class="fw-bold">สถานะ Batch:</span>
            <?php echo branchImportStatusBadge((string)$batch['status']); ?>
            <span class="text-muted small ms-2">เดือนข้อมูล <?php echo branchImportE($batch['import_month']); ?></span>
        </div>
        <div class="d-flex flex-wrap gap-2">
            <a class="btn btn-outline-secondary btn-sm <?php echo $actionFilter === '' ? 'active' : ''; ?>" href="preview.php?batch_id=<?php echo $batchId; ?>">ทั้งหมด</a>
            <a class="btn btn-outline-success btn-sm <?php echo $actionFilter === 'insert' ? 'active' : ''; ?>" href="preview.php?batch_id=<?php echo $batchId; ?>&action_type=insert">เพิ่มใหม่</a>
            <a class="btn btn-outline-primary btn-sm <?php echo $actionFilter === 'update' ? 'active' : ''; ?>" href="preview.php?batch_id=<?php echo $batchId; ?>&action_type=update">อัปเดต</a>
            <a class="btn btn-outline-danger btn-sm <?php echo $actionFilter === 'error' ? 'active' : ''; ?>" href="preview.php?batch_id=<?php echo $batchId; ?>&action_type=error">Error</a>
        </div>
    </div>

    <?php if ($canImport): ?>
        <div class="mt-3 d-flex flex-wrap align-items-center gap-2">
            <form method="post" action="confirm.php" class="m-0" onsubmit="return confirm('ยืนยัน Import ข้อมูลลง branch_directory ใช่หรือไม่?');">
                <input type="hidden" name="csrf_token" value="<?php echo branchImportE(branchImportCsrfToken()); ?>">
                <input type="hidden" name="batch_id" value="<?php echo $batchId; ?>">
                <input type="hidden" name="action" value="confirm">
                <button type="submit" class="btn btn-success">ยืนยัน Import ลงฐานข้อมูล</button>
            </form>

            <form method="post" action="confirm.php" class="m-0" onsubmit="return confirm('ต้องการยกเลิกการ Import Batch นี้ใช่หรือไม่?');">
                <input type="hidden" name="csrf_token" value="<?php echo branchImportE(branchImportCsrfToken()); ?>">
                <input type="hidden" name="batch_id" value="<?php echo $batchId; ?>">
                <input type="hidden" name="action" value="cancel">
                <button type="submit" class="btn btn-outline-danger">ยกเลิกการ Import</button>
            </form>

            <?php if ((int)$batch['error_rows'] > 0): ?>
                <span class="text-danger small">มีรายการ Error <?php echo number_format((int)$batch['error_rows']); ?> รายการ ระบบจะ Import เฉพาะรายการที่ถูกต้อง</span>
            <?php endif; ?>
        </div>
    <?php elseif ($batch['status'] === 'imported'): ?>
        <div class="alert alert-success mt-3 mb-0">Batch นี้ Import เรียบร้อยแล้ว</div>
    <?php elseif ($batch['status'] === 'cancelled'): ?>
        <div class="alert alert-warning mt-3 mb-0">Batch นี้ถูกยกเลิกการ Import แล้ว</div>
    <?php else: ?>
        <div class="alert alert-warning mt-3 mb-0">ไม่มีรายการเพิ่มใหม่/อัปเดตสำหรับ Import</div>
    <?php endif; ?>
</div>

<?php if ($actionFilter !== ''): ?>
    <?php
        $filterLabels = [
            'insert' => 'สาขาที่เพิ่มใหม่',
            'update' => 'รายการอัปเดต',
            'unchanged' => 'รายการเดิม / ไม่เปลี่ยนแปลง',
            'error' => 'รายการ Error',
        ];
        $filterLabel = $filterLabels[$actionFilter] ?? $actionFilter;
    ?>
    <div class="alert alert-info d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div>
            กำลังแสดงเฉพาะรายการ <strong><?php echo branchImportE($filterLabel); ?></strong> จำนวน <?php echo number_format($totalRows); ?> รายการ
            <span class="text-muted small ms-1">คลิกที่ Cost Center เพื่อดูรายละเอียดทั้งหมดของรายการ</span>
        </div>
        <a href="preview.php?batch_id=<?php echo $batchId; ?>" class="btn btn-outline-primary btn-sm">กลับไปดูทั้งหมด</a>
    </div>
<?php else: ?>
    <div class="alert alert-light border d-flex flex-wrap justify-content-between align-items-center gap-2">
        <div class="text-muted small">คลิกที่ <strong>Cost Center</strong> ในตาราง เพื่อดูรายละเอียดทั้งหมดของแต่ละรายการ</div>
    </div>
<?php endif; ?>

<div class="branch-preview-card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-bold">รายละเอียดรายการจากไฟล์</div>
        <div class="small text-muted">แสดง <?php echo number_format(count($rows)); ?> จาก <?php echo number_format($totalRows); ?> รายการ</div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered branch-preview-table align-middle mb-0">
            <thead class="table-light">
                <tr>
                    <th>แถว</th>
                    <th>Action</th>
                    <th>รหัสสาขาใหญ่</th>
                    <th>Cost Center</th>
                    <th>ชื่อสาขา</th>
                    <th>ประเภทสาขา</th>
                    <th>จังหวัด</th>
                    <th>เบอร์โทร</th>
                    <th>สถานะใช้งาน</th>
                    <th>ข้อความแจ้งเตือน</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($rows)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                    <?php foreach ($rows as $row): ?>
                        <?php
                            $newData = branchImportDecodeJson($row['new_data'] ?? '{}');
                            $oldData = branchImportDecodeJson($row['old_data'] ?? '{}');
                            $detailJson = branchImportE(branchImportJson($newData));
                            $oldDetailJson = branchImportE(branchImportJson($oldData));
                        ?>
                        <tr>
                            <td><?php echo (int)$row['row_no']; ?></td>
                            <td><?php echo branchImportActionBadge((string)$row['action_type']); ?></td>
                            <td><?php echo branchImportE($row['main_branch_code']); ?></td>
                            <td class="fw-bold text-primary">
                                <button type="button"
                                        class="btn btn-link btn-sm p-0 fw-bold text-primary branch-detail-btn"
                                        data-bs-toggle="modal"
                                        data-bs-target="#branchDetailModal"
                                        data-row-no="<?php echo (int)$row['row_no']; ?>"
                                        data-action-type="<?php echo branchImportE((string)$row['action_type']); ?>"
                                        data-error-message="<?php echo branchImportE($row['error_message']); ?>"
                                        data-branch-json="<?php echo $detailJson; ?>"
                                        data-old-json="<?php echo $oldDetailJson; ?>">
                                    <?php echo branchImportE($row['branch_code']); ?>
                                </button>
                            </td>
                            <td class="text-wrap-col"><?php echo branchImportE($row['branch_name']); ?></td>
                            <td><?php echo branchImportE($newData['branch_type'] ?? ''); ?></td>
                            <td><?php echo branchImportE($newData['province'] ?? ''); ?></td>
                            <td><?php echo branchImportE($row['phone']); ?></td>
                            <td><?php echo ((int)$row['is_active'] === 1) ? '<span class="badge bg-success">ใช้งาน</span>' : '<span class="badge bg-secondary">ไม่ใช้งาน</span>'; ?></td>
                            <td class="text-wrap-col text-danger"><?php echo branchImportE($row['error_message']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($totalPages > 1): ?>
        <nav class="mt-3">
            <ul class="pagination pagination-sm justify-content-center mb-0">
                <?php for ($i = max(1, $page - 2); $i <= min($totalPages, $page + 2); $i++): ?>
                    <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                        <a class="page-link" href="preview.php?batch_id=<?php echo $batchId; ?>&action_type=<?php echo branchImportE($actionFilter); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>


<div class="modal fade" id="branchDetailModal" tabindex="-1" aria-labelledby="branchDetailModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold" id="branchDetailModalLabel">รายละเอียดรายการข้อมูลสาขา</h5>
                    <div class="small text-muted" id="branchDetailSubtitle">ตรวจสอบข้อมูลก่อนยืนยัน Import</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-info py-2 small">
                    ข้อมูลนี้มาจากไฟล์ที่อัปโหลด ใช้ตรวจสอบก่อนยืนยัน Import เข้า <strong>branch_directory</strong>
                </div>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered branch-detail-table mb-0">
                        <tbody id="branchDetailTableBody">
                            <tr><td colspan="3" class="text-muted">ไม่พบข้อมูล</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal">ปิด</button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('click', function (event) {
    var button = event.target.closest('.branch-detail-btn');
    if (!button) {
        return;
    }

    var raw = button.getAttribute('data-branch-json') || '{}';
    var oldRaw = button.getAttribute('data-old-json') || '{}';
    var data = {};
    var oldData = {};
    try {
        data = JSON.parse(raw);
    } catch (e) {
        data = {};
    }
    try {
        oldData = JSON.parse(oldRaw);
    } catch (e) {
        oldData = {};
    }

    var fields = [
        ['main_branch_code', 'รหัสสาขาใหญ่'],
        ['branch_code', 'Cost Center'],
        ['branch_name', 'ชื่อสาขา'],
        ['branch_name_2', 'ชื่อสาขา 2'],
        ['branch_type', 'ประเภทสาขา'],
        ['full_address', 'ที่อยู่เต็ม'],
        ['phone', 'เบอร์โทรศัพท์'],
        ['landmark', 'สถานที่ใกล้เคียง'],
        ['area_code', 'สังกัดเขต'],
        ['hierarchy_area', 'Hierarchy area'],
        ['address_line', 'บ้านเลขที่/หมู่/ถนน/ซอย'],
        ['subdistrict', 'ตำบล/แขวง'],
        ['district', 'อำเภอ/เขต'],
        ['province', 'จังหวัด'],
        ['postal_code', 'รหัสไปรษณีย์'],
        ['bot_registered_date', 'ว/ด/ป ธปท. ค.ศ.'],
        ['opening_date', 'ว/ด/ป ทำการ ค.ศ.'],
        ['dbd_registration_no', 'ลำดับจดทะเบียนกรมพัฒน์'],
        ['latitude', 'ละติจูด'],
        ['longitude', 'ลองจิจูด'],
        ['payment_machine_no', 'หมายเลขประจำเครื่องชำระเงิน'],
        ['ptd20_registered_date', 'วันที่จดทะเบียน ภธ.20'],
        ['pp20_registered_date', 'วันที่จดทะเบียน ภพ.20'],
        ['is_active', 'สถานะใช้งาน'],
        ['source_file', 'ไฟล์ต้นทาง']
    ];

    var tableBody = document.getElementById('branchDetailTableBody');
    var title = document.getElementById('branchDetailModalLabel');
    var subtitle = document.getElementById('branchDetailSubtitle');
    var branchCode = data.branch_code || oldData.branch_code || '-';
    var branchName = data.branch_name || oldData.branch_name || '-';
    var rowNo = button.getAttribute('data-row-no') || '-';
    var actionType = button.getAttribute('data-action-type') || '-';
    var errorMessage = button.getAttribute('data-error-message') || '';

    var actionLabels = {
        insert: 'เพิ่มใหม่',
        update: 'อัปเดต',
        unchanged: 'ไม่เปลี่ยนแปลง',
        error: 'Error'
    };

    title.textContent = 'รายละเอียดรายการ: ' + branchCode;
    subtitle.textContent = 'แถวที่ ' + rowNo + ' | ' + branchName + ' | Action: ' + (actionLabels[actionType] || actionType);

    var hasOldData = Object.keys(oldData || {}).length > 0;
    var html = '';
    html += '<tr><th>Action</th><td colspan="2"><strong>' + escapeHtml(actionLabels[actionType] || actionType) + '</strong></td></tr>';
    if (errorMessage) {
        html += '<tr><th>ข้อความแจ้งเตือน</th><td colspan="2" class="text-danger">' + escapeHtml(errorMessage) + '</td></tr>';
    }

    if (hasOldData) {
        html += '<tr class="table-light"><th>ข้อมูล</th><th>ข้อมูลในไฟล์รอบนี้</th><th>ข้อมูลเดิมในฐานข้อมูล</th></tr>';
    }

    fields.forEach(function (item) {
        var key = item[0];
        var label = item[1];
        var value = data[key];
        var oldValue = oldData[key];
        if (key === 'is_active') {
            value = String(value) === '1' ? 'ใช้งาน' : 'ไม่ใช้งาน';
            if (oldValue !== undefined && oldValue !== null && oldValue !== '') {
                oldValue = String(oldValue) === '1' ? 'ใช้งาน' : 'ไม่ใช้งาน';
            }
        }
        if (value === null || value === undefined || value === '') {
            value = '-';
        }
        if (oldValue === null || oldValue === undefined || oldValue === '') {
            oldValue = '-';
        }

        if (hasOldData) {
            var changedClass = String(value) !== String(oldValue) ? ' class="table-warning"' : '';
            html += '<tr' + changedClass + '><th>' + escapeHtml(label) + '</th><td>' + escapeHtml(String(value)) + '</td><td>' + escapeHtml(String(oldValue)) + '</td></tr>';
        } else {
            html += '<tr><th>' + escapeHtml(label) + '</th><td colspan="2">' + escapeHtml(String(value)) + '</td></tr>';
        }
    });

    tableBody.innerHTML = html || '<tr><td colspan="3" class="text-muted">ไม่พบข้อมูล</td></tr>';
});

function escapeHtml(value) {
    return value
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}
</script>


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

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
