<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: matched.php?delete_error=1');
    exit;
}

verify_csrf();

if (!can_delete_records($pdo)) {
    die('ไม่มีสิทธิ์ลบข้อมูล');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    header('Location: matched.php?delete_error=1');
    exit;
}

try {
    $pdo->beginTransaction();

    $serialStmt = $pdo->prepare("
        SELECT hdd_serial
        FROM harddisk_request_items
        WHERE request_id = :request_id
          AND scan_status = 'matched'
    ");
    $serialStmt->execute([
        ':request_id' => $id
    ]);
    $serials = $serialStmt->fetchAll();

    /*
    |--------------------------------------------------------------------------
    | ลบประวัติการจัดส่ง ถ้ามีผูกกับคำขอนี้
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        DELETE FROM harddisk_shipments
        WHERE request_id = :request_id
    ");
    $stmt->execute([
        ':request_id' => $id
    ]);

    /*
    |--------------------------------------------------------------------------
    | ลบ Serial HDD ที่ผูกกับคำขอนี้
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        DELETE FROM harddisk_request_items
        WHERE request_id = :request_id
    ");
    $stmt->execute([
        ':request_id' => $id
    ]);

    foreach ($serials as $serial) {
        if (!empty($serial['hdd_serial'])) {
            $stmt = $pdo->prepare("
                UPDATE harddisk_inventory
                SET status = 'available',
                    updated_at = NOW()
                WHERE hdd_serial = :hdd_serial
                  AND status = 'reserved'
                  AND deleted_at IS NULL
            ");
            $stmt->execute([
                ':hdd_serial' => $serial['hdd_serial']
            ]);
        }
    }

    /*
    |--------------------------------------------------------------------------
    | ลบคำขอหลัก เฉพาะรายการนี้เท่านั้น
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        DELETE FROM harddisk_delivery_requests
        WHERE id = :id
          AND status = 'matched'
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $id
    ]);

    $pdo->commit();

    header('Location: matched.php?delete_success=1');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: matched.php?delete_error=1');
    exit;
}
