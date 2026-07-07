<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

if (!isset($pdo)) {
    require_once __DIR__ . '/../config/database.php';
}

require_once __DIR__ . '/functions.php';

/*
|--------------------------------------------------------------------------
| Current User
|--------------------------------------------------------------------------
*/
$currentUserDisplayName = '-';

if (function_exists('get_current_user_full_name')) {
    $currentUserDisplayName = get_current_user_full_name($pdo);
}

if ($currentUserDisplayName === '') {
    $currentUserDisplayName = '-';
}

/*
|--------------------------------------------------------------------------
| Badge Counts
|--------------------------------------------------------------------------
*/
$dashboardAlertCount = 0;
$myHddRequestCount = 0;
$pendingScanCount = 0;
$pendingShipmentConfirmCount = 0;
$shipmentTodayCount = 0;
$availableInventoryCount = 0;
$claimReturnPendingCount = 0;

if (!function_exists('headerTableExists')) {
    function headerTableExists(PDO $pdo, string $tableName): bool
    {
        try {
            $stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM INFORMATION_SCHEMA.TABLES\n                WHERE TABLE_SCHEMA = DATABASE()\n                  AND TABLE_NAME = :table_name\n            ");
            $stmt->execute([':table_name' => $tableName]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('headerColumnExists')) {
    function headerColumnExists(PDO $pdo, string $tableName, string $columnName): bool
    {
        try {
            $stmt = $pdo->prepare("\n                SELECT COUNT(*)\n                FROM INFORMATION_SCHEMA.COLUMNS\n                WHERE TABLE_SCHEMA = DATABASE()\n                  AND TABLE_NAME = :table_name\n                  AND COLUMN_NAME = :column_name\n            ");
            $stmt->execute([
                ':table_name' => $tableName,
                ':column_name' => $columnName,
            ]);
            return (int)$stmt->fetchColumn() > 0;
        } catch (Throwable $e) {
            return false;
        }
    }
}

if (!function_exists('headerCountPendingClaimReturns')) {
    function headerCountPendingClaimReturns(PDO $pdo, string $tableName): int
    {
        if (!headerTableExists($pdo, $tableName)) {
            return 0;
        }

        $where = [];
        $params = [];

        if (headerColumnExists($pdo, $tableName, 'deleted_at')) {
            $where[] = 'deleted_at IS NULL';
        }

        if (headerColumnExists($pdo, $tableName, 'status')) {
            $where[] = "status IN ('received', 'preparing_claim', 'sent_claim')";
        }

        $sql = "SELECT COUNT(*) AS total FROM {$tableName}";
        if (!empty($where)) {
            $sql .= ' WHERE ' . implode(' AND ', $where);
        }

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return (int)$stmt->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}

if (isset($pdo)) {

    /*
    |--------------------------------------------------------------------------
    | รายการเบิก Harddisk ของ IT ที่ Login อยู่
    |--------------------------------------------------------------------------
    */
    if ($currentUserDisplayName !== '-' && $currentUserDisplayName !== '') {
        $sql = "
            SELECT COUNT(*) AS total
            FROM harddisk_delivery_requests
            WHERE deleted_at IS NULL
              AND TRIM(requested_by) = :current_user_name
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':current_user_name' => $currentUserDisplayName
        ]);

        $myHddRequestCount = (int)$stmt->fetchColumn();
    }

    /*
    |--------------------------------------------------------------------------
    | รายการรอยิงบาร์โค้ดทั้งหมด
    |--------------------------------------------------------------------------
    */
    $sql = "
        SELECT COUNT(*) AS total
        FROM harddisk_delivery_requests
        WHERE deleted_at IS NULL
          AND status = 'pending_scan'
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $pendingScanCount = (int)$stmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | รายการรอยืนยันจัดส่งของ IT ที่ Login อยู่
    |--------------------------------------------------------------------------
    | ต้องใช้เงื่อนไขเดียวกับหน้า modules/requests/matched.php
    | หน้านี้แสดงตามผู้บันทึกคำขอ requested_by ไม่ใช่ผู้ยิงบาร์โค้ด matched_by
    */
    if ($currentUserDisplayName !== '-' && $currentUserDisplayName !== '') {
        $sql = "
            SELECT COUNT(*) AS total
            FROM harddisk_delivery_requests
            WHERE deleted_at IS NULL
              AND status = 'matched'
              AND TRIM(requested_by) = :current_user_name
        ";

        $stmt = $pdo->prepare($sql);
        $stmt->execute([
            ':current_user_name' => $currentUserDisplayName
        ]);

        $pendingShipmentConfirmCount = (int)$stmt->fetchColumn();
    }

    /*
    |--------------------------------------------------------------------------
    | รายการจัดส่งวันนี้
    |--------------------------------------------------------------------------
    */
    $sql = "
        SELECT COUNT(*) AS total
        FROM harddisk_shipments
        WHERE deleted_at IS NULL
          AND status = 'shipped'
          AND DATE(shipped_at) = CURDATE()
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $shipmentTodayCount = (int)$stmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | Harddisk พร้อมใช้งานในคลัง
    |--------------------------------------------------------------------------
    */
    $sql = "
        SELECT COUNT(*) AS total
        FROM harddisk_inventory
        WHERE deleted_at IS NULL
          AND status = 'available'
    ";

    $stmt = $pdo->prepare($sql);
    $stmt->execute();
    $availableInventoryCount = (int)$stmt->fetchColumn();

    /*
    |--------------------------------------------------------------------------
    | รายการรับคืน HDD ส่งเคลมที่รอดำเนินการ
    |--------------------------------------------------------------------------
    | นับจากตาราง harddisk_claim_returns เป็นหลัก
    | และรองรับชื่อเก่า hdd_claim_returns เผื่อบางเครื่องยังใช้ตารางเดิม
    | สถานะที่นับ: received, preparing_claim, sent_claim
    | สถานะปิดงาน เช่น scrapped / cancelled / claim_approved จะไม่ถูกนับ
    */
    $claimReturnPendingCount = 0;
    $claimReturnPendingCount += headerCountPendingClaimReturns($pdo, 'harddisk_claim_returns');
    $claimReturnPendingCount += headerCountPendingClaimReturns($pdo, 'hdd_claim_returns');

    /*
    |--------------------------------------------------------------------------
    | Dashboard Alert รวมเฉพาะรายการที่รอดำเนินการ
    |--------------------------------------------------------------------------
    */
    $dashboardAlertCount = $pendingScanCount + $pendingShipmentConfirmCount + $claimReturnPendingCount;
}

/*
|--------------------------------------------------------------------------
| Active Menu Helper
|--------------------------------------------------------------------------
*/
$currentPath = str_replace('\\', '/', $_SERVER['REQUEST_URI'] ?? '');

if (!function_exists('isActiveMenu')) {
    function isActiveMenu($keyword)
    {
        global $currentPath;

        return strpos($currentPath, $keyword) !== false ? 'active' : '';
    }
}

$pageTitle = $pageTitle ?? 'Harddisk Delivery System';
$baseUrl = '/harddisk_delivery_web';
?>
<!doctype html>
<html lang="th">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo e($pageTitle); ?></title>

    <link href="<?php echo $baseUrl; ?>/assets/css/bootstrap.min.css" rel="stylesheet">
    <link href="<?php echo $baseUrl; ?>/assets/css/app.css" rel="stylesheet">

    <style>
        body {
            background-color: #f5f7fb;
        }

        .app-sidebar {
            width: 270px;
            min-height: 100vh;
            background: #ffffff;
            border-right: 1px solid #e5e7eb;
            position: fixed;
            top: 0;
            left: 0;
            z-index: 1000;
        }

        .app-main {
            margin-left: 270px;
            min-height: 100vh;
        }

        .app-brand {
            height: 64px;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            padding: 0 20px;
            font-weight: 700;
            color: #0d6efd;
            white-space: nowrap;
        }

        .app-nav {
            padding: 14px;
        }

        .app-nav .nav-link {
            color: #374151;
            border-radius: 10px;
            padding: 10px 12px;
            margin-bottom: 6px;
            font-size: 14px;
            min-height: 42px;
        }

        .app-nav .nav-link:hover {
            background-color: #f3f4f6;
            color: #0d6efd;
        }

        .app-nav .nav-link.active {
            background-color: #e7f1ff;
            color: #0d6efd;
            font-weight: 600;
        }

        .menu-label {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            min-width: 0;
            white-space: nowrap;
        }

        .menu-alert-badge {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            font-size: 12px;
            font-weight: 700;
            padding: 4px 8px;
            border-radius: 999px;
            color: #ffffff;
            margin-left: 8px;
            min-width: 42px;
            justify-content: center;
            white-space: nowrap;
            line-height: 1;
        }

        .menu-alert-danger {
            background-color: #dc3545;
            animation: pulseDanger 1.4s infinite;
        }

        .menu-alert-primary {
            background-color: #0d6efd;
            animation: pulsePrimary 1.4s infinite;
        }

        .menu-alert-warning {
            background-color: #ffc107;
            color: #212529;
            animation: pulseWarning 1.4s infinite;
        }

        .menu-alert-success {
            background-color: #198754;
            animation: pulseSuccess 1.4s infinite;
        }

        .menu-alert-info {
            background-color: #0dcaf0;
            color: #212529;
            animation: pulseInfo 1.4s infinite;
        }

        .app-topbar {
            min-height: 64px;
            height: auto;
            background: #ffffff;
            border-bottom: 1px solid #e5e7eb;
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 10px 24px;
            position: sticky;
            top: 0;
            z-index: 900;
            gap: 16px;
        }

        .topbar-left {
            min-width: 0;
        }

        .topbar-title {
            font-weight: 700;
            line-height: 1.2;
            margin-bottom: 4px;
        }

        .top-alert-stack {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
        }

        .top-alert-pill {
            display: inline-flex;
            align-items: center;
            gap: 4px;
            padding: 3px 8px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            line-height: 1.2;
            white-space: nowrap;
        }

        .top-alert-warning {
            background-color: #fff3cd;
            color: #856404;
        }

        .top-alert-danger {
            background-color: #f8d7da;
            color: #842029;
        }

        .top-alert-primary {
            background-color: #e7f1ff;
            color: #084298;
        }

        .topbar-user {
            flex-shrink: 0;
        }

        @keyframes pulseDanger {
            0% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0.55);
            }

            70% {
                box-shadow: 0 0 0 8px rgba(220, 53, 69, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(220, 53, 69, 0);
            }
        }

        @keyframes pulsePrimary {
            0% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0.55);
            }

            70% {
                box-shadow: 0 0 0 8px rgba(13, 110, 253, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(13, 110, 253, 0);
            }
        }

        @keyframes pulseWarning {
            0% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0.55);
            }

            70% {
                box-shadow: 0 0 0 8px rgba(255, 193, 7, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(255, 193, 7, 0);
            }
        }

        @keyframes pulseSuccess {
            0% {
                box-shadow: 0 0 0 0 rgba(25, 135, 84, 0.55);
            }

            70% {
                box-shadow: 0 0 0 8px rgba(25, 135, 84, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(25, 135, 84, 0);
            }
        }

        @keyframes pulseInfo {
            0% {
                box-shadow: 0 0 0 0 rgba(13, 202, 240, 0.55);
            }

            70% {
                box-shadow: 0 0 0 8px rgba(13, 202, 240, 0);
            }

            100% {
                box-shadow: 0 0 0 0 rgba(13, 202, 240, 0);
            }
        }

        @media (max-width: 992px) {
            .app-sidebar {
                position: relative;
                width: 100%;
                min-height: auto;
                border-right: 0;
                border-bottom: 1px solid #e5e7eb;
            }

            .app-main {
                margin-left: 0;
            }

            .app-nav {
                display: grid;
                grid-template-columns: repeat(2, minmax(0, 1fr));
                gap: 8px;
            }

            .app-nav .nav-link {
                margin-bottom: 0;
            }

            .app-topbar {
                align-items: flex-start;
                flex-direction: column;
                padding: 12px 16px;
            }

            .topbar-user {
                width: 100%;
                justify-content: space-between;
            }
        }

        @media (max-width: 576px) {
            .app-nav {
                grid-template-columns: 1fr;
            }

            .top-alert-stack {
                display: none;
            }
        }
    </style>
</head>
<body>

<div class="app-sidebar">

    <div class="app-brand">
        Harddisk Tracking
        
    </div>

    <div class="app-nav">

        <a href="<?php echo $baseUrl; ?>/modules/dashboard/index.php"
           class="nav-link d-flex justify-content-between align-items-center <?php echo isActiveMenu('/modules/dashboard/'); ?>">

            <span class="menu-label">📊 Dashboard</span>

            <?php if ($dashboardAlertCount > 0): ?>
                <span class="menu-alert-badge menu-alert-danger"
                      title="มีรายการที่ต้องดำเนินการ <?php echo number_format($dashboardAlertCount); ?> รายการ">
                    🔔 <?php echo number_format($dashboardAlertCount); ?>
                </span>
            <?php endif; ?>

        </a>

        <a href="<?php echo $baseUrl; ?>/modules/requests/create.php"
           class="nav-link d-flex justify-content-between align-items-center <?php echo isActiveMenu('/modules/requests/create.php'); ?>">

            <span class="menu-label">➕ บันทึกคำขอส่ง HDD</span>

        </a>

        

        <a href="<?php echo $baseUrl; ?>/modules/requests/assign_hdd.php"
           class="nav-link d-flex justify-content-between align-items-center <?php echo isActiveMenu('/modules/requests/assign_hdd.php'); ?>">

            <span class="menu-label">🔫 ยิงบาร์โค้ด HDD</span>

            <?php if ($pendingScanCount > 0): ?>
                <span class="menu-alert-badge menu-alert-warning"
                      title="มีรายการรอยิงบาร์โค้ด <?php echo number_format($pendingScanCount); ?> รายการ">
                    📌 <?php echo number_format($pendingScanCount); ?>
                </span>
            <?php endif; ?>

        </a>

        <a href="<?php echo $baseUrl; ?>/modules/requests/matched.php"
           class="nav-link d-flex justify-content-between align-items-center <?php echo isActiveMenu('/modules/requests/matched.php'); ?>">

            <span class="menu-label">🚚 รอยืนยันจัดส่ง</span>

            <?php if ($pendingShipmentConfirmCount > 0): ?>
                <span class="menu-alert-badge menu-alert-danger"
                      title="คุณมีรายการรอยืนยันจัดส่ง <?php echo number_format($pendingShipmentConfirmCount); ?> รายการ">
                    🔔 <?php echo number_format($pendingShipmentConfirmCount); ?>
                </span>
            <?php endif; ?>

        </a>

<a href="<?php echo $baseUrl; ?>/modules/requests/index.php"
           class="nav-link d-flex justify-content-between align-items-center <?php echo isActiveMenu('/modules/requests/index.php'); ?>">

            <span class="menu-label">📋 รายการเบิก Harddisk</span>

            <?php if ($myHddRequestCount > 0): ?>
                <span class="menu-alert-badge menu-alert-primary"
                      title="คุณมีรายการเบิก Harddisk <?php echo number_format($myHddRequestCount); ?> รายการ">
                    🧾 <?php echo number_format($myHddRequestCount); ?>
                </span>
            <?php endif; ?>

        </a>

        <a href="<?php echo $baseUrl; ?>/modules/shipments/index.php"
           class="nav-link d-flex justify-content-between align-items-center <?php echo isActiveMenu('/modules/shipments/index.php'); ?>">

            <span class="menu-label">📦 ประวัติการจัดส่ง</span>

            <?php if ($shipmentTodayCount > 0): ?>
                <span class="menu-alert-badge menu-alert-info"
                      title="วันนี้มีรายการจัดส่ง <?php echo number_format($shipmentTodayCount); ?> รายการ">
                    📦 <?php echo number_format($shipmentTodayCount); ?>
                </span>
            <?php endif; ?>

        </a>

        <a href="<?php echo $baseUrl; ?>/modules/claim_returns/index.php"
           class="nav-link d-flex justify-content-between align-items-center <?php echo isActiveMenu('/modules/claim_returns/'); ?>">

            <span class="menu-label">🛠️ รับคืน HDD ส่งเคลม</span>

            <?php if ($claimReturnPendingCount > 0): ?>
                <span class="menu-alert-badge menu-alert-danger"
                      title="มีรายการรับคืน HDD รอดำเนินการ <?php echo number_format($claimReturnPendingCount); ?> รายการ">
                    🔔 <?php echo number_format($claimReturnPendingCount); ?>
                </span>
            <?php endif; ?>

        </a>

        <a href="<?php echo $baseUrl; ?>/modules/inventory/index.php"
           class="nav-link d-flex justify-content-between align-items-center <?php echo isActiveMenu('/modules/inventory/'); ?>">

            <span class="menu-label">🧾 คลัง Harddisk</span>

            <?php if ($availableInventoryCount > 0): ?>
                <span class="menu-alert-badge menu-alert-success"
                      title="มี Harddisk พร้อมใช้งาน <?php echo number_format($availableInventoryCount); ?> ลูก">
                    💽 <?php echo number_format($availableInventoryCount); ?>
                </span>
            <?php endif; ?>

        </a>

        <a href="<?php echo $baseUrl; ?>/modules/reports/index.php"
           class="nav-link d-flex justify-content-between align-items-center <?php echo isActiveMenu('/modules/reports/'); ?>">

            <span class="menu-label">📈 รายงาน</span>

            <?php if ($dashboardAlertCount > 0): ?>
                <span class="menu-alert-badge menu-alert-primary"
                      title="มีรายการที่ต้องติดตาม">
                    📊
                </span>
            <?php endif; ?>

        </a>

    </div>

    <main>

</div>

<div class="app-main">

    <div class="app-topbar">

        <div class="topbar-left">
            <div class="topbar-title">
                <?php echo e($pageTitle); ?>
            </div>

            <div class="top-alert-stack">

                <?php if ($pendingScanCount > 0): ?>
                    <span class="top-alert-pill top-alert-warning">
                        📌 รอยิงบาร์โค้ด <?php echo number_format($pendingScanCount); ?>
                    </span>
                <?php endif; ?>

                <?php if ($pendingShipmentConfirmCount > 0): ?>
                    <span class="top-alert-pill top-alert-danger">
                        🔔 รอยืนยันจัดส่ง <?php echo number_format($pendingShipmentConfirmCount); ?>
                    </span>
                <?php endif; ?>

                <?php if ($claimReturnPendingCount > 0): ?>
                    <span class="top-alert-pill top-alert-primary">
                        🔔 รับคืน HDD รอดำเนินการ <?php echo number_format($claimReturnPendingCount); ?>
                    </span>
                <?php endif; ?>

                <?php if ($myHddRequestCount > 0): ?>
                    <span class="top-alert-pill top-alert-primary">
                        🧾 รายการเบิกของคุณ <?php echo number_format($myHddRequestCount); ?>
                    </span>
                <?php endif; ?>

            </div>
        </div>

        <div class="topbar-user d-flex align-items-center gap-3">

            <div class="text-end">
                <div class="fw-semibold small">
                    <?php echo e($currentUserDisplayName); ?>
                </div>
                <div class="text-muted small">
                    ผู้ใช้งานระบบ
                </div>
            </div>

            <a href="<?php echo $baseUrl; ?>/public/logout.php"
               class="btn btn-outline-danger btn-sm">
                ออกจากระบบ
            </a>

        </div>

    </div>
