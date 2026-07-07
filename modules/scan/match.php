<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php');
    exit;
}

verify_csrf();

$requestId = isset($_POST['request_id']) ? (int)$_POST['request_id'] : 0;
$hddSerial = trim($_POST['hdd_serial'] ?? '');
$currentUserName = get_current_user_full_name($pdo);

if ($currentUserName === '') {
    $currentUserName = current_user_name();
}

if ($requestId <= 0) {
    header('Location: index.php?error=not_found');
    exit;
}

if ($hddSerial === '') {
    header('Location: index.php?request_id=' . $requestId . '&error=empty_serial');
    exit;
}

try {
    $pdo->beginTransaction();

    $requestSql = "
        SELECT id, status
        FROM harddisk_delivery_requests
        WHERE id = :id
          AND deleted_at IS NULL
          AND status = 'pending_scan'
        LIMIT 1
        FOR UPDATE
    ";

    $requestStmt = $pdo->prepare($requestSql);
    $requestStmt->execute([
        ':id' => $requestId
    ]);

    $request = $requestStmt->fetch();

    if (!$request) {
        $pdo->rollBack();
        header('Location: index.php?error=not_found');
        exit;
    }

    $existingItemSql = "
        SELECT id
        FROM harddisk_request_items
        WHERE request_id = :request_id
          AND scan_status = 'matched'
        LIMIT 1
        FOR UPDATE
    ";

    $existingItemStmt = $pdo->prepare($existingItemSql);
    $existingItemStmt->execute([
        ':request_id' => $requestId
    ]);

    if ($existingItemStmt->fetch()) {
        $pdo->rollBack();
        header('Location: index.php?request_id=' . $requestId . '&error=already_matched');
        exit;
    }

    $inventorySql = "
        SELECT id, hdd_serial, status
        FROM harddisk_inventory
        WHERE hdd_serial = :hdd_serial
          AND deleted_at IS NULL
        LIMIT 1
        FOR UPDATE
    ";

    $inventoryStmt = $pdo->prepare($inventorySql);
    $inventoryStmt->execute([
        ':hdd_serial' => $hddSerial
    ]);

    $inventory = $inventoryStmt->fetch();

    if (!$inventory) {
        $pdo->rollBack();
        header('Location: index.php?request_id=' . $requestId . '&error=hdd_not_found');
        exit;
    }

    if ($inventory['status'] !== 'available') {
        $pdo->rollBack();
        header('Location: index.php?request_id=' . $requestId . '&error=hdd_not_available');
        exit;
    }

    $activeSerialSql = "
        SELECT id
        FROM harddisk_request_items
        WHERE hdd_serial = :hdd_serial
          AND scan_status = 'matched'
        LIMIT 1
        FOR UPDATE
    ";

    $activeSerialStmt = $pdo->prepare($activeSerialSql);
    $activeSerialStmt->execute([
        ':hdd_serial' => $hddSerial
    ]);

    if ($activeSerialStmt->fetch()) {
        $pdo->rollBack();
        header('Location: index.php?request_id=' . $requestId . '&error=duplicate_serial');
        exit;
    }

    $insertItemSql = "
        INSERT INTO harddisk_request_items (
            request_id,
            hdd_inventory_id,
            hdd_serial,
            scan_status,
            scanned_by,
            scanned_at,
            created_at
        ) VALUES (
            :request_id,
            :hdd_inventory_id,
            :hdd_serial,
            'matched',
            :scanned_by,
            NOW(),
            NOW()
        )
    ";

    $insertItemStmt = $pdo->prepare($insertItemSql);
    $insertItemStmt->execute([
        ':request_id' => $requestId,
        ':hdd_inventory_id' => $inventory['id'],
        ':hdd_serial' => $inventory['hdd_serial'],
        ':scanned_by' => $currentUserName
    ]);

    $updateRequestSql = "
        UPDATE harddisk_delivery_requests
        SET
            status = 'matched',
            matched_by = :matched_by,
            matched_at = NOW(),
            updated_at = NOW()
        WHERE id = :id
          AND status = 'pending_scan'
    ";

    $updateRequestStmt = $pdo->prepare($updateRequestSql);
    $updateRequestStmt->execute([
        ':matched_by' => $currentUserName,
        ':id' => $requestId
    ]);

    $updateInventorySql = "
        UPDATE harddisk_inventory
        SET
            status = 'reserved',
            updated_at = NOW()
        WHERE id = :id
          AND status = 'available'
    ";

    $updateInventoryStmt = $pdo->prepare($updateInventorySql);
    $updateInventoryStmt->execute([
        ':id' => $inventory['id']
    ]);

    $pdo->commit();

    header('Location: matched.php?match_success=1');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Location: index.php?request_id=' . $requestId . '&error=1');
    exit;
}
