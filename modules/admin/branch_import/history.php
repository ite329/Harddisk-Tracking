<?php
$pageTitle = 'ประวัติ Import ข้อมูลสาขา';

require_once __DIR__ . '/../../../config/database.php';
require_once __DIR__ . '/../../../includes/permissions.php';
require_once __DIR__ . '/includes/import_helpers.php';

branchImportRequireAccess();

$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 30;
$offset = ($page - 1) * $limit;
$keyword = trim((string)($_GET['keyword'] ?? ''));
$status = trim((string)($_GET['status'] ?? ''));

$where = [];
$params = [];
if ($keyword !== '') {
    $where[] = '(batch_no LIKE :keyword OR original_filename LIKE :keyword OR uploaded_by LIKE :keyword)';
    $params[':keyword'] = '%' . $keyword . '%';
}
if (in_array($status, ['uploaded', 'validated', 'imported', 'failed', 'cancelled'], true)) {
    $where[] = 'status = :status';
    $params[':status'] = $status;
}
$whereSql = empty($where) ? '' : ' WHERE ' . implode(' AND ', $where);

$countStmt = $pdo->prepare('SELECT COUNT(*) FROM branch_import_batches' . $whereSql);
$countStmt->execute($params);
$totalRows = (int)$countStmt->fetchColumn();
$totalPages = max(1, (int)ceil($totalRows / $limit));

$sql = 'SELECT * FROM branch_import_batches' . $whereSql . ' ORDER BY id DESC LIMIT :limit OFFSET :offset';
$stmt = $pdo->prepare($sql);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$batches = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once __DIR__ . '/../../../includes/header.php';
?>

<style>
    .branch-history-card {
        border: 1px solid #e2e8f0;
        border-radius: 16px;
        background: #fff;
        box-shadow: 0 8px 20px rgba(15, 23, 42, .04);
    }
    .branch-history-table {
        min-width: 1180px;
    }
    .branch-history-table th,
    .branch-history-table td {
        white-space: nowrap;
        vertical-align: middle;
        font-size: .88rem;
    }
</style>

<div class="d-flex flex-wrap justify-content-between align-items-start mb-3 gap-2">
    <div>
        <h1 class="h4 mb-1 fw-bold">ประวัติ Import ข้อมูลสาขา</h1>
        <div class="text-muted">ตรวจสอบประวัติการอัปโหลดและผลการ Import ข้อมูล branch_directory</div>
    </div>
    <div class="d-flex gap-2">
        <a href="index.php" class="btn btn-primary btn-sm">อัปโหลดไฟล์ใหม่</a>
        <a href="download_template.php" class="btn btn-outline-primary btn-sm">ดาวน์โหลด Template</a>
    </div>
</div>

<div class="branch-history-card p-3 mb-3">
    <form method="get" class="row g-2 align-items-end">
        <div class="col-md-5">
            <label class="form-label fw-bold">Keyword</label>
            <input type="text" name="keyword" class="form-control" value="<?php echo branchImportE($keyword); ?>" placeholder="Batch, ไฟล์, ผู้อัปโหลด">
        </div>
        <div class="col-md-3">
            <label class="form-label fw-bold">สถานะ</label>
            <select name="status" class="form-select">
                <option value="">ทั้งหมด</option>
                <?php foreach (['validated', 'imported', 'failed', 'cancelled'] as $item): ?>
                    <option value="<?php echo branchImportE($item); ?>" <?php echo $status === $item ? 'selected' : ''; ?>><?php echo branchImportE($item); ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-4 d-flex gap-2">
            <button class="btn btn-primary" type="submit">ค้นหา</button>
            <a href="history.php" class="btn btn-outline-secondary">ล้างค่า</a>
        </div>
    </form>
</div>

<div class="branch-history-card p-3">
    <div class="d-flex justify-content-between align-items-center mb-2">
        <div class="fw-bold">รายการ Batch</div>
        <div class="small text-muted">ทั้งหมด <?php echo number_format($totalRows); ?> รายการ</div>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-bordered branch-history-table mb-0">
            <thead class="table-light">
                <tr>
                    <th>Batch</th>
                    <th>เดือนข้อมูล</th>
                    <th>ไฟล์</th>
                    <th>ทั้งหมด</th>
                    <th>เพิ่มใหม่</th>
                    <th>อัปเดต</th>
                    <th>ไม่เปลี่ยนแปลง</th>
                    <th>Error</th>
                    <th>สถานะ</th>
                    <th>ผู้อัปโหลด</th>
                    <th>วันที่อัปโหลด</th>
                    <th>ผู้นำเข้า</th>
                    <th>วันที่นำเข้า</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($batches)): ?>
                    <tr><td colspan="13" class="text-center text-muted py-4">ไม่พบข้อมูล</td></tr>
                <?php else: ?>
                    <?php foreach ($batches as $batch): ?>
                        <tr>
                            <td><a href="preview.php?batch_id=<?php echo (int)$batch['id']; ?>" class="fw-bold"><?php echo branchImportE($batch['batch_no']); ?></a></td>
                            <td><?php echo branchImportE($batch['import_month']); ?></td>
                            <td><?php echo branchImportE($batch['original_filename']); ?></td>
                            <td><?php echo number_format((int)$batch['total_rows']); ?></td>
                            <td class="text-success fw-bold"><?php echo number_format((int)$batch['new_rows']); ?></td>
                            <td class="text-primary fw-bold"><?php echo number_format((int)$batch['updated_rows']); ?></td>
                            <td><?php echo number_format((int)$batch['unchanged_rows']); ?></td>
                            <td class="text-danger fw-bold"><?php echo number_format((int)$batch['error_rows']); ?></td>
                            <td><?php echo branchImportStatusBadge((string)$batch['status']); ?></td>
                            <td><?php echo branchImportE($batch['uploaded_by']); ?></td>
                            <td><?php echo branchImportE($batch['uploaded_at']); ?></td>
                            <td><?php echo branchImportE($batch['imported_by']); ?></td>
                            <td><?php echo branchImportE($batch['imported_at']); ?></td>
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
                        <a class="page-link" href="history.php?keyword=<?php echo urlencode($keyword); ?>&status=<?php echo urlencode($status); ?>&page=<?php echo $i; ?>"><?php echo $i; ?></a>
                    </li>
                <?php endfor; ?>
            </ul>
        </nav>
    <?php endif; ?>
</div>

<?php require_once __DIR__ . '/../../../includes/footer.php'; ?>
