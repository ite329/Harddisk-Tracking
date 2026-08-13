<?php

require_once __DIR__ . '/../../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$pageTitle = 'บันทึกข้อมูลชื่อเครื่องคอม';

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/data_database.php';

if (!function_exists('dcE')) {
    function dcE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dcClean')) {
    function dcClean($value): string
    {
        return trim((string)($value ?? ''));
    }
}


if (!function_exists('dcIsValidComputerName')) {
    function dcIsValidComputerName(string $value): bool
    {
        return preg_match('/^[A-Za-z0-9]+$/', $value) === 1;
    }
}

if (!function_exists('dcQuoteColumn')) {
    function dcQuoteColumn(string $column): string
    {
        return '`' . str_replace('`', '``', $column) . '`';
    }
}

if (!function_exists('dcCurrentUser')) {
    function dcCurrentUser(): string
    {
        $fullName = dcClean($_SESSION['full_name'] ?? '');
        $employeeCode = dcClean($_SESSION['employee_code'] ?? '');

        if ($fullName !== '' && $employeeCode !== '') {
            return $fullName . ' (' . $employeeCode . ')';
        }
        if ($fullName !== '') {
            return $fullName;
        }
        if ($employeeCode !== '') {
            return $employeeCode;
        }
        foreach (['emp_code', 'username', 'employee_id', 'id'] as $key) {
            $value = dcClean($_SESSION[$key] ?? '');
            if ($value !== '') {
                return $value;
            }
        }
        return 'system';
    }
}


if (!function_exists('dcCurrentEmployeeCode')) {
    function dcCurrentEmployeeCode(): string
    {
        foreach (['employee_code', 'emp_code', 'employee_id', 'username', 'id'] as $key) {
            $value = dcClean($_SESSION[$key] ?? '');
            if ($value !== '') {
                return dcNormalizeEmployeeCode($value);
            }
        }

        if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
            foreach (['employee_code', 'emp_code', 'employee_id', 'username', 'id', 'code'] as $key) {
                $value = dcClean($_SESSION['user'][$key] ?? '');
                if ($value !== '') {
                    return dcNormalizeEmployeeCode($value);
                }
            }
        }

        return '';
    }
}

if (!function_exists('dcIsSuperAdmin')) {
    function dcIsSuperAdmin(): bool
    {
        return function_exists('can') && can('delete_computer.manage');
    }
}


if (!function_exists('dcNormalizeEmployeeCode')) {
    function dcNormalizeEmployeeCode(string $value): string
    {
        $value = dcClean($value);
        if ($value === '' || $value === '-') {
            return '';
        }

        // รหัสพนักงานในตาราง users เก็บเป็น 5 หลัก เช่น 06836
        // แต่ข้อมูลเดิมใน delete_computer บางรายการเก็บเป็น 6836
        if (preg_match('/^[0-9]{1,4}$/', $value)) {
            return str_pad($value, 5, '0', STR_PAD_LEFT);
        }

        return $value;
    }
}


if (!function_exists('dcRecorderCodeFromRow')) {
    function dcRecorderCodeFromRow(array $row, array $availableColumns): string
    {
        $candidates = [
            'de_name_l_new',
            'de_name_l_del',
            'created_by',
            'create_by',
            'user_create',
            'record_by',
        ];

        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($availableColumns[$key])) {
                $column = $availableColumns[$key];
                $value = dcClean($row[$column] ?? '');
                if ($value !== '' && $value !== '-') {
                    return $value;
                }
            }
        }

        foreach ($candidates as $candidate) {
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === strtolower($candidate)) {
                    $text = dcClean($value ?? '');
                    if ($text !== '' && $text !== '-') {
                        return $text;
                    }
                }
            }
        }

        return '';
    }
}


if (!function_exists('dcDeleterCodeFromRow')) {
    function dcDeleterCodeFromRow(array $row, array $availableColumns): string
    {
        $candidates = [
            'de_name_l_del',
            'deleted_by',
            'delete_by',
            'user_delete',
            'updated_by',
        ];

        foreach ($candidates as $candidate) {
            $key = strtolower($candidate);
            if (isset($availableColumns[$key])) {
                $column = $availableColumns[$key];
                $value = dcClean($row[$column] ?? '');
                if ($value !== '' && $value !== '-') {
                    return $value;
                }
            }
        }

        foreach ($candidates as $candidate) {
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === strtolower($candidate)) {
                    $text = dcClean($value ?? '');
                    if ($text !== '' && $text !== '-') {
                        return $text;
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('dcBuildUserDisplayMap')) {
    function dcBuildUserDisplayMap(PDO $pdo, array $employeeCodes): array
    {
        $rawEmployeeCodes = array_values(array_unique(array_filter(array_map('dcClean', $employeeCodes), static function ($value) {
            return $value !== '' && $value !== '-';
        })));

        $employeeCodes = [];
        foreach ($rawEmployeeCodes as $code) {
            if (!in_array($code, $employeeCodes, true)) {
                $employeeCodes[] = $code;
            }

            $normalizedCode = dcNormalizeEmployeeCode($code);
            if ($normalizedCode !== '' && !in_array($normalizedCode, $employeeCodes, true)) {
                $employeeCodes[] = $normalizedCode;
            }
        }

        if (empty($employeeCodes)) {
            return [];
        }

        try {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
            $stmt->execute();
            if ((int)$stmt->fetchColumn() === 0) {
                return [];
            }

            $columnsStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
            $columnsStmt->execute();
            $columns = [];
            foreach ($columnsStmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
                $column = (string)$column;
                $columns[strtolower($column)] = $column;
            }

            if (!isset($columns['employee_code'])) {
                return [];
            }

            $displayCandidates = [
                'full_name', 'name', 'employee_name', 'fullname', 'display_name',
                'first_name', 'firstname', 'fname', 'last_name', 'lastname', 'lname',
                'username'
            ];
            $selectParts = [dcQuoteColumn($columns['employee_code']) . ' AS employee_code'];
            foreach ($displayCandidates as $candidate) {
                if (isset($columns[$candidate])) {
                    $selectParts[] = dcQuoteColumn($columns[$candidate]) . ' AS ' . dcQuoteColumn($candidate);
                }
            }

            $placeholders = [];
            $params = [];
            foreach ($employeeCodes as $index => $code) {
                $param = ':emp_' . $index;
                $placeholders[] = $param;
                $params[$param] = $code;
            }

            $sql = 'SELECT ' . implode(', ', $selectParts) . ' FROM `users` WHERE ' . dcQuoteColumn($columns['employee_code']) . ' IN (' . implode(', ', $placeholders) . ')';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);

            $map = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $user) {
                $code = dcClean($user['employee_code'] ?? '');
                if ($code === '') {
                    continue;
                }

                $name = '';
                foreach (['full_name', 'name', 'employee_name', 'fullname', 'display_name'] as $field) {
                    if (isset($user[$field]) && dcClean($user[$field]) !== '') {
                        $name = dcClean($user[$field]);
                        break;
                    }
                }

                if ($name === '') {
                    $first = '';
                    $last = '';
                    foreach (['first_name', 'firstname', 'fname'] as $field) {
                        if (isset($user[$field]) && dcClean($user[$field]) !== '') {
                            $first = dcClean($user[$field]);
                            break;
                        }
                    }
                    foreach (['last_name', 'lastname', 'lname'] as $field) {
                        if (isset($user[$field]) && dcClean($user[$field]) !== '') {
                            $last = dcClean($user[$field]);
                            break;
                        }
                    }
                    $name = trim($first . ' ' . $last);
                }

                if ($name === '' && isset($user['username'])) {
                    $name = dcClean($user['username']);
                }

                $normalizedCode = dcNormalizeEmployeeCode($code);
                $displayCode = $normalizedCode !== '' ? $normalizedCode : $code;
                $displayText = $name !== '' ? $name . ' (' . $displayCode . ')' : $displayCode;

                $map[$code] = $displayText;
                if ($normalizedCode !== '') {
                    $map[$normalizedCode] = $displayText;
                    $map[ltrim($normalizedCode, '0')] = $displayText;
                }
            }

            return $map;
        } catch (Throwable $e) {
            return [];
        }
    }
}

if (!function_exists('dcTableExists')) {
    function dcTableExists(PDO $pdo, string $dbName, string $tableName): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name');
        $stmt->execute([':db_name' => $dbName, ':table_name' => $tableName]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('dcColumnExists')) {
    function dcColumnExists(PDO $pdo, string $dbName, string $tableName, string $columnName): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name AND COLUMN_NAME = :column_name');
        $stmt->execute([
            ':db_name' => $dbName,
            ':table_name' => $tableName,
            ':column_name' => $columnName,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('dcPrimaryKeyColumn')) {
    function dcPrimaryKeyColumn(PDO $pdo, string $dbName, string $tableName): ?string
    {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION ASC LIMIT 1");
        $stmt->execute([':db_name' => $dbName, ':table_name' => $tableName]);
        $column = $stmt->fetchColumn();
        return $column !== false ? (string)$column : null;
    }
}

if (!function_exists('dcGetColumns')) {
    function dcGetColumns(PDO $pdo, string $dbName, string $tableName): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name ORDER BY ORDINAL_POSITION ASC');
        $stmt->execute([':db_name' => $dbName, ':table_name' => $tableName]);
        $columns = [];
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $column) {
            $column = (string)$column;
            if ($column !== '') {
                $columns[strtolower($column)] = $column;
            }
        }
        return $columns;
    }
}

if (!function_exists('dcResolveColumn')) {
    function dcResolveColumn(array $availableColumns, array $candidates): ?string
    {
        foreach ($candidates as $candidate) {
            $key = strtolower((string)$candidate);
            if (isset($availableColumns[$key])) {
                return $availableColumns[$key];
            }
        }
        return null;
    }
}

if (!function_exists('dcExistingColumns')) {
    function dcExistingColumns(array $availableColumns, array $candidates): array
    {
        $result = [];
        foreach ($candidates as $candidate) {
            $key = strtolower((string)$candidate);
            if (isset($availableColumns[$key]) && !in_array($availableColumns[$key], $result, true)) {
                $result[] = $availableColumns[$key];
            }
        }
        return $result;
    }
}

if (!function_exists('dcFieldCandidates')) {
    function dcFieldCandidates(): array
    {
        return [
            'new_computer_name' => [
                'name_com_new', 'new_computer_name', 'computer_name_new', 'new_computer', 'new_pc_name', 'pc_name_new',
                'new_name', 'name_new', 'new_hostname', 'hostname_new', 'new_host_name', 'host_name_new',
                'computer_new', 'com_name_new', 'com_new', 'pc_new', 'new_machine_name', 'machine_name_new', 'comname_new', 'computername_new', 'computer_new_name', 'new_comp_name', 'newcomputer', 'newcomputername', 'computer_name', 'com_name', 'hostname', 'host_name', 'pc_name', 'machine_name'
            ],
            'old_computer_name' => [
                'name_com_del', 'old_computer_name', 'computer_name_old', 'old_computer', 'old_pc_name', 'pc_name_old',
                'old_name', 'name_old', 'old_hostname', 'hostname_old', 'old_host_name', 'host_name_old',
                'computer_old', 'com_name_old', 'com_old', 'pc_old', 'old_machine_name', 'machine_name_old', 'comname_old', 'computername_old', 'computer_old_name', 'old_comp_name', 'oldcomputer', 'oldcomputername', 'computer_name_old', 'com_name_old', 'hostname_old', 'host_name_old', 'pc_name_old', 'machine_name_old', 'delete_computer_name', 'deleted_computer_name'
            ],
            'delete_status' => ['de_poin', 'delete_status', 'delete_flag', 'is_deleted'],
            'created_by' => ['created_by', 'create_by', 'user_create', 'record_by'],
            'updated_by' => ['updated_by', 'update_by', 'user_update'],
            'created_at' => ['created_at', 'created_date', 'date_create', 'recorded_at', 'recorded_date'],
            'updated_at' => ['updated_at', 'updated_date', 'date_update'],
            'deleted_at' => ['deleted_at', 'delete_at', 'deleted_date'],
        ];
    }
}

if (!function_exists('dcColumnMap')) {
    function dcColumnMap(array $availableColumns): array
    {
        $map = [];
        foreach (dcFieldCandidates() as $field => $candidates) {
            $map[$field] = dcResolveColumn($availableColumns, $candidates);
        }
        return $map;
    }
}


if (!function_exists('dcFallbackValueFromRow')) {
    function dcFallbackValueFromRow(array $row, string $field): string
    {
        $ignoreColumns = [
            'id', 'deleted_at', 'delete_at', 'deleted_date', 'updated_at', 'updated_date',
            'created_at', 'created_date', 'date_create', 'recorded_at', 'recorded_date',
            'updated_by', 'update_by', 'user_update', 'created_by', 'create_by',
            'user_create', 'record_by'
        ];

        $nonSystemValues = [];
        foreach ($row as $key => $value) {
            $columnName = strtolower((string)$key);
            $text = dcClean($value ?? '');
            if ($text === '' || $text === '-' || in_array($columnName, $ignoreColumns, true)) {
                continue;
            }
            $nonSystemValues[] = [
                'column' => $columnName,
                'value' => $text,
            ];
        }

        foreach ($nonSystemValues as $item) {
            $columnName = $item['column'];
            $text = $item['value'];

            if ($field === 'new_computer_name' && preg_match('/(new|ใหม่|computer_name|com_name|hostname|host|pc|machine)/i', $columnName) && !preg_match('/(old|เก่า|delete|del|remove)/i', $columnName)) {
                return $text;
            }

            if ($field === 'old_computer_name' && preg_match('/(old|เก่า|delete|del|remove|computer_name|com_name|hostname|host|pc|machine)/i', $columnName) && !preg_match('/(new|ใหม่)/i', $columnName)) {
                return $text;
            }
        }

        // ตารางเดิมบางชุดอาจมีเพียง 2 คอลัมน์ข้อมูล แต่ชื่อคอลัมน์ไม่ตรงกับระบบใหม่
        // จึงใช้ลำดับคอลัมน์จริงเป็น fallback: คอลัมน์ข้อมูลแรก = ชื่อเครื่องใหม่, คอลัมน์ข้อมูลที่สอง = ชื่อเครื่องเก่า
        if ($field === 'new_computer_name' && isset($nonSystemValues[0]['value'])) {
            return $nonSystemValues[0]['value'];
        }

        if ($field === 'old_computer_name' && isset($nonSystemValues[1]['value'])) {
            return $nonSystemValues[1]['value'];
        }

        return '';
    }
}

if (!function_exists('dcRawValue')) {
    function dcRawValue(array $row, array $columnMap, string $field): string
    {
        $column = $columnMap[$field] ?? null;
        if ($column !== null && array_key_exists($column, $row)) {
            $value = dcClean($row[$column] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        foreach (dcFieldCandidates()[$field] ?? [] as $candidate) {
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === strtolower((string)$candidate)) {
                    $text = dcClean($value ?? '');
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        $fallbackValue = dcFallbackValueFromRow($row, $field);
        return $fallbackValue !== '' ? $fallbackValue : '';
    }
}

if (!function_exists('dcValue')) {
    function dcValue(array $row, array $columnMap, string $field): string
    {
        $value = dcRawValue($row, $columnMap, $field);
        return $value !== '' ? $value : '-';
    }
}

if (!function_exists('dcCreatedDateFromRow')) {
    function dcCreatedDateFromRow(array $row, array $availableColumns, array $columnMap): string
    {
        $column = $columnMap['created_at'] ?? null;
        if ($column !== null && array_key_exists($column, $row)) {
            $value = dcClean($row[$column] ?? '');
            if ($value !== '' && $value !== '0000-00-00' && $value !== '0000-00-00 00:00:00') {
                return $value;
            }
        }

        foreach (['created_at', 'created_date', 'date_create', 'recorded_at', 'recorded_date'] as $candidate) {
            $key = strtolower($candidate);
            if (isset($availableColumns[$key])) {
                $column = $availableColumns[$key];
                $value = dcClean($row[$column] ?? '');
                if ($value !== '' && $value !== '0000-00-00' && $value !== '0000-00-00 00:00:00') {
                    return $value;
                }
            }
        }

        return '-';
    }
}


if (!function_exists('dcDeleteStatusFromRow')) {
    function dcDeleteStatusFromRow(array $row, array $availableColumns, array $columnMap): string
    {
        $column = $columnMap['delete_status'] ?? null;
        if ($column !== null && array_key_exists($column, $row)) {
            $value = dcClean($row[$column] ?? '');
            if ($value !== '') {
                return $value;
            }
        }

        foreach (['de_poin', 'delete_status', 'delete_flag', 'is_deleted'] as $candidate) {
            $key = strtolower($candidate);
            if (isset($availableColumns[$key])) {
                $column = $availableColumns[$key];
                $value = dcClean($row[$column] ?? '');
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '2';
    }
}


if (!function_exists('dcEnsureTable')) {
    function dcEnsureTable(PDO $pdo, string $dbName): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `delete_computer` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name_com_new` VARCHAR(150) NOT NULL DEFAULT '',
            `name_com_del` VARCHAR(150) NULL,
            `new_computer_name` VARCHAR(150) NOT NULL DEFAULT '',
            `old_computer_name` VARCHAR(150) NULL,
            `de_name_l_new` VARCHAR(50) NULL,
            `de_name_l_del` VARCHAR(50) NULL,
            `de_poin` VARCHAR(10) NOT NULL DEFAULT '2',
            `created_by` VARCHAR(180) NULL,
            `updated_by` VARCHAR(180) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL,
            `deleted_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_new_computer_name` (`new_computer_name`),
            KEY `idx_old_computer_name` (`old_computer_name`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = [
            'name_com_new' => "VARCHAR(150) NOT NULL DEFAULT ''",
            'name_com_del' => 'VARCHAR(150) NULL',
            'new_computer_name' => "VARCHAR(150) NOT NULL DEFAULT ''",
            'old_computer_name' => 'VARCHAR(150) NULL',
            'de_name_l_new' => 'VARCHAR(50) NULL',
            'de_name_l_del' => 'VARCHAR(50) NULL',
            'de_poin' => "VARCHAR(10) NOT NULL DEFAULT '2'",
            'created_by' => 'VARCHAR(180) NULL',
            'updated_by' => 'VARCHAR(180) NULL',
            'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME NULL DEFAULT NULL',
            'deleted_at' => 'DATETIME NULL DEFAULT NULL',
        ];

        foreach ($columns as $column => $definition) {
            if (!dcColumnExists($pdo, $dbName, 'delete_computer', $column)) {
                $pdo->exec('ALTER TABLE `delete_computer` ADD COLUMN ' . dcQuoteColumn($column) . ' ' . $definition);
            }
        }

        if (!dcPrimaryKeyColumn($pdo, $dbName, 'delete_computer') && !dcColumnExists($pdo, $dbName, 'delete_computer', 'id')) {
            $pdo->exec('ALTER TABLE `delete_computer` ADD COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
        }
    }
}

if (!function_exists('dcBuildWriteColumns')) {
    function dcBuildWriteColumns(array $availableColumns): array
    {
        $result = [];
        foreach (['new_computer_name', 'old_computer_name'] as $field) {
            $columns = dcExistingColumns($availableColumns, dcFieldCandidates()[$field] ?? []);
            if (!empty($columns)) {
                $result[$field] = $columns;
            }
        }
        return $result;
    }
}

if (!function_exists('dcFetchRow')) {
    function dcFetchRow(PDO $pdo, string $primaryKey, $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM `delete_computer` WHERE ' . dcQuoteColumn($primaryKey) . ' = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

$dataDbName = $DATA_DB_NAME ?? 'data';
$pageError = '';
$pageSuccess = '';
$computerRows = [];
$activeComputerRows = [];
$deletedComputerRows = [];
$editRow = null;
$primaryKey = 'id';
$query = dcClean($_GET['q'] ?? '');
$editId = dcClean($_GET['edit'] ?? '');
$activePage = max(1, (int)($_GET['active_page'] ?? ($_GET['page'] ?? 1)));
$deletedPage = max(1, (int)($_GET['deleted_page'] ?? 1));
$perPage = 20;
$totalRows = 0;
$activeTotalRows = 0;
$deletedTotalRows = 0;
$activeTotalPages = 1;
$deletedTotalPages = 1;
$activeOffset = 0;
$deletedOffset = 0;
$availableColumns = [];
$columnMap = [];
$writeColumns = [];
$userDisplayMap = [];
$isSuperAdmin = dcIsSuperAdmin();

if ($dataDbError !== '') {
    $pageError = $dataDbError;
} elseif (!$dataPdo instanceof PDO) {
    $pageError = 'ไม่พบการเชื่อมต่อฐานข้อมูล data';
} else {
    try {
        dcEnsureTable($dataPdo, $dataDbName);
        $availableColumns = dcGetColumns($dataPdo, $dataDbName, 'delete_computer');
        $columnMap = dcColumnMap($availableColumns);
        $writeColumns = dcBuildWriteColumns($availableColumns);
        $primaryKey = dcPrimaryKeyColumn($dataPdo, $dataDbName, 'delete_computer') ?: 'id';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = dcClean($_POST['action'] ?? '');
            $recordId = dcClean($_POST['record_id'] ?? '');

            if ($action === 'delete' && $recordId !== '') {
                if (!$isSuperAdmin) {
                    $pageError = 'คุณไม่มีสิทธิ์ลบข้อมูล ต้องใช้สิทธิ์ super admin';
                } else {
                    $setParts = [];
                    $params = [':id' => $recordId];

                    if (isset($availableColumns['de_poin'])) {
                        $setParts[] = dcQuoteColumn($availableColumns['de_poin']) . " = '1'";
                    }
                    if (isset($availableColumns['de_name_l_del'])) {
                        $setParts[] = dcQuoteColumn($availableColumns['de_name_l_del']) . ' = :de_name_l_del';
                        $params[':de_name_l_del'] = dcCurrentEmployeeCode();
                    }
                    if (isset($availableColumns['updated_by'])) {
                        $setParts[] = dcQuoteColumn($availableColumns['updated_by']) . ' = :updated_by';
                        $params[':updated_by'] = dcCurrentUser();
                    }
                    if (isset($availableColumns['updated_at'])) {
                        $setParts[] = dcQuoteColumn($availableColumns['updated_at']) . ' = NOW()';
                    }

                    if (!empty($setParts)) {
                        $stmt = $dataPdo->prepare('UPDATE `delete_computer` SET ' . implode(', ', $setParts) . ' WHERE ' . dcQuoteColumn($primaryKey) . ' = :id');
                        $stmt->execute($params);
                    }
                }
                if ($pageError === '') {
                    $pageSuccess = 'เปลี่ยนสถานะเป็นลบแล้วเรียบร้อยแล้ว';
                }
            } elseif ($action === 'save') {
                $newName = dcClean($_POST['new_computer_name'] ?? '');
                $oldName = dcClean($_POST['old_computer_name'] ?? '');

                if ($newName === '') {
                    $pageError = 'กรุณากรอกชื่อเครื่องคอมใหม่';
                } elseif (!dcIsValidComputerName($newName)) {
                    $pageError = 'ชื่อเครื่องคอมใหม่ ต้องกรอกได้เฉพาะภาษาอังกฤษและตัวเลขเท่านั้น';
                } elseif ($oldName === '') {
                    $pageError = 'กรุณากรอกชื่อเครื่องเก่า';
                } elseif (!dcIsValidComputerName($oldName)) {
                    $pageError = 'ชื่อเครื่องเก่า ต้องกรอกได้เฉพาะภาษาอังกฤษและตัวเลขเท่านั้น';
                } else {
                    $inputValues = [
                        'new_computer_name' => $newName,
                        'old_computer_name' => $oldName,
                    ];

                    if ($recordId !== '') {
                        if (!$isSuperAdmin) {
                            $pageError = 'คุณไม่มีสิทธิ์แก้ไขข้อมูล ต้องใช้สิทธิ์ super admin';
                        } else {
                        $setParts = [];
                        $params = [':id' => $recordId];
                        $paramIndex = 0;

                        foreach ($inputValues as $field => $value) {
                            foreach ($writeColumns[$field] ?? [] as $column) {
                                $param = ':p_' . $paramIndex++;
                                $setParts[] = dcQuoteColumn($column) . ' = ' . $param;
                                $params[$param] = $value;
                            }
                        }

                        if (isset($availableColumns['updated_by'])) {
                            $setParts[] = dcQuoteColumn($availableColumns['updated_by']) . ' = :updated_by';
                            $params[':updated_by'] = dcCurrentUser();
                        }
                        if (isset($availableColumns['updated_at'])) {
                            $setParts[] = dcQuoteColumn($availableColumns['updated_at']) . ' = NOW()';
                        }

                        if (!empty($setParts)) {
                            $stmt = $dataPdo->prepare('UPDATE `delete_computer` SET ' . implode(', ', $setParts) . ' WHERE ' . dcQuoteColumn($primaryKey) . ' = :id');
                            $stmt->execute($params);
                            $pageSuccess = 'แก้ไขข้อมูลชื่อเครื่องคอมเรียบร้อยแล้ว';
                        }
                        }
                    } else {
                        $columns = [];
                        $placeholders = [];
                        $params = [];
                        $paramIndex = 0;

                        foreach ($inputValues as $field => $value) {
                            foreach ($writeColumns[$field] ?? [] as $column) {
                                $param = ':p_' . $paramIndex++;
                                $columns[] = dcQuoteColumn($column);
                                $placeholders[] = $param;
                                $params[$param] = $value;
                            }
                        }

                        if (isset($availableColumns['de_name_l_new'])) {
                            $columns[] = dcQuoteColumn($availableColumns['de_name_l_new']);
                            $placeholders[] = ':de_name_l_new';
                            $params[':de_name_l_new'] = dcCurrentEmployeeCode();
                        }
                        if (isset($availableColumns['de_poin'])) {
                            $columns[] = dcQuoteColumn($availableColumns['de_poin']);
                            $placeholders[] = ':de_poin';
                            $params[':de_poin'] = '2';
                        }
                        if (isset($availableColumns['created_by'])) {
                            $columns[] = dcQuoteColumn($availableColumns['created_by']);
                            $placeholders[] = ':created_by';
                            $params[':created_by'] = date('Y-m-d');
                        }
                        if (isset($availableColumns['created_at'])) {
                            $columns[] = dcQuoteColumn($availableColumns['created_at']);
                            $placeholders[] = 'NOW()';
                        }

                        $stmt = $dataPdo->prepare('INSERT INTO `delete_computer` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
                        $stmt->execute($params);
                        $pageSuccess = 'บันทึกข้อมูลชื่อเครื่องคอมเรียบร้อยแล้ว';
                    }
                }
            }
        }

        if ($editId !== '') {
            if ($isSuperAdmin) {
                $editRow = dcFetchRow($dataPdo, $primaryKey, $editId);
            } else {
                $pageError = 'คุณไม่มีสิทธิ์แก้ไขข้อมูล ต้องใช้สิทธิ์ super admin';
            }
        }

        $where = [];
        $params = [];
        if ($query !== '') {
            $searchColumns = [];
            foreach (['new_computer_name', 'old_computer_name'] as $field) {
                foreach (dcExistingColumns($availableColumns, dcFieldCandidates()[$field] ?? []) as $column) {
                    if (!in_array($column, $searchColumns, true)) {
                        $searchColumns[] = $column;
                    }
                }
            }

            foreach ($availableColumns as $lower => $column) {
                if (!in_array($lower, ['deleted_at'], true) && !in_array($column, $searchColumns, true)) {
                    $searchColumns[] = $column;
                }
            }

            $searchParts = [];
            foreach ($searchColumns as $index => $column) {
                $param = ':q_' . $index;
                $searchParts[] = 'CAST(' . dcQuoteColumn($column) . ' AS CHAR) LIKE ' . $param;
                $params[$param] = '%' . $query . '%';
            }
            if (!empty($searchParts)) {
                $where[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        $orderParts = [];
        if (isset($availableColumns['created_at'])) {
            $orderParts[] = dcQuoteColumn($availableColumns['created_at']) . ' DESC';
        }
        $orderParts[] = dcQuoteColumn($primaryKey) . ' DESC';
        $orderSql = ' ORDER BY ' . implode(', ', $orderParts);

        $statusColumn = $availableColumns['de_poin'] ?? ($columnMap['delete_status'] ?? null);
        if ($statusColumn === null) {
            $statusColumn = 'de_poin';
        }
        $statusSql = dcQuoteColumn($statusColumn);

        $activeWhere = $where;
        $activeParams = $params;
        $activeWhere[] = $statusSql . " = '2'";
        $activeWhereSql = ' WHERE ' . implode(' AND ', $activeWhere);

        $deletedWhere = $where;
        $deletedParams = $params;
        $deletedWhere[] = $statusSql . " = '1'";
        $deletedWhereSql = ' WHERE ' . implode(' AND ', $deletedWhere);

        $activeCountStmt = $dataPdo->prepare('SELECT COUNT(*) FROM `delete_computer`' . $activeWhereSql);
        $activeCountStmt->execute($activeParams);
        $activeTotalRows = (int)$activeCountStmt->fetchColumn();
        $activeTotalPages = max(1, (int)ceil($activeTotalRows / $perPage));
        $activePage = min($activePage, $activeTotalPages);
        $activeOffset = ($activePage - 1) * $perPage;

        $deletedCountStmt = $dataPdo->prepare('SELECT COUNT(*) FROM `delete_computer`' . $deletedWhereSql);
        $deletedCountStmt->execute($deletedParams);
        $deletedTotalRows = (int)$deletedCountStmt->fetchColumn();
        $deletedTotalPages = max(1, (int)ceil($deletedTotalRows / $perPage));
        $deletedPage = min($deletedPage, $deletedTotalPages);
        $deletedOffset = ($deletedPage - 1) * $perPage;

        $totalRows = $activeTotalRows + $deletedTotalRows;

        $activeSql = 'SELECT * FROM `delete_computer`' . $activeWhereSql . $orderSql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$activeOffset;
        $activeStmt = $dataPdo->prepare($activeSql);
        $activeStmt->execute($activeParams);
        $activeComputerRows = $activeStmt->fetchAll(PDO::FETCH_ASSOC);

        $deletedSql = 'SELECT * FROM `delete_computer`' . $deletedWhereSql . $orderSql . ' LIMIT ' . (int)$perPage . ' OFFSET ' . (int)$deletedOffset;
        $deletedStmt = $dataPdo->prepare($deletedSql);
        $deletedStmt->execute($deletedParams);
        $deletedComputerRows = $deletedStmt->fetchAll(PDO::FETCH_ASSOC);

        $computerRows = array_merge($activeComputerRows, $deletedComputerRows);

        $recorderCodes = [];
        foreach ($computerRows as $computerRow) {
            $recorderCode = dcRecorderCodeFromRow($computerRow, $availableColumns);
            if ($recorderCode !== '' && !in_array($recorderCode, $recorderCodes, true)) {
                $recorderCodes[] = $recorderCode;
            }
            $normalizedRecorderCode = dcNormalizeEmployeeCode($recorderCode);
            if ($normalizedRecorderCode !== '' && !in_array($normalizedRecorderCode, $recorderCodes, true)) {
                $recorderCodes[] = $normalizedRecorderCode;
            }

            $deleterCode = dcDeleterCodeFromRow($computerRow, $availableColumns);
            if ($deleterCode !== '' && !in_array($deleterCode, $recorderCodes, true)) {
                $recorderCodes[] = $deleterCode;
            }
            $normalizedDeleterCode = dcNormalizeEmployeeCode($deleterCode);
            if ($normalizedDeleterCode !== '' && !in_array($normalizedDeleterCode, $recorderCodes, true)) {
                $recorderCodes[] = $normalizedDeleterCode;
            }
        }
        if (isset($pdo) && $pdo instanceof PDO) {
            $userDisplayMap = dcBuildUserDisplayMap($pdo, $recorderCodes);
        }
    } catch (Throwable $e) {
        $pageError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

$formValue = static function (string $key) use ($editRow, $columnMap): string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
        return dcE($_POST[$key] ?? '');
    }
    return dcE($editRow ? dcRawValue($editRow, $columnMap, $key) : '');
};

$buildPageUrl = static function (int $page, string $target = 'active') use ($query, $activePage, $deletedPage): string {
    $params = [
        'active_page' => $activePage,
        'deleted_page' => $deletedPage,
    ];
    if ($target === 'deleted') {
        $params['deleted_page'] = $page;
    } else {
        $params['active_page'] = $page;
    }
    if ($query !== '') {
        $params['q'] = $query;
    }
    return 'index.php?' . http_build_query($params);
};

require_once __DIR__ . '/../../includes/header.php';

require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_permission('delete_computer.manage');
} else {
    require_permission('delete_computer.view');
}

?>

<style>
.dc-page{--dc-blue:#0f4c81;--dc-border:#dbe5ee;--dc-active-table-head-font:.78rem;--dc-active-table-body-font:.84rem;padding-bottom:24px}
.dc-hero{background:linear-gradient(135deg,#0b3c68,#1769aa);border-radius:18px;padding:22px;color:#fff;box-shadow:0 12px 30px rgba(15,76,129,.18)}
.dc-hero h1{font-size:1.35rem;font-weight:700;margin:0 0 5px}.dc-hero p{margin:0;opacity:.86;font-size:.9rem}.dc-hero-actions{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}.dc-total{display:inline-flex;align-items:center;gap:.45rem;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.25);padding:.42rem .72rem;border-radius:999px;font-size:.8rem}.dc-add-btn{position:relative;overflow:hidden;color:#007c91!important;border:2px solid #00bcd4!important;background:#fff!important;border-radius:10px!important;font-weight:900!important;white-space:nowrap;padding:.55rem .9rem!important;font-size:.92rem!important;line-height:1.5!important;box-shadow:0 6px 18px rgba(0,188,212,.28)!important;animation:dcAddButtonPulse 1.8s ease-in-out infinite;transform-origin:center;will-change:transform,box-shadow}.dc-add-btn::before{content:"";position:absolute;top:-45%;left:-80%;width:42%;height:190%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.92),transparent);transform:skewX(-24deg);animation:dcAddButtonShine 2.7s ease-in-out infinite;pointer-events:none}.dc-add-btn:hover,.dc-add-btn:focus{color:#fff!important;background:#00bcd4!important;border-color:#00a5bb!important;box-shadow:0 12px 28px rgba(0,188,212,.38)!important;transform:translateY(-2px) scale(1.02);animation-play-state:paused}.dc-add-btn:active{transform:translateY(0) scale(.98)}
@keyframes dcAddButtonPulse{0%,100%{transform:scale(1);box-shadow:0 6px 18px rgba(0,188,212,.24)}50%{transform:scale(1.035);box-shadow:0 0 0 5px rgba(0,188,212,.15),0 11px 26px rgba(0,188,212,.38)}}
@keyframes dcAddButtonShine{0%,28%{left:-80%}62%,100%{left:145%}}
@media(prefers-reduced-motion:reduce){.dc-add-btn{animation:none!important;transition:none!important}.dc-add-btn::before{animation:none!important;display:none!important}.dc-add-btn:hover,.dc-add-btn:focus{transform:none}}
.dc-kpi-row{display:grid;grid-template-columns:repeat(3,minmax(0,1fr));gap:12px}.dc-kpi{background:#fff;border:1px solid #e2e8f0;border-radius:15px;padding:13px 15px;box-shadow:0 5px 18px rgba(20,46,70,.06)}.dc-kpi-label{font-size:.72rem;font-weight:800;color:#64748b}.dc-kpi-value{font-size:1.45rem;font-weight:900;color:#0f4c81;line-height:1.2;margin-top:3px}.dc-kpi-note{font-size:.66rem;color:#94a3b8;margin-top:2px}
.dc-filter{background:#fff;border-radius:16px;padding:14px 16px;box-shadow:0 5px 18px rgba(20,46,70,.07);border:1px solid #edf2f7}.dc-filter .form-label{font-size:.75rem;font-weight:800;color:#475569}.dc-filter .form-control{min-height:39px;font-size:.84rem}
.dc-table-card{background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 5px 18px rgba(20,46,70,.07);border:1px solid #edf2f7}.dc-card-head{display:flex;justify-content:space-between;align-items:center;gap:10px;padding:13px 16px;border-bottom:1px solid var(--dc-border);background:#fbfdff}.dc-card-title{font-weight:900;color:#0f172a}.dc-card-sub{font-size:.7rem;color:#64748b;margin-top:2px}.dc-table-card .table-responsive{overflow-x:hidden}.dc-table{width:100%;min-width:0;table-layout:fixed;margin:0}.dc-table th{padding:.58rem .3rem;font-size:clamp(.63rem,.69vw,.74rem);line-height:1.25;color:#52616f;background:#f7f9fb;border-bottom:1px solid var(--dc-border);white-space:normal;text-align:center;vertical-align:middle;overflow-wrap:anywhere}.dc-table td{padding:.56rem .3rem;vertical-align:middle;font-size:clamp(.67rem,.73vw,.79rem);line-height:1.3;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.dc-table tbody tr:hover{background:#f8fbfe}
.dc-active-table th{font-size:var(--dc-active-table-head-font)}
.dc-active-table td{font-size:var(--dc-active-table-body-font)}.dc-table th:nth-child(1),.dc-table td:nth-child(1){width:5%;text-align:center}.dc-table th:nth-child(2),.dc-table td:nth-child(2){width:24%}.dc-table th:nth-child(3),.dc-table td:nth-child(3){width:24%}.dc-table th:nth-child(4),.dc-table td:nth-child(4){width:20%}.dc-table th:nth-child(5),.dc-table td:nth-child(5){width:15%;text-align:center}.dc-table.with-manage th:nth-child(6),.dc-table.with-manage td:nth-child(6){width:12%;text-align:center}.dc-computer-name{font-family:Consolas,monospace;font-weight:800;color:#0f4c81}.dc-sequence{text-align:center;font-weight:700;color:#657686}.dc-actions{display:flex;justify-content:center;gap:.22rem;flex-wrap:wrap}.dc-action-btn{border-radius:8px;font-size:.66rem;font-weight:700;padding:.22rem .38rem;white-space:nowrap}.dc-deleted-badge{display:inline-flex;border-radius:999px;padding:.24rem .5rem;background:#fee2e2;color:#b91c1c;font-size:.65rem;font-weight:900}.dc-pagination{padding:12px 16px;border-top:1px solid var(--dc-border);display:flex;flex-wrap:wrap;justify-content:space-between;align-items:center;gap:8px}.dc-pagination .pagination{margin:0}.dc-pagination .page-link{font-size:.72rem}
.dc-form-modal .modal-dialog{max-width:700px}.dc-form-modal .modal-content{border:0;border-radius:16px;overflow:hidden}.dc-form-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff);border-bottom:1px solid #dbe5ee;padding:9px 13px}.dc-form-modal .modal-title{font-size:1rem}.dc-form-modal .modal-header .small{font-size:.7rem;margin-top:2px!important}.dc-form-modal .modal-body{background:#f8fafc;padding:8px}.dc-form-modal .modal-footer{padding:6px 9px}.dc-form-modal .modal-footer .btn{font-size:.76rem;padding:.3rem .75rem}.dc-form-table-wrap{border:1px solid #dbe5ee;border-radius:10px;overflow:hidden;background:#fff}.dc-form-table{width:100%;margin:0;table-layout:fixed}.dc-form-table th,.dc-form-table td{padding:.35rem .48rem;border-color:#dbe5ee;vertical-align:middle;font-size:.78rem;line-height:1.2}.dc-form-table th{width:32%;background:#f1f5f9;color:#334155;font-weight:800;white-space:nowrap}.dc-form-table td{background:#fff}.dc-form-table tr:nth-child(even) td{background:#f8fafc}.dc-form-table .form-control{min-height:32px;height:32px;border-radius:7px;font-size:.78rem;padding:.3rem .55rem}.dc-readonly{background:#f1f5f9!important}
@media(max-width:1366px){.dc-page{margin-left:-4px;margin-right:-4px}.dc-hero{padding:18px}.dc-kpi{padding:11px 13px}.dc-filter{padding:12px 14px}.dc-table th{padding:.5rem .18rem;font-size:.61rem}.dc-table td{padding:.5rem .18rem;font-size:.66rem}.dc-active-table th{font-size:.84rem}.dc-active-table td{font-size:.90rem}.dc-action-btn{font-size:.58rem;padding:.18rem .27rem}}
@media(max-width:900px){.dc-kpi-row{grid-template-columns:1fr}.dc-table-card .table-responsive{overflow-x:auto}.dc-table{min-width:850px}.dc-hero-actions{width:100%}.dc-add-btn{flex:1}}
</style>


<link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hdd-primary-action-button.css">

<div class="dc-page">
    <div class="dc-hero d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3 mb-3">
        <div>
            <h1>ลบชื่อเครื่อง Join Domain</h1>
            <!-- <p>จัดการชื่อเครื่องใหม่ ชื่อเครื่องเก่า และประวัติการลบออกจาก Domain</p> -->
        </div>
        <div class="dc-hero-actions">
            <div class="dc-total">ข้อมูลทั้งหมด <strong><?php echo number_format($totalRows); ?></strong> รายการ</div>
            <button type="button" class="btn btn-light dc-add-btn hdd-primary-action-btn" data-bs-toggle="modal" data-bs-target="#computerNameModal">+ เพิ่มชื่อเครื่อง</button>
        </div>
    </div>

    <?php if ($pageSuccess !== ''): ?><div class="alert alert-success py-2"><?php echo dcE($pageSuccess); ?></div><?php endif; ?>
    <?php if ($pageError !== ''): ?><div class="alert alert-danger py-2"><?php echo dcE($pageError); ?></div><?php endif; ?>

    <!-- <div class="dc-kpi-row mb-3">
        <div class="dc-kpi"><div class="dc-kpi-label">รายการทั้งหมด</div><div class="dc-kpi-value"><?php echo number_format($totalRows); ?></div><div class="dc-kpi-note">รวมรายการปัจจุบันและประวัติการลบ</div></div>
        <div class="dc-kpi"><div class="dc-kpi-label">รายการใช้งาน</div><div class="dc-kpi-value"><?php echo number_format($activeTotalRows); ?></div><div class="dc-kpi-note">ข้อมูลที่ยังไม่ถูกเปลี่ยนสถานะเป็นลบ</div></div>
        <div class="dc-kpi"><div class="dc-kpi-label">ประวัติการลบ</div><div class="dc-kpi-value"><?php echo number_format($deletedTotalRows); ?></div><div class="dc-kpi-note">รายการที่เปลี่ยนสถานะเป็นลบแล้ว</div></div>
    </div> -->

    <form method="get" class="dc-filter mb-3" autocomplete="off">
        <div class="row g-2 align-items-end">
            <div class="col-lg-8">
                <!-- <label class="form-label">ค้นหาข้อมูลชื่อเครื่อง</label> -->
                <input type="search" name="q" class="form-control" value="<?php echo dcE($query); ?>" placeholder="ค้นหาชื่อเครื่องคอมใหม่ ชื่อเครื่องเก่า หรือผู้บันทึก">
            </div>
            <div class="col-lg-2 d-grid"><button class="btn btn-primary" type="submit">ค้นหา</button></div>
            <div class="col-lg-2 d-grid"><a href="index.php" class="btn btn-outline-secondary">ล้างค่า</a></div>
        </div>
    </form>

    <div class="dc-table-card mb-3">
        <div class="dc-card-head">
            <div><div class="dc-card-title">รายการชื่อเครื่องคอม</div><div class="dc-card-sub">แสดง <?php echo number_format($activeTotalRows > 0 ? $activeOffset + 1 : 0); ?>-<?php echo number_format(min($activeOffset + $perPage, $activeTotalRows)); ?> จาก <?php echo number_format($activeTotalRows); ?> รายการ</div></div>
            <span class="badge text-bg-light border">หน้า <?php echo number_format($activePage); ?> / <?php echo number_format($activeTotalPages); ?></span>
        </div>
        <div class="table-responsive">
            <table class="table table-hover dc-table dc-active-table <?php echo $isSuperAdmin ? 'with-manage' : ''; ?>">
                <thead><tr><th>ลำดับ</th><th class="text-start">ชื่อเครื่องคอมใหม่</th><th class="text-start">ชื่อเครื่องเก่า</th><th class="text-start">ผู้บันทึก</th><th>วันที่บันทึก</th><?php if ($isSuperAdmin): ?><th>จัดการ</th><?php endif; ?></tr></thead>
                <tbody>
                <?php if (empty($activeComputerRows)): ?><tr><td colspan="<?php echo $isSuperAdmin ? 6 : 5; ?>" class="text-center text-muted py-5">ไม่พบข้อมูลชื่อเครื่องคอม</td></tr><?php endif; ?>
                <?php foreach ($activeComputerRows as $index => $row):
                    $rowId = $row[$primaryKey] ?? '';
                    $newComputerName = dcValue($row, $columnMap, 'new_computer_name');
                    $oldComputerName = dcValue($row, $columnMap, 'old_computer_name');
                    $recorderCodeRaw = dcRecorderCodeFromRow($row, $availableColumns);
                    $recorderCode = dcNormalizeEmployeeCode($recorderCodeRaw);
                    $createdBy = $recorderCode !== '' ? ($userDisplayMap[$recorderCodeRaw] ?? $userDisplayMap[$recorderCode] ?? $recorderCode) : '-';
                    $createdAt = dcRawValue($row, $columnMap, 'created_by');
                    $createdAt = $createdAt !== '' ? $createdAt : '-';
                ?>
                <tr>
                    <td class="dc-sequence"><?php echo number_format($activeOffset + $index + 1); ?></td>
                    <td><span class="dc-computer-name"><?php echo dcE($newComputerName); ?></span></td>
                    <td><span class="dc-computer-name text-secondary"><?php echo dcE($oldComputerName); ?></span></td>
                    <td><?php echo dcE($createdBy); ?></td>
                    <td class="text-center"><?php echo dcE($createdAt); ?></td>
                    <?php if ($isSuperAdmin): ?><td><div class="dc-actions">
                        <a class="btn btn-sm btn-outline-warning dc-action-btn" href="index.php?edit=<?php echo urlencode((string)$rowId); ?>"><svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" role="img" aria-label="แก้ไข"><path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10A.5.5 0 0 1 5.5 14H2a.5.5 0 0 1-.5-.5V10a.5.5 0 0 1 .146-.354zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zM12.793 5.5 10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293zM3.5 10.207 2.5 11.207V13h1.793l1-1H5.5v-.5H5a.5.5 0 0 1-.5-.5v-.5H4a.5.5 0 0 1-.5-.5z"/></svg></a>
                        <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันเปลี่ยนสถานะรายการนี้เป็นลบแล้ว?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="record_id" value="<?php echo dcE($rowId); ?>"><button type="submit" class="btn btn-sm btn-outline-danger dc-action-btn" title="ลบ" aria-label="ลบ"><svg class="action-icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1M4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg></button></form>
                    </div></td><?php endif; ?>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php if ($activeTotalPages > 1): ?><div class="dc-pagination"><span class="small text-muted">แสดงผลทีละ <?php echo number_format($perPage); ?> รายการ</span><nav><ul class="pagination pagination-sm">
            <li class="page-item <?php echo $activePage <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo dcE($buildPageUrl(max(1,$activePage-1),'active')); ?>">ก่อนหน้า</a></li>
            <?php for($p=max(1,$activePage-2);$p<=min($activeTotalPages,$activePage+2);$p++): ?><li class="page-item <?php echo $p===$activePage?'active':''; ?>"><a class="page-link" href="<?php echo dcE($buildPageUrl($p,'active')); ?>"><?php echo $p; ?></a></li><?php endfor; ?>
            <li class="page-item <?php echo $activePage >= $activeTotalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo dcE($buildPageUrl(min($activeTotalPages,$activePage+1),'active')); ?>">ถัดไป</a></li>
        </ul></nav></div><?php endif; ?>
    </div>

    <div class="dc-table-card">
        <div class="dc-card-head"><div><div class="dc-card-title">ประวัติการลบชื่อเครื่องคอม</div><div class="dc-card-sub">แสดง <?php echo number_format($deletedTotalRows > 0 ? $deletedOffset + 1 : 0); ?>-<?php echo number_format(min($deletedOffset + $perPage, $deletedTotalRows)); ?> จาก <?php echo number_format($deletedTotalRows); ?> รายการ</div></div><span class="dc-deleted-badge">ลบแล้ว</span></div>
        <div class="table-responsive"><table class="table table-hover dc-table"><thead><tr><th>ลำดับ</th><th class="text-start">ชื่อเครื่องคอมใหม่</th><th class="text-start">ชื่อเครื่องเก่า</th><th class="text-start">ผู้บันทึก</th><th class="text-start">ผู้ลบเครื่อง</th><th>วันที่บันทึก</th></tr></thead><tbody>
        <?php if (empty($deletedComputerRows)): ?><tr><td colspan="6" class="text-center text-muted py-5">ยังไม่มีประวัติการลบชื่อเครื่องคอม</td></tr><?php endif; ?>
        <?php foreach ($deletedComputerRows as $index => $row):
            $newComputerName = dcValue($row, $columnMap, 'new_computer_name'); $oldComputerName = dcValue($row, $columnMap, 'old_computer_name');
            $recorderCodeRaw = dcRecorderCodeFromRow($row, $availableColumns); $recorderCode = dcNormalizeEmployeeCode($recorderCodeRaw); $createdBy = $recorderCode !== '' ? ($userDisplayMap[$recorderCodeRaw] ?? $userDisplayMap[$recorderCode] ?? $recorderCode) : '-';
            $deleterCodeRaw = dcDeleterCodeFromRow($row, $availableColumns); $deleterCode = dcNormalizeEmployeeCode($deleterCodeRaw); $deletedBy = $deleterCode !== '' ? ($userDisplayMap[$deleterCodeRaw] ?? $userDisplayMap[$deleterCode] ?? $deleterCode) : '-';
            $createdAt = dcRawValue($row, $columnMap, 'created_by'); $createdAt = $createdAt !== '' ? $createdAt : '-';
        ?><tr><td class="dc-sequence"><?php echo number_format($deletedOffset + $index + 1); ?></td><td><span class="dc-computer-name"><?php echo dcE($newComputerName); ?></span></td><td><span class="dc-computer-name text-secondary"><?php echo dcE($oldComputerName); ?></span></td><td><?php echo dcE($createdBy); ?></td><td><?php echo dcE($deletedBy); ?></td><td class="text-center"><?php echo dcE($createdAt); ?></td></tr><?php endforeach; ?>
        </tbody></table></div>
        <?php if ($deletedTotalPages > 1): ?><div class="dc-pagination"><span class="small text-muted">แสดงผลทีละ <?php echo number_format($perPage); ?> รายการ</span><nav><ul class="pagination pagination-sm">
            <li class="page-item <?php echo $deletedPage <= 1 ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo dcE($buildPageUrl(max(1,$deletedPage-1),'deleted')); ?>">ก่อนหน้า</a></li>
            <?php for($p=max(1,$deletedPage-2);$p<=min($deletedTotalPages,$deletedPage+2);$p++): ?><li class="page-item <?php echo $p===$deletedPage?'active':''; ?>"><a class="page-link" href="<?php echo dcE($buildPageUrl($p,'deleted')); ?>"><?php echo $p; ?></a></li><?php endfor; ?>
            <li class="page-item <?php echo $deletedPage >= $deletedTotalPages ? 'disabled' : ''; ?>"><a class="page-link" href="<?php echo dcE($buildPageUrl(min($deletedTotalPages,$deletedPage+1),'deleted')); ?>">ถัดไป</a></li>
        </ul></nav></div><?php endif; ?>
    </div>
</div>

<div class="modal fade dc-form-modal" id="computerNameModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form method="post" class="modal-content" autocomplete="off">
            <div class="modal-header"><div><h5 class="modal-title fw-bold"><?php echo $editRow ? 'แก้ไขข้อมูลชื่อเครื่องคอม' : 'เพิ่มข้อมูลชื่อเครื่องคอม'; ?></h5><div class="small text-muted mt-1">กรอกชื่อเครื่องใหม่และชื่อเครื่องเดิมสำหรับดำเนินการ Join Domain</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save"><input type="hidden" name="record_id" value="<?php echo dcE($editRow[$primaryKey] ?? ''); ?>">
                <div class="table-responsive dc-form-table-wrap">
                    <table class="table table-bordered dc-form-table">
                        <tbody>
                            <tr>
                                <th>ชื่อเครื่องคอมใหม่ <span class="text-danger">*</span></th>
                                <td><input type="text" name="new_computer_name" class="form-control dc-computer-name-input" value="<?php echo $formValue('new_computer_name'); ?>" placeholder="เช่น B00PC000K00D0" pattern="[A-Za-z0-9]+" title="กรอกได้เฉพาะภาษาอังกฤษและตัวเลขเท่านั้น" required></td>
                            </tr>
                            <tr>
                                <th>ชื่อเครื่องเก่า <span class="text-danger">*</span></th>
                                <td><input type="text" name="old_computer_name" class="form-control dc-computer-name-input" value="<?php echo $formValue('old_computer_name'); ?>" placeholder="เช่น B00PC000K00" pattern="[A-Za-z0-9]+" title="กรอกได้เฉพาะภาษาอังกฤษและตัวเลขเท่านั้น" required></td>
                            </tr>
                            <tr>
                                <th>ผู้บันทึก</th>
                                <td><input type="text" class="form-control dc-readonly" value="<?php echo dcE(dcCurrentUser()); ?>" readonly></td>
                            </tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer"><a href="index.php" class="btn btn-light btn-sm">ยกเลิก</a><button type="submit" class="btn btn-primary btn-sm px-3"><?php echo $editRow ? 'บันทึกการแก้ไข' : 'บันทึกข้อมูล'; ?></button></div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    <?php if ($editRow): ?>
    var editModalElement = document.getElementById('computerNameModal');
    if (editModalElement && window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(editModalElement).show();
    }
    <?php endif; ?>
    document.querySelectorAll('.dc-computer-name-input').forEach(function (input) {
        input.addEventListener('input', function () {
            var cleaned = input.value.replace(/[^A-Za-z0-9]/g, '');
            if (input.value !== cleaned) {
                input.value = cleaned;
            }
        });
    });
});
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
