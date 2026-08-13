<?php
declare(strict_types=1);


require_once __DIR__ . '/../../includes/auth.php';
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$basePath = dirname(__DIR__, 2);
if (file_exists($basePath . '/includes/permissions.php')) {
    require_once $basePath . '/includes/permissions.php';
}

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

if (!function_exists('h')) {
    function h($value): string
    {
        return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}

$pageTitle = 'ประวัติการจัดส่ง Harddisk';

if (!isset($pdo) || !$pdo instanceof PDO) {
    require_once $basePath . '/includes/header.php';
    ?>
    <div class="container-fluid py-4">
        <div class="alert alert-danger">
            <strong>ไม่พบการเชื่อมต่อฐานข้อมูล</strong><br>
            กรุณาตรวจสอบไฟล์เชื่อมต่อฐานข้อมูล เช่น <code>config/database.php</code>
        </div>
    </div>
    <?php
    require_once $basePath . '/includes/footer.php';
    exit;
}


function normalizeThaiText($value): string
{
    if ($value === null) {
        return '';
    }

    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $hasThai = static function (string $candidate): bool {
        return preg_match('/[\x{0E00}-\x{0E7F}]/u', $candidate) === 1;
    };

    $isUtf8 = function (string $candidate): bool {
        if (function_exists('mb_check_encoding')) {
            return mb_check_encoding($candidate, 'UTF-8');
        }
        return preg_match('//u', $candidate) === 1;
    };

    // Case 1: text is already correct UTF-8.
    if ($isUtf8($text)) {
        // Fix common mojibake such as "à¸à¸²à¸¢..." caused by UTF-8 bytes being decoded as Windows-1252/Latin-1.
        if (preg_match('/(?:à¸|à¹|Ã|Â)/u', $text) === 1) {
            $candidate = '';

            if (function_exists('mb_convert_encoding')) {
                $candidate = (string)@mb_convert_encoding($text, 'Windows-1252', 'UTF-8');
            } elseif (function_exists('iconv')) {
                $candidate = (string)@iconv('UTF-8', 'Windows-1252//IGNORE', $text);
            }

            if ($candidate !== '' && $isUtf8($candidate) && $hasThai($candidate)) {
                return trim($candidate);
            }
        }

        return $text;
    }

    // Case 2: raw bytes are Thai legacy encodings from CSV / old MySQL columns.
    $encodings = ['Windows-874', 'CP874', 'TIS-620', 'ISO-8859-11'];

    if (function_exists('mb_convert_encoding')) {
        foreach ($encodings as $encoding) {
            $candidate = @mb_convert_encoding($text, 'UTF-8', $encoding);
            if (is_string($candidate) && $candidate !== '' && $isUtf8($candidate)) {
                return trim($candidate);
            }
        }
    }

    if (function_exists('iconv')) {
        foreach ($encodings as $encoding) {
            $candidate = @iconv($encoding, 'UTF-8//IGNORE', $text);
            if (is_string($candidate) && $candidate !== '' && $isUtf8($candidate)) {
                return trim($candidate);
            }
        }
    }

    return $text;
}

function shipmentDisplayNameOnly($value): string
{
    $displayName = normalizeThaiText($value);
    $displayName = preg_replace('/\s*\(\d+\)\s*$/u', '', $displayName);

    return trim((string)$displayName);
}


function shipmentReporterLooksCorrupted(string $value): bool
{
    $value = trim($value);
    if ($value === '') {
        return true;
    }

    if (preg_match('/(?:à¸|à¹|Ã|Â)/u', $value) === 1) {
        return true;
    }

    return substr_count($value, '?') >= 2 || preg_match('/\x{FFFD}/u', $value) === 1;
}

function shipmentResolveUserName(PDO $pdo, string $lookupValue): string
{
    static $metadata = null;
    static $cache = [];

    $lookupValue = trim($lookupValue);
    if ($lookupValue === '') {
        return '';
    }
    if (array_key_exists($lookupValue, $cache)) {
        return $cache[$lookupValue];
    }

    try {
        if ($metadata === null) {
            $tableStmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
            $tableStmt->execute();
            if ((int)$tableStmt->fetchColumn() === 0) {
                $metadata = false;
            } else {
                $columnStmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'");
                $columnStmt->execute();
                $columns = array_map('strtolower', $columnStmt->fetchAll(PDO::FETCH_COLUMN));
                $metadata = ['columns' => $columns];
            }
        }

        if ($metadata === false) {
            return $cache[$lookupValue] = '';
        }

        $columns = $metadata['columns'];
        $has = static fn(string $column): bool => in_array($column, $columns, true);

        $nameExpression = '';
        foreach (['full_name', 'fullname', 'display_name', 'employee_name', 'name', 'user_name', 'username'] as $column) {
            if ($has($column)) {
                $nameExpression = "TRIM(COALESCE(`{$column}`, ''))";
                break;
            }
        }
        if ($nameExpression === '') {
            $first = '';
            $last = '';
            foreach (['first_name', 'firstname', 'fname'] as $column) {
                if ($has($column)) { $first = $column; break; }
            }
            foreach (['last_name', 'lastname', 'lname', 'surname'] as $column) {
                if ($has($column)) { $last = $column; break; }
            }
            if ($first !== '' || $last !== '') {
                $firstSql = $first !== '' ? "COALESCE(`{$first}`, '')" : "''";
                $lastSql = $last !== '' ? "COALESCE(`{$last}`, '')" : "''";
                $nameExpression = "TRIM(CONCAT({$firstSql}, ' ', {$lastSql}))";
            }
        }
        if ($nameExpression === '') {
            return $cache[$lookupValue] = '';
        }

        $conditions = [];
        $params = [];
        foreach (['employee_code', 'emp_code', 'employee_id', 'emp_id', 'user_code', 'staff_code', 'username', 'user_name', 'login_name', 'id'] as $index => $column) {
            if (!$has($column)) continue;
            $parameter = ':lookup_' . $index;
            $conditions[] = "TRIM(CAST(`{$column}` AS CHAR)) = {$parameter}";
            $params[$parameter] = $lookupValue;
        }
        if (!$conditions) {
            return $cache[$lookupValue] = '';
        }

        $stmt = $pdo->prepare("SELECT {$nameExpression} AS display_name FROM `users` WHERE (" . implode(' OR ', $conditions) . ") LIMIT 1");
        $stmt->execute($params);
        $name = shipmentDisplayNameOnly($stmt->fetchColumn() ?: '');
        return $cache[$lookupValue] = $name;
    } catch (Throwable $e) {
        error_log('[shipments/index] Cannot resolve reporter name: ' . $e->getMessage());
        return $cache[$lookupValue] = '';
    }
}

function shipmentResolveReporter(PDO $pdo, array $row): string
{
    $fallback = '';
    foreach (['request_requested_by', 'reported_by_raw', 'reported_by', 'created_by_raw', 'created_by'] as $field) {
        $raw = trim((string)($row[$field] ?? ''));
        if ($raw === '') continue;

        $display = shipmentDisplayNameOnly($raw);
        if ($fallback === '' && $display !== '') {
            $fallback = $display;
        }
        if ($display !== '' && !shipmentReporterLooksCorrupted($display)) {
            return $display;
        }

        $lookupValues = [$raw, $display];
        if (preg_match_all('/\d{3,10}/', $raw, $matches)) {
            $lookupValues = array_merge($lookupValues, $matches[0]);
        }
        foreach (array_unique($lookupValues) as $lookupValue) {
            $resolved = shipmentResolveUserName($pdo, (string)$lookupValue);
            if ($resolved !== '' && !shipmentReporterLooksCorrupted($resolved)) {
                return $resolved;
            }
        }
    }

    return $fallback;
}

function shipmentStatusText(?string $status): string
{
    $status = strtolower(trim((string)$status));

    $map = [
        'pending'   => 'รอดำเนินการ',
        'sent'      => 'จัดส่งแล้ว',
        'shipped'   => 'จัดส่งแล้ว',
        'received'  => 'ได้รับแล้ว',
        'installed' => 'ติดตั้งแล้ว',
        'completed' => 'เสร็จสิ้น',
        'cancelled' => 'ยกเลิก',
        'cancel'    => 'ยกเลิก',
    ];

    return $map[$status] ?? ($status !== '' ? $status : '-');
}

function shipmentStatusBadge(?string $status): string
{
    $status = strtolower(trim((string)$status));
    $class = 'shipment-status-badge shipment-status-secondary';

    if (in_array($status, ['sent', 'shipped'], true)) {
        $class = 'shipment-status-badge shipment-status-shipped';
    } elseif (in_array($status, ['received', 'installed', 'completed'], true)) {
        $class = 'shipment-status-badge shipment-status-success';
    } elseif ($status === 'pending') {
        $class = 'shipment-status-badge shipment-status-pending';
    } elseif (in_array($status, ['cancelled', 'cancel'], true)) {
        $class = 'shipment-status-badge shipment-status-danger';
    }

    return '<span class="' . h($class) . '">' . h(shipmentStatusText($status)) . '</span>';
}

function formatDateThai(?string $date, bool $showTime = false): string
{
    if (empty($date) || $date === '0000-00-00' || $date === '0000-00-00 00:00:00') {
        return '-';
    }

    $timestamp = strtotime($date);
    if (!$timestamp) {
        return '-';
    }

    return $showTime ? date('d/m/Y H:i', $timestamp) : date('d/m/Y', $timestamp);
}

function formatMainBranchCode($value): string
{
    $value = trim((string)($value ?? ''));

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

function shortText($value, int $length = 80): string
{
    $text = trim((string)($value ?? ''));
    if ($text === '') {
        return '-';
    }

    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
        return mb_strlen($text, 'UTF-8') > $length
            ? mb_substr($text, 0, $length, 'UTF-8') . '...'
            : $text;
    }

    return strlen($text) > $length ? substr($text, 0, $length) . '...' : $text;
}

function bindValues(PDOStatement $stmt, array $params): void
{
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
}


function shipmentTableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
    $stmt->execute([':table_name' => $tableName]);
    return (int)$stmt->fetchColumn() > 0;
}

function shipmentTableColumns(PDO $pdo, string $tableName): array
{
    if (!shipmentTableExists($pdo, $tableName)) {
        return [];
    }
    $stmt = $pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table_name");
    $stmt->execute([':table_name' => $tableName]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN) ?: [];
}

function shipmentHasColumn(array $columns, string $column): bool
{
    return in_array($column, $columns, true);
}

function shipmentIsSuperAdmin(): bool
{
    return function_exists('can') && can('request.status.manage');
}

function shipmentCurrentEditorName(): string
{
    $fullName = trim((string)($_SESSION['full_name'] ?? ''));
    if ($fullName === '') {
        $fullName = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
    }
    $employeeCode = trim((string)($_SESSION['employee_code'] ?? ''));
    if ($fullName !== '' && $employeeCode !== '') return $fullName . ' (' . $employeeCode . ')';
    if ($fullName !== '') return $fullName;
    if ($employeeCode !== '') return $employeeCode;
    return 'IT';
}

function shipmentResetItems(PDO $pdo, array $columns, int $requestId): void
{
    if ($requestId <= 0 || !shipmentHasColumn($columns, 'request_id')) return;
    if (shipmentHasColumn($columns, 'deleted_at')) {
        $stmt = $pdo->prepare("UPDATE harddisk_request_items SET deleted_at = NOW() WHERE request_id = :request_id AND deleted_at IS NULL");
    } else {
        $stmt = $pdo->prepare("DELETE FROM harddisk_request_items WHERE request_id = :request_id");
    }
    $stmt->execute([':request_id' => $requestId]);
}

function shipmentSyncMatchedItem(PDO $pdo, array $columns, int $requestId, int $inventoryId, string $serial, string $editor): void
{
    if ($requestId <= 0 || $serial === '' || !shipmentHasColumn($columns, 'request_id') || !shipmentHasColumn($columns, 'hdd_serial')) return;
    $where = ['request_id = :request_id'];
    if (shipmentHasColumn($columns, 'deleted_at')) $where[] = 'deleted_at IS NULL';
    $stmt = $pdo->prepare("SELECT id FROM harddisk_request_items WHERE " . implode(' AND ', $where) . " ORDER BY id DESC LIMIT 1 FOR UPDATE");
    $stmt->execute([':request_id' => $requestId]);
    $id=(int)($stmt->fetchColumn() ?: 0);
    if ($id > 0) {
        $set=['hdd_serial = :serial']; $params=[':serial'=>$serial, ':id'=>$id];
        if (shipmentHasColumn($columns,'hdd_inventory_id')) { $set[]='hdd_inventory_id = :inventory_id'; $params[':inventory_id']=$inventoryId; }
        if (shipmentHasColumn($columns,'scan_status')) $set[]="scan_status = 'matched'";
        if (shipmentHasColumn($columns,'scanned_by')) { $set[]='scanned_by = :editor'; $params[':editor']=$editor; }
        if (shipmentHasColumn($columns,'scanned_at')) $set[]='scanned_at = COALESCE(scanned_at, NOW())';
        if (shipmentHasColumn($columns,'updated_at')) $set[]='updated_at = NOW()';
        $stmt=$pdo->prepare("UPDATE harddisk_request_items SET ".implode(', ',$set)." WHERE id = :id");
        $stmt->execute($params);
        return;
    }
    $cols=['request_id','hdd_serial']; $vals=[':request_id',':serial']; $params=[':request_id'=>$requestId,':serial'=>$serial];
    if (shipmentHasColumn($columns,'hdd_inventory_id')) { $cols[]='hdd_inventory_id'; $vals[]=':inventory_id'; $params[':inventory_id']=$inventoryId; }
    if (shipmentHasColumn($columns,'scan_status')) { $cols[]='scan_status'; $vals[]="'matched'"; }
    if (shipmentHasColumn($columns,'scanned_by')) { $cols[]='scanned_by'; $vals[]=':editor'; $params[':editor']=$editor; }
    if (shipmentHasColumn($columns,'scanned_at')) { $cols[]='scanned_at'; $vals[]='NOW()'; }
    if (shipmentHasColumn($columns,'created_at')) { $cols[]='created_at'; $vals[]='NOW()'; }
    if (shipmentHasColumn($columns,'updated_at')) { $cols[]='updated_at'; $vals[]='NOW()'; }
    $stmt=$pdo->prepare("INSERT INTO harddisk_request_items (`".implode('`, `',$cols)."`) VALUES (".implode(', ',$vals).")");
    $stmt->execute($params);
}

function shipmentSetInventoryStatus(PDO $pdo, array $columns, int $inventoryId, string $serial, string $status, string $editor): void
{
    if (!shipmentHasColumn($columns,'status')) return;
    $where=[]; $params=[':status'=>$status];
    if ($inventoryId > 0 && shipmentHasColumn($columns,'id')) { $where[]='id = :inventory_id'; $params[':inventory_id']=$inventoryId; }
    elseif ($serial !== '' && shipmentHasColumn($columns,'hdd_serial')) { $where[]='BINARY hdd_serial = :serial'; $params[':serial']=$serial; }
    else return;
    if (shipmentHasColumn($columns,'deleted_at')) $where[]='deleted_at IS NULL';
    $set=['status = :status'];
    if (shipmentHasColumn($columns,'updated_at')) $set[]='updated_at = NOW()';
    if (shipmentHasColumn($columns,'updated_by')) { $set[]='updated_by = :editor'; $params[':editor']=$editor; }
    $stmt=$pdo->prepare("UPDATE harddisk_inventory SET ".implode(', ',$set)." WHERE ".implode(' AND ',$where));
    $stmt->execute($params);
}

try {
    $stmtColumns = $pdo->query("SHOW COLUMNS FROM `harddisk_shipments`");
    $shipmentColumns = $stmtColumns->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {
    require_once $basePath . '/includes/header.php';
    ?>
    <div class="container-fluid py-4">
        <div class="alert alert-danger">
            <strong>ไม่พบตาราง harddisk_shipments</strong><br>
            รายละเอียด: <?= h($e->getMessage()) ?>
        </div>
    </div>
    <?php
    require_once $basePath . '/includes/footer.php';
    exit;
}

$has = static function (string $column) use ($shipmentColumns): bool {
    return in_array($column, $shipmentColumns, true);
};

$requestColumns = [];
$hasRequestTable = false;

try {
    $stmtRequestColumns = $pdo->query("SHOW COLUMNS FROM `harddisk_delivery_requests`");
    $requestColumns = $stmtRequestColumns->fetchAll(PDO::FETCH_COLUMN);
    $hasRequestTable = true;
} catch (Throwable $e) {
    $requestColumns = [];
    $hasRequestTable = false;
}

$hasRequestColumn = static function (string $column) use ($requestColumns): bool {
    return in_array($column, $requestColumns, true);
};

$isSuperAdmin = shipmentIsSuperAdmin();
$statusManageError = '';
$itemColumns = shipmentTableColumns($pdo, 'harddisk_request_items');
$inventoryColumns = shipmentTableColumns($pdo, 'harddisk_inventory');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['manage_action'] ?? '') === 'change_status') {
    if (!$isSuperAdmin) {
        $statusManageError = 'ไม่มีสิทธิ์แก้ไขสถานะประวัติการจัดส่ง';
    } elseif (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        $statusManageError = 'CSRF Token ไม่ถูกต้อง กรุณารีเฟรชหน้าแล้วลองใหม่';
    } else {
        try {
            $shipmentId = max(0, (int)($_POST['shipment_id'] ?? 0));
            $newStatus = trim((string)($_POST['status'] ?? ''));
            $allowedStatuses = ['pending_scan','pending','approved','matched','reserved','shipped','received','installed','cancelled','rejected','completed'];
            if ($shipmentId <= 0 || !in_array($newStatus, $allowedStatuses, true)) {
                throw new RuntimeException('ข้อมูลสถานะไม่ถูกต้อง');
            }

            $pdo->beginTransaction();
            $shipmentSelect = ['id'];
            foreach (['request_id','hdd_serial','status','shipment_status'] as $column) if ($has($column)) $shipmentSelect[]='`'.$column.'`';
            $stmt=$pdo->prepare("SELECT ".implode(', ',$shipmentSelect)." FROM harddisk_shipments WHERE id = :id LIMIT 1 FOR UPDATE");
            $stmt->execute([':id'=>$shipmentId]);
            $shipment=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$shipment) throw new RuntimeException('ไม่พบรายการจัดส่งที่ต้องการแก้ไข');

            $requestId=max(0,(int)($shipment['request_id'] ?? 0));
            $serial=trim((string)($shipment['hdd_serial'] ?? ''));
            if ($requestId <= 0) throw new RuntimeException('รายการจัดส่งนี้ไม่ได้เชื่อมกับคำขอ จึงไม่สามารถย้อน Workflow อัตโนมัติได้');

            $requestSelect=['id'];
            foreach (['status','hdd_inventory_id','hdd_serial'] as $column) if ($hasRequestColumn($column)) $requestSelect[]='`'.$column.'`';
            $stmt=$pdo->prepare("SELECT ".implode(', ',$requestSelect)." FROM harddisk_delivery_requests WHERE id = :id LIMIT 1 FOR UPDATE");
            $stmt->execute([':id'=>$requestId]);
            $request=$stmt->fetch(PDO::FETCH_ASSOC);
            if (!$request) throw new RuntimeException('ไม่พบคำขอที่เชื่อมกับรายการจัดส่ง');

            $inventoryId=max(0,(int)($request['hdd_inventory_id'] ?? 0));
            if ($serial === '') $serial=trim((string)($request['hdd_serial'] ?? ''));
            $editor=shipmentCurrentEditorName();
            $releaseStatuses=['pending_scan','pending','approved','cancelled','rejected'];
            $matchedStatuses=['matched','reserved'];

            if (in_array($newStatus,$releaseStatuses,true)) {
                shipmentResetItems($pdo,$itemColumns,$requestId);
                shipmentSetInventoryStatus($pdo,$inventoryColumns,$inventoryId,$serial,'available',$editor);
            } elseif (in_array($newStatus,$matchedStatuses,true)) {
                if ($inventoryId <= 0 || $serial === '') throw new RuntimeException('ไม่สามารถย้อนเป็นรอยืนยันจัดส่งได้ เนื่องจากไม่พบ Serial HDD ที่จับคู่');
                shipmentSyncMatchedItem($pdo,$itemColumns,$requestId,$inventoryId,$serial,$editor);
                shipmentSetInventoryStatus($pdo,$inventoryColumns,$inventoryId,$serial,'reserved',$editor);
            } else {
                if ($inventoryId <= 0 || $serial === '') throw new RuntimeException('ไม่สามารถเปลี่ยนเป็นสถานะหลังจัดส่งได้ เนื่องจากไม่พบ Serial HDD ที่จับคู่');
                shipmentSetInventoryStatus($pdo,$inventoryColumns,$inventoryId,$serial,in_array($newStatus,['installed','completed'],true)?'used':'shipped',$editor);
            }

            $requestSet=[]; $requestParams=[':id'=>$requestId];
            if ($hasRequestColumn('status')) { $requestSet[]='status = :status'; $requestParams[':status']=$newStatus; }
            if (in_array($newStatus,$releaseStatuses,true)) {
                foreach (['hdd_inventory_id','hdd_serial','assigned_by','assigned_at','matched_by','matched_at','shipped_by','shipped_at'] as $column) if ($hasRequestColumn($column)) $requestSet[]='`'.$column.'` = NULL';
            } elseif (in_array($newStatus,$matchedStatuses,true)) {
                foreach (['shipped_by','shipped_at'] as $column) if ($hasRequestColumn($column)) $requestSet[]='`'.$column.'` = NULL';
            }
            if ($hasRequestColumn('updated_at')) $requestSet[]='updated_at = NOW()';
            if ($hasRequestColumn('updated_by')) { $requestSet[]='updated_by = :editor'; $requestParams[':editor']=$editor; }
            if ($requestSet) { $stmt=$pdo->prepare("UPDATE harddisk_delivery_requests SET ".implode(', ',$requestSet)." WHERE id = :id"); $stmt->execute($requestParams); }

            if (in_array($newStatus,$releaseStatuses,true) || in_array($newStatus,$matchedStatuses,true)) {
                if ($has('deleted_at')) {
                    $set=['deleted_at = NOW()']; $params=[':id'=>$shipmentId];
                    if ($has('updated_at')) $set[]='updated_at = NOW()';
                    if ($has('updated_by')) { $set[]='updated_by = :editor'; $params[':editor']=$editor; }
                    $stmt=$pdo->prepare("UPDATE harddisk_shipments SET ".implode(', ',$set)." WHERE id = :id"); $stmt->execute($params);
                } else {
                    $stmt=$pdo->prepare("DELETE FROM harddisk_shipments WHERE id = :id"); $stmt->execute([':id'=>$shipmentId]);
                }
            } else {
                $shipmentStatusColumn = $has('status') ? 'status' : ($has('shipment_status') ? 'shipment_status' : null);
                if ($shipmentStatusColumn !== null) {
                    $set=['`'.$shipmentStatusColumn.'` = :status']; $params=[':status'=>$newStatus,':id'=>$shipmentId];
                    if ($has('updated_at')) $set[]='updated_at = NOW()';
                    if ($has('updated_by')) { $set[]='updated_by = :editor'; $params[':editor']=$editor; }
                    $stmt=$pdo->prepare("UPDATE harddisk_shipments SET ".implode(', ',$set)." WHERE id = :id"); $stmt->execute($params);
                }
            }

            $pdo->commit();
            header('Location: index.php?status_updated=1');
            exit;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            $statusManageError='ไม่สามารถแก้ไขสถานะได้: '.$e->getMessage();
        }
    }
}

$keyword = trim((string)($_GET['keyword'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$dateFrom = trim((string)($_GET['date_from'] ?? ''));
$dateTo = trim((string)($_GET['date_to'] ?? ''));

$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$page = max($page, 1);

$perPage = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 20;
if (!in_array($perPage, [10, 20, 50, 100], true)) {
    $perPage = 20;
}
$offset = ($page - 1) * $perPage;

$selectFields = [];
$selectFields[] = $has('id') ? '`id`' : '0 AS `id`';
$selectFields[] = $has('request_id') ? '`request_id`' : 'NULL AS `request_id`';

if ($has('delivery_request_no')) {
    $selectFields[] = '`delivery_request_no` AS `delivery_request_no`';
} elseif ($has('request_no')) {
    $selectFields[] = '`request_no` AS `delivery_request_no`';
} elseif ($has('request_id') && $hasRequestTable && $hasRequestColumn('id') && $hasRequestColumn('request_no')) {
    $selectFields[] = '(
        SELECT r.`request_no`
        FROM `harddisk_delivery_requests` r
        WHERE r.`id` = `harddisk_shipments`.`request_id`
        LIMIT 1
    ) AS `delivery_request_no`';
} else {
    $selectFields[] = 'NULL AS `delivery_request_no`';
}

$selectFields[] = $has('seq_no') ? '`seq_no`' : 'NULL AS `seq_no`';
$selectFields[] = $has('main_branch_code') ? '`main_branch_code`' : 'NULL AS `main_branch_code`';
$selectFields[] = $has('branch_code') ? '`branch_code`' : 'NULL AS `branch_code`';
$selectFields[] = $has('branch_name') ? '`branch_name`' : 'NULL AS `branch_name`';
$selectFields[] = $has('hdd_serial') ? '`hdd_serial`' : 'NULL AS `hdd_serial`';
$selectFields[] = $has('reported_by') ? '`reported_by`' : 'NULL AS `reported_by`';
$selectFields[] = $has('created_by') ? '`created_by`' : 'NULL AS `created_by`';
$selectFields[] = $has('reported_by') ? 'CAST(`reported_by` AS BINARY) AS `reported_by_raw`' : 'NULL AS `reported_by_raw`';
$selectFields[] = $has('created_by') ? 'CAST(`created_by` AS BINARY) AS `created_by_raw`' : 'NULL AS `created_by_raw`';
$selectFields[] = $has('remark') ? '`remark`' : 'NULL AS `remark`';

if ($has('request_id') && $hasRequestTable && $hasRequestColumn('id')) {
    foreach ([
        'request_reason' => 'request_reason',
        'problem_no' => 'problem_no',
        'requested_by' => 'request_requested_by',
        'requested_at' => 'request_requested_at'
    ] as $requestColumn => $alias) {
        if ($hasRequestColumn($requestColumn)) {
            $selectFields[] = '(
                SELECT r.`' . $requestColumn . '`
                FROM `harddisk_delivery_requests` r
                WHERE r.`id` = `harddisk_shipments`.`request_id`
                LIMIT 1
            ) AS `' . $alias . '`';
        } else {
            $selectFields[] = 'NULL AS `' . $alias . '`';
        }
    }
} else {
    $selectFields[] = 'NULL AS `request_reason`';
    $selectFields[] = 'NULL AS `problem_no`';
    $selectFields[] = 'NULL AS `request_requested_by`';
    $selectFields[] = 'NULL AS `request_requested_at`';
}

if ($has('status')) {
    $selectFields[] = '`status` AS `display_status`';
} elseif ($has('shipment_status')) {
    $selectFields[] = '`shipment_status` AS `display_status`';
} else {
    $selectFields[] = "'shipped' AS `display_status`";
}

if ($has('shipped_at')) {
    $selectFields[] = '`shipped_at` AS `display_shipped_at`';
    $dateColumn = 'shipped_at';
} elseif ($has('shipped_date')) {
    $selectFields[] = '`shipped_date` AS `display_shipped_at`';
    $dateColumn = 'shipped_date';
} elseif ($has('created_at')) {
    $selectFields[] = '`created_at` AS `display_shipped_at`';
    $dateColumn = 'created_at';
} else {
    $selectFields[] = 'NULL AS `display_shipped_at`';
    $dateColumn = null;
}

$selectFields[] = $has('shipped_date') ? '`shipped_date`' : 'NULL AS `shipped_date`';
$selectFields[] = $has('created_at') ? '`created_at`' : 'NULL AS `created_at`';

$where = [];
$params = [];

if ($has('deleted_at')) {
    $where[] = '`deleted_at` IS NULL';
}

if ($dateColumn !== null && $dateFrom !== '') {
    $where[] = "DATE(`{$dateColumn}`) >= :date_from";
    $params[':date_from'] = $dateFrom;
}

if ($dateColumn !== null && $dateTo !== '') {
    $where[] = "DATE(`{$dateColumn}`) <= :date_to";
    $params[':date_to'] = $dateTo;
}

if ($keyword !== '') {
    $searchColumns = [];
    $keywordLike = '%' . $keyword . '%';

    $keywordIsNumberOnly = preg_match('/^\d+$/', $keyword) === 1;
    $keywordIsShortBranchCode = $keywordIsNumberOnly && strlen($keyword) <= 3;

    if ($keywordIsShortBranchCode) {
        $normalizedBranchKeyword = str_pad($keyword, 3, '0', STR_PAD_LEFT);

        if ($has('main_branch_code')) {
            $searchColumns[] = "LPAD(`main_branch_code`, 3, '0') = :keyword_main_branch_exact";
            $params[':keyword_main_branch_exact'] = $normalizedBranchKeyword;
        }

        if ($has('branch_code')) {
            $searchColumns[] = "LPAD(`branch_code`, 3, '0') = :keyword_branch_code_exact";
            $params[':keyword_branch_code_exact'] = $normalizedBranchKeyword;
        }

        if ($has('request_id') && $hasRequestTable && $hasRequestColumn('id')) {
            $requestSearchParts = [];

            if ($hasRequestColumn('main_branch_code')) {
                $requestSearchParts[] = "LPAD(r.`main_branch_code`, 3, '0') = :keyword_request_main_branch_exact";
                $params[':keyword_request_main_branch_exact'] = $normalizedBranchKeyword;
            }

            if ($hasRequestColumn('branch_code')) {
                $requestSearchParts[] = "LPAD(r.`branch_code`, 3, '0') = :keyword_request_branch_code_exact";
                $params[':keyword_request_branch_code_exact'] = $normalizedBranchKeyword;
            }

            if (!empty($requestSearchParts)) {
                $searchColumns[] = "EXISTS (
                    SELECT 1
                    FROM `harddisk_delivery_requests` r
                    WHERE r.`id` = `harddisk_shipments`.`request_id`
                      AND (" . implode(' OR ', $requestSearchParts) . ")
                )";
            }
        }
    } else {
        foreach (['delivery_request_no', 'request_no', 'branch_name', 'hdd_serial'] as $index => $column) {
            if ($has($column)) {
                $paramName = ':keyword_text_' . $index;
                $searchColumns[] = "`{$column}` LIKE {$paramName}";
                $params[$paramName] = $keywordLike;
            }
        }

        if ($has('request_id') && $hasRequestTable && $hasRequestColumn('id')) {
            $requestSearchParts = [];

            foreach (['request_no', 'branch_name', 'hdd_serial'] as $index => $column) {
                if ($hasRequestColumn($column)) {
                    $paramName = ':keyword_request_text_' . $index;
                    $requestSearchParts[] = "r.`{$column}` LIKE {$paramName}";
                    $params[$paramName] = $keywordLike;
                }
            }

            if (!empty($requestSearchParts)) {
                $searchColumns[] = "EXISTS (
                    SELECT 1
                    FROM `harddisk_delivery_requests` r
                    WHERE r.`id` = `harddisk_shipments`.`request_id`
                      AND (" . implode(' OR ', $requestSearchParts) . ")
                )";
            }
        }
    }

    if (!empty($searchColumns)) {
        $where[] = '(' . implode(' OR ', $searchColumns) . ')';
    }
}

$statusColumn = null;
if ($has('status')) {
    $statusColumn = 'status';
} elseif ($has('shipment_status')) {
    $statusColumn = 'shipment_status';
}

if ($statusFilter !== '' && $statusColumn !== null) {
    $where[] = "`{$statusColumn}` = :status";
    $params[':status'] = $statusFilter;
}

$whereSql = !empty($where) ? 'WHERE ' . implode(' AND ', $where) : '';

/*
|--------------------------------------------------------------------------
| Report Export: CSV / Excel / PDF print view
|--------------------------------------------------------------------------
| ใช้เงื่อนไขค้นหาเดียวกับหน้าจอ และส่งออกข้อมูลทั้งหมดตามตัวกรอง
| โดยไม่จำกัดเฉพาะข้อมูลในหน้าปัจจุบัน
*/
$exportType = strtolower(trim((string)($_GET['export'] ?? '')));
if (in_array($exportType, ['csv', 'excel', 'pdf'], true)) {
    require_login();
    require_permission('shipment.view');

    if ($dateColumn !== null) {
        $exportOrderSql = "ORDER BY `{$dateColumn}` DESC, `id` DESC";
    } else {
        $exportOrderSql = 'ORDER BY `id` DESC';
    }

    $exportSql = "
        SELECT
            " . implode(",\n            ", $selectFields) . "
        FROM `harddisk_shipments`
        {$whereSql}
        {$exportOrderSql}
    ";
    $stmtExport = $pdo->prepare($exportSql);
    bindValues($stmtExport, $params);
    $stmtExport->execute();
    $exportRows = $stmtExport->fetchAll(PDO::FETCH_ASSOC);

    $reportRows = [];
    foreach ($exportRows as $index => $row) {
        $displayDate = $row['display_shipped_at'] ?? $row['shipped_date'] ?? null;
        // ใช้ชื่อจากคำขอเป็นหลัก พร้อมแก้ mojibake และ fallback ไปตาราง users เมื่อพบข้อมูลชื่อเสีย
        $reporter = shipmentResolveReporter($pdo, $row);

        $reportRows[] = [
            'ลำดับ' => $index + 1,
            'เลขที่คำขอ' => trim((string)($row['delivery_request_no'] ?? '')) ?: '-',
            'รหัสสาขาใหญ่' => ($mainCode = trim((string)($row['main_branch_code'] ?? ''))) !== '' ? formatMainBranchCode($mainCode) : '-',
            'Cost Center' => trim((string)($row['branch_code'] ?? '')) ?: '-',
            'ชื่อสาขา' => normalizeThaiText($row['branch_name'] ?? '') ?: '-',
            'Serial HDD' => trim((string)($row['hdd_serial'] ?? '')) ?: '-',
            'สถานะ' => shipmentStatusText((string)($row['display_status'] ?? '')),
            'วันที่ส่ง' => formatDateThai($displayDate),
            'ผู้แจ้ง/ผู้บันทึก' => $reporter !== '' ? $reporter : '-',
            'หมายเหตุ' => normalizeThaiText($row['remark'] ?? '') ?: '-',
        ];
    }

    $fileDate = date('Ymd_His');
    $fileBase = 'shipment_history_' . $fileDate;
    $headers = ['ลำดับ', 'เลขที่คำขอ', 'รหัสสาขาใหญ่', 'Cost Center', 'ชื่อสาขา', 'Serial HDD', 'สถานะ', 'วันที่ส่ง', 'ผู้แจ้ง/ผู้บันทึก', 'หมายเหตุ'];

    if ($exportType === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.csv"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        $output = fopen('php://output', 'wb');
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, $headers);
        foreach ($reportRows as $reportRow) fputcsv($output, array_values($reportRow));
        fclose($output);
        exit;
    }

    if ($exportType === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $fileBase . '.xls"');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        echo "\xEF\xBB\xBF";
        ?><!doctype html><html lang="th"><head><meta charset="utf-8"><style>
        body{font-family:Tahoma,Arial,sans-serif;font-size:11pt}table{border-collapse:collapse;width:100%}th,td{border:1px solid #777;padding:5px;vertical-align:top}th{background:#dbeafe;color:#0f172a;font-weight:bold}.text{mso-number-format:"\@"}.center{text-align:center}
        </style></head><body>
        <h2>รายงานประวัติการจัดส่ง Harddisk</h2>
        <div>วันที่ออกรายงาน: <?= h(date('d/m/Y H:i')) ?> | จำนวน <?= number_format(count($reportRows)) ?> รายการ</div><br>
        <table><thead><tr><?php foreach ($headers as $column): ?><th><?= h($column) ?></th><?php endforeach; ?></tr></thead><tbody>
        <?php foreach ($reportRows as $reportRow): ?><tr><?php foreach ($reportRow as $key => $value): ?><td class="<?= in_array($key, ['เลขที่คำขอ','รหัสสาขาใหญ่','Cost Center','Serial HDD'], true) ? 'text' : '' ?>"><?= h((string)$value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
        </tbody></table></body></html><?php
        exit;
    }

    // PDF: เปิดหน้ารายงานสำหรับสั่งพิมพ์/บันทึกเป็น PDF ผ่าน Browser
    ?><!doctype html>
    <html lang="th"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
    <title>รายงานประวัติการจัดส่ง Harddisk</title>
    <style>
    @page{size:A4 landscape;margin:9mm}*{box-sizing:border-box}body{font-family:"Noto Sans Thai",Tahoma,Arial,sans-serif;color:#111827;margin:0;font-size:10px}.report-toolbar{display:flex;justify-content:space-between;align-items:center;gap:8px;padding:10px 12px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:8px;margin-bottom:10px}.report-toolbar button,.report-toolbar a{border:0;border-radius:6px;padding:7px 12px;text-decoration:none;font-weight:700;cursor:pointer}.print-btn{background:#2563eb;color:#fff}.back-btn{background:#e5e7eb;color:#111827}h1{font-size:18px;margin:0 0 3px}.meta{color:#475569;margin-bottom:8px}table{width:100%;border-collapse:collapse;table-layout:fixed}th,td{border:1px solid #94a3b8;padding:4px 5px;vertical-align:top;overflow-wrap:anywhere}th{background:#dbeafe;font-weight:800;text-align:left}th:nth-child(1),td:nth-child(1){width:4%;text-align:center}th:nth-child(2),td:nth-child(2){width:10%}th:nth-child(3),td:nth-child(3){width:7%}th:nth-child(4),td:nth-child(4){width:8%}th:nth-child(5),td:nth-child(5){width:17%}th:nth-child(6),td:nth-child(6){width:11%}th:nth-child(7),td:nth-child(7){width:8%}th:nth-child(8),td:nth-child(8){width:8%}th:nth-child(9),td:nth-child(9){width:12%}th:nth-child(10),td:nth-child(10){width:15%}@media print{.report-toolbar{display:none}body{font-size:8.5px}thead{display:table-header-group}tr{page-break-inside:avoid}}
    </style></head><body>
    <div class="report-toolbar"><div><strong>ตัวอย่างรายงาน PDF</strong><div>กด “พิมพ์ / บันทึก PDF” แล้วเลือก Save as PDF</div></div><div><a class="back-btn" href="index.php">กลับ</a> <button class="print-btn" type="button" onclick="window.print()">พิมพ์ / บันทึก PDF</button></div></div>
    <h1>รายงานประวัติการจัดส่ง Harddisk</h1>
    <div class="meta">วันที่ออกรายงาน: <?= h(date('d/m/Y H:i')) ?> | จำนวน <?= number_format(count($reportRows)) ?> รายการ</div>
    <table><thead><tr><?php foreach ($headers as $column): ?><th><?= h($column) ?></th><?php endforeach; ?></tr></thead><tbody>
    <?php if (!$reportRows): ?><tr><td colspan="10" style="text-align:center;padding:20px">ไม่พบข้อมูลตามเงื่อนไขที่เลือก</td></tr><?php endif; ?>
    <?php foreach ($reportRows as $reportRow): ?><tr><?php foreach ($reportRow as $value): ?><td><?= h((string)$value) ?></td><?php endforeach; ?></tr><?php endforeach; ?>
    </tbody></table>
    <script>window.addEventListener('load',function(){setTimeout(function(){window.print();},300);});</script>
    </body></html><?php
    exit;
}

if ($dateColumn !== null) {
    $orderSql = "ORDER BY `{$dateColumn}` DESC, `id` DESC";
} else {
    $orderSql = 'ORDER BY `id` DESC';
}

try {
    $countSql = "
        SELECT COUNT(*) AS total
        FROM `harddisk_shipments`
        {$whereSql}
    ";
    $stmtCount = $pdo->prepare($countSql);
    bindValues($stmtCount, $params);
    $stmtCount->execute();

    $totalRows = (int)$stmtCount->fetchColumn();
    $totalPages = max((int)ceil($totalRows / $perPage), 1);

    if ($page > $totalPages) {
        $page = $totalPages;
        $offset = ($page - 1) * $perPage;
    }

    $dataSql = "
        SELECT
            " . implode(",\n            ", $selectFields) . "
        FROM `harddisk_shipments`
        {$whereSql}
        {$orderSql}
        LIMIT :limit OFFSET :offset
    ";

    $stmtData = $pdo->prepare($dataSql);
    bindValues($stmtData, $params);
    $stmtData->bindValue(':limit', $perPage, PDO::PARAM_INT);
    $stmtData->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmtData->execute();
    $shipments = $stmtData->fetchAll(PDO::FETCH_ASSOC);

    $summarySql = "
        SELECT display_status, COUNT(*) AS total
        FROM (
            SELECT
                " . ($has('status') ? '`status`' : ($has('shipment_status') ? '`shipment_status`' : "'shipped'")) . " AS display_status
            FROM `harddisk_shipments`
            {$whereSql}
        ) x
        GROUP BY display_status
        ORDER BY total DESC
    ";
    $stmtSummary = $pdo->prepare($summarySql);
    bindValues($stmtSummary, $params);
    $stmtSummary->execute();
    $summaryRows = $stmtSummary->fetchAll(PDO::FETCH_ASSOC);

    $thisMonthWhere = [];
    $thisMonthParams = [];
    if ($has('deleted_at')) {
        $thisMonthWhere[] = '`deleted_at` IS NULL';
    }
    if ($dateColumn !== null) {
        $thisMonthWhere[] = "DATE_FORMAT(`{$dateColumn}`, '%Y-%m') = DATE_FORMAT(CURDATE(), '%Y-%m')";
    }
    $thisMonthSqlWhere = !empty($thisMonthWhere) ? 'WHERE ' . implode(' AND ', $thisMonthWhere) : '';
    $stmtThisMonth = $pdo->prepare("SELECT COUNT(*) FROM `harddisk_shipments` {$thisMonthSqlWhere}");
    bindValues($stmtThisMonth, $thisMonthParams);
    $stmtThisMonth->execute();
    $thisMonthTotal = (int)$stmtThisMonth->fetchColumn();
} catch (Throwable $e) {
    require_once $basePath . '/includes/header.php';
    ?>
    <div class="container-fluid py-4">
        <div class="alert alert-danger">
            <strong>เกิดข้อผิดพลาดในการดึงข้อมูลประวัติการจัดส่ง</strong><br>
            รายละเอียด: <?= h($e->getMessage()) ?>
        </div>
    </div>
    <?php
    require_once $basePath . '/includes/footer.php';
    exit;
}

$statusOptions = [];
if ($statusColumn !== null) {
    try {
        $statusSql = "
            SELECT DISTINCT `{$statusColumn}` AS status_name
            FROM `harddisk_shipments`
            WHERE `{$statusColumn}` IS NOT NULL
              AND `{$statusColumn}` <> ''
            ORDER BY `{$statusColumn}` ASC
        ";
        $stmtStatus = $pdo->query($statusSql);
        $statusOptions = $stmtStatus->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        $statusOptions = [];
    }
}


$summaryMap = [];
foreach ($summaryRows as $row) {
    $statusKey = strtolower(trim((string)($row['display_status'] ?? '')));
    if ($statusKey === '') {
        $statusKey = 'unknown';
    }
    if (!isset($summaryMap[$statusKey])) {
        $summaryMap[$statusKey] = 0;
    }
    $summaryMap[$statusKey] += (int)($row['total'] ?? 0);
}

$summaryShipped = 0;
foreach (['sent', 'shipped'] as $statusKey) {
    $summaryShipped += (int)($summaryMap[$statusKey] ?? 0);
}

$summaryCompleted = 0;
foreach (['received', 'installed', 'completed'] as $statusKey) {
    $summaryCompleted += (int)($summaryMap[$statusKey] ?? 0);
}

$summaryPending = (int)($summaryMap['pending'] ?? 0);

$exportParams = $_GET;
unset($exportParams['page']);
$csvParams = array_merge($exportParams, ['export' => 'csv']);
$excelParams = array_merge($exportParams, ['export' => 'excel']);
$pdfParams = array_merge($exportParams, ['export' => 'pdf']);
$csvExportUrl = 'index.php?' . http_build_query($csvParams);
$excelExportUrl = 'index.php?' . http_build_query($excelParams);
$pdfExportUrl = 'index.php?' . http_build_query($pdfParams);

require_once $basePath . '/includes/header.php';

require_login();
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
    require_permission('shipment.manage');
} else {
    require_permission('shipment.view');
}

?>

<style>
    body { background: #f3f6fb; }
    .shipment-page { padding: 10px 0 16px 0; }
    .shipment-title { font-size: 22px; font-weight: 900; color: #0f172a; margin: 0; line-height: 1.15; }
    .shipment-subtitle { font-size: 13px; color: #64748b; }
    .shipment-card { border: 0; border-radius: 16px; box-shadow: 0 8px 24px rgba(15, 23, 42, 0.07); overflow: hidden; }
    .shipment-card .card-header { background: #ffffff; border-bottom: 1px solid #e5e7eb; font-weight: 900; color: #0f172a; padding: 10px 14px; }
    .shipment-card .card-body { padding: 12px; }
    .hero-card { border: 0; border-radius: 16px; background: linear-gradient(135deg, #0ea5e9, #2563eb); color: #ffffff; box-shadow: 0 10px 24px rgba(37, 99, 235, 0.22); }
    .hero-card .card-body { padding: 12px 16px; }
    .kpi-card { border: 0; border-radius: 15px; box-shadow: 0 6px 18px rgba(15, 23, 42, 0.07); height: 100%; }
    .kpi-card .card-body { padding: 12px 14px; }
    .kpi-label { font-size: 12px; color: #64748b; margin-bottom: 4px; }
    .kpi-value { font-size: 28px; font-weight: 900; color: #0f172a; line-height: 1; }
    .kpi-note { font-size: 11px; color: #64748b; margin-top: 5px; }
    .form-label { font-size: 12px; color: #475569; font-weight: 800; margin-bottom: 4px; }
    .form-control, .form-select, .btn { font-size: 13px; border-radius: 10px; }
    .filter-pill { display: inline-flex; align-items: center; gap: 6px; border-radius: 999px; padding: 5px 10px; background: #e0f2fe; color: #075985; font-size: 12px; font-weight: 800; }
    .table-scroll { max-height: calc(100vh - 365px); min-height: 330px; overflow: auto; }
    .table-shipment th { position: sticky; top: 0; z-index: 2; background: #f8fafc; font-size: 12px; white-space: nowrap; padding: 7px 8px; color: #334155; }
    .table-shipment td { font-size: 12px; vertical-align: middle; padding: 7px 8px; }
    .serial-text { font-family: Consolas, Monaco, monospace; font-weight: 900; color: #7c2d12; white-space: nowrap; }
    .branch-code { font-weight: 900; color: #1d4ed8; white-space: nowrap; }
    .status-cell { width: 120px; min-width: 120px; max-width: 120px; text-align: center; padding-left: 6px !important; padding-right: 6px !important; }
    .shipment-status-badge {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 78px;
        max-width: 100%;
        min-height: 24px;
        padding: 3px 6px;
        border-radius: 999px;
        font-size: 11px;
        font-weight: 900;
        line-height: 1;
        white-space: nowrap;
        box-shadow: inset 0 0 0 1px rgba(255,255,255,.42), 0 1px 3px rgba(15,23,42,.16);
    }
    .shipment-status-shipped { background: #1d4ed8; color: #ffffff; }
    .shipment-status-success { background: #15803d; color: #ffffff; }
    .shipment-status-pending { background: #f59e0b; color: #111827; }
    .shipment-status-danger { background: #dc2626; color: #ffffff; }
    .shipment-status-secondary { background: #475569; color: #ffffff; }
    .text-ellipsis { max-width: 260px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .remark-cell { max-width: 240px; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
    .status-mini-row { display: flex; justify-content: space-between; gap: 8px; border-bottom: 1px solid #f1f5f9; padding: 6px 0; font-size: 12px; }
    .status-mini-row:last-child { border-bottom: 0; }
    .pagination .page-link { font-size: 12px; padding: 4px 9px; }
    @media (max-width: 1366px) {
        .shipment-page { padding-top: 8px; }
        .shipment-title { font-size: 20px; }
        .shipment-card .card-body { padding: 10px; }
        .kpi-card .card-body { padding: 10px 12px; }
        .kpi-value { font-size: 25px; }
        .table-scroll { max-height: calc(100vh - 345px); min-height: 305px; }
        .table-shipment th, .table-shipment td { font-size: 11.5px; padding: 6px 7px; }
        .status-cell { width: 120px; min-width: 120px; max-width: 120px; padding-left: 5px !important; padding-right: 5px !important; }
        .shipment-status-badge { width: 76px; min-height: 23px; font-size: 10.5px; padding: 3px 5px; }
        .form-control, .form-select { font-size: 12px; }
    }
    @media print {
        .no-print, .pagination, .btn { display: none !important; }
        body { background: #ffffff; }
        .table-scroll { max-height: none; overflow: visible; }
        .shipment-card, .kpi-card, .hero-card { box-shadow: none; border: 1px solid #e5e7eb; }
    }

    /* Unified page design based on IT system registry */
    .unified-hero{background:linear-gradient(135deg,#0b3c68,#1769aa);border-radius:18px;padding:18px 20px;color:#fff;box-shadow:0 12px 30px rgba(15,76,129,.18);margin-bottom:14px}
    .unified-hero h1{font-size:1.35rem;font-weight:800;margin:0 0 4px;line-height:1.2;color:#fff}
    .unified-hero p{font-size:.86rem;margin:0;opacity:.88}
    .unified-hero-actions{display:flex;align-items:center;justify-content:flex-end;gap:8px;flex-wrap:wrap}
    .unified-total{display:inline-flex;align-items:center;gap:6px;background:rgba(255,255,255,.15);border:1px solid rgba(255,255,255,.26);padding:.45rem .75rem;border-radius:999px;font-size:.78rem;white-space:nowrap}
    .shipment-export-dropdown .btn{background:#fff;color:#0f4c81;border-color:#fff;box-shadow:0 5px 14px rgba(15,23,42,.14)}
    .shipment-export-dropdown .dropdown-menu{min-width:210px;padding:6px;border:1px solid #dbe5ee;border-radius:12px;box-shadow:0 14px 36px rgba(15,23,42,.18)}
    .shipment-export-dropdown .dropdown-item{display:flex;align-items:center;gap:9px;border-radius:8px;padding:8px 10px;font-size:.76rem;font-weight:800;color:#17324d}
    .shipment-export-dropdown .dropdown-item:hover{background:#eff6ff;color:#0f4c81}
    .shipment-export-format{width:34px;height:24px;border-radius:6px;display:inline-flex;align-items:center;justify-content:center;font-size:.6rem;font-weight:900;color:#fff}
    .shipment-export-format.excel{background:#15803d}.shipment-export-format.pdf{background:#dc2626}.shipment-export-format.csv{background:#0f766e}
    .unified-hero .btn{border-radius:10px;font-size:.78rem;font-weight:800;white-space:nowrap;padding:.48rem .72rem}
    .unified-search-card{background:#fff;border:0;border-radius:16px;box-shadow:0 5px 18px rgba(20,46,70,.07);overflow:hidden}
    .unified-search-card .card-header{background:#fff;border-bottom:1px solid #e5ebf0;padding:11px 14px;font-weight:800;color:#17324d}
    .unified-search-card .card-body{padding:13px 14px}
    .unified-search-card .step-box{background:transparent;border:0;padding:0;border-radius:0}
    .unified-search-card .step-title{display:none}
    .unified-search-card .form-label{font-size:.72rem;font-weight:800;color:#5f6f7e;margin-bottom:4px}
    .unified-search-card .form-control,.unified-search-card .form-select{min-height:38px;font-size:.76rem;border-radius:10px}
    .unified-search-card .btn{min-height:38px;border-radius:10px;font-size:.75rem;font-weight:800}
    .unified-action-modal .modal-content{border:0;border-radius:16px;overflow:hidden;box-shadow:0 18px 55px rgba(15,23,42,.24)}
    .unified-action-modal .modal-header{background:linear-gradient(135deg,#eff6ff,#fff);padding:12px 16px}
    .unified-action-modal .modal-body{padding:16px;background:#f8fafc}
    .unified-edit-frame{width:100%;height:min(72vh,680px);border:0;border-radius:10px;background:#fff}
    @media(max-width:1366px){.unified-hero{padding:15px 17px}.unified-hero h1{font-size:1.15rem}.unified-hero p{font-size:.75rem}.unified-search-card .card-body{padding:10px 12px}.unified-search-card .form-control,.unified-search-card .form-select,.unified-search-card .btn{min-height:34px;font-size:.7rem}}
    @media(max-width:767.98px){.unified-hero{padding:14px}.unified-hero-actions{width:100%;justify-content:flex-start}.unified-hero .btn{flex:1 1 auto}.unified-edit-frame{height:78vh}}

    .shipment-filter-row{
        display:grid;
        grid-template-columns:minmax(260px,1fr) repeat(3,minmax(125px,150px)) minmax(72px,90px) minmax(86px,100px) minmax(86px,100px);
        gap:8px;
        align-items:end;
        width:100%;
        max-width:100%;
    }
    .shipment-filter-row>div{min-width:0;max-width:100%}
    .shipment-filter-row .form-control,
    .shipment-filter-row .form-select,
    .shipment-filter-row .btn{width:100%;max-width:100%;height:38px;min-height:38px}
    .shipment-filter-action .form-label{display:block}

    /* Notebook layout: keep all search controls in one row */
    @media(max-width:1366px){
        .shipment-filter-row{
            grid-template-columns:minmax(210px,1.55fr) minmax(112px,.78fr) minmax(112px,.78fr) minmax(105px,.72fr) minmax(64px,.42fr) minmax(74px,.5fr) minmax(74px,.5fr);
            gap:5px;
        }
        .shipment-filter-keyword{grid-column:auto}
        .shipment-filter-row .form-control,
        .shipment-filter-row .form-select,
        .shipment-filter-row .btn{height:34px;min-height:34px;font-size:.68rem;padding-left:.42rem;padding-right:.42rem}
        .shipment-filter-row .form-label{font-size:.64rem;margin-bottom:3px;white-space:nowrap}
        .shipment-filter-action .form-label{display:block}
    }
    @media(max-width:1100px){
        .shipment-filter-row{
            grid-template-columns:minmax(180px,1.45fr) minmax(105px,.78fr) minmax(105px,.78fr) minmax(96px,.68fr) minmax(58px,.4fr) minmax(70px,.48fr) minmax(70px,.48fr);
            gap:4px;
        }
        .shipment-filter-row .form-control,
        .shipment-filter-row .form-select,
        .shipment-filter-row .btn{font-size:.64rem;padding-left:.32rem;padding-right:.32rem}
        .shipment-filter-row .form-label{font-size:.6rem}
    }
    @media(max-width:767.98px){
        .shipment-filter-row{
            grid-template-columns:minmax(170px,1.35fr) minmax(100px,.75fr) minmax(100px,.75fr) minmax(90px,.65fr) minmax(54px,.38fr) minmax(68px,.46fr) minmax(68px,.46fr);
            min-width:760px;
        }
        .unified-search-card .card-body{overflow-x:auto}
    }

    /* Compact shipment table: fit desktop width and use text-only status */
    .table-shipment{
        width:100%;
        min-width:0;
        table-layout:fixed;
    }
    .table-scroll{
        overflow-x:hidden;
    }
    .table-shipment th,.table-shipment td{
        overflow:hidden;
        text-overflow:ellipsis;
    }
    .table-shipment th:nth-child(1),.table-shipment td:nth-child(1){width:4%;text-align:center}
    .table-shipment th:nth-child(2),.table-shipment td:nth-child(2){width:11%}
    .table-shipment th:nth-child(3),.table-shipment td:nth-child(3){width:7%}
    .table-shipment th:nth-child(4),.table-shipment td:nth-child(4){width:9%}
    .table-shipment th:nth-child(5),.table-shipment td:nth-child(5){width:18%}
    .table-shipment th:nth-child(6),.table-shipment td:nth-child(6){width:11%}
    .table-shipment th:nth-child(7),.table-shipment td:nth-child(7){width:8%;text-align:center}
    .table-shipment th:nth-child(8),.table-shipment td:nth-child(8){width:8%}
    .table-shipment th:nth-child(9),.table-shipment td:nth-child(9){width:11%}
    .table-shipment th:nth-child(10),.table-shipment td:nth-child(10){width:13%}
    .status-cell{
        width:auto!important;
        min-width:0!important;
        max-width:none!important;
    }
    .shipment-status-badge{
        display:inline;
        width:auto;
        max-width:none;
        min-height:0;
        padding:0;
        border:0;
        border-radius:0;
        background:transparent!important;
        box-shadow:none;
        font-size:11px;
        font-weight:900;
        line-height:1.2;
        white-space:nowrap;
    }
    .shipment-status-shipped{color:#2563eb!important}
    .shipment-status-success{color:#15803d!important}
    .shipment-status-pending{color:#d97706!important}
    .shipment-status-danger{color:#dc2626!important}
    .shipment-status-secondary{color:#475569!important}
    .table-shipment .text-ellipsis,.table-shipment .remark-cell{
        max-width:100%;
    }
    @media(max-width:1366px){
        .table-shipment th,.table-shipment td{font-size:10.8px;padding:5px 5px}
        .shipment-status-badge{font-size:10.5px}
    }
    @media(max-width:767.98px){
        .table-scroll{overflow-x:auto}
        .table-shipment{min-width:980px;table-layout:fixed}
    }

    .shipment-request-link{
        display:inline-flex;align-items:center;gap:4px;
        color:#0f4c81;text-decoration:none;font-weight:900;
        border:0;border-bottom:1px dashed rgba(15,76,129,.45);
        background:transparent;padding:0;cursor:pointer;white-space:nowrap;
    }
    .shipment-request-link:hover{color:#1769aa;border-bottom-color:#1769aa}
    .shipment-request-link:focus{outline:2px solid rgba(23,105,170,.18);outline-offset:2px;border-radius:3px}
    .shipment-detail-modal .modal-dialog{max-width:680px}
    .shipment-detail-modal .modal-content{border:0;border-radius:14px;overflow:hidden;box-shadow:0 22px 60px rgba(15,23,42,.22)}
    .shipment-detail-modal .modal-header{padding:.58rem .82rem;background:linear-gradient(135deg,#eff6ff,#fff);border-bottom:1px solid #dbe5ee}
    .shipment-detail-modal .modal-title{font-size:1rem}
    .shipment-detail-modal .modal-body{padding:.5rem;background:#f8fafc}
    .shipment-detail-modal .modal-footer{padding:.35rem .5rem;background:#fff;border-top:1px solid #e2e8f0}
    .shipment-detail-modal .modal-footer .btn{font-size:.75rem;padding:.27rem .7rem}
    .shipment-detail-table-wrap{border:1px solid #dbe5ee;border-radius:10px;overflow:hidden;background:#fff}
    .shipment-detail-table{width:100%;margin:0;table-layout:fixed}
    .shipment-detail-table th,.shipment-detail-table td{padding:.32rem .46rem;border-color:#dbe5ee;vertical-align:middle;font-size:.78rem;line-height:1.18;overflow-wrap:anywhere;word-break:break-word}
    .shipment-detail-table th{width:31%;background:#f1f5f9;color:#475569;font-weight:800;white-space:nowrap}
    .shipment-detail-table td{background:#fff;color:#0f172a;font-weight:700;white-space:pre-wrap}
    .shipment-detail-table tr:nth-child(even) td{background:#f8fafc}
    .shipment-detail-table .primary{color:#0f4c81;font-weight:900}
    @media(max-width:767.98px){.shipment-detail-modal .modal-dialog{margin:.5rem}.shipment-detail-table{table-layout:auto}.shipment-detail-table th{width:38%;white-space:normal}.shipment-detail-table th,.shipment-detail-table td{font-size:.74rem;padding:.3rem .4rem}}
    .shipment-report-table{font-size:.78rem}.shipment-report-table th{width:190px;background:#f1f5f9;color:#475569;font-weight:800;white-space:nowrap}.shipment-report-table th,.shipment-report-table td{padding:.5rem .65rem}.shipment-report-table .form-control,.shipment-report-table .form-select{min-height:36px;font-size:.76rem}
    @media(max-width:767.98px){.shipment-report-table th{width:135px;white-space:normal}.shipment-report-table th,.shipment-report-table td{padding:.42rem .5rem}}


    /* Shared Harddisk Delivery module navigation */
    .hdd-module-menu {
        display:grid;
        grid-template-columns:repeat(6,minmax(0,1fr));
        gap:9px;
        margin:0 0 14px;
    }
    .hdd-module-menu-item {
        position:relative; min-width:0; min-height:78px; display:flex;
        align-items:center; gap:10px; padding:11px 12px;
        border:1px solid #dbe5ee; border-radius:14px; background:#fff;
        color:#334155; text-decoration:none;
        box-shadow:0 5px 16px rgba(15,23,42,.055);
        transition:transform .16s ease,border-color .16s ease,box-shadow .16s ease,background .16s ease;
        overflow:hidden;
    }
    .hdd-module-menu-item:hover { color:#0f4c81; text-decoration:none; border-color:#93c5fd; box-shadow:0 9px 22px rgba(37,99,235,.12); transform:translateY(-1px); }
    .hdd-module-menu-item.active { color:#fff; border-color:#00acc1; background:linear-gradient(135deg,#0097a7 0%,#00bcd4 58%,#26c6da 100%); box-shadow:0 10px 24px rgba(0,188,212,.28); }
    .hdd-module-menu-icon { width:38px; height:38px; flex:0 0 38px; display:flex; align-items:center; justify-content:center; border-radius:11px; background:#e0f7fa; color:#00acc1; }
    .hdd-module-menu-item.active .hdd-module-menu-icon { background:rgba(255,255,255,.20); color:#fff; }
    .hdd-module-menu-icon svg { width:20px; height:20px; }
    .hdd-module-menu-item.active .hdd-module-menu-icon { background:rgba(255,255,255,.16); color:#fff; }
    .hdd-module-menu-content { min-width:0; }
    .hdd-module-menu-title { display:block; font-size:.76rem; line-height:1.25; font-weight:900; white-space:normal; }
    .hdd-module-menu-note { display:block; margin-top:3px; font-size:.63rem; line-height:1.2; color:#64748b; white-space:nowrap; overflow:hidden; text-overflow:ellipsis; }
    .hdd-module-menu-item.active .hdd-module-menu-note { color:rgba(255,255,255,.8); }
    .hdd-module-menu-count { position:absolute; top:7px; right:7px; min-width:22px; height:22px; padding:0 6px; border-radius:999px; display:flex; align-items:center; justify-content:center; background:#fee2e2; color:#b91c1c; border:1px solid #fecaca; font-size:.63rem; font-weight:900; line-height:1; }
    .hdd-module-menu-item.active .hdd-module-menu-count { background:#fff; color:#00838f; border-color:rgba(255,255,255,.6); }
    @media(max-width:1366px){
        .hdd-module-menu{gap:7px}
        .hdd-module-menu-item{min-height:70px;padding:9px 8px;gap:7px}
        .hdd-module-menu-icon{width:32px;height:32px;flex-basis:32px;border-radius:9px}
        .hdd-module-menu-icon svg{width:17px;height:17px}
        .hdd-module-menu-title{font-size:.68rem}
        .hdd-module-menu-note{font-size:.57rem}
    }
    @media(max-width:1100px){.hdd-module-menu{grid-template-columns:repeat(3,minmax(0,1fr))}}
    @media(max-width:700px){.hdd-module-menu{grid-template-columns:repeat(2,minmax(0,1fr))}}
    @media(max-width:420px){.hdd-module-menu{grid-template-columns:1fr}}


    /* Blink only the active menu of the current page */
    .hdd-module-menu-item.active.hdd-active-menu-blink {
        /* animation: hddActiveMenuBlink 1.4s ease-out infinite; */
        transform-origin: center;
        will-change: transform, box-shadow, filter;
    }
    @keyframes hddActiveMenuBlink {
        0%, 100% {
            transform: scale(1);
            box-shadow: 0 10px 24px rgba(0,188,212,.28);
            filter: brightness(1);
        }
        50% {
            transform: scale(1.025);
            box-shadow: 0 0 0 4px rgba(0,188,212,.22), 0 14px 30px rgba(0,151,167,.38);
            filter: brightness(1.18);
        }
    }
    @media (prefers-reduced-motion: reduce) {
        .hdd-module-menu-item.active.hdd-active-menu-blink {
            animation: none;
        }
    }
</style>

<div class="container-fluid shipment-page">
    <nav class="hdd-module-menu" aria-label="เมนูระบบจัดส่งฮาร์ดดิส">
            <?php if (function_exists('can') && can('request.create')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/requests/create.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('request'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">บันทึกคำขอส่ง Harddisk</span><span class="hdd-module-menu-note">สร้างคำขอและยิงบาร์โค้ด</span></span>
                <?php if ($pendingScanCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($pendingScanCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('shipment.manage')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/requests/matched.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('confirm'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">รอยืนยันจัดส่ง</span><span class="hdd-module-menu-note">ตรวจสอบและยืนยันการส่ง</span></span>
                <?php if ($pendingShipmentConfirmCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($pendingShipmentConfirmCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('request.view')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/requests/index.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('list'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">รายการเบิก</span><span class="hdd-module-menu-note">ติดตามคำขอทั้งหมด</span></span>
                <?php if ($myHddRequestCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($myHddRequestCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('shipment.view')): ?>
            <a class="hdd-module-menu-item active hdd-active-menu-blink" href="<?php echo $baseUrl; ?>/modules/shipments/index.php" aria-current="page">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('history'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">ประวัติการจัดส่ง</span><span class="hdd-module-menu-note">ดูรายการจัดส่งย้อนหลัง</span></span>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('inventory.view')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/inventory/index.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('warehouse'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">คลัง Harddisk</span><span class="hdd-module-menu-note">ตรวจสอบสต็อกพร้อมใช้งาน</span></span>
                <?php if ($availableInventoryCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($availableInventoryCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
            <?php if (function_exists('can') && can('claim.view')): ?>
            <a class="hdd-module-menu-item" href="<?php echo $baseUrl; ?>/modules/claim_returns/index.php">
                <span class="hdd-module-menu-icon"><?php echo hddSidebarIcon('return'); ?></span>
                <span class="hdd-module-menu-content"><span class="hdd-module-menu-title">รับคืน / ส่งเคลม</span><span class="hdd-module-menu-note">จัดการคืนและเคลม HDD</span></span>
                <?php if ($claimReturnPendingCount > 0): ?><span class="hdd-module-menu-count"><?php echo number_format($claimReturnPendingCount); ?></span><?php endif; ?>
            </a>
            <?php endif; ?>
        </nav>


    <div class="unified-hero d-flex flex-column flex-lg-row justify-content-between align-items-lg-center gap-3">
        <div><h1>ประวัติการจัดส่ง Harddisk</h1>
        <!-- <p>ค้นหาและตรวจสอบรายการจัดส่ง HDD ให้สาขา พร้อมติดตามสถานะได้จากหน้าเดียว</p> -->
    </div>
        <div class="unified-hero-actions">
            <div class="unified-total">ข้อมูลทั้งหมด <strong><?= number_format($totalRows) ?> รายการ</strong></div>
            <div class="shipment-export-dropdown no-print">
                <button class="btn btn-light" type="button" data-bs-toggle="modal" data-bs-target="#shipmentReportModal">
                    <i class="bi bi-file-earmark-bar-graph me-1"></i>ออกรายงาน
                </button>
            </div>
            <!-- <a href="import.php" class="btn btn-success">⬆️ อัปโหลดไฟล์</a>
            <button type="button" onclick="window.print();" class="btn btn-outline-secondary">🖨️ พิมพ์</button>
            <a href="../dashboard/index.php" class="btn btn-outline-secondary">Dashboard</a> -->
        </div>
    </div>

    <?php if (isset($_GET['status_updated'])): ?>
        <div class="alert alert-success py-2 mb-2">แก้ไขสถานะและย้อน Workflow ที่เกี่ยวข้องเรียบร้อยแล้ว</div>
    <?php endif; ?>
    <?php if ($statusManageError !== ''): ?>
        <div class="alert alert-danger py-2 mb-2"><?= h($statusManageError) ?></div>
    <?php endif; ?>

    <div class="card hero-card mb-2 no-print d-none">
        <div class="card-body d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <div class="fw-bold"></div>
                <div class="small opacity-75">หน้านี้ใช้ติดตามรายการที่จัดส่ง HDD ไปยังสาขาแล้ว พร้อมค้นหาด้วยเลขที่คำขอ รหัสสาขา ชื่อสาขา และ Serial HDD</div>
            </div>
            <div class="small">ทั้งหมดตามตัวกรอง: <strong><?php echo number_format($totalRows); ?></strong> รายการ</div>
        </div>
    </div>

    <!-- <div class="row g-2 mb-2">
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">รายการตามตัวกรอง</div><div class="kpi-value"><?php echo number_format($totalRows); ?></div><div class="kpi-note">รายการที่พบจากเงื่อนไขค้นหา</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">จัดส่งเดือนนี้</div><div class="kpi-value"><?php echo number_format($thisMonthTotal); ?></div><div class="kpi-note">นับจากวันที่จัดส่ง</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">จัดส่งแล้ว</div><div class="kpi-value"><?php echo number_format($summaryShipped); ?></div><div class="kpi-note">สถานะ shipped / sent</div></div></div></div>
        <div class="col-lg-3 col-md-6"><div class="card kpi-card"><div class="card-body"><div class="kpi-label">ปลายทางรับแล้ว</div><div class="kpi-value"><?php echo number_format($summaryCompleted); ?></div><div class="kpi-note">received / installed / completed</div></div></div></div>
    </div> -->

    <div class="card shipment-card unified-search-card hdd-search-card mb-2 no-print">
        <!-- <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>ค้นหาและกรองประวัติการจัดส่ง</div>
            <?php if ($keyword !== '' || $statusFilter !== '' || $dateFrom !== '' || $dateTo !== ''): ?>
                <span class="filter-pill">🔎 กำลังกรองข้อมูล</span>
            <?php endif; ?>
        </div> -->
        <div class="card-body">
            <form method="get" class="shipment-filter-row hdd-unified-search-row hdd-fields-7">
                <div class="shipment-filter-keyword">
                    <label for="keyword" class="form-label">ช่องค้นหา</label>
                    <input
                        type="text"
                        name="keyword"
                        id="keyword"
                        class="form-control"
                        value="<?= h($keyword) ?>"
                        placeholder="เลขที่คำขอ, รหัสสาขา, ชื่อสาขา, Serial HDD"
                    >
                </div>

                <div>
                    <label for="date_from" class="form-label">วันที่เริ่มต้น</label>
                    <input type="date" name="date_from" id="date_from" class="form-control" value="<?= h($dateFrom) ?>">
                </div>

                <div>
                    <label for="date_to" class="form-label">วันที่สิ้นสุด</label>
                    <input type="date" name="date_to" id="date_to" class="form-control" value="<?= h($dateTo) ?>">
                </div>

                <div>
                    <label for="status" class="form-label">สถานะ</label>
                    <select name="status" id="status" class="form-select">
                        <option value="">ทั้งหมด</option>
                        <?php foreach ($statusOptions as $statusOption): ?>
                            <option value="<?= h($statusOption) ?>" <?= $statusFilter === $statusOption ? 'selected' : '' ?>>
                                <?= h(shipmentStatusText($statusOption)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div>
                    <label for="per_page" class="form-label">แสดง</label>
                    <select name="per_page" id="per_page" class="form-select">
                        <?php foreach ([10, 20, 50, 100] as $option): ?>
                            <option value="<?= (int)$option ?>" <?= $perPage === $option ? 'selected' : '' ?>>
                                <?= (int)$option ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="shipment-filter-action">
                    <label class="form-label">&nbsp;</label>
                    <button type="submit" class="btn btn-primary w-100">ค้นหา</button>
                </div>

                <div class="shipment-filter-action">
                    <label class="form-label">&nbsp;</label>
                    <a href="index.php" class="btn btn-outline-secondary w-100">ล้างค่า</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card shipment-card">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <div>
                <strong>รายการจัดส่งทั้งหมด</strong>
                <span class="text-muted small"><?= number_format($totalRows) ?> รายการ</span>
            </div>

            <div class="text-muted small">
                หน้า <?= number_format($page) ?> / <?= number_format($totalPages) ?>
            </div>
        </div>

        <div class="card-body p-0">
            <div class="table-responsive table-scroll">
                <table class="table table-hover table-bordered align-middle mb-0 table-shipment">
                    <thead>
                        <tr>
                            <th style="width:60px;" class="text-center">ลำดับ</th>
                            <th style="width:135px;">เลขที่คำขอ</th>
                            <th style="width:95px;">รหัสสาขา</th>
                            <th style="width:120px;">Cost Center</th>
                            <th>ชื่อสาขา</th>
                            <th style="width:145px;">Serial HDD</th>
                            <th style="width:120px;" class="status-cell">สถานะ</th>
                            <th style="width:105px;">วันที่ส่ง</th>
                            <th style="width:145px;">ผู้แจ้ง/ผู้บันทึก</th>
                            <th style="width:210px;">หมายเหตุ</th>
                            <?php if ($isSuperAdmin): ?><th style="width:90px;" class="text-center no-print">จัดการ</th><?php endif; ?>
                        </tr>
                    </thead>

                    <tbody>
                        <?php if (empty($shipments)): ?>
                            <tr>
                                <td colspan="<?= $isSuperAdmin ? 11 : 10 ?>" class="text-center text-muted py-4">
                                    ไม่พบข้อมูลประวัติการจัดส่ง
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($shipments as $index => $row): ?>
                                <?php
                                $runningNo = $offset + $index + 1;
                                $displayDate = $row['display_shipped_at'] ?? $row['shipped_date'] ?? null;
                                $requestNo = trim((string)($row['delivery_request_no'] ?? ''));
                                $mainBranchCode = trim((string)($row['main_branch_code'] ?? ''));
                                $branchCode = trim((string)($row['branch_code'] ?? ''));
                                $branchName = trim((string)($row['branch_name'] ?? ''));
                                $hddSerial = trim((string)($row['hdd_serial'] ?? ''));
                                $status = trim((string)($row['display_status'] ?? ''));
                                // ใช้ชื่อจากคำขอเป็นหลัก พร้อมแก้ mojibake และ fallback ไปตาราง users เมื่อพบข้อมูลชื่อเสีย
                                $reporter = shipmentResolveReporter($pdo, $row);

                                $remark = normalizeThaiText($row['remark'] ?? '');
                                $requestReason = normalizeThaiText($row['request_reason'] ?? '');
                                $problemNo = trim((string)($row['problem_no'] ?? ''));
                                $requestedAt = $row['request_requested_at'] ?? null;
                                ?>
                                <tr>
                                    <td class="text-center"><?= h((string)$runningNo) ?></td>
                                    <td class="text-nowrap">
                                        <?php if ($requestNo !== ''): ?>
                                            <button type="button" class="shipment-request-link js-shipment-detail"
                                                data-request-no="<?= h($requestNo) ?>"
                                                data-main-branch-code="<?= h($mainBranchCode !== '' ? formatMainBranchCode($mainBranchCode) : '-') ?>"
                                                data-branch-code="<?= h($branchCode !== '' ? $branchCode : '-') ?>"
                                                data-branch-name="<?= h($branchName !== '' ? $branchName : '-') ?>"
                                                data-hdd-serial="<?= h($hddSerial !== '' ? $hddSerial : '-') ?>"
                                                data-status="<?= h(shipmentStatusText($status)) ?>"
                                                data-shipped-at="<?= h(formatDateThai($displayDate, true)) ?>"
                                                data-reporter="<?= h($reporter !== '' ? $reporter : '-') ?>"
                                                data-request-reason="<?= h($requestReason !== '' ? $requestReason : '-') ?>"
                                                data-problem-no="<?= h($problemNo !== '' ? $problemNo : '-') ?>"
                                                data-requested-at="<?= h(formatDateThai($requestedAt, true)) ?>"
                                                data-remark="<?= h($remark !== '' ? $remark : '-') ?>"
                                                title="คลิกเพื่อดูรายละเอียดคำขอ"><?= h($requestNo) ?></button>
                                        <?php else: ?>
                                            -
                                        <?php endif; ?>
                                    </td>
                                    <td class="text-nowrap"><span class="branch-code"><?= $mainBranchCode !== '' ? h(formatMainBranchCode($mainBranchCode)) : '-' ?></span></td>
                                    <td class="text-nowrap"><?= $branchCode !== '' ? h($branchCode) : '-' ?></td>
                                    <td>
                                        <div class="text-ellipsis" title="<?= h($branchName) ?>">
                                            <?= $branchName !== '' ? h($branchName) : '-' ?>
                                        </div>
                                    </td>
                                    <td class="text-nowrap">
                                        <?= $hddSerial !== '' ? '<span class="serial-text">' . h($hddSerial) . '</span>' : '-' ?>
                                    </td>
                                    <td class="text-nowrap status-cell"><?= shipmentStatusBadge($status) ?></td>
                                    <td class="text-nowrap"><?= h(formatDateThai($displayDate)) ?></td>
                                    <td>
                                        <div class="text-ellipsis" title="<?= h($reporter) ?>">
                                            <?= $reporter !== '' ? h($reporter) : '-' ?>
                                        </div>
                                    </td>
                                    <td>
                                        <div class="remark-cell" title="<?= h($remark) ?>">
                                            <?= $remark !== '' ? h(shortText($remark, 100)) : '-' ?>
                                        </div>
                                    </td>
                                    <?php if ($isSuperAdmin): ?>
                                        <td class="text-center no-print">
                                            <button type="button" class="btn btn-sm btn-outline-primary js-edit-shipment-status"
                                                data-bs-toggle="modal" data-bs-target="#shipmentStatusModal"
                                                data-shipment-id="<?= (int)($row['id'] ?? 0) ?>"
                                                data-request-no="<?= h($requestNo !== '' ? $requestNo : '-') ?>"
                                                data-branch-name="<?= h($branchName !== '' ? $branchName : '-') ?>"
                                                data-hdd-serial="<?= h($hddSerial !== '' ? $hddSerial : '-') ?>"
                                                data-status="<?= h($status) ?>">แก้ไข</button>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if ($totalPages > 1): ?>
            <div class="card-footer bg-white no-print">
                <nav aria-label="Shipment pagination">
                    <ul class="pagination pagination-sm justify-content-center mb-0">
                        <?php $queryBase = $_GET; ?>

                        <?php
                        $prevPage = max($page - 1, 1);
                        $queryBase['page'] = $prevPage;
                        ?>
                        <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>">ก่อนหน้า</a>
                        </li>

                        <?php
                        $startPage = max($page - 2, 1);
                        $endPage = min($page + 2, $totalPages);
                        ?>

                        <?php if ($startPage > 1): ?>
                            <?php $queryBase['page'] = 1; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>">1</a>
                            </li>
                            <?php if ($startPage > 2): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                        <?php endif; ?>

                        <?php for ($i = $startPage; $i <= $endPage; $i++): ?>
                            <?php $queryBase['page'] = $i; ?>
                            <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                                <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>"><?= h((string)$i) ?></a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($endPage < $totalPages): ?>
                            <?php if ($endPage < $totalPages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">...</span></li>
                            <?php endif; ?>
                            <?php $queryBase['page'] = $totalPages; ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>"><?= h((string)$totalPages) ?></a>
                            </li>
                        <?php endif; ?>

                        <?php
                        $nextPage = min($page + 1, $totalPages);
                        $queryBase['page'] = $nextPage;
                        ?>
                        <li class="page-item <?= $page >= $totalPages ? 'disabled' : '' ?>">
                            <a class="page-link" href="?<?= h(http_build_query($queryBase)) ?>">ถัดไป</a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>


<div class="modal fade unified-action-modal" id="shipmentReportModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold"><i class="bi bi-file-earmark-bar-graph me-1 text-primary"></i>ออกรายงานประวัติการจัดส่ง Harddisk</h5>
                    <div class="small text-muted mt-1">เลือกช่วงเวลาและรูปแบบไฟล์ที่ต้องการ</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive border rounded-3 bg-white">
                    <table class="table table-bordered align-middle mb-0 shipment-report-table">
                        <tbody>
                            <tr>
                                <th>ช่วงรายงาน <span class="text-danger">*</span></th>
                                <td>
                                    <select class="form-select" id="shipment_report_period_type">
                                        <option value="day">ช่วงวัน</option>
                                        <option value="month">ช่วงเดือน</option>
                                        <option value="year">ช่วงปี</option>
                                    </select>
                                </td>
                            </tr>
                            <tr id="shipment_report_day_row">
                                <th>ช่วงวันที่ <span class="text-danger">*</span></th>
                                <td>
                                    <div class="row g-2">
                                        <div class="col-md-6"><label class="form-label mb-1" for="shipment_report_day_from">ตั้งแต่วันที่</label><input type="date" class="form-control" id="shipment_report_day_from" value="<?= h($dateFrom !== '' ? $dateFrom : date('Y-m-d')) ?>"></div>
                                        <div class="col-md-6"><label class="form-label mb-1" for="shipment_report_day_to">ถึงวันที่</label><input type="date" class="form-control" id="shipment_report_day_to" value="<?= h($dateTo !== '' ? $dateTo : date('Y-m-d')) ?>"></div>
                                    </div>
                                </td>
                            </tr>
                            <tr id="shipment_report_month_row" class="d-none">
                                <th>ช่วงเดือน <span class="text-danger">*</span></th>
                                <td>
                                    <div class="row g-2">
                                        <div class="col-md-6"><label class="form-label mb-1" for="shipment_report_month_from">ตั้งแต่เดือน</label><input type="month" class="form-control" id="shipment_report_month_from" value="<?= h(date('Y-m')) ?>"></div>
                                        <div class="col-md-6"><label class="form-label mb-1" for="shipment_report_month_to">ถึงเดือน</label><input type="month" class="form-control" id="shipment_report_month_to" value="<?= h(date('Y-m')) ?>"></div>
                                    </div>
                                </td>
                            </tr>
                            <tr id="shipment_report_year_row" class="d-none">
                                <th>ช่วงปี ค.ศ. <span class="text-danger">*</span></th>
                                <td>
                                    <div class="row g-2">
                                        <div class="col-md-6"><label class="form-label mb-1" for="shipment_report_year_from">ตั้งแต่ปี</label><input type="number" class="form-control" id="shipment_report_year_from" min="2000" max="2100" value="<?= h(date('Y')) ?>"></div>
                                        <div class="col-md-6"><label class="form-label mb-1" for="shipment_report_year_to">ถึงปี</label><input type="number" class="form-control" id="shipment_report_year_to" min="2000" max="2100" value="<?= h(date('Y')) ?>"></div>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <th>รูปแบบรายงาน <span class="text-danger">*</span></th>
                                <td>
                                    <select class="form-select" id="shipment_report_format">
                                        <option value="excel">Excel</option>
                                        <option value="pdf">PDF</option>
                                        <option value="csv">CSV</option>
                                    </select>
                                </td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="alert alert-info py-2 mt-2 mb-0 small">
                    ระบบจะใช้สถานะและคำค้นหาปัจจุบันร่วมกับช่วงวันที่ที่เลือกในการออกรายงาน
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light border" data-bs-dismiss="modal">ยกเลิก</button>
                <button type="button" class="btn btn-primary" id="shipment_report_submit"><i class="bi bi-download me-1"></i>ออกรายงาน</button>
            </div>
        </div>
    </div>
</div>


<div class="modal fade shipment-detail-modal" id="shipmentDetailModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <div>
                    <h5 class="modal-title fw-bold">รายละเอียดรายการจัดส่ง Harddisk</h5>
                    <div class="small text-muted mt-1" id="shipment_detail_subtitle">-</div>
                </div>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="ปิด"></button>
            </div>
            <div class="modal-body">
                <div class="table-responsive shipment-detail-table-wrap">
                    <table class="table table-bordered table-hover shipment-detail-table mb-0">
                        <tbody>
                            <tr><th>เลขที่คำขอ</th><td class="primary" id="shipment_detail_request_no">-</td></tr>
                            <tr><th>สถานะ</th><td id="shipment_detail_status">-</td></tr>
                            <tr><th>รหัสสาขาใหญ่</th><td id="shipment_detail_main_branch_code">-</td></tr>
                            <tr><th>Cost Center</th><td class="primary" id="shipment_detail_branch_code">-</td></tr>
                            <tr><th>ชื่อสาขา</th><td id="shipment_detail_branch_name">-</td></tr>
                            <tr><th>Serial HDD</th><td id="shipment_detail_hdd_serial">-</td></tr>
                            <tr><th>เลขที่ปัญหา</th><td id="shipment_detail_problem_no">-</td></tr>
                            <tr><th>สาเหตุที่ส่ง HDD</th><td id="shipment_detail_request_reason">-</td></tr>
                            <tr><th>ผู้แจ้ง/ผู้บันทึก</th><td id="shipment_detail_reporter">-</td></tr>
                            <tr><th>วันที่บันทึกคำขอ</th><td id="shipment_detail_requested_at">-</td></tr>
                            <tr><th>วันที่จัดส่ง</th><td id="shipment_detail_shipped_at">-</td></tr>
                            <tr><th>หมายเหตุ</th><td id="shipment_detail_remark">-</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="modal-footer"><button type="button" class="btn btn-secondary btn-sm" data-bs-dismiss="modal">ปิด</button></div>
        </div>
    </div>
</div>

<?php if ($isSuperAdmin): ?>
<div class="modal fade unified-action-modal" id="shipmentStatusModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <form method="post" class="modal-content" id="shipmentStatusForm">
      <div class="modal-header"><div><h5 class="modal-title fw-bold">แก้ไขสถานะการจัดส่ง</h5><div class="small text-muted mt-1" id="shipment_status_subtitle">-</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body">
        <input type="hidden" name="manage_action" value="change_status">
        <input type="hidden" name="shipment_id" value="">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
        <div class="alert alert-warning small">การย้อนสถานะจะปรับคำขอ, Serial HDD, ประวัติการจัดส่ง และสถานะ HDD ในคลังให้ตรงกับ Workflow อัตโนมัติ</div>
        <div class="mb-3"><label class="form-label">Serial HDD</label><input type="text" id="shipment_status_serial" class="form-control" readonly></div>
        <div><label class="form-label">สถานะใหม่</label><select name="status" class="form-select" required>
          <option value="pending_scan">รอยิงบาร์โค้ด</option><option value="pending">รอดำเนินการ</option><option value="approved">อนุมัติแล้ว</option>
          <option value="matched">รอยืนยันจัดส่ง</option><option value="reserved">จับคู่ HDD แล้ว</option><option value="shipped">จัดส่งแล้ว</option>
          <option value="received">รับแล้ว</option><option value="installed">ติดตั้งแล้ว</option><option value="completed">เสร็จสิ้น</option>
          <option value="cancelled">ยกเลิก</option><option value="rejected">ไม่อนุมัติ</option>
        </select></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button type="submit" class="btn btn-primary">บันทึกสถานะ</button></div>
    </form>
  </div>
</div>
<?php endif; ?>


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

<?php require_once $basePath . '/includes/footer.php'; ?>


<div class="modal fade unified-action-modal" id="unifiedConfirmModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header"><div><h5 class="modal-title fw-bold">ยืนยันการดำเนินการ</h5><div class="small text-muted mt-1">กรุณาตรวจสอบข้อมูลก่อนดำเนินการ</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><div class="alert alert-warning mb-0" id="unifiedConfirmMessage">ยืนยันการดำเนินการนี้หรือไม่?</div></div>
      <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">ยกเลิก</button><button type="button" class="btn btn-danger" id="unifiedConfirmSubmit">ยืนยัน</button></div>
    </div>
  </div>
</div>
<div class="modal fade unified-action-modal" id="unifiedEditModal" tabindex="-1" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
  <div class="modal-dialog modal-xl modal-dialog-centered modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header"><div><h5 class="modal-title fw-bold">แก้ไขข้อมูล</h5><div class="small text-muted mt-1">แก้ไขข้อมูลโดยไม่ออกจากหน้ารายการ</div></div><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
      <div class="modal-body"><iframe id="unifiedEditFrame" class="unified-edit-frame" title="แก้ไขข้อมูล"></iframe></div>
      <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">ปิด</button></div>
    </div>
  </div>
</div>
<script>
document.addEventListener('DOMContentLoaded',function(){
  const reportPeriodType=document.getElementById('shipment_report_period_type');
  const reportDayRow=document.getElementById('shipment_report_day_row');
  const reportMonthRow=document.getElementById('shipment_report_month_row');
  const reportYearRow=document.getElementById('shipment_report_year_row');
  function updateShipmentReportPeriodFields(){
    const type=reportPeriodType ? reportPeriodType.value : 'day';
    if(reportDayRow) reportDayRow.classList.toggle('d-none',type!=='day');
    if(reportMonthRow) reportMonthRow.classList.toggle('d-none',type!=='month');
    if(reportYearRow) reportYearRow.classList.toggle('d-none',type!=='year');
  }
  if(reportPeriodType){
    reportPeriodType.addEventListener('change',updateShipmentReportPeriodFields);
    updateShipmentReportPeriodFields();
  }
  const reportSubmit=document.getElementById('shipment_report_submit');
  if(reportSubmit) reportSubmit.addEventListener('click',function(){
    const type=reportPeriodType ? reportPeriodType.value : 'day';
    const format=document.getElementById('shipment_report_format')?.value||'excel';
    let dateFrom='';
    let dateTo='';
    if(type==='day'){
      dateFrom=document.getElementById('shipment_report_day_from')?.value||'';
      dateTo=document.getElementById('shipment_report_day_to')?.value||'';
      if(!dateFrom||!dateTo){alert('กรุณาเลือกวันที่เริ่มต้นและวันที่สิ้นสุด');return;}
    }else if(type==='month'){
      const monthFrom=document.getElementById('shipment_report_month_from')?.value||'';
      const monthTo=document.getElementById('shipment_report_month_to')?.value||'';
      if(!/^\d{4}-\d{2}$/.test(monthFrom)||!/^\d{4}-\d{2}$/.test(monthTo)){alert('กรุณาเลือกเดือนเริ่มต้นและเดือนสิ้นสุด');return;}
      const fromParts=monthFrom.split('-');
      const toParts=monthTo.split('-');
      dateFrom=monthFrom+'-01';
      const lastDay=new Date(Number(toParts[0]),Number(toParts[1]),0).getDate();
      dateTo=monthTo+'-'+String(lastDay).padStart(2,'0');
    }else{
      const yearFrom=String(document.getElementById('shipment_report_year_from')?.value||'').trim();
      const yearTo=String(document.getElementById('shipment_report_year_to')?.value||'').trim();
      if(!/^\d{4}$/.test(yearFrom)||!/^\d{4}$/.test(yearTo)){alert('กรุณาระบุปีเริ่มต้นและปีสิ้นสุดเป็น ค.ศ. 4 หลัก');return;}
      dateFrom=yearFrom+'-01-01';
      dateTo=yearTo+'-12-31';
    }
    if(dateFrom>dateTo){alert('ช่วงวันที่ไม่ถูกต้อง วันที่เริ่มต้นต้องไม่มากกว่าวันที่สิ้นสุด');return;}
    const params=new URLSearchParams(window.location.search);
    params.delete('page');
    params.set('date_from',dateFrom);
    params.set('date_to',dateTo);
    params.set('export',format);
    const url='index.php?'+params.toString();
    if(format==='pdf') window.open(url,'_blank','noopener');
    else window.location.href=url;
  });
  const shipmentStatusForm=document.getElementById('shipmentStatusForm');
  document.querySelectorAll('.js-edit-shipment-status').forEach(function(button){
    button.addEventListener('click',function(){
      if(!shipmentStatusForm) return;
      shipmentStatusForm.querySelector('[name="shipment_id"]').value=button.dataset.shipmentId||'';
      shipmentStatusForm.querySelector('[name="status"]').value=button.dataset.status||'shipped';
      const serial=document.getElementById('shipment_status_serial'); if(serial) serial.value=button.dataset.hddSerial||'-';
      const subtitle=document.getElementById('shipment_status_subtitle'); if(subtitle) subtitle.textContent=(button.dataset.requestNo||'-')+' • '+(button.dataset.branchName||'-');
    });
  });
  if(shipmentStatusForm){shipmentStatusForm.addEventListener('submit',function(event){if(!confirm('ยืนยันการเปลี่ยนสถานะและปรับข้อมูลทุกตารางที่เกี่ยวข้องหรือไม่?')) event.preventDefault();});}

  document.querySelectorAll('.js-shipment-detail').forEach(function(button){
    button.addEventListener('click',function(){
      const values={
        shipment_detail_request_no:button.dataset.requestNo||'-',
        shipment_detail_status:button.dataset.status||'-',
        shipment_detail_main_branch_code:button.dataset.mainBranchCode||'-',
        shipment_detail_branch_code:button.dataset.branchCode||'-',
        shipment_detail_branch_name:button.dataset.branchName||'-',
        shipment_detail_hdd_serial:button.dataset.hddSerial||'-',
        shipment_detail_problem_no:button.dataset.problemNo||'-',
        shipment_detail_request_reason:button.dataset.requestReason||'-',
        shipment_detail_reporter:button.dataset.reporter||'-',
        shipment_detail_requested_at:button.dataset.requestedAt||'-',
        shipment_detail_shipped_at:button.dataset.shippedAt||'-',
        shipment_detail_remark:button.dataset.remark||'-'
      };
      Object.keys(values).forEach(function(id){
        const element=document.getElementById(id);
        if(element) element.textContent=values[id];
      });
      const subtitle=document.getElementById('shipment_detail_subtitle');
      if(subtitle) subtitle.textContent=(button.dataset.requestNo||'-')+' • '+(button.dataset.branchName||'-');
      const modal=document.getElementById('shipmentDetailModal');
      if(modal&&window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
    });
  });

  let pendingForm=null;
  const confirmEl=document.getElementById('unifiedConfirmModal');
  const confirmMsg=document.getElementById('unifiedConfirmMessage');
  const confirmBtn=document.getElementById('unifiedConfirmSubmit');
  document.querySelectorAll('form[data-confirm-message]').forEach(function(form){
    form.addEventListener('submit',function(ev){
      if(form.dataset.confirmed==='1') return;
      ev.preventDefault(); pendingForm=form;
      if(confirmMsg) confirmMsg.textContent=form.dataset.confirmMessage||'ยืนยันการดำเนินการนี้หรือไม่?';
      if(confirmEl&&window.bootstrap) bootstrap.Modal.getOrCreateInstance(confirmEl).show();
    });
  });
  if(confirmBtn) confirmBtn.addEventListener('click',function(){
    if(!pendingForm) return;
    pendingForm.dataset.confirmed='1';
    if(confirmEl&&window.bootstrap) bootstrap.Modal.getOrCreateInstance(confirmEl).hide();
    pendingForm.requestSubmit ? pendingForm.requestSubmit() : pendingForm.submit();
  });
  document.querySelectorAll('a[data-edit-popup]').forEach(function(link){
    link.addEventListener('click',function(ev){
      ev.preventDefault();
      const frame=document.getElementById('unifiedEditFrame');
      const modal=document.getElementById('unifiedEditModal');
      if(frame) frame.src=link.href;
      if(modal&&window.bootstrap) bootstrap.Modal.getOrCreateInstance(modal).show();
    });
  });
  const editModal=document.getElementById('unifiedEditModal');
  if(editModal) editModal.addEventListener('hidden.bs.modal',function(){
    const frame=document.getElementById('unifiedEditFrame'); if(frame) frame.src='';
    if(editModal.dataset.refreshOnClose==='1') location.reload();
  });
});
</script>





<style>
/* Content-height tables: height follows the actual number of rows */
.scan-main-panel .scan-queue,
.matched-card .table-scroll,
.request-card .table-wrap,
.shipment-card .table-scroll,
.inventory-card .table-scroll,
.claim-card .claim-table-wrap,
.claim-table-wrap,
.js-footer-fit-table,
.hdd-footer-fit-table,
.hdd-footer-anchor-table {
    height: auto !important;
    min-height: 0 !important;
    max-height: none !important;
    overflow-y: visible !important;
}

.scan-main-panel .scan-queue,
.matched-card .table-scroll,
.request-card .table-wrap,
.shipment-card .table-scroll,
.inventory-card .table-scroll,
.claim-card .claim-table-wrap,
.claim-table-wrap {
    overflow-x: auto !important;
}

/* Sticky headers are disabled because the table no longer has an internal vertical scroll area. */
.scan-main-panel .scan-queue th,
.matched-card .table-scroll th,
.request-card .table-wrap th,
.shipment-card .table-scroll th,
.inventory-card .table-scroll th,
.claim-card .claim-table-wrap th,
.claim-table-wrap th {
    position: static !important;
}
</style>

<style>

/* Unified single-row search menu copied from Shipment History */
.hdd-search-card{background:#fff;border:0;border-radius:16px;box-shadow:0 5px 18px rgba(20,46,70,.07);overflow:hidden}
.hdd-search-card .card-body{padding:13px 14px}
.hdd-unified-search-row{display:grid;align-items:end;gap:8px;width:100%;max-width:100%}
.hdd-unified-search-row>div{min-width:0;max-width:100%}
.hdd-unified-search-row .form-label{display:block;font-size:.72rem;font-weight:800;color:#5f6f7e;margin-bottom:4px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
.hdd-unified-search-row .form-control,.hdd-unified-search-row .form-select,.hdd-unified-search-row .btn{width:100%;max-width:100%;height:38px;min-height:38px;border-radius:10px;font-size:.76rem}
.hdd-unified-search-row .hdd-search-keyword{min-width:0}
.hdd-unified-search-row.hdd-fields-7{grid-template-columns:minmax(260px,1fr) repeat(3,minmax(125px,150px)) minmax(72px,90px) minmax(86px,100px) minmax(86px,100px)}
.hdd-unified-search-row.hdd-fields-6{grid-template-columns:minmax(260px,1.35fr) minmax(120px,.72fr) minmax(240px,1.15fr) minmax(82px,.48fr) minmax(82px,.48fr)}
.hdd-unified-search-row.hdd-fields-5{grid-template-columns:minmax(300px,1fr) minmax(140px,.55fr) minmax(110px,.42fr) minmax(90px,.34fr) minmax(90px,.34fr)}
.hdd-unified-search-row.hdd-fields-8{grid-template-columns:minmax(240px,1.45fr) minmax(115px,.7fr) minmax(115px,.7fr) minmax(115px,.7fr) minmax(65px,.38fr) minmax(80px,.48fr) minmax(92px,.52fr) minmax(80px,.48fr)}
.hdd-search-date-pair{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:5px}
.hdd-search-actions{display:grid!important;grid-template-columns:minmax(0,1fr) minmax(0,1fr);gap:5px}
@media(max-width:1366px){
 .hdd-search-card .card-body{padding:10px 12px}
 .hdd-unified-search-row{gap:5px}
 .hdd-unified-search-row .form-control,.hdd-unified-search-row .form-select,.hdd-unified-search-row .btn{height:34px;min-height:34px;font-size:.68rem;padding-left:.42rem;padding-right:.42rem}
 .hdd-unified-search-row .form-label{font-size:.64rem;margin-bottom:3px}
 .hdd-unified-search-row.hdd-fields-7{grid-template-columns:minmax(210px,1.55fr) minmax(112px,.78fr) minmax(112px,.78fr) minmax(105px,.72fr) minmax(64px,.42fr) minmax(74px,.5fr) minmax(74px,.5fr)}
 .hdd-unified-search-row.hdd-fields-6{grid-template-columns:minmax(210px,1.35fr) minmax(105px,.62fr) minmax(215px,1.18fr) minmax(78px,.42fr) minmax(78px,.42fr)}
 .hdd-unified-search-row.hdd-fields-5{grid-template-columns:minmax(250px,1.6fr) minmax(120px,.72fr) minmax(88px,.48fr) minmax(76px,.42fr) minmax(76px,.42fr)}
 .hdd-unified-search-row.hdd-fields-8{grid-template-columns:minmax(200px,1.4fr) minmax(100px,.66fr) minmax(100px,.66fr) minmax(100px,.66fr) minmax(58px,.36fr) minmax(72px,.44fr) minmax(84px,.48fr) minmax(72px,.44fr)}
}
@media(max-width:767.98px){.hdd-search-card .card-body{overflow-x:auto}.hdd-unified-search-row{min-width:760px}}

</style>
<style id="hdd-six-page-status-style">
/* HDD 6 pages: unified rectangular status colors */
.scan-status-badge,
.duplicate-status-badge.status-pending-scan,
.hdd-status-badge.hdd-status-pending_scan,
.hdd-status-badge.hdd-status-pending {
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    min-width:92px!important;padding:5px 9px!important;border:1px solid #dc2626!important;
    border-radius:4px!important;background:#dc2626!important;color:#fff!important;
    box-shadow:none!important;font-weight:800!important;line-height:1.15!important;white-space:nowrap!important;
}
.status-pill,
.duplicate-status-badge.status-matched,
.hdd-status-badge.hdd-status-matched {
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    min-width:92px!important;padding:5px 9px!important;border:1px solid #0d6efd!important;
    border-radius:4px!important;background:#0d6efd!important;color:#fff!important;
    box-shadow:none!important;font-weight:800!important;line-height:1.15!important;white-space:nowrap!important;
}
.duplicate-status-badge.status-shipped,
.hdd-status-badge.hdd-status-shipped,
.shipment-status-badge.shipment-status-shipped,
.inventory-status-badge.inventory-status-shipped {
    display:inline-flex!important;align-items:center!important;justify-content:center!important;
    min-width:92px!important;padding:5px 9px!important;border:1px solid #198754!important;
    border-radius:4px!important;background:#198754!important;color:#fff!important;
    box-shadow:none!important;font-weight:800!important;line-height:1.15!important;white-space:nowrap!important;
}
@media(max-width:1366px){
    .scan-status-badge,.duplicate-status-badge.status-pending-scan,.duplicate-status-badge.status-matched,
    .duplicate-status-badge.status-shipped,.status-pill,.hdd-status-badge.hdd-status-pending_scan,
    .hdd-status-badge.hdd-status-pending,.hdd-status-badge.hdd-status-matched,.hdd-status-badge.hdd-status-shipped,
    .shipment-status-badge.shipment-status-shipped,.inventory-status-badge.inventory-status-shipped{
        min-width:84px!important;padding:4px 7px!important;
    }
}
</style>

