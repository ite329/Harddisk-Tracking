<?php
require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/csrf.php';


require_login();
require_permission('inventory.create');

require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: index.php?error=1');
    exit;
}

verify_csrf();

$hddSerial = trim($_POST['hdd_serial'] ?? '');
$brand = trim($_POST['brand'] ?? '');
$model = trim($_POST['model'] ?? '');
$capacity = trim($_POST['capacity'] ?? '');
$receivedFrom = trim($_POST['received_from'] ?? '');
$receivedDate = trim($_POST['received_date'] ?? '');
$remark = trim($_POST['remark'] ?? '');

$fullName = $_SESSION['full_name'] ?? 'Unknown';

if ($hddSerial === '') {
    header('Location: index.php?error=1');
    exit;
}

try {
    $pdo->beginTransaction();

    $checkSql = "
        SELECT id, status
        FROM harddisk_inventory
        WHERE hdd_serial = :hdd_serial
          AND deleted_at IS NULL
        LIMIT 1
        FOR UPDATE
    ";

    $checkStmt = $pdo->prepare($checkSql);
    $checkStmt->execute([
        ':hdd_serial' => $hddSerial
    ]);

    $existing = $checkStmt->fetch();

    if ($existing) {
        $pdo->rollBack();
        header('Location: index.php?duplicate=1');
        exit;
    }

    $insertSql = "
        INSERT INTO harddisk_inventory (
            hdd_serial,
            brand,
            model,
            capacity,
            status,
            scanned_by,
            scanned_at,
            received_from,
            received_at,
            remark,
            created_by,
            created_at
        ) VALUES (
            :hdd_serial,
            :brand,
            :model,
            :capacity,
            'available',
            :scanned_by,
            NOW(),
            :received_from,
            :received_at,
            :remark,
            :created_by,
            NOW()
        )
    ";

    $stmt = $pdo->prepare($insertSql);
    $stmt->execute([
        ':hdd_serial' => $hddSerial,
        ':brand' => $brand !== '' ? $brand : null,
        ':model' => $model !== '' ? $model : null,
        ':capacity' => $capacity !== '' ? $capacity : null,
        ':scanned_by' => $fullName,
        ':received_from' => $receivedFrom !== '' ? $receivedFrom : null,
        ':received_at' => $receivedDate !== '' ? $receivedDate : null,
        ':remark' => $remark !== '' ? $remark : null,
        ':created_by' => $fullName
    ]);

    $pdo->commit();

    header('Location: index.php?success=1');
    exit;

} catch (Exception $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }

    header('Location: index.php?error=1');
    exit;
}
