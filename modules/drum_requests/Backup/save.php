<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$drumSaveCompleted = false;
register_shutdown_function(static function () use (&$drumSaveCompleted): void {
    if ($drumSaveCompleted) {
        return;
    }
    $lastError = error_get_last();
    if (!$lastError || !in_array((int)$lastError['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
        return;
    }
    error_log('[drum_withdrawals/save][fatal] ' . ($lastError['message'] ?? 'Unknown fatal error') . ' in ' . ($lastError['file'] ?? '-') . ':' . ($lastError['line'] ?? 0));
    if (!headers_sent()) {
        $_SESSION['drum_error'] = 'ไม่สามารถบันทึกข้อมูล Drum ได้ เนื่องจากเกิดข้อผิดพลาดภายในระบบ กรุณาตรวจสอบ PHP/Apache error log';
        header('Location: index.php');
    }
});

try {
    require_once __DIR__ . '/../../config/database.php';

    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        $drumSaveCompleted = true;
        header('Location: index.php');
        exit;
    }

    if (!isset($_SESSION['csrf_drum']) || !hash_equals((string)$_SESSION['csrf_drum'], (string)($_POST['csrf_token'] ?? ''))) {
        throw new RuntimeException('Session หมดอายุ กรุณาลองใหม่');
    }

    $branchCode = trim((string)($_POST['main_branch_code'] ?? ''));
    $selectedBranchCode = trim((string)($_POST['branch_code'] ?? ''));
    $branchName = trim((string)($_POST['branch_name'] ?? ''));
    if (preg_match('/^\d{1,3}$/', $branchCode)) {
        $branchCode = str_pad($branchCode, 3, '0', STR_PAD_LEFT);
    }

    $allowedDrums = ['Drum-DR-3455', 'Drum-DR-3608'];
    $postedDrums = $_POST['drum_codes'] ?? [];
    $postedDrums = is_array($postedDrums) ? $postedDrums : [];
    $drums = [];
    foreach ($postedDrums as $drum) {
        $drum = trim((string)$drum);
        if ($drum !== '' && in_array($drum, $allowedDrums, true)) {
            $drums[] = $drum;
        }
    }
    $legacyCounts = array_count_values($drums);
    $uniqueDrums = array_values(array_unique($drums));

    $postedQuantities = $_POST['drum_quantities'] ?? [];
    $postedQuantities = is_array($postedQuantities) ? $postedQuantities : [];
    $drumQuantities = [];
    foreach ($uniqueDrums as $drum) {
        $quantity = array_key_exists($drum, $postedQuantities)
            ? (int)$postedQuantities[$drum]
            : (int)($legacyCounts[$drum] ?? 1);
        $drumQuantities[$drum] = max(1, min(99, $quantity));
    }

    $cutText = static function (string $value, int $maxLength): string {
        $value = trim($value);
        if ($value === '') return '';
        if (function_exists('mb_substr')) {
            return (string)mb_substr($value, 0, $maxLength, 'UTF-8');
        }
        return substr($value, 0, $maxLength);
    };
    $textLength = static function (string $value): int {
        return function_exists('mb_strlen') ? (int)mb_strlen($value, 'UTF-8') : strlen($value);
    };

    $repeatReason = $cutText((string)($_POST['repeat_reason'] ?? ''), 500);
    $problemNo = $cutText((string)($_POST['problem_no'] ?? ''), 100);
    $remark = $cutText((string)($_POST['remark'] ?? ''), 500);

    $fullName = trim((string)($_SESSION['full_name'] ?? ''));
    if ($fullName === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $fullName = trim((string)($_SESSION['user']['full_name'] ?? ''));
    }
    $employeeCode = trim((string)($_SESSION['employee_code'] ?? $_SESSION['emp_code'] ?? ''));
    if ($employeeCode === '' && !empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $employeeCode = trim((string)($_SESSION['user']['employee_code'] ?? $_SESSION['user']['emp_code'] ?? ''));
    }
    if ($fullName === '') {
        $fullName = $employeeCode;
    }

    if ($branchCode === '' || $selectedBranchCode === '' || $branchName === '' || !$uniqueDrums || $fullName === '' || $problemNo === '') {
        throw new RuntimeException('กรุณากรอกข้อมูลให้ครบถ้วน');
    }

    if (!isset($pdo) || !($pdo instanceof PDO)) {
        throw new RuntimeException('ไม่พบการเชื่อมต่อฐานข้อมูล PDO');
    }

    $check = $pdo->prepare("SELECT main_branch_code, branch_code, branch_name, branch_name_2 FROM harddisk_db.branch_directory WHERE LPAD(TRIM(CAST(main_branch_code AS CHAR)), 3, '0') = :main_code AND TRIM(CAST(branch_code AS CHAR)) = :branch_code LIMIT 1");
    $check->execute([':main_code' => $branchCode, ':branch_code' => $selectedBranchCode]);
    $branch = $check->fetch(PDO::FETCH_ASSOC);
    if (!$branch) {
        throw new RuntimeException('ไม่พบสาขาปลายทางภายใต้รหัสสาขาใหญ่ที่เลือก');
    }
    $verifiedName = trim((string)($branch['branch_name'] ?? ''));
    if ($verifiedName === '') {
        $verifiedName = trim((string)($branch['branch_name_2'] ?? ''));
    }

    $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
    $columnsStmt->execute();
    $drumColumns = array_map('strtolower', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));
    if (!$drumColumns) {
        throw new RuntimeException('ไม่พบตาราง drum_withdrawals ในฐานข้อมูล harddisk_db');
    }

    $requiredColumns = ['request_no', 'main_branch_code', 'branch_name', 'drum_code', 'recorded_by', 'created_at', 'delivery_status', 'problem_no', 'remark', 'quantity'];
    $missingColumns = array_values(array_diff($requiredColumns, $drumColumns));
    if ($missingColumns) {
        throw new RuntimeException('ตาราง drum_withdrawals ขาดคอลัมน์: ' . implode(', ', $missingColumns));
    }

    $hasBranchCodeColumn = in_array('branch_code', $drumColumns, true);
    $hasEmployeeCodeColumn = in_array('recorded_by_employee_code', $drumColumns, true);
    $hasRepeatReasonColumn = in_array('repeat_reason', $drumColumns, true);
    $hasDeletedAtColumn = in_array('deleted_at', $drumColumns, true);

    $duplicatePlaceholders = implode(',', array_fill(0, count($uniqueDrums), '?'));
    $duplicateDeletedCondition = $hasDeletedAtColumn ? ' AND dw.deleted_at IS NULL' : '';
    if ($hasBranchCodeColumn) {
        $duplicateBranchCondition = 'TRIM(CAST(dw.branch_code AS CHAR)) = ?';
    } else {
        $duplicateBranchCondition = "EXISTS (
            SELECT 1 FROM harddisk_db.branch_directory bd_dup
            WHERE TRIM(CAST(bd_dup.branch_code AS CHAR)) = ?
              AND LPAD(TRIM(CAST(bd_dup.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(dw.main_branch_code AS CHAR)), 3, '0')
              AND (TRIM(COALESCE(bd_dup.branch_name, '')) = TRIM(COALESCE(dw.branch_name, ''))
                   OR TRIM(COALESCE(bd_dup.branch_name_2, '')) = TRIM(COALESCE(dw.branch_name, '')))
        )";
    }

    $duplicateSql = "SELECT dw.drum_code, dw.request_no, MAX(dw.created_at) AS latest_created_at
        FROM harddisk_db.drum_withdrawals dw
        WHERE COALESCE(dw.delivery_status, 'pending') IN ('pending', 'shipped')
          AND {$duplicateBranchCondition}
          AND dw.drum_code IN ({$duplicatePlaceholders})
          {$duplicateDeletedCondition}
        GROUP BY dw.drum_code, dw.request_no
        ORDER BY latest_created_at DESC";
    $duplicateStmt = $pdo->prepare($duplicateSql);
    $duplicateStmt->execute(array_merge([$selectedBranchCode], $uniqueDrums));
    $duplicateRows = $duplicateStmt->fetchAll(PDO::FETCH_ASSOC);
    $hasDuplicate = !empty($duplicateRows);

    if ($hasDuplicate && $textLength($repeatReason) < 5) {
        throw new RuntimeException('พบรายการเบิกหรือประวัติการจัดส่ง Drum ซ้ำ กรุณาระบุเหตุผลในการส่งซ้ำอย่างน้อย 5 ตัวอักษร');
    }
    if ($hasDuplicate && !$hasRepeatReasonColumn) {
        throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ repeat_reason กรุณารันไฟล์ database/add_drum_repeat_reason.sql');
    }

    $insertColumns = ['request_no', 'main_branch_code'];
    $insertValues = [':request_no', ':main_branch_code'];
    if ($hasBranchCodeColumn) {
        $insertColumns[] = 'branch_code';
        $insertValues[] = ':branch_code';
    }
    $insertColumns = array_merge($insertColumns, ['branch_name', 'drum_code', 'quantity', 'recorded_by', 'problem_no', 'remark']);
    $insertValues = array_merge($insertValues, [':branch_name', ':drum_code', ':quantity', ':recorded_by', ':problem_no', ':remark']);
    if ($hasEmployeeCodeColumn) {
        $insertColumns[] = 'recorded_by_employee_code';
        $insertValues[] = ':employee_code';
    }
    if ($hasRepeatReasonColumn) {
        $insertColumns[] = 'repeat_reason';
        $insertValues[] = ':repeat_reason';
    }
    $insertColumns[] = 'delivery_status';
    $insertValues[] = ':delivery_status';
    $insertColumns[] = 'created_at';
    $insertValues[] = 'NOW()';

    $requestNo = 'DR' . date('YmdHis') . random_int(10, 99);
    $insertSql = 'INSERT INTO harddisk_db.drum_withdrawals (`' . implode('`,`', $insertColumns) . '`) VALUES (' . implode(',', $insertValues) . ')';
    $stmt = $pdo->prepare($insertSql);

    $pdo->beginTransaction();
    foreach ($uniqueDrums as $drum) {
        $params = [
            ':request_no' => $requestNo,
            ':main_branch_code' => $branchCode,
            ':branch_name' => $verifiedName,
            ':drum_code' => $drum,
            ':quantity' => $drumQuantities[$drum],
            ':recorded_by' => $fullName,
            ':problem_no' => $problemNo,
            ':remark' => $remark !== '' ? $remark : null,
            ':delivery_status' => 'pending',
        ];
        if ($hasBranchCodeColumn) {
            $params[':branch_code'] = $selectedBranchCode;
        }
        if ($hasEmployeeCodeColumn) {
            $params[':employee_code'] = $employeeCode !== '' ? $employeeCode : null;
        }
        if ($hasRepeatReasonColumn) {
            $params[':repeat_reason'] = $hasDuplicate ? $repeatReason : null;
        }
        $stmt->execute($params);
    }
    $pdo->commit();

    $_SESSION['drum_success'] = 'บันทึกการเบิก Drum สำเร็จ เลขที่ ' . $requestNo;
    $drumSaveCompleted = true;
    header('Location: index.php');
    exit;
} catch (Throwable $e) {
    if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    error_log('[drum_withdrawals/save] ' . get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
    $_SESSION['drum_error'] = $e instanceof RuntimeException
        ? $e->getMessage()
        : 'ไม่สามารถบันทึกข้อมูล Drum ได้ กรุณาตรวจสอบ Apache/PHP error log';
    $drumSaveCompleted = true;
    if (!headers_sent()) {
        header('Location: index.php');
        exit;
    }
    echo 'ไม่สามารถบันทึกข้อมูล Drum ได้';
}
