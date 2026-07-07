<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/functions.php';
require_once __DIR__ . '/../../includes/csrf.php';

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?delete_error=1');
    exit;
}

verify_csrf();

if (!can_delete_records($pdo)) {
    die('ไม่มีสิทธิ์ลบข้อมูล');
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;

if ($id <= 0) {
    header('Location: index.php?delete_error=1');
    exit;
}

try {
    $pdo->beginTransaction();

    /*
    |--------------------------------------------------------------------------
    | ดึงข้อมูล shipment ก่อนลบ
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        SELECT request_id, hdd_serial
        FROM harddisk_shipments
        WHERE id = :id
        LIMIT 1
    ");
    $stmt->execute([
        ':id' => $id
    ]);

    $shipment = $stmt->fetch();

    if (!$shipment) {
        $pdo->rollBack();
        header('Location: index.php?delete_error=1');
        exit;
    }

    /*
    |--------------------------------------------------------------------------
    | ลบประวัติการจัดส่งจริง
    |--------------------------------------------------------------------------
    */
    $stmt = $pdo->prepare("
        DELETE FROM harddisk_shipments
        WHERE id = :id
    ");
    $stmt->execute([
        ':id' => $id
    ]);

    /*
    |--------------------------------------------------------------------------
    | ถ้าลบเฉพาะประวัติการจัดส่ง ให้ย้อนคำขอกลับไปรอยืนยันจัดส่ง
    |--------------------------------------------------------------------------
    */
    if (!empty($shipment['request_id'])) {
        $stmt = $pdo->prepare("
            UPDATE harddisk_delivery_requests
            SET status = 'matched',
                shipped_by = NULL,
                shipped_at = NULL,
                updated_at = NOW()
            WHERE id = :request_id
              AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':request_id' => $shipment['request_id']
        ]);
    }

    /*
    |--------------------------------------------------------------------------
    | ย้อนสถานะ HDD ในคลังกลับเป็น reserved
    |--------------------------------------------------------------------------
    */
    if (!empty($shipment['hdd_serial'])) {
        $stmt = $pdo->prepare("
            UPDATE harddisk_inventory
            SET status = 'reserved',
                updated_at = NOW()
            WHERE hdd_serial = :hdd_serial
              AND deleted_at IS NULL
        ");
        $stmt->execute([
            ':hdd_serial' => $shipment['hdd_serial']
        ]);
    }

    $pdo->commit();

    header('Location: index.php?delete_success=1');
    exit;

} catch (Exception $e) {
    $pdo->rollBack();
    header('Location: index.php?delete_error=1');
    exit;
}
