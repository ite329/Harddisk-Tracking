<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';


require_login();
require_permission('shipment.manage');

require_login();

function getCurrentLoginNamesForShip()
{
    $names = [];

    if (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
        $names[] = trim(($_SESSION['first_name'] ?? '') . ' ' . ($_SESSION['last_name'] ?? ''));
    }

    if (!empty($_SESSION['full_name'])) {
        $names[] = trim($_SESSION['full_name']);
    }

    if (!empty($_SESSION['name'])) {
        $names[] = trim($_SESSION['name']);
    }

    if (!empty($_SESSION['employee_code'])) {
        $names[] = trim($_SESSION['employee_code']);
    }

    if (!empty($_SESSION['username'])) {
        $names[] = trim($_SESSION['username']);
    }

    if (!empty($_SESSION['user']['first_name']) || !empty($_SESSION['user']['last_name'])) {
        $names[] = trim(($_SESSION['user']['first_name'] ?? '') . ' ' . ($_SESSION['user']['last_name'] ?? ''));
    }

    if (!empty($_SESSION['user']['full_name'])) {
        $names[] = trim($_SESSION['user']['full_name']);
    }

    if (!empty($_SESSION['user']['name'])) {
        $names[] = trim($_SESSION['user']['name']);
    }

    if (!empty($_SESSION['user']['employee_code'])) {
        $names[] = trim($_SESSION['user']['employee_code']);
    }

    if (!empty($_SESSION['user']['username'])) {
        $names[] = trim($_SESSION['user']['username']);
    }

    $fullNameForCombo = '';
    if (!empty($_SESSION['full_name'])) {
        $fullNameForCombo = trim((string)$_SESSION['full_name']);
    } elseif (!empty($_SESSION['first_name']) || !empty($_SESSION['last_name'])) {
        $fullNameForCombo = trim((string)($_SESSION['first_name'] ?? '') . ' ' . (string)($_SESSION['last_name'] ?? ''));
    }
    $employeeCodeForCombo = trim((string)($_SESSION['employee_code'] ?? ''));
    if ($fullNameForCombo !== '' && $employeeCodeForCombo !== '') {
        $names[] = $fullNameForCombo . ' (' . $employeeCodeForCombo . ')';
    }

    if (!empty($_SESSION['user']) && is_array($_SESSION['user'])) {
        $userFullNameForCombo = trim((string)($_SESSION['user']['full_name'] ?? ''));
        if ($userFullNameForCombo === '') {
            $userFullNameForCombo = trim((string)($_SESSION['user']['first_name'] ?? '') . ' ' . (string)($_SESSION['user']['last_name'] ?? ''));
        }
        $userEmployeeCodeForCombo = trim((string)($_SESSION['user']['employee_code'] ?? ''));
        if ($userFullNameForCombo !== '' && $userEmployeeCodeForCombo !== '') {
            $names[] = $userFullNameForCombo . ' (' . $userEmployeeCodeForCombo . ')';
        }
    }

    $names = array_filter($names, function ($value) {
        return trim((string)$value) !== '';
    });

    $names = array_unique($names);

    return array_values($names);
}

function getCurrentDisplayNameForShip($names)
{
    foreach ($names as $name) {
        if (preg_match('/[ก-๙]/u', $name)) {
            return $name;
        }
    }

    return $names[0] ?? '';
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: confirm.php');
    exit;
}

verify_csrf();

$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;

$currentLoginNames = getCurrentLoginNamesForShip();
$currentDisplayName = getCurrentDisplayNameForShip($currentLoginNames);

if ($requestId <= 0 || count($currentLoginNames) === 0) {
    header('Location: ../requests/matched.php?error=invalid');
    exit;
}

$ownerParams = [];
$requestedByPlaceholders = [];
$matchedByPlaceholders = [];

foreach ($currentLoginNames as $index => $name) {
    $requestedKey = ':requested_by_' . $index;
    $matchedKey = ':matched_by_' . $index;

    $requestedByPlaceholders[] = $requestedKey;
    $matchedByPlaceholders[] = $matchedKey;

    $ownerParams[$requestedKey] = $name;
    $ownerParams[$matchedKey] = $name;
}

$requestedByInSql = implode(', ', $requestedByPlaceholders);
$matchedByInSql = implode(', ', $matchedByPlaceholders);

try {
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | Lock request
    |--------------------------------------------------------------------------
    | ดึงรายการที่ผู้ Login เป็นผู้บันทึกคำขอ หรือเป็นผู้จับคู่ HDD
    |--------------------------------------------------------------------------
    */
    $requestSql = "
        SELECT
            id,
            request_no,
            main_branch_code,
            branch_code,
            branch_name,
            request_reason,
            status,
            requested_by,
            requested_at,
            matched_by,
            matched_at,
            hdd_inventory_id,
            hdd_serial
        FROM harddisk_delivery_requests
        WHERE id = :id
          AND deleted_at IS NULL
          AND status = 'matched'
          AND (
              TRIM(requested_by) IN ($requestedByInSql)
              OR TRIM(matched_by) IN ($matchedByInSql)
          )
        LIMIT 1
        FOR UPDATE
    ";

    $requestStmt = $pdo->prepare($requestSql);

    $requestStmt->bindValue(':id', $requestId, PDO::PARAM_INT);

    foreach ($ownerParams as $key => $value) {
        $requestStmt->bindValue($key, $value);
    }

    $requestStmt->execute();

    $request = $requestStmt->fetch();

    if (!$request) {
        $pdo->rollBack();
        header('Location: ../requests/matched.php?error=not_found');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Get HDD Serial
    |--------------------------------------------------------------------------
    | Newer barcode flow stores Serial directly on harddisk_delivery_requests.
    | Older pages may still keep it in harddisk_request_items, so support both.
    */
    $hddSerial = trim((string)($request['hdd_serial'] ?? ''));

    if ($hddSerial === '') {
        $itemSql = "
            SELECT
                id,
                hdd_serial
            FROM harddisk_request_items
            WHERE request_id = :request_id
              AND scan_status = 'matched'
            ORDER BY id ASC
            LIMIT 1
            FOR UPDATE
        ";

        $itemStmt = $pdo->prepare($itemSql);
        $itemStmt->execute([
            ':request_id' => $requestId
        ]);

        $item = $itemStmt->fetch();
        $hddSerial = trim((string)($item['hdd_serial'] ?? ''));
    }

    if ($hddSerial === '') {
        $pdo->rollBack();
        header('Location: ../requests/matched.php?error=serial_empty');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Check duplicate shipment
    |--------------------------------------------------------------------------
    */
    $checkShipmentSql = "
        SELECT id
        FROM harddisk_shipments
        WHERE deleted_at IS NULL
          AND request_id = :request_id
        LIMIT 1
        FOR UPDATE
    ";

    $checkShipmentStmt = $pdo->prepare($checkShipmentSql);
    $checkShipmentStmt->execute([
        ':request_id' => $requestId
    ]);

    $existingShipment = $checkShipmentStmt->fetch();

    if ($existingShipment) {
        $pdo->rollBack();
        header('Location: ../requests/matched.php?error=already_shipped');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | Insert shipment
    |--------------------------------------------------------------------------
    */
    $insertShipmentSql = "
        INSERT INTO harddisk_shipments (
            request_id,
            delivery_request_no,
            main_branch_code,
            branch_code,
            branch_name,
            hdd_serial,
            status,
            created_by,
            created_at,
            shipped_at
        ) VALUES (
            :request_id,
            :delivery_request_no,
            :main_branch_code,
            :branch_code,
            :branch_name,
            :hdd_serial,
            'shipped',
            :created_by,
            NOW(),
            NOW()
        )
    ";

    $insertShipmentStmt = $pdo->prepare($insertShipmentSql);
    $insertShipmentStmt->execute([
        ':request_id' => $request['id'],
        ':delivery_request_no' => $request['request_no'],
        ':main_branch_code' => $request['main_branch_code'],
        ':branch_code' => $request['branch_code'],
        ':branch_name' => $request['branch_name'],
        ':hdd_serial' => $hddSerial,
        ':created_by' => $currentDisplayName
    ]);

    /*
    |--------------------------------------------------------------------------
    | Update request status
    |--------------------------------------------------------------------------
    */
    $updateRequestSql = "
        UPDATE harddisk_delivery_requests
        SET
            status = 'shipped',
            shipped_by = :shipped_by,
            shipped_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND status = 'matched'
    ";

    $updateRequestStmt = $pdo->prepare($updateRequestSql);
    $updateRequestStmt->execute([
        ':shipped_by' => $currentDisplayName,
        ':id' => $requestId
    ]);

    /*
    |--------------------------------------------------------------------------
    | Update HDD inventory if exists
    |--------------------------------------------------------------------------
    */
    $inventoryWhere = 'BINARY hdd_serial = :hdd_serial';
    $inventoryParams = [':hdd_serial' => $hddSerial];
    if (!empty($request['hdd_inventory_id'])) {
        $inventoryWhere = 'id = :hdd_inventory_id';
        $inventoryParams = [':hdd_inventory_id' => (int)$request['hdd_inventory_id']];
    }

    $updateInventorySql = "
        UPDATE harddisk_inventory
        SET
            status = 'shipped',
            updated_at = NOW()
        WHERE {$inventoryWhere}
          AND deleted_at IS NULL
    ";

    $updateInventoryStmt = $pdo->prepare($updateInventorySql);
    $updateInventoryStmt->execute($inventoryParams);

    $pdo->commit();

    header('Location: ../requests/matched.php?success=1');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    die('Shipment Error: ' . $e->getMessage());
}
