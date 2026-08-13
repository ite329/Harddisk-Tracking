<?php

require_once __DIR__ . '/../../../includes/auth.php';
$pageTitle = 'อัปเดตข้อมูลสาขาประจำเดือน';

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/permissions.php';
require_once __DIR__ . '/includes/import_helpers.php';

if (!function_exists('branchImportStatusBadgeClass')) {
    function branchImportStatusBadgeClass($status): string
    {
        $status = strtolower(trim((string)$status));

        if ($status === 'imported') {
            return 'bg-success';
        }
        if ($status === 'cancelled') {
            return 'bg-danger';
        }
        if ($status === 'validated') {
            return 'bg-warning text-dark';
        }

        return 'bg-secondary';
    }
}

branchImportRequireAccess();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        branchImportVerifyCsrf();

        if (empty($_FILES['branch_file']['tmp_name']) || !is_uploaded_file($_FILES['branch_file']['tmp_name'])) {
            throw new RuntimeException('กรุณาเลือกไฟล์ CSV ก่อนตรวจสอบข้อมูล');
        }

        $originalFilename = basename((string)$_FILES['branch_file']['name']);
        $extension = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));
        if ($extension !== 'csv') {
            throw new RuntimeException('รอบนี้รองรับไฟล์ .csv ก่อน กรุณา Save as CSV จาก Excel แล้วอัปโหลดอีกครั้ง');
        }

        $importMonth = trim((string)($_POST['import_month'] ?? ''));
        if (!preg_match('/^\d{4}-\d{2}$/', $importMonth)) {
            throw new RuntimeException('กรุณาเลือกเดือนข้อมูลให้ถูกต้อง เช่น 2026-07');
        }

        $uploadDir = __DIR__ . '/../../../uploads/branch_imports';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0775, true);
        }

        $safeName = preg_replace('/[^A-Za-z0-9_\.\-ก-ฮะ-์]/u', '_', $originalFilename);
        $storedPath = $uploadDir . '/' . date('YmdHis') . '_' . random_int(100, 999) . '_' . $safeName;
        if (!move_uploaded_file($_FILES['branch_file']['tmp_name'], $storedPath)) {
            throw new RuntimeException('ไม่สามารถบันทึกไฟล์อัปโหลดได้');
        }

        $batchId = branchImportCreatePreviewBatch($pdo, $storedPath, $originalFilename, [
            'import_month' => $importMonth,
            'allow_insert_new' => !empty($_POST['allow_insert_new']),
            'allow_update_existing' => !empty($_POST['allow_update_existing']),
            'allow_blank_overwrite' => !empty($_POST['allow_blank_overwrite']),
            'deactivate_missing' => !empty($_POST['deactivate_missing']),
        ]);

        header('Location: preview.php?batch_id=' . $batchId . '&created=1');
        exit;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}

$latestBatches = [];
if (branchImportTableExists($pdo, 'branch_import_batches')) {
    try {
        $stmtLatest = $pdo->query('SELECT * FROM branch_import_batches ORDER BY id DESC LIMIT 5');
        $latestBatches = $stmtLatest->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $latestBatches = [];
    }
}

require_once __DIR__ . '/../../../includes/header.php';

require_login();
require_permission('admin.branch_import');

?>

<style>
    .branch-import-hero {
        background: linear-gradient(135deg, #e0f2fe 0%, #eff6ff 55%, #ffffff 100%);
        border: 1px solid #bfdbfe;
        border-radius: 18px;
        padding: 18px 20px;
        margin-bottom: 16px;
    }
    .branch-import-step {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #ffffff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .04);
    }
    .branch-import-step .step-badge {
        width: 32px;
        height: 32px;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        border-radius: 10px;
        background: #2563eb;
        color: #fff;
        font-weight: 800;
        margin-right: 8px;
    }
    .branch-import-note {
        background: #f8fafc;
        border: 1px dashed #cbd5e1;
        border-radius: 14px;
        padding: 12px 14px;
    }
    .branch-import-table th,
    .branch-import-table td {
        white-space: nowrap;
        vertical-align: middle;
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div>
        <h1 class="h4 mb-1 fw-bold text-gray-900">อัปเดตข้อมูลสาขาประจำเดือน</h1>
        <div class="text-muted">สำหรับอัปเดตข้อมูลสาขาประจำเดือน</div>
    </div>
    <div class="d-flex gap-2">
        <a href="download_template.php" class="btn btn-outline-primary btn-sm">ดาวน์โหลด Template</a>
        <a href="history.php" class="btn btn-outline-secondary btn-sm">ประวัติการอัปโหลด</a>
    </div>
</div>

<?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo branchImportE($error); ?></div>
<?php endif; ?>

<div class="branch-import-hero">
    <div class="row g-3 align-items-center">
        <div class="col-lg-8">
            <div class="fw-bold text-primary mb-1">Monthly Branch Master Data Update</div>
            <div class="text-muted small">ขั้นตอนคือ Upload CSV → Preview ตรวจสอบ → Confirm Import ระบบจะจับคู่ด้วย <strong>Cost Center / branch_code</strong></div>
        </div>
        <div class="col-lg-4 text-lg-end small text-muted">
            ผู้ใช้งานปัจจุบัน: <strong><?php echo branchImportE(branchImportCurrentUserDisplay()); ?></strong>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-xl-7">
        <div class="branch-import-step p-3 p-lg-4">
            <div class="d-flex align-items-center mb-3">
                <span class="step-badge">1</span>
                <div>
                    <div class="fw-bold">อัปโหลดไฟล์ข้อมูลสาขา</div>
                    <div class="small text-muted">รองรับไฟล์ CSV UTF-8 จาก Excel</div>
                </div>
            </div>

            <form method="post" enctype="multipart/form-data" autocomplete="off">
                <input type="hidden" name="csrf_token" value="<?php echo branchImportE(branchImportCsrfToken()); ?>">

                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">เดือนข้อมูล <span class="text-danger">*</span></label>
                        <input type="month" name="import_month" class="form-control" value="<?php echo date('Y-m'); ?>" required>
                    </div>
                    <div class="col-md-8">
                        <label class="form-label fw-bold">ไฟล์ข้อมูลสาขา CSV <span class="text-danger">*</span></label>
                        <input type="file" name="branch_file" class="form-control" accept=".csv,text/csv" required>
                    </div>
                </div>

                <div class="branch-import-note mt-3">
                    <div class="fw-bold mb-2">ตัวเลือกการอัปเดต</div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="allow_insert_new" value="1" id="allowInsertNew" checked>
                        <label class="form-check-label" for="allowInsertNew">เพิ่มสาขาใหม่ ถ้าไม่พบ Cost Center เดิม</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="allow_update_existing" value="1" id="allowUpdateExisting" checked>
                        <label class="form-check-label" for="allowUpdateExisting">อัปเดตข้อมูลสาขาเดิม ถ้า Cost Center ตรงกัน</label>
                    </div>
                    <div class="form-check mb-1">
                        <input class="form-check-input" type="checkbox" name="allow_blank_overwrite" value="1" id="allowBlankOverwrite">
                        <label class="form-check-label" for="allowBlankOverwrite">อนุญาตให้ค่าว่างในไฟล์เขียนทับข้อมูลเดิม</label>
                    </div>
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="deactivate_missing" value="1" id="deactivateMissing">
                        <label class="form-check-label" for="deactivateMissing">ปิดใช้งานสาขาที่ไม่มีอยู่ในไฟล์รอบนี้ <span class="text-danger small">ควรใช้เฉพาะไฟล์ Master ครบทั้งบริษัท</span></label>
                    </div>
                </div>

                <div class="d-flex justify-content-end gap-2 mt-3">
                    <button type="reset" class="btn btn-outline-secondary">ล้างค่า</button>
                    <button type="submit" class="btn btn-primary">ตรวจสอบไฟล์</button>
                </div>
            </form>
        </div>
    </div>

    <div class="col-xl-5">
        <div class="branch-import-step p-3 p-lg-4 h-100">
            <div class="d-flex align-items-center mb-3">
                <span class="step-badge">2</span>
                <div>
                    <div class="fw-bold">รูปแบบไฟล์ที่รองรับ</div>
                    <div class="small text-muted">ระบบจะ Map หัวคอลัมน์ให้อัตโนมัติ</div>
                </div>
            </div>

            <div class="small text-muted mb-2">คอลัมน์หลักที่แนะนำ</div>
            <div class="table-responsive">
                <table class="table table-sm table-bordered branch-import-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th>คอลัมน์</th>
                            <th>ใช้ทำอะไร</th>
                        </tr>
                    </thead>
                    <tbody>
                        <tr><td>main_branch_code</td><td>รหัสสาขาใหญ่</td></tr>
                        <tr><td>branch_code</td><td>Cost Center สำหรับจับคู่</td></tr>
                        <tr><td>branch_name</td><td>ชื่อสาขา</td></tr>
                        <tr><td>branch_type</td><td>ประเภทสาขา เช่น สาขาใหญ่, สาขาย่อย, ศูนย์บริการ</td></tr>
                        <tr><td>full_address</td><td>ที่อยู่เต็ม</td></tr>
                        <tr><td>phone</td><td>เบอร์โทรศัพท์</td></tr>
                        <tr><td>is_active</td><td>สถานะใช้งาน 1/0</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<div class="branch-import-step p-3 mt-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-bold">ประวัติอัปโหลดล่าสุด</div>
        <a href="history.php" class="small">ดูทั้งหมด</a>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered align-middle branch-import-table mb-0">
            <thead class="table-light">
                <tr>
                    <th>Batch</th>
                    <th>เดือนข้อมูล</th>
                    <th>ไฟล์</th>
                    <th>ทั้งหมด</th>
                    <th>เพิ่มใหม่</th>
                    <th>อัปเดต</th>
                    <th>Error</th>
                    <th>สถานะ</th>
                    <th>ผู้อัปโหลด</th>
                    <th>วันที่</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($latestBatches)): ?>
                    <tr><td colspan="10" class="text-center text-muted py-3">ยังไม่มีประวัติการอัปโหลด</td></tr>
                <?php else: ?>
                    <?php foreach ($latestBatches as $batch): ?>
                        <tr>
                            <td><a href="preview.php?batch_id=<?php echo (int)$batch['id']; ?>"><?php echo branchImportE($batch['batch_no']); ?></a></td>
                            <td><?php echo branchImportE($batch['import_month']); ?></td>
                            <td><?php echo branchImportE($batch['original_filename']); ?></td>
                            <td><?php echo number_format((int)$batch['total_rows']); ?></td>
                            <td><?php echo number_format((int)$batch['new_rows']); ?></td>
                            <td><?php echo number_format((int)$batch['updated_rows']); ?></td>
                            <td><?php echo number_format((int)$batch['error_rows']); ?></td>
                            <td><span class="badge <?php echo branchImportStatusBadgeClass($batch['status'] ?? ''); ?>"><?php echo branchImportE($batch['status']); ?></span></td>
                            <td><?php echo branchImportE($batch['uploaded_by']); ?></td>
                            <td><?php echo branchImportE($batch['uploaded_at']); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
