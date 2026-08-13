<?php
declare(strict_types=1);


require_once __DIR__ . '/../../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

$basePath = dirname(__DIR__, 2);

$possibleDbFiles = [
    $basePath . '/config/database.php',
    $basePath . '/includes/database.php',
    $basePath . '/includes/db.php',
    $basePath . '/config/db.php',
];

foreach ($possibleDbFiles as $dbFile) {
    if (file_exists($dbFile)) {
        require_once $dbFile;
        break;
    }
}

if (file_exists($basePath . '/includes/auth.php')) {
    require_once $basePath . '/includes/auth.php';
    if (function_exists('require_login')) {
        require_login();
    }
}

$pageTitle = 'อัปโหลดประวัติการจัดส่ง Harddisk';

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

function currentLoginNameForShipmentImport(): string
{
    if (!empty($_SESSION['full_name'])) {
        return cleanText($_SESSION['full_name']);
    }

    if (!empty($_SESSION['employee_code'])) {
        return cleanText($_SESSION['employee_code']);
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        if (!empty($_SESSION['user']['full_name'])) {
            return cleanText($_SESSION['user']['full_name']);
        }

        if (!empty($_SESSION['user']['employee_code'])) {
            return cleanText($_SESSION['user']['employee_code']);
        }

        if (!empty($_SESSION['user']['username'])) {
            return cleanText($_SESSION['user']['username']);
        }
    }

    return 'system';
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("\n        SELECT COUNT(*)\n        FROM INFORMATION_SCHEMA.TABLES\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([':table_name' => $tableName]);

    return (int)$stmt->fetchColumn() > 0;
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    $stmt = $pdo->prepare("\n        SELECT COLUMN_NAME\n        FROM INFORMATION_SCHEMA.COLUMNS\n        WHERE TABLE_SCHEMA = DATABASE()\n          AND TABLE_NAME = :table_name\n    ");
    $stmt->execute([':table_name' => $tableName]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function hasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}


function normalizeCsvEncodingValue($value): string
{
    $value = (string)($value ?? '');
    $value = str_replace("Â ", ' ', $value);

    if ($value === '') {
        return '';
    }

    if (function_exists('mb_check_encoding') && mb_check_encoding($value, 'UTF-8')) {
        return trim($value);
    }

    foreach (['Windows-874', 'CP874', 'TIS-620'] as $sourceEncoding) {
        if (!function_exists('mb_convert_encoding')) {
            break;
        }

        $converted = @mb_convert_encoding($value, 'UTF-8', $sourceEncoding);
        if ($converted !== '' && (!function_exists('mb_check_encoding') || mb_check_encoding($converted, 'UTF-8'))) {
            return trim(str_replace("Â ", ' ', $converted));
        }
    }

    return trim($value);
}

function normalizeCsvRowEncoding(array $row): array
{
    foreach ($row as $index => $value) {
        $row[$index] = normalizeCsvEncodingValue($value);
    }

    return $row;
}

function normalizeHeaderName($value): string
{
    $value = (string)($value ?? '');

    $cleaned = preg_replace('/^\xEF\xBB\xBF/', '', $value);
    $value = $cleaned !== null ? $cleaned : '';

    $value = trim($value);
    $value = str_replace(["\r", "\n", "\t"], ' ', $value);

    $spaced = preg_replace('/\s+/u', '_', $value);
    $value = $spaced !== null ? $spaced : $value;

    $value = str_replace(['-', '.', '/', '\\', '(', ')', '[', ']'], '_', $value);

    if (function_exists('mb_strtolower')) {
        $value = mb_strtolower($value, 'UTF-8');
    } else {
        $value = strtolower($value);
    }

    return trim($value, '_');
}

function rowValue(array $row, array $map, array $aliases): string
{
    foreach ($aliases as $alias) {
        $key = normalizeHeaderName($alias);
        if (array_key_exists($key, $map)) {
            return cleanText($row[$map[$key]] ?? '');
        }
    }

    return '';
}

function normalizeSerial(string $value): string
{
    $value = strtoupper(trim($value));
    $value = preg_replace('/[^A-Z0-9]/', '', $value) ?: '';

    return $value;
}

function normalizeMainBranchCodeForImport(string $value): string
{
    $value = trim($value);

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

function normalizeShipmentStatus(string $status): string
{
    $status = mb_strtolower(trim($status), 'UTF-8');

    $map = [
        '' => 'shipped',
        'sent' => 'shipped',
        'shipped' => 'shipped',
        'จัดส่งแล้ว' => 'shipped',
        'ส่งแล้ว' => 'shipped',
        'นำส่งแล้ว' => 'shipped',
        'pending' => 'pending',
        'รอดำเนินการ' => 'pending',
        'received' => 'received',
        'ได้รับแล้ว' => 'received',
        'installed' => 'installed',
        'ติดตั้งแล้ว' => 'installed',
        'completed' => 'completed',
        'เสร็จสิ้น' => 'completed',
        'cancelled' => 'cancelled',
        'cancel' => 'cancelled',
        'ยกเลิก' => 'cancelled',
    ];

    return $map[$status] ?? ($status !== '' ? $status : 'shipped');
}

function normalizeDateTimeForImport(string $value): ?string
{
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})(.*)$/', $value, $m)) {
        $year = (int)$m[3];
        if ($year > 2400) {
            $year -= 543;
        }
        $value = sprintf('%04d-%02d-%02d%s', $year, (int)$m[2], (int)$m[1], $m[4]);
    } elseif (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(.*)$/', $value, $m)) {
        $year = (int)$m[1];
        if ($year > 2400) {
            $year -= 543;
        }
        $value = sprintf('%04d-%02d-%02d%s', $year, (int)$m[2], (int)$m[3], $m[4]);
    }

    $timestamp = strtotime($value);

    if ($timestamp === false) {
        return null;
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function resolveBranchFromDirectory(PDO $pdo, string $code, string $name = ''): array
{
    $result = [
        'main_branch_code' => '',
        'branch_code' => '',
        'branch_name' => '',
    ];

    if (!tableExists($pdo, 'branch_directory')) {
        return $result;
    }

    $code = trim($code);
    $name = trim($name);

    if ($code !== '') {
        $stmt = $pdo->prepare("\n            SELECT main_branch_code, branch_code, branch_name\n            FROM branch_directory\n            WHERE branch_code = :code_branch\n               OR main_branch_code = :code_main\n               OR LPAD(main_branch_code, 3, '0') = :code_pad_main\n               OR LPAD(branch_code, 3, '0') = :code_pad_branch\n            ORDER BY is_active DESC, id DESC\n            LIMIT 1\n        ");
        $codePad = normalizeMainBranchCodeForImport($code);
        $stmt->execute([
            ':code_branch' => $code,
            ':code_main' => $code,
            ':code_pad_main' => $codePad,
            ':code_pad_branch' => $codePad,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'main_branch_code' => (string)($row['main_branch_code'] ?? ''),
                'branch_code' => (string)($row['branch_code'] ?? ''),
                'branch_name' => (string)($row['branch_name'] ?? ''),
            ];
        }
    }

    if ($name !== '') {
        $stmt = $pdo->prepare("\n            SELECT main_branch_code, branch_code, branch_name\n            FROM branch_directory\n            WHERE branch_name LIKE :branch_name\n            ORDER BY is_active DESC, id DESC\n            LIMIT 1\n        ");
        $stmt->execute([':branch_name' => '%' . $name . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row) {
            return [
                'main_branch_code' => (string)($row['main_branch_code'] ?? ''),
                'branch_code' => (string)($row['branch_code'] ?? ''),
                'branch_name' => (string)($row['branch_name'] ?? ''),
            ];
        }
    }

    return $result;
}

function findDeliveryRequestId(PDO $pdo, string $requestNo): int
{
    $requestNo = trim($requestNo);

    if ($requestNo === '' || !tableExists($pdo, 'harddisk_delivery_requests')) {
        return 0;
    }

    $columns = getTableColumns($pdo, 'harddisk_delivery_requests');
    if (!hasColumn($columns, 'id') || !hasColumn($columns, 'request_no')) {
        return 0;
    }

    $where = 'request_no = :request_no';
    if (hasColumn($columns, 'deleted_at')) {
        $where .= ' AND deleted_at IS NULL';
    }

    $stmt = $pdo->prepare("\n        SELECT id\n        FROM harddisk_delivery_requests\n        WHERE {$where}\n        ORDER BY id DESC\n        LIMIT 1\n    ");
    $stmt->execute([':request_no' => $requestNo]);

    return (int)($stmt->fetchColumn() ?: 0);
}

function buildInsertSql(string $table, array $data): array
{
    $columns = array_keys($data);
    $placeholders = [];
    $params = [];

    foreach ($columns as $column) {
        if ($data[$column] === '__NOW__') {
            $placeholders[] = 'NOW()';
        } else {
            $placeholder = ':' . $column;
            $placeholders[] = $placeholder;
            $params[$placeholder] = $data[$column];
        }
    }

    $sql = "INSERT INTO {$table} (`" . implode('`, `', $columns) . "`) VALUES (" . implode(', ', $placeholders) . ")";

    return [$sql, $params];
}

function buildUpdateSql(string $table, array $data): array
{
    $set = [];
    $params = [];

    foreach ($data as $column => $value) {
        if ($value === '__NOW__') {
            $set[] = "`{$column}` = NOW()";
        } else {
            $placeholder = ':' . $column;
            $set[] = "`{$column}` = {$placeholder}";
            $params[$placeholder] = $value;
        }
    }

    $sql = "UPDATE {$table} SET " . implode(', ', $set) . " WHERE `id` = :id LIMIT 1";

    return [$sql, $params];
}


function findInventoryBySerialForImport(PDO $pdo, array $inventoryColumns, string $hddSerial): ?array
{
    if ($hddSerial === '' || empty($inventoryColumns) || !hasColumn($inventoryColumns, 'id') || !hasColumn($inventoryColumns, 'hdd_serial')) {
        return null;
    }

    $where = ['BINARY `hdd_serial` = :hdd_serial'];
    if (hasColumn($inventoryColumns, 'deleted_at')) {
        $where[] = '`deleted_at` IS NULL';
    }

    $select = ['`id`'];
    if (hasColumn($inventoryColumns, 'status')) {
        $select[] = '`status`';
    } else {
        $select[] = "'' AS `status`";
    }

    $stmt = $pdo->prepare("\n        SELECT " . implode(', ', $select) . "\n        FROM harddisk_inventory\n        WHERE " . implode(' AND ', $where) . "\n        ORDER BY id DESC\n        LIMIT 1\n    ");
    $stmt->execute([':hdd_serial' => $hddSerial]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return null;
    }

    return [
        'id' => (int)($row['id'] ?? 0),
        'status' => trim((string)($row['status'] ?? '')),
    ];
}

function linkShipmentToInventoryForImport(PDO $pdo, array $shipmentColumns, int $shipmentId, int $inventoryId, string $loginName): bool
{
    if ($shipmentId <= 0 || $inventoryId <= 0 || !hasColumn($shipmentColumns, 'hdd_inventory_id')) {
        return false;
    }

    $set = ['`hdd_inventory_id` = :hdd_inventory_id'];
    $params = [
        ':hdd_inventory_id' => $inventoryId,
        ':shipment_id' => $shipmentId,
    ];

    if (hasColumn($shipmentColumns, 'updated_by')) {
        $set[] = '`updated_by` = :updated_by';
        $params[':updated_by'] = $loginName;
    }

    if (hasColumn($shipmentColumns, 'updated_at')) {
        $set[] = '`updated_at` = NOW()';
    }

    $stmt = $pdo->prepare("\n        UPDATE harddisk_shipments\n        SET " . implode(', ', $set) . "\n        WHERE `id` = :shipment_id\n        LIMIT 1\n    ");
    $stmt->execute($params);

    return $stmt->rowCount() > 0;
}

function deductInventoryForShipmentImport(PDO $pdo, array $inventoryColumns, int $inventoryId, string $loginName): string
{
    if ($inventoryId <= 0 || empty($inventoryColumns) || !hasColumn($inventoryColumns, 'id')) {
        return 'not_found';
    }

    if (!hasColumn($inventoryColumns, 'status')) {
        return 'matched_only';
    }

    $stmtCurrent = $pdo->prepare("\n        SELECT `status`\n        FROM harddisk_inventory\n        WHERE `id` = :id\n        LIMIT 1\n    ");
    $stmtCurrent->execute([':id' => $inventoryId]);
    $currentStatus = strtolower(trim((string)$stmtCurrent->fetchColumn()));

    if (in_array($currentStatus, ['shipped', 'sent', 'reserved', 'in_transit', 'delivered'], true)) {
        return 'already_deducted';
    }

    $set = ['`status` = :status'];
    $params = [
        ':status' => 'shipped',
        ':id' => $inventoryId,
    ];

    if (hasColumn($inventoryColumns, 'updated_by')) {
        $set[] = '`updated_by` = :updated_by';
        $params[':updated_by'] = $loginName;
    }

    if (hasColumn($inventoryColumns, 'updated_at')) {
        $set[] = '`updated_at` = NOW()';
    }

    if (hasColumn($inventoryColumns, 'remark')) {
        $set[] = "`remark` = TRIM(CONCAT(COALESCE(`remark`, ''), CASE WHEN COALESCE(`remark`, '') = '' THEN '' ELSE ' | ' END, 'ตัดยอดอัตโนมัติจากการนำเข้าประวัติการจัดส่ง'))";
    }

    $stmtUpdate = $pdo->prepare("\n        UPDATE harddisk_inventory\n        SET " . implode(', ', $set) . "\n        WHERE `id` = :id\n        LIMIT 1\n    ");
    $stmtUpdate->execute($params);

    return 'deducted';
}

$result = null;
$errors = [];
$previewRows = [];

if (!isset($pdo) || !$pdo instanceof PDO) {
    $errors[] = 'ไม่พบการเชื่อมต่อฐานข้อมูล PDO';
} else {
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errors)) {
    $handle = null;

    try {
        if (!tableExists($pdo, 'harddisk_shipments')) {
            throw new Exception('ไม่พบตาราง harddisk_shipments');
        }

        $shipmentColumns = getTableColumns($pdo, 'harddisk_shipments');
        $inventoryTableExists = tableExists($pdo, 'harddisk_inventory');
        $inventoryColumns = $inventoryTableExists ? getTableColumns($pdo, 'harddisk_inventory') : [];

        if (!hasColumn($shipmentColumns, 'id')) {
            throw new Exception('ตาราง harddisk_shipments ต้องมีคอลัมน์ id');
        }

        if (!hasColumn($shipmentColumns, 'hdd_serial')) {
            throw new Exception('ตาราง harddisk_shipments ต้องมีคอลัมน์ hdd_serial');
        }

        if (empty($_FILES['csv_file']['tmp_name'])) {
            throw new Exception('กรุณาเลือกไฟล์ CSV');
        }

        $fileName = (string)($_FILES['csv_file']['name'] ?? '');
        $fileSize = (int)($_FILES['csv_file']['size'] ?? 0);
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));

        if (!in_array($extension, ['csv', 'txt'], true)) {
            throw new Exception('รองรับเฉพาะไฟล์ .csv หรือ .txt เท่านั้น');
        }

        if ($fileSize > 10 * 1024 * 1024) {
            throw new Exception('ไฟล์มีขนาดเกิน 10 MB');
        }

        $handle = fopen($_FILES['csv_file']['tmp_name'], 'r');
        if (!$handle) {
            throw new Exception('ไม่สามารถอ่านไฟล์ CSV ได้');
        }

        $header = fgetcsv($handle);
        if ($header) {
            $header = normalizeCsvRowEncoding($header);
        }
        if (!$header) {
            throw new Exception('ไม่พบหัวตารางในไฟล์ CSV');
        }

        $map = [];
        foreach ($header as $index => $name) {
            $map[normalizeHeaderName((string)$name)] = $index;
        }

        $serialAliases = ['hdd_serial', 'serial', 'serial_hdd', 'sn_hdd', 'sn', 's_n', 'เลข_serial', 'เลขซีเรียล', 'ซีเรียล'];
        $hasSerialColumn = false;
        foreach ($serialAliases as $alias) {
            if (array_key_exists(normalizeHeaderName($alias), $map)) {
                $hasSerialColumn = true;
                break;
            }
        }

        if (!$hasSerialColumn) {
            throw new Exception('ไฟล์ต้องมีคอลัมน์ hdd_serial, serial หรือ SN_HDD');
        }

        $updateExisting = isset($_POST['update_existing']);
        $defaultStatus = normalizeShipmentStatus((string)($_POST['default_status'] ?? 'shipped'));
        $loginName = currentLoginNameForShipmentImport();

        $dateColumn = null;
        if (hasColumn($shipmentColumns, 'shipped_at')) {
            $dateColumn = 'shipped_at';
        } elseif (hasColumn($shipmentColumns, 'shipped_date')) {
            $dateColumn = 'shipped_date';
        } elseif (hasColumn($shipmentColumns, 'created_at')) {
            $dateColumn = 'created_at';
        }

        $statusColumn = null;
        if (hasColumn($shipmentColumns, 'status')) {
            $statusColumn = 'status';
        } elseif (hasColumn($shipmentColumns, 'shipment_status')) {
            $statusColumn = 'shipment_status';
        }

        $summary = [
            'total' => 0,
            'inserted' => 0,
            'updated' => 0,
            'skipped' => 0,
            'failed' => 0,
            'inventory_matched' => 0,
            'inventory_deducted' => 0,
            'inventory_already_deducted' => 0,
            'inventory_not_found' => 0,
            'shipment_linked' => 0,
            'messages' => [],
        ];

        $pdo->beginTransaction();

        while (($row = fgetcsv($handle)) !== false) {
            $row = normalizeCsvRowEncoding($row);
            $summary['total']++;
            $lineNo = $summary['total'] + 1;

            $requestNo = rowValue($row, $map, ['delivery_request_no', 'request_no', 'เลขที่คำขอ', 'เลขคำขอ']);
            $seqNo = rowValue($row, $map, ['seq_no', 'seq', 'no', 'ลำดับ']);
            $uploadedMainBranchCode = rowValue($row, $map, ['main_branch_code', 'รหัสสาขาใหญ่', 'รหัสสาขา']);
            $uploadedBranchCode = rowValue($row, $map, ['branch_code', 'cost_center', 'costcenter', 'cost center', 'รหัสสาขาย่อย', 'รหัส_cost_center']);
            $branchName = rowValue($row, $map, ['branch_name', 'ชื่อสาขา', 'ชื้อสาขา', 'สาขา']);
            $hddSerial = normalizeSerial(rowValue($row, $map, $serialAliases));
            $reportedBy = rowValue($row, $map, ['reported_by', 'requested_by', 'คนแจ้ง', 'ผู้แจ้ง']);
            $createdBy = rowValue($row, $map, ['created_by', 'ผู้บันทึก']);
            if ($reportedBy === '') {
                $reportedBy = $createdBy;
            }
            if ($createdBy === '') {
                $createdBy = $reportedBy;
            }
            $remark = rowValue($row, $map, ['remark', 'note', 'notes', 'หมายเหตุ']);
            $status = normalizeShipmentStatus(rowValue($row, $map, ['status', 'shipment_status', 'สถานะ']));
            $dateRaw = rowValue($row, $map, ['shipped_at', 'shipped_date', 'delivery_date', 'วันที่ส่งให้สาขา', 'วันที่จัดส่ง', 'วันที่ส่ง', 'date', 'วันที่']);
            $shippedAt = normalizeDateTimeForImport($dateRaw) ?: date('Y-m-d H:i:s');

            if ($status === '') {
                $status = $defaultStatus;
            }

            if ($hddSerial === '') {
                $summary['failed']++;
                $summary['messages'][] = "แถวที่ {$lineNo}: ไม่พบ Serial HDD";
                continue;
            }

            $inventoryMatch = null;
            if ($inventoryTableExists) {
                $inventoryMatch = findInventoryBySerialForImport($pdo, $inventoryColumns, $hddSerial);
            }

            if ($inventoryMatch !== null && (int)$inventoryMatch['id'] > 0) {
                $summary['inventory_matched']++;
            } else {
                $summary['inventory_not_found']++;
            }

            $codeForLookup = $uploadedBranchCode !== '' ? $uploadedBranchCode : $uploadedMainBranchCode;
            $branchInfo = resolveBranchFromDirectory($pdo, $codeForLookup, $branchName);

            $mainBranchCode = $uploadedMainBranchCode !== '' ? normalizeMainBranchCodeForImport($uploadedMainBranchCode) : normalizeMainBranchCodeForImport($branchInfo['main_branch_code']);
            $branchCode = $uploadedBranchCode !== '' ? $uploadedBranchCode : (string)$branchInfo['branch_code'];

            if ($branchCode === '' && $mainBranchCode !== '') {
                $branchCode = $mainBranchCode;
            }

            if ($mainBranchCode === '' && $branchCode !== '') {
                $mainBranchCode = normalizeMainBranchCodeForImport($branchCode);
            }

            if ($branchName === '' && $branchInfo['branch_name'] !== '') {
                $branchName = $branchInfo['branch_name'];
            }

            $deliveryRequestId = 0;
            $uploadedRequestId = rowValue($row, $map, ['request_id', 'delivery_request_id']);
            if ($uploadedRequestId !== '' && ctype_digit($uploadedRequestId)) {
                $deliveryRequestId = (int)$uploadedRequestId;
            } elseif ($requestNo !== '') {
                $deliveryRequestId = findDeliveryRequestId($pdo, $requestNo);
            }

            $duplicateWhere = ['`hdd_serial` = :dup_hdd_serial'];
            $duplicateParams = [':dup_hdd_serial' => $hddSerial];

            if (hasColumn($shipmentColumns, 'deleted_at')) {
                $duplicateWhere[] = '`deleted_at` IS NULL';
            }

            if (hasColumn($shipmentColumns, 'branch_code') && $branchCode !== '') {
                $duplicateWhere[] = '`branch_code` = :dup_branch_code';
                $duplicateParams[':dup_branch_code'] = $branchCode;
            }

            if ($dateColumn !== null) {
                $duplicateWhere[] = "DATE(`{$dateColumn}`) = DATE(:dup_shipped_at)";
                $duplicateParams[':dup_shipped_at'] = $shippedAt;
            }

            $stmtDup = $pdo->prepare("\n                SELECT id\n                FROM harddisk_shipments\n                WHERE " . implode(' AND ', $duplicateWhere) . "\n                ORDER BY id DESC\n                LIMIT 1\n            ");
            $stmtDup->execute($duplicateParams);
            $existingId = (int)($stmtDup->fetchColumn() ?: 0);

            if ($existingId > 0 && !$updateExisting) {
                if ($inventoryMatch !== null && (int)$inventoryMatch['id'] > 0) {
                    if (linkShipmentToInventoryForImport($pdo, $shipmentColumns, $existingId, (int)$inventoryMatch['id'], $loginName)) {
                        $summary['shipment_linked']++;
                    }

                    $deductResult = deductInventoryForShipmentImport($pdo, $inventoryColumns, (int)$inventoryMatch['id'], $loginName);
                    if ($deductResult === 'deducted') {
                        $summary['inventory_deducted']++;
                    } elseif ($deductResult === 'already_deducted') {
                        $summary['inventory_already_deducted']++;
                    }
                }

                $summary['skipped']++;
                continue;
            }

            $data = [];

            if (hasColumn($shipmentColumns, 'request_id')) {
                $data['request_id'] = $deliveryRequestId > 0 ? $deliveryRequestId : null;
            }

            if (hasColumn($shipmentColumns, 'delivery_request_id')) {
                $data['delivery_request_id'] = $deliveryRequestId > 0 ? $deliveryRequestId : null;
            }

            if (hasColumn($shipmentColumns, 'delivery_request_no')) {
                $data['delivery_request_no'] = $requestNo !== '' ? $requestNo : null;
            }

            if (hasColumn($shipmentColumns, 'request_no')) {
                $data['request_no'] = $requestNo !== '' ? $requestNo : null;
            }

            if (hasColumn($shipmentColumns, 'seq_no')) {
                $data['seq_no'] = $seqNo !== '' ? $seqNo : null;
            }

            if (hasColumn($shipmentColumns, 'main_branch_code')) {
                $data['main_branch_code'] = $mainBranchCode !== '' ? $mainBranchCode : null;
            }

            if (hasColumn($shipmentColumns, 'branch_code')) {
                $data['branch_code'] = $branchCode !== '' ? $branchCode : null;
            }

            if (hasColumn($shipmentColumns, 'branch_name')) {
                $data['branch_name'] = $branchName !== '' ? $branchName : null;
            }

            $data['hdd_serial'] = $hddSerial;

            if (hasColumn($shipmentColumns, 'hdd_inventory_id') && $inventoryMatch !== null && (int)$inventoryMatch['id'] > 0) {
                $data['hdd_inventory_id'] = (int)$inventoryMatch['id'];
            }

            if (hasColumn($shipmentColumns, 'reported_by')) {
                $data['reported_by'] = $reportedBy !== '' ? $reportedBy : $loginName;
            }

            if (hasColumn($shipmentColumns, 'created_by')) {
                $data['created_by'] = $createdBy !== '' ? $createdBy : $loginName;
            }

            if ($statusColumn !== null) {
                $data[$statusColumn] = $status;
            }

            if (hasColumn($shipmentColumns, 'shipped_at')) {
                $data['shipped_at'] = $shippedAt;
            }

            if (hasColumn($shipmentColumns, 'shipped_date')) {
                $data['shipped_date'] = substr($shippedAt, 0, 10);
            }

            if (hasColumn($shipmentColumns, 'remark')) {
                $data['remark'] = $remark !== '' ? $remark : null;
            }

            if ($existingId > 0 && $updateExisting) {
                if (hasColumn($shipmentColumns, 'updated_by')) {
                    $data['updated_by'] = $loginName;
                }

                if (hasColumn($shipmentColumns, 'updated_at')) {
                    $data['updated_at'] = '__NOW__';
                }

                [$sqlUpdate, $paramsUpdate] = buildUpdateSql('harddisk_shipments', $data);
                $paramsUpdate[':id'] = $existingId;
                $stmtUpdate = $pdo->prepare($sqlUpdate);
                $stmtUpdate->execute($paramsUpdate);

                if ($inventoryMatch !== null && (int)$inventoryMatch['id'] > 0) {
                    if (linkShipmentToInventoryForImport($pdo, $shipmentColumns, $existingId, (int)$inventoryMatch['id'], $loginName)) {
                        $summary['shipment_linked']++;
                    }

                    $deductResult = deductInventoryForShipmentImport($pdo, $inventoryColumns, (int)$inventoryMatch['id'], $loginName);
                    if ($deductResult === 'deducted') {
                        $summary['inventory_deducted']++;
                    } elseif ($deductResult === 'already_deducted') {
                        $summary['inventory_already_deducted']++;
                    }
                }

                $summary['updated']++;
                continue;
            }

            if (hasColumn($shipmentColumns, 'created_at') && !isset($data['created_at'])) {
                $data['created_at'] = '__NOW__';
            }

            if (hasColumn($shipmentColumns, 'updated_at') && !isset($data['updated_at'])) {
                $data['updated_at'] = '__NOW__';
            }

            [$sqlInsert, $paramsInsert] = buildInsertSql('harddisk_shipments', $data);
            $stmtInsert = $pdo->prepare($sqlInsert);
            $stmtInsert->execute($paramsInsert);
            $newShipmentId = (int)$pdo->lastInsertId();

            if ($inventoryMatch !== null && (int)$inventoryMatch['id'] > 0) {
                if (linkShipmentToInventoryForImport($pdo, $shipmentColumns, $newShipmentId, (int)$inventoryMatch['id'], $loginName)) {
                    $summary['shipment_linked']++;
                }

                $deductResult = deductInventoryForShipmentImport($pdo, $inventoryColumns, (int)$inventoryMatch['id'], $loginName);
                if ($deductResult === 'deducted') {
                    $summary['inventory_deducted']++;
                } elseif ($deductResult === 'already_deducted') {
                    $summary['inventory_already_deducted']++;
                }
            }

            $summary['inserted']++;
        }

        fclose($handle);
        $pdo->commit();
        $result = $summary;
    } catch (Throwable $e) {
        if (is_resource($handle)) {
            fclose($handle);
        }

        if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
            $pdo->rollBack();
        }

        $errors[] = $e->getMessage();
    }
}

require_once $basePath . '/includes/header.php';

require_login();
require_permission('shipment.manage');

?>

<style>
    body { background: #f3f6fb; }
    .shipment-import-page { padding: 10px 0 16px 0; }
    .shipment-import-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .shipment-import-subtitle { font-size: 13px; color: #64748b; }
    .import-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .import-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; }
    .import-card .card-body { padding: 14px; }
    .hero-upload { border: 0; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #ffffff; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22); }
    .hero-upload .card-body { padding: 14px 16px; }
    .form-label { font-size: 12px; color: #475569; font-weight: 800; margin-bottom: 4px; }
    .form-control, .form-select, .btn { font-size: 13px; border-radius: 10px; }
    .help-box { background: #eff6ff; border: 1px solid #bfdbfe; border-radius: 12px; padding: 10px 12px; color: #1e3a8a; font-size: 13px; }
    .template-table th, .template-table td { font-size: 13px; vertical-align: middle; }
    .summary-box { border-radius: 14px; padding: 12px; background: #f8fafc; border: 1px solid #e2e8f0; text-align: center; height: 100%; }
    .summary-number { font-size: 28px; font-weight: 900; line-height: 1; }
    .summary-label { font-size: 12px; color: #64748b; margin-top: 4px; }
    .code-sample { background: #0f172a; color: #e2e8f0; border-radius: 12px; padding: 10px 12px; font-size: 12px; overflow: auto; }
</style>

<div class="container-fluid shipment-import-page">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
        <div>
            <h3 class="shipment-import-title">อัปโหลดประวัติการจัดส่ง Harddisk</h3>
            <div class="shipment-import-subtitle">นำเข้าข้อมูลจากไฟล์ CSV ลงตาราง harddisk_shipments</div>
        </div>

        <div class="d-flex gap-2">
            <a href="index.php" class="btn btn-outline-secondary btn-sm">กลับหน้าประวัติการจัดส่ง</a>
            <a href="../dashboard/index.php" class="btn btn-outline-secondary btn-sm">Dashboard</a>
        </div>
    </div>

    <div class="card hero-upload mb-3">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold">Import Shipment History</div>
                <div class="small opacity-75">รองรับไฟล์ CSV จาก Excel เช่น ลำดับ, รหัสสาขา, ชื่อสาขา, SN_HDD, วันที่ส่งให้สาขา, คนแจ้ง</div>
            </div>
            <div class="small">สถานะเริ่มต้น: <strong>จัดส่งแล้ว</strong></div>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <strong>ไม่สามารถอัปโหลดได้</strong>
            <ul class="mb-0 mt-1">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo h($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if ($result !== null): ?>
        <div class="card import-card mb-3">
            <div class="card-header">ผลการอัปโหลด</div>
            <div class="card-body">
                <div class="row g-2">
                    <div class="col-md-2"><div class="summary-box"><div class="summary-number text-dark"><?php echo number_format($result['total']); ?></div><div class="summary-label">รายการทั้งหมด</div></div></div>
                    <div class="col-md-2"><div class="summary-box"><div class="summary-number text-success"><?php echo number_format($result['inserted']); ?></div><div class="summary-label">เพิ่มใหม่</div></div></div>
                    <div class="col-md-2"><div class="summary-box"><div class="summary-number text-primary"><?php echo number_format($result['updated']); ?></div><div class="summary-label">อัปเดตซ้ำ</div></div></div>
                    <div class="col-md-2"><div class="summary-box"><div class="summary-number text-warning"><?php echo number_format($result['skipped']); ?></div><div class="summary-label">ข้ามรายการซ้ำ</div></div></div>
                    <div class="col-md-2"><div class="summary-box"><div class="summary-number text-danger"><?php echo number_format($result['failed']); ?></div><div class="summary-label">ผิดพลาด</div></div></div>
                    <div class="col-md-2 d-flex align-items-center justify-content-end"><a href="index.php" class="btn btn-success w-100">ดูประวัติ</a></div>
                </div>

                <div class="row g-2 mt-2">
                    <div class="col-md-3"><div class="summary-box"><div class="summary-number text-primary"><?php echo number_format($result['inventory_matched'] ?? 0); ?></div><div class="summary-label">จับคู่ Serial ในคลัง</div></div></div>
                    <div class="col-md-3"><div class="summary-box"><div class="summary-number text-success"><?php echo number_format($result['inventory_deducted'] ?? 0); ?></div><div class="summary-label">ตัดยอดอัตโนมัติ</div></div></div>
                    <div class="col-md-3"><div class="summary-box"><div class="summary-number text-secondary"><?php echo number_format($result['inventory_already_deducted'] ?? 0); ?></div><div class="summary-label">ตัดยอดอยู่แล้ว</div></div></div>
                    <div class="col-md-3"><div class="summary-box"><div class="summary-number text-danger"><?php echo number_format($result['inventory_not_found'] ?? 0); ?></div><div class="summary-label">ไม่พบในคลัง</div></div></div>
                </div>

                <div class="alert alert-info mt-3 mb-0">
                    เมื่อ Serial HDD ในไฟล์ตรงกับ <strong>harddisk_inventory.hdd_serial</strong> ระบบจะจับคู่กับรายการจัดส่ง และปรับสถานะ HDD ในคลังเป็น <strong>shipped</strong> เพื่อเป็นการตัดยอดอัตโนมัติ
                </div>

                <?php if (!empty($result['messages'])): ?>
                    <div class="alert alert-warning mt-3 mb-0">
                        <?php foreach ($result['messages'] as $message): ?>
                            <div><?php echo h($message); ?></div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    <?php endif; ?>

    <div class="row g-3">
        <div class="col-lg-7">
            <div class="card import-card">
                <div class="card-header">เลือกไฟล์สำหรับอัปโหลด</div>
                <div class="card-body">
                    <form method="post" enctype="multipart/form-data" autocomplete="off">
                        <div class="mb-3">
                            <label class="form-label">ไฟล์ CSV <span class="text-danger">*</span></label>
                            <input type="file" name="csv_file" class="form-control" accept=".csv,.txt" required>
                            <div class="form-text">แนะนำให้ Save As จาก Excel เป็น CSV UTF-8 ก่อนอัปโหลด</div>
                        </div>

                        <div class="row g-2 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">สถานะเริ่มต้น หากไฟล์ไม่ระบุสถานะ</label>
                                <select name="default_status" class="form-select">
                                    <option value="shipped">จัดส่งแล้ว</option>
                                    <option value="pending">รอดำเนินการ</option>
                                    <option value="received">ได้รับแล้ว</option>
                                    <option value="completed">เสร็จสิ้น</option>
                                </select>
                            </div>
                            <div class="col-md-6 d-flex align-items-end">
                                <div class="form-check">
                                    <input type="checkbox" name="update_existing" value="1" class="form-check-input" id="update_existing">
                                    <label class="form-check-label" for="update_existing">ถ้าข้อมูลซ้ำ ให้ Update รายการเดิม</label>
                                </div>
                            </div>
                        </div>

                        <div class="help-box mb-3">
                            ระบบตรวจรายการซ้ำจาก <strong>Serial HDD + รหัสสาขา + วันที่จัดส่ง</strong> และถ้า Serial HDD ตรงกับรายการในคลัง ระบบจะ <strong>จับคู่และตัดยอดคลังอัตโนมัติ</strong>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="index.php" class="btn btn-outline-secondary">ยกเลิก</a>
                            <button type="submit" class="btn btn-primary">อัปโหลดเข้าประวัติการจัดส่ง</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-5">
            <div class="card import-card mb-3">
                <div class="card-header">รูปแบบไฟล์ CSV ที่รองรับ</div>
                <div class="card-body">
                    <table class="table table-bordered template-table mb-3">
                        <thead class="table-light">
                            <tr>
                                <th>คอลัมน์</th>
                                <th>จำเป็น</th>
                                <th>ตัวอย่าง</th>
                            </tr>
                        </thead>
                        <tbody>
                            <tr><td><strong>SN_HDD</strong> หรือ hdd_serial</td><td><span class="badge bg-danger">จำเป็น</span></td><td>WWD2FN47</td></tr>
                            <tr><td>รหัสสาขา หรือ branch_code</td><td><span class="badge bg-secondary">แนะนำ</span></td><td>301</td></tr>
                            <tr><td>ชื่อสาขา หรือ branch_name</td><td><span class="badge bg-secondary">แนะนำ</span></td><td>สวนป่าน</td></tr>
                            <tr><td>วันที่ส่งให้สาขา หรือ shipped_date</td><td><span class="badge bg-secondary">แนะนำ</span></td><td>2026-07-06</td></tr>
                            <tr><td>คนแจ้ง หรือ reported_by</td><td><span class="badge bg-secondary">ไม่บังคับ</span></td><td>นายทดสอบ</td></tr>
                            <tr><td>request_no</td><td><span class="badge bg-secondary">ไม่บังคับ</span></td><td>REQ2026070001</td></tr>
                        </tbody>
                    </table>

                    <div class="code-sample">ลำดับ,รหัสสาขา,ชื่อสาขา,SN_HDD,วันที่ส่งให้สาขา,คนแจ้ง
1,301,สวนป่าน,WWD2FN47,2026-07-06,นายทดสอบ
2,297,ถ.พหลโยธิน 62,WWD2FN3B,2026-07-06,นายทดสอบ</div>
                </div>
            </div>

            <div class="alert alert-info mb-0">
                ถ้าไฟล์มีเฉพาะ “รหัสสาขา” ระบบจะพยายามเทียบข้อมูลจาก <strong>branch_directory</strong> เพื่อเติมชื่อสาขา / Cost Center ให้อัตโนมัติ และจะตัดยอดจาก <strong>harddisk_inventory</strong> เมื่อพบ Serial ตรงกัน
            </div>
        </div>
    </div>
</div>

<?php require_once $basePath . '/includes/footer.php'; ?>
