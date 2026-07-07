<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';

function getPdoConnection(): PDO
{
    if (isset($GLOBALS['pdo']) && $GLOBALS['pdo'] instanceof PDO) {
        return $GLOBALS['pdo'];
    }

    if (isset($GLOBALS['conn']) && $GLOBALS['conn'] instanceof PDO) {
        return $GLOBALS['conn'];
    }

    if (isset($GLOBALS['db']) && $GLOBALS['db'] instanceof PDO) {
        return $GLOBALS['db'];
    }

    if (function_exists('getConnection')) {
        $connection = getConnection();
        if ($connection instanceof PDO) {
            return $connection;
        }
    }

    throw new Exception('ไม่พบการเชื่อมต่อฐานข้อมูล PDO');
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->prepare("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([
        ':table_name' => $tableName
    ]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function cleanValue($value): string
{
    return trim((string)($value ?? ''));
}

function formatMainBranchCode($value): string
{
    $value = cleanValue($value);

    if ($value === '') {
        return '';
    }

    if (is_numeric($value)) {
        $value = (string)(int)$value;
    }

    if (ctype_digit($value) && strlen($value) < 3) {
        return str_pad($value, 3, '0', STR_PAD_LEFT);
    }

    return $value;
}

function getCurrentUserName(): string
{
    $keys = [
        'fullname',
        'full_name',
        'name',
        'username',
        'user_name',
        'login_name'
    ];

    foreach ($keys as $key) {
        if (!empty($_SESSION[$key])) {
            return cleanValue($_SESSION[$key]);
        }
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        foreach ($keys as $key) {
            if (!empty($_SESSION['user'][$key])) {
                return cleanValue($_SESSION['user'][$key]);
            }
        }
    }

    return 'IT';
}

function generateRequestNo(PDO $pdo, array $columns): string
{
    $prefix = 'HDD' . date('Ymd');

    if (!hasColumn($columns, 'request_no')) {
        return $prefix . '0001';
    }

    $stmt = $pdo->prepare("
        SELECT request_no
        FROM harddisk_delivery_requests
        WHERE request_no LIKE :prefix
        ORDER BY request_no DESC
        LIMIT 1
    ");
    $stmt->execute([
        ':prefix' => $prefix . '%'
    ]);

    $lastRequestNo = (string)($stmt->fetchColumn() ?: '');

    if ($lastRequestNo === '') {
        return $prefix . '0001';
    }

    $lastRunning = (int)substr($lastRequestNo, -4);
    $newRunning = $lastRunning + 1;

    return $prefix . str_pad((string)$newRunning, 4, '0', STR_PAD_LEFT);
}

function redirectWithError(string $message): void
{
    header('Location: create.php?error=1&message=' . urlencode($message));
    exit;
}

try {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        header('Location: create.php');
        exit;
    }

    $pdo = getPdoConnection();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $mainBranchCode = formatMainBranchCode($_POST['main_branch_code'] ?? '');
    $branchCode = cleanValue($_POST['branch_code'] ?? '');
    $branchName = cleanValue($_POST['branch_name'] ?? '');
    $requestReason = cleanValue($_POST['request_reason'] ?? '');
    $remark = cleanValue($_POST['remark'] ?? '');

    if ($mainBranchCode === '' || !preg_match('/^\d{3}$/', $mainBranchCode)) {
        redirectWithError('กรุณากรอกรหัสสาขาใหญ่ให้ถูกต้อง');
    }

    if ($branchCode === '') {
        redirectWithError('กรุณาเลือกสาขาก่อนบันทึกคำขอ');
    }

    if ($requestReason === '') {
        redirectWithError('กรุณาระบุสาเหตุที่ต้องส่ง HDD');
    }

    $requestColumns = getTableColumns($pdo, 'harddisk_delivery_requests');

    if (!hasColumn($requestColumns, 'branch_code')) {
        redirectWithError('ตาราง harddisk_delivery_requests ไม่มีคอลัมน์ branch_code');
    }

    $pdo->beginTransaction();

    /*
     * ยืนยันข้อมูลสาขาจาก branch_directory ด้วย Cost Center / branch_code
     */
    $stmtBranch = $pdo->prepare("
        SELECT
            main_branch_code,
            branch_code,
            branch_name
        FROM branch_directory
        WHERE branch_code = :branch_code
          AND LPAD(main_branch_code, 3, '0') = :main_branch_code
          AND is_active = 1
        LIMIT 1
    ");
    $stmtBranch->execute([
        ':branch_code' => $branchCode,
        ':main_branch_code' => $mainBranchCode
    ]);

    $branch = $stmtBranch->fetch(PDO::FETCH_ASSOC);

    if (!$branch) {
        throw new Exception('ไม่พบข้อมูลสาขาที่เลือกใน branch_directory');
    }

    $branchCode = cleanValue($branch['branch_code']);
    $branchName = cleanValue($branch['branch_name']);
    $mainBranchCode = formatMainBranchCode($branch['main_branch_code']);

    /*
     * ตรวจซ้ำจาก Cost Center เท่านั้น
     */
    $duplicateWhere = [
        'branch_code = :branch_code'
    ];

    if (hasColumn($requestColumns, 'deleted_at')) {
        $duplicateWhere[] = 'deleted_at IS NULL';
    }

    if (hasColumn($requestColumns, 'status')) {
        $duplicateWhere[] = "status NOT IN ('cancelled', 'rejected')";
    }

    $duplicateSelectColumns = ['id'];

    foreach (['request_no', 'branch_code', 'branch_name', 'status'] as $column) {
        if (hasColumn($requestColumns, $column)) {
            $duplicateSelectColumns[] = $column;
        }
    }

    $stmtDuplicate = $pdo->prepare("
        SELECT " . implode(', ', $duplicateSelectColumns) . "
        FROM harddisk_delivery_requests
        WHERE " . implode(' AND ', $duplicateWhere) . "
        ORDER BY id DESC
        LIMIT 1
        FOR UPDATE
    ");
    $stmtDuplicate->execute([
        ':branch_code' => $branchCode
    ]);

    $duplicate = $stmtDuplicate->fetch(PDO::FETCH_ASSOC);

    if ($duplicate) {
        throw new Exception(
            'พบรายการซ้ำของ Cost Center นี้ เลขที่คำขอ: '
            . ($duplicate['request_no'] ?? '-')
        );
    }

    $requestNo = generateRequestNo($pdo, $requestColumns);
    $currentUser = getCurrentUserName();

    /*
     * บันทึกคำขอเท่านั้น
     * ยังไม่ตัด Stock HDD
     */
    $insertData = [];

    if (hasColumn($requestColumns, 'request_no')) {
        $insertData['request_no'] = $requestNo;
    }

    if (hasColumn($requestColumns, 'main_branch_code')) {
        $insertData['main_branch_code'] = $mainBranchCode;
    }

    if (hasColumn($requestColumns, 'branch_code')) {
        $insertData['branch_code'] = $branchCode;
    }

    if (hasColumn($requestColumns, 'branch_name')) {
        $insertData['branch_name'] = $branchName;
    }

    if (hasColumn($requestColumns, 'request_reason')) {
        $insertData['request_reason'] = $requestReason;
    }

    if (hasColumn($requestColumns, 'status')) {
        $insertData['status'] = 'pending_scan';
    }

    if (hasColumn($requestColumns, 'remark')) {
        $insertData['remark'] = $remark;
    }

    if (hasColumn($requestColumns, 'created_by')) {
        $insertData['created_by'] = $currentUser;
    }

    if (hasColumn($requestColumns, 'requested_by')) {
        $insertData['requested_by'] = $currentUser;
    }

    if (hasColumn($requestColumns, 'hdd_inventory_id')) {
        $insertData['hdd_inventory_id'] = null;
    }

    if (hasColumn($requestColumns, 'hdd_serial')) {
        $insertData['hdd_serial'] = null;
    }

    if (hasColumn($requestColumns, 'created_at')) {
        $insertData['created_at'] = date('Y-m-d H:i:s');
    }

    if (hasColumn($requestColumns, 'updated_at')) {
        $insertData['updated_at'] = date('Y-m-d H:i:s');
    }

    if (empty($insertData)) {
        throw new Exception('ไม่พบคอลัมน์สำหรับบันทึกข้อมูลใน harddisk_delivery_requests');
    }

    $columns = array_keys($insertData);
    $placeholders = array_map(function ($column) {
        return ':' . $column;
    }, $columns);

    $stmtInsert = $pdo->prepare("
        INSERT INTO harddisk_delivery_requests
        (" . implode(', ', $columns) . ")
        VALUES
        (" . implode(', ', $placeholders) . ")
    ");

    foreach ($insertData as $column => $value) {
        $stmtInsert->bindValue(':' . $column, $value);
    }

    $stmtInsert->execute();

    $requestId = (int)$pdo->lastInsertId();

    $pdo->commit();

    /*
     * บันทึกแล้วส่งไปหน้า ยิงบาร์โค้ด HDD ทันที
     */
    header('Location: assign_hdd.php?request_id=' . $requestId . '&created=1');
    exit;

} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }

    redirectWithError($e->getMessage());
}