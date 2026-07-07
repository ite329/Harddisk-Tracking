<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/permissions.php';

if (function_exists('require_login')) {
    require_login();
}

if (!function_exists('can_edit_hdd_request') || !can_edit_hdd_request()) {
    http_response_code(403);
    exit('ไม่มีสิทธิ์แก้ไขข้อมูล');
}

$pageTitle = 'แก้ไขคำขอส่ง HDD';

function h($value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([':table_name' => $tableName]);

    return (int)$stmt->fetchColumn() > 0;
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    if (!tableExists($pdo, $tableName)) {
        return [];
    }

    $stmt = $pdo->prepare("\n        SELECT COLUMN_NAME\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([':table_name' => $tableName]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function formatMainBranchCode($value): string
{
    $value = trim((string)($value ?? ''));

    if ($value === '') {
        return '';
    }

    $value = preg_replace('/[^0-9]/', '', $value);

    if ($value !== '' && strlen($value) < 3) {
        $value = str_pad($value, 3, '0', STR_PAD_LEFT);
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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errorMessage = '';
$row = null;
$columns = [];
$inventoryRows = [];
$inventoryColumns = [];

$requestReasonOptions = [
    'HDD เสีย' => 'HDD เสีย',
    'เครื่องบันทึกไม่เห็น HDD' => 'เครื่องบันทึกไม่เห็น HDD',
    'เปลี่ยนทดแทนของเดิม' => 'เปลี่ยนทดแทนของเดิม',
    'ส่งสำรองให้สาขา' => 'ส่งสำรองให้สาขา',
    'อื่น ๆ' => 'อื่น ๆ',
];

$statusOptions = [
    'pending_scan' => 'รอยิงบาร์โค้ด',
    'pending' => 'รอดำเนินการ',
    'approved' => 'อนุมัติแล้ว',
    'matched' => 'รอยืนยันจัดส่ง',
    'reserved' => 'จับคู่ HDD แล้ว',
    'shipped' => 'จัดส่งแล้ว',
    'received' => 'รับแล้ว',
    'installed' => 'ติดตั้งแล้ว',
    'cancelled' => 'ยกเลิก',
    'rejected' => 'ไม่อนุมัติ',
    'completed' => 'เสร็จสิ้น',
];

try {
    if ($id <= 0) {
        throw new Exception('ไม่พบรหัสรายการที่ต้องการแก้ไข');
    }

    $columns = getTableColumns($pdo, 'harddisk_delivery_requests');

    if (empty($columns)) {
        throw new Exception('ไม่พบตาราง harddisk_delivery_requests');
    }

    $where = 'id = :id';
    if (hasColumn($columns, 'deleted_at')) {
        $where .= ' AND deleted_at IS NULL';
    }

    $stmt = $pdo->prepare("\n        SELECT *\n        FROM harddisk_delivery_requests\n        WHERE {$where}\n        LIMIT 1\n    ");
    $stmt->execute([':id' => $id]);

    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        throw new Exception('ไม่พบรายการคำขอส่ง HDD');
    }

    $currentReason = trim((string)($row['request_reason'] ?? ''));
    if ($currentReason !== '' && !array_key_exists($currentReason, $requestReasonOptions)) {
        $requestReasonOptions[$currentReason] = $currentReason;
    }

    $currentStatus = trim((string)($row['status'] ?? ''));
    if ($currentStatus !== '' && !array_key_exists($currentStatus, $statusOptions)) {
        $statusOptions[$currentStatus] = $currentStatus;
    }

    if (tableExists($pdo, 'harddisk_inventory')) {
        $inventoryColumns = getTableColumns($pdo, 'harddisk_inventory');
        $currentSerial = trim((string)($row['hdd_serial'] ?? ''));
        $currentInventoryId = (int)($row['hdd_inventory_id'] ?? 0);

        if (hasColumn($inventoryColumns, 'hdd_serial')) {
            $selectColumns = ['id', 'hdd_serial'];
            foreach (['status', 'scanned_by', 'created_by', 'created_at'] as $col) {
                if (hasColumn($inventoryColumns, $col)) {
                    $selectColumns[] = $col;
                }
            }

            $invWhere = [];
            $invParams = [];

            if (hasColumn($inventoryColumns, 'deleted_at')) {
                $invWhere[] = 'deleted_at IS NULL';
            }

            $statusParts = [];
            if (hasColumn($inventoryColumns, 'status')) {
                $statusParts[] = "status = 'available'";
            }

            if ($currentSerial !== '') {
                $statusParts[] = 'BINARY hdd_serial = :current_serial';
                $invParams[':current_serial'] = $currentSerial;
            }

            if ($currentInventoryId > 0 && hasColumn($inventoryColumns, 'id')) {
                $statusParts[] = 'id = :current_inventory_id';
                $invParams[':current_inventory_id'] = $currentInventoryId;
            }

            if (!empty($statusParts)) {
                $invWhere[] = '(' . implode(' OR ', $statusParts) . ')';
            }

            $invWhereSql = '';
            if (!empty($invWhere)) {
                $invWhereSql = 'WHERE ' . implode(' AND ', $invWhere);
            }

            $stmtInv = $pdo->prepare("\n                SELECT " . implode(', ', $selectColumns) . "\n                FROM harddisk_inventory\n                {$invWhereSql}\n                ORDER BY\n                    CASE\n                        WHEN hdd_serial = :order_current_serial THEN 0\n                        WHEN " . (hasColumn($inventoryColumns, 'status') ? "status = 'available'" : '1=1') . " THEN 1\n                        ELSE 2\n                    END,\n                    hdd_serial ASC\n            ");

            foreach ($invParams as $key => $value) {
                $stmtInv->bindValue($key, $value);
            }
            $stmtInv->bindValue(':order_current_serial', $currentSerial);
            $stmtInv->execute();
            $inventoryRows = $stmtInv->fetchAll(PDO::FETCH_ASSOC);
        }
    }
} catch (Throwable $e) {
    $errorMessage = $e->getMessage();
}

$currentMainBranchCode = $row ? formatMainBranchCode($row['main_branch_code'] ?? '') : '';
$currentBranchCode = $row ? trim((string)($row['branch_code'] ?? '')) : '';
$currentBranchName = $row ? trim((string)($row['branch_name'] ?? '')) : '';
$currentHddSerial = $row ? trim((string)($row['hdd_serial'] ?? '')) : '';
$currentHddInventoryId = $row ? (int)($row['hdd_inventory_id'] ?? 0) : 0;

$serialFoundInOptions = false;
foreach ($inventoryRows as $invRow) {
    if (trim((string)($invRow['hdd_serial'] ?? '')) === $currentHddSerial) {
        $serialFoundInOptions = true;
        break;
    }
}

$hasHeader = file_exists(__DIR__ . '/../../includes/header.php');
$hasFooter = file_exists(__DIR__ . '/../../includes/footer.php');

if ($hasHeader) {
    include __DIR__ . '/../../includes/header.php';
} else {
    ?>
    <!doctype html>
    <html lang="th">
    <head>
        <meta charset="utf-8">
        <title><?php echo h($pageTitle); ?></title>
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    </head>
    <body class="bg-light">
    <?php
}
?>

<style>
    .edit-page-wrap {
        padding: 18px;
        background: #f3f6fb;
        min-height: calc(100vh - 60px);
    }

    .edit-card {
        border: 0;
        border-radius: 16px;
        box-shadow: 0 8px 24px rgba(15, 23, 42, 0.08);
    }

    .edit-page-title {
        font-weight: 800;
        color: #0f172a;
    }

    .readonly-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 10px;
        padding: 9px 12px;
        min-height: 40px;
        font-weight: 700;
    }

    .sync-panel-title {
        font-weight: 800;
        color: #0f172a;
    }

    .branch-info-box {
        background: #f8fafc;
        border: 1px solid #e2e8f0;
        border-radius: 12px;
        padding: 12px;
    }

    .compact-help {
        font-size: 12px;
        color: #64748b;
    }
</style>

<div class="edit-page-wrap">
    <div class="container-fluid">

        <div class="d-flex flex-wrap justify-content-between align-items-center mb-3 gap-2">
            <div>
                <h3 class="edit-page-title mb-1">แก้ไขคำขอส่ง HDD</h3>
                <div class="text-muted small">
                    ซิงค์ข้อมูลกับหน้าบันทึกคำขอส่ง Harddisk และคลัง Harddisk
                </div>
            </div>

            <a href="index.php" class="btn btn-outline-secondary">
                กลับหน้ารายการ
            </a>
        </div>

        <?php if (isset($_GET['updated'])): ?>
            <div class="alert alert-success">
                บันทึกข้อมูลที่แก้ไขเรียบร้อยแล้ว
            </div>
        <?php endif; ?>

        <?php if (isset($_GET['edit_error'])): ?>
            <div class="alert alert-danger">
                ไม่สามารถบันทึกข้อมูลได้ กรุณาตรวจสอบข้อมูลอีกครั้ง
            </div>
        <?php endif; ?>

        <?php if ($errorMessage !== ''): ?>
            <div class="alert alert-danger">
                <strong>เกิดข้อผิดพลาด:</strong> <?php echo h($errorMessage); ?>
            </div>
        <?php elseif ($row): ?>

            <form action="update.php" method="post" autocomplete="off" id="editRequestForm">
                <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                <?php if (hasColumn($columns, 'main_branch_code')): ?>
                    <input type="hidden" name="main_branch_code" id="main_branch_code" value="<?php echo h($currentMainBranchCode); ?>">
                <?php endif; ?>

                <?php if (hasColumn($columns, 'branch_code')): ?>
                    <input type="hidden" name="branch_code" id="branch_code" value="<?php echo h($currentBranchCode); ?>">
                <?php endif; ?>

                <?php if (hasColumn($columns, 'branch_name')): ?>
                    <input type="hidden" name="branch_name" id="branch_name" value="<?php echo h($currentBranchName); ?>">
                <?php endif; ?>

                <?php if (hasColumn($columns, 'hdd_inventory_id')): ?>
                    <input type="hidden" name="hdd_inventory_id" id="hdd_inventory_id" value="<?php echo (int)$currentHddInventoryId; ?>">
                <?php endif; ?>

                <div class="row g-3">
                    <div class="col-xl-4 col-lg-5">
                        <div class="card edit-card h-100">
                            <div class="card-header bg-white">
                                <div class="sync-panel-title">1) เลือกสาขาจากรหัสสาขาใหญ่</div>
                                <div class="compact-help">อ้างอิงวิธีค้นหาจากหน้า บันทึกคำขอส่ง Harddisk</div>
                            </div>
                            <div class="card-body">
                                <div class="mb-3">
                                    <label class="form-label">รหัสสาขาใหญ่</label>
                                    <div class="input-group">
                                        <input type="text"
                                               id="search_branch_code"
                                               class="form-control"
                                               value="<?php echo h($currentMainBranchCode); ?>"
                                               placeholder="เช่น 017"
                                               maxlength="3"
                                               autocomplete="off">
                                        <button type="button" class="btn btn-primary" id="btnSearchBranch">
                                            ค้นหา
                                        </button>
                                    </div>
                                    <div class="form-text">เมื่อค้นหาแล้ว ระบบจะดึงสาขาในสังกัดทั้งหมดมาให้เลือก</div>
                                </div>

                                <div id="branchSearchResult" class="d-none"></div>

                                <div class="mb-3">
                                    <label class="form-label">เลือกสาขาที่ต้องการแก้ไข</label>
                                    <select id="branch_select" class="form-select" disabled>
                                        <option value="">-- กดค้นหารหัสสาขาใหญ่ก่อน --</option>
                                    </select>
                                </div>

                                <div id="selectedBranchBox" class="branch-info-box">
                                    <div class="fw-bold mb-2">ข้อมูลสาขาที่เลือก</div>
                                    <div class="row small g-1">
                                        <div class="col-5 text-muted">รหัสสาขาใหญ่</div>
                                        <div class="col-7" id="show_main_branch_code"><?php echo h($currentMainBranchCode ?: '-'); ?></div>

                                        <div class="col-5 text-muted">Cost Center</div>
                                        <div class="col-7 fw-bold text-primary" id="show_branch_code"><?php echo h($currentBranchCode ?: '-'); ?></div>

                                        <div class="col-5 text-muted">ชื่อสาขา</div>
                                        <div class="col-7" id="show_branch_name"><?php echo h($currentBranchName ?: '-'); ?></div>

                                        <div class="col-5 text-muted">เบอร์โทร</div>
                                        <div class="col-7" id="show_phone">-</div>

                                        <div class="col-5 text-muted">ที่อยู่</div>
                                        <div class="col-7" id="show_address">-</div>

                                        <div class="col-5 text-muted">สถานที่ใกล้เคียง</div>
                                        <div class="col-7" id="show_landmark">-</div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-8 col-lg-7">
                        <div class="card edit-card">
                            <div class="card-header bg-white">
                                <div class="sync-panel-title">2) รายละเอียดคำขอส่ง HDD</div>
                                <div class="compact-help">Serial HDD อ้างอิงจากคลัง Harddisk / สาเหตุอ้างอิงจากหน้าบันทึกคำขอ</div>
                            </div>
                            <div class="card-body">

                                <div class="row g-3 mb-3">
                                    <?php if (hasColumn($columns, 'request_no')): ?>
                                        <div class="col-md-4">
                                            <label class="form-label">เลขที่คำขอ</label>
                                            <div class="readonly-box"><?php echo h($row['request_no'] ?? '-'); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (hasColumn($columns, 'created_at')): ?>
                                        <div class="col-md-4">
                                            <label class="form-label">วันที่บันทึก</label>
                                            <div class="readonly-box"><?php echo h(formatDateTimeThai($row['created_at'] ?? '')); ?></div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (hasColumn($columns, 'created_by') || hasColumn($columns, 'requested_by')): ?>
                                        <div class="col-md-4">
                                            <label class="form-label">ผู้บันทึกเดิม</label>
                                            <div class="readonly-box"><?php echo h($row['created_by'] ?? ($row['requested_by'] ?? '-')); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="row g-3">
                                    <?php if (hasColumn($columns, 'hdd_serial')): ?>
                                        <div class="col-md-6">
                                            <label class="form-label">Serial HDD จากคลัง Harddisk</label>
                                            <select name="hdd_serial" id="hdd_serial_select" class="form-select">
                                                <option value="" data-inventory-id="">-- ไม่ระบุ Serial HDD --</option>

                                                <?php if ($currentHddSerial !== '' && !$serialFoundInOptions): ?>
                                                    <option value="<?php echo h($currentHddSerial); ?>"
                                                            data-inventory-id="<?php echo (int)$currentHddInventoryId; ?>"
                                                            selected>
                                                        <?php echo h($currentHddSerial); ?> - Serial เดิมของรายการนี้
                                                    </option>
                                                <?php endif; ?>

                                                <?php foreach ($inventoryRows as $invRow): ?>
                                                    <?php
                                                    $serial = trim((string)($invRow['hdd_serial'] ?? ''));
                                                    $inventoryId = (int)($invRow['id'] ?? 0);
                                                    $statusText = inventoryStatusText($invRow['status'] ?? '');
                                                    $selected = ($serial !== '' && $serial === $currentHddSerial) || ($currentHddInventoryId > 0 && $inventoryId === $currentHddInventoryId);
                                                    ?>
                                                    <option value="<?php echo h($serial); ?>"
                                                            data-inventory-id="<?php echo $inventoryId; ?>"
                                                            <?php echo $selected ? 'selected' : ''; ?>>
                                                        <?php echo h($serial . ' - ' . $statusText); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="form-text">
                                                แสดง HDD สถานะพร้อมใช้งาน และ Serial เดิมของรายการนี้
                                            </div>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (hasColumn($columns, 'request_reason')): ?>
                                        <div class="col-md-6">
                                            <label class="form-label">สาเหตุที่ต้องส่ง HDD</label>
                                            <?php $selectedReason = trim((string)($row['request_reason'] ?? '')); ?>
                                            <select name="request_reason" class="form-select" required>
                                                <option value="">-- เลือกสาเหตุ --</option>
                                                <?php foreach ($requestReasonOptions as $value => $label): ?>
                                                    <option value="<?php echo h($value); ?>" <?php echo $selectedReason === $value ? 'selected' : ''; ?>>
                                                        <?php echo h($label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (hasColumn($columns, 'status')): ?>
                                        <div class="col-md-6">
                                            <label class="form-label">สถานะ</label>
                                            <?php $selectedStatus = trim((string)($row['status'] ?? '')); ?>
                                            <select name="status" class="form-select">
                                                <?php foreach ($statusOptions as $value => $label): ?>
                                                    <option value="<?php echo h($value); ?>" <?php echo $selectedStatus === $value ? 'selected' : ''; ?>>
                                                        <?php echo h($label); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    <?php endif; ?>

                                    <?php if (hasColumn($columns, 'remark')): ?>
                                        <div class="col-md-12">
                                            <label class="form-label">หมายเหตุ</label>
                                            <textarea name="remark" class="form-control" rows="4" placeholder="หมายเหตุเพิ่มเติม"><?php echo h($row['remark'] ?? ''); ?></textarea>
                                        </div>
                                    <?php endif; ?>
                                </div>

                                <div class="alert alert-info mt-3 mb-0">
                                    <strong>การซิงค์ข้อมูล:</strong>
                                    หากต้องการเปลี่ยนสาขา ให้ค้นหารหัสสาขาใหญ่ก่อน แล้วเลือกสาขาในสังกัด ระบบจะอัปเดต Cost Center และชื่อสาขาให้อัตโนมัติ
                                </div>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <a href="index.php" class="btn btn-outline-secondary">ยกเลิก</a>
                            <button type="submit" class="btn btn-primary" onclick="return confirm('ยืนยันการบันทึกข้อมูลที่แก้ไขหรือไม่?');">
                                บันทึกการแก้ไข
                            </button>
                        </div>
                    </div>
                </div>
            </form>

        <?php endif; ?>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const searchInput = document.getElementById('search_branch_code');
    const btnSearch = document.getElementById('btnSearchBranch');
    const branchSelect = document.getElementById('branch_select');
    const branchSearchResult = document.getElementById('branchSearchResult');

    const mainBranchCodeInput = document.getElementById('main_branch_code');
    const branchCodeInput = document.getElementById('branch_code');
    const branchNameInput = document.getElementById('branch_name');
    const hddInventoryIdInput = document.getElementById('hdd_inventory_id');
    const hddSerialSelect = document.getElementById('hdd_serial_select');
    const editRequestForm = document.getElementById('editRequestForm');

    const currentBranchCode = <?php echo json_encode($currentBranchCode, JSON_UNESCAPED_UNICODE); ?>;
    let branchData = [];

    function escapeHtml(value) {
        return String(value ?? '')
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function cleanText(value) {
        return String(value ?? '').trim();
    }

    function formatMainBranchCode(value) {
        value = cleanText(value).replace(/[^0-9]/g, '');

        if (value !== '' && value.length < 3) {
            value = value.padStart(3, '0');
        }

        return value;
    }

    function isValidMainBranchCode(value) {
        return /^\d{3}$/.test(cleanText(value));
    }

    function getBranchAddress(branch) {
        return branch.branch_address || branch.full_address || '';
    }

    function showSearchMessage(type, message) {
        if (!branchSearchResult) {
            return;
        }

        branchSearchResult.className = 'alert alert-' + type;
        branchSearchResult.innerHTML = message;
        branchSearchResult.classList.remove('d-none');
    }

    function setBranchToForm(branch) {
        const mainBranchCode = formatMainBranchCode(branch.main_branch_code || '');
        const costCenter = cleanText(branch.branch_code);
        const branchName = cleanText(branch.branch_name);

        if (mainBranchCodeInput) {
            mainBranchCodeInput.value = mainBranchCode;
        }

        if (branchCodeInput) {
            branchCodeInput.value = costCenter;
        }

        if (branchNameInput) {
            branchNameInput.value = branchName;
        }

        document.getElementById('show_main_branch_code').textContent = mainBranchCode || '-';
        document.getElementById('show_branch_code').textContent = costCenter || '-';
        document.getElementById('show_branch_name').textContent = branchName || '-';
        document.getElementById('show_phone').textContent = branch.phone || '-';
        document.getElementById('show_address').textContent = getBranchAddress(branch) || '-';
        document.getElementById('show_landmark').textContent = branch.landmark || '-';
    }

    function searchBranch(autoSelectCurrent) {
        const mainBranchCode = formatMainBranchCode(searchInput ? searchInput.value : '');

        if (!branchSelect) {
            return;
        }

        branchData = [];
        branchSelect.innerHTML = '<option value="">กำลังค้นหาข้อมูล...</option>';
        branchSelect.disabled = true;

        if (!isValidMainBranchCode(mainBranchCode)) {
            showSearchMessage('warning', 'กรุณากรอกรหัสสาขาใหญ่เป็นตัวเลข 3 หลัก เช่น 017, 088, 123');
            branchSelect.innerHTML = '<option value="">-- รหัสสาขาใหญ่ไม่ถูกต้อง --</option>';
            return;
        }

        const params = new URLSearchParams();
        params.append('main_branch_code', mainBranchCode);
        params.append('branch_code', mainBranchCode);

        fetch('/harddisk_delivery_web/api/get_branches.php?' + params.toString())
            .then(function (response) {
                return response.json();
            })
            .then(function (result) {
                branchSelect.innerHTML = '<option value="">-- เลือกสาขา --</option>';

                if (!result.success) {
                    showSearchMessage('danger', escapeHtml(result.message || 'ไม่สามารถค้นหาข้อมูลสาขาได้'));
                    branchSelect.disabled = true;
                    return;
                }

                const rows = Array.isArray(result.data) ? result.data : [];

                if (rows.length === 0) {
                    showSearchMessage('warning', 'ไม่พบข้อมูลสาขาภายใต้รหัสสาขาใหญ่ ' + escapeHtml(mainBranchCode));
                    branchSelect.disabled = true;
                    return;
                }

                branchData = rows;
                let currentIndex = '';

                rows.forEach(function (branch, index) {
                    const option = document.createElement('option');
                    const branchCode = cleanText(branch.branch_code || '');

                    option.value = String(index);
                    option.dataset.branchCode = branchCode;
                    option.textContent = branchCode + ' - ' + (branch.branch_name || '-');
                    branchSelect.appendChild(option);

                    if (branchCode !== '' && branchCode === currentBranchCode) {
                        currentIndex = String(index);
                    }
                });

                branchSelect.disabled = false;
                showSearchMessage('success', 'พบสาขาในสังกัดรหัสสาขาใหญ่ <strong>' + escapeHtml(mainBranchCode) + '</strong> จำนวน <strong>' + rows.length + '</strong> รายการ');

                if (autoSelectCurrent && currentIndex !== '') {
                    branchSelect.value = currentIndex;
                    setBranchToForm(branchData[Number(currentIndex)]);
                }
            })
            .catch(function () {
                showSearchMessage('danger', 'เกิดข้อผิดพลาดในการเชื่อมต่อ API ค้นหาสาขา');
                branchSelect.innerHTML = '<option value="">-- ไม่สามารถโหลดข้อมูลได้ --</option>';
                branchSelect.disabled = true;
            });
    }

    if (btnSearch) {
        btnSearch.addEventListener('click', function () {
            searchBranch(false);
        });
    }

    if (searchInput) {
        searchInput.addEventListener('keydown', function (event) {
            if (event.key === 'Enter') {
                event.preventDefault();
                searchBranch(false);
            }
        });

        searchInput.addEventListener('input', function () {
            this.value = this.value.replace(/[^0-9]/g, '').slice(0, 3);
        });
    }

    if (branchSelect) {
        branchSelect.addEventListener('change', function () {
            const selectedIndex = branchSelect.value;

            if (selectedIndex === '') {
                return;
            }

            const branch = branchData[Number(selectedIndex)];
            if (!branch) {
                return;
            }

            setBranchToForm(branch);
        });
    }

    if (hddSerialSelect) {
        hddSerialSelect.addEventListener('change', function () {
            const selectedOption = hddSerialSelect.options[hddSerialSelect.selectedIndex];
            const inventoryId = selectedOption ? selectedOption.getAttribute('data-inventory-id') : '';

            if (hddInventoryIdInput) {
                hddInventoryIdInput.value = inventoryId || '';
            }
        });
    }

    if (editRequestForm) {
        editRequestForm.addEventListener('submit', function (event) {
            if (branchCodeInput && cleanText(branchCodeInput.value) === '') {
                event.preventDefault();
                alert('กรุณาเลือกสาขาก่อนบันทึกข้อมูล');
                return false;
            }

            if (hddSerialSelect && cleanText(hddSerialSelect.value) !== '' && !/^[A-Za-z0-9]+$/.test(cleanText(hddSerialSelect.value))) {
                event.preventDefault();
                alert('Serial HDD ต้องเป็นตัวอักษรอังกฤษหรือตัวเลขเท่านั้น');
                return false;
            }
        });
    }

    if (searchInput && cleanText(searchInput.value) !== '') {
        searchBranch(true);
    }
});
</script>

<?php
if ($hasFooter) {
    include __DIR__ . '/../../includes/footer.php';
} else {
    ?>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
    </body>
    </html>
    <?php
}
?>
