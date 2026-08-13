<?php

require_once __DIR__ . '/../../includes/auth.php';
$pageTitle = 'บันทึกข้อมูลเครื่อง Server';

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../config/data_database.php';

if (!function_exists('serverE')) {
    function serverE($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('serverClean')) {
    function serverClean($value): string
    {
        return trim((string)($value ?? ''));
    }
}

if (!function_exists('serverCurrentUser')) {
    function serverCurrentUser(): string
    {
        $fullName = trim((string)($_SESSION['full_name'] ?? ''));
        $employeeCode = trim((string)($_SESSION['employee_code'] ?? ''));

        if ($fullName !== '' && $employeeCode !== '') {
            return $fullName . ' (' . $employeeCode . ')';
        }

        if ($fullName !== '') {
            return $fullName;
        }

        if ($employeeCode !== '') {
            return $employeeCode;
        }

        return 'system';
    }
}

if (!function_exists('serverQuoteColumn')) {
    function serverQuoteColumn(string $column): string
    {
        return '`' . str_replace('`', '``', $column) . '`';
    }
}

if (!function_exists('serverTableExists')) {
    function serverTableExists(PDO $pdo, string $dbName, string $tableName): bool
    {
        $stmt = $pdo->prepare('SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name');
        $stmt->execute([
            ':db_name' => $dbName,
            ':table_name' => $tableName,
        ]);
        return (int)$stmt->fetchColumn() > 0;
    }
}

if (!function_exists('serverColumnExists')) {
    function serverColumnExists(PDO $pdo, string $dbName, string $tableName, string $columnName): bool
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

if (!function_exists('serverPrimaryKeyColumn')) {
    function serverPrimaryKeyColumn(PDO $pdo, string $dbName, string $tableName): ?string
    {
        $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION ASC LIMIT 1");
        $stmt->execute([
            ':db_name' => $dbName,
            ':table_name' => $tableName,
        ]);
        $column = $stmt->fetchColumn();
        return $column !== false ? (string)$column : null;
    }
}

if (!function_exists('serverGetColumns')) {
    function serverGetColumns(PDO $pdo, string $dbName, string $tableName): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = :db_name AND TABLE_NAME = :table_name ORDER BY ORDINAL_POSITION ASC');
        $stmt->execute([
            ':db_name' => $dbName,
            ':table_name' => $tableName,
        ]);
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

if (!function_exists('serverResolveColumn')) {
    function serverResolveColumn(array $availableColumns, array $candidates): ?string
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

if (!function_exists('serverExistingColumns')) {
    function serverExistingColumns(array $availableColumns, array $candidates): array
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

if (!function_exists('serverFieldCandidates')) {
    function serverFieldCandidates(): array
    {
        return [
            'server_name' => ['s_nameserver', 'server_name', 'name', 'hostname', 'machine_name'],
            'dns_name' => ['s_dns_name', 'dns_name', 'dns', 'dns_server'],
            'serial_mt' => ['s_sn', 's-sn', 's_n', 'serial_mt', 'serial_no', 'serial', 'sn', 'mt'],
            'processor' => ['s_processor', 'processor', 'cpu'],
            'memory_size' => ['s_memory_size', 's_memory', 'memory_size', 'memory', 'ram_size', 'ram'],
            'memory_type' => ['s_memory_type', 'memory_type', 'ram_type'],
            'disk_size' => ['s_disk_size', 's_disk', 'disk_size', 'disk', 'storage_size', 'storage'],
            'disk_type' => ['s_disk_type', 'disk_type', 'storage_type'],
            'caretaker' => ['s_caretaker', 'caretaker', 'owner', 'admin', 'administrator'],
            'location' => ['s_location', 'location', 'site', 'address'],
            'expire_date' => ['s_day', 'expire_date', 'expired_date', 'warranty_expire', 'end_date'],
            'detail' => ['s_detail', 'server_detail', 'detail', 'description', 'remark', 'note'],
        ];
    }
}

if (!function_exists('serverColumnMap')) {
    function serverColumnMap(array $availableColumns): array
    {
        $map = [];
        foreach (serverFieldCandidates() as $field => $candidates) {
            $map[$field] = serverResolveColumn($availableColumns, $candidates);
        }
        return $map;
    }
}

if (!function_exists('serverRawValue')) {
    function serverRawValue(array $row, array $columnMap, string $field): string
    {
        $column = $columnMap[$field] ?? null;
        if ($column !== null && array_key_exists($column, $row)) {
            $value = trim((string)($row[$column] ?? ''));
            if ($value !== '') {
                return $value;
            }
        }

        // Fallback: check every known candidate column for this field.
        // This fixes old server tables that contain both `s-sn` and `s_sn`,
        // while actual S/N data is stored in `s_sn`.
        $candidates = serverFieldCandidates()[$field] ?? [];
        foreach ($candidates as $candidate) {
            foreach ($row as $key => $value) {
                if (strtolower((string)$key) === strtolower((string)$candidate)) {
                    $text = trim((string)($value ?? ''));
                    if ($text !== '') {
                        return $text;
                    }
                }
            }
        }

        return '';
    }
}

if (!function_exists('serverValue')) {
    function serverValue(array $row, array $columnMap, string $field): string
    {
        $value = serverRawValue($row, $columnMap, $field);
        return $value !== '' ? $value : '-';
    }
}

if (!function_exists('serverEnsureTable')) {
    function serverEnsureTable(PDO $pdo, string $dbName): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `server` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `s_nameserver` VARCHAR(150) NOT NULL DEFAULT '',
            `s_dns_name` VARCHAR(150) NULL,
            `s_sn` VARCHAR(150) NULL,
            `s_processor` VARCHAR(150) NULL,
            `s_memory_size` VARCHAR(80) NULL,
            `s_memory_type` VARCHAR(80) NULL,
            `s_disk_size` VARCHAR(80) NULL,
            `s_disk_type` VARCHAR(80) NULL,
            `s_caretaker` VARCHAR(150) NULL,
            `s_location` VARCHAR(150) NULL,
            `s_day` DATE NULL,
            `created_by` VARCHAR(150) NULL,
            `updated_by` VARCHAR(150) NULL,
            `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` DATETIME NULL DEFAULT NULL,
            `deleted_at` DATETIME NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `idx_s_nameserver` (`s_nameserver`),
            KEY `idx_s_dns_name` (`s_dns_name`),
            KEY `idx_s_day` (`s_day`),
            KEY `idx_deleted_at` (`deleted_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        $columns = [
            's_nameserver' => "VARCHAR(150) NOT NULL DEFAULT ''",
            's_dns_name' => 'VARCHAR(150) NULL',
            's_sn' => 'VARCHAR(150) NULL',
            's_processor' => 'VARCHAR(150) NULL',
            's_memory_size' => 'VARCHAR(80) NULL',
            's_memory_type' => 'VARCHAR(80) NULL',
            's_disk_size' => 'VARCHAR(80) NULL',
            's_disk_type' => 'VARCHAR(80) NULL',
            's_caretaker' => 'VARCHAR(150) NULL',
            's_location' => 'VARCHAR(150) NULL',
            's_day' => 'DATE NULL',
            'created_by' => 'VARCHAR(150) NULL',
            'updated_by' => 'VARCHAR(150) NULL',
            'created_at' => 'DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP',
            'updated_at' => 'DATETIME NULL DEFAULT NULL',
            'deleted_at' => 'DATETIME NULL DEFAULT NULL',
        ];

        foreach ($columns as $column => $definition) {
            if (!serverColumnExists($pdo, $dbName, 'server', $column)) {
                $pdo->exec('ALTER TABLE `server` ADD COLUMN ' . serverQuoteColumn($column) . ' ' . $definition);
            }
        }

        if (!serverPrimaryKeyColumn($pdo, $dbName, 'server') && !serverColumnExists($pdo, $dbName, 'server', 'id')) {
            $pdo->exec('ALTER TABLE `server` ADD COLUMN `id` INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY FIRST');
        }
    }
}

if (!function_exists('serverNormalizeDate')) {
    function serverNormalizeDate(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return null;
        }

        $dt = DateTime::createFromFormat('Y-m-d', $value);
        $errors = DateTime::getLastErrors();
        if ($dt instanceof DateTime && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))) {
            return $dt->format('Y-m-d');
        }

        return null;
    }
}

if (!function_exists('serverDateForInput')) {
    function serverDateForInput($value): string
    {
        $value = trim((string)($value ?? ''));
        if ($value === '' || $value === '0000-00-00') {
            return '';
        }

        $value = str_replace('/', '-', $value);
        foreach (['Y-m-d', 'd-m-Y'] as $format) {
            $dt = DateTime::createFromFormat($format, substr($value, 0, 10));
            $errors = DateTime::getLastErrors();
            if ($dt instanceof DateTime && ($errors === false || ((int)$errors['warning_count'] === 0 && (int)$errors['error_count'] === 0))) {
                return $dt->format('Y-m-d');
            }
        }

        return '';
    }
}

if (!function_exists('serverFormatDate')) {
    function serverFormatDate($value): string
    {
        $inputValue = serverDateForInput($value);
        if ($inputValue === '') {
            return '-';
        }

        $dt = DateTime::createFromFormat('Y-m-d', $inputValue);
        return $dt instanceof DateTime ? $dt->format('d/m/Y') : $inputValue;
    }
}


if (!function_exists('serverBuildPageUrl')) {
    function serverBuildPageUrl(int $page, string $query = ''): string
    {
        $params = ['page' => max(1, $page)];
        if (trim($query) !== '') {
            $params['q'] = trim($query);
        }
        return 'index.php?' . http_build_query($params);
    }
}

if (!function_exists('serverExpireBadgeClass')) {
    function serverExpireBadgeClass($value): string
    {
        $inputValue = serverDateForInput($value);
        if ($inputValue === '') {
            return 'secondary';
        }

        $today = new DateTime('today', new DateTimeZone('Asia/Bangkok'));
        $expire = DateTime::createFromFormat('Y-m-d', $inputValue);
        if (!$expire) {
            return 'secondary';
        }

        $expire->setTime(0, 0, 0);
        if ($expire < $today) {
            return 'danger';
        }

        $days = (int)$today->diff($expire)->format('%a');
        if ($days <= 30) {
            return 'warning';
        }

        return 'success';
    }
}

if (!function_exists('serverFetchRow')) {
    function serverFetchRow(PDO $pdo, string $primaryKey, $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM `server` WHERE ' . serverQuoteColumn($primaryKey) . ' = :id LIMIT 1');
        $stmt->execute([':id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    }
}

if (!function_exists('serverBuildWriteColumns')) {
    function serverBuildWriteColumns(array $availableColumns): array
    {
        $result = [];
        foreach (serverFieldCandidates() as $field => $candidates) {
            if ($field === 'detail') {
                continue;
            }
            $columns = serverExistingColumns($availableColumns, $candidates);
            if (!empty($columns)) {
                $result[$field] = $columns;
            }
        }
        return $result;
    }
}

$dataDbName = $DATA_DB_NAME ?? 'Data';
$pageError = '';
$pageSuccess = '';
$serverRows = [];
$editRow = null;
$primaryKey = 'id';
$query = serverClean($_GET['q'] ?? '');
$editId = serverClean($_GET['edit'] ?? '');
$serverPage = max(1, (int)($_GET['page'] ?? 1));
$serverPerPage = 20;
$serverTotalRows = 0;
$serverTotalPages = 1;
$serverOffset = 0;
$serverAvailableColumns = [];
$serverColumnMap = [];
$serverWriteColumns = [];

if ($dataDbError !== '') {
    $pageError = $dataDbError;
} elseif (!$dataPdo instanceof PDO) {
    $pageError = 'ไม่พบการเชื่อมต่อฐานข้อมูล Data';
} else {
    try {
        serverEnsureTable($dataPdo, $dataDbName);
        $serverAvailableColumns = serverGetColumns($dataPdo, $dataDbName, 'server');
        $serverColumnMap = serverColumnMap($serverAvailableColumns);
        $serverWriteColumns = serverBuildWriteColumns($serverAvailableColumns);
        $primaryKey = serverPrimaryKeyColumn($dataPdo, $dataDbName, 'server') ?: 'id';

        if ($_SERVER['REQUEST_METHOD'] === 'POST') {
            $action = serverClean($_POST['action'] ?? '');
            $recordId = serverClean($_POST['record_id'] ?? '');

            if ($action === 'delete' && $recordId !== '') {
                if (isset($serverAvailableColumns['deleted_at'])) {
                    $setParts = ['deleted_at = NOW()'];
                    $params = [':id' => $recordId];
                    if (isset($serverAvailableColumns['updated_at'])) {
                        $setParts[] = 'updated_at = NOW()';
                    }
                    if (isset($serverAvailableColumns['updated_by'])) {
                        $setParts[] = 'updated_by = :updated_by';
                        $params[':updated_by'] = serverCurrentUser();
                    }
                    $stmt = $dataPdo->prepare('UPDATE `server` SET ' . implode(', ', $setParts) . ' WHERE ' . serverQuoteColumn($primaryKey) . ' = :id');
                    $stmt->execute($params);
                } else {
                    $stmt = $dataPdo->prepare('DELETE FROM `server` WHERE ' . serverQuoteColumn($primaryKey) . ' = :id');
                    $stmt->execute([':id' => $recordId]);
                }
                $pageSuccess = 'ลบข้อมูลเครื่อง Server เรียบร้อยแล้ว';
            } elseif ($action === 'save') {
                $serverName = serverClean($_POST['server_name'] ?? '');
                $expireDateRaw = serverClean($_POST['expire_date'] ?? '');
                $expireDate = serverNormalizeDate($expireDateRaw);

                $inputValues = [
                    'server_name' => $serverName,
                    'dns_name' => serverClean($_POST['dns_name'] ?? ''),
                    'serial_mt' => serverClean($_POST['serial_mt'] ?? ''),
                    'processor' => serverClean($_POST['processor'] ?? ''),
                    'memory_size' => serverClean($_POST['memory_size'] ?? ''),
                    'memory_type' => serverClean($_POST['memory_type'] ?? ''),
                    'disk_size' => serverClean($_POST['disk_size'] ?? ''),
                    'disk_type' => serverClean($_POST['disk_type'] ?? ''),
                    'caretaker' => serverClean($_POST['caretaker'] ?? ''),
                    'location' => serverClean($_POST['location'] ?? ''),
                    'expire_date' => $expireDate,
                ];

                if ($serverName === '') {
                    $pageError = 'กรุณากรอกชื่อเครื่อง Server';
                } elseif ($expireDateRaw !== '' && $expireDate === null) {
                    $pageError = 'รูปแบบวันที่หมดอายุไม่ถูกต้อง';
                } else {
                    if ($recordId !== '') {
                        $setParts = [];
                        $params = [':id' => $recordId];
                        $paramIndex = 0;

                        foreach ($inputValues as $field => $value) {
                            foreach ($serverWriteColumns[$field] ?? [] as $column) {
                                $param = ':p_' . $paramIndex++;
                                $setParts[] = serverQuoteColumn($column) . ' = ' . $param;
                                $params[$param] = $value;
                            }
                        }

                        if (isset($serverAvailableColumns['updated_by'])) {
                            $setParts[] = serverQuoteColumn($serverAvailableColumns['updated_by']) . ' = :updated_by';
                            $params[':updated_by'] = serverCurrentUser();
                        }
                        if (isset($serverAvailableColumns['updated_at'])) {
                            $setParts[] = serverQuoteColumn($serverAvailableColumns['updated_at']) . ' = NOW()';
                        }

                        if (!empty($setParts)) {
                            $stmt = $dataPdo->prepare('UPDATE `server` SET ' . implode(', ', $setParts) . ' WHERE ' . serverQuoteColumn($primaryKey) . ' = :id');
                            $stmt->execute($params);
                            $pageSuccess = 'แก้ไขข้อมูลเครื่อง Server เรียบร้อยแล้ว';
                        }
                    } else {
                        $columns = [];
                        $placeholders = [];
                        $params = [];
                        $paramIndex = 0;

                        foreach ($inputValues as $field => $value) {
                            foreach ($serverWriteColumns[$field] ?? [] as $column) {
                                $param = ':p_' . $paramIndex++;
                                $columns[] = serverQuoteColumn($column);
                                $placeholders[] = $param;
                                $params[$param] = $value;
                            }
                        }

                        if (isset($serverAvailableColumns['created_by'])) {
                            $columns[] = serverQuoteColumn($serverAvailableColumns['created_by']);
                            $placeholders[] = ':created_by';
                            $params[':created_by'] = serverCurrentUser();
                        }
                        if (isset($serverAvailableColumns['created_at'])) {
                            $columns[] = serverQuoteColumn($serverAvailableColumns['created_at']);
                            $placeholders[] = 'NOW()';
                        }

                        $stmt = $dataPdo->prepare('INSERT INTO `server` (' . implode(', ', $columns) . ') VALUES (' . implode(', ', $placeholders) . ')');
                        $stmt->execute($params);
                        $pageSuccess = 'บันทึกข้อมูลเครื่อง Server เรียบร้อยแล้ว';
                    }
                }
            }
        }

        if ($editId !== '') {
            $editRow = serverFetchRow($dataPdo, $primaryKey, $editId);
        }

        $where = [];
        $params = [];
        if (isset($serverAvailableColumns['deleted_at'])) {
            $where[] = serverQuoteColumn($serverAvailableColumns['deleted_at']) . ' IS NULL';
        }

        if ($query !== '') {
            $searchColumns = array_filter([
                $serverColumnMap['server_name'] ?? null,
                $serverColumnMap['dns_name'] ?? null,
                $serverColumnMap['serial_mt'] ?? null,
                $serverColumnMap['caretaker'] ?? null,
                $serverColumnMap['location'] ?? null,
            ]);
            $searchParts = [];
            foreach (array_values(array_unique($searchColumns)) as $index => $column) {
                $param = ':q_' . $index;
                $searchParts[] = 'CAST(' . serverQuoteColumn($column) . ' AS CHAR) LIKE ' . $param;
                $params[$param] = '%' . $query . '%';
            }
            if (!empty($searchParts)) {
                $where[] = '(' . implode(' OR ', $searchParts) . ')';
            }
        }

        $orderParts = [];
        if (!empty($serverColumnMap['expire_date'])) {
            $expireCol = serverQuoteColumn($serverColumnMap['expire_date']);
            $orderParts[] = 'CASE WHEN ' . $expireCol . ' IS NULL OR ' . $expireCol . " = '0000-00-00' THEN 1 ELSE 0 END ASC";
            $orderParts[] = $expireCol . ' ASC';
        }
        if (!empty($serverColumnMap['server_name'])) {
            $orderParts[] = serverQuoteColumn($serverColumnMap['server_name']) . ' ASC';
        }
        $orderSql = !empty($orderParts) ? ' ORDER BY ' . implode(', ', $orderParts) : '';
        $whereSql = !empty($where) ? ' WHERE ' . implode(' AND ', $where) : '';

        $countSql = 'SELECT COUNT(*) FROM `server`' . $whereSql;
        $countStmt = $dataPdo->prepare($countSql);
        $countStmt->execute($params);
        $serverTotalRows = (int)$countStmt->fetchColumn();
        $serverTotalPages = max(1, (int)ceil($serverTotalRows / $serverPerPage));
        if ($serverPage > $serverTotalPages) {
            $serverPage = $serverTotalPages;
        }
        $serverOffset = ($serverPage - 1) * $serverPerPage;

        $sql = 'SELECT * FROM `server`' . $whereSql . $orderSql . ' LIMIT :limit OFFSET :offset';
        $stmt = $dataPdo->prepare($sql);
        foreach ($params as $paramName => $paramValue) {
            $stmt->bindValue($paramName, $paramValue);
        }
        $stmt->bindValue(':limit', $serverPerPage, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $serverOffset, PDO::PARAM_INT);
        $stmt->execute();
        $serverRows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        $pageError = 'เกิดข้อผิดพลาด: ' . $e->getMessage();
    }
}

$formValue = static function (string $key) use ($editRow, $serverColumnMap): string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
        return serverE($_POST[$key] ?? '');
    }
    return serverE($editRow ? serverRawValue($editRow, $serverColumnMap, $key) : '');
};

$formDateValue = static function (string $key) use ($editRow, $serverColumnMap): string {
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save') {
        return serverE($_POST[$key] ?? '');
    }
    return $editRow ? serverE(serverDateForInput(serverRawValue($editRow, $serverColumnMap, $key))) : '';
};

require_once __DIR__ . '/../../includes/header.php';

require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_permission('server.manage');
} else {
    require_permission('server.view');
}

?>

<style>
.server-page{--sv-blue:#0f4c81;--sv-border:#dbe5ee}
.server-hero{background:linear-gradient(135deg,#0b3c68,#1769aa);border-radius:18px;padding:22px;color:#fff;box-shadow:0 12px 30px rgba(15,76,129,.18);margin-bottom:24px}
.server-hero h1{font-size:1.35rem;font-weight:700;margin:0 0 5px}.server-hero p{margin:0;opacity:.86;font-size:.9rem}
.server-hero-actions{display:flex;align-items:center;gap:.65rem;flex-wrap:wrap}.server-total{display:inline-flex;align-items:center;gap:.45rem;background:rgba(255,255,255,.16);border:1px solid rgba(255,255,255,.25);padding:.42rem .72rem;border-radius:999px;font-size:.8rem}.server-add-btn{color:#dc2626!important;border:2px solid #dc2626!important;background:#fff!important;border-radius:10px!important;font-weight:900!important;white-space:nowrap;padding:.55rem .9rem!important;font-size:.92rem!important;line-height:1.5!important;box-shadow:0 4px 12px rgba(220,38,38,.22)!important;position:relative;overflow:hidden;isolation:isolate;animation:serverAddPulse 1.8s ease-in-out infinite;transform-origin:center;will-change:transform,box-shadow}.server-add-btn::before{content:'';position:absolute;top:-45%;left:-70%;width:42%;height:190%;background:linear-gradient(90deg,transparent,rgba(255,255,255,.82),transparent);transform:rotate(18deg);animation:serverAddShine 2.5s ease-in-out infinite;pointer-events:none;z-index:-1}.server-add-btn:hover,.server-add-btn:focus{color:#fff!important;background:#dc2626!important;border-color:#b91c1c!important;box-shadow:0 10px 24px rgba(0,188,212,.42)!important;transform:translateY(-2px) scale(1.02);animation-play-state:paused}.server-add-btn:hover::before,.server-add-btn:focus::before{z-index:0}.server-add-btn:active{transform:translateY(0) scale(.98);box-shadow:0 5px 14px rgba(0,188,212,.30)!important}@keyframes serverAddPulse{0%,100%{transform:scale(1);box-shadow:0 4px 12px rgba(220,38,38,.22),0 0 0 0 rgba(0,188,212,.22)}50%{transform:scale(1.025);box-shadow:0 7px 19px rgba(0,188,212,.34),0 0 0 5px rgba(0,188,212,.08)}}@keyframes serverAddShine{0%,35%{left:-70%;opacity:0}48%{opacity:1}68%,100%{left:135%;opacity:0}}@media(prefers-reduced-motion:reduce){.server-add-btn,.server-add-btn::before{animation:none!important;transition:none!important}.server-add-btn:hover,.server-add-btn:focus{transform:none}}
.server-card{background:#fff;border:0;border-radius:16px;box-shadow:0 5px 18px rgba(20,46,70,.07);overflow:hidden}.server-section-title{font-weight:700;color:#17324d}.server-search-form{display:flex;gap:.45rem;flex:1 1 420px;justify-content:flex-end}.server-search-form .form-control{max-width:380px}
.server-table-wrap{overflow-x:hidden}.server-table{width:100%;min-width:0;table-layout:fixed}.server-table th{padding:.62rem .3rem;font-size:clamp(.63rem,.69vw,.74rem);line-height:1.25;color:#52616f;background:#f7f9fb;border-bottom:1px solid var(--sv-border);white-space:normal;text-align:center;vertical-align:middle;overflow-wrap:anywhere}.server-table td{padding:.58rem .3rem;vertical-align:middle;font-size:clamp(.67rem,.73vw,.79rem);line-height:1.3;white-space:normal;overflow-wrap:anywhere;word-break:break-word}.server-table tbody tr:hover{background:#f8fbfe}
.server-table th:nth-child(1),.server-table td:nth-child(1){width:4.5%;text-align:center}.server-table th:nth-child(2),.server-table td:nth-child(2){width:15%}.server-table th:nth-child(3),.server-table td:nth-child(3){width:15%}.server-table th:nth-child(4),.server-table td:nth-child(4){width:12%}.server-table th:nth-child(5),.server-table td:nth-child(5){width:18%}.server-table th:nth-child(6),.server-table td:nth-child(6){width:11%}.server-table th:nth-child(7),.server-table td:nth-child(7){width:11%}.server-table th:nth-child(8),.server-table td:nth-child(8){width:13.5%}
.server-expire-badge{display:inline-flex;align-items:center;justify-content:center;border-radius:999px;padding:.25rem .45rem;font-weight:800;font-size:.66rem;min-width:68px;line-height:1.15}.server-detail-btn,.server-action-btn{border-radius:8px;font-size:.66rem;font-weight:700;padding:.22rem .38rem;white-space:nowrap}.server-actions{display:flex;justify-content:center;gap:.22rem;flex-wrap:wrap}.server-pagination-info{color:#64748b;font-size:.78rem;font-weight:700}.server-pagination .page-link{border-radius:9px;margin:0 2px;font-size:.76rem;font-weight:800;min-width:32px;text-align:center}
.server-modal .modal-dialog{max-width:760px}.server-modal .modal-content{border:0;border-radius:18px;box-shadow:0 22px 60px rgba(15,23,42,.22);overflow:hidden}.server-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff);border-bottom:1px solid #e2e8f0;padding:10px 14px}.server-modal .modal-title{font-weight:800;color:#17324d;font-size:1rem}.server-modal .modal-body{background:#f8fafc;padding:8px}.server-modal .modal-footer{padding:6px 10px}.server-form-table-wrap{border:1px solid #dbe5ee;border-radius:12px;overflow:hidden;background:#fff}.server-form-table{width:100%;margin:0;table-layout:fixed}.server-form-table th,.server-form-table td{padding:.36rem .5rem;border-color:#dbe5ee;vertical-align:middle;font-size:.8rem;line-height:1.25}.server-form-table th{width:31%;background:#f1f5f9;color:#475569;font-weight:900;white-space:normal}.server-form-table td{background:#fff}.server-form-table tr:nth-child(even) td{background:#f8fafc}.server-form-table .form-control{min-height:32px;height:32px;font-size:.79rem;padding:.25rem .5rem}.server-form-label{font-size:.78rem;font-weight:800;color:#475569;margin:0}.server-required:after{content:' *';color:#dc3545}
.server-detail-table-wrap{border:1px solid #dbe5ee;border-radius:12px;overflow:hidden;background:#fff}.server-detail-table{width:100%;margin:0;table-layout:fixed}.server-detail-table th,.server-detail-table td{padding:.58rem .68rem;border-color:#dbe5ee;vertical-align:middle;font-size:.84rem;line-height:1.35;word-break:break-word;overflow-wrap:anywhere}.server-detail-table th{width:30%;background:#f1f5f9;color:#475569;font-weight:900;white-space:nowrap}.server-detail-table td{background:#fff;color:#0f172a;font-weight:700}.server-detail-table tr:nth-child(even) td{background:#f8fafc}.server-detail-subvalue{display:block;color:#64748b;font-size:.76rem;font-weight:600;margin-top:2px}
@media(max-width:1366px){.server-page{margin-left:-4px;margin-right:-4px}.server-hero{padding:18px}.server-table th{font-size:.65rem;padding:.48rem .15rem}.server-table td{font-size:.72rem;padding:.48rem .15rem}.server-detail-btn,.server-action-btn{font-size:.56rem;padding:.17rem .24rem}.server-expire-badge{font-size:.58rem;min-width:58px;padding:.18rem .28rem}.server-modal .modal-dialog{max-width:720px}.server-modal .modal-body{padding:7px}.server-form-table th,.server-form-table td{padding:.31rem .42rem;font-size:.75rem}.server-form-table .form-control{min-height:30px;height:30px;font-size:.74rem}}
@media(max-width:992px){.server-search-form{flex:1 1 100%;justify-content:flex-start}.server-search-form .form-control{max-width:none}.server-table-wrap{overflow-x:auto}.server-table{min-width:920px}}
@media(max-width:767.98px){.server-hero-actions{width:100%}.server-add-btn{flex:1}.server-modal .modal-dialog{margin:.5rem}.server-form-table th{width:39%}.server-form-table th,.server-form-table td{padding:.32rem .4rem}.server-detail-table th,.server-detail-table td{font-size:.78rem;padding:.5rem .55rem}.server-detail-table th{width:38%;white-space:normal}}
</style>




<link rel="stylesheet" href="<?php echo $baseUrl; ?>/assets/css/hdd-primary-action-button.css">

<div class="server-page pb-4">
    <div class="server-hero d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div>
            <h1>บันทึกข้อมูลเครื่อง Server</h1>
            <!-- <p>จัดเก็บข้อมูลเครื่อง Server ผู้ดูแล สถานที่ และวันหมดอายุ</p> -->
        </div>
        <div class="server-hero-actions">
            <div class="server-total">ข้อมูลทั้งหมด <strong><?php echo number_format($serverTotalRows); ?></strong> เครื่อง</div>
            <button class="btn btn-light hdd-primary-action-btn js-server-add" type="button" data-bs-toggle="modal" data-bs-target="#serverFormModal">+ เพิ่มเครื่อง Server</button>
        </div>
    </div>

    <?php if ($pageSuccess !== ''): ?><div class="alert alert-success border-0 shadow-sm"><?php echo serverE($pageSuccess); ?></div><?php endif; ?>
    <?php if ($pageError !== ''): ?><div class="alert alert-danger border-0 shadow-sm"><?php echo serverE($pageError); ?></div><?php endif; ?>

    <div class="server-card p-3">
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
            <div class="server-section-title">รายการเครื่อง Server</div>
            <form method="get" class="server-search-form" autocomplete="off">
                <input type="text" name="q" class="form-control form-control-sm" value="<?php echo serverE($query); ?>" placeholder="ค้นหาชื่อเครื่อง, DNS, S/N, ผู้ดูแล, ที่ตั้ง">
                <button class="btn btn-sm btn-primary" type="submit">ค้นหา</button>
                <a href="index.php" class="btn btn-sm btn-outline-secondary">ล้างค่า</a>
            </form>
        </div>

        <div class="table-responsive server-table-wrap">
            <table class="table table-sm align-middle server-table mb-0">
                <thead><tr><th>ลำดับ</th><th class="text-start">ชื่อเครื่อง</th><th class="text-start">DNS</th><th class="text-start">S/N : M/T</th><th class="text-start">ผู้ดูแล/ที่ตั้ง</th><th>หมดอายุ</th><th>ข้อมูลเครื่อง</th><th>จัดการ</th></tr></thead>
                <tbody>
                <?php if (empty($serverRows)): ?>
                    <tr><td colspan="8" class="text-center text-muted py-5">ยังไม่มีข้อมูลเครื่อง Server</td></tr>
                <?php else: ?>
                    <?php foreach ($serverRows as $index => $row):
                        $rowId = $row[$primaryKey] ?? '';
                        $serverName = serverValue($row,$serverColumnMap,'server_name');
                        $dnsName = serverValue($row,$serverColumnMap,'dns_name');
                        $serialMt = serverValue($row,$serverColumnMap,'serial_mt');
                        $processor = serverValue($row,$serverColumnMap,'processor');
                        $memorySize = serverValue($row,$serverColumnMap,'memory_size');
                        $memoryType = serverValue($row,$serverColumnMap,'memory_type');
                        $diskSize = serverValue($row,$serverColumnMap,'disk_size');
                        $diskType = serverValue($row,$serverColumnMap,'disk_type');
                        $caretaker = serverValue($row,$serverColumnMap,'caretaker');
                        $location = serverValue($row,$serverColumnMap,'location');
                        $detailText = serverValue($row,$serverColumnMap,'detail');
                        $expireRaw = serverRawValue($row,$serverColumnMap,'expire_date');
                        $expireInput = serverDateForInput($expireRaw);
                        $badgeClass = serverExpireBadgeClass($expireRaw);
                    ?>
                    <tr>
                        <td><?php echo number_format($serverOffset+$index+1); ?></td>
                        <td class="fw-bold text-primary"><?php echo serverE($serverName); ?></td>
                        <td><?php echo serverE($dnsName); ?></td>
                        <td><?php echo serverE($serialMt); ?></td>
                        <td><div class="fw-bold"><?php echo serverE($caretaker); ?></div><div class="text-muted"><?php echo serverE($location); ?></div></td>
                        <td class="text-center"><span class="server-expire-badge text-bg-<?php echo serverE($badgeClass); ?>"><?php echo serverE(serverFormatDate($expireRaw)); ?></span></td>
                        <td class="text-center"><button type="button" class="btn btn-sm btn-outline-info server-detail-btn js-server-detail" data-bs-toggle="modal" data-bs-target="#serverDetailModal" data-server-name="<?php echo serverE($serverName); ?>" data-dns="<?php echo serverE($dnsName); ?>" data-serial="<?php echo serverE($serialMt); ?>" data-caretaker="<?php echo serverE($caretaker); ?>" data-location="<?php echo serverE($location); ?>" data-expire="<?php echo serverE(serverFormatDate($expireRaw)); ?>" data-processor="<?php echo serverE($processor); ?>" data-memory-size="<?php echo serverE($memorySize); ?>" data-memory-type="<?php echo serverE($memoryType); ?>" data-disk-size="<?php echo serverE($diskSize); ?>" data-disk-type="<?php echo serverE($diskType); ?>" data-detail="<?php echo serverE($detailText); ?>">รายละเอียด</button></td>
                        <td><div class="server-actions">
                            <?php if ($rowId !== ''): ?>
                            <button type="button" class="btn btn-sm btn-outline-warning server-action-btn js-server-edit" data-bs-toggle="modal" data-bs-target="#serverFormModal" data-record-id="<?php echo serverE($rowId); ?>" data-server-name="<?php echo serverE(serverRawValue($row,$serverColumnMap,'server_name')); ?>" data-dns-name="<?php echo serverE(serverRawValue($row,$serverColumnMap,'dns_name')); ?>" data-serial-mt="<?php echo serverE(serverRawValue($row,$serverColumnMap,'serial_mt')); ?>" data-processor="<?php echo serverE(serverRawValue($row,$serverColumnMap,'processor')); ?>" data-memory-size="<?php echo serverE(serverRawValue($row,$serverColumnMap,'memory_size')); ?>" data-memory-type="<?php echo serverE(serverRawValue($row,$serverColumnMap,'memory_type')); ?>" data-disk-size="<?php echo serverE(serverRawValue($row,$serverColumnMap,'disk_size')); ?>" data-disk-type="<?php echo serverE(serverRawValue($row,$serverColumnMap,'disk_type')); ?>" data-caretaker="<?php echo serverE(serverRawValue($row,$serverColumnMap,'caretaker')); ?>" data-location="<?php echo serverE(serverRawValue($row,$serverColumnMap,'location')); ?>" data-expire-date="<?php echo serverE($expireInput); ?>"><svg width="14" height="14" viewBox="0 0 16 16" fill="currentColor" role="img" aria-label="แก้ไข"><path d="M12.146.146a.5.5 0 0 1 .708 0l3 3a.5.5 0 0 1 0 .708l-10 10A.5.5 0 0 1 5.5 14H2a.5.5 0 0 1-.5-.5V10a.5.5 0 0 1 .146-.354zM11.207 2.5 13.5 4.793 14.793 3.5 12.5 1.207zM12.793 5.5 10.5 3.207 4 9.707V10h.5a.5.5 0 0 1 .5.5v.5h.5a.5.5 0 0 1 .5.5v.5h.293zM3.5 10.207 2.5 11.207V13h1.793l1-1H5.5v-.5H5a.5.5 0 0 1-.5-.5v-.5H4a.5.5 0 0 1-.5-.5z"/></svg></button>
                            <form method="post" class="d-inline" onsubmit="return confirm('ยืนยันลบข้อมูลเครื่อง Server รายการนี้?');"><input type="hidden" name="action" value="delete"><input type="hidden" name="record_id" value="<?php echo serverE($rowId); ?>"><button type="submit" class="btn btn-sm btn-outline-danger server-action-btn" title="ลบ" aria-label="ลบ"><svg class="action-icon" width="14" height="14" viewBox="0 0 16 16" fill="currentColor" aria-hidden="true"><path d="M5.5 5.5A.5.5 0 0 1 6 6v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m2.5 0a.5.5 0 0 1 .5.5v6a.5.5 0 0 1-1 0V6a.5.5 0 0 1 .5-.5m3 .5a.5.5 0 0 0-1 0v6a.5.5 0 0 0 1 0z"/><path d="M14.5 3a1 1 0 0 1-1 1H13v9a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V4h-.5a1 1 0 0 1 0-2H5a1 1 0 0 1 1-1h4a1 1 0 0 1 1 1h2.5a1 1 0 0 1 1 1M4.118 4 4 4.059V13a1 1 0 0 0 1 1h6a1 1 0 0 0 1-1V4.059L11.882 4zM6.5 2a.5.5 0 0 0-.5.5V3h4v-.5a.5.5 0 0 0-.5-.5z"/></svg></button></form>
                            <?php else: ?><span class="text-muted">-</span><?php endif; ?>
                        </div></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>

        <?php $serverStartRow=$serverTotalRows>0?$serverOffset+1:0;$serverEndRow=min($serverOffset+count($serverRows),$serverTotalRows); ?>
        <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3">
            <div class="server-pagination-info">แสดง <?php echo number_format($serverStartRow); ?>-<?php echo number_format($serverEndRow); ?> จาก <?php echo number_format($serverTotalRows); ?> รายการ | หน้า <?php echo number_format($serverPage); ?> / <?php echo number_format($serverTotalPages); ?></div>
            <?php if ($serverTotalPages>1): $pageStart=max(1,$serverPage-2);$pageEnd=min($serverTotalPages,$serverPage+2); ?>
            <nav class="server-pagination"><ul class="pagination pagination-sm mb-0">
                <li class="page-item <?php echo $serverPage<=1?'disabled':''; ?>"><a class="page-link" href="<?php echo serverE(serverBuildPageUrl($serverPage-1,$query)); ?>">ก่อนหน้า</a></li>
                <?php for($pageNo=$pageStart;$pageNo<=$pageEnd;$pageNo++): ?><li class="page-item <?php echo $pageNo===$serverPage?'active':''; ?>"><a class="page-link" href="<?php echo serverE(serverBuildPageUrl($pageNo,$query)); ?>"><?php echo number_format($pageNo); ?></a></li><?php endfor; ?>
                <li class="page-item <?php echo $serverPage>=$serverTotalPages?'disabled':''; ?>"><a class="page-link" href="<?php echo serverE(serverBuildPageUrl($serverPage+1,$query)); ?>">ถัดไป</a></li>
            </ul></nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<div class="modal fade server-modal" id="serverFormModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <form method="post" class="modal-content" id="serverForm" autocomplete="off">
            <div class="modal-header"><div><h5 class="modal-title" id="serverFormTitle">เพิ่มข้อมูลเครื่อง Server</h5><div class="small text-muted mt-1" id="serverFormSubtitle">กรอกข้อมูลเครื่อง Server</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
            <div class="modal-body">
                <input type="hidden" name="action" value="save"><input type="hidden" name="record_id" value="">
                <div class="table-responsive server-form-table-wrap">
                    <table class="table table-bordered server-form-table">
                        <tbody>
                            <tr><th><label class="server-form-label server-required">ชื่อเครื่อง</label></th><td><input type="text" name="server_name" class="form-control" required></td></tr>
                            <tr><th><label class="server-form-label">DNS</label></th><td><input type="text" name="dns_name" class="form-control" placeholder="เช่น idrac-server01 หรือ mtc-server.local"></td></tr>
                            <tr><th><label class="server-form-label">S/N : M/T</label></th><td><input type="text" name="serial_mt" class="form-control"></td></tr>
                            <tr><th><label class="server-form-label">Processor</label></th><td><input type="text" name="processor" class="form-control"></td></tr>
                            <tr><th><label class="server-form-label">Memory Size</label></th><td><input type="text" name="memory_size" class="form-control" placeholder="เช่น 128GB"></td></tr>
                            <tr><th><label class="server-form-label">Memory Type</label></th><td><input type="text" name="memory_type" class="form-control" placeholder="เช่น DDR4"></td></tr>
                            <tr><th><label class="server-form-label">Disk Size</label></th><td><input type="text" name="disk_size" class="form-control" placeholder="เช่น 2TB x 4"></td></tr>
                            <tr><th><label class="server-form-label">Disk Type</label></th><td><input type="text" name="disk_type" class="form-control" placeholder="เช่น SSD / SAS / SATA"></td></tr>
                            <tr><th><label class="server-form-label">ผู้ดูแล</label></th><td><input type="text" name="caretaker" class="form-control"></td></tr>
                            <tr><th><label class="server-form-label">ที่ตั้ง</label></th><td><input type="text" name="location" class="form-control" placeholder="เช่น HQ / Tellus"></td></tr>
                            <tr><th><label class="server-form-label">วันที่หมดอายุ</label></th><td><input type="date" name="expire_date" class="form-control"></td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-light btn-sm" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-primary btn-sm px-3" id="serverFormSubmit">บันทึกข้อมูล</button></div>
        </form>
    </div>
</div>

<div class="modal fade server-modal" id="serverDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><div><h5 class="modal-title">รายละเอียดทรัพย์สิน Server</h5><div class="small text-muted mt-1" id="serverDetailSubtitle">-</div></div><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button></div>
        <div class="modal-body">
            <div class="table-responsive server-detail-table-wrap">
                <table class="table table-bordered table-hover server-detail-table">
                    <tbody>
                        <tr><th>ชื่อเครื่อง</th><td data-detail="serverName">-</td></tr>
                        <tr><th>DNS</th><td data-detail="dns">-</td></tr>
                        <tr><th>S/N : M/T</th><td data-detail="serial">-</td></tr>
                        <tr><th>วันที่หมดอายุ</th><td data-detail="expire">-</td></tr>
                        <tr><th>ผู้ดูแล</th><td data-detail="caretaker">-</td></tr>
                        <tr><th>ที่ตั้ง</th><td data-detail="location">-</td></tr>
                        <tr><th>Processor</th><td data-detail="processor">-</td></tr>
                        <tr><th>Memory</th><td><span data-detail="memorySize">-</span><span class="server-detail-subvalue" data-detail="memoryType">-</span></td></tr>
                        <tr><th>Disk</th><td><span data-detail="diskSize">-</span><span class="server-detail-subvalue" data-detail="diskType">-</span></td></tr>
                        <tr><th>หมายเหตุ / รายละเอียดเพิ่มเติม</th><td data-detail="detail">-</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
    var form=document.getElementById('serverForm');
    var title=document.getElementById('serverFormTitle');
    var subtitle=document.getElementById('serverFormSubtitle');
    var submit=document.getElementById('serverFormSubmit');
    var fields=['server_name','dns_name','serial_mt','processor','memory_size','memory_type','disk_size','disk_type','caretaker','location','expire_date'];
    function setField(name,value){var input=form?form.querySelector('[name="'+name+'"]'):null;if(input)input.value=value||'';}
    document.querySelectorAll('.js-server-add').forEach(function(btn){btn.addEventListener('click',function(){if(!form)return;form.reset();setField('record_id','');title.textContent='เพิ่มข้อมูลเครื่อง Server';subtitle.textContent='กรอกข้อมูลเครื่อง Server';submit.textContent='บันทึกข้อมูล';});});
    document.querySelectorAll('.js-server-edit').forEach(function(btn){btn.addEventListener('click',function(){if(!form)return;form.reset();setField('record_id',btn.dataset.recordId);fields.forEach(function(name){var key=name.replace(/_([a-z])/g,function(_,c){return c.toUpperCase();});setField(name,btn.dataset[key]);});title.textContent='แก้ไขข้อมูลเครื่อง Server';subtitle.textContent=btn.dataset.serverName||'-';submit.textContent='บันทึกการแก้ไข';});});
    var detailModal=document.getElementById('serverDetailModal');
    function setDetail(key,value){if(!detailModal)return;detailModal.querySelectorAll('[data-detail="'+key+'"]').forEach(function(node){node.textContent=value&&String(value).trim()!==''?value:'-';});}
    document.querySelectorAll('.js-server-detail').forEach(function(btn){btn.addEventListener('click',function(){var d=btn.dataset;setDetail('serverName',d.serverName);setDetail('dns',d.dns);setDetail('serial',d.serial);setDetail('caretaker',d.caretaker);setDetail('location',d.location);setDetail('expire',d.expire);setDetail('processor',d.processor);setDetail('memorySize',d.memorySize);setDetail('memoryType',d.memoryType);setDetail('diskSize',d.diskSize);setDetail('diskType',d.diskType);setDetail('detail',d.detail);var sub=document.getElementById('serverDetailSubtitle');if(sub)sub.textContent=d.serverName?'ข้อมูลเครื่อง: '+d.serverName:'-';});});
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
