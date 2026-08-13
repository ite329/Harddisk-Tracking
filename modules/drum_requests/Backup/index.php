<?php
$pageTitle = 'บันทึกข้อมูลการเบิก Drum';
require_once __DIR__ . '/../../includes/header.php';
if (empty($_SESSION['csrf_drum'])) $_SESSION['csrf_drum'] = bin2hex(random_bytes(32));
$success = $_SESSION['drum_success'] ?? ''; unset($_SESSION['drum_success']);
$error = $_SESSION['drum_error'] ?? ''; unset($_SESSION['drum_error']);
$currentEmployeeCode = trim((string)($_SESSION['employee_code'] ?? $_SESSION['emp_code'] ?? ''));

if (!function_exists('drumCanManagePermission')) {
    function drumCanManagePermission(PDO $pdo, string $permissionCode): bool
    {
        if (function_exists('is_super_admin_employee') && is_super_admin_employee()) {
            return true;
        }
        if (function_exists('current_user_role') && current_user_role() === 'super_admin') {
            return true;
        }
        if (!function_exists('permission_tables_ready') || !permission_tables_ready($pdo) || !function_exists('central_permission_user_key')) {
            return false;
        }
        $userKey = trim((string)central_permission_user_key());
        if ($userKey === '') {
            return false;
        }
        try {
            $permissionStmt = $pdo->prepare("SELECT id FROM permissions WHERE permission_code = :code AND is_active = 1 LIMIT 1");
            $permissionStmt->execute([':code' => $permissionCode]);
            $permissionId = (int)$permissionStmt->fetchColumn();
            if ($permissionId <= 0) {
                return false;
            }
            $overrideStmt = $pdo->prepare("SELECT permission_type FROM user_permissions WHERE user_key = :user_key AND permission_id = :permission_id LIMIT 1");
            $overrideStmt->execute([':user_key' => $userKey, ':permission_id' => $permissionId]);
            $override = trim((string)($overrideStmt->fetchColumn() ?: ''));
            if ($override === 'deny') {
                return false;
            }
            if ($override === 'allow') {
                return true;
            }
            $roleStmt = $pdo->prepare("SELECT COUNT(*) FROM user_roles ur INNER JOIN roles r ON r.id = ur.role_id AND r.is_active = 1 INNER JOIN role_permissions rp ON rp.role_id = ur.role_id WHERE ur.user_key = :user_key AND rp.permission_id = :permission_id");
            $roleStmt->execute([':user_key' => $userKey, ':permission_id' => $permissionId]);
            return (int)$roleStmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            error_log('[drum_withdrawals/index] Permission check failed: ' . $e->getMessage());
            return false;
        }
    }
}
$canEditDrum = drumCanManagePermission($pdo, 'drum_requests.edit');
$canDeleteDrum = drumCanManagePermission($pdo, 'drum_requests.delete');
$canManageDrum = $canEditDrum || $canDeleteDrum;

$drumUserOptions = [];
$drumUserLoadError = '';
try {
    $userTableStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'users'");
    $userTableStmt->execute();
    if ((int)$userTableStmt->fetchColumn() === 0) {
        throw new RuntimeException('ไม่พบตาราง harddisk_db.users');
    }

    $userColumnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'users'");
    $userColumnsStmt->execute();
    $userColumns = [];
    foreach ($userColumnsStmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
        $userColumns[strtolower((string)$column)] = (string)$column;
    }

    $quoteUserColumn = static function (string $column): string {
        return '`' . str_replace('`', '``', $column) . '`';
    };

    $userNameExpression = '';
    foreach (['full_name', 'fullname', 'display_name', 'employee_name', 'name', 'user_name', 'username'] as $candidate) {
        if (isset($userColumns[$candidate])) {
            $columnSql = $quoteUserColumn($userColumns[$candidate]);
            $userNameExpression = "TRIM(COALESCE({$columnSql}, ''))";
            break;
        }
    }

    if ($userNameExpression === '') {
        $firstNameColumn = '';
        $lastNameColumn = '';
        foreach (['first_name', 'firstname', 'fname', 'first_name_th', 'name_th'] as $candidate) {
            if (isset($userColumns[$candidate])) {
                $firstNameColumn = $quoteUserColumn($userColumns[$candidate]);
                break;
            }
        }
        foreach (['last_name', 'lastname', 'lname', 'last_name_th', 'surname'] as $candidate) {
            if (isset($userColumns[$candidate])) {
                $lastNameColumn = $quoteUserColumn($userColumns[$candidate]);
                break;
            }
        }
        if ($firstNameColumn !== '' || $lastNameColumn !== '') {
            $firstNameSql = $firstNameColumn !== '' ? "COALESCE({$firstNameColumn}, '')" : "''";
            $lastNameSql = $lastNameColumn !== '' ? "COALESCE({$lastNameColumn}, '')" : "''";
            $userNameExpression = "TRIM(CONCAT({$firstNameSql}, ' ', {$lastNameSql}))";
        }
    }

    $employeeCodeColumn = '';
    foreach (['employee_code', 'emp_code', 'employee_id', 'emp_id', 'user_code', 'staff_code'] as $candidate) {
        if (isset($userColumns[$candidate])) {
            $employeeCodeColumn = $quoteUserColumn($userColumns[$candidate]);
            break;
        }
    }
    $employeeCodeExpression = $employeeCodeColumn !== ''
        ? "TRIM(COALESCE({$employeeCodeColumn}, ''))"
        : "''";

    if ($userNameExpression === '') {
        throw new RuntimeException('ไม่พบคอลัมน์ชื่อผู้ใช้งานที่รองรับใน harddisk_db.users');
    }

    $usersSql = "SELECT {$userNameExpression} AS user_name,
                        {$employeeCodeExpression} AS employee_code
                 FROM harddisk_db.users
                 WHERE {$userNameExpression} <> ''
                 ORDER BY {$userNameExpression} ASC
                 LIMIT 5000";
    $usersStmt = $pdo->query($usersSql);
    $loadedUsers = $usersStmt->fetchAll(PDO::FETCH_ASSOC);

    $uniqueUsers = [];
    foreach ($loadedUsers as $loadedUser) {
        $userName = trim((string)($loadedUser['user_name'] ?? ''));
        $employeeCode = trim((string)($loadedUser['employee_code'] ?? ''));
        if ($userName === '') {
            continue;
        }
        $uniqueKey = mb_strtolower($userName, 'UTF-8') . '|' . $employeeCode;
        if (!isset($uniqueUsers[$uniqueKey])) {
            $uniqueUsers[$uniqueKey] = [
                'user_name' => $userName,
                'employee_code' => $employeeCode,
            ];
        }
    }
    $drumUserOptions = array_values($uniqueUsers);

    if (!$drumUserOptions) {
        $drumUserLoadError = 'ไม่พบข้อมูลผู้ใช้งานที่มีชื่อใน harddisk_db.users';
    }
} catch (Throwable $e) {
    $drumUserLoadError = $e->getMessage();
    error_log('[drum_withdrawals/index] Cannot load users dropdown: ' . $e->getMessage());
}
$keyword = trim((string)($_GET['keyword'] ?? ''));
$drumFilter = trim((string)($_GET['drum_code'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = 20;
$totalRows = 0;
$totalPages = 1;
$offset = 0;
$allowedDrumFilters = ['Drum-DR-3455', 'Drum-DR-3608'];
if (!in_array($drumFilter, $allowedDrumFilters, true)) $drumFilter = '';
$recent = [];
try {
    $tableCheck = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
    $tableCheck->execute();
    if ((int)$tableCheck->fetchColumn() === 0) {
        throw new RuntimeException('ไม่พบตาราง drum_withdrawals ในฐานข้อมูลที่ระบบกำลังเชื่อมต่อ');
    }

    $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = 'harddisk_db' AND TABLE_NAME = 'drum_withdrawals'");
    $columnsStmt->execute();
    $drumColumns = array_map('strtolower', $columnsStmt->fetchAll(PDO::FETCH_COLUMN));

    $requiredColumns = ['request_no', 'main_branch_code', 'branch_name', 'drum_code', 'recorded_by', 'created_at'];
    $missingColumns = array_values(array_diff($requiredColumns, $drumColumns));
    if ($missingColumns) {
        throw new RuntimeException('ตาราง drum_withdrawals ขาดคอลัมน์: ' . implode(', ', $missingColumns));
    }

    $hasBranchCodeColumn = in_array('branch_code', $drumColumns, true);
    $hasDeletedAtColumn = in_array('deleted_at', $drumColumns, true);
    $hasDeliveryStatusColumn = in_array('delivery_status', $drumColumns, true);
    $hasProblemNoColumn = in_array('problem_no', $drumColumns, true);
    $hasRemarkColumn = in_array('remark', $drumColumns, true);
    $hasQuantityColumn = in_array('quantity', $drumColumns, true);
    if (!$hasDeliveryStatusColumn) {
        throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ delivery_status กรุณารันไฟล์ database/add_drum_delivery_status.sql');
    }
    if (!$hasProblemNoColumn || !$hasRemarkColumn) {
        throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ problem_no หรือ remark กรุณารันไฟล์ database/add_drum_problem_no_remark.sql');
    }
    if (!$hasQuantityColumn) {
        throw new RuntimeException('ตาราง drum_withdrawals ยังไม่มีคอลัมน์ quantity กรุณารันไฟล์ database/add_drum_quantity.sql');
    }

    $where = [];
    if ($hasDeletedAtColumn) {
        $where[] = 'dw.deleted_at IS NULL';
    }
    $where[] = "COALESCE(dw.delivery_status, 'pending') = 'pending'";
    $params = [];

    if ($keyword !== '') {
        $keywordConditions = [
            'dw.request_no LIKE :keyword_request_no',
            'dw.main_branch_code LIKE :keyword_main_branch_code',
            'dw.branch_name LIKE :keyword_branch_name',
            'dw.recorded_by LIKE :keyword_recorded_by',
            'dw.drum_code LIKE :keyword_drum_code',
            'dw.problem_no LIKE :keyword_problem_no',
            'dw.remark LIKE :keyword_remark',
        ];
        $keywordLike = '%' . $keyword . '%';
        $params[':keyword_request_no'] = $keywordLike;
        $params[':keyword_main_branch_code'] = $keywordLike;
        $params[':keyword_branch_name'] = $keywordLike;
        $params[':keyword_recorded_by'] = $keywordLike;
        $params[':keyword_drum_code'] = $keywordLike;
        $params[':keyword_problem_no'] = $keywordLike;
        $params[':keyword_remark'] = $keywordLike;

        if ($hasBranchCodeColumn) {
            $keywordConditions[] = 'dw.branch_code LIKE :keyword_branch_code';
            $params[':keyword_branch_code'] = $keywordLike;
        } else {
            $keywordConditions[] = "EXISTS (
                SELECT 1
                FROM harddisk_db.branch_directory bd_kw
                WHERE LPAD(TRIM(CAST(bd_kw.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(dw.main_branch_code AS CHAR)), 3, '0')
                  AND (TRIM(bd_kw.branch_name) = TRIM(dw.branch_name) OR TRIM(COALESCE(bd_kw.branch_name_2, '')) = TRIM(dw.branch_name))
                  AND TRIM(CAST(bd_kw.branch_code AS CHAR)) LIKE :keyword_branch_code_lookup
            )";
            $params[':keyword_branch_code_lookup'] = $keywordLike;
        }
        $where[] = '(' . implode(' OR ', $keywordConditions) . ')';
    }

    if ($drumFilter !== '') {
        $where[] = 'dw.drum_code = :drum_code';
        $params[':drum_code'] = $drumFilter;
    }
    if ($dateFrom !== '') {
        $where[] = 'DATE(dw.created_at) >= :date_from';
        $params[':date_from'] = $dateFrom;
    }
    if ($dateTo !== '') {
        $where[] = 'DATE(dw.created_at) <= :date_to';
        $params[':date_to'] = $dateTo;
    }
    $whereSql = $where ? ' WHERE ' . implode(' AND ', $where) : '';

    if ($hasBranchCodeColumn) {
        $countSql = "SELECT COUNT(*) FROM (
                        SELECT dw.request_no, dw.main_branch_code, dw.branch_code, dw.branch_name, dw.recorded_by, dw.created_at, dw.delivery_status
                        FROM harddisk_db.drum_withdrawals dw
                        {$whereSql}
                        GROUP BY dw.request_no, dw.main_branch_code, dw.branch_code, dw.branch_name, dw.recorded_by, dw.created_at, dw.delivery_status
                     ) grouped_rows";
    } else {
        $countSql = "SELECT COUNT(*) FROM (
                        SELECT dw.request_no, dw.main_branch_code, dw.branch_name, dw.recorded_by, dw.created_at, dw.delivery_status
                        FROM harddisk_db.drum_withdrawals dw
                        {$whereSql}
                        GROUP BY dw.request_no, dw.main_branch_code, dw.branch_name, dw.recorded_by, dw.created_at, dw.delivery_status
                     ) grouped_rows";
    }

    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $totalRows = (int)$countStmt->fetchColumn();
    $totalPages = max(1, (int)ceil($totalRows / $perPage));
    if ($page > $totalPages) {
        $page = $totalPages;
    }
    $offset = ($page - 1) * $perPage;

    if ($hasBranchCodeColumn) {
        $sql = "SELECT dw.request_no, dw.main_branch_code, dw.branch_code, dw.branch_name,
                       GROUP_CONCAT(CONCAT(dw.drum_code, ' x', COALESCE(dw.quantity,1)) ORDER BY dw.drum_code SEPARATOR ', ') AS drum_codes,
                       GROUP_CONCAT(CONCAT(dw.drum_code, '|', COALESCE(dw.quantity,1)) ORDER BY dw.drum_code SEPARATOR ',') AS drum_items,
                       MAX(dw.problem_no) AS problem_no, MAX(dw.remark) AS remark,
                       dw.recorded_by, dw.created_at, COALESCE(dw.delivery_status, 'pending') AS delivery_status
                FROM harddisk_db.drum_withdrawals dw
                {$whereSql}
                GROUP BY dw.request_no, dw.main_branch_code, dw.branch_code, dw.branch_name, dw.recorded_by, dw.created_at, dw.delivery_status
                ORDER BY dw.created_at DESC
                LIMIT :limit OFFSET :offset";
    } else {
        $sql = "SELECT dw.request_no, dw.main_branch_code,
                       COALESCE((
                           SELECT bd.branch_code
                           FROM harddisk_db.branch_directory bd
                           WHERE LPAD(TRIM(CAST(bd.main_branch_code AS CHAR)), 3, '0') = LPAD(TRIM(CAST(dw.main_branch_code AS CHAR)), 3, '0')
                             AND (TRIM(bd.branch_name) = TRIM(dw.branch_name) OR TRIM(COALESCE(bd.branch_name_2, '')) = TRIM(dw.branch_name))
                           ORDER BY bd.branch_code ASC
                           LIMIT 1
                       ), '') AS branch_code,
                       dw.branch_name,
                       GROUP_CONCAT(CONCAT(dw.drum_code, ' x', COALESCE(dw.quantity,1)) ORDER BY dw.drum_code SEPARATOR ', ') AS drum_codes,
                       GROUP_CONCAT(CONCAT(dw.drum_code, '|', COALESCE(dw.quantity,1)) ORDER BY dw.drum_code SEPARATOR ',') AS drum_items,
                       MAX(dw.problem_no) AS problem_no, MAX(dw.remark) AS remark,
                       dw.recorded_by, dw.created_at, COALESCE(dw.delivery_status, 'pending') AS delivery_status
                FROM harddisk_db.drum_withdrawals dw
                {$whereSql}
                GROUP BY dw.request_no, dw.main_branch_code, dw.branch_name, dw.recorded_by, dw.created_at, dw.delivery_status
                ORDER BY dw.created_at DESC
                LIMIT :limit OFFSET :offset";
    }

    $stmt = $pdo->prepare($sql);
    foreach ($params as $paramName => $paramValue) {
        $stmt->bindValue($paramName, $paramValue);
    }
    $stmt->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    $recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[drum_withdrawals/index] ' . $e->getMessage());
    if ($error === '') {
        $error = $e instanceof RuntimeException
            ? $e->getMessage()
            : 'ไม่สามารถโหลดรายการเบิก Drum ได้ กรุณาตรวจสอบโครงสร้างตาราง drum_withdrawals';
    }
}

$exportParams = ['delivery_status' => 'pending'];
if ($keyword !== '') $exportParams['keyword'] = $keyword;
if ($drumFilter !== '') $exportParams['drum_code'] = $drumFilter;
if ($dateFrom !== '') $exportParams['date_from'] = $dateFrom;
if ($dateTo !== '') $exportParams['date_to'] = $dateTo;
$exportUrl = 'export_pdf.php' . ($exportParams ? '?' . http_build_query($exportParams) : '');
$exportExcelUrl = 'export_excel.php' . ($exportParams ? '?' . http_build_query($exportParams) : '');
$paginationParams = [];
if ($keyword !== '') $paginationParams['keyword'] = $keyword;
if ($drumFilter !== '') $paginationParams['drum_code'] = $drumFilter;
if ($dateFrom !== '') $paginationParams['date_from'] = $dateFrom;
if ($dateTo !== '') $paginationParams['date_to'] = $dateTo;
$buildDrumPageUrl = static function (int $targetPage) use ($paginationParams): string {
    $query = $paginationParams;
    $query['page'] = max(1, $targetPage);
    return 'index.php?' . http_build_query($query);
};
?>
<style>
.drum-page {
    font-size: .82rem;
    padding: 0 10px 24px;
}

.drum-hero {
    background: linear-gradient(135deg, #0f4c81, #1976d2);
    color: #fff;
    border-radius: 16px;
    padding: 17px 20px;
    box-shadow: 0 12px 28px rgba(15, 76, 129, .18);
}

.drum-card {
    border: 0;
    border-radius: 15px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, .08);
    overflow: hidden;
}

.drum-card .card-header {
    background: #fff;
    border-bottom: 1px solid #e2e8f0;
    font-weight: 900;
}

.drum-step {
    width: 30px;
    height: 30px;
    border-radius: 9px;
    background: #eaf3ff;
    color: #0f4c81;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 800;
}

.drum-choice {
    border: 1px solid #dbe5ef;
    border-radius: 12px;
    padding: 13px;
    cursor: pointer;
    transition: .18s;
}

.drum-choice:hover,
.drum-choice:has(input:checked) {
    border-color: #1976d2;
    background: #f2f8ff;
}

.branch-result {
    background: #f8fafc;
    border: 1px solid #e2e8f0;
    border-radius: 11px;
    padding: 12px;
}

.drum-table-wrap {
    max-height: none;
    overflow-x: auto;
    overflow-y: visible;
}

.drum-table {
    width: 100%;
    margin: 0;
}

.drum-table thead th {
    position: sticky;
    top: 0;
    z-index: 2;
    background: #f1f5f9;
    white-space: nowrap;
    font-size: .76rem;
    text-align: center;
    vertical-align: middle;
    padding: .55rem .5rem;
}

.drum-table td {
    font-size: .78rem;
    vertical-align: middle;
    padding: .52rem .5rem;
}

.drum-code-text {
    color: inherit;
    background: transparent;
    font-weight: 400;
    white-space: normal;
    word-break: break-word;
}

.modal-content {
    border: 0;
    border-radius: 17px;
    overflow: hidden;
}

.modal-header {
    background: linear-gradient(135deg, #eff6ff, #fff);
}

.drum-filter .form-control,
.drum-filter .form-select,
.drum-filter .btn {
    min-height: 36px;
    font-size: .74rem;
    border-radius: 10px;
}

.drum-filter-card {
    border: 0;
    border-radius: 15px;
    box-shadow: 0 6px 20px rgba(15, 23, 42, .07);
}

/* Modal เพิ่มรายการ Drum */
.drum-entry-modal-body {
    padding: 8px;
    background: #f8fafc;
}

.drum-add-modal .modal-dialog {
    max-width: 900px;
}

.drum-add-table {
    margin: 0;
    font-size: .78rem;
}

.drum-add-table th {
    width: 185px;
    background: #f8fafc;
    color: #334155;
    font-weight: 800;
    vertical-align: middle;
    white-space: nowrap;
}

.drum-add-table th,
.drum-add-table td {
    padding: .48rem .6rem;
}

.drum-add-table .form-control,
.drum-add-table .form-select {
    min-height: 34px;
    font-size: .76rem;
}

.drum-add-search-row {
    display: grid;
    grid-template-columns: minmax(130px, 1fr) 96px;
    gap: 8px;
}

.drum-add-recorder {
    display: grid;
    grid-template-columns: 1.4fr 1fr;
    gap: 8px;
}

.drum-add-drums {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

.drum-add-drum-item {
    display: block;
    border: 1px solid #dbe5ef;
    border-radius: 9px;
    padding: 8px 10px;
    background: #fff;
    cursor: pointer;
    transition: .16s;
}

.drum-add-drum-item:hover,
.drum-add-drum-item:has(input:checked) {
    border-color: #1976d2;
    background: #eef6ff;
}

.drum-add-drum-item strong {
    display: block;
}

.drum-add-drum-item small {
    display: block;
    margin-top: 2px;
    color: #64748b;
}

.drum-add-drum-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 74px;
    align-items: center;
    gap: 8px;
}

.drum-add-drum-select {
    display: flex;
    align-items: flex-start;
    gap: 0;
    min-width: 0;
    cursor: pointer;
}

.drum-add-drum-select > span {
    min-width: 0;
}

.drum-add-qty-wrap {
    display: none;
    align-items: center;
    gap: 4px;
    justify-content: flex-end;
}

.drum-add-drum-item:has(.drum-checkbox:checked) .drum-add-qty-wrap {
    display: flex;
}

.drum-add-qty-label {
    font-size: .66rem;
    font-weight: 800;
    color: #475569;
    white-space: nowrap;
}

.drum-add-qty {
    width: 44px;
    min-height: 30px !important;
    padding: .2rem .25rem !important;
    text-align: center;
    font-weight: 800;
}

.drum-add-branch-result {
    margin-top: 8px;
}

.drum-add-info {
    margin-top: 8px;
    margin-bottom: 0;
}

/* Modal แก้ไข Drum */
.drum-action-buttons {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 4px;
    white-space: nowrap;
}

.drum-action-buttons .btn {
    font-size: .66rem;
    padding: .25rem .38rem;
    line-height: 1.15;
}

.drum-edit-modal .modal-dialog {
    max-width: 900px;
}

.drum-edit-table {
    margin: 0;
    font-size: .78rem;
}

.drum-edit-table th {
    width: 185px;
    background: #f8fafc;
    color: #334155;
    font-weight: 800;
    vertical-align: middle;
    white-space: nowrap;
}

.drum-edit-table th,
.drum-edit-table td {
    padding: .48rem .6rem;
}

.drum-edit-table .form-control,
.drum-edit-table .form-select {
    min-height: 34px;
    font-size: .76rem;
}

.drum-edit-search-btn {
    min-width: 96px;
    min-height: 34px;
    padding: .35rem .8rem;
    font-size: .76rem;
    font-weight: 700;
    white-space: nowrap;
}

.drum-edit-drums {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 8px;
}

.drum-edit-drum-item {
    border: 1px solid #dbe5ef;
    border-radius: 9px;
    padding: 8px 10px;
    background: #fff;
}

.drum-edit-drum-item {
    display: grid;
    grid-template-columns: minmax(0, 1fr) 74px;
    align-items: center;
    gap: 8px;
}

.drum-edit-drum-select {
    display: flex;
    align-items: center;
    min-width: 0;
    cursor: pointer;
}

.drum-edit-qty-wrap {
    display: none;
    align-items: center;
    gap: 4px;
    justify-content: flex-end;
}

.drum-edit-drum-item:has(.edit-drum-checkbox:checked) .drum-edit-qty-wrap {
    display: flex;
}

.drum-edit-qty-label {
    font-size: .66rem;
    font-weight: 800;
    color: #475569;
    white-space: nowrap;
}

.drum-edit-qty {
    width: 44px;
    min-height: 30px !important;
    padding: .2rem .25rem !important;
    text-align: center;
    font-weight: 800;
}

.drum-delete-modal .modal-dialog {
    max-width: 520px;
}

.drum-status-badge {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    min-width: 92px;
    padding: 5px 9px;
    border: 1px solid transparent;
    border-radius: 4px;
    color: #fff;
    font-size: .68rem;
    font-weight: 800;
    line-height: 1.15;
    white-space: nowrap;
    box-shadow: none;
}

.drum-status-pending {
    background: #ff9b0d;
    border-color: #ff9b0d;
}
.drum-status-shipped {
    background: #198754;
    border-color: #198754;
}
.drum-cover-locked{pointer-events:none!important;opacity:.55!important;cursor:not-allowed!important;background:#e2e8f0!important;color:#64748b!important;border-color:#cbd5e1!important}

/* ปุ่มเพิ่มรายการ */
.drum-add-btn {
    color: #dc2626 !important;
    border: 2px solid #dc2626 !important;
    background: #fff !important;
    border-radius: 10px !important;
    font-weight: 900 !important;
    white-space: nowrap;
    padding: .55rem .9rem !important;
    font-size: .92rem !important;
    line-height: 1.5 !important;
    box-shadow: 0 4px 12px rgba(220, 38, 38, .22) !important;
}

.drum-add-btn:hover,
.drum-add-btn:focus {
    color: #fff !important;
    background: #dc2626 !important;
    border-color: #b91c1c !important;
    box-shadow: 0 6px 16px rgba(220, 38, 38, .32) !important;
}

/* Notebook */
@media (max-width: 1366px) {
    .drum-add-modal .modal-dialog {
        max-width: 780px;
    }

    .drum-add-table {
        font-size: .7rem;
    }

    .drum-add-table th {
        width: 145px;
    }

    .drum-add-table th,
    .drum-add-table td {
        padding: .35rem .45rem;
    }

    .drum-add-table .form-control,
    .drum-add-table .form-select {
        min-height: 30px;
        font-size: .69rem;
    }

    .drum-add-search-row {
        grid-template-columns: minmax(110px, 1fr) 78px;
        gap: 6px;
    }

    .drum-add-recorder,
    .drum-add-drums {
        gap: 6px;
    }

    .drum-add-drum-item {
        padding: 6px 8px;
    }

    .drum-add-drum-item strong {
        font-size: .69rem;
    }

    .drum-add-drum-item small {
        font-size: .62rem;
    }

    .drum-add-drum-item {
        grid-template-columns: minmax(0, 1fr) 66px;
        gap: 5px;
    }

    .drum-add-qty-label {
        font-size: .58rem;
    }

    .drum-add-qty {
        width: 40px;
        min-height: 26px !important;
        font-size: .66rem !important;
    }

    .drum-edit-modal .modal-dialog {
        max-width: 780px;
    }

    .drum-edit-table {
        font-size: .7rem;
    }

    .drum-edit-table th {
        width: 145px;
    }

    .drum-edit-table th,
    .drum-edit-table td {
        padding: .35rem .45rem;
    }

    .drum-edit-table .form-control,
    .drum-edit-table .form-select {
        min-height: 30px;
        font-size: .69rem;
    }

    .drum-edit-search-btn {
        min-width: 78px;
        min-height: 30px;
        padding: .25rem .55rem;
        font-size: .69rem;
    }

    .drum-edit-drum-item {
        grid-template-columns: minmax(0, 1fr) 66px;
        gap: 5px;
    }

    .drum-edit-qty-label {
        font-size: .58rem;
    }

    .drum-edit-qty {
        width: 40px;
        min-height: 26px !important;
        font-size: .66rem !important;
    }

    .drum-action-buttons .btn {
        font-size: .58rem;
        padding: .2rem .28rem;
    }

    .drum-page {
        padding-left: 4px;
        padding-right: 4px;
    }

    .drum-hero {
        padding: 14px 17px;
    }

    .drum-card .card-body {
        overflow: hidden;
    }

    .drum-table {
        table-layout: auto;
    }

    .drum-table thead th {
        font-size: .66rem;
        padding: .38rem .24rem;
        white-space: nowrap;
        line-height: 1.15;
    }

    .drum-table td {
        font-size: .68rem;
        padding: .36rem .24rem;
        line-height: 1.2;
    }

    .drum-table th:not(:nth-child(5)),
    .drum-table td:not(:nth-child(5)) {
        width: 1% !important;
        white-space: nowrap;
    }

    .drum-table th:nth-child(5),
    .drum-table td:nth-child(5) {
        width: auto !important;
        min-width: 150px;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }

    .drum-code-text {
        white-space: nowrap !important;
        word-break: normal !important;
    }
}

@media (max-width: 1366px) {
    .drum-status-badge {
        min-width: 84px;
        padding: 4px 7px;
    }
}

/* Mobile */
@media (max-width: 767.98px) {
    .drum-add-table,
    .drum-add-table tbody,
    .drum-add-table tr,
    .drum-add-table th,
    .drum-add-table td {
        display: block;
        width: 100%;
    }

    .drum-add-table th {
        border-bottom: 0;
    }

    .drum-add-table td {
        padding-top: .25rem;
    }

    .drum-add-recorder,
    .drum-add-drums,
    .drum-add-search-row {
        grid-template-columns: 1fr;
    }

    .drum-add-drum-item {
        grid-template-columns: minmax(0, 1fr) 70px;
    }

    .drum-edit-table,
    .drum-edit-table tbody,
    .drum-edit-table tr,
    .drum-edit-table th,
    .drum-edit-table td {
        display: block;
        width: 100%;
    }

    .drum-edit-table th {
        border-bottom: 0;
    }

    .drum-edit-table td {
        padding-top: .25rem;
    }

    .drum-edit-drums {
        grid-template-columns: 1fr;
    }
}

/* Desktop */
@media (min-width: 1367px) {
    .drum-table thead th {
        font-size: .8rem;
        padding: .65rem .6rem;
    }

    .drum-table td {
        font-size: .82rem;
        padding: .62rem .6rem;
    }

    .drum-table th:nth-child(2) {
        width: 145px !important;
    }
}
</style>

<?php if ($canManageDrum): ?>
<style>
@media (max-width: 1366px) {
    .drum-table th:not(:nth-child(5)),
    .drum-table td:not(:nth-child(5)) {
        width: 1% !important;
        white-space: nowrap;
    }

    .drum-table th:nth-child(5),
    .drum-table td:nth-child(5) {
        width: auto !important;
        min-width: 150px;
        white-space: normal;
        overflow-wrap: anywhere;
        word-break: break-word;
    }
}
</style>
<?php endif; ?>

<style>
.drum-module-menu{display:grid;grid-template-columns:repeat(2,minmax(0,1fr));gap:9px;margin:0 0 14px}.drum-module-menu-item{position:relative;min-width:0;min-height:78px;display:flex;align-items:center;gap:10px;padding:11px 12px;border:1px solid #dbe5ee;border-radius:14px;background:#fff;color:#334155;text-decoration:none;box-shadow:0 5px 16px rgba(15,23,42,.055);transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease,background .16s ease;overflow:hidden}.drum-module-menu-item:hover{color:#0f4c81;text-decoration:none;border-color:#93c5fd;box-shadow:0 9px 22px rgba(37,99,235,.12);transform:translateY(-1px)}.drum-module-menu-item.active{color:#fff;border-color:#00acc1;background:linear-gradient(135deg,#0097a7 0%,#00bcd4 58%,#26c6da 100%);box-shadow:0 10px 24px rgba(0,188,212,.28)}.drum-module-menu-icon{width:38px;height:38px;flex:0 0 38px;display:flex;align-items:center;justify-content:center;border-radius:11px;background:#e0f7fa;color:#00acc1;font-size:1.1rem}.drum-module-menu-icon svg{width:21px;height:21px;display:block}.drum-module-menu-item.active .drum-module-menu-icon{background:rgba(255,255,255,.18);color:#fff}.drum-module-menu-content{min-width:0}.drum-module-menu-title{display:block;font-size:.78rem;line-height:1.25;font-weight:900;white-space:normal}.drum-module-menu-note{display:block;margin-top:3px;font-size:.65rem;line-height:1.2;color:#64748b;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.drum-module-menu-item.active .drum-module-menu-note{color:rgba(255,255,255,.82)}@media(max-width:1366px){.drum-module-menu{gap:7px}.drum-module-menu-item{min-height:70px;padding:9px 10px;gap:8px}.drum-module-menu-icon{width:32px;height:32px;flex-basis:32px;border-radius:9px;font-size:.95rem}.drum-module-menu-icon svg{width:18px;height:18px}.drum-module-menu-title{font-size:.7rem}.drum-module-menu-note{font-size:.59rem}}@media(max-width:700px){.drum-module-menu{grid-template-columns:1fr}}
.drum-duplicate-warning{display:none;border:1px solid #f59e0b;background:#fffbeb;color:#92400e;border-radius:10px;padding:10px;margin-top:10px}.drum-duplicate-warning.show{display:block}.drum-duplicate-list{margin:.35rem 0 .5rem;padding-left:0;list-style:none;overflow-x:auto}.drum-duplicate-list li{display:flex;align-items:center;gap:5px;min-width:max-content;white-space:nowrap}.drum-duplicate-list li::before{content:'•';font-weight:900;margin-right:6px}.drum-repeat-reason-wrap{display:none;margin-top:8px}.drum-repeat-reason-wrap.show{display:block}.drum-repeat-reason-wrap textarea{min-height:76px;font-size:.76rem}.drum-duplicate-request-link{display:inline-flex;align-items:center;gap:4px;flex:0 0 auto;color:#1d4ed8;background:transparent;border:0;padding:0;font-weight:800;text-decoration:underline;text-underline-offset:2px}.drum-duplicate-request-link:hover,.drum-duplicate-request-link:focus{color:#1e40af}.drum-duplicate-detail-modal .modal-dialog{max-width:720px}.drum-duplicate-detail-table{margin:0;font-size:.78rem}.drum-duplicate-detail-table th{width:170px;background:#f8fafc;color:#475569;font-weight:800}.drum-duplicate-detail-table th,.drum-duplicate-detail-table td{padding:.5rem .65rem;vertical-align:middle}.drum-duplicate-detail-table td{color:#0f172a;overflow-wrap:anywhere}.drum-duplicate-detail-request-no{color:#1d4ed8!important;font-weight:800}.drum-duplicate-detail-status-pending{color:#dc2626!important;font-weight:800}
</style>

<link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hdd-primary-action-button.css">

<div class="drum-page">
<nav class="drum-module-menu" aria-label="เมนูระบบเบิก Drum">
  <a class="drum-module-menu-item active" href="index.php" aria-current="page">
    <span class="drum-module-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 7l9-4 9 4-9 4-9-4Z"></path><path d="M3 7v10l9 4 9-4V7"></path><path d="M12 11v10"></path><path d="M7 9v4"></path></svg></span>
    <span class="drum-module-menu-content"><span class="drum-module-menu-title">บันทึกข้อมูลการเบิก Drum</span><span class="drum-module-menu-note">เพิ่ม แก้ไข และจัดการรายการเบิก Drum</span></span>
  </a>
  <a class="drum-module-menu-item" href="history.php">
    <span class="drum-module-menu-icon" aria-hidden="true"><svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M3 12a9 9 0 1 0 3-6.7"></path><path d="M3 4v5h5"></path><path d="M12 7v5l3 2"></path></svg></span>
    <span class="drum-module-menu-content"><span class="drum-module-menu-title">ประวัติการจัดส่ง Drum</span><span class="drum-module-menu-note">ค้นหาและตรวจสอบรายการจัดส่งย้อนหลัง</span></span>
  </a>
</nav>

  <section class="drum-hero mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
      <div><h1 class="h5 mb-1 fw-bold">บันทึกข้อมูลการเบิก Drum</h1>
      <!-- <div class="opacity-75">จัดการรายการเบิก Drum และตรวจสอบประวัติการบันทึก</div> -->
    </div>
      <button type="button" class="btn btn-light hdd-primary-action-btn" data-bs-toggle="modal" data-bs-target="#drumModal" id="addDrumBtn">+ เพิ่มรายการเบิก Drum</button>
    </div>
  </section>

  <?php if ($success): ?><div class="alert alert-success py-2"><?php echo e($success); ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-danger py-2"><?php echo e($error); ?></div><?php endif; ?>

  <div class="card drum-filter-card mb-3">
    <div class="card-body">
      <form method="get" class="row g-2 drum-filter align-items-end" id="drumSearchForm">
        <div class="col-lg-4">
          <label class="form-label small fw-bold">ค้นหา</label>
          <input type="search" name="keyword" class="form-control" value="<?php echo e($keyword); ?>" placeholder="เลขที่รายการ, รหัสสาขา, Cost Center, ชื่อสาขา, เลขที่ปัญหา, หมายเหตุ, ผู้บันทึก">
        </div>
        <div class="col-lg-2">
          <label class="form-label small fw-bold">ประเภท Drum</label>
          <select name="drum_code" class="form-select">
            <option value="">ทุกประเภท</option>
            <option value="Drum-DR-3455" <?php echo $drumFilter === 'Drum-DR-3455' ? 'selected' : ''; ?>>Drum-DR-3455</option>
            <option value="Drum-DR-3608" <?php echo $drumFilter === 'Drum-DR-3608' ? 'selected' : ''; ?>>Drum-DR-3608</option>
          </select>
        </div>
        <div class="col-lg-3">
          <label class="form-label small fw-bold">ช่วงวันที่</label>
          <div class="input-group">
            <input type="date" name="date_from" class="form-control" value="<?php echo e($dateFrom); ?>" aria-label="วันที่เริ่มต้น">
            <input type="date" name="date_to" class="form-control" value="<?php echo e($dateTo); ?>" aria-label="วันที่สิ้นสุด">
          </div>
        </div>
        <div class="col-lg-3 d-flex gap-2">
          <button type="submit" class="btn btn-dark flex-fill"><i class="bi bi-search me-1"></i>ค้นหา</button>
          <a href="index.php" class="btn btn-outline-secondary flex-fill">ล้างค่า</a>
          <a href="<?php echo e($exportUrl); ?>" class="btn btn-outline-danger" target="_blank" rel="noopener"><i class="bi bi-file-earmark-pdf me-1"></i>PDF</a>
          <a href="<?php echo e($exportExcelUrl); ?>" class="btn btn-outline-success"><i class="bi bi-file-earmark-excel me-1"></i>Excel</a>
        </div>
      </form>
    </div>
  </div>

  <div class="card drum-card">
    <div class="card-header d-flex justify-content-between align-items-center flex-wrap gap-2">
      <span><i class="bi bi-list-ul me-1 text-primary"></i>รายการเบิก Drum ทั้งหมด</span>
      <span class="small text-muted"><?php echo number_format($totalRows); ?> รายการ | หน้า <?php echo number_format($page); ?>/<?php echo number_format($totalPages); ?></span>
    </div>
    <div class="card-body p-0">
      <div class="table-responsive drum-table-wrap">
        <table class="table table-hover table-bordered align-middle drum-table" id="drumDataTable">
          <thead><tr><th style="width:58px">ลำดับ</th><th style="width:145px">เลขที่รายการ</th><th style="width:112px">รหัสสาขาใหญ่</th><th style="width:118px">Cost Center</th><th>ชื่อสาขา</th><th>Drum</th><th style="width:185px">ผู้บันทึก</th><th style="width:118px">วันที่บันทึก</th><th style="width:125px">สถานะ</th><?php if ($canManageDrum): ?><th style="width:112px">จัดการ</th><?php endif; ?></tr></thead>
          <tbody>
          <?php if (!$recent): ?>
            <tr><td colspan="<?php echo $canManageDrum ? 10 : 9; ?>" class="text-center text-muted py-5"><i class="bi bi-inbox d-block fs-3 mb-2"></i>ยังไม่มีข้อมูลการเบิก Drum</td></tr>
          <?php else: foreach($recent as $index => $row): ?>
            <tr data-drum-request-row="<?php echo e($row['request_no']); ?>">
              <td class="text-center"><?php echo number_format($offset + $index + 1); ?></td>
              <td class="fw-bold text-primary"><?php echo e($row['request_no']); ?></td>
              <td class="fw-bold text-center"><?php echo e($row['main_branch_code']); ?></td>
              <td class="fw-bold text-center text-primary"><?php echo e($row['branch_code'] ?: '-'); ?></td>
              <td><?php echo e($row['branch_name']); ?></td>
              <td class="drum-code-text"><?php echo e($row['drum_codes']); ?></td>
              <td><?php echo e($row['recorded_by']); ?></td>
              <td><?php echo e(date('d/m/Y', strtotime($row['created_at']))); ?></td>
              <td class="text-center"><span class="drum-status-badge drum-status-pending js-drum-status">รอจัดส่ง</span></td>
              <?php if ($canManageDrum): ?>
              <td class="text-center">
                <div class="drum-action-buttons">
                  <?php if ($canEditDrum): ?><button type="button" class="btn btn-outline-primary js-drum-edit" data-bs-toggle="modal" data-bs-target="#drumEditModal" data-request-no="<?php echo e($row['request_no']); ?>" data-main-branch-code="<?php echo e($row['main_branch_code']); ?>" data-branch-code="<?php echo e($row['branch_code'] ?: ''); ?>" data-branch-name="<?php echo e($row['branch_name']); ?>" data-drum-codes="<?php echo e($row['drum_codes']); ?>" data-drum-items="<?php echo e($row['drum_items'] ?? ''); ?>" data-problem-no="<?php echo e($row['problem_no'] ?? ''); ?>" data-remark="<?php echo e($row['remark'] ?? ''); ?>" data-recorded-by="<?php echo e($row['recorded_by']); ?>" data-created-at="<?php echo e(date('Y-m-d\TH:i', strtotime($row['created_at']))); ?>"><i class="bi bi-pencil-square"></i> แก้ไข</button><?php endif; ?>
                  <a class="btn btn-outline-secondary js-drum-cover" href="cover_sheet.php?request_no=<?php echo urlencode((string)$row['request_no']); ?>" target="_blank" rel="noopener" title="เปิดใบปะหน้า" data-request-no="<?php echo e($row['request_no']); ?>"><i class="bi bi-file-earmark-text"></i> ใบปะหน้า</a>
                  <?php if ($canDeleteDrum): ?><button type="button" class="btn btn-outline-danger js-drum-delete" data-bs-toggle="modal" data-bs-target="#drumDeleteModal" data-request-no="<?php echo e($row['request_no']); ?>"><i class="bi bi-trash"></i> ลบ</button><?php endif; ?>
                </div>
              </td>
              <?php endif; ?>
            </tr>
          <?php endforeach; endif; ?>
          </tbody>
        </table>
      </div>
      <?php if ($totalPages > 1): ?>
      <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 px-3 py-2 border-top bg-white">
        <div class="small text-muted">แสดง <?php echo number_format($totalRows > 0 ? $offset + 1 : 0); ?>-<?php echo number_format(min($offset + $perPage, $totalRows)); ?> จาก <?php echo number_format($totalRows); ?> รายการ</div>
        <nav aria-label="เปลี่ยนหน้ารายการเบิก Drum">
          <ul class="pagination pagination-sm mb-0">
            <li class="page-item <?php echo $page <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($buildDrumPageUrl($page - 1)); ?>">ก่อนหน้า</a></li>
            <?php $pageStart = max(1, $page - 2); $pageEnd = min($totalPages, $page + 2); ?>
            <?php for ($pageNo = $pageStart; $pageNo <= $pageEnd; $pageNo++): ?>
              <li class="page-item <?php echo $pageNo === $page ? 'active' : ''; ?>"><a class="page-link" href="<?php echo e($buildDrumPageUrl($pageNo)); ?>"><?php echo number_format($pageNo); ?></a></li>
            <?php endfor; ?>
            <li class="page-item <?php echo $page >= $totalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo e($buildDrumPageUrl($page + 1)); ?>">ถัดไป</a></li>
          </ul>
        </nav>
      </div>
      <?php endif; ?>
    </div>
  </div>
</div>

<?php if ($canEditDrum): ?>
<div class="modal fade drum-edit-modal" id="drumEditModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form method="post" action="update.php" class="modal-content" id="drumEditForm">
      <div class="modal-header"><div><h5 class="modal-title fw-bold"><i class="bi bi-pencil-square me-1 text-primary"></i>แก้ไขรายการเบิก Drum</h5><div class="small text-muted">แก้ไขข้อมูลในรูปแบบตาราง</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body bg-light p-2">
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_drum']); ?>">
        <div class="table-responsive border rounded-3 bg-white"><table class="table table-bordered drum-edit-table align-middle">
          <tbody>
            <tr><th>เลขที่รายการ</th><td><input type="text" class="form-control bg-light" name="request_no" id="editRequestNo" readonly></td></tr>
            <tr><th>รหัสสาขาใหญ่ <span class="text-danger">*</span></th><td><div class="input-group"><input type="text" class="form-control" name="main_branch_code" id="editMainBranchCode" maxlength="3" inputmode="numeric" required><button type="button" class="btn btn-outline-primary drum-edit-search-btn" id="editLoadBranchesBtn"><i class="bi bi-search me-1"></i>ค้นหา</button></div></td></tr>
            <tr><th>ชื่อสาขา</th><td><select class="form-select" id="editBranchSelect" required><option value="">-- กรุณาระบุรหัสสาขาใหญ่ --</option></select><input type="hidden" name="branch_name" id="editBranchName"></td></tr>
            <tr><th>Cost Center <span class="text-danger">*</span></th><td><input type="text" class="form-control bg-light" name="branch_code" id="editBranchCode" readonly required></td></tr>
            <tr><th>รายการ Drum <span class="text-danger">*</span></th><td><div class="drum-edit-drums">
              <div class="drum-edit-drum-item"><label class="drum-edit-drum-select"><input class="form-check-input me-2 edit-drum-checkbox" type="checkbox" value="Drum-DR-3455"><span>Drum-DR-3455</span></label><div class="drum-edit-qty-wrap"><span class="drum-edit-qty-label">จำนวน</span><input type="number" class="form-control drum-edit-qty" min="1" max="99" step="1" value="1" inputmode="numeric" aria-label="จำนวน Drum-DR-3455"></div></div>
              <div class="drum-edit-drum-item"><label class="drum-edit-drum-select"><input class="form-check-input me-2 edit-drum-checkbox" type="checkbox" value="Drum-DR-3608"><span>Drum-DR-3608</span></label><div class="drum-edit-qty-wrap"><span class="drum-edit-qty-label">จำนวน</span><input type="number" class="form-control drum-edit-qty" min="1" max="99" step="1" value="1" inputmode="numeric" aria-label="จำนวน Drum-DR-3608"></div></div>
            </div></td></tr>
            <tr><th>เลขที่ปัญหา <span class="text-danger">*</span></th><td><input type="text" class="form-control" name="problem_no" id="editProblemNo" maxlength="100" required placeholder="ระบุเลขที่ปัญหา"></td></tr>
            <tr><th>หมายเหตุ</th><td><textarea class="form-control" name="remark" id="editRemark" maxlength="500" rows="2" placeholder="ระบุหมายเหตุเพิ่มเติม (ถ้ามี)"></textarea><div class="form-text">ไม่บังคับกรอก สูงสุด 500 ตัวอักษร</div></td></tr>
            <tr><th>ผู้บันทึก <span class="text-danger">*</span></th><td><select class="form-select" name="recorded_by" id="editRecordedBy" required><option value="">-- เลือกผู้บันทึก --</option><?php foreach ($drumUserOptions as $userOption): ?><?php $userOptionName = trim((string)($userOption['user_name'] ?? '')); $userOptionCode = trim((string)($userOption['employee_code'] ?? '')); ?><option value="<?php echo e($userOptionName); ?>"><?php echo e($userOptionName . ($userOptionCode !== '' ? ' (' . $userOptionCode . ')' : '')); ?></option><?php endforeach; ?></select><?php if ($drumUserLoadError !== ''): ?><div class="small text-danger mt-1"><i class="bi bi-exclamation-circle me-1"></i><?php echo e($drumUserLoadError); ?></div><?php endif; ?></td></tr>
            <tr><th>วันที่บันทึก <span class="text-danger">*</span></th><td><input type="datetime-local" class="form-control" name="created_at" id="editCreatedAt" required></td></tr>
          </tbody>
        </table></div>
        <div class="alert alert-info py-2 mt-2 mb-0 small">ระบบจะตรวจสอบรหัสสาขาใหญ่และ Cost Center กับข้อมูลสาขาก่อนบันทึก</div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-primary px-4"><i class="bi bi-save me-1"></i>บันทึกการแก้ไข</button></div>
    </form>
  </div>
</div>
<?php endif; ?>

<?php if ($canDeleteDrum): ?>
<div class="modal fade drum-delete-modal" id="drumDeleteModal" tabindex="-1" data-bs-backdrop="static" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered"><form method="post" action="delete.php" class="modal-content">
    <div class="modal-header bg-danger-subtle"><h5 class="modal-title fw-bold text-danger"><i class="bi bi-exclamation-triangle me-1"></i>ยืนยันลบรายการเบิก Drum</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body"><input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_drum']); ?>"><input type="hidden" name="request_no" id="deleteRequestNo"><p class="mb-1">ต้องการลบรายการเลขที่</p><div class="fs-5 fw-bold text-danger" id="deleteRequestNoText">-</div><div class="small text-muted mt-2">รายการ Drum ทุกแถวภายใต้เลขที่รายการนี้จะถูกลบพร้อมกัน</div></div>
    <div class="modal-footer"><button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-danger"><i class="bi bi-trash me-1"></i>ยืนยันลบ</button></div>
  </form></div>
</div>
<?php endif; ?>

<div class="modal fade drum-add-modal" id="drumModal" tabindex="-1" data-bs-backdrop="static" aria-labelledby="drumModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <form method="post" action="save.php" class="modal-content" id="drumForm">
      <div class="modal-header"><div><h5 class="modal-title fw-bold" id="drumModalLabel"><i class="bi bi-box-seam me-1 text-primary"></i>เพิ่มรายการเบิก Drum</h5><div class="small text-muted">เพิ่มข้อมูลในรูปแบบตาราง</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
      <div class="modal-body drum-entry-modal-body">
        <input type="hidden" name="csrf_token" value="<?php echo e($_SESSION['csrf_drum']); ?>">
        <input type="hidden" name="branch_code" id="branchCodeInput">
        <input type="hidden" name="branch_name" id="branchNameInput">
        <div class="table-responsive border rounded-3 bg-white">
          <table class="table table-bordered drum-add-table align-middle">
            <tbody>
              <tr>
                <th>ผู้บันทึกข้อมูล</th>
                <td>
                  <div class="drum-add-recorder">
                    <div><label class="form-label small fw-semibold mb-1">ชื่อผู้บันทึก</label><input class="form-control bg-light" value="<?php echo e($currentUserDisplayName); ?>" readonly></div>
                    <div><label class="form-label small fw-semibold mb-1">รหัสพนักงาน</label><input class="form-control bg-light" value="<?php echo e($currentEmployeeCode ?: '-'); ?>" readonly></div>
                  </div>
                </td>
              </tr>
              <tr>
                <th>รหัสสาขาใหญ่ <span class="text-danger">*</span></th>
                <td><div class="drum-add-search-row"><input type="text" class="form-control" id="mainBranchCode" name="main_branch_code" maxlength="3" inputmode="numeric" placeholder="เช่น 017" required><button class="btn btn-outline-primary drum-edit-search-btn" type="button" id="searchBranchBtn"><i class="bi bi-search me-1"></i>ค้นหา</button></div></td>
              </tr>
              <tr>
                <th>เลือกสาขาที่ต้องการจัดส่ง <span class="text-danger">*</span></th>
                <td><select class="form-select" id="branchSelect" disabled required><option value="">-- กรุณาค้นหาสาขาก่อน --</option></select><div class="branch-result drum-add-branch-result d-none" id="branchResult"></div></td>
              </tr>
              <tr>
                <th>รายการ Drum <span class="text-danger">*</span></th>
                <td>
                  <div class="drum-add-drums">
                    <?php foreach (['Drum-DR-3455'=>'สำหรับเครื่องปริ้นรุ่น : DCP-L5600DN,MFC-L5900DW','Drum-DR-3608'=>'สำหรับเครื่องปริ้นรุ่น : MFC-L5915DW'] as $code=>$desc): ?>
                    <div class="drum-add-drum-item">
                      <label class="drum-add-drum-select" for="<?php echo e(str_replace('-','_',$code)); ?>">
                        <input class="visually-hidden drum-checkbox" type="checkbox" name="drum_codes[]" value="<?php echo e($code); ?>" id="<?php echo e(str_replace('-','_',$code)); ?>">
                        <span><strong><?php echo e($code); ?></strong><small><?php echo e($desc); ?></small></span>
                      </label>
                      <div class="drum-add-qty-wrap">
                        <span class="drum-add-qty-label">จำนวน</span>
                        <input type="number" class="form-control drum-add-qty" name="drum_quantities[<?php echo e($code); ?>]" min="1" max="99" step="1" value="1" inputmode="numeric" aria-label="จำนวน <?php echo e($code); ?>">
                      </div>
                    </div>
                    <?php endforeach; ?>
                  </div>
                </td>
              </tr>
              <tr>
                <th>เลขที่ปัญหา <span class="text-danger">*</span></th>
                <td><input type="text" class="form-control" name="problem_no" id="problemNo" maxlength="100" required placeholder="ระบุเลขที่ปัญหา"></td>
              </tr>
              <tr>
                <th>หมายเหตุ</th>
                <td><textarea class="form-control" name="remark" id="remark" maxlength="500" rows="2" placeholder="ระบุหมายเหตุเพิ่มเติม (ถ้ามี)"></textarea><div class="form-text">ไม่บังคับกรอก สูงสุด 500 ตัวอักษร</div></td>
              </tr>
            </tbody>
          </table>
        </div>
        <div class="alert alert-info py-2 small drum-add-info"><i class="bi bi-info-circle me-1"></i>กรุณาค้นหาและเลือกสาขาปลายทาง จากนั้นเลือก Drum อย่างน้อย 1 รายการ</div>
        <div class="drum-duplicate-warning" id="drumDuplicateWarning" role="alert">
          <div class="fw-bold"><i class="bi bi-exclamation-triangle-fill me-1"></i>พบรายการเบิกหรือประวัติการจัดส่ง Drum ซ้ำไปยังสาขานี้</div>
          <ul class="drum-duplicate-list" id="drumDuplicateList"></ul>
          <!-- <div class="small">ระบบอนุญาตให้บันทึกซ้ำได้ แต่ต้องระบุเหตุผลเพื่อใช้ตรวจสอบย้อนหลัง</div> -->
          <div class="drum-repeat-reason-wrap" id="drumRepeatReasonWrap">
            <label for="repeatReason" class="form-label fw-bold mb-1">หมายเหตุ <span class="text-danger">*</span></label>
            <textarea class="form-control" name="repeat_reason" id="repeatReason" maxlength="500" placeholder="ระบุเหตุผล เช่น สาขาไม่ได้รับสินค้าเดิม, Drum ชำรุด หรือส่งเพิ่มเติม"></textarea>
            <div class="form-text">สูงสุด 500 ตัวอักษร</div>
          </div>
        </div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light border" id="resetBtn"><i class="bi bi-arrow-counterclockwise"></i> ล้างข้อมูล</button><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-primary px-4" id="saveBtn" disabled><i class="bi bi-save"></i> บันทึกการเบิก Drum</button></div>
    </form>
  </div>
</div>

<div class="modal fade drum-duplicate-detail-modal" id="drumDuplicateDetailModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
  <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-bold"><i class="bi bi-search me-1 text-primary"></i>รายละเอียดรายการ Drum ที่ซ้ำ</h5>
          <div class="small text-muted">ข้อมูลรายการที่เคยบันทึกหรือจัดส่งแล้ว</div>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
      </div>
      <div class="modal-body bg-light p-2">
        <div class="table-responsive border rounded-3 bg-white">
          <table class="table table-bordered drum-duplicate-detail-table align-middle">
            <tbody id="drumDuplicateDetailBody"></tbody>
          </table>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button>
      </div>
    </div>
  </div>
</div>
<script>
(function(){
 const code=document.getElementById('mainBranchCode');
 const branchSelect=document.getElementById('branchSelect');
 const result=document.getElementById('branchResult');
 const branchCodeInput=document.getElementById('branchCodeInput');
 const nameInput=document.getElementById('branchNameInput');
 const save=document.getElementById('saveBtn');
 const form=document.getElementById('drumForm');
 const duplicateWarning=document.getElementById('drumDuplicateWarning');
 const duplicateList=document.getElementById('drumDuplicateList');
 const repeatReasonWrap=document.getElementById('drumRepeatReasonWrap');
 const repeatReason=document.getElementById('repeatReason');
 const problemNo=document.getElementById('problemNo');
 const duplicateDetailModal=document.getElementById('drumDuplicateDetailModal');
 const duplicateDetailBody=document.getElementById('drumDuplicateDetailBody');
 let duplicateMatches=[];
 let duplicateCheckToken=0;
 let branchRows=[];
 function esc(value){return String(value??'').replace(/[&<>"]/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'}[ch]));}
 function formatMainCode(value){value=String(value||'').replace(/[^0-9]/g,'').slice(0,3);return value===''?'':value.padStart(3,'0');}
 function selectedDrums(){return Array.from(document.querySelectorAll('.drum-checkbox:checked')).map(item=>item.value);}
 function normalizeDrumQty(value){const qty=parseInt(String(value||'1'),10);return Number.isFinite(qty)?Math.min(99,Math.max(1,qty)):1;}
 function syncDrumQtyState(){
   document.querySelectorAll('.drum-add-drum-item').forEach(function(item){
     const checkbox=item.querySelector('.drum-checkbox');
     const qty=item.querySelector('.drum-add-qty');
     if(!checkbox||!qty)return;
     qty.disabled=!checkbox.checked;
     if(!checkbox.checked)qty.value='1';
     else qty.value=String(normalizeDrumQty(qty.value));
   });
 }
 function rebuildDrumSubmitInputs(){
   form.querySelectorAll('input[data-drum-submit-code="1"]').forEach(function(input){input.remove();});
   document.querySelectorAll('.drum-add-drum-item').forEach(function(item){
     const checkbox=item.querySelector('.drum-checkbox');
     const qty=item.querySelector('.drum-add-qty');
     if(!checkbox||!qty)return;
     qty.value=String(normalizeDrumQty(qty.value));
     qty.disabled=!checkbox.checked;
   });
 }
 function hideDuplicateWarning(){duplicateMatches=[];duplicateWarning.classList.remove('show');repeatReasonWrap.classList.remove('show');duplicateList.innerHTML='';repeatReason.required=false;repeatReason.value='';}
 function validate(){const baseReady=Boolean(branchCodeInput.value && nameInput.value && document.querySelector('.drum-checkbox:checked') && String(problemNo.value||'').trim());const reasonReady=!duplicateMatches.length||String(repeatReason.value||'').trim().length>=5;save.disabled=!(baseReady&&reasonReady);}
 function openDuplicateDetail(item){
   if(!duplicateDetailBody||!duplicateDetailModal)return;
   const rows=[
     ['เลขที่รายการ',item.request_no||'-'],
     ['รายการ Drum',item.drum_code||'-'],
     ['สถานะ',item.status_label||'-'],
     ['วันที่',item.recorded_date||item.shipped_date||'-'],
     ['รหัสสาขาใหญ่',item.main_branch_code||'-'],
     ['Cost Center',item.branch_code||branchCodeInput.value||'-'],
     ['ชื่อสาขา',item.branch_name||nameInput.value||'-'],
     ['ผู้บันทึก',item.recorded_by||'-'],
     ['หมายเหตุการส่งซ้ำ',item.repeat_reason||'-']
   ];
   duplicateDetailBody.innerHTML=rows.map(function(row){
     var label=row[0];
     var value=esc(row[1]);
     var valueClass='';

     if(label==='เลขที่รายการ'){
       valueClass=' class="drum-duplicate-detail-request-no"';
     }else if(label==='สถานะ' && String(row[1]||'').trim()==='รอจัดส่ง'){
       valueClass=' class="drum-duplicate-detail-status-pending"';
     }

     return '<tr><th>'+esc(label)+'</th><td'+valueClass+'>'+value+'</td></tr>';
   }).join('');
   bootstrap.Modal.getOrCreateInstance(duplicateDetailModal).show();
 }

async function checkDuplicateHistory() {
    const token = ++duplicateCheckToken;
    const drums = selectedDrums();

    hideDuplicateWarning();

    if (!branchCodeInput.value || !drums.length) {
        validate();
        return;
    }

    try {
        const params = new URLSearchParams({
            branch_code: branchCodeInput.value
        });

        drums.forEach(function (drum) {
            params.append('drum_codes[]', drum);
        });

        const response = await fetch(
            'check_duplicate.php?' + params.toString(),
            {
                headers: {
                    'X-Requested-With': 'XMLHttpRequest'
                }
            }
        );

        const json = await response.json();

        if (token !== duplicateCheckToken) {
            return;
        }

        if (!response.ok || json.success === false) {
            throw new Error(
                json.message || 'ตรวจสอบประวัติการจัดส่งซ้ำไม่สำเร็จ'
            );
        }

        duplicateMatches = Array.isArray(json.data)
            ? json.data
            : [];

        if (duplicateMatches.length) {
            duplicateList.innerHTML = duplicateMatches
                .map(function (item, index) {
                    return (
                        '<li>' +
                            '<button type="button" class="drum-duplicate-request-link js-duplicate-detail" ' +
                                'data-index="' + index + '" ' +
                                'title="ดูรายละเอียดรายการ ' + esc(item.request_no || '-') + '">' +
                                '<i class="bi bi-search"></i>' +
                                esc(item.request_no || '-') +
                            '</button> ' +
                            '<strong>' + esc(item.drum_code || '-') + '</strong> ' +
                            'สถานะ <strong>' + esc(item.status_label || '-') + '</strong> ' +
                            'วันที่ ' + esc(item.recorded_date || item.shipped_date || '-') +
                        '</li>'
                    );
                })
                .join('');

            duplicateList.querySelectorAll('.js-duplicate-detail').forEach(function(button){
                button.addEventListener('click',function(){
                    const item=duplicateMatches[Number(button.dataset.index)]||null;
                    if(item)openDuplicateDetail(item);
                });
            });

            duplicateWarning.classList.add('show');
            repeatReasonWrap.classList.add('show');
            repeatReason.required = true;
            repeatReason.focus();
        }

        validate();
    } catch (error) {
        if (token !== duplicateCheckToken) {
            return;
        }

        duplicateMatches = [];
        duplicateWarning.classList.add('show');

        duplicateList.innerHTML =
            '<li>' +
                esc(
                    error.message ||
                    'ไม่สามารถตรวจสอบข้อมูลซ้ำได้'
                ) +
            '</li>';

        repeatReasonWrap.classList.remove('show');
        repeatReason.required = false;
        save.disabled = true;
    }
}

 function clearSelected(){duplicateCheckToken++;hideDuplicateWarning();branchRows=[];branchCodeInput.value='';nameInput.value='';branchSelect.innerHTML='<option value="">-- กรุณาค้นหาสาขาก่อน --</option>';branchSelect.disabled=true;result.className='branch-result mt-3 d-none';result.innerHTML='';validate();}
 function resetForm(){form.reset();code.value='';clearSelected();document.querySelectorAll('.drum-checkbox').forEach(item=>item.checked=false);document.querySelectorAll('.drum-add-qty').forEach(item=>{item.value='1';item.disabled=true;});form.querySelectorAll('input[data-drum-submit-code="1"]').forEach(item=>item.remove());hideDuplicateWarning();validate();}
 function showMessage(type,message){result.className='alert alert-'+type+' py-2 mt-3 mb-0';result.textContent=message;}
 async function lookup(){
   const raw=String(code.value||'').replace(/[^0-9]/g,'').slice(0,3);code.value=raw;clearSelected();
   if(!/^\d{3}$/.test(raw)){showMessage('warning','กรุณากรอกรหัสสาขาใหญ่เป็นตัวเลข 3 หลัก เช่น 017');return;}
   const mainCode=formatMainCode(raw);branchSelect.innerHTML='<option value="">กำลังค้นหาข้อมูล...</option>';
   try{
     const params=new URLSearchParams({main_branch_code:mainCode,branch_code:mainCode});
     const response=await fetch(<?php echo json_encode($baseUrl . '/api/get_branches.php'); ?>+'?'+params.toString(),{headers:{'X-Requested-With':'XMLHttpRequest'}});
     const json=await response.json();if(!response.ok||json.success===false)throw new Error(json.message||'ไม่สามารถค้นหาข้อมูลสาขาได้');
     const rows=Array.isArray(json.data)?json.data:[];if(!rows.length){clearSelected();showMessage('warning','ไม่พบข้อมูลสาขาภายใต้รหัสสาขาใหญ่ '+mainCode);return;}
     branchRows=rows;branchSelect.innerHTML='<option value="">-- เลือกสาขา --</option>';
     rows.forEach((branch,index)=>{const option=document.createElement('option');option.value=String(index);option.textContent=(branch.branch_code||'-')+' - '+(branch.branch_name||branch.branch_name_2||'-');branchSelect.appendChild(option);});
     branchSelect.disabled=false;showMessage('success','พบทั้งหมด '+rows.length+' สาขา กรุณาเลือกสาขาที่ต้องการจัดส่ง');
   }catch(error){clearSelected();showMessage('danger',error.message||'เกิดข้อผิดพลาดในการเชื่อมต่อ API ค้นหาสาขา');}
 }
 document.getElementById('searchBranchBtn').addEventListener('click',lookup);
 code.addEventListener('input',function(){this.value=this.value.replace(/[^0-9]/g,'').slice(0,3);clearSelected();});
 code.addEventListener('keydown',e=>{if(e.key==='Enter'){e.preventDefault();lookup();}});
 branchSelect.addEventListener('change',function(){
   const index=Number(this.value);const branch=Number.isInteger(index)?branchRows[index]:null;
   if(!branch){branchCodeInput.value='';nameInput.value='';validate();return;}
   branchCodeInput.value=String(branch.branch_code||'').trim();nameInput.value=String(branch.branch_name||branch.branch_name_2||'').trim();
   result.className='branch-result mt-3';
   result.innerHTML='<div class="row g-2"><div class="col-md-4"><small class="text-muted">รหัสสาขาใหญ่</small><div class="fw-bold">'+esc(formatMainCode(branch.main_branch_code||code.value))+'</div></div><div class="col-md-4"><small class="text-muted">Cost Center</small><div class="fw-bold text-primary">'+esc(branchCodeInput.value||'-')+'</div></div><div class="col-md-4"><small class="text-muted">ชื่อสาขา</small><div class="fw-bold">'+esc(nameInput.value||'-')+'</div></div></div>';checkDuplicateHistory();
 });
 document.querySelectorAll('.drum-checkbox').forEach(function(x){x.addEventListener('change',function(){syncDrumQtyState();checkDuplicateHistory();});});
 document.querySelectorAll('.drum-add-qty').forEach(function(qty){
   qty.disabled=true;
   qty.addEventListener('input',function(){this.value=String(normalizeDrumQty(this.value));});
   qty.addEventListener('change',function(){this.value=String(normalizeDrumQty(this.value));});
 });
 repeatReason.addEventListener('input',validate);
 problemNo.addEventListener('input',validate);
 document.getElementById('resetBtn').addEventListener('click',resetForm);
 document.getElementById('addDrumBtn').addEventListener('click',resetForm);
 form.addEventListener('submit',e=>{if(!branchCodeInput.value||!nameInput.value||!document.querySelector('.drum-checkbox:checked')||!String(problemNo.value||'').trim()){e.preventDefault();if(!String(problemNo.value||'').trim()){problemNo.focus();alert('กรุณากรอกเลขที่ปัญหา');}else{alert('กรุณาค้นหา เลือกสาขาปลายทาง และเลือก Drum อย่างน้อย 1 รายการ');}return;}if(duplicateMatches.length&&String(repeatReason.value||'').trim().length<5){e.preventDefault();repeatReason.focus();alert('พบรายการเบิกหรือประวัติการจัดส่งซ้ำ กรุณาระบุเหตุผลในการส่งซ้ำอย่างน้อย 5 ตัวอักษร');return;}rebuildDrumSubmitInputs();});
 const editMainBranchCode=document.getElementById('editMainBranchCode');
 const editBranchSelect=document.getElementById('editBranchSelect');
 const editBranchCode=document.getElementById('editBranchCode');
 const editBranchName=document.getElementById('editBranchName');
 let editBranchRows=[];
 async function loadEditBranches(selectedBranchCode,selectedBranchName){
   if(!editMainBranchCode||!editBranchSelect)return;
   const raw=String(editMainBranchCode.value||'').replace(/[^0-9]/g,'').slice(0,3);editMainBranchCode.value=raw;
   editBranchRows=[];editBranchSelect.innerHTML='<option value="">กำลังโหลดข้อมูล...</option>';editBranchSelect.disabled=true;
   if(!/^\d{3}$/.test(raw)){editBranchSelect.innerHTML='<option value="">-- กรุณาระบุรหัสสาขาใหญ่ 3 หลัก --</option>';editBranchCode.value='';editBranchName.value='';return;}
   try{
     const params=new URLSearchParams({main_branch_code:formatMainCode(raw),branch_code:formatMainCode(raw)});
     const response=await fetch(<?php echo json_encode($baseUrl . '/api/get_branches.php'); ?>+'?'+params.toString(),{headers:{'X-Requested-With':'XMLHttpRequest'}});
     const json=await response.json();if(!response.ok||json.success===false)throw new Error(json.message||'ไม่สามารถโหลดข้อมูลสาขาได้');
     editBranchRows=Array.isArray(json.data)?json.data:[];editBranchSelect.innerHTML='<option value="">-- เลือกชื่อสาขา --</option>';
     let selectedIndex='';
     editBranchRows.forEach(function(branch,index){const option=document.createElement('option');option.value=String(index);option.textContent=(branch.branch_name||branch.branch_name_2||'-')+' | Cost Center '+(branch.branch_code||'-');editBranchSelect.appendChild(option);if((selectedBranchCode&&String(branch.branch_code||'')===String(selectedBranchCode))||(!selectedBranchCode&&selectedBranchName&&(branch.branch_name===selectedBranchName||branch.branch_name_2===selectedBranchName)))selectedIndex=String(index);});
     editBranchSelect.disabled=false;
     if(selectedIndex!==''){editBranchSelect.value=selectedIndex;editBranchSelect.dispatchEvent(new Event('change'));}
   }catch(error){editBranchSelect.innerHTML='<option value="">'+esc(error.message||'โหลดข้อมูลสาขาไม่สำเร็จ')+'</option>';editBranchCode.value='';editBranchName.value='';}
 }
 if(document.getElementById('editLoadBranchesBtn'))document.getElementById('editLoadBranchesBtn').addEventListener('click',function(){loadEditBranches('','');});
 if(editMainBranchCode){editMainBranchCode.addEventListener('input',function(){this.value=this.value.replace(/[^0-9]/g,'').slice(0,3);editBranchSelect.innerHTML='<option value="">-- กดโหลดสาขาในสังกัด --</option>';editBranchSelect.disabled=true;editBranchCode.value='';editBranchName.value='';});}
 if(editBranchSelect)editBranchSelect.addEventListener('change',function(){const index=Number(this.value);const branch=Number.isInteger(index)?editBranchRows[index]:null;editBranchCode.value=branch?String(branch.branch_code||'').trim():'';editBranchName.value=branch?String(branch.branch_name||branch.branch_name_2||'').trim():'';});
 document.querySelectorAll('.js-drum-edit').forEach(function(button){button.addEventListener('click',function(){
   const requestNo=document.getElementById('editRequestNo');if(!requestNo)return;
   requestNo.value=button.dataset.requestNo||'';
   editMainBranchCode.value=button.dataset.mainBranchCode||'';
   const editRecordedBy=document.getElementById('editRecordedBy');
   const recordedByValue=button.dataset.recordedBy||'';
   if(editRecordedBy){
     let matched=false;
     Array.from(editRecordedBy.options).forEach(function(option){if(option.value===recordedByValue)matched=true;});
     if(recordedByValue!==''&&!matched){const option=document.createElement('option');option.value=recordedByValue;option.textContent=recordedByValue+' (ข้อมูลเดิม)';editRecordedBy.appendChild(option);}
     editRecordedBy.value=recordedByValue;
   }
   document.getElementById('editCreatedAt').value=button.dataset.createdAt||'';
   document.getElementById('editProblemNo').value=button.dataset.problemNo||'';
   document.getElementById('editRemark').value=button.dataset.remark||'';
   const selectedCounts={};
   const drumItems=(button.dataset.drumItems||'').split(',').map(v=>v.trim()).filter(Boolean);
   if(drumItems.length){drumItems.forEach(function(item){const parts=item.split('|');const code=String(parts[0]||'').trim();const qty=Math.max(1,parseInt(parts[1]||'1',10)||1);if(code)selectedCounts[code]=qty;});}
   else{(button.dataset.drumCodes||'').split(',').map(v=>v.trim()).filter(Boolean).forEach(function(code){selectedCounts[code]=(selectedCounts[code]||0)+1;});}
   document.querySelectorAll('.drum-edit-drum-item').forEach(function(item){const cb=item.querySelector('.edit-drum-checkbox');const qty=item.querySelector('.drum-edit-qty');if(!cb)return;const count=Number(selectedCounts[cb.value]||0);cb.checked=count>0;if(qty){qty.value=String(count>0?count:1);qty.disabled=count===0;}});
   editForm&&editForm.querySelectorAll('input[data-edit-drum-submit-code="1"]').forEach(function(input){input.remove();});
   loadEditBranches(button.dataset.branchCode||'',button.dataset.branchName||'');
 });});
 document.querySelectorAll('.js-drum-delete').forEach(function(button){button.addEventListener('click',function(){const requestNo=button.dataset.requestNo||'';const input=document.getElementById('deleteRequestNo');const text=document.getElementById('deleteRequestNoText');if(input)input.value=requestNo;if(text)text.textContent=requestNo||'-';});});
 function normalizeEditDrumQty(value){const qty=parseInt(String(value||'1'),10);return Number.isFinite(qty)?Math.min(99,Math.max(1,qty)):1;}
 function syncEditDrumQtyState(){document.querySelectorAll('.drum-edit-drum-item').forEach(function(item){const checkbox=item.querySelector('.edit-drum-checkbox');const qty=item.querySelector('.drum-edit-qty');if(!checkbox||!qty)return;qty.disabled=!checkbox.checked;if(!checkbox.checked)qty.value='1';else qty.value=String(normalizeEditDrumQty(qty.value));});}
 function rebuildEditDrumSubmitInputs(){if(!editForm)return;editForm.querySelectorAll('input[data-edit-drum-submit-code="1"]').forEach(function(input){input.remove();});document.querySelectorAll('.drum-edit-drum-item').forEach(function(item){const checkbox=item.querySelector('.edit-drum-checkbox');const qty=item.querySelector('.drum-edit-qty');if(!checkbox||!checkbox.checked)return;const count=normalizeEditDrumQty(qty?qty.value:1);for(let i=0;i<count;i++){const hidden=document.createElement('input');hidden.type='hidden';hidden.name='drum_codes[]';hidden.value=checkbox.value;hidden.dataset.editDrumSubmitCode='1';editForm.appendChild(hidden);}});}
 document.querySelectorAll('.edit-drum-checkbox').forEach(function(cb){cb.addEventListener('change',syncEditDrumQtyState);});
 document.querySelectorAll('.drum-edit-qty').forEach(function(qty){qty.disabled=true;qty.addEventListener('input',function(){this.value=String(normalizeEditDrumQty(this.value));});qty.addEventListener('change',function(){this.value=String(normalizeEditDrumQty(this.value));});});
 const editForm=document.getElementById('drumEditForm');if(editForm)editForm.addEventListener('submit',function(e){if(!document.querySelector('.edit-drum-checkbox:checked')){e.preventDefault();alert('กรุณาเลือก Drum อย่างน้อย 1 รายการ');return;}const editProblemNo=document.getElementById('editProblemNo');if(!editProblemNo||!String(editProblemNo.value||'').trim()){e.preventDefault();if(editProblemNo)editProblemNo.focus();alert('กรุณากรอกเลขที่ปัญหา');return;}rebuildEditDrumSubmitInputs();});
 function applyDrumShippedState(requestNo){
   requestNo=String(requestNo||'').trim();
   if(!requestNo)return;
   const row=document.querySelector('[data-drum-request-row="'+CSS.escape(requestNo)+'"]');
   if(!row)return;
   const coverButton=row.querySelector('.js-drum-cover');
   if(coverButton){coverButton.classList.add('drum-cover-locked');coverButton.removeAttribute('href');coverButton.setAttribute('aria-disabled','true');coverButton.setAttribute('title','พิมพ์ใบปะหน้าและยืนยันจัดส่งแล้ว');}
   const status=row.querySelector('.js-drum-status');
   if(status){status.classList.remove('drum-status-pending');status.classList.add('drum-status-shipped');status.textContent='จัดส่งแล้ว';}
   row.querySelectorAll('.js-drum-edit,.js-drum-delete').forEach(function(button){button.disabled=true;button.classList.add('disabled');});
   window.setTimeout(function(){window.location.reload();},250);
 }
 window.addEventListener('message',function(event){
   if(event.data&&event.data.type==='drum-shipped'){applyDrumShippedState(event.data.requestNo);}
 });
 window.addEventListener('storage',function(event){
   if(event.key==='drum_shipped_event'&&event.newValue){
     try{const data=JSON.parse(event.newValue);applyDrumShippedState(data.requestNo);}catch(ignore){}
   }
 });
})();
</script>
<?php if (file_exists(__DIR__ . '/../../includes/footer.php')) require_once __DIR__ . '/../../includes/footer.php'; ?>


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
