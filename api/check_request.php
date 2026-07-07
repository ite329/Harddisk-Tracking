<?php
declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../config/database.php';

$authFile = __DIR__ . '/../includes/auth.php';
if (file_exists($authFile)) {
    require_once $authFile;
}

if (function_exists('require_login')) {
    require_login();
}

header('Content-Type: application/json; charset=utf-8');

function jsonResponse(array $payload): void
{
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function cleanText($value): string
{
    return trim((string)($value ?? ''));
}

function tableExists(PDO $pdo, string $tableName): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
    ");
    $stmt->execute([
        ':table_name' => $tableName
    ]);

    return (int)$stmt->fetchColumn() > 0;
}

function getTableColumns(PDO $pdo, string $tableName): array
{
    if (!tableExists($pdo, $tableName)) {
        return [];
    }

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

function selectColumns(array $columns, array $wanted): string
{
    $select = [];

    foreach ($wanted as $column => $alias) {
        if (is_int($column)) {
            $column = $alias;
            $alias = $column;
        }

        if (hasColumn($columns, (string)$column)) {
            $select[] = '`' . $column . '` AS `' . $alias . '`';
        } else {
            $select[] = 'NULL AS `' . $alias . '`';
        }
    }

    return implode(', ', $select);
}

function countRows(PDO $pdo, string $tableName, array $columns, array $where, array $params): int
{
    if (!tableExists($pdo, $tableName)) {
        return 0;
    }

    $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM `{$tableName}`
        {$whereSql}
    ");

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->execute();

    return (int)$stmt->fetchColumn();
}

function getOrderParts(array $columns): array
{
    $orderParts = [];

    foreach (['updated_at', 'matched_at', 'shipped_at', 'shipped_date', 'created_at', 'requested_at', 'id'] as $orderColumn) {
        if (hasColumn($columns, $orderColumn)) {
            $orderParts[] = '`' . $orderColumn . '` DESC';
        }
    }

    if (empty($orderParts)) {
        $orderParts[] = '1 DESC';
    }

    return $orderParts;
}

function fetchRows(PDO $pdo, string $tableName, array $columns, array $where, array $params, array $wantedColumns, int $limit = 10): array
{
    if (!tableExists($pdo, $tableName)) {
        return [];
    }

    $whereSql = empty($where) ? '' : 'WHERE ' . implode(' AND ', $where);

    $sql = "
        SELECT " . selectColumns($columns, $wantedColumns) . "
        FROM `{$tableName}`
        {$whereSql}
        ORDER BY " . implode(', ', getOrderParts($columns)) . "
        LIMIT :limit
    ";

    $stmt = $pdo->prepare($sql);

    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }

    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function fetchLatestRow(PDO $pdo, string $tableName, array $columns, array $where, array $params, array $wantedColumns): ?array
{
    $rows = fetchRows($pdo, $tableName, $columns, $where, $params, $wantedColumns, 1);

    return $rows[0] ?? null;
}

function getDateValue(array $row): int
{
    foreach (['updated_at', 'matched_at', 'shipped_at', 'shipped_date', 'created_at', 'requested_at'] as $column) {
        if (!empty($row[$column])) {
            $timestamp = strtotime((string)$row[$column]);
            if ($timestamp !== false) {
                return $timestamp;
            }
        }
    }

    return isset($row['id']) ? (int)$row['id'] : 0;
}

function requestStatusText(?string $status): string
{
    $status = cleanText($status);

    $map = [
        'pending_scan' => 'รอยิงบาร์โค้ด',
        'pending' => 'รอยิงบาร์โค้ด',
        'matched' => 'รอยืนยันจัดส่ง',
        'reserved' => 'รอยืนยันจัดส่ง',
        'pending_delivery' => 'รอยืนยันจัดส่ง',
        'pending_ship' => 'รอยืนยันจัดส่ง',
        'waiting_ship' => 'รอยืนยันจัดส่ง',
        'shipped' => 'จัดส่งแล้ว',
        'received' => 'สาขาได้รับแล้ว',
        'installed' => 'ติดตั้งแล้ว',
        'completed' => 'เสร็จสิ้น',
        'cancelled' => 'ยกเลิก',
        'rejected' => 'ไม่อนุมัติ',
    ];

    return $map[$status] ?? ($status !== '' ? $status : '-');
}

function normalizeLatestSource(array $row): string
{
    $source = cleanText($row['_source'] ?? '');

    if ($source === 'shipment') {
        return 'ประวัติการจัดส่ง Harddisk';
    }

    $status = cleanText($row['status'] ?? '');

    if (in_array($status, ['pending_scan', 'pending'], true)) {
        return 'ยิงบาร์โค้ด HDD เพื่อจับคู่กับสาขา';
    }

    if (in_array($status, ['matched', 'reserved', 'pending_delivery', 'pending_ship', 'waiting_ship'], true)) {
        return 'รายการรอยืนยันจัดส่ง';
    }

    return 'รายการคำขอส่ง HDD';
}

try {
    if (!isset($pdo) || !$pdo instanceof PDO) {
        throw new Exception('ไม่พบการเชื่อมต่อฐานข้อมูล PDO');
    }

    $branchCode = cleanText($_GET['branch_code'] ?? ($_GET['cost_center'] ?? ''));

    if ($branchCode === '') {
        jsonResponse([
            'success' => false,
            'message' => 'ไม่พบ Cost Center สำหรับตรวจสอบรายการซ้ำ'
        ]);
    }

    $requestTable = 'harddisk_delivery_requests';
    $shipmentTable = 'harddisk_shipments';

    $requestColumns = getTableColumns($pdo, $requestTable);
    $shipmentColumns = getTableColumns($pdo, $shipmentTable);

    if (empty($requestColumns) || !hasColumn($requestColumns, 'branch_code')) {
        jsonResponse([
            'success' => false,
            'message' => 'ไม่พบตาราง harddisk_delivery_requests หรือไม่มีคอลัมน์ branch_code'
        ]);
    }

    $requestBaseWhere = [
        'branch_code = :branch_code'
    ];
    $requestParams = [
        ':branch_code' => $branchCode
    ];

    if (hasColumn($requestColumns, 'deleted_at')) {
        $requestBaseWhere[] = 'deleted_at IS NULL';
    }

    $requestWanted = [
        'id',
        'request_no',
        'main_branch_code',
        'branch_code',
        'branch_name',
        'hdd_serial',
        'request_reason',
        'status',
        'requested_by',
        'created_by',
        'updated_at',
        'created_at',
        'requested_at',
        'matched_by',
        'matched_at',
        'remark'
    ];

    $allRequestsCount = countRows($pdo, $requestTable, $requestColumns, $requestBaseWhere, $requestParams);
    $allRequestsRows = fetchRows($pdo, $requestTable, $requestColumns, $requestBaseWhere, $requestParams, $requestWanted, 10);

    $pendingWhere = $requestBaseWhere;
    $pendingStatusParts = [];

    if (hasColumn($requestColumns, 'status')) {
        $pendingStatusParts[] = "status IN ('pending_scan', 'pending')";
    }

    $noHddParts = [];

    if (hasColumn($requestColumns, 'hdd_inventory_id')) {
        $noHddParts[] = '(hdd_inventory_id IS NULL OR hdd_inventory_id = 0)';
    }

    if (hasColumn($requestColumns, 'hdd_serial')) {
        $noHddParts[] = "(hdd_serial IS NULL OR TRIM(hdd_serial) = '')";
    }

    if (!empty($noHddParts)) {
        $pendingStatusParts[] = '(' . implode(' AND ', $noHddParts) . ')';
    }

    if (!empty($pendingStatusParts)) {
        $pendingWhere[] = '(' . implode(' OR ', $pendingStatusParts) . ')';
    }

    $pendingCount = countRows($pdo, $requestTable, $requestColumns, $pendingWhere, $requestParams);
    $pendingRows = fetchRows($pdo, $requestTable, $requestColumns, $pendingWhere, $requestParams, $requestWanted, 10);

    $matchedWhere = $requestBaseWhere;

    if (hasColumn($requestColumns, 'status')) {
        $matchedWhere[] = "status IN ('matched', 'reserved', 'pending_delivery', 'pending_ship', 'waiting_ship')";
    } else {
        $matchedWhere[] = '1 = 0';
    }

    $matchedCount = countRows($pdo, $requestTable, $requestColumns, $matchedWhere, $requestParams);
    $matchedRows = fetchRows($pdo, $requestTable, $requestColumns, $matchedWhere, $requestParams, $requestWanted, 10);

    $shipmentCount = 0;
    $shipmentRows = [];
    $latestShipmentRow = null;

    if (!empty($shipmentColumns) && hasColumn($shipmentColumns, 'branch_code')) {
        $shipmentWhere = [
            'branch_code = :branch_code'
        ];

        if (hasColumn($shipmentColumns, 'deleted_at')) {
            $shipmentWhere[] = 'deleted_at IS NULL';
        }

        $shipmentWanted = [
            'id',
            'delivery_request_no',
            'request_no',
            'main_branch_code',
            'branch_code',
            'branch_name',
            'hdd_serial',
            'status',
            'shipment_status',
            'reported_by',
            'created_by',
            'updated_at',
            'shipped_at',
            'shipped_date',
            'created_at',
            'remark'
        ];

        $shipmentCount = countRows($pdo, $shipmentTable, $shipmentColumns, $shipmentWhere, $requestParams);
        $shipmentRows = fetchRows($pdo, $shipmentTable, $shipmentColumns, $shipmentWhere, $requestParams, $shipmentWanted, 10);
        $latestShipmentRow = fetchLatestRow($pdo, $shipmentTable, $shipmentColumns, $shipmentWhere, $requestParams, $shipmentWanted);

        if ($latestShipmentRow) {
            $latestShipmentRow['_source'] = 'shipment';
        }
    }

    $latestRequestRow = fetchLatestRow($pdo, $requestTable, $requestColumns, $requestBaseWhere, $requestParams, $requestWanted);

    if ($latestRequestRow) {
        $latestRequestRow['_source'] = 'request';
    }

    $latest = null;

    if ($latestRequestRow && $latestShipmentRow) {
        $latest = getDateValue($latestRequestRow) >= getDateValue($latestShipmentRow)
            ? $latestRequestRow
            : $latestShipmentRow;
    } elseif ($latestRequestRow) {
        $latest = $latestRequestRow;
    } elseif ($latestShipmentRow) {
        $latest = $latestShipmentRow;
    }

    $latestStatus = cleanText($latest['status'] ?? ($latest['shipment_status'] ?? ''));
    $latestSource = $latest ? normalizeLatestSource($latest) : '';

    $blockedStatuses = [
        'pending_scan',
        'pending',
        'matched',
        'reserved',
        'pending_delivery',
        'pending_ship',
        'waiting_ship',
    ];

    $blockSave = $latest && cleanText($latest['_source'] ?? '') === 'request' && in_array($latestStatus, $blockedStatuses, true);
    $latestStatusText = requestStatusText($latestStatus);

    $blockMessage = '';
    if ($blockSave) {
        $blockMessage = 'ไม่สามารถบันทึกคำขอใหม่ได้ เนื่องจากรายการล่าสุดของ Cost Center นี้อยู่ในสถานะ "' . $latestStatusText . '"';
    }

    $totalFound = $allRequestsCount + $shipmentCount;

    jsonResponse([
        'success' => true,
        'branch_code' => $branchCode,
        'exists' => $totalFound > 0,
        'has_pending' => $pendingCount > 0,
        'has_duplicate' => $totalFound > 0,
        'can_create' => !$blockSave,
        'block_save' => $blockSave,
        'block_message' => $blockMessage,
        'latest_status' => $latestStatus,
        'latest_status_text' => $latestStatusText,
        'latest_source' => $latestSource,
        'latest' => $latest,
        'total' => $totalFound,
        'summary' => [
            'requests' => $allRequestsCount,
            'pending_scan' => $pendingCount,
            'matched' => $matchedCount,
            'shipments' => $shipmentCount
        ],
        'items' => [
            'requests' => $allRequestsRows,
            'pending_scan' => $pendingRows,
            'matched' => $matchedRows,
            'shipments' => $shipmentRows
        ],
        'data' => $latest,
        'message' => $blockSave
            ? $blockMessage
            : ($totalFound > 0 ? 'ตรวจพบประวัติของ Cost Center นี้ในระบบ แต่สามารถบันทึกคำขอใหม่ได้' : 'ไม่พบประวัติของ Cost Center นี้ สามารถบันทึกคำขอได้')
    ]);
} catch (Throwable $e) {
    jsonResponse([
        'success' => false,
        'message' => 'เกิดข้อผิดพลาดในการตรวจสอบข้อมูล: ' . $e->getMessage()
    ]);
}
