<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
if (file_exists(__DIR__ . '/../../includes/functions.php')) {
    require_once __DIR__ . '/../../includes/functions.php';
}

if (function_exists('require_login')) {
    require_login();
}

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

function cleanText($value): string
{
    return trim((string)($value ?? ''));
}

function currentEmployeeCodeInventory(): string
{
    if (!empty($_SESSION['employee_code'])) {
        return cleanText($_SESSION['employee_code']);
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['employee_code'])) {
        return cleanText($_SESSION['user']['employee_code']);
    }

    return '';
}

function currentUserRoleInventory(): string
{
    if (!empty($_SESSION['role'])) {
        return strtolower(cleanText($_SESSION['role']));
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user']) && !empty($_SESSION['user']['role'])) {
        return strtolower(cleanText($_SESSION['user']['role']));
    }

    return '';
}

function canManageHddInventory(): bool
{
    $employeeCode = currentEmployeeCodeInventory();
    $role = currentUserRoleInventory();

    if ($employeeCode === '14329') {
        return true;
    }

    return in_array($role, ['admin', 'administrator', 'super_admin'], true);
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

function formatThaiDateTime($value): string
{
    $value = cleanText($value);
    if ($value === '' || $value === '0000-00-00' || $value === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($value);
    if ($timestamp === false) {
        return $value;
    }

    return date('d/m/Y H:i', $timestamp);
}

if (!canManageHddInventory()) {
    header('Location: index.php?error=permission_denied');
    exit;
}

if (!isset($pdo) || !$pdo instanceof PDO) {
    exit('ไม่พบการเชื่อมต่อฐานข้อมูล');
}

$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php?error=invalid_id');
    exit;
}

$inventoryColumns = getTableColumns($pdo, 'harddisk_inventory');
if (empty($inventoryColumns)) {
    exit('ไม่พบตาราง harddisk_inventory');
}

$where = ['id = :id'];
if (hasColumn($inventoryColumns, 'deleted_at')) {
    $where[] = 'deleted_at IS NULL';
}

$selectColumns = [];
foreach (['id', 'hdd_serial', 'status', 'scanned_by', 'scanned_at', 'received_from', 'received_at', 'remark', 'created_by', 'created_at', 'updated_at'] as $column) {
    if (hasColumn($inventoryColumns, $column)) {
        $selectColumns[] = $column;
    }
}

if (empty($selectColumns)) {
    $selectColumns[] = 'id';
}

$stmt = $pdo->prepare('SELECT ' . implode(', ', $selectColumns) . ' FROM harddisk_inventory WHERE ' . implode(' AND ', $where) . ' LIMIT 1');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$row) {
    header('Location: index.php?error=not_found');
    exit;
}

$statusOptions = [
    'available' => 'พร้อมใช้งาน',
    'reserved' => 'จองไว้',
    'shipped' => 'จัดส่งแล้ว',
    'used' => 'ใช้งานแล้ว',
    'damaged' => 'ชำรุด',
    'cancelled' => 'ยกเลิก',
];

$receivedFromOptions = [
    'IT Stock' => 'IT Stock',
    'เคลม' => 'เคลม',
];

$pageTitle = 'แก้ไข HDD ในคลัง';
require_once __DIR__ . '/../../includes/header.php';
?>

<style>
    body { background: #f3f6fb; }
    .inventory-edit-page { padding: 10px 0 16px 0; }
    .inventory-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .inventory-subtitle { font-size: 13px; color: #64748b; }
    .inventory-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .inventory-card .card-header { background: #fff; border-bottom: 1px solid #e5e7eb; font-weight: 900; padding: 12px 14px; }
    .inventory-card .card-body { padding: 16px; }
    .hero-card { border: 0; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #fff; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22); }
    .hero-card .card-body { padding: 12px 16px; }
    .form-label { font-size: 13px; font-weight: 800; color: #334155; margin-bottom: 4px; }
    .form-control, .form-select { border-radius: 10px; font-size: 13px; }
    .btn { border-radius: 10px; }
    .info-box { border: 1px solid #e2e8f0; background: #f8fafc; border-radius: 14px; padding: 12px; font-size: 13px; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-weight: 900; color: #7c2d12; }
</style>

<div class="container-fluid inventory-edit-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center mb-2 gap-2">
        <div>
            <h3 class="inventory-title">แก้ไขข้อมูล HDD ในคลัง</h3>
            <div class="inventory-subtitle">ปรับปรุง Serial, สถานะ, แหล่งที่มา และหมายเหตุของ HDD ในคลัง</div>
        </div>
        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">กลับหน้าคลัง HDD</a>
        </div>
    </div>

    <div class="card hero-card mb-2">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold">สิทธิ์แก้ไขคลัง HDD</div>
                <div class="small opacity-75">ผู้ใช้งานที่ได้รับสิทธิ์สามารถแก้ไขข้อมูล HDD ในคลังได้</div>
            </div>
            <div class="small">รหัสพนักงาน: <strong><?php echo h(currentEmployeeCodeInventory()); ?></strong></div>
        </div>
    </div>

    <div class="row g-2">
        <div class="col-lg-7">
            <div class="card inventory-card">
                <div class="card-header">ข้อมูลที่ต้องการแก้ไข</div>
                <div class="card-body">
                    <form method="post" action="update.php" autocomplete="off">
                        <input type="hidden" name="id" value="<?php echo (int)$row['id']; ?>">

                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label">Serial HDD <span class="text-danger">*</span></label>
                                <input type="text" name="hdd_serial" class="form-control" value="<?php echo h($row['hdd_serial'] ?? ''); ?>" required pattern="[A-Za-z0-9]+" autocomplete="off">
                                <div class="form-text">ใช้ตัวอักษรภาษาอังกฤษและตัวเลขเท่านั้น</div>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">สถานะ</label>
                                <select name="status" class="form-select">
                                    <?php $currentStatus = cleanText($row['status'] ?? 'available'); ?>
                                    <?php foreach ($statusOptions as $value => $label): ?>
                                        <option value="<?php echo h($value); ?>" <?php echo $currentStatus === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">รับมาจาก</label>
                                <select name="received_from" class="form-select">
                                    <?php $currentReceivedFrom = cleanText($row['received_from'] ?? 'IT Stock'); ?>
                                    <?php foreach ($receivedFromOptions as $value => $label): ?>
                                        <option value="<?php echo h($value); ?>" <?php echo $currentReceivedFrom === $value ? 'selected' : ''; ?>><?php echo h($label); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="col-md-6">
                                <label class="form-label">วันที่รับเข้า</label>
                                <input type="text" class="form-control" value="<?php echo h(formatThaiDateTime($row['received_at'] ?? ($row['created_at'] ?? ''))); ?>" disabled>
                            </div>

                            <div class="col-12">
                                <label class="form-label">หมายเหตุ</label>
                                <textarea name="remark" class="form-control" rows="4" placeholder="ระบุหมายเหตุเพิ่มเติม"><?php echo h($row['remark'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2 mt-3">
                            <a href="index.php" class="btn btn-outline-secondary">ยกเลิก</a>
                            <button type="submit" class="btn btn-primary">บันทึกการแก้ไข</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card inventory-card h-100">
                <div class="card-header">ข้อมูลเดิมของรายการ</div>
                <div class="card-body">
                    <div class="info-box mb-2">
                        <div class="text-muted small">Serial HDD</div>
                        <div class="serial-text fs-5"><?php echo h($row['hdd_serial'] ?? '-'); ?></div>
                    </div>
                    <div class="info-box mb-2">
                        <div class="text-muted small">ผู้สแกน / ผู้บันทึก</div>
                        <div><?php echo h(($row['scanned_by'] ?? '') !== '' ? $row['scanned_by'] : ($row['created_by'] ?? '-')); ?></div>
                    </div>
                    <div class="info-box mb-2">
                        <div class="text-muted small">วันที่สแกน</div>
                        <div><?php echo h(formatThaiDateTime($row['scanned_at'] ?? '')); ?></div>
                    </div>
                    <div class="info-box">
                        <div class="text-muted small">อัปเดตล่าสุด</div>
                        <div><?php echo h(formatThaiDateTime($row['updated_at'] ?? '')); ?></div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
